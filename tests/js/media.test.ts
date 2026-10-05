// Media library selection and crop/focal keyboard logic.
// Run: node --test "tests/js/*.test.ts" (Node 23.6+ strips the TypeScript types itself).
import { test } from "node:test";
import assert from "node:assert/strict";
import { nextTick, ref } from "vue";
import {
    bulkFolderBody,
    useMediaSelection,
    type MediaListFilters,
} from "../../resources/js/admin/composables/useMediaSelection.ts";
import {
    arrowDelta,
    useFocalPoint,
    useMediaCrop,
} from "../../resources/js/admin/composables/useMediaCrop.ts";

function setup(filters: MediaListFilters) {
    const page = ref({ current_page: 1 });
    const currentFilters = ref<MediaListFilters>(filters);
    const selection = useMediaSelection({
        visibleIds: () => [1, 2, 3],
        total: () => 40,
        filters: () => currentFilters.value,
        page: () => page.value,
    });
    return { page, currentFilters, selection };
}

function key(k: string, shiftKey = false) {
    return {
        key: k,
        shiftKey,
        preventDefault() {},
        stopPropagation() {},
    } as unknown as KeyboardEvent;
}

test("select all sends the list filters flat, under the index query names", () => {
    const { selection } = setup({
        search: "кот",
        type: "image",
        folder: "Баннеры/2026",
        usage: "",
    });
    selection.toggleSelect(1);
    selection.selectAllMatching();

    const payload = selection.selectionPayload();

    assert.deepEqual(payload, {
        all: true,
        search: "кот",
        type: "image",
        folder: "Баннеры/2026",
    });
    assert.equal("filter" in payload, false);
});

test("picked files are sent as ids", () => {
    const { selection } = setup({ folder: "Баннеры" });
    selection.toggleSelect(2);
    selection.toggleSelect(3);

    assert.deepEqual(selection.selectionPayload(), { ids: [2, 3] });
});

test("moving every matching file keeps the folder filter apart from the destination", () => {
    const { selection } = setup({ folder: "Баннеры" });
    selection.toggleSelect(1);
    selection.selectAllMatching();

    const body = bulkFolderBody(selection.selectionPayload(), "Архив");

    assert.equal(body.folder, "Баннеры");
    assert.equal(body.target, "Архив");
    assert.deepEqual(bulkFolderBody({ ids: [5] }, ""), {
        ids: [5],
        target: "",
    });
});

test("a new server page (page, sort or filter change) drops the picked files", async () => {
    const { page, selection } = setup({});
    selection.toggleSelect(1);
    selection.toggleSelect(2);
    assert.equal(selection.selectedCount.value, 2);

    page.value = { current_page: 2 };
    await nextTick();

    assert.equal(selection.selected.value.size, 0);
    assert.equal(selection.selectedCount.value, 0);
});

test("a filter change drops the select-all choice", async () => {
    const { currentFilters, selection } = setup({ type: "image" });
    selection.toggleSelect(1);
    selection.selectAllMatching();
    assert.equal(selection.selectedCount.value, 40);

    currentFilters.value = { type: "video" };
    await nextTick();

    assert.equal(selection.allMatching.value, false);
    assert.deepEqual(selection.selectionPayload(), { ids: [] });
});

test("arrow keys give a small step, with Shift a big one, other keys nothing", () => {
    assert.deepEqual(arrowDelta(key("ArrowRight")), { x: 0.01, y: 0 });
    assert.deepEqual(arrowDelta(key("ArrowUp", true)), { x: 0, y: -0.1 });
    assert.equal(arrowDelta(key("Enter")), null);
});

test("the crop box moves with the keyboard and stays inside the image", () => {
    const crop = useMediaCrop(() => 1);
    crop.box.value = { x: 0.1, y: 0.1, width: 0.5, height: 0.5 };

    assert.equal(crop.onBoxKey(key("ArrowLeft", true)), true);
    assert.equal(crop.box.value.x, 0);

    for (let i = 0; i < 10; i++) crop.onBoxKey(key("ArrowDown", true));
    assert.equal(crop.box.value.y, 0.5);
    assert.equal(crop.box.value.height, 0.5);

    assert.equal(crop.onBoxKey(key("Tab")), false);
});

test("the crop handle resizes with the keyboard within the image and the minimum size", () => {
    const crop = useMediaCrop(() => 1);
    crop.box.value = { x: 0.5, y: 0.5, width: 0.3, height: 0.3 };

    for (let i = 0; i < 5; i++) crop.onHandleKey(key("ArrowRight", true));
    assert.equal(crop.box.value.width, 0.5);

    for (let i = 0; i < 10; i++) crop.onHandleKey(key("ArrowUp", true));
    assert.ok(crop.box.value.height >= 0.02);
    assert.ok(crop.box.value.height < 0.03);
});

test("a fixed aspect ratio holds while resizing with the keyboard", async () => {
    // A 2:1 image cropped 1:1: the height fraction is twice the width one.
    const crop = useMediaCrop(() => 2);
    crop.box.value = { x: 0, y: 0, width: 0.2, height: 0.4 };
    crop.aspect.value = "1";
    await nextTick();

    crop.onHandleKey(key("ArrowRight"));
    assert.ok(
        Math.abs(crop.box.value.height - crop.box.value.width * 2) < 1e-9,
    );

    crop.onHandleKey(key("ArrowDown", true));
    assert.ok(
        Math.abs(crop.box.value.height - crop.box.value.width * 2) < 1e-9,
    );
    assert.ok(crop.box.value.y + crop.box.value.height <= 1 + 1e-9);
});

test("the focal point moves from the center with the keyboard and stays inside", () => {
    const focal = useFocalPoint();

    focal.onKey(key("ArrowRight", true));
    assert.deepEqual(focal.point.value, { x: 0.6, y: 0.5 });

    for (let i = 0; i < 10; i++) focal.onKey(key("ArrowUp", true));
    assert.equal(focal.point.value?.y, 0);
});
