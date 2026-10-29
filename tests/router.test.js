import assert from "node:assert/strict";
import test from "node:test";
import {parseHTML} from "linkedom";
import {mountNavigation} from "../frontend/navigation.js";

const {document, window} = parseHTML('<html><body><div id="dashboard" class="vue-component"></div><div id="app"></div></body></html>');
for (const key of ["window", "document", "Element", "HTMLElement", "SVGElement", "Node"]) {
    globalThis[key] = key === "window" ? window : window[key];
}
window.location = new URL("https://radio.example/station/1");
globalThis.history = window.history = {state: null};
window.requestAnimationFrame = (callback) => setImmediate(callback);
window.cancelAnimationFrame = clearImmediate;
const {createApp, h, nextTick, onMounted} = await import("vue");
const {createMemoryHistory, createRouter, RouterView} = await import("vue-router");

test("connects to the mounted native dashboard before or after vue-ready, ignoring unrelated apps", async () => {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [{
            path: "/station/:station_id",
            component: {render: () => h(RouterView)},
            children: [{path: "", component: {render: () => h("main", {id: "content"}, "Native station content")}}],
        }],
    });
    const unrelatedApp = createApp({render: () => h("div", "Unrelated app")});
    unrelatedApp.mount("#app");
    const nativeApp = createApp({
        setup() {
            onMounted(async () => {
                await nextTick();
                document.dispatchEvent(new window.Event("vue-ready"));
            });
        },
        render: () => h(RouterView),
    });
    await router.push("/station/1");
    nativeApp.use(router);
    let stop = mountNavigation(document, window);
    try {
        assert.equal(router.hasRoute("sweepers:intros"), false);
        nativeApp.mount("#dashboard");
        await nextTick();
        await nextTick();
        assert.equal(document.getElementById("dashboard").__vue_app__, nativeApp);
        assert.equal(router.hasRoute("sweepers:intros"), true);
        assert.equal(document.getElementById("azuracast-sweepers-navigation-error"), null);
        assert.equal(document.getElementById("content").textContent, "Native station content");
        stop();
        assert.equal(router.hasRoute("sweepers:intros"), false);
        stop = mountNavigation(document, window);
        assert.equal(router.hasRoute("sweepers:intros"), true);
        assert.equal(document.getElementById("azuracast-sweepers-navigation-error"), null);
    } finally {
        stop();
        nativeApp.unmount();
        unrelatedApp.unmount();
    }
});
