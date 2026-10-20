import type { ObjectDirective } from 'vue';
// Copy column headings into cell labels for the mobile card layout; calendars keep their grid.
function annotate(table: HTMLTableElement) {
  if (table.closest('.calendar')) return;
  table.classList.add('responsive-table');
  const labels = Array.from(table.querySelectorAll('thead th')).map(
    (th) => th.textContent?.trim() || 'Actions',
  );
  table.querySelectorAll('tbody tr').forEach((row) =>
    Array.from(row.children).forEach((cell, index) => {
      if (!(cell instanceof HTMLTableCellElement)) return;
      if (cell.colSpan > 1) {
        cell.classList.add('table-empty-cell');
        return;
      }
      cell.dataset.label = labels[index] || 'Details';
    }),
  );
}
export const vResponsiveTable: ObjectDirective<HTMLTableElement> = {
  mounted: annotate,
  updated: annotate,
};
