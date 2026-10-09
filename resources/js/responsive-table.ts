import type { ObjectDirective } from "vue";
function labelCells(table: HTMLTableElement) {
    const headers = Array.from(table.querySelectorAll("thead th")).map(
        (h) => h.textContent?.trim() || "",
    );
    for (const row of Array.from(table.querySelectorAll("tbody tr"))) {
        for (const [i, cell] of Array.from(
            row.querySelectorAll("td"),
        ).entries())
            cell.dataset.label = headers[i] || "";
    }
}
export const responsiveTable: ObjectDirective<HTMLTableElement> = {
    mounted: labelCells,
    updated: labelCells,
};
