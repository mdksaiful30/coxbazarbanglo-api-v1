# Frontend code guide

## Where to start

Read the files in this order:

1. `resources/views/app.blade.php` — Laravel's HTML shell and the Vue mount element.
2. `resources/js/app.ts` — imports responsive styles and mounts `App.vue`.
3. `resources/js/App.vue` — page state, server requests, booking actions and the page templates.
4. `resources/js/components/BookingShare.vue` — guest confirmation preview, PDF/JPG downloads and sharing.
5. `resources/js/composables/useTheme.ts` — light, dark and system appearance.
6. `resources/js/composables/useResponsiveOverlays.ts` — keyboard focus, dialogs, drawer and mobile menu.
7. `resources/js/directives/responsiveTable.ts` — headings used as labels in mobile table cards.
8. `resources/js/components/AppIcon.vue` — the shared SVG icon library.
9. `resources/css/app.css` — base styles and dark appearance; `responsive.css` adds screen-size adjustments.

The files now use consistent indentation, spacing, multiline functions, expanded CSS rules and readable Vue attributes. Comments explain the main workflows. Formatting does not split the application into new components or change business rules.

## Reading a Vue component

`<script setup lang="ts">` contains TypeScript state and functions. `<template>` describes what the user sees. A `<style>` section, when present, controls that component's appearance.

- `ref(...)` stores a reactive value; script code reads or changes it through `.value`. Templates unwrap refs automatically.
- `reactive(...)` groups related form fields. `computed(...)` derives a value from other state.
- `watch(...)` reacts to changes. `onMounted(...)` starts work when the component is displayed; `onBeforeUnmount(...)` removes listeners or temporary URLs.
- `v-model` connects an input to state. `v-if` shows a conditional section, `v-for` repeats items, `:disabled` binds an attribute and `@click` calls an event handler.
- TypeScript `type` declarations describe data shapes. `Row` currently permits flexible API data; consult the API documentation for the exact fields.

## Follow a booking through the code

The New booking button calls `newBooking()`. `bookingRooms()` checks availability and `getQuote()` requests pricing. `saveBooking()` creates the draft. `transition()` opens the action dialog, and `performAction()` submits or approves the booking. `view()` reloads the details.

For an approved booking, `editApproved()` fills the editor and `saveApproved()` calls the dedicated admin endpoint. Staff changes use `amend()` and `saveAmendment()`. The backend decides permissions, availability, pricing and financial adjustments.

The shared `api()` helper calls `/api/v1/...`. The `task()` wrapper sets busy/error state. To follow the request on the server, start with `routes/api.php`, then the named controller, then its service. See `docs/API.md` and `docs/openapi.json` for request fields and lifecycle rules.

## CSS and responsive rules

Read ordinary selectors first, then dark-theme selectors and media queries. CSS custom properties such as `--teal` and `--border` are reusable values. A media query applies only at its specified viewport size. Later matching rules can override earlier ones, so preserve rule order when editing.

`responsive.css` adapts navigation, tables, booking room choices and dialogs to smaller screens. The overlay composable also updates `--app-viewport-height` when the mobile keyboard changes the visible height.

## Keep formatting consistent

```powershell
npm.cmd run format
npm.cmd run format:check
npm.cmd run build
```

`format` updates the project's CSS, Vue and TypeScript source in `resources/`. `format:check` reports differences without changing files. `.prettierrc.json` defines the shared style. Generated files in `public/build/`, dependencies and storage are excluded. Use `build` to check TypeScript and regenerate browser assets after changes.
