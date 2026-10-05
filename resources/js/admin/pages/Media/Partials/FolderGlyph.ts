import { h } from "vue";

/** Folder glyph: the design system has no folder icon. */
const FolderGlyph = (p: { size?: number }) =>
    h(
        "svg",
        {
            viewBox: "0 0 24 24",
            width: p.size ?? 20,
            height: p.size ?? 20,
            fill: "none",
            stroke: "currentColor",
            "stroke-width": 1.6,
            "stroke-linejoin": "round",
            "aria-hidden": "true",
        },
        [
            h("path", {
                d: "M3 7.5A1.5 1.5 0 0 1 4.5 6h4.4l2 2.2h8.6A1.5 1.5 0 0 1 21 9.7v8.8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z",
                fill: "currentColor",
                "fill-opacity": 0.14,
            }),
        ],
    );
FolderGlyph.props = ["size"];

export default FolderGlyph;
