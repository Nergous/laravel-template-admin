// Multi-value list filters: query values, summaries and chips.
// Run: node --test "tests/js/*.test.ts" (Node 23.6+ strips the TypeScript types itself).
import { test } from "node:test";
import assert from "node:assert/strict";
import {
    chipTarget,
    joinList,
    listChips,
    listSummary,
    parseList,
    without,
} from "../../resources/js/lib/filterList.ts";

const roles = [
    { value: "admin", label: "Администратор" },
    { value: "operator", label: "Оператор" },
    { value: "__none", label: "Без роли" },
];

test("parseList reads a comma list, an array and a number the way the server does", () => {
    assert.deepEqual(parseList("admin, operator,,admin"), ["admin", "operator"]);
    assert.deepEqual(parseList(["admin", 7, null, " operator "]), ["admin", "7", "operator"]);
    assert.deepEqual(parseList(12), ["12"]);
    assert.deepEqual(parseList(undefined), []);
    assert.deepEqual(parseList({ admin: true }), []);
});

test("joinList drops an empty filter from the URL", () => {
    assert.equal(joinList(["admin", "operator"]), "admin,operator");
    assert.equal(joinList([]), undefined);
});

test("listSummary shows the placeholder, one label or the first label with a count", () => {
    assert.equal(listSummary([], roles, "Все роли"), "Все роли");
    assert.equal(listSummary(["operator"], roles, "Все роли"), "Оператор");
    assert.equal(listSummary(["admin", "operator", "__none"], roles, "Все роли"), "Администратор +2");
    assert.equal(listSummary(["ghost"], roles, "Все роли"), "ghost");
});

test("a chip removes exactly its own value", () => {
    const chips = listChips("role", "Роль", ["admin", "__none"], roles);
    assert.deepEqual(
        chips.map((chip) => chip.value),
        ["Администратор", "Без роли"],
    );

    const target = chipTarget(chips[1].key);
    assert.deepEqual(target, { name: "role", value: "__none" });
    assert.deepEqual(without(["admin", "__none"], target.value!), ["admin"]);

    // Chips of single-value filters keep their plain key.
    assert.deepEqual(chipTarget("search"), { name: "search", value: null });
});
