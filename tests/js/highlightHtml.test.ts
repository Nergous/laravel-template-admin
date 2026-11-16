// HTML source highlighting for the HTML mode of HtmlEditor.
// Run: node --test "tests/js/*.test.ts" (Node 23.6+ strips the TypeScript types itself).
import { test } from "node:test";
import assert from "node:assert/strict";
import {
    HIGHLIGHT_LIMIT,
    highlightHtml,
} from "../../resources/js/admin/highlightHtml.ts";

// What the colored layer shows as text: spans removed, escapes decoded.
function shownText(markup: string): string {
    return markup
        .replace(/<\/?span[^>]*>/g, "")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&amp;/g, "&");
}

function kinds(markup: string): [string, string][] {
    return [...markup.matchAll(/<span class="hl-(\w)">([^<]*)<\/span>/g)].map(
        (m) => [m[1], shownText(m[2])],
    );
}

const SAMPLES = [
    "",
    "plain text & more",
    '<p class="lead">Привет, <b>мир</b>&nbsp;&#8212; &amp;</p>\n',
    "<img src=/a.png alt='x' data-x = \"1\" hidden/>",
    "<!-- note --><!DOCTYPE html><?xml?>",
    "<script>if (a < b && c > d) {}</script><style>a>b{}</style>",
    '<a href="unterminated',
    "<div <span>broken",
    "1 < 2 > 0 </ > <",
    '<p title="a\'b" =x "stray>t</p>',
    "<!-- open comment",
    "<p>\ttab\r\nCRLF</p>\n\n",
];

test("the layer shows the source character for character", () => {
    for (const src of SAMPLES) {
        assert.equal(shownText(highlightHtml(src)), src, JSON.stringify(src));
    }
});

test("tags, attributes, values and entities get their own colors", () => {
    assert.deepEqual(kinds(highlightHtml('<a href="/x" download>&amp;</a>')), [
        ["p", "<"],
        ["t", "a"],
        ["a", "href"],
        ["p", "="],
        ["v", '"/x"'],
        ["a", "download"],
        ["p", ">"],
        ["e", "&amp;"],
        ["p", "</"],
        ["t", "a"],
        ["p", ">"],
    ]);
});

test("script and style content stays plain text", () => {
    const out = highlightHtml("<script>x = '<b>' && y;</script>");
    assert.deepEqual(
        kinds(out)
            .filter(([kind]) => kind === "t")
            .map(([, text]) => text),
        ["script", "script"],
    );
    assert.equal(
        kinds(out).some(([kind]) => kind === "e"),
        false,
    );
});

test("comments and doctype are one token each", () => {
    assert.deepEqual(kinds(highlightHtml("<!-- <b>x</b> --><!doctype html>")), [
        ["c", "<!-- <b>x</b> -->"],
        ["d", "<!doctype html>"],
    ]);
});

test("very large sources are shown without colors", () => {
    const src = "<b>x</b>".repeat(HIGHLIGHT_LIMIT / 8 + 1);
    const out = highlightHtml(src);
    assert.equal(out.includes("<span"), false);
    assert.equal(shownText(out), src);
});
