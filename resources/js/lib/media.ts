import { ref } from "vue";
import { useToast } from "nergous-ui-vue";
import type { MediaItem } from "@/admin/types";
import { pluralize } from "@/lib/format";

/** Human-readable media type names. */
export const TYPE_LABEL: Record<string, string> = {
    image: "Фото",
    video: "Видео",
    audio: "Аудио",
    document: "Документ",
    other: "Файл",
};

/** Glyphs for files without a usable thumbnail (design-system icons only). */
export const TYPE_ICON: Record<string, string> = {
    image: "asset",
    video: "layers",
    audio: "activity",
    document: "copy",
    other: "asset",
};

type Typed = Pick<MediaItem, "type">;
type Named = Pick<MediaItem, "original_name" | "filename">;

export function typeLabel(m: Typed) {
    return TYPE_LABEL[m.type] || TYPE_LABEL.other;
}

export function typeIcon(m: Typed) {
    return TYPE_ICON[m.type] || TYPE_ICON.other;
}

/** Short file-type badge: the filename extension, else the MIME subtype. */
export function typeBadge(
    m: Typed & Partial<Named> & Pick<MediaItem, "mime_type">,
) {
    const name = m.original_name || m.filename || "";
    const ext = name.includes(".") ? name.split(".").pop() : "";
    if (ext) return ext.toUpperCase().slice(0, 5);
    const sub = (m.mime_type || "").split("/")[1] || m.type || "";
    return sub.toUpperCase().slice(0, 5);
}

/** Display name: the uploaded name, else the stored filename. */
export function fileName(m: Named) {
    return m.original_name || m.filename;
}

/** Keeps the focal point in view when a card crops the thumbnail. */
export function focalStyle(m: Pick<MediaItem, "focal_x" | "focal_y">) {
    if (m.focal_x == null || m.focal_y == null) return undefined;
    return {
        objectPosition: `${Math.round(m.focal_x * 100)}% ${Math.round(m.focal_y * 100)}%`,
    };
}

/** Public URL of a file as an absolute link (for copying and display). */
export function absoluteUrl(url: string) {
    return new URL(url, window.location.origin).toString();
}

/**
 * Thumbnails that failed to load: such files fall back to the type glyph.
 * The Set is reassigned so Vue notices the change.
 */
export function useThumbFallback() {
    const broken = ref(new Set<number>());

    function onImgError(id: number) {
        const next = new Set(broken.value);
        next.add(id);
        broken.value = next;
    }

    function showThumb(m: Pick<MediaItem, "id" | "type" | "thumb_url">) {
        return m.type === "image" && !!m.thumb_url && !broken.value.has(m.id);
    }

    /** Gives a replaced file's new thumbnail another chance. */
    function forget(id: number) {
        if (!broken.value.has(id)) return;
        const next = new Set(broken.value);
        next.delete(id);
        broken.value = next;
    }

    function reset() {
        broken.value = new Set();
    }

    return { onImgError, showThumb, forget, reset };
}

export type ThumbFallback = ReturnType<typeof useThumbFallback>;

/** Copies a file's absolute URL to the clipboard and reports the outcome. */
export function useCopyUrl() {
    const toast = useToast();
    return async function copyUrl(m: Named & Pick<MediaItem, "url">) {
        try {
            await navigator.clipboard.writeText(absoluteUrl(m.url));
            toast.success("Ссылка скопирована", fileName(m));
        } catch {
            toast.error(
                "Не удалось скопировать",
                "Браузер запретил доступ к буферу обмена.",
            );
        }
    };
}

/**
 * A library folder. name is the full path ("Баннеры/2026"); count and
 * subfolders describe what lies directly inside, total and size the subtree.
 */
export interface FolderEntry {
    name: string;
    count: number;
    subfolders: number;
    total: number;
    size: number;
    created_at: string | null;
    created_local: string | null;
}

/** "A/B/C" → "A/B"; a top-level folder's parent is the root (""). */
export function parentOf(path: string) {
    const i = path.lastIndexOf("/");
    return i === -1 ? "" : path.slice(0, i);
}

/** "A/B/C" → "C". */
export function baseName(path: string) {
    return path.slice(path.lastIndexOf("/") + 1);
}

/** "2 папки · 5 файлов": what lies directly inside a folder. */
export function folderSummary(f: FolderEntry) {
    const parts = [];
    if (f.subfolders)
        parts.push(
            `${f.subfolders} ${pluralize(f.subfolders, "папка", "папки", "папок")}`,
        );
    if (f.count || !f.subfolders)
        parts.push(
            `${f.count} ${pluralize(f.count, "файл", "файла", "файлов")}`,
        );
    return parts.join(" · ");
}
