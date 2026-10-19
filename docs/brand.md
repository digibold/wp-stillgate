# Stillgate brand guidelines

Product: Stillgate, Editorial Freeze for Deployments (free WordPress plugin).

## The idea

The S is a gate: two identical leaves, one turned 180 degrees from the other, hang on a single amber hinge pin. The pin is the still point; the cut around it is the only thing that tells you the gate is held open on purpose.

Reading of the parts:

- Two congruent hooks (each a flat arm, a semicircular bend) = the two leaves of a gate. Arms end flat like gate posts.
- Amber disc = the hinge pin and the pause light. It is the only warm element and the only element that is not white.
- Ring of empty space around the disc = the freeze. It closes when the freeze ends.
- 180 degree point symmetry = calm. Nothing leans; nothing is in motion.

In the wordmark the monogram is the letter S (the name is read as one word), and the dot of the "i" repeats the hinge detail in ink: a round dot seated in a scooped stem, built with the same ratios.

## Construction

Artboard 256 x 256, unit u = 8.

| Element | Value | In u |
| --- | --- | --- |
| Tile | 256 x 256, corner radius 56 | 32, 7 |
| Symbol height | 192 (y 32 to 224) | 24 |
| Symbol width | 128 (x 64 to 192) | 16 |
| Stroke | 32 | 4 |
| Bend, centreline radius | 40 (outer 56, inner 24) | 5 (7, 3) |
| Run between bend centres | 16 (centres at x 120 and 136) | 2 |
| Pin (amber disc) radius | 16, centred at 128,128 | 2 |
| Ring (gap around pin) | 8 | 1 |
| Socket radius (pin + ring) | 24, equal to the inner bend radius | 3 |

Rules:

- Leaf 2 is leaf 1 rotated 180 degrees about (128,128). No other transform exists.
- Arm length is set so each arm ends flush with the outer edge of the opposite bend (x 192 on top, x 64 on bottom).
- The socket circle is subtracted from both leaves; the leaves never touch.
- Outer bend radius (56) equals the tile corner radius (56), so the curve of the S repeats the curve of the tile.
- Curves are circular arcs joined tangent to straight runs. There are no kinks and no free-form Beziers.
- Pin diameter = stroke (2:4), ring = 1/4 stroke, socket = 3/4 stroke. The "i" of the wordmark uses the same three ratios against its stem.
- The icon S is centred on the tile; it fills 75% of tile height and 50% of tile width.

Wordmark: "Stillgate", capital S, rest lowercase.

- S = the symbol scaled so its height equals the cap height (700/1000 em); flat arms sit exactly on the cap line and the baseline.
- "tillgate" = Jost weight 600, outlined, default spacing; the gap from the S to the "t" is 72/1000 em.
- Custom "i": stem 125.9/1000 em wide, flat top at 460, scooped by a circle of radius 94.4 (3/4 stem) centred 70.4 above the top; dot radius 62.9 (1/2 stem), concentric with the scoop. Ring thickness 31.5 (1/4 stem).
- "ll" keeps the font spacing, where the gap between the two stems equals one stem width (the pause sign).

## Clear space and minimum sizes

x = stroke width of the S.

| Asset | Clear space | Minimum size |
| --- | --- | --- |
| Tile icon (icon.svg) | none beyond the tile; keep 1/8 tile width from other objects | 16 px |
| Symbol alone (mark.svg) | 2x on all sides | 24 px high |
| Lockup (logo.svg, logo-dark.svg) | 1.5x on all sides (x = one sixth of the cap height) | 96 px wide, 25 mm in print |

At 16 px the tile reads as a white S with a small amber point; the ring is below one pixel and merges with the tile. This is intended.

## Colour tokens

| Token | Hex | Role |
| --- | --- | --- |
| Ink | #0A2530 | tile, text on light backgrounds |
| Mist | #F2F7F6 | symbol and text on dark backgrounds |
| Amber | #F5A623 | pin on dark backgrounds |
| Amber on light | #D06F00 | pin on white and light backgrounds |
| Tagline blue-grey | #A9C8D3 | tagline on Ink only |
| Tonal 1 | #0E2D3A | banner background emblem, decorative only |
| Tonal 2 | #123644 | banner emblem pin, decorative only |

