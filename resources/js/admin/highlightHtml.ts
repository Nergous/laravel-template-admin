/**
 * Turns HTML source into escaped markup with token spans for the HTML mode
 * of HtmlEditor. The text of the result is exactly the input, character for
 * character: the colored layer sits under a transparent textarea and any
 * shift would move the letters away from the caret.
 *
 * Classes: hl-p punctuation, hl-t tag name, hl-a attribute name,
 * hl-v attribute value, hl-e entity, hl-c comment, hl-d doctype.
 */

/** Above this size the layer shows plain text so typing stays smooth. */
export const HIGHLIGHT_LIMIT = 300_000;

const TAG = /<(\/?)([A-Za-z][\w:.-]*)/y;
const SPACE = /\s+/y;
const NAME = /[^\s"'<>\/=]+/y;
const QUOTED = /"[^"]*"?|'[^']*'?/y;
const UNQUOTED = /[^\s>]+/y;
const ENTITY = /&(?:#\d+|#x[\da-f]+|[a-z][\da-z]*);/gi;
// Tags whose content is raw text: no tags or entities inside.
const RAW_TEXT = new Set(["script", "style"]);

function escape(text: string): string {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
}

function span(kind: string, text: string): string {
    return '<span class="hl-' + kind + '">' + escape(text) + "</span>";
}

function plain(text: string): string {
    let out = "";
    let last = 0;
    for (const match of text.matchAll(ENTITY)) {
        out += escape(text.slice(last, match.index)) + span("e", match[0]);
        last = match.index + match[0].length;
    }
    return out + escape(text.slice(last));
}

function take(re: RegExp, src: string, at: number): string {
    re.lastIndex = at;
    return re.exec(src)?.[0] ?? "";
}

export function highlightHtml(src: string): string {
    if (src.length > HIGHLIGHT_LIMIT) return escape(src);

    let out = "";
    let i = 0;
    const n = src.length;

    while (i < n) {
        const lt = src.indexOf("<", i);
        if (lt === -1) {
            out += plain(src.slice(i));
            break;
        }
        if (lt > i) out += plain(src.slice(i, lt));
        i = lt;

        if (src.startsWith("<!--", i)) {
            const close = src.indexOf("-->", i + 4);
            const stop = close === -1 ? n : close + 3;
            out += span("c", src.slice(i, stop));
            i = stop;
            continue;
        }
        if (src[i + 1] === "!" || src[i + 1] === "?") {
            const close = src.indexOf(">", i);
            const stop = close === -1 ? n : close + 1;
            out += span("d", src.slice(i, stop));
            i = stop;
            continue;
        }

        TAG.lastIndex = i;
        const tag = TAG.exec(src);
        if (!tag) {
            out += escape("<");
            i++;
            continue;
        }
        out += span("p", "<" + tag[1]) + span("t", tag[2]);
        i += tag[0].length;

        let closed = false;
        while (i < n) {
            const space = take(SPACE, src, i);
            out += space;
            i += space.length;
            if (i >= n) break;

            const ch = src[i];
            if (ch === ">" || src.startsWith("/>", i)) {
                const end = ch === ">" ? ">" : "/>";
                out += span("p", end);
                i += end.length;
                closed = true;
                break;
            }
            // An unfinished tag: the next one starts here.
            if (ch === "<") break;
            if (ch === "/") {
                out += span("p", "/");
                i++;
                continue;
            }
            if (ch === "=") {
                out += span("p", "=");
                i++;
                const gap = take(SPACE, src, i);
                out += gap;
                i += gap.length;
                const value = take(QUOTED, src, i) || take(UNQUOTED, src, i);
                if (value) {
                    out += span("v", value);
                    i += value.length;
                }
                continue;
            }
            const name = take(NAME, src, i);
            if (name) {
                out += span("a", name);
                i += name.length;
                continue;
            }
            // A stray quote.
            out += escape(ch);
            i++;
        }

        const tagName = tag[2].toLowerCase();
        if (closed && !tag[1] && RAW_TEXT.has(tagName)) {
            const end = src.toLowerCase().indexOf("</" + tagName, i);
            const stop = end === -1 ? n : end;
            out += escape(src.slice(i, stop));
            i = stop;
        }
    }

    return out;
}
