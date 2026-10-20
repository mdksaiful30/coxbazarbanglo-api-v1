<script setup lang="ts">
  import { computed, ref, watch, onBeforeUnmount } from 'vue';
  import axios from 'axios';
  import AppIcon from './AppIcon.vue';
  // Guest-safe confirmation data comes from the server; internal booking notes are excluded.
  type Confirmation = Record<string, any>;
  const props = defineProps<{ bookingId: number; bookingVersion: number }>();
  const data = ref<Confirmation | null>(null),
    exportRoot = ref<HTMLElement | null>(null),
    busy = ref(false),
    menu = ref(false),
    error = ref(''),
    message = ref(''),
    prepared = ref<File | null>(null);
  const downloadUrl = ref(''),
    downloadName = ref('');
  const amount = (value: number = 0) =>
    'BDT ' +
    new Intl.NumberFormat('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
      Number(value) / 100,
    );
  const human = (value: string = '') => value.replaceAll('_', ' ');
  const stamp = (value: string | null) =>
    value ? value.replace('T', ' ').replace(/\.\d+Z$/, ' UTC') : '—';
  const filename = computed(
    () =>
      `Booking-${(data.value?.number || props.bookingId).toString().replace(/[^a-zA-Z0-9_-]/g, '-')}`,
  );
  // Build the same guest-facing summary for WhatsApp and email composers.
  const summary = computed(() => {
    const d = data.value;
    if (!d) return '';
    return [
      d.property.name,
      'BOOKING DETAILS',
      `Booking: ${d.number}`,
      `Status: ${human(d.status)}${d.provisional ? ' (provisional)' : ''}`,
      `Guest: ${d.guest.name}`,
      d.guest.designation ? `Designation: ${d.guest.designation}` : '',
      d.guest.organization ? `Organization: ${d.guest.organization}` : '',
      `Arrival: ${stamp(d.check_in_at)} (Dhaka)`,
      `Departure: ${stamp(d.check_out_at)} (Dhaka)`,
      `Rooms: ${d.rooms.map((r: Confirmation) => `${r.code} — ${r.name}`).join(', ')}`,
      `Guests: ${d.guest_count}`,
      d.reference_letter ? `Reference: ${d.reference_letter}` : '',
      `Charges: ${amount(d.finance.charges_minor)}`,
      `Net paid: ${amount(d.finance.paid_minor - d.finance.refunded_minor)}`,
      `Balance: ${amount(d.finance.balance_minor)}`,
      d.provisional
        ? `Provisional quote: ${amount(d.quote_total_minor)}. This is not an approved reservation.`
        : '',
      'All quoted room tariffs include VAT.',
    ]
      .filter(Boolean)
      .join('\n');
  });
  const whatsappUrl = computed(() => {
    const phone = (data.value?.guest.mobile || '').replace(/\D/g, '');
    return phone ? `https://wa.me/${phone}?text=${encodeURIComponent(summary.value)}` : '';
  });
  const emailUrl = computed(() =>
    data.value?.guest.email
      ? `mailto:${encodeURIComponent(data.value.guest.email)}?subject=${encodeURIComponent('Rest-house booking ' + data.value.number)}&body=${encodeURIComponent(summary.value)}`
      : '',
  );
  watch(
    () => [props.bookingId, props.bookingVersion],
    () => {
      data.value = null;
      prepared.value = null;
      menu.value = false;
      error.value = '';
      message.value = '';
      if (downloadUrl.value) {
        URL.revokeObjectURL(downloadUrl.value);
        urls.delete(downloadUrl.value);
      }
      downloadUrl.value = '';
      downloadName.value = '';
    },
  );
  async function load() {
    data.value = (
      await axios.get(`/api/v1/bookings/${props.bookingId}/guest-confirmation`)
    ).data.data;
    await import('vue').then((v) => v.nextTick());
  }
  async function attempt(fn: () => Promise<void>) {
    busy.value = true;
    error.value = '';
    message.value = '';
    try {
      await fn();
    } catch (e: any) {
      if (e.name !== 'AbortError')
        error.value =
          e.response?.data?.message || e.message || 'Unable to prepare booking details.';
    } finally {
      busy.value = false;
    }
  }
  async function toggleMenu() {
    if (menu.value) {
      menu.value = false;
      return;
    }
    menu.value = true;
    await attempt(load);
  }
  // Render the hidden booking document after data and fonts finish loading.
  async function capture() {
    await load();
    await document.fonts.ready;
    const { default: html2canvas } = await import('html2canvas');
    if (!exportRoot.value) throw new Error('Booking document is not ready.');
    return html2canvas(exportRoot.value, {
      scale: 2,
      backgroundColor: '#ffffff',
      logging: false,
      useCORS: true,
      windowWidth: 1200,
      scrollX: 0,
      scrollY: 0,
    });
  }
  // Split the captured document across A4 pages while avoiding cuts through text.
  async function pdfFile(): Promise<File> {
    const canvas = await capture();
    const { jsPDF } = await import('jspdf');
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4', compress: true });
    const width = 190,
      pagePixels = Math.floor((canvas.width * 277) / width);
    let offset = 0,
      page = 0;
    while (offset < canvas.height) {
      let height = Math.min(pagePixels, canvas.height - offset);
      // Prefer a blank horizontal row near a page boundary so text is not cut in half.
      if (offset + height < canvas.height) {
        const ctx = canvas.getContext('2d')!;
        for (let y = height; y > height - 160 && y > 100; y--) {
          const pixels = ctx.getImageData(0, offset + y - 1, canvas.width, 1).data;
          let clear = true;
          for (let x = 0; x < pixels.length; x += 16) {
            if (pixels[x] < 245 || pixels[x + 1] < 245 || pixels[x + 2] < 245) {
              clear = false;
              break;
            }
          }
          if (clear) {
            height = y;
            break;
          }
        }
      }
      const slice = document.createElement('canvas');
      slice.width = canvas.width;
      slice.height = height;
      slice
        .getContext('2d')!
        .drawImage(canvas, 0, offset, canvas.width, height, 0, 0, canvas.width, height);
      if (page++) pdf.addPage();
      pdf.addImage(
        slice.toDataURL('image/jpeg', 0.96),
        'JPEG',
        10,
        10,
        width,
        (height * width) / canvas.width,
      );
      offset += height;
    }
    pdf.setProperties({
      title: 'Booking ' + data.value!.number,
      subject: 'Guest booking details',
      author: data.value!.property.name,
    });
    return new File([pdf.output('blob')], filename.value + '.pdf', { type: 'application/pdf' });
  }
  async function jpgFile(): Promise<File> {
    const canvas = await capture();
    const blob = await new Promise<Blob>((resolve, reject) =>
      canvas.toBlob(
        (value) => (value ? resolve(value) : reject(new Error('Image export failed.'))),
        'image/jpeg',
        0.96,
      ),
    );
    return new File([blob], filename.value + '.jpg', { type: 'image/jpeg' });
  }
  // Track generated object URLs so downloads can be cleaned up when replaced or unmounted.
  const urls = new Set<string>();
  function download(file: File) {
    if (downloadUrl.value) {
      URL.revokeObjectURL(downloadUrl.value);
      urls.delete(downloadUrl.value);
    }
    const url = URL.createObjectURL(file);
    downloadUrl.value = url;
    downloadName.value = file.name;
    urls.add(url);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = file.name;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
  }
  onBeforeUnmount(() => urls.forEach((url) => URL.revokeObjectURL(url)));
  async function exportFile(format: 'pdf' | 'jpg') {
    await attempt(async () => {
      download(await (format === 'pdf' ? pdfFile() : jpgFile()));
      message.value = `${format.toUpperCase()} download prepared.`;
    });
  }
  // Native sharing requires a separate user gesture after the PDF is prepared.
  async function prepareAttachment() {
    await attempt(async () => {
      prepared.value = await pdfFile();
      if (!navigator.canShare?.({ files: [prepared.value] })) {
        download(prepared.value);
        message.value = 'PDF downloaded. Attach it in WhatsApp or your email composer.';
        prepared.value = null;
      } else
        message.value =
          'Attachment ready. Choose “Share prepared PDF” to select WhatsApp, email or another available app.';
    });
  }
  async function sharePrepared() {
    if (!prepared.value) return;
    await attempt(async () => {
      await navigator.share({
        files: [prepared.value!],
        title: 'Booking ' + data.value!.number,
        text: 'Rest-house booking details',
      });
      message.value = 'File handed to your selected sharing app.';
    });
  }
