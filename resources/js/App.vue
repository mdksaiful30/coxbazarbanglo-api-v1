<script setup lang="ts">
  import { computed, onMounted, reactive, ref, watch } from 'vue';
  import axios from 'axios';
  import { useResponsiveOverlays } from './composables/useResponsiveOverlays';
  import { vResponsiveTable } from './directives/responsiveTable';
  import { useTheme } from './composables/useTheme';
  const { preference: themePreference, dark: isDark, toggle: toggleTheme } = useTheme();
  import AppIcon from './components/AppIcon.vue';
  import BookingShare from './components/BookingShare.vue';
  const brandLogoUrl = '/images/brand/coxbazarbanglo-logo.png';
  // Shared HTTP setup: browser requests use the Laravel session and CSRF token.
  axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
  axios.defaults.withCredentials = true;
  const print = () => window.print();
  const setLanguage = () => {
    lang.value = lang.value === 'en' ? 'bn' : 'en';
    localStorage.setItem('resthouse-lang', lang.value);
  };
  const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  axios.defaults.headers.common['X-CSRF-TOKEN'] = csrf();
  // Reactive state: refs hold replaceable values; reactive objects hold editable fields.
  type Row = Record<string, any>;
  const user = ref<Row | null>(null),
    loading = ref(false),
    busy = ref(false),
    error = ref(''),
    notice = ref(''),
    page = ref('Dashboard'),
    lang = ref(localStorage.getItem('resthouse-lang') || 'en');
  const catalog = reactive<Row>({ rooms: [], categories: [], tariffs: [], operations: {} }),
    dashboard = ref<Row>({}),
    bookings = ref<Row[]>([]),
    guests = ref<Row[]>([]),
    blocks = ref<Row[]>([]),
    users = ref<Row[]>([]),
    logs = ref<Row[]>([]),
    deliveries = ref<Row[]>([]),
    report = ref<Row>({ bookings: [] }),
    calendar = ref<Row>({ entries: [], bookings: [] }),
    availability = ref<Row[]>([]);
  const selected = ref<Row | null>(null),
    finance = ref<Row>({}),
    modal = ref(''),
    quote = ref<Row | null>(null),
    filter = ref(''),
    search = ref(''),
    guestSearch = ref(''),
    nextBookings = ref<string | null>(null),
    nextGuests = ref<string | null>(null);
  const day = (date = new Date()) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const add = (value: string, n: number) => {
    const d = new Date(value + 'T12:00:00');
    d.setDate(d.getDate() + n);
    return day(d);
  };
  const dhakaToday = () =>
    new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Dhaka',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date());
  const today = dhakaToday(),
    calendarStart = ref(today),
    calendarSpan = ref(7),
    dates = computed(() =>
      Array.from({ length: calendarSpan.value }, (_, i) => add(calendarStart.value, i)),
    );
  const range = reactive({ start: today, end: add(today, 30) }),
    roomDates = reactive({ check_in_at: today + 'T14:00', check_out_at: add(today, 1) + 'T12:00' });
  const login = reactive({ email: '', password: '' }),
    loginMode = ref(location.pathname.startsWith('/reset-password/') ? 'reset' : 'login');
  const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
  const form = reactive<Row>({});
  const admin = computed(() => user.value?.role === 'admin');
  const mobileMenu = ref(false);
  useResponsiveOverlays(modal, selected, mobileMenu, busy);
  // Navigation and display helpers. Monetary values from the API are integer poisha.
  const labels: Record<string, string> = {
    Dashboard: 'ড্যাশবোর্ড',
    Calendar: 'ক্যালেন্ডার',
    Bookings: 'বুকিং',
    Rooms: 'কক্ষসমূহ',
    Guests: 'অতিথি',
    Tariffs: 'ভাড়ার তালিকা',
    Reservations: 'সংরক্ষণ',
    Reports: 'প্রতিবেদন',
    Staff: 'কর্মী',
    Notifications: 'বার্তা',
    'Audit trail': 'কার্যক্রমের ইতিহাস',
    Account: 'অ্যাকাউন্ট',
  };
  const t = (s: string) => (lang.value === 'bn' ? labels[s] || s : s);
  const nav = computed(() => [
    'Dashboard',
    'Calendar',
    'Bookings',
    'Rooms',
    'Guests',
    ...(admin.value
      ? ['Tariffs', 'Reservations', 'Reports', 'Staff', 'Notifications', 'Audit trail']
      : []),
    'Account',
  ]);
  const money = (n: number = 0) =>
    new Intl.NumberFormat(lang.value === 'bn' ? 'bn-BD' : 'en-BD', {
      style: 'currency',
      currency: 'BDT',
    }).format(Number(n) / 100);
  const pretty = (s: string = '') => s.replaceAll('_', ' ');
  const displayDate = (s: string) =>
    s
      ? new Intl.DateTimeFormat(lang.value === 'bn' ? 'bn-BD' : 'en-GB', {
          day: 'numeric',
          month: 'short',
          year: 'numeric',
        }).format(new Date(s.replace(' ', 'T')))
      : '—';
  const cleanError = (e: any) => {
    error.value =
      Object.values(e.response?.data?.errors || {})
        .flat()
        .join(' ') ||
      e.response?.data?.message ||
      e.message ||
      'Request failed.';
    if (e.response?.status === 401) user.value = null;
  };
  // Wrap screen actions with the shared loading state and readable error messages.
  async function task(fn: () => Promise<any>) {
    busy.value = true;
    error.value = '';
    try {
      return await fn();
    } catch (e) {
      cleanError(e);
    } finally {
      busy.value = false;
    }
  }
  // API gateway for this screen. Each new mutation gets a unique idempotency key.
  async function api(method: string, path: string, data?: any) {
    return (
      await axios({
        method,
        url: path.startsWith('/api/') ? path : '/api/v1/' + path,
        data,
        headers: ['post', 'put', 'patch', 'delete'].includes(method)
          ? { 'Idempotency-Key': crypto.randomUUID() }
          : undefined,
      })
    ).data;
  }
  // Load the reference catalog and the data required by the current page.
  async function loadCatalog() {
    Object.assign(catalog, (await api('get', 'catalog')).data);
  }
  async function loadBookings(more = false) {
    const response = await api(
      'get',
      more && nextBookings.value
        ? nextBookings.value
        : `bookings?status=${filter.value}&search=${encodeURIComponent(search.value)}`,
    );
    bookings.value = more ? [...bookings.value, ...response.data] : response.data;
    nextBookings.value = response.next_page_url
      ? new URL(response.next_page_url).pathname + new URL(response.next_page_url).search
      : null;
  }
  async function loadGuests(more = false) {
    const response = await api(
      'get',
      more && nextGuests.value
        ? nextGuests.value
        : 'guests?search=' + encodeURIComponent(guestSearch.value),
    );
    guests.value = more ? [...guests.value, ...response.data] : response.data;
    nextGuests.value = response.next_page_url
      ? new URL(response.next_page_url).pathname + new URL(response.next_page_url).search
      : null;
  }
  async function loadCalendar() {
    calendar.value = (
      await api(
        'get',
        `calendar?start=${calendarStart.value}&end=${add(calendarStart.value, calendarSpan.value)}`,
      )
    ).data;
  }
  async function refresh() {
    loading.value = true;
    try {
      await loadCatalog();
      if (page.value === 'Dashboard') dashboard.value = (await api('get', 'dashboard')).data;
      if (page.value === 'Bookings') await loadBookings();
      if (page.value === 'Guests') await loadGuests();
      if (page.value === 'Calendar') await loadCalendar();
      if (page.value === 'Rooms') await findRooms();
      if (page.value === 'Reservations') blocks.value = (await api('get', 'room-blocks')).data;
      if (page.value === 'Staff') users.value = (await api('get', 'users')).data;
      if (page.value === 'Audit trail') logs.value = (await api('get', 'audit-logs')).data;
      if (page.value === 'Notifications')
        deliveries.value = (await api('get', 'notifications')).data;
      if (page.value === 'Reports') await loadReport();
    } finally {
      loading.value = false;
    }
  }
  async function navigate(p: string) {
    mobileMenu.value = false;
    page.value = p;
    selected.value = null;
    await task(refresh);
  }
  // Account access: login, password recovery and password reset share one form.
  async function signIn() {
    await task(async () => {
      if (loginMode.value === 'forgot') {
        await axios.post('/forgot-password', { email: login.email });
        notice.value =
          'If the account exists, a reset link was requested. Email delivery requires SMTP.';
        return;
      }
      if (loginMode.value === 'reset') {
        await axios.post('/reset-password', {
          email: login.email,
          token: location.pathname.split('/').pop(),
          password: login.password,
          password_confirmation: form.password_confirmation,
        });
        location.href = '/';
        return;
      }
      await axios.post('/login', login);
      location.href = '/';
    });
  }
  async function signOut() {
    await task(async () => {
      await axios.post('/logout');
      location.href = '/';
    });
  }
  async function findRooms() {
    availability.value = (await api('get', 'availability?' + new URLSearchParams(roomDates))).data;
  }
  function open(name: string, data: Row = {}) {
    Object.keys(form).forEach((k) => delete form[k]);
    Object.assign(form, data);
    modal.value = name;
    error.value = '';
    quote.value = null;
  }
  // Draft booking flow: choose a guest, find available rooms, quote, save, then submit.
  async function newBooking() {
    await task(async () => {
      await loadGuests();
      const earliest = dhakaToday() + 'T00:00';
      const arrival =
        roomDates.check_in_at < earliest ? dhakaToday() + 'T14:00' : roomDates.check_in_at;
      open('booking', {
        guest_id: guests.value[0]?.id || '',
        guest_category_id: catalog.categories[0]?.id || '',
        check_in_at: arrival,
        check_out_at:
          roomDates.check_out_at <= arrival
            ? add(arrival.slice(0, 10), 1) + 'T12:00'
            : roomDates.check_out_at,
        room_ids: [],
        guest_count: 1,
        reference_letter: '',
        purpose: '',
        notes: '',
      });
      await bookingRooms();
    });
  }
  async function bookingRooms() {
    quote.value = null;
    if (
      ['booking', 'edit-booking'].includes(modal.value) &&
      form.check_in_at?.slice(0, 10) < dhakaToday()
    ) {
      availability.value = [];
      form.room_ids = [];
      throw new Error('Arrival cannot be before today (Asia/Dhaka).');
    }
    if (form.check_in_at && form.check_out_at)
      availability.value = (
        await api(
          'get',
          'availability?' +
            new URLSearchParams({
              check_in_at: form.check_in_at,
              check_out_at: form.check_out_at,
              ...(['edit-approved', 'amendment'].includes(modal.value) && form.id
                ? { exclude_booking_id: String(form.id) }
                : {}),
            }),
        )
      ).data;
  }
  function toggleRoom(id: number) {
    quote.value = null;
    form.room_ids = form.room_ids.includes(id)
      ? form.room_ids.filter((x: number) => x !== id)
      : [...form.room_ids, id];
  }
  async function getQuote() {
    await task(async () => (quote.value = (await api('post', 'booking-quotes', form)).data));
  }
  async function saveBooking() {
    await task(async () => {
      const editing = modal.value === 'edit-booking';
      const result = await api(
        editing ? 'patch' : 'post',
        editing ? `bookings/${form.id}` : 'bookings',
        form,
      );
      modal.value = '';
      page.value = 'Bookings';
      notice.value = 'Draft saved. Submit it for approval or confirm it as admin.';
      await refresh();
      await view(result.data.id);
    });
  }
  async function view(id: number) {
    await task(async () => {
      const r = await api('get', `bookings/${id}`);
      selected.value = r.data;
      finance.value = r.finance;
    });
  }
  // Lifecycle actions use the current booking version to detect concurrent edits.
  async function transition(action: string) {
    if (!selected.value) return;
    const needs = [
      'reject',
      'return-for-correction',
      'cancel',
      'mark-no-show',
      'check-out',
    ].includes(action);
    open('action', {
      action,
      version: selected.value.version,
      reason: '',
      needs_reason: needs,
      reservation_id: null,
    });
  }
  async function performAction() {
    await task(async () => {
      const id = selected.value!.id;
      await api('post', `bookings/${id}/${form.action}`, {
        version: form.version,
        reason: form.reason,
        reservation_id: form.reservation_id || null,
      });
      modal.value = '';
      notice.value = 'Booking updated.';
      await refresh();
      await view(id);
    });
  }
  async function saveGuest() {
    await task(async () => {
      await api('post', 'guests', form);
      modal.value = '';
      notice.value = 'Guest saved.';
      await loadGuests();
    });
  }
  async function saveRoom() {
    await task(async () => {
      await api('patch', `rooms/${form.id}`, {
        name: form.name,
        capacity: form.capacity,
        bookable: form.bookable,
        housekeeping: form.housekeeping,
      });
      modal.value = '';
      notice.value = 'Room updated.';
      await refresh();
    });
  }
  async function activate() {
    await task(async () => {
      await api('post', `tariffs/${form.id}/activate`, form);
      modal.value = '';
      notice.value = 'Tariff policy approved and activated.';
      await refresh();
    });
  }
  async function saveBlock() {
    await task(async () => {
      await api('post', 'room-blocks', form);
      modal.value = '';
      notice.value = 'Rooms blocked for the selected interval.';
      await refresh();
    });
  }
  async function savePayment() {
    await task(async () => {
      await api('post', `bookings/${selected.value!.id}/payments`, {
        amount_minor: Math.round(Number(form.amount) * 100),
        method: form.method,
        reference: form.reference || null,
      });
      modal.value = '';
      notice.value = 'Payment posted. Room occupancy is unchanged.';
      await view(selected.value!.id);
    });
  }
  async function saveStaff() {
    await task(async () => {
      await api(form.id ? 'put' : 'post', form.id ? `users/${form.id}` : 'users', form);
      modal.value = '';
      notice.value = 'Staff account saved.';
      await refresh();
    });
  }
  async function releaseBlock() {
    await task(async () => {
      await api('post', `room-blocks/${form.id}/release`, { reason: form.reason });
      modal.value = '';
      await refresh();
    });
  }
  async function loadReport() {
    report.value = (await api('get', `reports?start=${range.start}&end=${range.end}`)).data;
  }
  // Browser CSV download for the currently loaded report.
  function exportCsv() {
    const esc = (v: any) => '"' + String(v ?? '').replaceAll('"', '""') + '"';
    const rows = [
      ['Booking', 'Guest', 'Status', 'Arrival', 'Departure', 'Rooms', 'Charges BDT', 'Balance BDT'],
      ...report.value.bookings.map((b: Row) => [
        b.number,
        b.guest,
        b.status,
        b.check_in_at,
        b.check_out_at,
        b.rooms,
        b.finance.charges_minor / 100,
        b.finance.balance_minor / 100,
      ]),
    ];
    const url = URL.createObjectURL(
      new Blob(['\uFEFF' + rows.map((r) => r.map(esc).join(',')).join('\r\n')], {
        type: 'text/csv;charset=utf-8',
      }),
    );
    const a = document.createElement('a');
    a.href = url;
    a.download = `rest-house-${range.start}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }
  // Resolve one room/day calendar cell from allocations, then pending requests.
  function cell(room: Row, date: string) {
    const entry = calendar.value.entries.find(
      (e: Row) =>
        e.room_id === room.id && e.starts_at.slice(0, 10) <= date && e.ends_at.slice(0, 10) > date,
    );
    if (entry) {
      const b = calendar.value.bookings.find((x: Row) => x.id === entry.booking_id);
      return {
        ...entry,
        label: b?.guest_snapshot?.name || pretty(entry.kind),
        status: b?.status || entry.kind,
      };
    }
    const p = calendar.value.bookings.find(
      (b: Row) =>
        b.status === 'pending_approval' &&
        b.rooms.some((r: Row) => r.id === room.id) &&
        b.check_in_at.slice(0, 10) <= date &&
        b.check_out_at.slice(0, 10) > date,
    );
    return p ? { booking_id: p.id, label: 'Pending request', status: 'pending_approval' } : null;
  }
  // Admin edits preserve approval and financial history through the approved-details API.
  async function editApproved() {
    const b = selected.value!;
    await task(async () => {
      await loadGuests();
      if (!guests.value.some((g) => g.id === b.guest_id))
        guests.value.unshift({ id: b.guest_id, ...b.guest_snapshot });
      open('edit-approved', {
        ...b,
        guest_details: { ...b.guest_snapshot },
        room_ids: b.rooms.map((r: Row) => r.id),
        check_in_at: b.check_in_at.replace(' ', 'T').slice(0, 16),
        check_out_at: b.check_out_at.replace(' ', 'T').slice(0, 16),
        reason: '',
        notify_guest: false,
      });
      await bookingRooms();
    });
  }
  async function saveApproved() {
    await task(async () => {
      const id = selected.value!.id;
      await api('patch', `bookings/${id}/approved-details`, form);
      modal.value = '';
      notice.value =
        'Booking changes saved. Payment history is preserved and any price difference has been recorded.';
      await refresh();
      await view(id);
    });
  }
  function selectEditGuest() {
    if (modal.value === 'edit-approved') {
      const g = guests.value.find((g) => g.id === form.guest_id);
      if (g)
        form.guest_details = {
          name: g.name,
          designation: g.designation,
          organization: g.organization,
          mobile: g.mobile,
          email: g.email,
          address: g.address,
        };
    }
  }
  async function editDraft() {
    const b = selected.value!;
    await task(async () => {
      await loadGuests();
      open('edit-booking', {
        ...b,
        room_ids: b.rooms.map((r: Row) => r.id),
        check_in_at: b.check_in_at.replace(' ', 'T').slice(0, 16),
        check_out_at: b.check_out_at.replace(' ', 'T').slice(0, 16),
      });
      await bookingRooms();
    });
  }
  // Staff propose amendments; the original booking stays effective until approval.
  async function amend() {
    const b = selected.value!;
    await task(async () => {
      await loadGuests();
      open('amendment', {
        ...b,
        room_ids: b.rooms.map((r: Row) => r.id),
        check_in_at: b.check_in_at.replace(' ', 'T').slice(0, 16),
        check_out_at: b.check_out_at.replace(' ', 'T').slice(0, 16),
        reason: '',
      });
      await bookingRooms();
    });
  }
  async function saveAmendment() {
    await task(async () => {
      await api('post', `bookings/${selected.value!.id}/amendments`, form);
      modal.value = '';
      notice.value = 'Amendment submitted. Original booking remains in force.';
      await view(selected.value!.id);
    });
  }
  async function decideAmendment(a: Row, action: string) {
    await task(async () => {
      await api('post', `booking-amendments/${a.id}/${action}`, {});
      await view(selected.value!.id);
    });
  }
  async function upload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    await task(async () => {
      const data = new FormData();
      data.append('file', file);
      await api('post', `bookings/${selected.value!.id}/attachments`, data);
      await view(selected.value!.id);
    });
  }
  async function issueCredit() {
    await task(async () => {
      await api('post', `bookings/${selected.value!.id}/credit-notes`, {
        amount_minor: Math.round(Number(form.amount) * 100),
        reason: form.reason,
      });
      modal.value = '';
      await view(selected.value!.id);
    });
  }
  async function financeAction() {
    await task(async () => {
      await api(
        'post',
        `payments/${form.id}/${form.action}`,
        form.action === 'refunds'
          ? { amount_minor: Math.round(Number(form.amount) * 100), reason: form.reason }
          : { reason: form.reason },
      );
      modal.value = '';
      await view(selected.value!.id);
    });
  }
  async function changePassword() {
    await task(async () => {
      await api('put', 'auth/password', passwordForm);
      notice.value = 'Password updated.';
      passwordForm.current_password = '';
      passwordForm.password = '';
      passwordForm.password_confirmation = '';
    });
  }
  async function retry(id: number) {
    await task(async () => {
      await api('post', `notifications/${id}/retry`, {});
      await refresh();
    });
  }
  async function cloneTariff() {
    await task(async () => {
      await api('post', `tariffs/${form.id}/clone`, form);
      modal.value = '';
      await refresh();
    });
  }
  async function saveRate() {
    await task(async () => {
      await api('patch', `tariffs/${form.tariff_id}/rates/${form.id}`, {
        amount_minor: Math.round(Number(form.amount) * 100),
      });
      modal.value = '';
      await refresh();
    });
  }
  // Apply saved account preferences before enabling browser-to-server synchronization.
  const preferencesReady = ref(false);
  watch([themePreference, lang], async () => {
    if (!user.value || !preferencesReady.value) return;
    try {
      await api('patch', 'auth/preferences', {
        theme: themePreference.value,
        language: lang.value,
      });
    } catch {
      /* Local preferences remain usable if syncing fails. */
    }
  });
  onMounted(async () => {
    try {
      user.value = (await api('get', 'auth/me')).data;
      try {
        const p = (await api('get', 'auth/preferences')).data;
        if (p.persisted) {
          themePreference.value = p.theme;
          lang.value = p.language;
          localStorage.setItem('resthouse-lang', p.language);
        } else
          await api('patch', 'auth/preferences', {
            theme: themePreference.value,
            language: lang.value,
          });
      } catch {
        /* Keep local settings when preferences are unavailable. */
      }
      await refresh();
      preferencesReady.value = true;
    } catch (e: any) {
      if (e.response?.status !== 401) cleanError(e);
    }
  });
</script>

<template>
  <div
    v-if="!user"
    class="login-layout"
  >
    <section class="login-story"
      ><div class="w-full max-w-[360px] rounded-xl bg-white p-3">
        <img
          :src="brandLogoUrl"
          alt="Coxbazarbanglo"
          width="1977"
          height="582"
          class="block h-auto w-full"
          fetchpriority="high"
        /> </div
      ><p class="eyebrow">BIWTA · COX'S BAZAR</p
      ><h1>A welcoming stay.<br />A well-managed<br />rest house.</h1
      ><p class="story-text"
        >কক্সবাজার পরিদর্শন বাংলো<br />Reservations, guest care and room management in one place.</p
      ><div class="story-footer"
        >14 rooms <span>·</span> 3 floors <span>·</span> One clear view</div
      ></section
    >
    <main class="login-main"
      ><div class="login-theme-control"
        ><button
          class="theme-toggle"
          type="button"
          :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
          :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
          :aria-pressed="isDark"
          @click="toggleTheme"
          ><AppIcon :name="isDark ? 'Sun' : 'Moon'" /><span>{{
            isDark ? 'Light mode' : 'Dark mode'
          }}</span></button
        ></div
      ><div class="login-card"
        ><img
          :src="brandLogoUrl"
          alt="Coxbazarbanglo"
          width="1977"
          height="582"
          class="mb-6 block h-auto w-full rounded-lg bg-white p-3 min-[1025px]:hidden"
        /><p class="eyebrow">REST HOUSE MANAGEMENT</p
        ><h2>{{
          loginMode === 'login'
            ? 'Welcome back'
            : loginMode === 'forgot'
              ? 'Recover your account'
              : 'Set a new password'
        }}</h2
        ><p class="muted">{{
          loginMode === 'login'
            ? 'Sign in with your administrator or staff account.'
            : 'Use the email address registered to your account.'
        }}</p>
        <form @submit.prevent="signIn"
          ><label
            >Email address<input
              v-model="login.email"
              type="email"
              required
              autocomplete="username"
              placeholder="you@example.com" /></label
          ><label v-if="loginMode !== 'forgot'"
            >Password<input
              v-model="login.password"
              type="password"
              required
              :minlength="loginMode === 'reset' ? 12 : 1"
              :autocomplete="loginMode === 'login' ? 'current-password' : 'new-password'" /></label
          ><label v-if="loginMode === 'reset'"
            >Confirm password<input
              v-model="form.password_confirmation"
              type="password"
              required /></label
          ><p
            v-if="error"
            class="alert danger"
            role="alert"
            >{{ error }}</p
          ><p
            v-if="notice"
            class="alert success"
            >{{ notice }}</p
          ><button
            class="primary wide"
            :disabled="busy"
            >{{
              busy
                ? 'Please wait…'
                : loginMode === 'login'
                  ? 'Sign in →'
                  : loginMode === 'forgot'
                    ? 'Request reset link'
                    : 'Reset password'
            }}</button
          ></form
        ><button
          class="link"
          @click="loginMode = loginMode === 'login' ? 'forgot' : 'login'"
          >{{ loginMode === 'login' ? 'Forgot password?' : 'Back to sign in' }}</button
        ><p class="small muted"
          >Authorized staff access only. Guest bookings are managed by the rest-house office.</p
        >
      </div></main
    >
  </div>
  <div
    v-else
    class="app-layout"
  >
    <button
      v-if="mobileMenu"
      class="mobile-nav-backdrop"
      aria-label="Close navigation"
      @click="mobileMenu = false"
    ></button
    ><aside
      id="main-navigation"
      class="sidebar"
      :class="{ 'mobile-open': mobileMenu }"
      :role="mobileMenu ? 'dialog' : undefined"
      :aria-modal="mobileMenu ? true : undefined"
      aria-label="Main navigation"
      ><button
        class="mobile-nav-close"
        aria-label="Close navigation"
        @click="mobileMenu = false"
        ><AppIcon name="Close" /></button
      ><a
        href="/"
        class="brand"
        aria-label="Coxbazarbanglo home"
        ><img
          :src="brandLogoUrl"
          alt="Coxbazarbanglo"
          width="1977"
          height="582"
          class="block h-auto w-full rounded-lg bg-white p-2" /></a
      ><p class="nav-caption">WORKSPACE</p
      ><nav
        ><button
          v-for="item in nav"
          :key="item"
          :class="{ active: page === item }"
          @click="navigate(item)"
          ><AppIcon :name="item" />{{ t(item)
          }}<b v-if="item === 'Bookings' && dashboard.pending">{{ dashboard.pending }}</b></button
        ></nav
      ><div class="sidebar-bottom"
        ><div class="avatar">{{ user.name.slice(0, 1) }}</div
        ><div
          ><strong>{{ user.name }}</strong
          ><small>{{ pretty(user.role) }}</small></div
        ><button
          class="signout"
          @click="signOut"
          title="Sign out"
          aria-label="Sign out"
          ><AppIcon name="Logout" /></button></div
    ></aside>
    <div class="workspace"
      ><header class="topbar"
        ><div class="topbar-location"
          ><button
            class="mobile-menu-toggle"
            aria-label="Open navigation"
            aria-controls="main-navigation"
            :aria-expanded="mobileMenu"
            @click="mobileMenu = true"
            ><AppIcon name="Menu" /></button
          ><span class="breadcrumb">Rest house <span>/</span> {{ t(page) }}</span></div
        ><div class="topbar-controls"
          ><button
            class="theme-toggle"
            type="button"
            :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            :aria-pressed="isDark"
            @click="toggleTheme"
            ><AppIcon :name="isDark ? 'Sun' : 'Moon'" /><span>{{
              isDark ? 'Light mode' : 'Dark mode'
            }}</span></button
          ><button
            class="language"
            @click="setLanguage()"
            >{{ lang === 'en' ? 'বাংলা' : 'English' }}</button
          ><span class="today">{{ displayDate(today) }}</span></div
        ></header
      >
      <main
        ><div class="page-heading"
          ><div
            ><p class="eyebrow">COX'S BAZAR INSPECTION BUNGALOW</p><h1>{{ t(page) }}</h1
            ><p class="muted">{{
              page === 'Dashboard'
                ? 'Your daily overview of stays, rooms and guest care.'
                : page === 'Calendar'
                  ? 'A room-by-room view of upcoming stays. All times are Asia/Dhaka.'
                  : page === 'Bookings'
                    ? 'Manage requests from first entry to checkout.'
                    : page === 'Rooms'
                      ? 'Check availability and keep every room ready for its next guest.'
                      : 'Manage your rest-house operations.'
            }}</p></div
          ><button
            v-if="['Dashboard', 'Bookings', 'Calendar'].includes(page)"
            class="primary"
            @click="newBooking"
            >＋ New booking</button
          ><button
            v-if="page === 'Guests'"
            class="primary"
            @click="open('guest', {})"
            >＋ Add guest</button
          ><button
            v-if="page === 'Reservations'"
            class="primary"
            @click="
              open('block', {
                kind: 'reservation',
                reason: '',
                room_ids: [],
                starts_at: today + 'T14:00',
                ends_at: add(today, 1) + 'T12:00',
              })
            "
            >＋ Reserve / block rooms</button
          ><button
            v-if="page === 'Staff'"
            class="primary"
            @click="
              open('staff', {
                name: '',
                email: '',
                role: 'staff_admin',
                active: true,
                password: '',
              })
            "
            >＋ Add staff</button
          ></div
        >
        <div
          v-if="error && !modal"
          class="alert danger"
          role="alert"
          >{{ error }}<button @click="error = ''">×</button></div
        ><div
          v-if="notice"
          class="alert success"
          >{{ notice }}<button @click="notice = ''">×</button></div
        ><p
          v-if="loading"
          class="loading"
          >Updating your workspace…</p
        >

        <template v-if="page === 'Dashboard'">
          <div
            v-if="!dashboard.tariff_ready || dashboard.bookable_rooms !== 14"
            class="setup-banner"
            ><div
              ><strong>Finish your opening setup</strong
              ><p
                >Approve the tariff calculation policy and verify each room's capacity and
                bookability before confirming stays.</p
              ></div
            ><button
              v-if="admin"
              @click="navigate(!dashboard.tariff_ready ? 'Tariffs' : 'Rooms')"
              >Continue setup →</button
            ></div
          >
          <section class="stats"
            ><article
              v-for="s in [
                { name: 'Arrivals today', value: dashboard.arrivals, caption: 'Guests expected' },
                {
                  name: 'Departures today',
                  value: dashboard.departures,
                  caption: 'Ready for checkout',
                },
                {
                  name: 'Occupied stays',
                  value: dashboard.occupied,
                  caption: 'Currently checked in',
                },
                {
                  name: 'Pending approval',
                  value: dashboard.pending,
                  caption: 'Requests to review',
                },
              ]"
              :key="s.name"
              class="stat"
              ><span>{{ s.name }}</span
              ><strong>{{ s.value || 0 }}</strong
              ><small>{{ s.caption }}</small></article
            ></section
          >
          <div class="dashboard-grid"
            ><section class="panel"
              ><div class="section-heading"
                ><div
                  ><h2>A clear view of every stay</h2
                  ><p class="muted">Plan arrivals, confirm requests and keep rooms moving.</p></div
                ><span class="tag teal">LIVE WORKSPACE</span></div
              ><div class="quick-actions"
                ><button @click="navigate('Calendar')"
                  ><span><AppIcon name="Calendar" /></span><strong>Booking calendar</strong
                  ><small>See rooms and upcoming stays</small><b>↗</b></button
                ><button
                  @click="
                    filter = 'pending_approval';
                    navigate('Bookings');
                  "
                  ><span><AppIcon name="Approval" /></span><strong>Review requests</strong
                  ><small>{{ dashboard.pending || 0 }} waiting for approval</small><b>↗</b></button
                ><button @click="navigate('Rooms')"
                  ><span><AppIcon name="Rooms" /></span><strong>Room readiness</strong
                  ><small>{{ dashboard.bookable_rooms || 0 }} of 14 verified for booking</small
                  ><b>↗</b></button
                ></div
              ></section
            ><section class="financial-card"
              ><p class="eyebrow">TODAY'S COLLECTIONS</p
              ><h2>{{ money(dashboard.collections_minor) }}</h2
              ><p>Posted payments received today</p><hr /><span>Outstanding receivables</span
              ><strong>{{ money(dashboard.receivables_minor) }}</strong
              ><button
                v-if="admin"
                @click="navigate('Reports')"
                >View financial report →</button
              ></section
            ></div
          >
          <section class="panel reminder"
            ><span>ⓘ</span
            ><div
              ><strong>Payment and room readiness are tracked separately.</strong
              ><p class="muted"
                >Checkout ends occupancy. Mark a room ready after cleaning before the next guest
                checks in.</p
              ></div
            ></section
          >
        </template>

        <template v-if="page === 'Bookings'">
          <div class="toolbar"
            ><input
              v-model="search"
              placeholder="Search guest, mobile or booking number"
              @keyup.enter="task(() => loadBookings())"
            /><select
              v-model="filter"
              @change="task(() => loadBookings())"
              ><option value="">All statuses</option
              ><option
                v-for="s in [
                  'draft',
                  'pending_approval',
                  'confirmed',
                  'checked_in',
                  'checked_out',
                  'rejected',
                  'cancelled',
                  'no_show',
                ]"
                :value="s"
                >{{ pretty(s) }}</option
              ></select
            ><button @click="task(() => loadBookings())">Search</button></div
          >
          <div class="panel table-wrap"
            ><table v-responsive-table
              ><thead
                ><tr
                  ><th>Booking / Guest</th><th>Stay dates</th><th>Rooms</th><th>Status</th
                  ><th>Quoted total</th><th></th></tr></thead
              ><tbody
                ><tr
                  v-for="b in bookings"
                  :key="b.id"
                  ><td
                    ><strong>{{ b.guest_snapshot.name }}</strong
                    ><small>{{ b.number }} · {{ b.guest_snapshot.mobile }}</small></td
                  ><td
                    >{{ displayDate(b.check_in_at)
                    }}<small>to {{ displayDate(b.check_out_at) }}</small></td
                  ><td>{{ b.rooms.map((r: Row) => `${r.code} — ${r.name}`).join(', ') }}</td
                  ><td
                    ><span
                      class="tag"
                      :class="b.status"
                      >{{ pretty(b.status) }}</span
                    ></td
                  ><td>{{ money(b.total_minor) }}</td
                  ><td
                    ><button
                      class="link"
                      @click="view(b.id)"
                      >View →</button
                    ></td
                  ></tr
                ><tr v-if="!bookings.length"
                  ><td
                    colspan="6"
                    class="empty"
                    >No bookings found. Create a draft to get started.</td
                  ></tr
                ></tbody
              ></table
            ></div
          ><button
            v-if="nextBookings"
            @click="task(() => loadBookings(true))"
            >Load more bookings</button
          >
        </template>

        <template v-if="page === 'Calendar'">
          <div class="toolbar"
            ><button
              @click="
                calendarStart = add(calendarStart, -calendarSpan);
                task(loadCalendar);
              "
              >←</button
            ><input
              type="date"
              v-model="calendarStart"
              @change="task(loadCalendar)"
            /><button
              @click="
                calendarStart = add(calendarStart, calendarSpan);
                task(loadCalendar);
              "
              >→</button
            ><select
              v-model="calendarSpan"
              @change="task(loadCalendar)"
              ><option :value="1">Day</option
              ><option :value="7">Week</option
              ><option :value="30">30 days</option></select
            ><div class="legend"
              ><span class="tag confirmed">Confirmed</span
              ><span class="tag checked_in">Occupied</span
              ><span class="tag reservation">Reserved</span
              ><span class="tag maintenance">Maintenance</span
              ><span class="tag pending_approval">Request</span></div
            ></div
          >
          <p class="mobile-scroll-hint"
            >Swipe the calendar horizontally to see more dates. Room names stay visible.</p
          ><div
            class="panel table-wrap calendar"
            tabindex="0"
            role="region"
            aria-label="Scrollable room booking calendar"
            ><table :style="{ minWidth: 140 + calendarSpan * 115 + 'px' }"
              ><thead
                ><tr
                  ><th>Room</th
                  ><th
                    v-for="d in dates"
                    :key="d"
                    >{{ displayDate(d).slice(0, 6) }}</th
                  ></tr
                ></thead
              ><tbody
                ><tr
                  v-for="r in catalog.rooms"
                  :key="r.id"
                  ><th
                    >{{ r.code }}<small>{{ r.name }}</small></th
                  ><td
                    v-for="d in dates"
                    :key="d"
                    ><button
                      v-if="cell(r, d)"
                      class="calendar-entry"
                      :class="cell(r, d).status"
                      @click="cell(r, d).booking_id && view(cell(r, d).booking_id)"
                      >{{ cell(r, d).label }}</button
                    ><span
                      v-else
                      class="free-cell"
                      >{{ r.bookable ? 'Available' : 'Unverified' }}</span
                    ></td
                  ></tr
                ></tbody
              ></table
            ></div
          ><p class="small muted"
            >Cells represent chargeable nights. Pending requests do not reserve inventory. Open
            booking details for exact arrival and departure times.</p
          >
        </template>

        <template v-if="page === 'Rooms'">
          <div class="toolbar"
            ><label
              >Arrival<input
                type="datetime-local"
                v-model="roomDates.check_in_at" /></label
            ><label
              >Departure<input
                type="datetime-local"
                v-model="roomDates.check_out_at" /></label
            ><button
              class="primary"
              @click="task(findRooms)"
              >Check availability</button
            ></div
          >
          <div
            v-for="floor in catalog.floors"
            :key="floor.id"
            class="floor-group"
            ><h2
              >{{ floor.name }}
              <span class="muted small"
                >{{ availability.filter((r) => r.floor_id === floor.id).length }} rooms</span
              ></h2
            ><div class="room-grid"
              ><article
                v-for="r in availability.filter((r) => r.floor_id === floor.id)"
                :key="r.id"
                class="room-card"
                ><div class="room-top"
                  ><span>{{ r.code }}</span
                  ><span
                    class="tag"
                    :class="r.available ? 'confirmed' : 'maintenance'"
                    >{{
                      !r.bookable ? 'Unverified' : r.available ? 'Available' : 'Unavailable'
                    }}</span
                  ></div
                ><h3>{{ r.name }}</h3
                ><p>{{ r.ac_type === 'ac' ? 'AC' : 'Non-AC' }} · {{ r.bed_type }}</p
                ><div class="room-meta"
                  ><span>Capacity: {{ r.capacity || 'Not verified' }}</span
                  ><span>{{ pretty(r.housekeeping) }}</span></div
                ><button
                  v-if="admin"
                  @click="open('room', { ...r })"
                  >Manage room →</button
                ></article
              ></div
            ></div
          >
        </template>

        <template v-if="page === 'Guests'"
          ><div class="toolbar"
            ><input
              v-model="guestSearch"
              placeholder="Find by name or mobile"
              @keyup.enter="task(() => loadGuests())"
            /><button @click="task(() => loadGuests())">Search</button></div
          ><div class="panel table-wrap"
            ><table v-responsive-table
              ><thead
                ><tr
                  ><th>Name</th><th>Designation</th><th>Organization</th><th>Contact</th></tr
                ></thead
              ><tbody
                ><tr
                  v-for="g in guests"
                  :key="g.id"
                  ><td
                    ><strong>{{ g.name }}</strong></td
                  ><td>{{ g.designation || '—' }}</td
                  ><td>{{ g.organization || '—' }}</td
                  ><td
                    >{{ g.mobile }}<small>{{ g.email }}</small></td
                  ></tr
                ><tr v-if="!guests.length"
                  ><td
                    colspan="4"
                    class="empty"
                    >No guests yet. Add your first guest.</td
                  ></tr
                ></tbody
              ></table
            ></div
          ><button
            v-if="nextGuests"
            @click="task(() => loadGuests(true))"
            >Load more guests</button
          ></template
        >

        <template v-if="page === 'Tariffs'"
          ><div class="alert info"
            >All reference rates include VAT. Confirm how duration bands apply before activation.
            Approved versions remain immutable.</div
          ><section
            v-for="tariff in catalog.tariffs"
            :key="tariff.id"
            class="panel tariff-panel"
            ><div class="section-heading"
              ><div
                ><h2>{{ tariff.name }}</h2
                ><p class="muted"
                  >{{ tariff.reference }} ·
                  {{
                    tariff.active
                      ? pretty(tariff.calculation_mode)
                      : tariff.approved_at
                        ? 'Previously approved'
                        : 'Awaiting policy approval'
                  }}</p
                ></div
              ><div class="button-row"
                ><span
                  class="tag"
                  :class="tariff.active ? 'confirmed' : 'pending_approval'"
                  >{{ tariff.active ? 'Active' : 'Inactive' }}</span
                ><button
                  v-if="!tariff.approved_at"
                  class="primary"
                  @click="
                    open('tariff', {
                      id: tariff.id,
                      calculation_mode: '',
                      effective_from: today,
                      check_in_time: '14:00',
                      check_out_time: '12:00',
                      turnaround_minutes: 0,
                      notification_channel: 'email',
                      policy_acknowledged: false,
                      reason: '',
                    })
                  "
                  >Review & activate</button
                ><button
                  @click="
                    open('clone', {
                      id: tariff.id,
                      name: tariff.name + ' — revision',
                      reference: '',
                    })
                  "
                  >Clone version</button
                ></div
              ></div
            ><div class="table-wrap"
              ><table v-responsive-table
                ><thead
                  ><tr
                    ><th>Category</th><th>Room class</th><th>Duration band</th
                    ><th>VAT-inclusive rate</th><th></th></tr></thead
                ><tbody
                  ><tr
                    v-for="rate in tariff.rates"
                    :key="rate.id"
                    ><td>{{
                      catalog.categories.find((c: Row) => c.id === rate.guest_category_id)?.code
                    }}</td
                    ><td>{{ rate.ac_type === 'ac' ? 'AC' : 'Non-AC' }}</td
                    ><td>{{ rate.min_nights }}–{{ rate.max_nights || '∞' }} days</td
                    ><td>{{ money(rate.amount_minor) }}</td
                    ><td
                      ><button
                        v-if="!tariff.approved_at"
                        class="link"
                        @click="
                          open('rate', {
                            ...rate,
                            tariff_id: tariff.id,
                            amount: rate.amount_minor / 100,
                          })
                        "
                        >Edit</button
                      ></td
                    ></tr
                  ></tbody
                ></table
              ></div
            ></section
          ><div class="panel"
            ><h2>Guest categories</h2
            ><p
              v-for="c in catalog.categories"
              :key="c.id"
              ><strong>{{ c.code }}</strong> — {{ c.name }}
              <span class="muted"
                >({{
                  c.max_nights ? 'Maximum ' + c.max_nights + ' nights' : 'No source maximum stated'
                }})</span
              ></p
            ></div
          ></template
        >

        <template v-if="page === 'Reservations'"
          ><div class="panel table-wrap"
            ><table v-responsive-table
              ><thead
                ><tr
                  ><th>Type / Reason</th><th>Rooms</th><th>Interval</th><th>Status</th
                  ><th></th></tr></thead
              ><tbody
                ><tr
                  v-for="b in blocks"
                  :key="b.id"
                  ><td
                    ><strong>{{ pretty(b.kind) }}</strong
                    ><small>{{ b.reason }}</small></td
                  ><td>{{
                    b.entries
                      .map((e: Row) => catalog.rooms.find((r: Row) => r.id === e.room_id)?.code)
                      .join(', ')
                  }}</td
                  ><td
                    >{{ b.entries[0]?.starts_at }}<small>to {{ b.entries[0]?.ends_at }}</small></td
                  ><td
                    ><span class="tag">{{ b.status }}</span></td
                  ><td
                    ><button
                      v-if="b.status === 'active'"
                      @click="open('release', { id: b.id, reason: '' })"
                      >Release</button
                    ></td
                  ></tr
                ><tr v-if="!blocks.length"
                  ><td
                    colspan="5"
                    class="empty"
                    >No reservations or maintenance blocks.</td
                  ></tr
                ></tbody
              ></table
            ></div
          ></template
        >

        <template v-if="page === 'Reports'"
          ><div class="toolbar"
            ><label
              >From<input
                type="date"
                v-model="range.start" /></label
            ><label
              >Through<input
                type="date"
                v-model="range.end" /></label
            ><button
              class="primary"
              @click="task(loadReport)"
              >Run report</button
            ><button @click="exportCsv">Export CSV</button></div
          ><section class="stats"
            ><article class="stat"
              ><span>Collections</span
              ><strong class="money-stat">{{ money(report.collections_minor) }}</strong></article
            ><article class="stat"
              ><span>Refunds</span
              ><strong class="money-stat">{{ money(report.refunds_minor) }}</strong></article
            ><article class="stat"
              ><span>Net collections</span
              ><strong class="money-stat">{{
                money(report.net_collections_minor)
              }}</strong></article
            ><article class="stat"
              ><span>Stay balances</span
              ><strong class="money-stat">{{ money(report.receivables_minor) }}</strong></article
            ></section
          ><p class="small muted"
            >Collections use payment posting dates; the stay list uses bookings overlapping this
            date range.</p
          ><div class="panel table-wrap"
            ><table v-responsive-table
              ><thead
                ><tr
                  ><th>Booking</th><th>Guest</th><th>Rooms</th><th>Status</th><th>Charges</th
                  ><th>Balance</th></tr
                ></thead
              ><tbody
                ><tr
                  v-for="b in report.bookings"
                  :key="b.id"
                  ><td
                    ><button
                      class="link"
                      @click="view(b.id)"
                      >{{ b.number }}</button
                    ></td
                  ><td>{{ b.guest }}</td
                  ><td>{{ b.rooms }}</td
                  ><td>{{ pretty(b.status) }}</td
                  ><td>{{ money(b.finance.charges_minor) }}</td
                  ><td>{{ money(b.finance.balance_minor) }}</td></tr
                ></tbody
              ></table
            ></div
          ></template
        >

        <template v-if="page === 'Staff'"
          ><div class="panel table-wrap"
            ><table v-responsive-table
              ><thead
                ><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead
              ><tbody
                ><tr
                  v-for="u in users"
                  :key="u.id"
                  ><td>{{ u.name }}</td
                  ><td>{{ u.email }}</td
                  ><td>{{ pretty(u.role) }}</td
                  ><td>{{ u.active ? 'Active' : 'Disabled' }}</td
                  ><td><button @click="open('staff', { ...u, password: '' })">Edit</button></td></tr
                ></tbody
              ></table
            ></div
          ></template
        >
        <template v-if="page === 'Notifications'"
          ><div class="alert info"
            >Run the scheduler and queue worker to send queued messages. Email requires SMTP. SMS
            and WhatsApp require Twilio credentials, approved senders/templates and a public HTTPS
            callback.</div
          ><div class="panel table-wrap"
            ><table v-responsive-table
              ><thead
                ><tr
                  ><th>Booking</th><th>Channel</th><th>Destination</th><th>Status</th
                  ><th>Delivery detail</th><th></th></tr></thead
              ><tbody
                ><tr
                  v-for="n in deliveries"
                  :key="n.id"
                  ><td
                    ><button
                      class="link"
                      @click="view(n.booking_id)"
                      >#{{ n.booking_id }}</button
                    ></td
                  ><td>{{ n.channel }}</td
                  ><td>{{ n.destination }}</td
                  ><td>{{ n.status }}</td
                  ><td>{{ n.last_error || '—' }}</td
                  ><td
                    ><button
                      v-if="n.status === 'failed'"
                      @click="retry(n.id)"
                      >Retry</button
                    ></td
                  ></tr
                ><tr v-if="!deliveries.length"
                  ><td
                    colspan="6"
                    class="empty"
                    >No guest notifications yet.</td
                  ></tr
                ></tbody
              ></table
            ></div
          ></template
        >
        <template v-if="page === 'Audit trail'"
          ><div class="panel table-wrap"
            ><table v-responsive-table
              ><thead
                ><tr><th>Time</th><th>Action</th><th>Entity</th><th>Actor</th></tr></thead
              ><tbody
                ><tr
                  v-for="a in logs"
                  :key="a.id"
                  ><td>{{ a.created_at }}</td
                  ><td>{{ pretty(a.action) }}</td
                  ><td>{{ a.entity }} #{{ a.entity_id }}</td
                  ><td>#{{ a.actor_id || 'system' }}</td></tr
                ></tbody
              ></table
            ></div
          ><p class="small muted"
            >Showing the latest 50 entries. The API provides paginated access to the complete
            history.</p
          ></template
        >
        <template v-if="page === 'Account'"
          ><section class="panel account-panel"
            ><h2>Appearance</h2
            ><p class="muted">Choose how the rest-house workspace looks on this device.</p
            ><label
              >Color mode<select v-model="themePreference"
                ><option value="light">Light</option
                ><option value="dark">Dark</option
                ><option value="system">Use device setting</option></select
              ></label
            ></section
          ><section class="panel account-panel"
            ><h2>Change password</h2
            ><p class="muted">Changing your password signs out existing mobile tokens.</p
            ><form @submit.prevent="changePassword"
              ><label
                >Current password<input
                  type="password"
                  v-model="passwordForm.current_password"
                  required
                  autocomplete="current-password" /></label
              ><label
                >New password<input
                  type="password"
                  v-model="passwordForm.password"
                  required
                  minlength="12"
                  autocomplete="new-password" /></label
              ><label
                >Confirm new password<input
                  type="password"
                  v-model="passwordForm.password_confirmation"
                  required
                  minlength="12"
                  autocomplete="new-password" /></label
              ><button
                class="primary"
                :disabled="busy"
                >Update password</button
              ></form
            ></section
          ></template
        > </main
      ><footer>BIWTA Cox's Bazar Inspection Bungalow <span>Developed by Zaman's Dev</span></footer>
    </div>

    <div
      v-if="selected"
      class="drawer-backdrop"
      @click.self="selected = null"
      ><section
        class="drawer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="booking-details-title"
        ><div class="section-heading booking-details-heading"
          ><div
            ><p class="eyebrow">BOOKING DETAILS</p
            ><h2 id="booking-details-title">{{ selected.number }}</h2></div
          ><div class="button-row"
            ><button
              v-if="admin && ['confirmed', 'checked_in', 'checked_out'].includes(selected.status)"
              class="primary edit-booking-button"
              @click="editApproved"
              ><AppIcon name="Edit" /> Edit booking</button
            ><button
              @click="selected = null"
              aria-label="Close booking details"
              >×</button
            ></div
          ></div
        ><BookingShare
          :booking-id="selected.id"
          :booking-version="selected.version"
        /><span
          class="tag"
          :class="selected.status"
          >{{ pretty(selected.status) }}</span
        ><p
          v-if="!admin && ['confirmed', 'checked_in', 'checked_out'].includes(selected.status)"
          class="small muted"
          >Approved bookings can be edited directly by an administrator. Staff can submit an
          amendment request.</p
        ><h2 class="guest-title">{{ selected.guest_snapshot.name }}</h2
        ><p
          >{{ selected.guest_snapshot.designation }} · {{ selected.guest_snapshot.organization }}</p
        ><p class="muted"
          >{{ selected.guest_snapshot.mobile }}<br />{{ selected.guest_snapshot.email }}</p
        ><div class="detail-grid"
          ><div
            ><small>ARRIVAL</small><strong>{{ selected.check_in_at }}</strong></div
          ><div
            ><small>DEPARTURE</small><strong>{{ selected.check_out_at }}</strong></div
          ><div
            ><small>ROOMS</small
            ><strong
              v-for="room in selected.rooms"
              :key="room.id"
              class="allocated-room"
              >{{ room.code }} — {{ room.name }}</strong
            ></div
          ><div
            ><small>REFERENCE LETTER</small
            ><strong>{{ selected.reference_letter || '—' }}</strong></div
          ></div
        ><div class="bill-summary"
          ><div
            ><span>Approved charges</span><strong>{{ money(finance.charges_minor) }}</strong></div
          ><div
            ><span>Net payments</span
            ><strong>{{ money(finance.paid_minor - finance.refunded_minor) }}</strong></div
          ><div
            ><span>Balance</span><strong>{{ money(finance.balance_minor) }}</strong></div
          ></div
        ><p
          v-if="selected.quote?.provisional"
          class="alert info"
          >This draft quote is provisional until the tariff is approved.</p
        >
        <div class="button-row wrap"
          ><button
            v-if="['draft', 'rejected'].includes(selected.status)"
            @click="editDraft"
            >Edit draft</button
          ><button
            v-if="selected.status === 'draft'"
            class="primary"
            @click="transition('submit')"
            >Submit for approval</button
          ><button
            v-if="admin && ['draft', 'pending_approval'].includes(selected.status)"
            class="primary"
            @click="transition('approve')"
            >Approve booking</button
          ><button
            v-if="admin && selected.status === 'pending_approval'"
            @click="transition('reject')"
            >Reject</button
          ><button
            v-if="admin && selected.status === 'pending_approval'"
            @click="transition('return-for-correction')"
            >Return for correction</button
          ><button
            v-if="admin && selected.status === 'confirmed'"
            class="primary"
            @click="transition('check-in')"
            >Check in</button
          ><button
            v-if="admin && selected.status === 'checked_in'"
            class="primary"
            @click="transition('check-out')"
            >Check out</button
          ><button
            v-if="selected.status === 'confirmed'"
            @click="amend"
            >Propose amendment</button
          ><button
            v-if="admin && ['confirmed', 'checked_in', 'checked_out'].includes(selected.status)"
            @click="
              open('payment', {
                amount: Math.max(0, finance.balance_minor) / 100,
                method: 'cash',
                reference: '',
              })
            "
            >Record payment</button
          ><button
            v-if="admin && ['draft', 'pending_approval', 'confirmed'].includes(selected.status)"
            class="danger-text"
            @click="transition('cancel')"
            >Cancel booking</button
          ><button
            v-if="admin && selected.status === 'confirmed'"
            @click="transition('mark-no-show')"
            >No-show</button
          ><button
            v-if="admin && finance.charges_minor > 0"
            @click="open('credit', { amount: finance.charges_minor / 100, reason: '' })"
            >Issue credit note</button
          ><button @click="print()">Print confirmation</button></div
        >
        <h3>Payments & receipts</h3
        ><div
          v-for="p in selected.payments"
          :key="p.id"
          class="history-item"
          ><strong>{{ p.receipt_number }} · {{ money(p.amount_minor) }}</strong
          ><small>{{ p.method }} · {{ p.status }}</small
          ><div
            v-if="admin && p.status === 'posted'"
            class="button-row"
            ><button
              class="link"
              @click="open('finance-action', { id: p.id, action: 'reverse', reason: '' })"
              >Reverse</button
            ><button
              class="link"
              @click="
                open('finance-action', { id: p.id, action: 'refunds', reason: '', amount: '' })
              "
              >Refund</button
            ></div
          ></div
        ><p
          v-if="!selected.payments.length"
          class="muted"
          >No payments posted.</p
        >
        <h3>Invoices</h3
        ><div
          v-for="i in selected.invoices"
          :key="i.id"
          class="history-item"
          ><strong>{{ i.number }} · {{ money(i.total_minor) }}</strong
          ><small>{{ i.status }} · {{ i.lines.length }} room-night charge lines</small
          ><details
            ><summary>View itemized invoice</summary
            ><table v-responsive-table
              ><thead
                ><tr><th>Date</th><th>Room</th><th>VAT-inclusive amount</th></tr></thead
              ><tbody
                ><tr
                  v-for="(line, index) in i.lines"
                  :key="index"
                  ><td>{{ line.date }}</td
                  ><td>{{ line.room_code }}</td
                  ><td>{{ money(line.amount_minor) }}</td></tr
                ></tbody
              ></table
            ></details
          ></div
        >
        <h3 v-if="selected.credits?.length">Credit notes</h3
        ><div
          v-for="c in selected.credits"
          :key="c.id"
          class="history-item"
          ><strong>{{ c.number }} · {{ money(c.amount_minor) }}</strong
          ><small>{{ c.reason }}</small></div
        ><h3>Reference documents</h3
        ><a
          v-for="a in selected.attachments"
          :key="a.id"
          class="attachment"
          :href="'/api/v1/attachments/' + a.id + '/download'"
          >{{ a.name }} ↓</a
        ><label v-if="['draft', 'rejected'].includes(selected.status)"
          >Attach reference letter (PDF/JPG/PNG, up to 5 MB)<input
            type="file"
            accept=".pdf,.jpg,.jpeg,.png"
            @change="upload"
        /></label>
        <h3>Amendments</h3
        ><div
          v-for="a in selected.amendments"
          :key="a.id"
          class="history-item"
          ><strong>{{ a.reason }}</strong
          ><small>{{ a.status }} · Proposed total {{ money(a.proposal.quote?.total_minor) }}</small
          ><div
            class="button-row"
            v-if="admin && a.status === 'pending'"
            ><button @click="decideAmendment(a, 'approve')">Approve</button
            ><button @click="decideAmendment(a, 'reject')">Reject</button></div
          ></div
        >
        <h3>History</h3
        ><div
          v-for="e in selected.events"
          :key="e.id"
          class="history-item"
          ><strong>{{ pretty(e.action) }}</strong
          ><small>{{ e.created_at }} · Staff #{{ e.actor_id }}</small
          ><p v-if="e.reason">{{ e.reason }}</p></div
        >
      </section></div
    >

    <div
      v-if="modal"
      class="modal-backdrop"
      @click.self="!busy && (modal = '')"
      ><section
        class="modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
        :class="{
          'large-modal': ['booking', 'edit-booking', 'edit-approved', 'amendment'].includes(modal),
        }"
        ><div class="section-heading"
          ><h2 id="modal-title">{{
            (
              {
                booking: 'New booking',
                'edit-booking': 'Edit booking draft',
                'edit-approved': 'Edit approved booking',
                amendment: 'Propose booking amendment',
                guest: 'Add guest',
                room: 'Verify & manage room',
                tariff: 'Approve tariff policy',
                block: 'Reserve / block rooms',
                payment: 'Record payment',
                staff: 'Staff account',
                release: 'Release room block',
                action: 'Confirm booking action',
                credit: 'Issue credit note',
                'finance-action': 'Financial correction',
                clone: 'Clone tariff version',
                rate: 'Edit draft rate',
              } as Row
            )[modal]
          }}</h2
          ><div class="button-row"
            ><button
              v-if="modal === 'edit-approved'"
              type="submit"
              form="booking-editor"
              class="primary"
              :disabled="busy || !form.room_ids.length"
              >{{ busy ? 'Saving…' : 'Save changes' }}</button
            ><button
              @click="modal = ''"
              :disabled="busy"
              aria-label="Close dialog"
              >×</button
            ></div
          ></div
        ><p
          v-if="error"
          class="alert danger"
          role="alert"
          >{{ error }}</p
        >
        <form
          id="booking-editor"
          v-if="['booking', 'edit-booking', 'edit-approved', 'amendment'].includes(modal)"
          @submit.prevent="
            modal === 'edit-approved'
              ? saveApproved()
              : modal === 'amendment'
                ? saveAmendment()
                : saveBooking()
          "
          ><div class="toolbar"
            ><input
              v-model="guestSearch"
              placeholder="Search guest name or mobile"
            /><button
              type="button"
              @click="task(() => loadGuests())"
              >Find guest</button
            ><button
              type="button"
              v-if="nextGuests"
              @click="task(() => loadGuests(true))"
              >Load more guests</button
            ></div
          ><div class="form-grid"
            ><label
              >Guest<select
                v-model="form.guest_id"
                @change="selectEditGuest"
                required
                ><option
                  value=""
                  disabled
                  >Select a guest</option
                ><option
                  v-for="g in guests"
                  :key="g.id"
                  :value="g.id"
                  >{{ g.name }} · {{ g.mobile }}</option
                ></select
              ></label
            ><label
              >Guest category<select
                v-model="form.guest_category_id"
                @change="quote = null"
                required
                ><option
                  v-for="c in catalog.categories"
                  :key="c.id"
                  :value="c.id"
                  >{{ c.code }} — {{ c.name }}</option
                ></select
              ></label
            ><label
              >Check-in (Dhaka)<input
                type="datetime-local"
                v-model="form.check_in_at"
                :min="
                  ['booking', 'edit-booking'].includes(modal) ? dhakaToday() + 'T00:00' : undefined
                "
                @change="task(bookingRooms)"
                required /></label
            ><label
              >Checkout (Dhaka)<input
                type="datetime-local"
                v-model="form.check_out_at"
                :min="
                  ['booking', 'edit-booking'].includes(modal)
                    ? form.check_in_at || dhakaToday() + 'T00:00'
                    : undefined
                "
                @change="task(bookingRooms)"
                required /></label
            ><label>Reference letter number<input v-model="form.reference_letter" /></label
            ><label
              >Number of guests<input
                type="number"
                min="1"
                max="100"
                v-model.number="form.guest_count"
                required /></label></div
          ><div
            v-if="modal === 'edit-approved'"
            class="form-grid"
            ><label
              >Guest name<input
                v-model="form.guest_details.name"
                required /></label
            ><label>Designation<input v-model="form.guest_details.designation" /></label
            ><label>Organization<input v-model="form.guest_details.organization" /></label
            ><label
              >Mobile<input
                v-model="form.guest_details.mobile"
                required /></label
            ><label
              >Email<input
                type="email"
                v-model="form.guest_details.email" /></label
            ><label>Address<textarea v-model="form.guest_details.address"></textarea></label
            ><label
              >Reference letter date<input
                type="date"
                v-model="form.reference_date" /></label
            ><label
              >Approved tariff<select
                v-model="form.tariff_version_id"
                @change="quote = null"
                required
                ><option
                  v-for="tariff in catalog.tariffs.filter((t: Row) => t.approved_at)"
                  :key="tariff.id"
                  :value="tariff.id"
                  >{{ tariff.name }} · {{ tariff.reference }}</option
                ></select
              ></label
            ></div
          ><p class="small muted"
            >Add a new guest from the Guest directory before creating the booking. Bookings over ten
            nights are restricted by guest category.</p
          ><h3>Select rooms</h3
          ><div class="room-picker"
            ><button
              type="button"
              v-for="r in availability"
              :key="r.id"
              :disabled="!r.available && !form.room_ids.includes(r.id)"
              :class="{ chosen: form.room_ids.includes(r.id) }"
              @click="toggleRoom(r.id)"
              ><strong>{{ r.code }}</strong
              ><span>{{ r.name }}</span
              ><small
                >{{ r.ac_type === 'ac' ? 'AC' : 'Non-AC' }} ·
                {{
                  r.available
                    ? 'Available'
                    : form.room_ids.includes(r.id)
                      ? 'Current room'
                      : 'Unavailable'
                }}</small
              ></button
            ></div
          ><div class="form-grid"
            ><label>Purpose<textarea v-model="form.purpose"></textarea></label
            ><label>Notes<textarea v-model="form.notes"></textarea></label></div
          ><label v-if="['amendment', 'edit-approved'].includes(modal)"
            >Reason for change<input
              v-model="form.reason"
              required /></label
          ><div v-if="modal === 'edit-approved'"
            ><label class="check-label"
              ><input
                type="checkbox"
                v-model="form.notify_guest"
              />Send the updated confirmation to the guest</label
            ><p class="small muted"
              >Changes apply to this booking's guest details. Payments and issued invoices remain in
              history; a price increase adds an adjustment invoice and a decrease issues a credit
              note. Approval status is retained.</p
            ></div
          ><div
            v-if="quote"
            class="quote"
            ><strong
              >{{ quote.provisional ? 'Provisional quote' : 'Quote' }}:
              {{ money(quote.total_minor) }}</strong
            ><span
              >{{ quote.nights }} nights · {{ pretty(quote.calculation_mode) }} · VAT included</span
            ></div
          ><div class="modal-actions"
            ><button
              type="button"
              @click="getQuote"
              :disabled="busy || !form.room_ids.length"
              >Calculate quote</button
            ><button
              class="primary"
              :disabled="busy || !form.room_ids.length"
              >{{
                busy
                  ? 'Saving…'
                  : modal === 'edit-approved'
                    ? 'Save changes'
                    : modal === 'amendment'
                      ? 'Submit amendment'
                      : 'Save draft'
              }}</button
            ></div
          ></form
        >
        <form
          v-if="modal === 'guest'"
          @submit.prevent="saveGuest"
          ><label
            >Full name<input
              v-model="form.name"
              required /></label
          ><div class="form-grid"
            ><label>Designation<input v-model="form.designation" /></label
            ><label>Organization<input v-model="form.organization" /></label
            ><label
              >Mobile<input
                v-model="form.mobile"
                placeholder="+88017XXXXXXXX"
                required /></label
            ><label
              >Email<input
                type="email"
                v-model="form.email" /></label></div
          ><label>Address<textarea v-model="form.address"></textarea></label
          ><button
            class="primary"
            :disabled="busy"
            >Save guest</button
          ></form
        >
        <form
          v-if="modal === 'room'"
          @submit.prevent="saveRoom"
          ><p>{{ form.code }} · {{ form.ac_type === 'ac' ? 'AC' : 'Non-AC' }}</p
          ><label
            >Room name<input
              v-model="form.name"
              required /></label
          ><label
            >Verified capacity<input
              type="number"
              min="1"
              max="20"
              v-model.number="form.capacity"
              required /></label
          ><label
            >Housekeeping<select v-model="form.housekeeping"
              ><option value="unverified">Unverified</option
              ><option value="ready">Ready</option
              ><option value="needs_cleaning">Needs cleaning</option
              ><option value="cleaning">Cleaning</option></select
            ></label
          ><label class="check-label"
            ><input
              type="checkbox"
              v-model="form.bookable"
            />Verified for guest booking</label
          ><p class="small muted">Verify R102 separately: the source names it a waiting room.</p
          ><button
            class="primary"
            :disabled="busy"
            >Save room</button
          ></form
        >
        <form
          v-if="modal === 'tariff'"
          @submit.prevent="activate"
          ><p class="alert info"
            >A four-night Category A AC stay is BDT 1,250 with progressive rates or BDT 2,000 with
            whole-stay rates. Choose the policy authorized by your office.</p
          ><label
            >Duration-band calculation<select
              v-model="form.calculation_mode"
              required
              ><option
                disabled
                value=""
                >Choose approved calculation method</option
              ><option value="progressive">Progressive — each night uses its duration band</option
              ><option value="whole_stay"
                >Whole stay — one band applies to every night</option
              ></select
            ></label
          ><div class="form-grid"
            ><label
              >Effective from<input
                type="date"
                v-model="form.effective_from"
                required /></label
            ><label
              >Turnaround minutes<input
                type="number"
                min="0"
                max="1440"
                v-model.number="form.turnaround_minutes"
                required /></label
            ><label
              >Standard check-in<input
                type="time"
                v-model="form.check_in_time"
                required /></label
            ><label
              >Standard checkout<input
                type="time"
                v-model="form.check_out_time"
                required /></label></div
          ><label
            >Guest confirmation channel<select v-model="form.notification_channel"
              ><option value="email">Email (requires SMTP)</option
              ><option value="sms">SMS (requires Twilio configuration)</option
              ><option value="whatsapp"
                >WhatsApp (requires approved Twilio template)</option
              ></select
            ></label
          ><label
            >Approval reference / reason<textarea
              v-model="form.reason"
              required
            ></textarea></label
          ><label class="check-label"
            ><input
              type="checkbox"
              v-model="form.policy_acknowledged"
              required
            />I approve per-room, per-night charging, overnight stays only, arrival-version rates
            for the entire stay, and pre-arrival amendments with a new quote. Consecutive stays
            require manual admin review.</label
          ><button
            class="primary"
            :disabled="busy"
            >Approve & activate policy</button
          ></form
        >
        <form
          v-if="modal === 'block'"
          @submit.prevent="saveBlock"
          ><label
            >Type<select v-model="form.kind"
              ><option value="reservation">Admin reservation</option
              ><option value="maintenance">Maintenance</option></select
            ></label
          ><label
            >Reason<input
              v-model="form.reason"
              required /></label
          ><div class="form-grid"
            ><label
              >From<input
                type="datetime-local"
                v-model="form.starts_at"
                required /></label
            ><label
              >Until<input
                type="datetime-local"
                v-model="form.ends_at"
                required /></label></div
          ><div class="check-grid"
            ><label
              class="check-label"
              v-for="r in catalog.rooms"
              :key="r.id"
              ><input
                type="checkbox"
                :value="r.id"
                v-model="form.room_ids"
              />{{ r.code }} · {{ r.name }}</label
            ></div
          ><button
            class="primary"
            :disabled="busy || !form.room_ids.length"
            >Block selected rooms</button
          ></form
        >
        <form
          v-if="modal === 'payment'"
          @submit.prevent="savePayment"
          ><label
            >Amount (BDT)<input
              type="number"
              min="0.01"
              step="0.01"
              v-model="form.amount"
              required /></label
          ><label
            >Method<select v-model="form.method"
              ><option value="cash">Cash</option
              ><option value="bank_transfer">Bank transfer</option
              ><option value="other">Other</option></select
            ></label
          ><label>Transaction reference<input v-model="form.reference" /></label
          ><p class="muted small"
            >Record verified payments only. This action does not release an occupied room.</p
          ><button
            class="primary"
            :disabled="busy"
            >Post payment</button
          ></form
        >
        <form
          v-if="modal === 'staff'"
          @submit.prevent="saveStaff"
          ><label
            >Name<input
              v-model="form.name"
              required /></label
          ><label
            >Email<input
              type="email"
              v-model="form.email"
              required /></label
          ><label
            >Role<select v-model="form.role"
              ><option value="staff_admin">Staff admin — booking entry and submission</option
              ><option value="admin">Admin — approvals and financial management</option></select
            ></label
          ><label
            >{{
              form.id
                ? 'New password (leave blank to keep existing)'
                : 'Password (minimum 12 characters)'
            }}<input
              type="password"
              v-model="form.password"
              minlength="12"
              :required="!form.id"
              autocomplete="new-password" /></label
          ><label class="check-label"
            ><input
              type="checkbox"
              v-model="form.active"
            />Account active</label
          ><button
            class="primary"
            :disabled="busy"
            >Save staff account</button
          ></form
        >
        <form
          v-if="modal === 'action'"
          @submit.prevent="performAction"
          ><p
            >Perform <strong>{{ pretty(form.action) }}</strong> for {{ selected?.number }}?</p
          ><label v-if="form.needs_reason"
            >Reason<input
              v-model="form.reason"
              :required="form.action !== 'check-out'" /></label
          ><label v-if="form.action === 'approve'"
            >Convert existing reservation ID (optional)<input
              type="number"
              min="1"
              v-model="form.reservation_id" /></label
          ><p
            v-if="form.action === 'check-out'"
            class="small muted"
            >An outstanding balance requires a reason and remains collectible after checkout.</p
          ><button
            class="primary"
            :disabled="busy"
            >Confirm {{ pretty(form.action) }}</button
          ></form
        >
        <form
          v-if="modal === 'release'"
          @submit.prevent="releaseBlock"
          ><label
            >Reason for release<input
              v-model="form.reason"
              required /></label
          ><button
            class="primary"
            :disabled="busy"
            >Release rooms</button
          ></form
        >
        <form
          v-if="modal === 'credit'"
          @submit.prevent="issueCredit"
          ><label
            >Credit amount (BDT)<input
              type="number"
              min="0.01"
              step="0.01"
              v-model="form.amount"
              required /></label
          ><label
            >Reason<input
              v-model="form.reason"
              required /></label
          ><p class="small muted"
            >A credit reduces issued charges without changing the original invoice or recording a
            cash refund.</p
          ><button
            class="primary"
            :disabled="busy"
            >Issue credit note</button
          ></form
        ><form
          v-if="modal === 'finance-action'"
          @submit.prevent="financeAction"
          ><label v-if="form.action === 'refunds'"
            >Refund amount (BDT)<input
              type="number"
              min="0.01"
              step="0.01"
              v-model="form.amount"
              required /></label
          ><label
            >Reason<input
              v-model="form.reason"
              required /></label
          ><button
            class="primary"
            :disabled="busy"
            >Record {{ form.action === 'refunds' ? 'refund' : 'reversal' }}</button
          ></form
        >
        <form
          v-if="modal === 'clone'"
          @submit.prevent="cloneTariff"
          ><label
            >Version name<input
              v-model="form.name"
              required /></label
          ><label
            >Official reference<input
              v-model="form.reference"
              required /></label
          ><button
            class="primary"
            :disabled="busy"
            >Create draft tariff</button
          ></form
        >
        <form
          v-if="modal === 'rate'"
          @submit.prevent="saveRate"
          ><label
            >VAT-inclusive rate (BDT)<input
              type="number"
              min="0"
              step="0.01"
              v-model="form.amount"
              required /></label
          ><button
            class="primary"
            :disabled="busy"
            >Save draft rate</button
          ></form
        >
      </section></div
    >
  </div>
</template>
