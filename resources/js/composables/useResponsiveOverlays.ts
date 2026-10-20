import { nextTick, onBeforeUnmount, onMounted, watch, type Ref } from 'vue';
/** Manage modal/drawer focus, Escape handling and the mobile viewport height. */
export function useResponsiveOverlays(
  modal: Ref<string>,
  selected: Ref<unknown>,
  menu: Ref<boolean>,
  busy: Ref<boolean>,
) {
  let active: HTMLElement | null = null,
    returnFocus: HTMLElement | null = null;
  const focusable = (element: HTMLElement) =>
    Array.from(
      element.querySelectorAll<HTMLElement>(
        'button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]',
      ),
    ).filter((e) => e.getClientRects().length > 0);
  const updateHeight = () =>
    document.documentElement.style.setProperty(
      '--app-viewport-height',
      `${window.visualViewport?.height || window.innerHeight}px`,
    );
  const wide = window.matchMedia('(min-width:1025px)');
  const resize = () => {
    updateHeight();
    if (wide.matches) menu.value = false;
  };
  // Keep keyboard navigation inside the topmost overlay.
  const keyboard = (event: KeyboardEvent) => {
    if (!active) return;
    if (event.key === 'Escape') {
      if (busy.value) return;
      event.preventDefault();
      if (modal.value) modal.value = '';
      else if (selected.value) selected.value = null;
      else menu.value = false;
      return;
    }
    if (event.key !== 'Tab') return;
    const items = focusable(active);
    if (!items.length) {
      event.preventDefault();
      active.focus();
      return;
    }
    const first = items[0],
      last = items[items.length - 1];
    if (
      event.shiftKey &&
      (document.activeElement === first || !active.contains(document.activeElement))
    ) {
      event.preventDefault();
      last.focus();
    } else if (
      !event.shiftKey &&
      (document.activeElement === last || !active.contains(document.activeElement))
    ) {
      event.preventDefault();
      first.focus();
    }
  };
  // Wait for Vue to render the new overlay before moving focus or disabling the background.
  watch(
    [modal, selected, menu],
    async () => {
      const wasOpen = !!active;
      const hadFocus =
        document.activeElement instanceof HTMLElement ? document.activeElement : null;
      await nextTick();
      const current = document.querySelector<HTMLElement>(
        modal.value
          ? '.modal'
          : selected.value
            ? '.drawer'
            : menu.value
              ? '.sidebar'
              : '.no-overlay',
      );
      if (current && !wasOpen) returnFocus = hadFocus;
      document.querySelector<HTMLElement>('.workspace')?.toggleAttribute('inert', !!current);
      document
        .querySelector<HTMLElement>('.sidebar')
        ?.toggleAttribute('inert', !!current && !menu.value);
      document
        .querySelector<HTMLElement>('.drawer-backdrop')
        ?.toggleAttribute('inert', !!modal.value);
      document.body.classList.toggle('overlay-open', !!current);
      active = current;
      if (current) {
        current.setAttribute('tabindex', '-1');
        (
          focusable(current).find((e) => e.getAttribute('aria-label')?.startsWith('Close')) ||
          focusable(current)[0] ||
          current
        ).focus({ preventScroll: true });
      } else if (wasOpen) {
        returnFocus?.focus({ preventScroll: true });
        returnFocus = null;
      }
    },
    { flush: 'post' },
  );
  onMounted(() => {
    updateHeight();
    window.visualViewport?.addEventListener('resize', updateHeight);
    window.addEventListener('resize', resize);
    document.addEventListener('keydown', keyboard);
  });
  onBeforeUnmount(() => {
    window.visualViewport?.removeEventListener('resize', updateHeight);
    window.removeEventListener('resize', resize);
    document.removeEventListener('keydown', keyboard);
    document.body.classList.remove('overlay-open');
    document.querySelectorAll('[inert]').forEach((e) => e.removeAttribute('inert'));
  });
}
