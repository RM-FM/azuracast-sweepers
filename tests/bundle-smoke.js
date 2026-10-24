import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import { parseHTML } from "linkedom";

const { window, document } = parseHTML('<html><body><div id="app" data-base="/sweepers/1" data-csrf="test"></div></body></html>');
window.location = new URL("https://radio.example/sweepers/1");
window.localStorage = { getItem: () => "dark" };
const fetch = async (url) => ({
    ok: true,
    json: async () => url.endsWith("/state")
        ? { station: { id: 1, name: "Test" }, settings: {}, playlists: [], jingle_playlists: [] }
        : { rows: [], limit: 100 },
});

vm.runInNewContext(readFileSync(new URL("../dist/app.js", import.meta.url), "utf8"), {
    window, document, Element: window.Element, SVGElement: window.SVGElement,
    Node: window.Node, fetch, setTimeout, clearTimeout,
});
await new Promise((resolve) => setTimeout(resolve, 30));
assert.match(document.body.textContent, /Sweeper settings/);
assert.match(document.body.textContent, /Test/);
assert.equal(document.documentElement.getAttribute("data-bs-theme"), "dark");
console.log("Browser bundle mounted without Node globals.");
