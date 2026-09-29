#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 INSTALLED_PRO_TEAM_PACKAGE_DIR NEW_OUTPUT_DIR" >&2
  exit 2
fi

package_dir="$(cd "$1" && pwd)"
output_dir="$2"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
overlay_dir="$repo_root/ops/production-overrides/pro-team/resources/js/Components/Analytics"

for required in package.json package-lock.json vite.config.js resources; do
  if [[ ! -e "$package_dir/$required" ]]; then
    echo "Missing $package_dir/$required" >&2
    exit 1
  fi
done
if [[ -e "$output_dir" ]]; then
  echo "Output already exists: $output_dir" >&2
  exit 1
fi

build_dir="$(mktemp -d "${TMPDIR:-/tmp}/mixpost-x-analytics.XXXXXX")"
trap 'rm -rf "$build_dir"' EXIT
cp "$package_dir/package.json" "$package_dir/package-lock.json" "$package_dir/vite.config.js" "$build_dir/"
cp -R "$package_dir/resources" "$build_dir/resources"
for component in AnalyticsContentList.vue AnalyticsPostCard.vue AnalyticsPostDetails.vue; do
  cp "$overlay_dir/$component" "$build_dir/resources/js/Components/Analytics/$component"
done

(
  cd "$build_dir"
  npm ci --ignore-scripts
  npm run build
)

dist_dir="$build_dir/resources/dist/vendor/mixpost"
test -f "$dist_dir/manifest.json"
cp "$repo_root/ops/production-overrides/peachy-posting.png" "$dist_dir/peachy-posting.png"
cp -R "$dist_dir" "$output_dir"
echo "Built full Mixpost client bundle in $output_dir"
shasum -a 256 "$output_dir/manifest.json"
