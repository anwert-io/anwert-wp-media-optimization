<?php
/*
Plugin Name: Anwert Media Optimizer
Description: Converts uploaded media to WebP, regenerates thumbnails, replaces URLs, deletes old thumbnails, supports dry run, and gives control over image sizes.
Version: 1.0.10
Author: Anwert (anwert.io)
Author URI: https://anwert.io
*/

use WP_CLI;

if (!defined('ABSPATH')) exit;

// Super-admins (network) or site admins can use the tool
function ctw_user_can(): bool
{
    return current_user_can('manage_options') || is_super_admin();
}

add_action('admin_menu', function () {
    if (is_network_admin()) return; // keep UI per site
    if (ctw_user_can()) {
        add_management_page(
            'Optimize Media',
            'Optimize Media',
            'manage_options',
            'optimize-media',
            'ctw_render_admin_page'
        );
    }
});


add_action('admin_notices', function () {
    $scr = get_current_screen();
    if ($scr && $scr->id === 'tools_page_optimize-media' && ctw_user_can()) {
        if (!function_exists('as_enqueue_async_action')) {
            echo '<div class="notice notice-error"><p><strong>Anwert Media Optimizer:</strong> Action Scheduler not found. Please install WooCommerce or the Action Scheduler library.</p></div>';
        }
    }
});


add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'tools_page_optimize-media') return;
    $css = '.log-section h3.hndle{font-size:14px;margin-top:20px}.log-section{margin-bottom:1em}.ctw-progress{width:100%;background:#f1f1f1;height:24px;border-radius:4px;overflow:hidden}.ctw-progress__bar{width:0;height:100%;background:#2271b1;transition:width .3s}.ctw-progress__wrap{margin:10px 0 15px}.ctw-controls{display:flex;gap:10px;align-items:center;margin:10px 0} .ctw-grid{display:flex;gap:20px;align-items:flex-start}.ctw-col-left{flex:0 0 25%;max-width:25%;min-width:260px}.ctw-col-right{flex:1;min-width:0}.ctw-results{margin-top:10px}.ctw-summary{background:#fff;border:1px solid #c3c4c7;padding:12px}.ctw-summary h3{margin:0 0 8px;font-size:14px}.ctw-summary .metrics{display:flex;flex-wrap:wrap;gap:12px}.ctw-summary .metric{background:#f6f7f7;border:1px solid #dcdcde;padding:8px 10px;border-radius:3px}';
    wp_register_style('anwert-image-optimizer-inline', false);
    wp_enqueue_style('anwert-image-optimizer-inline');
    wp_add_inline_style('anwert-image-optimizer-inline', $css);

    wp_register_script('ctw-admin', false);
    wp_enqueue_script('ctw-admin');
    wp_add_inline_script(
        'ctw-admin',
        'window.CTW = { ajax: "' . admin_url('admin-ajax.php') . '", nonce: "' . wp_create_nonce('ctw_ajax') . '" };'
    );

    wp_add_inline_script('ctw-admin', "
    function updateCTWButtonLabel() {
        var dryRunCb = document.getElementById('ctw-dry-run');
        var btn = document.getElementById('ctw-start-job');
        var dryRunChecked = dryRunCb ? !!dryRunCb.checked : true;
        if (btn) {
            btn.textContent = dryRunChecked
                ? 'Start Dry Run in Background'
                : 'Start Conversion in Background';
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        var dryRunCheckbox = document.getElementById('ctw-dry-run');
        if (dryRunCheckbox) {
            dryRunCheckbox.addEventListener('change', updateCTWButtonLabel);
        }
        updateCTWButtonLabel();
    });
");
});

// Ensure Action Scheduler is available (bundled with WooCommerce or standalone)
add_action('admin_notices', function () {
    if (get_current_screen() && get_current_screen()->id === 'tools_page_optimize-media') {
        if (!function_exists('as_enqueue_async_action')) {
            echo '<div class="notice notice-error"><p><strong>Anwert Media Optimizer:</strong> Action Scheduler not found. Please install WooCommerce or the Action Scheduler library.</p></div>';
        }
    }
});

add_action('wp_ajax_ctw_start_job', 'ctw_ajax_start_job');
add_action('wp_ajax_ctw_job_status', 'ctw_ajax_job_status');
add_action('wp_ajax_ctw_cancel_job', 'ctw_ajax_cancel_job');
add_action('wp_ajax_ctw_clear_results', 'ctw_ajax_clear_results');
add_action('wp_ajax_ctw_user_search', 'ctw_ajax_user_search');

function ctw_ajax_user_search()
{
    if (!ctw_user_can()) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ctw_ajax', 'nonce');

    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    if ($q === '') wp_send_json_success([]);

    $users = get_users([
        'search'         => '*' . $q . '*',
        'search_columns' => ['user_login', 'user_nicename', 'user_email', 'display_name'],
        'number'         => 20,
        'fields'         => ['ID', 'user_login', 'user_email', 'display_name'],
    ]);

    $out = array_map(function ($u) {
        return [
            'id'    => (int) $u->ID,
            'login' => $u->user_login,
            'email' => $u->user_email,
            'name'  => $u->display_name ?: $u->user_login,
        ];
    }, $users);

    wp_send_json_success($out);
}


function ctw_ajax_clear_results()
{
    if (!ctw_user_can()) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ctw_ajax', 'nonce');

    ctw_reset_job_state(); // removes saved results/status
    wp_send_json_success(['cleared' => true]);
}


// Group and hooks for Action Scheduler
define('CTW_AS_GROUP', 'ctw-image-optimizer');
add_action('ctw_process_file', 'ctw_as_process_file', 10, 2); // args: path, params
add_action('ctw_finalize_job', 'ctw_as_finalize_job', 10, 1);  // args: job_id

function ctw_get_job_state()
{
    $state = get_option('ctw_job_state');
    return is_array($state) ? $state : null;
}
function ctw_set_job_state($state)
{
    update_option('ctw_job_state', $state, false);
}
function ctw_reset_job_state()
{
    delete_option('ctw_job_state');
}

function ctw_ajax_start_job()
{
    if (!ctw_user_can()) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ctw_ajax', 'nonce');

    if (!function_exists('as_enqueue_async_action')) {
        wp_send_json_error(['message' => 'Action Scheduler not available']);
    }

    $skip_if_larger = !empty($_POST['skip_if_larger']);
    $exclude_users = array_map('intval', $_POST['exclude_users'] ?? []);
    $folders   = array_map('sanitize_text_field', $_POST['folders'] ?? []);
    $threshold = isset($_POST['threshold']) ? intval($_POST['threshold']) : 2048;
    $quality   = isset($_POST['quality']) ? max(0, min(100, intval($_POST['quality']))) : 85;
    $ignore_unattached = !empty($_POST['ignore_unattached']);
    $dry_run   = !empty($_POST['dry_run']);
    $enable_resize = !empty($_POST['enable_resize']);
    $disabled_sizes = array_map('sanitize_text_field', $_POST['disabled_sizes'] ?? []);
    $delete_empty_folders = !empty($_POST['delete_empty_folders']);
    $thumb_quality = isset($_POST['thumb_quality']) ? max(0, min(100, intval($_POST['thumb_quality']))) : 70;
    $strip_meta = !empty($_POST['strip_meta']);
    $enable_thumb_deletion = !empty($_POST['enable_thumb_deletion']);
    $enable_original_deletion = !isset($_POST['enable_original_deletion']) || $_POST['enable_original_deletion']; // checked by default
    $create_webp = !isset($_POST['create_webp']) || $_POST['create_webp']; // checked by default

    // New: limit files logic
    $limit_files = !empty($_POST['limit_files']);
    $max_files = $limit_files ? max(1, intval($_POST['max_files'] ?? 0)) : 0;

    if (empty($folders)) wp_send_json_error(['message' => 'No folders selected']);

    // Build file list (shallow scan per month folder)
    $upload = wp_upload_dir();
    $base   = trailingslashit($upload['basedir']);
    $files  = [];
    foreach ($folders as $folder) {
        $dir = $base . $folder;
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isDir()) continue;
            $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
            // include originals + thumbs; thumbs will be handled by logic
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'heic', 'webp'])) {
                $files[] = $f->getPathname();
                // Respect max_files limit if set
                if ($limit_files && $max_files > 0 && count($files) >= $max_files) {
                    break 2; // stop outer foreach as well
                }
            }
        }
    }

    $job_id = time();
    $group  = 'ctw-job-' . $job_id;

    // Store relative paths for resume support
    $rel = array_map(function ($p) use ($base) {
        return ltrim(str_replace($base, '', $p), '/\\');
    }, $files);
    $state = [
        'id' => $job_id,
        'group' => $group,
        'total' => count($files),
        'processed' => 0,
        'converted' => 0,
        'skipped' => 0,
        // deleted breakdown
        'deleted_originals' => 0,
        'deleted_originals_size' => 0,
        'thumbs_deleted' => 0,
        'deleted_thumbs_size' => 0,
        // totals
        'total_deleted_files' => 0,
        'deleted_size' => 0, // total deleted size (originals + thumbs) - kept for backwards compat
        // created sizes
        'webp_size' => 0,
        'created_thumbs_size' => 0,
        // created counts
        'created_webp_files'   => 0,
        'created_thumbs_count' => 0,
        'total_created_files'  => 0,
        // folders
        'deleted_folders' => 0,
        'status' => 'queued',
        'message' => 'Queued',
        'started_at' => current_time('mysql'),
        'ended_at' => null,
        'cancel_requested' => false,
        'params' => compact(
            'folders',
            'threshold',
            'quality',
            'thumb_quality',
            'ignore_unattached',
            'dry_run',
            'disabled_sizes',
            'enable_resize',
            'delete_empty_folders',
            'skip_if_larger',
            'strip_meta',
            'exclude_users',
            'enable_thumb_deletion',
            'enable_original_deletion',
            'create_webp',
            // New: pass limit info for transparency
            'limit_files',
            'max_files'
        ),
        'files' => $rel,
        'done'  => [], // set of processed relative paths
        // Per-file logs
        'converted_files'      => [],
        'skipped_files'        => [],
        'deleted_files'        => [],
        'deleted_folders_list' => [],
    ];

    update_option('ctw_main_quality',  $quality,       false);
    update_option('ctw_thumb_quality', $thumb_quality, false);

    ctw_reset_job_state(); // ensure no leftover arrays from a prior run
    ctw_set_job_state($state);

    // Schedule actions per file (chunking keeps UI responsive; AS handles backpressure)
    foreach ($rel as $rpath) {
        as_enqueue_async_action('ctw_process_file', [$rpath, ['job_id' => $job_id]], CTW_AS_GROUP);
    }
    // Finalize when AS queue empties (schedule a finalizer with small delay)
    as_schedule_single_action(time() + 5, 'ctw_finalize_job', [$job_id], CTW_AS_GROUP);

    $state['status'] = 'running';
    $state['message'] = 'Running';
    ctw_set_job_state($state);

    wp_send_json_success(['job_id' => $job_id, 'total' => $state['total']]);
}

