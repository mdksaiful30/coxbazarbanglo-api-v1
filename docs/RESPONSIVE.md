# Responsive interface

The workspace adapts to phones, tablets and desktop browsers. Below 1025px the sidebar becomes a collapsible menu with all sections and sign-out accessible. Below 601px, list tables become labeled cards; the room/date calendar remains a contained swipeable grid with sticky room names. Forms stack, controls use touch-friendly sizes, and phone inputs use 16px text to avoid automatic field zoom.

Phone dialogs use the available visual viewport height, with independent form scrolling and reachable close/save controls. Background scrolling and interaction are disabled while a dialog/menu is open. Keyboard focus stays in the active overlay and Escape closes the top overlay when a save is not in progress. Tablet and desktop dialogs retain their centered layout. Reduced-motion preferences and device safe areas are respected.

## Browser verification

- All 12 pages checked at 320px: Dashboard, Calendar, Bookings, Rooms, Guests, Tariffs, Reservations, Reports, Staff, Notifications, Audit trail and Account. No document-level horizontal overflow was observed.
- Booking editor checked at 320x568, 375x812, 430x932, 768x1024, 1024x768 and 1280x800. Header Save changes remained in view.
- Guest, staff, room and reservation dialogs checked at 320px; longer forms scroll within the screen.
- Key page layouts checked at 768px tablet width.
- Light/dark mobile details/editor checked. Focus containment, background inert state, Escape and 16px input sizing checked.
- JPG exported from the phone-width dark-mode details screen and visually inspected: the guest document remains white, complete and readable with Bangla room names.
- Production TypeScript check and Vite build passed.

These are browser viewport checks, not a physical-device certification. Device keyboards, native select/date pickers and native sharing should also be checked on the phones used by staff.
