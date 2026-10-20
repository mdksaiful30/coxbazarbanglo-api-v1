import { computed, onBeforeUnmount, ref, watch } from 'vue';
type ThemePreference = 'light' | 'dark' | 'system';
/** Keep the selected theme in browser storage and resolve system appearance changes. */
export function useTheme() {
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  const read = (): ThemePreference => {
    try {
      const value = localStorage.getItem('resthouse-theme');
      return value === 'dark' || value === 'light' ? value : 'system';
    } catch {
      return 'system';
    }
  };
  const preference = ref<ThemePreference>(read()),
    systemDark = ref(media.matches);
  const dark = computed(
    () => preference.value === 'dark' || (preference.value === 'system' && systemDark.value),
  );
  const updateSystem = (event: MediaQueryListEvent) => {
    systemDark.value = event.matches;
  };
  const sync = (event: StorageEvent) => {
    if (event.key === 'resthouse-theme' || event.key === null) preference.value = read();
  };
  media.addEventListener('change', updateSystem);
  window.addEventListener('storage', sync);
  watch(
    [preference, dark],
    () => {
      const theme = dark.value ? 'dark' : 'light';
      document.documentElement.dataset.theme = theme;
      document.documentElement.style.colorScheme = theme;
      try {
        if (localStorage.getItem('resthouse-theme') !== preference.value)
          localStorage.setItem('resthouse-theme', preference.value);
      } catch {
        /* Theme remains usable when browser storage is unavailable. */
      }
    },
    { immediate: true },
  );
  onBeforeUnmount(() => {
    media.removeEventListener('change', updateSystem);
    window.removeEventListener('storage', sync);
  });
  const toggle = () => {
    preference.value = dark.value ? 'light' : 'dark';
  };
  return { preference, dark, toggle };
}