// Helper to get relative uploads path
function ctw_rel_from_abs($abs)
{
    $upload_dir = wp_upload_dir();
    return ltrim(str_replace($upload_dir['basedir'], '', $abs), '/\\');
}

// Try to resolve an attachment ID by its relative uploads path
function ctw_find_attachment_by_relative_path($rel_path)
{
    global $wpdb;
    $rel_path = ltrim((string) $rel_path, '/\\');
    if ($rel_path === '') return 0;
    $post_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
        $rel_path
    ));
    return $post_id > 0 ? $post_id : 0;
}

// Log a skipped file with a reason (ensures uniqueness)
function ctw_log_skipped(&$state, $rel_key, $reason)
{
    $entry = $rel_key . ' — ' . $reason;
    if (!isset($state['skipped_files']) || !is_array($state['skipped_files'])) {
        $state['skipped_files'] = [];
    }
    if (!in_array($entry, $state['skipped_files'], true)) {
        $state['skipped_files'][] = $entry;
    }
}


// Human-readable bytes (e.g., "1.2 MB")
function ctw_format_bytes($bytes)
{
    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? round($bytes) : round($bytes, 1)) . ' ' . $units[$i];
}


// Apply separate quality for main vs. thumbnails (webp only)
// add_filter('wp_editor_set_quality', 'ctw_filter_wp_editor_set_quality', 10, 3);
// function ctw_filter_wp_editor_set_quality($quality, $mime_type, $context)
// {
//     if ($mime_type === 'image/webp') {
//         $main  = (int) get_option('ctw_main_quality', 85);
//         $thumb = (int) get_option('ctw_thumb_quality', 70);
//         if ($context === 'image_resize') {
//             return $thumb; // thumbnails / subsizes
//         }
//         if ($context === 'upload') {
//             return $main; // original write
//         }
//     }
//     return $quality;
// }


function ctw_ajax_job_status()
{
    if (!ctw_user_can()) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ctw_ajax', 'nonce');

    $state = ctw_get_job_state();
    if (!$state) wp_send_json_success(['status' => 'idle']);

    // Ensure arrays exist to avoid undefined and keep UI lists stable
    $state['converted_files']      = isset($state['converted_files']) && is_array($state['converted_files']) ? $state['converted_files'] : [];
    $state['skipped_files']        = isset($state['skipped_files']) && is_array($state['skipped_files']) ? $state['skipped_files'] : [];
    $state['deleted_files']        = isset($state['deleted_files']) && is_array($state['deleted_files']) ? $state['deleted_files'] : [];
    $state['deleted_folders_list'] = isset($state['deleted_folders_list']) && is_array($state['deleted_folders_list']) ? $state['deleted_folders_list'] : [];
    $state['done']                 = isset($state['done']) && is_array($state['done']) ? $state['done'] : [];

    // Normalize counters from lists to ensure UI consistency (incl. dry-run)
    $state['processed']            = count($state['done']);
    $state['skipped']              = count($state['skipped_files']);
    $state['total_deleted_files']  = count($state['deleted_files']);

    // If totals look empty but logs exist, fix totals from logs
    if (empty($state['total']) && !empty($state['files']) && is_array($state['files'])) {
        $state['total'] = count($state['files']);
    }

    wp_send_json_success($state);
}

function ctw_ajax_cancel_job()
{
    if (!ctw_user_can()) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ctw_ajax', 'nonce');

    $state = ctw_get_job_state();
    if (!$state) wp_send_json_error(['message' => 'No job']);

    $state['cancel_requested'] = true;
    $state['status'] = 'cancelling';
    $state['message'] = 'Cancelling...';
    ctw_set_job_state($state);

    // Unschedule any future actions in this group
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('ctw_process_file', [], CTW_AS_GROUP);
    }

    wp_send_json_success($state);
}

// Allowed subsizes minus any disabled ones (with width/height/crop)
function ctw_get_allowed_subsizes($enabled_sizes = [])
{
    $out = [];
    if (function_exists('wp_get_registered_image_subsizes')) {
        $all = wp_get_registered_image_subsizes();
        foreach ($all as $name => $args) {
            if (!in_array($name, (array)$enabled_sizes, true)) continue;
            $out[$name] = [
                'width'  => isset($args['width']) ? (int)$args['width'] : 0,
                'height' => isset($args['height']) ? (int)$args['height'] : 0,
                'crop'   => !empty($args['crop']),
            ];
        }
    } else {
        $sizes = get_intermediate_image_sizes();
        foreach ($sizes as $name) {
            if (!in_array($name, (array)$enabled_sizes, true)) continue;
            $out[$name] = [
                'width'  => (int) get_option("{$name}_size_w"),
                'height' => (int) get_option("{$name}_size_h"),
                'crop'   => (bool) get_option("{$name}_crop"),
            ];
        }
    }
    return $out; // name => [w,h,crop]
}

// Calculate resize + optional crop target (center-crop when $crop=true)
function ctw_calc_resize_dims($orig_w, $orig_h, $target_w, $target_h, $crop)
{
    if (!$target_w && !$target_h) return [$orig_w, $orig_h, 0, 0];
    if ($crop) {
        $scale = max($target_w ? ($target_w / $orig_w) : 0, $target_h ? ($target_h / $orig_h) : 0);
        if ($scale <= 0) $scale = 1;
        $new_w = (int) ceil($orig_w * $scale);
        $new_h = (int) ceil($orig_h * $scale);
        $x = max(0, (int) floor(($new_w - $target_w) / 2));
        $y = max(0, (int) floor(($new_h - $target_h) / 2));
        return [$new_w, $new_h, $x, $y];
    } else {
        $scale_w = $target_w ? ($target_w / $orig_w) : 1;
        $scale_h = $target_h ? ($target_h / $orig_h) : 1;
        $scale = min($scale_w ?: $scale_h, $scale_h ?: $scale_w);
        if ($scale > 1) $scale = 1; // no upscaling
        $new_w = (int) floor($orig_w * $scale);
        $new_h = (int) floor($orig_h * $scale);
        return [$new_w, $new_h, 0, 0];
    }
}

