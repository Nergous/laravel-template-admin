import { inject } from "vue";
import type { ComputedRef, InjectionKey, Ref } from "vue";
import type { MediaItem } from "@/admin/types";
import type { MediaDrag } from "@/admin/composables/useMediaDrag";
import type { MediaSelection } from "@/admin/composables/useMediaSelection";
import type { FolderEntry, ThumbFallback } from "@/lib/media";

/**
 * State and actions of the media library page shared with its grid, list
 * and selection bar (provided by Media/Index.vue).
 */
export interface MediaLibraryContext {
    selection: MediaSelection;
    drag: MediaDrag;
    thumbs: ThumbFallback;
    /** Selection drives both bulk actions, so either permission enables it. */
    canSelect: ComputedRef<boolean>;
    /** A folder visit is under way: the old list stays on screen, dimmed. */
    navigating: Ref<boolean>;
    /** The pencil opens the file drawer; without media.edit it is read-only. */
    editLabel: ComputedRef<string>;
    folderLabel: (f: FolderEntry) => string;
    openFolder: (name: string) => void;
    askRenameFolder: (name: string) => void;
    askClearFolder: (name: string) => void;
    dropOnFolder: (name: string) => void;
    openItem: (m: MediaItem) => void;
    openInfo: (m: MediaItem) => void;
    copyUrl: (m: MediaItem) => void;
    askDelete: (id: number) => void;
}

export const mediaLibraryKey: InjectionKey<MediaLibraryContext> =
    Symbol("mediaLibrary");

export function useMediaLibrary(): MediaLibraryContext {
    const context = inject(mediaLibraryKey);
    if (!context) throw new Error("Media library context is not provided");
    return context;
}

/** The eye opens the file itself: images in the lightbox, the rest in a new tab. */
export function openTitle(m: MediaItem) {
    return m.type === "image"
        ? "Открыть изображение"
        : "Открыть в новой вкладке";
}
