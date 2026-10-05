import { ref, watch } from "vue";

/** An area of the image in fractions (0..1) of its width and height. */
export interface CropBox {
    x: number;
    y: number;
    width: number;
    height: number;
}
export interface StagePoint {
    x: number;
    y: number;
}

const clamp = (v: number, min: number, max: number) =>
    Math.min(max, Math.max(min, v));
const clamp01 = (v: number) => clamp(v, 0, 1);

/** Smallest crop side; a smaller drag falls back to the previous box. */
const MIN_SIZE = 0.02;
/** Keyboard steps in fractions of the image: arrows, and arrows with Shift. */
const KEY_STEP = 0.01;
const KEY_STEP_LARGE = 0.1;
const INITIAL_BOX: CropBox = { x: 0.1, y: 0.1, width: 0.8, height: 0.8 };

/** Pointer position over the stage in fractions of its size. */
export function stagePoint(
    stage: HTMLElement | null,
    e: PointerEvent | MouseEvent,
): StagePoint {
    const rect = stage?.getBoundingClientRect();
    if (!rect || !rect.width || !rect.height) return { x: 0, y: 0 };
    return {
        x: clamp01((e.clientX - rect.left) / rect.width),
        y: clamp01((e.clientY - rect.top) / rect.height),
    };
}

/**
 * Arrow key → offset in fractions (Shift — a bigger step); null for other
 * keys, so they keep their default behaviour.
 */
export function arrowDelta(e: KeyboardEvent): StagePoint | null {
    const step = e.shiftKey ? KEY_STEP_LARGE : KEY_STEP;
    switch (e.key) {
        case "ArrowLeft":
            return { x: -step, y: 0 };
        case "ArrowRight":
            return { x: step, y: 0 };
        case "ArrowUp":
            return { x: 0, y: -step };
        case "ArrowDown":
            return { x: 0, y: step };
        default:
            return null;
    }
}

/** "12 %" for screen reader announcements. */
export function percent(v: number) {
    return `${Math.round(v * 100)} %`;
}

export const CROP_ASPECTS = [
    { value: "free", label: "Свободно" },
    { value: "1", label: "1:1" },
    { value: "1.7778", label: "16:9" },
    { value: "1.3333", label: "4:3" },
];

/**
 * Crop area picked on the preview: drawn, moved or resized with the pointer,
 * or moved (box) and resized (corner handle) with the arrow keys.
 *
 * @param imageRatio width / height of the image in pixels
 */
export function useMediaCrop(imageRatio: () => number) {
    const stage = ref<HTMLElement | null>(null);
    const box = ref<CropBox>({ ...INITIAL_BOX });
    const aspect = ref("free");
    let drag: {
        mode: "move" | "draw" | "resize";
        start: StagePoint;
        box: CropBox;
    } | null = null;

    /** Forces the chosen aspect ratio, shrinking the box to stay inside the image. */
    function withAspect(b: CropBox): CropBox {
        if (aspect.value === "free") return b;
        // Height in fractions of the image height for the target pixel ratio.
        const factor = imageRatio() / Number(aspect.value);
        let width = b.width;
        let height = width * factor;
        if (b.y + height > 1) {
            height = 1 - b.y;
            width = height / factor;
        }
        if (b.x + width > 1) {
            width = 1 - b.x;
            height = width * factor;
        }
        return { ...b, width, height };
    }
    watch(aspect, () => {
        box.value = withAspect(box.value);
    });

    function reset() {
        aspect.value = "free";
        box.value = { ...INITIAL_BOX };
    }

    function onPointerDown(e: PointerEvent) {
        const point = stagePoint(stage.value, e);
        const role = (e.target as HTMLElement).dataset.crop;
        const mode =
            role === "handle" ? "resize" : role === "box" ? "move" : "draw";
        drag = { mode, start: point, box: { ...box.value } };
        if (mode === "draw") box.value = { ...point, width: 0, height: 0 };
        stage.value?.setPointerCapture(e.pointerId);
        e.preventDefault();
    }
    function onPointerMove(e: PointerEvent) {
        if (!drag) return;
        const point = stagePoint(stage.value, e);
        const { mode, start, box: from } = drag;
        if (mode === "move") {
            box.value = {
                ...from,
                x: clamp(from.x + point.x - start.x, 0, 1 - from.width),
                y: clamp(from.y + point.y - start.y, 0, 1 - from.height),
            };
            return;
        }
        const origin = mode === "draw" ? start : { x: from.x, y: from.y };
        box.value = withAspect({
            x: Math.min(origin.x, point.x),
            y: Math.min(origin.y, point.y),
            width: Math.abs(point.x - origin.x),
            height: Math.abs(point.y - origin.y),
        });
    }
    function onPointerUp() {
        if (!drag) return;
        const tooSmall =
            box.value.width < MIN_SIZE || box.value.height < MIN_SIZE;
        if (tooSmall) box.value = drag.box;
        drag = null;
    }

    /** Shifts the box, keeping it inside the image. */
    function moveBy(dx: number, dy: number) {
        const b = box.value;
        box.value = {
            ...b,
            x: clamp(b.x + dx, 0, 1 - b.width),
            y: clamp(b.y + dy, 0, 1 - b.height),
        };
    }
    /**
     * Grows or shrinks the box from its top-left corner. With a fixed aspect
     * ratio a vertical step changes the height and the width follows.
     */
    function resizeBy(dx: number, dy: number) {
        const b = box.value;
        let width = clamp(b.width + dx, MIN_SIZE, 1 - b.x);
        const height = clamp(b.height + dy, MIN_SIZE, 1 - b.y);
        if (aspect.value !== "free" && dy && !dx) {
            width = height / (imageRatio() / Number(aspect.value));
        }
        const next = withAspect({ ...b, width, height });
        // A fixed ratio near the image edge may not fit the minimum size.
        if (next.width < MIN_SIZE || next.height < MIN_SIZE) return;
        box.value = next;
    }

    /** Arrow keys on the box move it; returns whether the key was handled. */
    function onBoxKey(e: KeyboardEvent) {
        const d = arrowDelta(e);
        if (!d) return false;
        e.preventDefault();
        moveBy(d.x, d.y);
        return true;
    }
    /** Arrow keys on the corner handle resize the box. */
    function onHandleKey(e: KeyboardEvent) {
        const d = arrowDelta(e);
        if (!d) return false;
        e.preventDefault();
        e.stopPropagation();
        resizeBy(d.x, d.y);
        return true;
    }

    return {
        stage,
        box,
        aspect,
        reset,
        onPointerDown,
        onPointerMove,
        onPointerUp,
        moveBy,
        resizeBy,
        onBoxKey,
        onHandleKey,
    };
}

/**
 * Focal point picked with a click on the preview or moved with the arrow
 * keys (from the center when none is set yet).
 */
export function useFocalPoint() {
    const stage = ref<HTMLElement | null>(null);
    const point = ref<StagePoint | null>(null);

    function pick(e: MouseEvent) {
        point.value = stagePoint(stage.value, e);
    }
    function onKey(e: KeyboardEvent) {
        const d = arrowDelta(e);
        if (!d) return false;
        e.preventDefault();
        const from = point.value ?? { x: 0.5, y: 0.5 };
        point.value = { x: clamp01(from.x + d.x), y: clamp01(from.y + d.y) };
        return true;
    }

    return { stage, point, pick, onKey };
}
