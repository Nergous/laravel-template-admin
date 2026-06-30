// icons.js — icon registry for <NIcon>. Line icons on a 24px grid, 1.8px stroke.
// Each entry is a list of SVG path `d` strings; a few icons add primitives via CIRCLES.
// Kept separate from NIcon.vue so the data is reusable/testable and the component
// stays a thin renderer.
export const PATHS = {
    home: ["m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z", "M9 22V12h6v10"],
    users: [
        "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2",
        "M22 21v-2a4 4 0 0 0-3-3.87",
        "M16 3.13a4 4 0 0 1 0 7.75",
    ],
    user: [
        "M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8",
        "M5 21v-1a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v1",
    ],
    search: ["m21 21-4.3-4.3"],
    bell: [
        "M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9",
        "M10.3 21a1.94 1.94 0 0 0 3.4 0",
    ],
    sun: [
        "M12 4V2",
        "M12 22v-2",
        "m5 5-1.4-1.4",
        "m20.4 20.4-1.4-1.4",
        "M4 12H2",
        "M22 12h-2",
        "m5 19-1.4 1.4",
        "m20.4 3.6-1.4 1.4",
    ],
    moon: ["M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"],
    plus: ["M12 5v14", "M5 12h14"],
    minus: ["M5 12h14"],
    check: ["M5 12.5 10 17l9-10"],
    x: ["M6 6l12 12", "M18 6 6 18"],
    trash: [
        "M3 6h18",
        "M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2",
        "M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6",
    ],
    bolt: ["M13 2 4 14h7l-1 8 9-12h-7z"],
    "alert-triangle": [
        "M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z",
        "M12 9v4",
        "M12 17h.01",
    ],
    mail: ["M3 6h18v12H3z", "m3 7 9 6 9-6"],
    settings: [
        "M4 21v-6",
        "M4 11V3",
        "M12 21v-8",
        "M12 9V3",
        "M20 21v-4",
        "M20 13V3",
        "M2 15h4",
        "M10 9h4",
        "M18 17h4",
    ],
    asset: [
        "M3.5 5.5A1.5 1.5 0 0 1 5 4h14a1.5 1.5 0 0 1 1.5 1.5v13A1.5 1.5 0 0 1 19 20H5a1.5 1.5 0 0 1-1.5-1.5z",
        "m4 16 5-4 4 3 3-3 4 4",
    ],
    layers: ["m12 2 9 5-9 5-9-5z", "m3 12 9 5 9-5", "m3 17 9 5 9-5"],
    download: ["M12 3v12", "m7 11 5 5 5-5", "M5 21h14"],
    upload: ["M12 16V4", "m7 9 5-5 5 5", "M4 20h16"],
    edit: ["M12 20h9", "M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"],
    copy: ["M9 9h11v11H9z", "M5 15H4V4h11v1"],
    ext: [
        "M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6",
        "M15 3h6v6",
        "M10 14 21 3",
    ],
    grid: ["M4 4h6v6H4z", "M14 4h6v6h-6z", "M14 14h6v6h-6z", "M4 14h6v6H4z"],
    list: [
        "M8 6h13",
        "M8 12h13",
        "M8 18h13",
        "M3 6h.01",
        "M3 12h.01",
        "M3 18h.01",
    ],
    shield: ["M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"],
    lock: ["M5 11h14v10H5z", "M8 11V7a4 4 0 0 1 8 0v4"],
    eye: [
        "M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0",
    ],
    "eye-off": [
        "M10.73 5.08a10.74 10.74 0 0 1 11.2 6.57 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-1.44 2.49",
        "M14.08 14.16a3 3 0 0 1-4.24-4.24",
        "M17.48 17.5a10.75 10.75 0 0 1-15.42-5.15 1 1 0 0 1 0-.7 10.75 10.75 0 0 1 4.45-5.14",
        "M2 2l20 20",
    ],
    globe: [
        "M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18",
        "M3 12h18",
        "M12 3c2.5 2.5 2.5 15 0 18",
        "M12 3C9.5 5.5 9.5 18.5 12 21",
    ],
    calendar: ["M4 6h16v15H4z", "M4 10h16", "M8 3v4", "M16 3v4"],
    filter: ["M3 5h18l-7 8v6l-4-2v-4z"],
    heart: [
        "M12 21C5 14 2 9 5.5 5.5a4.5 4.5 0 0 1 6.5 0 4.5 4.5 0 0 1 6.5 0C22 9 19 14 12 21z",
    ],
    "chevron-down": ["m6 9 6 6 6-6"],
    "chevron-right": ["m9 5 7 7-7 7"],
    "chevron-left": ["m15 5-7 7 7 7"],
    "arrow-up": ["m6 14 6-6 6 6"],
    "arrow-down": ["m6 10 6 6 6-6"],
    activity: ["M22 12h-4l-3 9L9 3l-3 9H2"],
    "log-out": [
        "M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9",
    ],
    // rich-text toolbar
    bold: ["M6 4h8a4 4 0 0 1 0 8H6z", "M6 12h9a4 4 0 0 1 0 8H6z"],
    italic: ["M19 4h-9", "M14 20H5", "m15 4-6 16"],
    strikethrough: [
        "M16 4H9a3 3 0 0 0-2.83 4",
        "M14 12a4 4 0 0 1 0 8H6",
        "M4 12h16",
    ],
    "list-ordered": [
        "M10 6h11",
        "M10 12h11",
        "M10 18h11",
        "M4 6h1v4",
        "M4 10h2",
        "M6 18H4c0-1 2-2 2-3s-1-1.5-2-1",
    ],
    link: [
        "M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71",
        "M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71",
    ],
    quote: ["M4 6v12", "M9 7h11", "M9 12h11", "M9 17h7"],
    code: ["m16 18 6-6-6-6", "m8 6-6 6 6 6"],
    eraser: [
        "M4 7V4h16v3",
        "M5 20h6",
        "m13 4-5 16",
        "m15 15 5 5",
        "m20 15-5 5",
    ],
};

// Extra non-path primitives keyed by icon name (rendered as <circle>).
export const CIRCLES = {
    search: { cx: 11, cy: 11, r: 7 },
    asset: { cx: 8.5, cy: 9.5, r: 1.3 },
    eye: { cx: 12, cy: 12, r: 3 },
};

// All known icon names — handy for validation, pickers, or docs.
export const ICON_NAMES = Object.keys(PATHS);
