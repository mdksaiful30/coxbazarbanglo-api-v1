<script setup lang="ts">
  defineProps<{ name: string }>();
  // Each icon name maps to SVG path commands inside the shared 24 × 24 viewBox.
  const paths: Record<string, string[]> = {
    Menu: ['M3 6h18M3 12h18M3 18h18'],
    Close: ['m6 6 12 12M6 18 18 6'],
    Sun: [
      'M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
      'M12 2v2M12 20v2M2 12h2M20 12h2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42',
    ],
    Moon: ['M21 12.8A9 9 0 0 1 11.2 3a9 9 0 1 0 9.8 9.8Z'],
    Download: ['M12 3v12m-5-5 5 5 5-5', 'M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4'],
    Email: ['M3 5h18v14H3z', 'm3 5 9 8 9-8'],
    Share: [
      'M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
      'M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
      'M18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
      'm8.6 10.5 6.8-4m-6.8 7 6.8 4',
    ],
    WhatsApp: [
      'M21 11.5a9 9 0 0 1-13.3 8L3 21l1.5-4.7A9 9 0 1 1 21 11.5Z',
      'M8.2 7.2c-.8.1-1.1 1.1-.8 2.1.8 3 3.4 5.5 6.3 6.3 1 .3 2-.1 2.1-.8l.2-1.1-2.3-1.1-.9 1c-1.4-.5-2.5-1.6-3-3l1-.9-1.1-2.3Z',
    ],
    Edit: ['m16 3 5 5-12 12-6 1 1-6Z', 'm14 5 5 5'],
    Dashboard: ['M3 3h7v7H3z', 'M14 3h7v7h-7z', 'M3 14h7v7H3z', 'M14 14h7v7h-7z'],
    Calendar: [
      'M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z',
      'M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2M8 18h2',
    ],
    Bookings: [
      'M9 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3',
      'M9 2h6v4H9z',
      'm8 14 3 3 5-6',
    ],
    Rooms: ['M3 18v3M21 18v3M3 18h18V9a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2Z', 'M3 13h18M7 10h3M14 10h3'],
    Guests: [
      'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2',
      'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
      'M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    ],
    Tariffs: ['M20 13 11 22 2 13V3h10l8 8a1.4 1.4 0 0 1 0 2Z', 'M7 7h.01M9 13h6M12 10v6'],
    Reservations: ['M6 3h12v18l-6-4-6 4Z', 'm9 9 2 2 4-4'],
    Reports: ['M4 3v18h17', 'M8 16v-5M13 16V7M18 16v-8'],
    Staff: [
      'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
      'M4 21v-2a5 5 0 0 1 5-5h6a5 5 0 0 1 5 5v2',
      'M10 15l2 3 2-3M12 18v3',
    ],
    Notifications: ['M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9', 'M10 21h4'],
    'Audit trail': [
      'M9 4h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6',
      'M12 9h5M12 13h5M9 17h8',
      'M7 2a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z',
      'M7 4v2l1 1',
    ],
    Account: ['M20 21v-2a6 6 0 0 0-6-6h-4a6 6 0 0 0-6 6v2', 'M12 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z'],
    Approval: [
      'M9 3h6v4H9z',
      'M9 5H6a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3',
      'm8 14 3 3 5-6',
    ],
    Logout: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'M9 12h12m-4-4 4 4-4 4'],
  };
</script>

<template>
  <svg
    class="app-icon"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.7"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <path
      v-for="(path, index) in paths[name] || paths.Dashboard"
      :key="index"
      :d="path"
    />
  </svg>
</template>
