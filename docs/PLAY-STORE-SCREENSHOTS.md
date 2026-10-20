# Phone screenshots

Five PNG screenshots are saved in `public/images/store-screenshots/`:

1. `01-dashboard.png`
2. `02-bookings.png`
3. `03-calendar.png`
4. `04-rooms-dark.png`
5. `05-guests.png`

Each screenshot is exactly 1080 × 1920 pixels (9:16), under 8 MB, and captures the real Flutter production widgets. The room screen demonstrates dark mode. No interface elements were invented or composited. All account, guest, booking, and financial records are synthetic fixtures; no live API was contacted. These are app-content captures, without device frames or OS status/navigation bars.

Download package: `public/images/store-screenshots/coxbazarbanglo-phone-screenshots.zip`. Upload the five PNG files individually to Google Play's phone screenshots section after extracting the archive.

The capture harness is `tools/play_store_screenshots_test.dart`. Run it from the Flutter project at `G:/apps-development/coxbanglo` with `flutter test G:/laragon/www/coxbazar-banglo/cox-laravel-application/tools/play_store_screenshots_test.dart`. It loads the app's real fonts, renders a 540 × 960 logical-pixel phone surface at 2× resolution, navigates the actual app shell, checks for layout exceptions, and saves the PNGs. No release application source or existing AAB was changed to create these assets.

## 7-inch tablet screenshots

Five additional images are in `public/images/store-screenshots-7-inch-tablet/`, with the same screen names. Each is a 1440 × 2560 PNG (9:16), under 8 MB. They are rendered from the actual responsive app at a 720 × 1280 logical-pixel tablet viewport at 2× resolution, showing the tablet navigation rail and responsive columns. These are tablet-layout renders, not physical-device captures or enlarged phone screenshots.

The package is `public/images/store-screenshots-7-inch-tablet/coxbazarbanglo-7-inch-tablet-screenshots.zip`. Upload its five PNGs to Google Play's **7-inch tablet screenshots** section.

Reproduction harness: `tools/play_store_tablet7_screenshots_test.dart`, run with `flutter test` from the Flutter project as above. It reuses the synthetic data from the phone harness. The capture test passed and all five dimensions, PNG formats and file sizes were verified; the images were visually reviewed together.

## 10-inch tablet screenshots

Five landscape images are in `public/images/store-screenshots-10-inch-tablet/`, using the same screen names. Each is a 2560 × 1440 PNG (16:9), under 8 MB, with both sides between 1080 and 7680 pixels. These are actual responsive app renders at a 1280 × 720 logical-pixel tablet viewport at 2× resolution, showing the expanded navigation sidebar and multi-column layouts, rather than resized phone screenshots. The room screen uses dark mode, and all records are synthetic.

Package: `public/images/store-screenshots-10-inch-tablet/coxbazarbanglo-10-inch-tablet-screenshots.zip`. Upload the extracted PNGs in Google Play's **10-inch tablet screenshots** section.

Reproduction harness: `tools/play_store_tablet10_screenshots_test.dart`. The capture test passed, dimensions and sizes were verified, and all five screens were visually inspected. No release app source or AAB was changed.
