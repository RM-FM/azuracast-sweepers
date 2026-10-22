import assert from "node:assert/strict";
import test from "node:test";
import {parseHTML} from "linkedom";
import {mountNavigation, stationContext} from "../frontend/navigation.js";

function fixture(path = "/station/1", base = "") {
    const {document, window} = parseHTML(`<!doctype html><html><body>
        <div id="sidebar-submenu-media"><ul class="navdrawer-nav">
            <li><a href="${base}/station/1/files">Music Files</a></li>
        </ul></div>
    </body></html>`);
    let location = new URL(path, "https://radio.example");
    window.location = location;
    const frames = new Map();
    let nextFrame = 0;
    window.requestAnimationFrame = (callback) => {
        frames.set(++nextFrame, callback);
        return nextFrame;
    };
    window.cancelAnimationFrame = (id) => frames.delete(id);
    const stop = mountNavigation(document, window, base);
    return {
        document, window, stop,
        item: () => document.getElementById("azuracast-sweepers-menu-item"),
        async flush() {
            await new Promise((resolve) => setImmediate(resolve));
            const pending = [...frames.values()];
            frames.clear();
            pending.forEach((callback) => callback());
        },
        navigate(path) {
            location = new URL(path, location);
            window.location = location;
            window.dispatchEvent(new window.Event("popstate"));
        },
    };
}

test("station paths respect the installation prefix and reject unrelated pages", () => {
    assert.deepEqual(stationContext("/radio/station/12/files", "/radio"), {
        stationPath: "/radio/station/12", href: "/radio/sweepers/12",
    });
    for (const path of ["/public/1", "/admin", "/station/0", "/station/1x", "/station/1"]) {
        assert.equal(stationContext(path, "/radio"), null);
    }
});

test("adds one normal Media link and does not loop on its own mutations", async () => {
    const f = fixture();
    assert.equal(f.item().querySelector("a").getAttribute("href"), "/sweepers/1");
    assert.equal(f.item().textContent, "Sweepers");
    await f.flush();
    await f.flush();
    assert.equal(f.document.querySelectorAll("#azuracast-sweepers-menu-item").length, 1);
    f.stop();
    assert.equal(f.item(), null);
});

test("station switching removes stale links until the new Media menu is ready", async () => {
    const f = fixture();
    f.navigate("/station/2/files");
    await f.flush();
    assert.equal(f.item(), null);
    f.document.querySelector("a").setAttribute("href", "/station/2/files");
    await f.flush();
    assert.equal(f.item().querySelector("a").getAttribute("href"), "/sweepers/2");
    f.navigate("/dashboard");
    await f.flush();
    assert.equal(f.item(), null);
    f.stop();
});

test("Vue sidebar replacement restores the link without duplicating it", async () => {
    const f = fixture();
    const menu = f.document.querySelector(".navdrawer-nav");
    const replacement = menu.cloneNode(true);
    replacement.querySelector("#azuracast-sweepers-menu-item").remove();
    menu.replaceWith(replacement);
    await f.flush();
    assert.equal(f.item().parentElement, replacement);
    assert.equal(f.document.querySelectorAll("#azuracast-sweepers-menu-item").length, 1);
    f.stop();
});

test("waits for a delayed sidebar and omits links without the native Media permission entry", async () => {
    const f = fixture("/station/1", "/radio");
    assert.equal(f.item(), null);
    f.navigate("/radio/station/1/files");
    await f.flush();
    assert.equal(f.item().querySelector("a").getAttribute("href"), "/radio/sweepers/1");
    f.document.querySelector(".navdrawer-nav > li").remove();
    await f.flush();
    assert.equal(f.item(), null);
    f.document.querySelector("#sidebar-submenu-media").remove();
    await f.flush();
    assert.equal(f.item(), null);
    f.stop();
});

test("entering a station from the dashboard handles lazy sidebar creation", async () => {
    const f = fixture("/dashboard");
    const sidebar = f.document.querySelector("#sidebar-submenu-media");
    sidebar.remove();
    f.navigate("/station/1");
    await f.flush();
    assert.equal(f.item(), null);
    f.document.body.append(sidebar);
    await f.flush();
    assert.equal(f.item().querySelector("a").getAttribute("href"), "/sweepers/1");
    f.stop();
});
