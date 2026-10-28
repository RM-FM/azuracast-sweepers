import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import { parseHTML } from "linkedom";

const { window, document } = parseHTML('<html><body><div id="app" class="sweepers-page" data-base="/sweepers/1" data-csrf="test"></div></body></html>');
window.location = new URL("https://radio.example/sweepers/1");
window.localStorage = { getItem: () => "dark" };
const fetch = async (url) => ({
    ok: true,
    json: async () => url.endsWith("/state")
        ? { station: { id: 1, name: "Test" }, settings: {}, playlists: [], jingle_playlists: [] }
        : { rows: [], total: 0, page: 1, limit: 25 },
});

const bundle = readFileSync(new URL("../dist/app.js", import.meta.url), "utf8");
const exported = bundle.match(/(\w+) as mountSweepers/);
assert.ok(exported);
const executable = bundle.replace(/export\s*\{[\s\S]*\};?\s*$/, "");
vm.runInNewContext(executable, {
    window, document, Element: window.Element, SVGElement: window.SVGElement,
    Node: window.Node, fetch, setTimeout, clearTimeout,
});
await new Promise((resolve) => setTimeout(resolve, 30));
assert.match(document.body.textContent, /Sweeper Settings/);
assert.match(document.body.textContent, /Test/);
assert.equal(document.documentElement.getAttribute("data-bs-theme"), "dark");

const embedded = parseHTML('<html><body><div id="app" class="sweepers-page embedded" data-base="/sweepers/1" data-csrf="test"></div></body></html>');
const sandbox = vm.createContext({
    window: embedded.window, document: embedded.document,
    Element: embedded.window.Element, SVGElement: embedded.window.SVGElement,
    Node: embedded.window.Node, fetch, setTimeout, clearTimeout,
});
embedded.window.location = new URL("https://radio.example/station/1?sweepers=1");
vm.runInContext(`${executable}\nglobalThis.mountSweepers = ${exported[1]};`, sandbox);
assert.equal(embedded.document.body.textContent, "");
assert.equal(embedded.document.documentElement.getAttribute("data-bs-theme"), null);
const unmount = sandbox.mountSweepers(embedded.document.getElementById("app"));
await new Promise((resolve) => setTimeout(resolve, 30));
assert.match(embedded.document.body.textContent, /Sweeper Settings/);
unmount();
console.log("Standalone and station panel bundles mounted without Node globals.");