function ctw_as_process_file($path, $args)
{
    $state = ctw_get_job_state();
    if (!$state || !empty($state['cancel_requested'])) return; // cancelled or missing

    $params = $state['params'];
    $create_webp = !isset($params['create_webp']) || $params['create_webp']; // checked by default
    $thumb_quality = isset($params['thumb_quality']) ? intval($params['thumb_quality']) : $quality;
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $skip_if_larger = !empty($params['skip_if_larger']);
    $enable_thumb_deletion = !empty($params['enable_thumb_deletion']);
    $enable_original_deletion = !isset($params['enable_original_deletion']) || $params['enable_original_deletion']; // checked by default

    // Accept relative path and rebuild absolute
    if ($path && $path[0] !== '/' && $path[1] !== ':') { // naive check for non-absolute
        $path = trailingslashit($upload_dir['basedir']) . ltrim($path, '/\\');
    }
    $rel_key = ltrim(str_replace($upload_dir['basedir'], '', $path), '/\\');
    $logged = false;

    // Respect disabled sizes for generation
    // if (is_admin() && !empty($params['disabled_sizes'])) {
    //     add_filter('intermediate_image_sizes', function ($sizes) use ($params) {
    //         return array_diff($sizes, $params['disabled_sizes']);
    //     });
    //     add_filter('intermediate_image_sizes_advanced', function ($sizes) use ($params) {
    //         return array_diff_key($sizes, array_flip($params['disabled_sizes']));
    //     });
    // }

    // replicate per-file logic (simplified counts; no verbose logs in AS loop)
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $filename = basename($path);
    $dry_run = !empty($params['dry_run']);
    $ignore_unattached = !empty($params['ignore_unattached']);
    $threshold = intval($params['threshold']);
    $quality   = intval($params['quality']);
    $enable_resize = !empty($params['enable_resize']);
    $strip_meta = !empty($params['strip_meta']);

    // Compute attachment id
    $relative_url = str_replace($base_dir, '', $path);
    $upload_url   = $upload_dir['baseurl'] . str_replace(DIRECTORY_SEPARATOR, '/', $relative_url);
    $attachment_id = attachment_url_to_postid($upload_url);
    // Fallback: try to resolve by relative path to preserve meta such as alt text
    if (! $attachment_id) {
        $attachment_id = ctw_find_attachment_by_relative_path(ltrim($relative_url, '/\\'));
    }

    $exclude_users = array_map('intval', $params['exclude_users'] ?? []);
    if ($attachment_id && !empty($exclude_users)) {
        $att = get_post($attachment_id);
        if ($att && in_array((int)$att->post_author, $exclude_users, true)) {
            $state['skipped']++;
            $u = get_user_by('id', (int)$att->post_author);
            $uname = $u ? ($u->display_name ?: $u->user_login) : ('ID ' . (int)$att->post_author);
            ctw_log_skipped($state, $rel_key, 'User excluded (' . $uname . ')');
            $logged = true;
            $state['processed']++;
            $state['done'][$rel_key] = true;
            ctw_set_job_state($state);
            return;
        }
    }

    if ($ignore_unattached && !$attachment_id) {
        // Check if the file is referenced in the DB
        $old_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $path);
        if (ctw_db_has_reference_to_url($old_url)) {
            // Referenced in DB, so process as normal (do not skip)
            // No return here, continue processing
        } else {
            $state['skipped']++;
            ctw_log_skipped($state, $rel_key, 'Unattached media');
            $logged = true;
            $state['processed']++;
            $state['done'][$rel_key] = true;
            ctw_set_job_state($state);
            return;
        }
    }

    // Thumbnail deletion for non-regenerated or non-webp
    if (preg_match('/-(\d+)x(\d+)\.(jpe?g|png|heic|webp)$/i', $filename, $m)) {
        $thumb_size = "{$m[1]}x{$m[2]}";
        $e = strtolower($m[3]);
        $registered_sizes = ctw_get_registered_size_dimensions();
        if ($enable_thumb_deletion && ($e !== 'webp' || !in_array($thumb_size, $registered_sizes))) { // <-- changed
            $sz = file_exists($path) ? filesize($path) : 0;
            $state['thumbs_deleted']++;
            $state['deleted_thumbs_size'] += $sz;
            $state['total_deleted_files']++;
            $state['deleted_size'] += $sz;
            if (!$dry_run) @unlink($path);
        }
        if (!in_array($rel_key, $state['deleted_files'], true)) {
            $state['deleted_files'][] = $rel_key;
            $logged = true;
        }
        $state['processed']++;
        $state['done'][$rel_key] = true;
        ctw_set_job_state($state);
        return;
    }

    if ($ext === 'webp') {
        $state['skipped']++;
        ctw_log_skipped($state, $rel_key, 'Already WebP');
        $logged = true;
        $state['processed']++;
        $state['done'][$rel_key] = true;
        ctw_set_job_state($state);
        return;
    }
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'heic'])) {
        $state['skipped']++;
        ctw_log_skipped($state, $rel_key, 'Unsupported extension');
        $logged = true;
        $state['processed']++;
        $state['done'][$rel_key] = true;
        ctw_set_job_state($state);
        return;
    }

    try {
        $imagick = new Imagick($path);
        $imagick->autoOrient();
        if ($imagick->getImageColorspace() !== Imagick::COLORSPACE_RGB) {
            $imagick->transformImageColorspace(Imagick::COLORSPACE_RGB);
        }
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        if ($enable_resize && max($width, $height) > $threshold) {
            if ($width >= $height) {
                $new_width = $threshold;
                $new_height = intval($height * ($new_width / $width));
            } else {
                $new_height = $threshold;
                $new_width = intval($width * ($new_height / $height));
            }
            $imagick->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, 1);
        }
        if ($strip_meta) {
            $imagick->stripImage();
        }
        $imagick->setImageFormat('webp');
        $imagick->setImageCompressionQuality($quality);
        $webp_path = preg_replace('/\.(jpe?g|png|heic)$/i', '.webp', $path);

        if ($dry_run) {
            // Main WebP in memory
            $sim = clone $imagick;
            if ($strip_meta) {
                $sim->stripImage();
            }
            $sim->setImageFormat('webp');
            $sim->setImageCompressionQuality($quality);
            $blob = $sim->getImagesBlob();
            $sim_main_size = strlen($blob);
            $sim->clear();
            $sim->destroy();

            $orig_sz = file_exists($path) ? filesize($path) : 0;

            // If enabled, skip when WebP would be larger
            if ($skip_if_larger && $sim_main_size > $orig_sz) {
                $state['skipped']++;
                ctw_log_skipped(
                    $state,
                    $rel_key . ' ('. ctw_format_bytes($sim_main_size) . ' > ' . ctw_format_bytes($orig_sz) . ')',
                    'WebP larger than original'
                );
                $logged = true;
                $state['processed']++;
                $state['done'][$rel_key] = true;
                ctw_set_job_state($state);
                return;
            }

            // Count main webp as created (since we would create it)
            $state['webp_size'] += $sim_main_size;
            $state['created_webp_files']++;
            $state['total_created_files']++;

            // Simulate thumbnails in memory (only if we didn't skip)
            $allowed = ctw_get_allowed_subsizes($params['disabled_sizes'] ?? []);
            $created_thumbs_count = 0;
            $created_thumbs_bytes = 0;
            foreach ($allowed as $name => $args) {
                $tw = (int) $args['width'];
                $th = (int) $args['height'];
                $crop = !empty($args['crop']);
                if (!$tw && !$th) continue;

                [$rw, $rh, $cx, $cy] = ctw_calc_resize_dims($width, $height, $tw, $th, $crop);
                if ($rw <= 0 || $rh <= 0) continue;

                $thumb = clone $imagick;
                if ($strip_meta) {
                    $thumb->stripImage();
                }
                $thumb->resizeImage($rw, $rh, Imagick::FILTER_LANCZOS, 1);
                if ($crop && $tw && $th) {
                    $thumb->cropImage($tw, $th, $cx, $cy);
                }
                $thumb->setImageFormat('webp');
                $thumb->setImageCompressionQuality($thumb_quality);
                $tblob = $thumb->getImagesBlob();
                $created_thumbs_bytes += strlen($tblob);
                $created_thumbs_count++;
                $thumb->clear();
                $thumb->destroy();
            }
            $state['created_thumbs_size'] += $created_thumbs_bytes;
            $state['created_thumbs_count'] += $created_thumbs_count;
            $state['total_created_files']  += $created_thumbs_count;

            // As in real run: conversion + planned deletion of original
            $state['converted']++;
            $state['processed']++;
            $rel_webp = preg_replace('/\.(jpe?g|png|heic)$/i', '.webp', $rel_key);

            $entry = sprintf(
                '%s (%s) → %s (%s)',
                $rel_key,
                ctw_format_bytes($orig_sz),
                $rel_webp,
                ctw_format_bytes($sim_main_size)
            );
            if (!in_array($entry, $state['converted_files'], true)) {
                $state['converted_files'][] = $entry;
                $logged = true;
            }

            // Only simulate deletion of the original if it's NOT still referenced anywhere
            $old_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $path);
            if (ctw_db_has_reference_to_url($old_url)) {
                // Keep original – it's still referenced somewhere in DB
                // Do NOT log as skipped, just do not delete
            } else {
                if ($enable_original_deletion) { // <-- only delete if enabled
                    $state['deleted_originals']++;
                    $state['deleted_originals_size'] += $orig_sz;
                    $state['total_deleted_files']++;
                    $state['deleted_size'] += $orig_sz;
                    if (!in_array($rel_key, $state['deleted_files'], true)) {
                        $state['deleted_files'][] = $rel_key;
                        $logged = true;
                    }
                } else {
                    ctw_log_skipped($state, $rel_key, 'Original deletion disabled');
                    $logged = true;
                }
            }

            $state['done'][$rel_key] = true;
            ctw_set_job_state($state);
            return;
        } else {
            // REAL RUN
            $imagick->writeImage($webp_path);
            $rel_webp  = ctw_rel_from_abs($webp_path);
            $webp_bytes = file_exists($webp_path) ? filesize($webp_path) : 0;
            $orig_size  = file_exists($path) ? filesize($path) : 0;

            // If enabled, skip when WebP ended up larger
            if ($skip_if_larger && $webp_bytes > $orig_size) {
                // remove created webp, keep original intact
                @unlink($webp_path);
                $state['skipped']++;
                ctw_log_skipped(
                    $state,
                    $rel_key . ' ('. ctw_format_bytes($webp_bytes) . ' > ' . ctw_format_bytes($orig_size) . ')',
                    'WebP larger than original'
                );
                $logged = true;
                $state['processed']++;
                $state['done'][$rel_key] = true;
                ctw_set_job_state($state);
                return;
            }

            // proceed with normal conversion bookkeeping
            $state['created_webp_files']++;
            $state['total_created_files']++;
            $state['webp_size'] += $webp_bytes;

            $entry = sprintf(
                '%s (%s) → %s (%s)',
                $rel_key,
                ctw_format_bytes($orig_size),
                $rel_webp,
                ctw_format_bytes($webp_bytes)
            );
            if (!in_array($entry, $state['converted_files'], true)) {
                $state['converted_files'][] = $entry;
            }
            // Update URLs in meta/options
            ctw_update_custom_fields_urls($attachment_id, $path, $webp_path, false);

            // Attachment metadata & MIME
            if ($attachment_id) {
                update_attached_file($attachment_id, $webp_path);
                $meta = wp_generate_attachment_metadata($attachment_id, $webp_path);
                wp_update_attachment_metadata($attachment_id, $meta);
                wp_update_post(['ID' => $attachment_id, 'post_mime_type' => 'image/webp']);
                if (!empty($meta['sizes'])) {
                    foreach ($meta['sizes'] as $size) {
                        $state['created_thumbs_count']++;
                        $state['total_created_files']++;
                        $p = path_join(dirname($webp_path), $size['file']);
                        if (file_exists($p)) $state['created_thumbs_size'] += filesize($p);
                        $rel_created = ctw_rel_from_abs($p);
                        // (Optional: not required by user to display, but safe to track)
                        // We won't render this list unless needed; keeping minimal footprint.
                    }
                }
            }

            $orig_size = file_exists($path) ? filesize($path) : 0;
            // Update custom fields already happened above; now only delete if no references remain.
            $old_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $path);
            if (ctw_db_has_reference_to_url($old_url)) {
                // Keep original – it's still referenced somewhere in DB. Do NOT log as skipped, just do not delete
                // ...no action...
            } else {
                if ($enable_original_deletion) { // <-- only delete if enabled
                    @unlink($path);
                    $state['deleted_originals']++;
                    $state['deleted_originals_size'] += $orig_size;
                    $state['total_deleted_files']++;
                    $state['deleted_size'] += $orig_size;
                    if (!in_array($rel_key, $state['deleted_files'], true)) {
                        $state['deleted_files'][] = $rel_key;
                    }
                } else {
                    ctw_log_skipped($state, $rel_key, 'Original deletion disabled');
                }
            }

            $state['converted']++;
            $state['processed']++;
        }
        $imagick->clear();
        $imagick->destroy();
    } catch (Exception $e) {
        $state['skipped']++;
        $state['processed']++;
        if (!in_array($rel_key, $state['skipped_files'], true)) {
            ctw_log_skipped($state, $rel_key, 'error: ' . $e->getMessage());
            $logged = true;
        }
    }

    if (!$logged) {
        // Fallback: mark as skipped with reason so UI lists always match counters
        $state['skipped']++;
        ctw_log_skipped($state, $rel_key, 'Unspecified');
    }

    $state['done'][$rel_key] = true;
    ctw_set_job_state($state);
}