The full-colour symbol uses three colours: Ink, Mist, Amber. Mono: one colour, no pin colour change.

Contrast (WCAG 2.x relative luminance):

| Foreground on background | Ratio | Use |
| --- | --- | --- |
| Mist on Ink | 14.70:1 | wordmark and symbol on dark (AAA text) |
| Tagline blue-grey on Ink | 9.01:1 | tagline (AAA text) |
| Ink on #FFFFFF | 15.91:1 | wordmark on white (AAA text) |
| Ink on Mist | 14.70:1 | text on light panels (AAA text) |
| Amber on Ink | 7.85:1 | pin on dark (graphic) |
| Amber on light on #FFFFFF | 3.53:1 | pin on white (graphic, meets 3:1) |
| Amber on light on Mist | 3.26:1 | pin on mist (graphic, meets 3:1) |
| Amber on #FFFFFF | 2.03:1 | do not use |
| Tonal 1 on Ink | 1.10:1 | decorative emblem, carries no information |

Mono usage: black on white 21.00:1; white on near-black #0E1216 18.80:1.

## Typefaces

| Family | Use | Licence |
| --- | --- | --- |
| Jost (weight 600) | wordmark letters "tillgate", outlined | SIL Open Font License 1.1 |
| Jost (weight 500) | tagline in banners, outlined | SIL Open Font License 1.1 |

Source: Google Fonts repository, directory ofl/jost. The artwork contains outlines only; no font software is distributed with the files. The "i" is redrawn, and the S is original geometry. For running text next to the logo use Jost or the system sans-serif stack.

## Usage

Correct:

- Tile icon on any background; the tile carries its own contrast.
- logo.svg on white or light backgrounds; logo-dark.svg on Ink or other dark backgrounds.
- mark.svg in a single colour through `currentColor`: Ink on light, Mist on dark, black or white in print.
- Tagline set only in banners and social images, in Tagline blue-grey on Ink.
- Scale uniformly; keep the ring of space around the pin open.

Incorrect:

- Do not fill the ring or touch the leaves together; the cut is the idea.
- Do not recolour the pin to red, green or any alarm colour; do not animate it blinking.
- Do not use Amber (#F5A623) on white or light backgrounds; use Amber on light.
- Do not place the Mist symbol on a light background or the Ink symbol on a dark one.
- Do not stretch, skew, rotate, outline, add shadows, gradients or glow.
- Do not set the name in another typeface, in capitals, or with a plain S in place of the symbol.
- Do not separate the symbol from the wordmark and set the S twice ("S Stillgate").
- Do not put the symbol inside a circle, shield or padlock.
- Do not place the logo over busy photographs.

## File inventory

`.wordpress-org/` (uploaded to the plugin's `/assets` directory on WordPress.org; excluded from the plugin archive)

- icon.svg: 256 x 256 viewBox, tile plus symbol, flat colour, source for the PNG icons.
- icon-128x128.png, icon-256x256.png: transparent rounded corners.
- banner-772x250.png, banner-1544x500.png: opaque; lockup and tagline sit in the middle band (y 100 to 400 of 500), left edge at 236 px of 1544, emblem bleeds off the right edge.
- github-social-preview-1280x640.png: opaque, content inside a 40 px safe margin, under 1 MB.

`docs/img/`

- logo.svg: horizontal lockup for light backgrounds, transparent, tight viewBox.
- logo-dark.svg: horizontal lockup for dark backgrounds, transparent, tight viewBox.
- mark.svg: symbol alone, 128 x 192 viewBox, `currentColor`.
- banner.png: README header, 1544 x 500, same artwork as the WordPress.org banner.

`docs/brand.md`: this file.

In the product, the symbol appears once, inline in the heading of the Tools > Stillgate screen (`src/Admin/SettingsPage.php`), in Ink with the pin in Amber on light.

All SVGs: hand-built paths, presentation attributes only, no text, filters, masks, images, scripts or style blocks; root carries `role="img"`, `aria-label="Stillgate"` and `<title>Stillgate</title>`.
