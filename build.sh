#!/usr/bin/env bash
#
# Build the release archive: dist/githubsync.zip
#
# Produces a zip whose top-level folder is githubsync/, containing only the
# files listed in .distignore's inverse — i.e. everything except docs, tests,
# build tooling and local leftovers.
#
# Usage: ./build.sh [version]
#   version  Optional; must match the Version header in githubsync.php.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

SLUG="githubsync"
OUT_DIR="$ROOT/dist"
ZIP="$OUT_DIR/$SLUG.zip"

VERSION_HEADER="$(sed -n 's/^ \* Version: *\(.*\)$/\1/p' "$SLUG.php" | head -1)"
if [ -z "$VERSION_HEADER" ]; then
	echo "Could not read the Version header from $SLUG.php" >&2
	exit 1
fi

if [ "${1:-}" != "" ] && [ "$1" != "$VERSION_HEADER" ]; then
	echo "Requested version $1 does not match $SLUG.php ($VERSION_HEADER)" >&2
	exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
	echo "zip is required" >&2
	exit 1
fi

# Fail early on syntax errors rather than shipping a broken zip.
if command -v php >/dev/null 2>&1; then
	while IFS= read -r -d '' file; do
		php -l "$file" >/dev/null || { echo "Syntax error in $file" >&2; exit 1; }
	done < <(find "$ROOT" -name '*.php' -not -path '*/tests/*' -not -path '*/dist/*' -print0)
fi

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/githubsync-build.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT

# Copy the plugin, honouring .distignore (rsync filter syntax is close enough:
# translate each pattern into an --exclude).
mkdir -p "$STAGE/$SLUG"
EXCLUDES=()
while IFS= read -r line; do
	case "$line" in
		''|'#'*) continue ;;
	esac
	# Strip the leading slash and a leading "*/" form used for nested copies.
	pattern="${line#/}"
	pattern="${pattern#\*/}"
	EXCLUDES+=(--exclude="$pattern")
done < .distignore

rsync -a "${EXCLUDES[@]}" "$ROOT/" "$STAGE/$SLUG/"

# Belt and braces: never ship these even if .distignore is edited badly.
rm -rf "$STAGE/$SLUG/tests" "$STAGE/$SLUG/dist" "$STAGE/$SLUG/docs"
rm -f "$STAGE/$SLUG"/*.key "$STAGE/$SLUG"/.env "$STAGE/$SLUG"/.gitignore \
	"$STAGE/$SLUG"/.distignore "$STAGE/$SLUG"/build.sh "$STAGE/$SLUG"/CHANGELOG.md

mkdir -p "$OUT_DIR"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" "$SLUG" )

echo "Built $ZIP ($VERSION_HEADER)"
unzip -l "$ZIP" | tail -1