function ctw_as_finalize_job($job_id)
{
    $state = ctw_get_job_state();
    if (!$state || intval($state['id']) !== intval($job_id)) return;

    $total = intval($state['total']);
    $done  = is_array($state['done']) ? count($state['done']) : 0;
    if (!empty($state['cancel_requested'])) {
        $state['status'] = 'cancelled';
        $state['message'] = 'Cancelled';
        $state['ended_at'] = current_time('mysql');
        ctw_set_job_state($state);
        return;
    }
    if ($done < $total) {
        // Not done yet – check again shortly
        as_schedule_single_action(time() + 10, 'ctw_finalize_job', [$job_id], CTW_AS_GROUP);
        return;
    }

    // Optionally delete empty folders (or log them in dry-run)
    $params = $state['params'];
    $delete_folders = !empty($params['delete_empty_folders']);
    $dry_run = !empty($params['dry_run']);
    if ($delete_folders && !empty($params['folders'])) {
        $upload = wp_upload_dir();
        $base   = trailingslashit($upload['basedir']);
        $dirs_to_check = [];

        foreach ($params['folders'] as $folder) {
            $root = $base . $folder;
            if (!is_dir($root)) continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $node) {
                if ($node->isDir()) $dirs_to_check[] = $node->getPathname();
            }
            $dirs_to_check[] = $root; // include root
        }

        foreach ($dirs_to_check as $dir) {
            if (is_dir($dir) && !(new FilesystemIterator($dir))->valid()) {
                $rel_dir = ltrim(str_replace($base, '', $dir), '/\\');
                if ($dry_run) {
                    if (!in_array($rel_dir, $state['deleted_folders_list'], true)) {
                        $state['deleted_folders_list'][] = $rel_dir;
                    }
                } else {
                    if (@rmdir($dir)) {
                        $state['deleted_folders']++;
                        if (!in_array($rel_dir, $state['deleted_folders_list'], true)) {
                            $state['deleted_folders_list'][] = $rel_dir;
                        }
                    }
                }
            }
        }
    }

    $state['status'] = 'completed';
    $state['message'] = 'Completed';
    $state['ended_at'] = current_time('mysql');
    ctw_set_job_state($state);
}


function ctw_get_upload_folders()
{
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $years = [];
    $max_count = 500; // Limit file count per folder for performance

    if (!is_dir($base_dir)) {
        return $years;
    }

    foreach (scandir($base_dir) as $year) {
        if (!preg_match('/^\d{4}$/', $year)) continue;
        $year_path = $base_dir . DIRECTORY_SEPARATOR . $year;
        if (!is_dir($year_path)) continue;

        $year_total = 0; // sum of files in all months for this year

        foreach (scandir($year_path) as $month) {
            if (!preg_match('/^\d{2}$/', $month)) continue;
            $month_path = $year_path . DIRECTORY_SEPARATOR . $month;
            if (is_dir($month_path)) {
                // Fast count: glob with limit
                $pattern = $month_path . DIRECTORY_SEPARATOR . '*';
                $files = glob($pattern, GLOB_NOSORT);
                $file_count = 0;
                if (is_array($files)) {
                    $file_count = count($files);
                    if ($file_count > $max_count) {
                        $file_count = $max_count . '+';
                    }
                }
                $years[$year][] = [
                    'month' => $month,
                    'count' => $file_count,
                ];
                if (is_numeric($file_count)) {
                    $year_total += $file_count;
                } else {
                    $year_total = $max_count . '+';
                }
            }
        }
        // Store the year total as a special key for UI display
        $years[$year . '_total'] = $year_total;
    }

    krsort($years);
    foreach ($years as $k => &$months) {
        if (is_array($months)) {
            usort($months, function($a, $b) {
                return strcmp($a['month'], $b['month']);
            });
        }
    }

    return $years;
}