</script>

<template>
  <div class="booking-sharing">
    <div
      class="booking-export-actions"
      aria-label="Download or share booking details"
    >
      <button
        class="export-button pdf-button"
        :disabled="busy"
        @click="exportFile('pdf')"
        ><AppIcon name="Download" /> PDF</button
      >
      <button
        class="export-button jpg-button"
        :disabled="busy"
        @click="exportFile('jpg')"
        ><AppIcon name="Download" /> JPG</button
      >
      <button
        class="export-button share-button"
        :disabled="busy"
        :aria-expanded="menu"
        @click="toggleMenu"
        ><AppIcon name="WhatsApp" /> Share</button
      >
    </div>
    <a
      v-if="downloadUrl"
      class="prepared-download"
      :href="downloadUrl"
      :download="downloadName"
      >Download prepared {{ downloadName.endsWith('.pdf') ? 'PDF' : 'JPG' }}</a
    ><p
      v-if="busy"
      class="export-feedback"
      role="status"
      >Preparing booking details…</p
    >
    <p
      v-if="error"
      class="export-error"
      role="alert"
      >{{ error }}</p
    ><p
      v-if="message"
      class="export-feedback"
      role="status"
      >{{ message }}</p
    >
    <div
      v-if="menu"
      class="booking-share-menu"
    >
      <strong>Share with the guest</strong>
      <a
        v-if="whatsappUrl"
        :href="whatsappUrl"
        target="_blank"
        rel="noopener noreferrer"
        ><AppIcon name="WhatsApp" /> WhatsApp guest</a
      ><span
        v-else
        class="share-unavailable"
        >A guest mobile number is required for WhatsApp.</span
      >
      <a
        v-if="emailUrl"
        :href="emailUrl"
        ><AppIcon name="Email" /> Email guest</a
      ><span
        v-else
        class="share-unavailable"
        >Add the guest's email address to enable email sharing.</span
      >
      <button
        v-if="!prepared"
        :disabled="busy"
        @click="prepareAttachment"
        ><AppIcon name="Download" /> Prepare PDF attachment</button
      ><button
        v-else
        :disabled="busy"
        @click="sharePrepared"
        ><AppIcon name="Share" /> Share prepared PDF</button
      >
      <small
        >WhatsApp and email open a message for you to review and send. To include a file, use the
        attachment option or attach the downloaded PDF/JPG.</small
      >
    </div>
    <div
      v-if="data"
      class="booking-export-offscreen"
      aria-hidden="true"
    >
      <article
        ref="exportRoot"
        class="guest-booking-document"
      >
        <header
          ><div class="guest-doc-kicker">BIWTA · COX'S BAZAR</div><h1>{{ data.property.name }}</h1
          ><p>{{ data.property.address }}</p></header
        >
        <div class="guest-doc-heading"
          ><div
            ><small>BOOKING DETAILS</small><h2>{{ data.number }}</h2></div
          ><span class="guest-doc-status">{{ human(data.status) }}</span></div
        >
        <p
          v-if="data.provisional || ['draft', 'pending_approval', 'rejected'].includes(data.status)"
          class="guest-doc-warning"
          >This is a {{ human(data.status) }} booking record. It is not a confirmed reservation.</p
        >
        <p
          v-if="['cancelled', 'no_show'].includes(data.status)"
          class="guest-doc-warning"
          >This booking is {{ human(data.status) }} and does not authorize room occupancy.</p
        >
        <section
          ><h3>Guest information</h3
          ><table
            ><tbody
              ><tr
                ><th>Name</th><td>{{ data.guest.name }}</td></tr
              ><tr
                ><th>Designation</th><td>{{ data.guest.designation || '—' }}</td></tr
              ><tr
                ><th>Organization</th><td>{{ data.guest.organization || '—' }}</td></tr
              ><tr
                ><th>Mobile / email</th
                ><td
                  >{{ data.guest.mobile || '—' }}<br v-if="data.guest.email" />{{
                    data.guest.email
                  }}</td
                ></tr
              ><tr
                ><th>Guest category</th><td>{{ data.category }}</td></tr
              ></tbody
            ></table
          ></section
        >
        <section
          ><h3>Stay information · Asia/Dhaka</h3
          ><table
            ><tbody
              ><tr
                ><th>Check-in</th><td>{{ stamp(data.check_in_at) }}</td></tr
              ><tr
                ><th>Checkout</th><td>{{ stamp(data.check_out_at) }}</td></tr
              ><tr
                ><th>Number of guests</th><td>{{ data.guest_count }}</td></tr
              ><tr
                ><th>Reference letter</th
                ><td
                  >{{ data.reference_letter || '—'
                  }}<span v-if="data.reference_date"> · {{ data.reference_date }}</span></td
                ></tr
              ></tbody
            ></table
          ></section
        >
        <section
          ><h3>Allocated rooms</h3
          ><table
            ><thead
              ><tr><th>Room number</th><th>Room name</th><th>Floor / type</th></tr></thead
            ><tbody
              ><tr
                v-for="room in data.rooms"
                :key="room.code"
                ><td>{{ room.code }}</td
                ><td>{{ room.name }}</td
                ><td>{{ room.floor }} · {{ room.ac_type === 'ac' ? 'AC' : 'Non-AC' }}</td></tr
              ></tbody
            ></table
          ></section
        >
        <section
          ><h3>Payment summary</h3
          ><table
            ><tbody
              ><tr v-if="data.provisional"
                ><th>Provisional quoted total</th><td>{{ amount(data.quote_total_minor) }}</td></tr
              ><tr
                ><th>Charges after credits</th><td>{{ amount(data.finance.charges_minor) }}</td></tr
              ><tr
                ><th>Net paid after refunds</th
                ><td>{{ amount(data.finance.paid_minor - data.finance.refunded_minor) }}</td></tr
              ><tr
                ><th>Balance</th
                ><td
                  ><strong>{{ amount(data.finance.balance_minor) }}</strong></td
                ></tr
              ><tr
                ><th>Payment status</th><td>{{ human(data.finance.payment_status) }}</td></tr
              ></tbody
            ></table
          ><p class="guest-doc-note"
            >Room tariff amounts include VAT. A negative balance represents an overpayment. This
            booking summary is not a separate payment receipt.</p
          ></section
        >
        <footer
          ><span
            >Generated: {{ stamp(data.generated_at) }} · Booking revision {{ data.version }}</span
          ><span>Please contact the rest-house office for assistance.</span></footer
        >
      </article>
    </div>
  </div>
