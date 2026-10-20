# Coxbazarbanglo branding

Created with the built-in image generation tool. The transparent horizontal logo combines a coastal bungalow, sun arc, and waves in teal and gold. The favicon uses the symbol alone. PNG resizing, transparent-margin trimming, and ICO packaging preserve the generated artwork.

## Assets

- `public/images/brand/coxbazarbanglo-logo.png`: transparent horizontal logo, 1977 × 582.
- `public/images/brand/coxbazarbanglo-symbol.png`: transparent square symbol, 512 × 512.
- `public/images/brand/favicon-16x16.png` and `favicon-32x32.png`: browser icons.
- `public/images/brand/apple-touch-icon.png`: opaque white touch icon, 180 × 180.
- `public/favicon.ico`: multi-size icon containing 16, 32, 48, 64, 128, and 256 pixel versions.
- The two `*-original.png` files preserve the generated masters.

The Vue interface displays the logo on the sign-in screen and sidebar. A white backing keeps the teal wordmark readable in dark mode. The Blade application shell declares the favicon and touch icon.

## Deployment

Upload `public/images/brand/`, `public/favicon.ico`, the entire rebuilt `public/build/` directory, and `resources/views/app.blade.php`. Run `php artisan view:clear`. Also synchronize `resources/js/App.vue` if source files are maintained on the server. No dependency changes are needed for these branding assets.

## Generation prompts

Logo:

> Use case: logo-brand. Create a finished professional logo for the coastal bungalow/rest-house management application named exactly "Coxbazarbanglo". Use the application's existing deep teal #126b61 and warm gold accent, with a restrained coastal hospitality feel. A distinctive simple bungalow roof and flowing sea wave integrated into one compact geometric emblem. Horizontal lockup: emblem left, clearly legible elegant modern sans-serif wordmark Coxbazarbanglo right. Exact text: "Coxbazarbanglo" (C o x b a z a r b a n g l o), no other text. Crisp flat vector-like geometry, no gradients, no shadows, no mockups, no extra decorative scenes. Transparent background with genuine alpha. Generous but modest clear margin, landscape composition, clean edges, production-ready. Make the emblem bold and simple enough to reuse alone as a favicon.

Symbol, using the generated logo as the edit reference:

> Edit the Coxbazarbanglo logo just generated to create its matching favicon / standalone brand symbol. Keep only the bungalow-roof, four-pane golden window, gold sun arc and teal-and-gold sea waves emblem from the left side; remove all wordmark text completely. Preserve the recognizable design and deep teal/gold palette. Simplify to solid flat fills with exceptionally clean edges, no texture, shadows or gradients, bold strokes and a clear silhouette readable at 32 pixels. Center the single compact emblem in a square transparent canvas, occupying 88% of the width and height with balanced margins. No text, no letters, no border, no added objects, genuinely transparent background.