function ctw_render_admin_page()
{
    if (! ctw_user_can()) {
        wp_die(__('Sorry, you are not allowed to access this page.', 'anwert-image-optimizer'));
    }
    $folders = ctw_get_upload_folders();
    $upload_dir = wp_upload_dir();
    $uploads_path = is_array($upload_dir) && !empty($upload_dir['basedir']) ? $upload_dir['basedir'] : '';
?>
    <div class="wrap">
        <h1>Optimize Media</h1>
        <form method="post" action="">
            <input type="hidden" name="page" value="optimize-media">
            <?php wp_nonce_field('ctw_convert_action', 'ctw_nonce'); ?>

            <div id="poststuff">
                <div class="ctw-grid">
                    <div class="ctw-col-left">
                        <div class="postbox">
                            <h2 class="hndle" style="font-size:18px; margin-top: 10px"><span>Select Folders</span></h2>
                            <div class="inside">
                                <div style="max-height:600px; overflow-y:auto; border:1px solid #ccc; padding:10px">
                                    <?php if (empty($folders)) : ?>
                                        <div class="notice notice-info inline" style="margin:0;">
                                            <p><strong>No upload folders found.</strong>
                                                <?php if (!empty($uploads_path)) : ?>
                                                    The uploads directory appears to be empty at <code><?= esc_html($uploads_path) ?></code>.
                                                <?php endif; ?>
                                                After you upload media, year/month folders (e.g., <code>2025/07</code>) will show here.</p>
                                        </div>
                                    <?php else : ?>
                                        <?php foreach ($folders as $year => $months):
                                            // Only process year keys (not *_total)
                                            if (!preg_match('/^\d{4}$/', $year)) continue;
                                            $year_total = isset($folders[$year . '_total']) ? $folders[$year . '_total'] : 0;
                                        ?>
                                            <div style="margin-bottom: 5px;">
                                                <strong>
                                                    <label>
                                                        <input type="checkbox" onclick="toggleYear('<?= esc_attr($year) ?>', this.checked)">
                                                        <?= esc_html($year) ?>
                                                        <span style="color:#888;font-size:90%;">(<?= esc_html($year_total) ?> file<?= $year_total == 1 ? '' : 's' ?>)</span>
                                                    </label>
                                                </strong>
                                                <ul style="margin-left:20px;">
                                                    <?php foreach ($months as $monthInfo):
                                                        $folder = $year . '/' . $monthInfo['month'];
                                                        $file_count = $monthInfo['count'];
                                                    ?>
                                                        <li>
                                                            <label>
                                                                <input type="checkbox" class="folder-<?= esc_attr($year) ?>" name="folders[]" value="<?= esc_attr($folder) ?>"
                                                                    <?= (isset($_POST['folders']) && in_array($folder, $_POST['folders'])) ? 'checked' : '' ?>>
                                                                <?= esc_html($monthInfo['month']) ?>
                                                                <span style="color:#888;font-size:90%;">(<?= esc_html($file_count) ?> file<?= $file_count == 1 ? '' : 's' ?>)</span>
                                                            </label>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($folders)) : ?>
                                    <p>
                                        <button type="button" class="button" id="ctw-select-all">Select All</button>
                                    </p>
                                <?php endif; ?>
                                <script>
                                    // Select All: tick all month + year checkboxes and cascade
                                    document.getElementById('ctw-select-all')?.addEventListener('click', () => {
                                        // select all months
                                        document.querySelectorAll("input[name='folders[]']").forEach(cb => cb.checked = true);
                                        // select all years and cascade
                                        document.querySelectorAll('input[type="checkbox"][onclick^="toggleYear"]').forEach(cb => {
                                            const m = cb.getAttribute('onclick').match(/toggleYear\('([0-9]{4})'/);
                                            cb.checked = true;
                                            if (m && m[1]) {
                                                toggleYear(m[1], true);
                                            }
                                        });
                                        // refresh UI state
                                        if (typeof onFolderChange === 'function') onFolderChange();
                                    });

                                    // Define the cascading function for year -> months
                                    function toggleYear(year, checked) {
                                        document.querySelectorAll('.folder-' + year).forEach(cb => {
                                            cb.checked = checked;
                                        });
                                        if (typeof onFolderChange === 'function') onFolderChange();
                                    }

                                    // Ensure root (year) toggles always cascade to children on change (mouse/keyboard)
                                    document.querySelectorAll('input[type="checkbox"][onclick^="toggleYear"]').forEach(cb => {
                                        cb.addEventListener('change', () => {
                                            const m = cb.getAttribute('onclick').match(/toggleYear\('([0-9]{4})'/);
                                            if (m && m[1]) {
                                                toggleYear(m[1], cb.checked);
                                            }
                                        });
                                    });
                                </script>
                            </div>
                        </div>
                    </div>

                    <div class="ctw-col-right">
                        <div class="postbox">
                            <h2 class="hndle" style="font-size:18px; margin-top: 10px"><span>Settings</span></h2>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Delete</th>
                                        <td>
                                            <label><input type="checkbox" name="enable_original_deletion" value="1" <?= !isset($_POST['enable_original_deletion']) || $_POST['enable_original_deletion'] ? 'checked' : '' ?>>Delete originals</label><br>
                                            <label><input type="checkbox" name="enable_thumb_deletion" value="1" <?= !isset($_POST['enable_thumb_deletion']) || $_POST['enable_thumb_deletion'] ? 'checked' : '' ?>>Delete thumbnails</label><br>
                                            <label><input type="checkbox" name="delete_empty_folders" value="1" <?= !isset($_POST['delete_empty_folders']) || $_POST['delete_empty_folders'] ? 'checked' : '' ?>>Delete empty folders</label><br>
                                            <label><input type="checkbox" name="strip_meta" value="1" <?= !isset($_POST['strip_meta']) || $_POST['strip_meta'] ? 'checked' : '' ?>>Remove EXIF/metadata</label><br>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Skip</th>
                                        <td>
                                            <label><input type="checkbox" name="ignore_unattached" value="1" <?= !isset($_POST['ignore_unattached']) || $_POST['ignore_unattached'] ? 'checked' : '' ?>>Skip unattached files</label><br>
                                            <label><input type="checkbox" name="skip_if_larger" value="1" <?= !isset($_POST['skip_if_larger']) || $_POST['skip_if_larger'] ? 'checked' : '' ?>>Skip conversion if WebP would be larger than original</label><br><br>
                                            <!-- Moved "Skip files from users" here -->
                                            <div style="margin-top:8px;">
                                                <label for="ctw-exclude-users" style="font-weight:600;">Skip files uploaded by certain users</label>
                                                <div id="ctw-exclude-users" class="ctw-user-picker">
                                                    <input type="text" id="ctw-user-search" placeholder="Search users by name, login, or email…" autocomplete="off" style="min-width:280px;">
                                                    <div id="ctw-user-results" class="ctw-user-results" style="position:relative;">
                                                        <ul style="position:absolute;z-index:10;background:#fff;border:1px solid #c3c4c7;max-height:200px;overflow:auto;display:none;margin-top:4px;"></ul>
                                                    </div>
                                                    <!-- Hidden inputs will be appended here as: <input type="hidden" name="exclude_users[]" value="ID"> -->
                                                </div>
                                                <div class="ctw-user-tokens" style="display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 0 0;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Creation</th>
                                        <td>
                                            <label><input type="checkbox" name="create_webp" value="1" <?= !isset($_POST['create_webp']) || $_POST['create_webp'] ? 'checked' : '' ?>>Create WebP versions</label><br><br>
                                            <!-- Inverted logic: "Create thumbnail sizes" -->
                                            <div style="margin: 8px 0px 30px 0px;">
                                                <label style="font-weight:600">Create thumbnail sizes</label><br><br>
                                                <?php
                                                $all_sizes = get_intermediate_image_sizes();
                                                $enabled_sizes = isset($_POST['enabled_sizes']) ? (array)$_POST['enabled_sizes'] : $all_sizes;
                                                if (!isset($_POST['enabled_sizes'])) {
                                                    $enabled_sizes = $all_sizes;
                                                }
                                                // add_filter('intermediate_image_sizes', function ($sizes) use ($enabled_sizes) {
                                                //     return array_values(array_intersect($sizes, $enabled_sizes));
                                                // });
                                                // add_filter('intermediate_image_sizes_advanced', function ($sizes) use ($enabled_sizes) {
                                                //     return array_intersect_key($sizes, array_flip($enabled_sizes));
                                                // });

                                                //foreach ($all_sizes as $size):
                                                //    $width  = get_option("{$size}_size_w");
                                                //    $height = get_option("{$size}_size_h");
                                                //    $label = preg_match('/^\d+x\d+$/', $size) ? $size : "{$size} ({$width}x{$height})";
                                                ?>
                                                    <!-- <label style="margin-right: 10px; display:inline-block; margin-bottom:6px;">
                                                        <input type="checkbox" name="enabled_sizes[]" value="<?= esc_attr($size) ?>"
                                                            <?= in_array($size, $enabled_sizes, true) ? 'checked' : '' ?>>
                                                        <?= esc_html($label) ?>
                                                    </label> -->
                                                <?php //endforeach; ?>
                                            </div>
                                            <label><input type="checkbox" id="ctw-enable-resize" name="enable_resize" value="1" <?= !isset($_POST['enable_resize']) || $_POST['enable_resize'] ? 'checked' : '' ?>>Resize large images</label><br>
                                            <div id="ctw-threshold-row" style="<?= (isset($_POST['enable_resize']) && !$_POST['enable_resize']) ? 'display:none;' : '' ?>;margin-top:6px;">
                                                <label for="threshold">Resize long side to a maximum of (px): </label>
                                                <input type="number" id="threshold" name="threshold" min="0" value="<?= esc_attr($_POST['threshold'] ?? 2048) ?>">
                                            </div>
                                           <div id="ctw-quality-row" style="margin-top:6px;">
                                                <div style="margin-top:10px;">
                                                    <label for="quality">Quality of full-size (0–100)</label>
                                                    <input type="number" id="quality" name="quality" min="0" max="100" value="<?= esc_attr($_POST['quality'] ?? 85) ?>">
                                                </div>
                                                <div style="margin-top:10px;">
                                                    <label for="thumb_quality">Quality of thumbnail (0–100)</label>
                                                    <input type="number" id="thumb_quality" name="thumb_quality" min="0" max="100" value="<?= esc_attr($_POST['thumb_quality'] ?? 70) ?>">
                                                </div>
                                            </div>                                            
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="thumb_quality">Processing</label></th>
                                        <td>
                                            <label><input type="checkbox" id="ctw-dry-run" name="dry_run" value="1" <?= !isset($_POST['dry_run']) || $_POST['dry_run'] ? 'checked' : '' ?>>Dry run</label>
                                            <br>
                                            <!-- New: Limit number of files processed -->
                                            <label style="margin-top:8px;display:inline-block;"><input type="checkbox" id="ctw-limit-files" name="limit_files" value="1" <?= !empty($_POST['limit_files']) ? 'checked' : '' ?>>Limit number of files</label>
                                            <input type="number" id="ctw-max-files" name="max_files" min="1" style="margin-left:10px;width:90px;<?= empty($_POST['limit_files']) ? 'display:none;' : '' ?>" value="<?= esc_attr($_POST['max_files'] ?? '') ?>" placeholder="Max files">
                                            <script>
                                                (function() {
                                                    var limitCb = document.getElementById('ctw-limit-files');
                                                    var maxInput = document.getElementById('ctw-max-files');
                                                    if (limitCb && maxInput) {
                                                        function updateMaxInput() {
                                                            maxInput.style.display = limitCb.checked ? '' : 'none';
                                                        }
                                                        limitCb.addEventListener('change', updateMaxInput);
                                                        updateMaxInput();
                                                    }
                                                })();
                                            </script>
                                        </td>
                                    </tr>
                                </table>


                                <div class="ctw-controls">
                                    <button type="button" class="button button-primary" id="ctw-start-job" disabled>Start in Background</button>
                                    <button type="button" class="button" id="ctw-cancel-job" disabled>Stop</button>
                                </div>
                            </div>
                        </div>
                        <div class="postbox">
                            <h2 class="hndle" style="font-size:18px; margin-top: 10px"><span>Results</span></h2>
                            <div class="inside">
                                <div class="ctw-results-actions" style="display:flex; justify-content:flex-start; margin-bottom:8px;">
                                    <button type="button" class="button" id="ctw-clear-results">Clear Results</button>
                                </div>
                                <div class="ctw-progress__wrap">
                                    <div class="ctw-progress">
                                        <div class="ctw-progress__bar" id="ctw-bar"></div>
                                    </div>
                                    <p id="ctw-status-text"></p>
                                </div>
                                <div id="ctw-results" class="ctw-results"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                (function() {
                    const $ = document.querySelector.bind(document);
                    const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
                    const picker = $('#ctw-exclude-users');
                    let tokensWrap, searchInput, userResultsUL;

                    if (picker) {
                        tokensWrap = document.querySelector('.ctw-user-tokens');
                        searchInput = $('#ctw-user-search');
                        userResultsUL = picker.querySelector('.ctw-user-results ul');



                        function addToken(user) {
                            if ($(`input[type="hidden"][name="exclude_users[]"][value="${user.id}"]`, picker)) return;
                            const token = document.createElement('span');
                            token.className = 'ctw-chip';
                            token.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#f0f0f1;border:1px solid #c3c4c7;padding:2px 8px;border-radius:16px;';
                            token.innerHTML = `<span>${escapeHtml(user.name)} (ID ${user.id})</span> <button type="button" aria-label="Remove" style="border:none;background:none;cursor:pointer;">×</button>`;
                            token.querySelector('button').addEventListener('click', () => {
                                const hidden = $(`input[type="hidden"][name="exclude_users[]"][value="${user.id}"]`, picker);
                                if (hidden) hidden.remove();
                                token.remove();
                            });
                            tokensWrap.appendChild(token);
                            const hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'exclude_users[]';
                            hidden.value = String(user.id);
                            picker.appendChild(hidden);
                        }

                        function escapeHtml(s) {
                            return String(s).replace(/[&<>"']/g, m => ({
                                '&': '&amp;',
                                '<': '&lt;',
                                '>': '&gt;',
                                '"': '&quot;',
                                "'": '&#39;'
                            } [m]));
                        }

                        let lastTerm = '';
                        let timer = null;

                        searchInput.addEventListener('input', () => {
                            clearTimeout(timer);
                            const term = searchInput.value.trim();
                            if (!term) {
                                userResultsUL.style.display = 'none';
                                userResultsUL.innerHTML = '';
                                return;
                            }
                            timer = setTimeout(async () => {
                                if (term === lastTerm) return;
                                lastTerm = term;
                                const fd = new FormData();
                                fd.append('action', 'ctw_user_search');
                                fd.append('nonce', CTW.nonce);
                                fd.append('q', term);
                                try {
                                    const r = await fetch(CTW.ajax, {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        body: fd
                                    });
                                    const j = await r.json();
                                    if (!j.success) {
                                        userResultsUL.style.display = 'none';
                                        userResultsUL.innerHTML = '';
                                        return;
                                    }
                                    const list = (j.data || []).filter(u => !document.querySelector(`input[name="exclude_users[]"][value="${u.id}"]`));
                                    if (!list.length) {
                                        userResultsUL.style.display = 'none';
                                        userResultsUL.innerHTML = '';
                                        return;
                                    }
                                    userResultsUL.innerHTML = list.map(u => `<li data-id="${u.id}" style="padding:6px 8px;cursor:pointer;">${escapeHtml(u.name)} <small style="opacity:.7;">(${escapeHtml(u.login)} • ${escapeHtml(u.email)})</small></li>`).join('');
                                    userResultsUL.style.display = 'block';
                                } catch (e) {
                                    /* noop */
                                }
                            }, 250);
                        });

                        userResultsUL.addEventListener('click', (e) => {
                            const li = e.target.closest('li[data-id]');
                            if (!li) return;
                            const id = parseInt(li.getAttribute('data-id'), 10);
                            const txt = li.textContent.trim();
                            addToken({
                                id,
                                name: txt.replace(/\s*\(.+$/, '')
                            });
                            userResultsUL.style.display = 'none';
                            userResultsUL.innerHTML = '';
                            searchInput.value = '';
                        });

                        // Restore tokens after POST (if any)
                        $$('#ctw-exclude-users input[name="exclude_users[]"]').forEach(h => {
                            // We don't have names now; show as ID tokens
                            addToken({
                                id: parseInt(h.value, 10),
                                name: 'User'
                            });
                        });

                    }

                    const bar = $('#ctw-bar');
                    const txt = $('#ctw-status-text');
                    const startBtn = $('#ctw-start-job');
                    const stopBtn = $('#ctw-cancel-job');
                    const form = document.querySelector('form');
                    const dryRunCb = document.querySelector('#ctw-dry-run');
                    const resultsBox = document.getElementById('ctw-results');
                    const nf = new Intl.NumberFormat(undefined);
                    const fmtInt = (n) => nf.format(Number(n) || 0);

                    function fmtBytes(n, options) {
                        let b = Number(n);
                        if (!isFinite(b)) b = 0;
                        const sign = b < 0 ? '-' : '';
                        b = Math.abs(b);

                        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
                        let i = 0;
                        while (b >= 1024 && i < units.length - 1) {
                            b /= 1024;
                            i++;
                        }

                        // Optional: enforce minimum unit, e.g. { minUnit: 'KB' }
                        const minUnit = options && options.minUnit ? String(options.minUnit).toUpperCase() : 'B';
                        let minIdx = units.indexOf(minUnit);
                        if (minIdx < 0) minIdx = 0;
                        while (i < minIdx) {
                            b /= 1024;
                            i++;
                        }

                        const fractionDigits = (i === 0) ? 0 : 1; // bytes = integer, others = 1 decimal
                        const value = nf.format(Number(b.toFixed(fractionDigits)));
                        return sign + value + ' ' + units[i];
                    }

                    function esc(s) {
                        return String(s).replace(/[&<>"']/g, function(m) {
                            return {
                                '&': '&amp;',
                                '<': '&lt;',
                                '>': '&gt;',
                                '"': '&quot;',
                                "'": '&#39;',
                            } [m];
                        });
                    }

                    function groupByReason(entries) {
                        const groups = {};
                        (entries || []).forEach((e) => {
                            const str = String(e);
                            const idx = str.indexOf(' — ');
                            let file = str;
                            let reason = 'Other';
                            if (idx !== -1) {
                                file = str.slice(0, idx);
                                reason = str.slice(idx + 3) || 'Other';
                            }
                            if (!groups[reason]) groups[reason] = [];
                            groups[reason].push(file);
                        });
                        return groups;
                    }

                    function renderSkippedGrouped(entries) {
                        const groups = groupByReason(entries);
                        const total = (entries || []).length;
                        let inner = '';
                        Object.keys(groups)
                            .sort((a, b) => a.localeCompare(b))
                            .forEach((reason) => {
                                const files = groups[reason];
                                const key = 'skipped-files:' + encodeURIComponent(reason);
                                inner += `
                                <details style="margin:8px 0;" data-key="${key}">
                                  <summary><span style="font-weight:600;">${esc(reason)}</span> (${fmtInt(files.length)})</summary>
                                  <div style="margin:6px 0 0 0; padding-left:12px;">
                                    ${files.map(f => `<div style="display:block;word-break:break-all;">${esc(f)}</div>`).join('')}
                                  </div>
                                </details>`;
                            });
                        if (!inner) inner = '<em>No results for this section.</em>';
                        return `
                        <details style="margin-top:8px;" data-key="skipped-files">
                          <summary><strong>Skipped files</strong> (${fmtInt(total)})</summary>
                          <div style="margin-top:6px; padding-left:16px;">${inner}</div>
                        </details>`;
                    }


                    function renderListBlock(title, arr, key) {
                        if (!Array.isArray(arr)) arr = [];
                        const count = arr.length;
                        const items = count ? arr.map(v => `<div style="display:block;word-break:break-all;">${v}</div>`).join('') : '<em>No results for this section.</em>';
                        const countFmt = fmtInt(count);
                        return `
                        <details style="margin-top:8px;" data-key="${key}">
                        <summary><strong>${title}</strong> (${countFmt})</summary>
                        <div style="margin-top:6px;">${items}</div>
                        </details>
                    `;
                    }

                    function renderResults(state) {
                        if (!resultsBox || !state) {
                            return;
                        }

                        const prevOpen = new Set(
                            Array.from(resultsBox.querySelectorAll('details[open]'))
                            .map(d => d.getAttribute('data-key'))
                        );

                        const converted = state.converted || 0;
                        const skipped = state.skipped || 0;
                        const thumbsDeleted = state.thumbs_deleted || 0;
                        const createdWebPCount = state.created_webp_files || 0;
                        const createdThumbsCount = state.created_thumbs_count || 0;
                        const totalCreatedCount = state.total_created_files || 0;
                        const deletedFolders = state.deleted_folders || 0;
                        const deletedSize = state.deleted_size || 0;
                        const webpSize = state.webp_size || 0;
                        const createdThumbsSize = state.created_thumbs_size || 0;
                        const deletedOriginals = state.deleted_originals || 0;
                        const deletedOriginalsSize = state.deleted_originals_size || 0;
                        const deletedThumbsSize = state.deleted_thumbs_size || 0;
                        const totalDeletedFiles = state.total_deleted_files || (state.deleted_files ? state.deleted_files.length : 0);
                        const selectedFolders = (state.params && Array.isArray(state.params.folders)) ? state.params.folders : [];
                        const totalCreatedSize = (webpSize + createdThumbsSize);
                        const netSaved = deletedSize - totalCreatedSize;

                        const convertedFiles = state.converted_files || [];
                        const skippedFiles = state.skipped_files || [];
                        const deletedFiles = state.deleted_files || [];
                        const deletedFoldersList = state.deleted_folders_list || [];

                        // --- CSV Redirects Section ---
                        function renderRedirectsCSV(convertedFiles) {
                            // Each entry is like: "orig.jpg (123 KB) → orig.webp (45 KB)"
                            // We want to extract the original and webp relative paths
                            const rows = [];
                            rows.push(['source', 'destination']);
                            // Get site URL and uploads base URL
                            const siteUrl = <?= json_encode(site_url()) ?>;
                            const uploadBaseUrl = <?= json_encode($upload_dir['baseurl']) ?>;
                            const uploadBaseDir = <?= json_encode($upload_dir['basedir']) ?>;
                            (convertedFiles || []).forEach(entry => {
                                // Try to extract the file names before and after the arrow
                                const m = String(entry).match(/^(.+?)\s+\([^\)]*\)\s+→\s+(.+?)\s+\([^\)]*\)$/);
                                if (m) {
                                    let relSource = m[1];
                                    let relDest = m[2];
                                    // Ensure leading slash for relative path
                                    if (relSource[0] !== '/') relSource = '/' + relSource;
                                    // Compose absolute URL for destination
                                    let absDest = uploadBaseUrl.replace(/\/+$/, '') + '/' + relDest.replace(/^\/+/, '');
                                    // Compose relative path for source (after site url)
                                    let relSourceFull = uploadBaseUrl.replace(/^https?:\/\/[^\/]+/, '') + '/' + relSource.replace(/^\/+/, '');
                                    // But if relSource already starts with uploads, just use that
                                    if (relSource.match(/^wp-content\//)) {
                                        relSourceFull = '/' + relSource;
                                    }
                                    rows.push([relSourceFull, absDest]);
                                }
                            });
                            // Convert to CSV string
                            const csv = rows.map(row =>
                                row.map(cell =>
                                    '"' + String(cell).replace(/"/g, '""') + '"'
                                ).join(',')
                            ).join('\n');
                            // Render as <pre> for copy-paste, with a download button
                            return `
                                <details style="margin-top:8px;" data-key="redirects-csv">
                                    <summary><strong>Redirects as CSV</strong> (${fmtInt(rows.length - 1)})</summary>
                                    <div style="margin-top:6px;">
                                        <pre style="white-space:pre;overflow-x:auto;background:#f6f7f7;border:1px solid #dcdcde;padding:8px 10px;border-radius:3px;font-size:13px;" id="ctw-redirects-csv-block">${esc(csv)}</pre>
                                        <button type="button" class="button" id="ctw-download-redirects-csv" style="margin-top:8px;">Download CSV</button>
                                    </div>
                                </details>
                            `;
                        }
                        // --- end CSV Redirects Section ---

                        resultsBox.innerHTML = `
                          <hr style="margin:30px 0;">
                          <div style="line-height:1.5em; border:none">
                            <h3>Summary</h3>
                            <div style="display:block;">⏭️ Skipped files: ${fmtInt(skipped)}</div>

                            <div style="display:block;">🗑️ Deleted originals: ${fmtInt(deletedOriginals)} (${fmtBytes(deletedOriginalsSize)})</div>
                            <div style="display:block;">🗑️ Deleted thumbnails: ${fmtInt(thumbsDeleted)} (${fmtBytes(deletedThumbsSize)})</div>

                            <div style="display:block;">🗑️ Total deleted files: ${fmtInt(totalDeletedFiles)} (${fmtBytes(deletedSize)})</div>

                            <div style="display:block;">🗂️ Deleted folders: ${fmtInt(deletedFolders)}</div>

                            <div style="display:block;">🖼️ Created WebP files: ${fmtInt(createdWebPCount)} (${fmtBytes(webpSize)})</div>
                            <div style="display:block;">📐 Created thumbnails: ${fmtInt(createdThumbsCount)} (${fmtBytes(createdThumbsSize)})</div>
                            <div style="display:block;">➕ Total created files: ${fmtInt(totalCreatedCount)} (${fmtBytes(totalCreatedSize)})</div>
                            <div style="display:block;">–––––––––––––––––––––––––––</div>

                            <div style="display:block;font-weight:bold;">💾 Net saved: ${fmtBytes(netSaved)}</div>

                            <hr style="margin:30px 0;">
                            <h3>Details</h3>
                            ${renderListBlock('Selected folders', selectedFolders, 'selected-folders')}
                            ${renderSkippedGrouped(skippedFiles)}
                            ${renderListBlock('Converted files', convertedFiles, 'converted-files')}
                            ${renderListBlock('Deleted files', deletedFiles, 'deleted-files')}
                            ${renderListBlock('Deleted folders', deletedFoldersList, 'deleted-folders')}
                            ${renderRedirectsCSV(convertedFiles)}
                            <div style="margin-top:18px;">
                                <button type="button" class="button" id="ctw-download-log">Download Log</button>
                            </div>
                          </div>
                        `;

                        resultsBox.querySelectorAll('details[data-key]').forEach(d => {
                            const key = d.getAttribute('data-key');
                            if (prevOpen.has(key)) d.setAttribute('open', '');
                        });

                        // --- Download log button logic ---
                        const downloadBtn = resultsBox.querySelector('#ctw-download-log');
                        if (downloadBtn) {
                            downloadBtn.addEventListener('click', function() {
                                // Compose plain text log
                                let log = '';
                                log += 'Anwert Media Optimizer Log\n';
                                log += '==========================\n\n';
                                log += 'Status: ' + (state.status || '') + '\n';
                                log += 'Started: ' + (state.started_at || '') + '\n';
                                log += 'Ended: ' + (state.ended_at || '') + '\n\n';

                                log += 'Summary:\n';
                                log += `  Skipped files: ${fmtInt(skipped)}\n`;
                                log += `  Deleted originals: ${fmtInt(deletedOriginals)} (${fmtBytes(deletedOriginalsSize)})\n`;
                                log += `  Deleted thumbnails: ${fmtInt(thumbsDeleted)} (${fmtBytes(deletedThumbsSize)})\n`;
                                log += `  Total deleted files: ${fmtInt(totalDeletedFiles)} (${fmtBytes(deletedSize)})\n`;
                                log += `  Deleted folders: ${fmtInt(deletedFolders)}\n`;
                                log += `  Created WebP files: ${fmtInt(createdWebPCount)} (${fmtBytes(webpSize)})\n`;
                                log += `  Created thumbnails: ${fmtInt(createdThumbsCount)} (${fmtBytes(createdThumbsSize)})\n`;
                                log += `  Total created files: ${fmtInt(totalCreatedCount)} (${fmtBytes(totalCreatedSize)})\n`;
                                log += `  Net saved: ${fmtBytes(netSaved)}\n\n`;

                                log += 'Selected folders:\n';
                                (selectedFolders || []).forEach(f => { log += '  - ' + f + '\n'; });
                                log += '\n';

                                // Skipped files grouped by reason

                                log += 'Skipped files:\n';
                                const skippedGroups = groupByReason(skippedFiles);
                                Object.keys(skippedGroups).forEach(reason => {
                                    log += `  [${reason}] (${skippedGroups[reason].length})\n`;
                                    skippedGroups[reason].forEach(f => { log += '    - ' + f + '\n'; });
                                });
                                if (!skippedFiles.length) log += '  (none)\n';
                                log += '\n';

                                log += 'Converted files:\n';
                                (convertedFiles || []).forEach(f => { log += '  - ' + f + '\n'; });
                                if (!convertedFiles.length) log += '  (none)\n';
                                log += '\n';

                                log += 'Deleted files:\n';
                                (deletedFiles || []).forEach(f => { log += '  - ' + f + '\n'; });
                                if (!deletedFiles.length) log += '  (none)\n';
                                log += '\n';

                                log += 'Deleted folders:\n';
                                (deletedFoldersList || []).forEach(f => { log += '  - ' + f + '\n'; });
                                if (!deletedFoldersList.length) log += '  (none)\n';
                                log += '\n';

                                // Download as .txt
                                const blob = new Blob([log], { type: 'text/plain' });
                                const url = URL.createObjectURL(blob);
                                const a = document.createElement('a');
                                a.href = url;
                                const date = (state.ended_at || state.started_at || '').replace(/[^0-9]/g, '').slice(0, 12);
                                a.download = `media-optimizer-log${date ? '-' + date : ''}.txt`;
                                document.body.appendChild(a);
                                a.click();
                                setTimeout(() => {
                                    document.body.removeChild(a);
                                    URL.revokeObjectURL(url);
                                }, 100);
                            });
                        }

                        // --- Download redirects CSV button logic ---
                        const downloadRedirectsBtn = resultsBox.querySelector('#ctw-download-redirects-csv');
                        if (downloadRedirectsBtn) {
                            downloadRedirectsBtn.addEventListener('click', function() {
                                const pre = resultsBox.querySelector('#ctw-redirects-csv-block');
                                if (!pre) return;
                                const csv = pre.textContent;
                                const blob = new Blob([csv], { type: 'text/csv' });
                                const url = URL.createObjectURL(blob);
                                const a = document.createElement('a');
                                a.href = url;
                                a.download = 'redirects.csv';
                                document.body.appendChild(a);
                                a.click();
                                setTimeout(() => {
                                    document.body.removeChild(a);
                                    URL.revokeObjectURL(url);
                                }, 100);
                            });
                        }
                    }

                    function updateStartLabel() {
                        if (!startBtn) return;
                        const isDry = dryRunCb && dryRunCb.checked;
                        startBtn.textContent = isDry ? 'Start Dry Run in Background' : 'Start Conversion in Background';
                    }

                    function setBar(p) {
                        if (bar) bar.style.width = Math.max(0, Math.min(100, p)) + '%';
                    }

                    function updateUI(state) {
                        if (!state || state.status === 'idle') {
                            setBar(0);
                            if (txt) txt.textContent = '';
                            startBtn.disabled = !anyFolderChecked();
                            stopBtn.disabled = true;
                            if (resultsBox) resultsBox.innerHTML = '';
                            return;
                        }

                        const total = state.total || 0;
                        const processed = state.processed || 0;
                        const pct = total ? Math.round(processed / total * 100) : (state.status === 'completed' ? 100 : 0);
                        setBar(pct);
                        if (txt) txt.textContent = (state.message || state.status) + ' — ' + fmtInt(processed) + ' / ' + fmtInt(total) + ' files';

                        if (state.status === 'completed') {
                            startBtn.disabled = !anyFolderChecked();
                            stopBtn.disabled = true;
                        } else if (state.status === 'running' || state.status === 'queued') {
                            startBtn.disabled = true;
                            stopBtn.disabled = false;
                        } else if (state.status === 'cancelling') {
                            startBtn.disabled = true;
                            stopBtn.disabled = true; // disable while cancellation in progress
                        } else if (state.status === 'cancelled') {
                            startBtn.disabled = !anyFolderChecked();
                            stopBtn.disabled = true;
                        } else {
                            // fallback
                            startBtn.disabled = !anyFolderChecked();
                            stopBtn.disabled = true;
                        }

                        renderResults(state);
                    }

                    function anyFolderChecked() {
                        return !!document.querySelector('input[name="folders[]"]:checked');
                    }

                    function onFolderChange() {
                        startBtn.disabled = !anyFolderChecked();
                    }
                    document.querySelectorAll('input[name="folders[]"]').forEach(cb => cb.addEventListener('change', onFolderChange));
                    onFolderChange();

                    if (dryRunCb) {
                        dryRunCb.addEventListener('change', updateStartLabel);
                    }

                    updateStartLabel();

                    const resizeCb = document.querySelector('#ctw-enable-resize');
                    const thresholdRow = document.querySelector('#ctw-threshold-row');

                    function updateThresholdVisibility() {
                        if (!thresholdRow) return;
                        thresholdRow.style.display = (resizeCb && !resizeCb.checked) ? 'none' : '';
                    }
                    if (resizeCb) resizeCb.addEventListener('change', updateThresholdVisibility);
                    updateThresholdVisibility();

                    async function poll() {
                        try {
                            const fd = new FormData();
                            fd.append('action', 'ctw_job_status');
                            fd.append('nonce', CTW.nonce);
                            const r = await fetch(CTW.ajax, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: fd
                            });
                            const j = await r.json();
                            if (j.success) updateUI(j.data);
                        } catch (e) {}
                    }
                    let timer = setInterval(poll,  1200);
                    poll();

                    const progressWrap = document.querySelector('.ctw-progress__wrap');
                    startBtn?.addEventListener('click', async (e) => {
                        e.preventDefault();
                        if (!anyFolderChecked()) return;
                        const fd = new FormData(form);
                        fd.set('action', 'ctw_start_job');
                        fd.append('nonce', CTW.nonce);
                        try {
                            const r = await fetch(CTW.ajax, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: fd
                            });
                            const j = await r.json();
                            if (j.success) {
                                if (progressWrap) progressWrap.style.display = '';
                                if (bar) bar.style.width = '0%';
                                if (txt) txt.textContent = 'Starting…';
                                poll();
                            } else {
                                alert(j.data && j.data.message ? j.data.message : 'Failed to start');
                            }
                        } catch (err) {
                            alert('Failed to start');
                        }
                    });

                    const clearBtn = document.getElementById('ctw-clear-results');
                    clearBtn?.addEventListener('click', async (e) => {
                        e.preventDefault();
                        const fd = new FormData();
                        fd.append('action', 'ctw_clear_results');
                        fd.append('nonce', CTW.nonce);
                        try {
                            const r = await fetch(CTW.ajax, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: fd
                            });
                            const j = await r.json();
                            if (j.success) {
                                // Clear UI immediately
                                if (resultsBox) resultsBox.innerHTML = '';
                                if (bar) bar.style.width = '0%';
                                if (txt) txt.textContent = '';
                                // Hide progress bar
                                if (progressWrap) progressWrap.style.display = 'none';

                                // Re-enable Start if folders are selected
                                startBtn.disabled = !anyFolderChecked();
                                stopBtn.disabled = true;
                            } else {
                                alert('Could not clear results.');
                            }
                        } catch (err) {
                            alert('Could not clear results.');
                        }
                    });

                    stopBtn?.addEventListener('click', async (e) => {
                        e.preventDefault();
                        stopBtn.disabled = true; // 🔒 disable immediately on request
                        const fd = new FormData();
                        fd.append('action', 'ctw_cancel_job');
                        fd.append('nonce', CTW.nonce);
                        try {
                            const r = await fetch(CTW.ajax, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: fd
                            });
                            const j = await r.json();
                            if (!j.success) {
                                alert('Failed to cancel');
                                return;
                            }
                            // Poll will update UI to 'cancelling' and keep Stop disabled
                            poll();
                        } catch (err) {
                            alert('Failed to cancel');
                        }
                    });
                })();
            </script>
        </form>
    </div>
