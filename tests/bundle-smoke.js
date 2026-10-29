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
        ? { station: { id: 1, name: "Test" }, settings: {
            enabled: false, playlist_ids: [], jingle_playlist_ids: [], excluded_playlist_ids: [],
        }, playlists: [], jingle_playlists: [] }
        : { rows: [], total: 0, page: 1, per_page: 25 },
});

const bundle = readFileSync(new URL("../dist/app.js", import.meta.url), "utf8");
const exported = bundle.match(/([$\w]+) as mountSweepers/);
assert.ok(exported);
const routeComponent = bundle.match(/([$\w]+) as SweepersPage/);
assert.ok(routeComponent);
const executable = bundle.replace(/export\s*\{[\s\S]*\};?\s*$/, "");
vm.runInNewContext(executable, {
    window, document, Element: window.Element, SVGElement: window.SVGElement,
    Node: window.Node, fetch, setTimeout, clearTimeout,
});
await new Promise((resolve) => setTimeout(resolve, 30));
assert.match(document.body.textContent, /Sweeper Settings/);
assert.match(document.body.textContent, /Enable sweepers/);
assert.equal(document.documentElement.getAttribute("data-bs-theme"), "dark");

const embedded = parseHTML('<html><body><div id="app" class="sweepers-page embedded" data-base="/sweepers/1" data-csrf="test"></div></body></html>');
const sandbox = vm.createContext({
    window: embedded.window, document: embedded.document,
    Element: embedded.window.Element, SVGElement: embedded.window.SVGElement,
    Node: embedded.window.Node, fetch, setTimeout, clearTimeout,
});
embedded.window.location = new URL("https://radio.example/station/1?sweepers=1");
vm.runInContext(`${executable}\nglobalThis.mountSweepers = ${exported[1]};
globalThis.SweepersPage = ${routeComponent[1]};`, sandbox);
assert.equal(embedded.document.body.textContent, "");
assert.equal(embedded.document.documentElement.getAttribute("data-bs-theme"), null);
const unmount = sandbox.mountSweepers(embedded.document.getElementById("app"));
await new Promise((resolve) => setTimeout(resolve, 30));
assert.match(embedded.document.body.textContent, /Sweeper Settings/);
unmount();

for (const key of ["window", "document", "Element", "HTMLElement", "SVGElement", "Node"]) {
    globalThis[key] = key === "window" ? embedded.window : embedded.window[key];
}
const {createApp, h, nextTick} = await import("vue");
const host = embedded.document.createElement("div");
embedded.document.body.append(host);
const requests = [];
sandbox.fetch = (url) => {
    requests.push(url);
    return fetch(url);
};
const nativeApp = createApp({
    data: () => ({base: "/sweepers/1", view: "settings"}),
    render() {
        return h(sandbox.SweepersPage, {base: this.base, view: this.view});
    },
});
const page = nativeApp.mount(host);
await new Promise((resolve) => setTimeout(resolve, 30));
assert.match(host.textContent, /Sweeper Settings/);
assert.equal(host.querySelectorAll(".sweepers-page.embedded").length, 1);
assert.equal(host.querySelector("#app"), null, "The native app keeps its own root.");
page.view = "intros";
page.base = "/sweepers/2";
await nextTick();
await new Promise((resolve) => setTimeout(resolve, 30));
assert.match(host.textContent, /Intro Manager/);
assert.doesNotMatch(host.textContent, /Sweeper Settings/);
assert.equal(requests.filter((url) => url === "/sweepers/2/state").length, 1);
nativeApp.unmount();
assert.equal(host.textContent, "", "The native lifecycle disposes the plugin app.");
console.log("Standalone bundle and native Vue route lifecycle mounted without Node globals.");
