#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <module-directory> [archive-prefix]" >&2
    exit 64
fi

module_dir=${1%/}
archive_prefix=${2:-$(basename "$module_dir")}

if [[ ! -d "$module_dir/modules" ]]; then
    echo "Missing $module_dir/modules" >&2
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
rm -f "$archive"

(
    cd "$module_dir"
    zip -qr "$archive" modules
)

if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$archive" > "$archive.sha256"
fi

printf 'Created %s\n' "$archive"
