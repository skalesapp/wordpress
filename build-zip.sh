#!/bin/bash
# Build the distributable plugin zip.
#
# v1.3.1 shipped a broken zip once (wrong folder structure, missing readme,
# macOS clutter), so this script builds from an explicit file list and refuses
# to produce a zip when the three places that carry the version disagree.
set -euo pipefail

cd "$(dirname "$0")"

SLUG=skales-connector
OUT=dist
STAGE="$OUT/$SLUG"

header_version=$(grep -m1 '^ \* Version:' "$SLUG.php" | sed 's/.*Version: *//' | tr -d ' \r')
const_version=$(grep -m1 "define('SKALES_VERSION'" "$SLUG.php" | sed "s/.*'\\([0-9][^']*\\)'.*/\\1/")
readme_version=$(grep -m1 '^Stable tag:' readme.txt | sed 's/.*: *//' | tr -d ' \r')

if [ "$header_version" != "$const_version" ] || [ "$header_version" != "$readme_version" ]; then
  echo "Version mismatch:"
  echo "  plugin header : $header_version"
  echo "  SKALES_VERSION: $const_version"
  echo "  readme.txt    : $readme_version"
  exit 1
fi

echo "Linting PHP..."
for f in "$SLUG.php" includes/*.php; do
  php -l "$f" > /dev/null
done

rm -rf "$OUT"
mkdir -p "$STAGE/includes"

cp "$SLUG.php" readme.txt LICENSE "$STAGE/"
cp includes/*.php "$STAGE/includes/"

# No resource forks, no .DS_Store, no extended attributes in the archive.
( cd "$OUT" && zip -q -r -X "$SLUG.zip" "$SLUG" -x '*.DS_Store' -x '__MACOSX/*' )

echo "Built $OUT/$SLUG.zip  (version $header_version)"
unzip -l "$OUT/$SLUG.zip"
