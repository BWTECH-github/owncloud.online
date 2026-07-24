#!/usr/bin/env bash
#
# Generate .min.js / .min.css siblings for core and settings assets.
#
# TemplateLayout::preferMinified() serves a foo.min.js when it sits next to
# foo.js (scoped to SERVERROOT = core + settings only). This script produces
# those siblings. It is best-effort: if a minifier is unavailable or fails on a
# file, the original is copied verbatim to the .min name so a valid sibling
# always exists and nothing breaks — the page just ships unminified for that file.
#
# Re-run this whenever core/settings JS or CSS changes:
#   make minify-assets
#
# Modified by BW-Tech GmbH for owncloud.online.
set -uo pipefail

ROOT="${1:-.}"

have() { command -v "$1" >/dev/null 2>&1; }

# Prefer the versions pinned in build/package.json (build/node_modules) so the
# output is reproducible; fall back to a global install, then npx.
TERSER=""
if [ -x "$ROOT/build/node_modules/.bin/terser" ]; then TERSER="$ROOT/build/node_modules/.bin/terser"; elif have terser; then TERSER="terser"; elif have npx; then TERSER="npx --no-install terser"; fi
CLEANCSS=""
if [ -x "$ROOT/build/node_modules/.bin/cleancss" ]; then CLEANCSS="$ROOT/build/node_modules/.bin/cleancss"; elif have cleancss; then CLEANCSS="cleancss"; elif have clean-css-cli; then CLEANCSS="clean-css-cli"; elif have npx; then CLEANCSS="npx --no-install clean-css-cli"; fi

minify_js() {
  local f="$1" out="${1%.js}.min.js"
  if [ -n "$TERSER" ] && $TERSER "$f" -c -m -o "$out" 2>/dev/null && [ -s "$out" ]; then
    return 0
  fi
  cp "$f" "$out"
}

minify_css() {
  local f="$1" out="${1%.css}.min.css"
  if [ -n "$CLEANCSS" ] && $CLEANCSS -o "$out" "$f" 2>/dev/null && [ -s "$out" ]; then
    return 0
  fi
  cp "$f" "$out"
}

count=0
for dir in "$ROOT"/core/js "$ROOT"/settings/js "$ROOT"/apps/*/js; do
  [ -d "$dir" ] || continue
  while IFS= read -r -d '' f; do
    case "$f" in */tests/*|*/vendor/*) continue;; esac
    minify_js "$f"; count=$((count+1))
  done < <(find "$dir" -type f -name '*.js' ! -name '*.min.js' -print0)
done
for dir in "$ROOT"/core/css "$ROOT"/settings/css "$ROOT"/apps/*/css; do
  [ -d "$dir" ] || continue
  while IFS= read -r -d '' f; do
    case "$f" in */tests/*|*/vendor/*) continue;; esac
    minify_css "$f"; count=$((count+1))
  done < <(find "$dir" -type f -name '*.css' ! -name '*.min.css' -print0)
done

echo "minify-assets: generated ${count} .min siblings under ${ROOT}/{core,settings,apps}"
