# Anwert Media Optimizer

Lightweight PHP utility for optimizing media assets (images, videos) used in the Anwert project.

> NOTE: This README was generated from the repository root where `anwert-media-optimizer.php` is present. If the project has additional configuration or a different entrypoint, update the Usage section accordingly.

## What it does

# Anwert Media Optimizer

- PHP 7.4+ (or the PHP version used by your project)

## Features
- Converts uploaded images to WebP format
- Regenerates thumbnails and cleans up old ones
- Replaces URLs in the database for optimized images
- Deletes unused/orphaned thumbnails and empty folders
- Supports dry run mode (preview changes without modifying files)
- Granular control over which image sizes are processed
- Exclude specific users from optimization
- Resizes images above a threshold
- Strips metadata from images
- Fully async: uses Action Scheduler for background processing

## Requirements
- WordPress (plugin, tested with 5.x+)
- PHP 7.4+ (recommended)
- Imagick extension for best results (fallbacks to GD for some operations)
- Action Scheduler (bundled with WooCommerce or install separately)

## Installation
1. Place `anwert-media-optimizer.php` in your WordPress plugins directory.
2. Activate the plugin from the WordPress admin panel.
3. Ensure Imagick and Action Scheduler are available (see admin notices for missing dependencies).

## Usage

### WordPress Admin
- Go to **Tools > Optimize Media** in the admin menu (visible to site/network admins).
- Configure options:
	- Folders to scan (by month/year)
	- Quality settings for originals and thumbnails
	- Enable/disable resize, meta stripping, deletion of originals/thumbs
	- Exclude users, set file limits, dry run mode, etc.
- Start the job; progress and logs are shown in the UI.

### WP-CLI (if enabled)
If WP-CLI integration is present, you can run optimization jobs via CLI. (Check code for WP_CLI commands or ask for CLI details.)

### AJAX Endpoints
The plugin exposes several AJAX endpoints for job control:
- `ctw_start_job` — start optimization
- `ctw_job_status` — get job status
- `ctw_cancel_job` — cancel running job
- `ctw_clear_results` — clear job results
- `ctw_user_search` — search for users to exclude

## Configuration Options
Most options are set via the admin UI, but can be passed via AJAX or WP-CLI:

- `folders` (array): Folders to scan (e.g., `2025/08`)
- `quality` (int): Main image quality (default 85)
- `thumb_quality` (int): Thumbnail quality (default 70)
- `threshold` (int): Resize threshold (max width/height)
- `dry_run` (bool): Preview changes only
- `enable_resize` (bool): Resize images above threshold
- `strip_meta` (bool): Remove metadata from images
- `disabled_sizes` (array): Image sizes to skip
- `exclude_users` (array): User IDs to exclude
- `delete_empty_folders` (bool): Remove empty folders after cleanup
- `enable_thumb_deletion` (bool): Delete unused thumbnails
- `enable_original_deletion` (bool): Delete original images after conversion
- `create_webp` (bool): Generate WebP versions
- `limit_files` (bool): Limit number of files processed
- `max_files` (int): Max files to process (if limit enabled)

## Example: Start a Job via AJAX
```js
jQuery.post(ajaxurl, {
	action: 'ctw_start_job',
	nonce: CTW.nonce,
	folders: ['2025/08'],
	quality: 85,
	thumb_quality: 70,
	dry_run: true,
	enable_resize: true,
	strip_meta: true,
	disabled_sizes: ['medium_large'],
	exclude_users: [1,2],
	delete_empty_folders: true,
	enable_thumb_deletion: true,
	enable_original_deletion: false,
	create_webp: true,
	limit_files: true,
	max_files: 100
});
```

## Development
- Code is organized as a single plugin file for easy review.
- Uses WordPress hooks, AJAX, and Action Scheduler for async jobs.
- Add unit tests for public functions if extending.
- Run PHP linters/formatters as needed.

## Contributing
1. Fork the repository
2. Make a small, testable change with a clear commit message
3. Open a pull request describing the change and why it's needed

## License
Add a license file if needed (e.g., MIT). This repository does not contain a LICENSE file by default.

## Contact
For questions or help, add your contact or project maintainer details here.

---

This README was enriched with details extracted from `anwert-media-optimizer.php`. For more advanced usage, CLI commands, or troubleshooting, see code comments or ask for specific examples.
- Common PHP extensions (gd, fileinfo) for image handling (install as needed)

## Install / Setup
1. Clone or copy this repository into your project.
2. Ensure PHP is installed and required extensions are available.

## Quick usage
Run the main script from the project root (adjust path if different):

```bash
php anwert-media-optimizer.php
```

If the script is meant to be included from another PHP app, require it:

```php
require_once __DIR__ . '/anwert-media-optimizer.php';
// call functions provided by the script as documented in code comments
```

## Configuration
Check the top of `anwert-media-optimizer.php` for configurable variables or constants (output directories, quality settings, file filters). If there is no configuration block, consider adding a small config array and loading it at runtime.

## Development
- Run linters/formatters your project uses (PHP_CodeSniffer, phpcsfixer) if configured.
- Add unit tests for any public functions to ensure future changes are safe.

## Contributing
1. Fork the repository.
2. Make a small, testable change with a clear commit message.
3. Open a pull request describing the change and why it's needed.

## License
Add a license file if needed (e.g., MIT). This repository does not contain a LICENSE file by default.

## Contact
For questions or help, add your contact or project maintainer details here.

---

Assumptions:
- This repository currently contains a single `anwert-media-optimizer.php` file and no package manifest. If you'd like a richer README (detailed CLI flags, examples, or automated tests), tell me how the script is invoked or share its contents and I will expand the README accordingly.