<?php
}



function ctw_update_custom_fields_urls($attachment_id, $old_path, $new_path, $dry_run)
{
    global $wpdb;

    $upload_dir = wp_upload_dir();
    $old_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $old_path);
    $new_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $new_path);

    // Helper for logging
    $log_change = function ($source, $key, $before, $after) {
        $GLOBALS['ctw_dry_run_replacements'][] = "🔁 [$source] {$key}:\n    → " . esc_html($before) . "\n    ← " . esc_html($after);
    };

    // --- POSTMETA batched by meta_id ---
    $last_id = 0;
    $limit = 500;
    do {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_value LIKE %s ORDER BY meta_id ASC LIMIT %d",
            $last_id,
            '%' . $wpdb->esc_like($old_url) . '%',
            $limit
        ));
        foreach ($rows as $row) {
            $original = maybe_unserialize($row->meta_value);
            $updated = ctw_recursive_replace_url($old_url, $new_url, $original);
            if ($original !== $updated) {
                if ($dry_run) {
                    $log_change('postmeta', "meta_id {$row->meta_id}", maybe_serialize($original), maybe_serialize($updated));
                } else {
                    $wpdb->update($wpdb->postmeta, ['meta_value' => maybe_serialize($updated)], ['meta_id' => $row->meta_id]);
                }
            }
        }
        $last_id = $rows ? end($rows)->meta_id : $last_id;
    } while (!empty($rows) && count($rows) === $limit);

    // --- USERMETA batched by umeta_id ---
    $last_uid = 0;
    $limit = 500;
    do {
               $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE umeta_id > %d AND meta_value LIKE %s ORDER BY umeta_id ASC LIMIT %d",
            $last_uid,
            '%' . $wpdb->esc_like($old_url) . '%',
            $limit
        ));
        foreach ($rows as $row) {
            $original = maybe_unserialize($row->meta_value);
            $updated = ctw_recursive_replace_url($old_url, $new_url, $original);
            if ($original !== $updated) {
                if ($dry_run) {
                    $log_change('usermeta', "umeta_id {$row->umeta_id}", maybe_serialize($original), maybe_serialize($updated));
                } else {
                    $wpdb->update($wpdb->usermeta, ['meta_value' => maybe_serialize($updated)], ['umeta_id' => $row->umeta_id]);
                }
            }
        }
        $last_uid = $rows ? end($rows)->umeta_id : $last_uid;
    } while (!empty($rows) && count($rows) === $limit);

    // --- OPTIONS batched by option_id ---
    $last_oid = 0;
    $limit = 200;
    do {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d AND option_value LIKE %s ORDER BY option_id ASC LIMIT %d",
            $last_oid,
            '%' . $wpdb->esc_like($old_url) . '%',
            $limit
        ));
        foreach ($rows as $row) {
            $original = maybe_unserialize($row->option_value);
            $updated = ctw_recursive_replace_url($old_url, $new_url, $original);
            if ($original !== $updated) {
                if ($dry_run) {
                    $log_change('options', $row->option_name, maybe_serialize($original), maybe_serialize($updated));
                } else {
                    $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($updated)], ['option_id' => $row->option_id]);
                }
            }
        }
        $last_oid = $rows ? end($rows)->option_id : $last_oid;
    } while (!empty($rows) && count($rows) === $limit);
}