</template>

<style scoped>
  @reference "../../css/app.css";
  @layer components {
    .prepared-download {
      @apply inline-block text-[11px] underline m-[0_0_9px];
    }
    .booking-sharing {
      @apply m-[0_0_20px];
    }
    .booking-export-actions {
      @apply flex justify-end gap-[10px] p-[12px_0];
    }
    .export-button {
      @apply inline-flex gap-[8px] items-center rounded-[15px] p-[12px_15px] text-[14px] font-medium;
    }
    .export-button .app-icon {
      @apply w-[17px] h-[17px];
    }
    .pdf-button {
      @apply text-[#cf1019] border-[#ffb6ba] [background:#fff4f4];
    }
    .jpg-button {
      @apply text-[#0074b3] border-[#9cdbfb] [background:#effaff];
    }
    .share-button {
      @apply text-[#008765] border-[#90eac8] [background:#edfff6];
    }
    .export-button:hover {
      @apply filter-[brightness(0.97)];
    }
    .booking-share-menu {
      @apply flex flex-col gap-[9px] [background:#f8fbf9] [border:1px_solid_#dce8df] rounded-[10px] p-[18px];
    }
    .booking-share-menu > strong {
      @apply text-[13px] mb-[4px];
    }
    .booking-share-menu a,
    .booking-share-menu button {
      @apply flex items-center gap-[10px] text-left text-[13px] [border:1px_solid_#dfebe3] [background:white] rounded-[7px] p-[11px];
    }
    .booking-share-menu small,
    .share-unavailable {
      @apply text-[11px] leading-[1.6] text-[#718678];
    }
    .export-feedback {
      @apply text-[11px] text-[#397c63] leading-normal m-[5px_0];
    }
    .export-error {
      @apply text-[12px] text-[#ad3939] [background:#fff0ed] p-[10px] rounded-[6px];
    }
    .booking-export-offscreen {
      @apply fixed left-[-12000px] top-0 pointer-events-none;
    }
    .guest-booking-document {
      @apply w-[794px] p-[36px] [background:#fff] text-[#243e36] font-['Noto_Sans_Bengali','Nirmala_UI',Arial,sans-serif] text-[13px] leading-[1.6] wrap-anywhere;
    }
    .guest-booking-document header {
      @apply [border-bottom:3px_solid_#126b61] pb-[17px] mb-[20px];
    }
    .guest-doc-kicker {
      @apply text-[11px] tracking-[2px] text-[#568675];
    }
    .guest-booking-document h1 {
      @apply text-[24px] leading-[1.4] m-[7px_0];
    }
    .guest-booking-document p {
      @apply m-[5px_0] text-[#697e74] text-[12px];
    }
    .guest-doc-heading {
      @apply flex items-center justify-between mb-[18px];
    }
    .guest-doc-heading small {
      @apply text-[10px] tracking-[1.3px] text-[#72897e];
    }
    .guest-doc-heading h2 {
      @apply text-[22px] m-[4px_0];
    }
    .guest-doc-status {
      @apply capitalize p-[7px_13px] [border:1px_solid_#cfe5d8] rounded-[6px] text-[#186d52] [background:#edf7ef];
    }
    .guest-booking-document section {
      @apply mb-[18px];
    }
    .guest-booking-document h3 {
      @apply text-[14px] text-[#126b61] m-[0_0_10px];
    }
    .guest-booking-document table {
      @apply w-full border-collapse table-fixed text-[12px];
    }
    .guest-booking-document th,
    .guest-booking-document td {
      @apply p-[8px_12px] [border:1px_solid_#e3ebe6] text-left align-top whitespace-normal;
    }
    .guest-booking-document th {
      @apply w-[33%] [background:#f4f8f5] text-[11px] text-[#617a6d] tracking-normal;
    }
    .guest-booking-document footer {
      @apply flex flex-col gap-[4px] [border-top:1px_solid_#dee8e2] p-[17px_0_0] text-[10px] text-[#789083];
    }
    .guest-doc-warning {
      @apply p-[12px] [background:#fff7df] text-[#8c722d]! mb-[18px]!;
    }
    .guest-doc-note {
      @apply text-[10px]! pt-[8px];
    }
    @media (max-width: 760px) {
      .booking-export-actions {
        @apply justify-start gap-[8px];
      }
      .export-button {
        @apply p-[10px_13px];
      }
    }
    @media print {
      .booking-sharing {
        @apply hidden;
      }
    }
  }
</style>
