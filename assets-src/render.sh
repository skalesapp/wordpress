#!/bin/bash
# Render the wordpress.org assets from the two SVG sources in this folder.
#
#   ./render.sh            renders all four PNGs next to the sources
#   CHROME=/path/to/chrome ./render.sh
#
# Everything the SVGs need is inside them (fonts as data URIs, the gecko as
# vector paths), so this needs nothing but a Chrome and macOS' sips.
#
# Two things are measured rather than assumed, both found the hard way:
#
#   1. Headless Chrome's viewport is SMALLER than --window-size (87 px shorter
#      on this Mac), while the screenshot canvas is the full window size. Left
#      alone, that silently cuts the bottom off every asset. The script probes
#      the real offset, pads the page by half of it, and centre-crops back.
#   2. Chrome's rasteriser drops parts of a complex path at some sizes: the
#      gecko's tail vanished at exactly 512x512 and was intact at 200 and at
#      1024. Everything is therefore rendered several times larger than the
#      target and downsampled, which also gives the 128 px icon clean edges.
#
# The four PNGs go into wordpress.org's SVN assets/ folder under exactly these
# names, NOT into the plugin zip. See WPORG-EINREICHUNG.md section 2.
set -euo pipefail
cd "$(dirname "$0")"

CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
[ -x "$CHROME" ] || { echo "No Chrome at $CHROME (set CHROME=...)"; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

shot() {             # shot <page.html> <window-w> <window-h> <out.png>
  "$CHROME" --headless --disable-gpu --hide-scrollbars \
    --allow-file-access-from-files --default-background-color=00000000 \
    --window-size="$2,$3" --virtual-time-budget=6000 \
    --screenshot="$4" "file://$1" >/dev/null 2>&1
}

# ---- probe: how much smaller is the viewport than the window we ask for? ----
cat > "$TMP/probe.html" <<'HTML'
<!doctype html><html><head><meta charset="utf-8"></head><body>
<script>document.title = innerWidth + 'x' + innerHeight;</script></body></html>
HTML
probe=$("$CHROME" --headless --disable-gpu --hide-scrollbars --window-size=1000,1000 \
  --virtual-time-budget=2000 --dump-dom "file://$TMP/probe.html" 2>/dev/null \
  | sed -n 's/.*<title>\([0-9]*x[0-9]*\)<\/title>.*/\1/p' | head -1)
[ -n "$probe" ] || { echo "Could not probe the viewport size"; exit 1; }
DW=$((1000 - ${probe%x*}))
DH=$((1000 - ${probe#*x}))
[ $((DW % 2)) -eq 0 ] || DW=$((DW + 1))
[ $((DH % 2)) -eq 0 ] || DH=$((DH + 1))
echo "Viewport is ${DW}x${DH} px smaller than the window; padding and cropping for it."

render() {           # render <source.svg> <width> <height> <scale> <out.png>
  local src="$1" w="$2" h="$3" scale="$4" out="$5"
  local sw=$((w * scale)) sh=$((h * scale))
  # Canvas = window size, viewport = canvas minus the offset above. Ask for a
  # canvas of sw+2*DW+4 by sh+2*DH+4 and place the image at DW+2 / DH+2: that
  # sits it dead centre of the canvas (so sips' centre crop lands on it exactly)
  # and still fully inside the shorter viewport (so nothing is cut off).
  local cw=$((sw + 2 * DW + 4)) ch=$((sh + 2 * DH + 4))
  cat > "$TMP/page.html" <<HTML
<!doctype html><html><head><meta charset="utf-8"><style>
html,body{margin:0;padding:0;background:transparent}
img{display:block;position:absolute;left:$((DW + 2))px;top:$((DH + 2))px;width:${sw}px;height:${sh}px}
</style></head><body><img src="file://$PWD/$src"></body></html>
HTML
  shot "$TMP/page.html" "$cw" "$ch" "$TMP/big.png"
  sips --cropToHeightWidth "$sh" "$sw" "$TMP/big.png" --out "$TMP/crop.png" >/dev/null
  sips --resampleHeightWidth "$h" "$w" "$TMP/crop.png" --out "$out" >/dev/null
  echo "  $out $(sips -g pixelWidth -g pixelHeight "$out" | tail -2 | tr -d ' \n' | sed 's/pixelWidth:/ /;s/pixelHeight:/x/')"
}

echo "Rendering wordpress.org assets:"
render skales-icon.svg    128  128 8 icon-128x128.png
render skales-icon.svg    256  256 4 icon-256x256.png
render skales-banner.svg  772  250 3 banner-772x250.png
render skales-banner.svg 1544  500 2 banner-1544x500.png
echo "Done. Upload these four to the SVN assets/ folder, not into the zip."
