#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <module-directory> [archive-prefix]" >&2
    exit 64
fi

module_dir=${1%/}
archive_prefix=${2:-$(basename "$module_dir")}

if [[ ! -d "$module_dir" ]]; then
    echo "Missing module directory: $module_dir" >&2
    exit 66
fi

if [[ ! -f "$module_dir/VERSION" ]]; then
    echo "Missing $module_dir/VERSION" >&2
    exit 66
fi

version=$(tr -d '[:space:]' < "$module_dir/VERSION")
if [[ -z "$version" ]]; then
    echo "VERSION is empty" >&2
    exit 65
fi

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
dist_dir="$repo_root/dist"
archive="$dist_dir/${archive_prefix}-${version}.zip"

mkdir -p "$dist_dir"
rm -f "$archive" "$archive.sha256"

tmp_dir=""
cleanup() {
    if [[ -n "$tmp_dir" && -d "$tmp_dir" ]]; then
        rm -rf "$tmp_dir"
    fi
}
trap cleanup EXIT

if [[ -d "$module_dir/modules" ]]; then
    # Canonical repository layout: module_dir/modules mirrors the WHMCS root.
    (
        cd "$module_dir"
        zip -qr "$archive" modules
    )
elif [[ "$(basename "$(dirname "$module_dir")")" == "addons" ]]; then
    # Historical flat addon layout: the directory itself is the WHMCS addon directory.
    # Stage it under modules/addons/<slug> without repository-only metadata.
    slug=$(basename "$module_dir")
    tmp_dir=$(mktemp -d)
    staged="$tmp_dir/modules/addons/$slug"
    mkdir -p "$staged"
    cp -a "$module_dir"/. "$staged"/
    rm -f "$staged/README.md" "$staged/CHANGELOG.md" "$staged/VERSION" "$staged/module.json"
    rm -rf "$staged/tests"
    (
        cd "$tmp_dir"
        zip -qr "$archive" modules
    )
else
    echo "Missing $module_dir/modules and no supported flat addon layout detected" >&2
    exit 66
fi

if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$archive" > "$archive.sha256"
fi

printf 'Created %s\n' "$archive"
