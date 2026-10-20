# Frontend styling

The Laravel/Vue frontend uses Tailwind CSS 4 through `@tailwindcss/vite`.

- `resources/css/app.css`: Tailwind entry point, source scanning, dark variant, and shared theme tokens.
- `resources/css/components.css`: reusable UI recipes built with Tailwind `@apply`.
- `resources/css/responsive.css`: Tailwind recipes for existing responsive layouts and accessibility behavior.
- `resources/css/typography.css`: shared heading and body typography recipes.
- `resources/js/components/BookingShare.vue`: scoped Tailwind recipes for booking sharing and the client-side document preview.
- `resources/css/booking-print.css`: standalone Tailwind recipes for server-generated booking documents.

Use Tailwind classes directly in Vue and Blade templates for new UI. Shared utilities include `font-sans`, `text-brand`, `text-muted`, and `border-divider`. The `dark:` variant follows the existing `data-theme="dark"` attribute. Complete utility names must appear in source; use a mapping of complete strings instead of building class names from fragments.

Existing reusable component names such as `primary`, `panel`, and `sidebar` remain, but their styling is now composed with Tailwind `@apply`. Exact pixel values preserve the established design. Component recipes are in Tailwind's `components` layer, so utilities in templates can override them. CSS variables retain runtime theme switching. The print stylesheet retains the native `@page` rule for A4 paper size.

Tailwind's theme and utilities are imported explicitly. Preflight is intentionally omitted to preserve the application's existing base styles, tables, buttons, and document exports.

Run `npm run dev` for frontend development and `npm run build` for production assets. Run `npm run build` after changing the booking print stylesheet too: booking exports embed its compiled contents through Laravel's `Vite::content`, because the export browser blocks external requests.

Migration validation: production build and Vue type checking passed; Blade templates compiled; computed styles were compared against the previous styles at 1440, 1024, 760, and 390 pixels in both light and dark themes. The rendered booking Blade document was also compared in screen and print modes. No differences were found in the checked computed properties. These checks used rendered template fixtures, not a full authenticated workflow test.
