# wordpress.org assets

The icon and banner wordpress.org shows for this plugin, plus the sources they
are rendered from.

| File | Goes to | Notes |
|---|---|---|
| `icon-128x128.png` | SVN `assets/` | The one that appears in search results, often at 64 px |
| `icon-256x256.png` | SVN `assets/` | Retina variant of the same artwork |
| `banner-772x250.png` | SVN `assets/` | Plugin page header |
| `banner-1544x500.png` | SVN `assets/` | Retina variant |
| `skales-icon.svg` | source | Edit this, not the PNG |
| `skales-banner.svg` | source | Edit this, not the PNG |
| `render.sh` | tooling | Re-renders all four PNGs |

**None of this belongs in the plugin zip.** wordpress.org serves these from the
SVN `assets/` folder next to `trunk/` and `tags/`, under exactly the file names
above. Screenshots (`screenshot-1.png` and up) go to the same folder and are
shot on a real site, not generated here; their captions live in the
`== Screenshots ==` section of `readme.txt` and must stay in the same order as
the files.

## Re-rendering

```sh
./render.sh
```

Needs a Chrome and macOS' `sips`, nothing else: the fonts are embedded in the
SVGs as data URIs and the gecko is vector, so the sources render the same
anywhere. Point the script at another browser with `CHROME=/path/to/chrome`.

Two things the script measures instead of assuming, both found by looking at
the output rather than trusting it:

- Headless Chrome's viewport is smaller than the `--window-size` you ask for
  (88 px shorter on this Mac) while the screenshot canvas is the full window,
  so a naive render silently loses the bottom of every asset. The script probes
  the real difference and pads and crops for it.
- Chrome's rasteriser drops parts of a complex path at some sizes: the gecko's
  tail disappeared at exactly 512x512 and was intact at 200 and at 1024.
  Everything is therefore rendered several times larger than its target and
  downsampled, which also gives the 128 px icon clean edges.

## Where the artwork comes from

Nothing here is newly invented; it is the existing brand, assembled for the
sizes wordpress.org wants.

- **Gecko:** the Noto gecko (`1f98e`) that the Skales app loads on its splash,
  taken from the Lottie in `skales-mobile/assets/lottie/brand/1f98e.json` at
  frame 0, the same frame the house asset exporter uses. It is real vector in
  these files, not a traced or embedded bitmap. This is also why there is no raw
  emoji character anywhere in the sources: a literal glyph would render as
  Apple's 3D emoji on a Mac and as something else everywhere else.
- **Stage, gradients and grid:** the dark green brand stage from
  `skales-landingpage/scripts/og-source.html` (`#0d120b` to `#0a1108`, lime
  `#84cc16` / `#a3e635`).
- **Type:** Switzer 700 for the wordmark, Switzer 500 for the line, from
  `skales-landingpage/public/fonts`, embedded as woff2 data URIs.
- **Plate:** the squircle of the app icon (`skales-desktop/electron/icons`),
  dark variant.

## Rules these files follow

- The icon carries no text. It is read at 64 px in search results, where any
  wordmark would collapse; the gecko silhouette still reads at 32 px.
- The banner carries the wordmark and one line, "Your site, run from your own
  computer.", and no screenshot. Screenshots are their own asset type.
- No raw emoji characters, no em-dashes.
