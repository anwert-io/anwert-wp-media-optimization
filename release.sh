# If you want to run all of this with just 1 command, you can use the following:
# git config --global alias.release '!./release.sh'
# Then you can run all this with "git release" 

#!/usr/bin/env bash
set -euo pipefail

# ----------------------------
# Config — adjust if needed
# ----------------------------
PLUGIN_MAIN_FILE="plugin/anwert-wp-media-optimization.php"        # path to your main plugin file that has "Version:"
ASSET_DIR="plugin"                                          # directory to zip
ASSET_NAME_PREFIX="anwert-media-optimizer"                  # base name for the zip
CHANGELOG_FILE="CHANGELOG.md"                               # changelog file to read release notes from (Keep a Changelog format)

# ----------------------------
# Helpers
# ----------------------------
die() { echo "Error: $*" >&2; exit 1; }

require_cmd() { command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"; }

# Extract release notes for a given version from CHANGELOG.md (Keep a Changelog style)
# Writes notes to path given as $2. Returns 0 if notes were written and non-empty; 1 otherwise.
extract_changelog_section() {
  local version="$1" out_file="$2"
  [[ -f "$CHANGELOG_FILE" ]] || return 1

  # Match headings like "## [1.2.3] - 2025-08-25" or "## 1.2.3" and capture until next "## " heading
  # If your changelog uses a different pattern, tweak the regex below.
  awk -v ver="$version" '
    BEGIN {start=0}
    /^##[[:space:]]*(\[)?"?"?ver"?"?(\])?[[:space:]]*(-[0-9]{4}-[0-9]{2}-[0-9]{2})?/ {
      # Normalize the line by stripping brackets and quotes for comparison
      line=$0
      gsub(/\[/, "", line); gsub(/\]/, "", line);
      gsub(/\"/, "", line)
      # Extract the token after ##
      match(line, /^##[[:space:]]*([^[:space:]]+)/, m)
      if (m[1] == ver) { start=1; next } else if (start==1) { exit } else { next }
    }
    start==1 { print }
  ' "$CHANGELOG_FILE" | sed '1{/^$/d;}' > "$out_file"

  # Trim trailing blank lines
  sed -i '' -e :a -e '/^\n*$/{$d;N;ba' -e '}' "$out_file" 2>/dev/null || true

  # Return success only if the file is non-empty
  [[ -s "$out_file" ]]
}

# ----------------------------
# Checks
# ----------------------------
require_cmd git
require_cmd gh
require_cmd zip

# Ensure we’re inside a git repo
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "Not a git repository"

# Ensure gh has a repo and we’re authenticated
gh repo view >/dev/null 2>&1 || die "GitHub CLI not authenticated or repo not found (run: gh auth login)"

# Ensure clean working tree (no uncommitted changes)
if ! git diff --quiet || ! git diff --cached --quiet; then
  die "Working tree not clean. Commit or stash your changes first."
fi

# Determine current branch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
echo "Current branch: $CURRENT_BRANCH"

# ----------------------------
# Version resolution
# ----------------------------
# Priority:
# 1) --version X.Y.Z argument
# 2) Read from plugin header "Version:"
VERSION_ARG="${1:-}"
if [[ -n "$VERSION_ARG" && "$VERSION_ARG" != "--version" ]]; then
  die "Usage: $0 [--version X.Y.Z]"
fi

if [[ "${1:-}" == "--version" ]]; then
  VERSION="${2:-}"
  [[ -n "${VERSION}" ]] || die "Provide a version: --version X.Y.Z"
else
  [[ -f "$PLUGIN_MAIN_FILE" ]] || die "Plugin main file not found at: $PLUGIN_MAIN_FILE"
  VERSION=$(grep -E '^\s*\*\s*Version:\s*[0-9]+\.[0-9]+\.[0-9]+' "$PLUGIN_MAIN_FILE" \
    | head -n1 \
    | sed -E 's/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/')
  [[ -n "$VERSION" ]] || die "Could not detect Version: from $PLUGIN_MAIN_FILE"
fi

TAG="v${VERSION}"
echo "Releasing version: $VERSION (tag: $TAG)"

RELEASE_NOTES_FILE=".release-notes-${VERSION}.md"
cleanup_notes() { [[ -f "$RELEASE_NOTES_FILE" ]] && rm -f "$RELEASE_NOTES_FILE"; }
trap cleanup_notes EXIT

# Prevent re-tagging existing tag
if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
  die "Tag $TAG already exists. Bump the version or delete the tag."
fi

# ----------------------------
# Build ZIP asset
# ----------------------------
ASSET_FILE="${ASSET_DIR}/../${ASSET_NAME_PREFIX}-${VERSION}.zip"
echo "Building asset: $ASSET_FILE from $ASSET_DIR"

[[ -d "$ASSET_DIR" ]] || die "Asset directory not found: $ASSET_DIR"

# Create a clean zip (excluding common junk)
rm -f "$ASSET_FILE"
(
  cd "$ASSET_DIR"
  # Zip the directory contents so the zip root is the plugin files (not the whole path)
  zip -r "${ASSET_FILE}" . \
    -x "*.DS_Store" \
    -x "*.git*" \
    -x "node_modules/*"
)
echo "Created $(pwd)/$ASSET_FILE"

# ----------------------------
# Prepare release notes from CHANGELOG (if available)
# ----------------------------
USE_GENERATED_NOTES=true
if extract_changelog_section "$VERSION" "$RELEASE_NOTES_FILE"; then
  echo "Using notes from $CHANGELOG_FILE for version $VERSION"
  USE_GENERATED_NOTES=false
else
  echo "No specific section for $VERSION found in $CHANGELOG_FILE; will use generated notes"
fi

# ----------------------------
# Create tag & push
# ----------------------------
echo "Creating annotated tag: $TAG"
git tag -a "$TAG" -m "Release $TAG"

echo "Pushing tag to origin"
git push origin "$TAG"

# ----------------------------
# Create GitHub Release
# ----------------------------
echo "Creating GitHub release $TAG with asset $ASSET_FILE"
if [[ "$USE_GENERATED_NOTES" == true ]]; then
  gh release create "$TAG" "./${ASSET_FILE}" \
    --title "$TAG" \
    --generate-notes
else
  gh release create "$TAG" "./${ASSET_FILE}" \
    --title "$TAG" \
    --notes-file "$RELEASE_NOTES_FILE"
fi

# Show release URL
REPO_NAME=$(gh repo view --json nameWithOwner -q .nameWithOwner)
echo "✅ Release created: https://github.com/${REPO_NAME}/releases/tag/${TAG}"