function ctw_get_registered_size_dimensions()
{
    if (function_exists('wp_get_registered_image_subsizes')) {
        $subsizes = wp_get_registered_image_subsizes(); // name => [width, height, crop]
        $out = [];
        foreach ($subsizes as $name => $args) {
            $w = isset($args['width']) ? (int)$args['width'] : 0;
            $h = isset($args['height']) ? (int)$args['height'] : 0;
            if ($w && $h) {
                $out[] = "{$w}x{$h}";
            }
        }
        return $out;
    }
    // Fallback (older WP): pull from options
    $sizes = get_intermediate_image_sizes();
    $result = [];
    foreach ($sizes as $size) {
        $w = (int) get_option("{$size}_size_w");
        $h = (int) get_option("{$size}_size_h");
        if ($w && $h) $result[] = "{$w}x{$h}";
    }
    return $result;
}


function ctw_recursive_replace_url($old, $new, $data)
{
    if (is_array($data)) {
        return array_map(fn($val) => ctw_recursive_replace_url($old, $new, $val), $data);
    } elseif (is_string($data)) {
        return str_replace($old, $new, $data);
    } else {
        return $data;
    }
}


function ctw_db_has_reference_to_url($url)
{
    global $wpdb;
    if (!is_string($url) || $url === '') return false;
    $like = '%' . $wpdb->esc_like($url) . '%';

    // posts: post_content or guid
    $found = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s OR guid LIKE %s LIMIT 1",
            $like,
            $like
        )
    );
    if ($found) return true;

    // postmeta
    $found = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 1",
            $like
        )
    );
    if ($found) return true;

    // termmeta
    if (isset($wpdb->termmeta)) {
        $found = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_value LIKE %s LIMIT 1",
                $like
            )
        );
        if ($found) return true;
    }

    // usermeta
    $found = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_value LIKE %s LIMIT 1",
            $like
        )
    );
    if ($found) return true;

    // options
    $found = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1",
            $like
        )
    );
    if ($found) return true;

    return false;
}


