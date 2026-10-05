import type { Row } from "nergous-ui-vue";

/**
 * Typed access to the row of a table slot: NDataTable hands every slot a
 * plain Row, so a page declares the row shape once
 * (const userRow = tableRow<AdminUser>()) and calls userRow(row) in slots.
 */
export function tableRow<T>(): (row: Row) => T {
    return (row) => row as unknown as T;
}