function ctw_render_log_section($id, $title, $entries, $open = false)
{
    $display = $open ? 'block' : 'none';
    $count = count($entries);
    $icon = $open ? '▼' : '▶';

    echo <<<HTML
        <div style="margin-top: 10px; padding: 10px 0px; border-bottom: 1px solid #ccc;">
            <h3 style="margin-bottom:15px; font-size: 16px; cursor: pointer;" onclick="toggleLog('{$id}', this)">
                <span class="toggle-icon">{$icon}</span> {$title} ({$count})
            </h3>
            <div id="{$id}" style="display: {$display}; padding-left: 10px; margin-bottom: 20px;">
    HTML;

    if ($count > 0) {
        echo implode("<br>", array_map('esc_html', $entries));
    } else {
        echo "<em>No results for this section.</em>";
    }

    echo "</div></div>";
}


// Disable generation of -scaled images (WordPress 5.3+)
// if (is_admin()) {
//     add_filter('big_image_size_threshold', '__return_false');
// }


add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('tools.php?page=optimize-media');
    $links[] = '<a href="' . esc_url($url) . '">Optimize Media</a>';
    return $links;
});

// ---- Cleanup: ensure no plugin data stays behind ----
if (! function_exists('ctw_cleanup_plugin_data')) {
    function ctw_cleanup_plugin_data($full = true)
    {
        // Cancel any queued/running jobs for our group
        if (function_exists('as_unschedule_all_actions')) {
            @as_unschedule_all_actions('ctw_process_file', [], CTW_AS_GROUP);
            @as_unschedule_all_actions('ctw_finalize_job', [], CTW_AS_GROUP);
        }

        // Best-effort: delete actions from Action Scheduler store (any status) for our group
        if (class_exists('\\ActionScheduler')) {
            try {
                $store = \ActionScheduler::store();
                if ($store && method_exists($store, 'query_actions') && method_exists($store, 'delete_action')) {
                    $statuses = ['pending', 'in-progress', 'complete', 'failed', 'canceled'];
                    foreach ($statuses as $st) {
                        $page = 1;
                        do {
                            $ids = $store->query_actions([
                                'group'    => CTW_AS_GROUP,
                                'status'   => $st,
                                'per_page' => 200,
                                'page'     => $page,
                            ]);
                            if (empty($ids)) break;
                            foreach ((array) $ids as $aid) {
                                try {
                                    $store->delete_action($aid);
                                } catch (\Exception $e) {
                                }
                            }
                            $page++;
                        } while (! empty($ids));
                    }
                }
            } catch (\Exception $e) {
                // ignore
            }
        }

        // Remove any saved state/options created by this plugin
        delete_option('ctw_job_state');
        delete_option('ctw_main_quality');
        delete_option('ctw_thumb_quality');

        // Nothing else is persisted by this plugin. Media conversions/URLs are considered user content and are not reverted.
    }

    // Run cleanup on this site, or across all sites when Multisite
    function ctw_cleanup_all_sites()
    {
        if (is_multisite()) {
            $site_ids = get_sites(['fields' => 'ids']);
            foreach ($site_ids as $blog_id) {
                switch_to_blog($blog_id);
                ctw_cleanup_plugin_data(true);
                restore_current_blog();
            }
        } else {
            ctw_cleanup_plugin_data(true);
        }
    }
}


if (! function_exists('ctw_deactivate_plugin')) {
    function ctw_deactivate_plugin()
    {
        ctw_cleanup_all_sites();
    }
}
register_deactivation_hook(__FILE__, 'ctw_deactivate_plugin');

if (! function_exists('ctw_uninstall_plugin')) {
    function ctw_uninstall_plugin()
    {
        ctw_cleanup_all_sites();
    }
}
register_uninstall_hook(__FILE__, 'ctw_uninstall_plugin');