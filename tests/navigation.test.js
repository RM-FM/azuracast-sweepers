import assert from "node:assert/strict";
import test from "node:test";
import {parseHTML} from "linkedom";
import {currentView, mountNavigation, registerRoutes, stationContext} from "../frontend/navigation.js";
import {createMemoryHistory, createRouter} from "vue-router";

function fixture(path = "/station/1", base = "", ready = Promise.resolve()) {
    const {document, window} = parseHTML(`<!doctype html><html><body>
        <ul class="navdrawer-nav" id="station-menu"><li class="nav-item" id="media-item">
        <a class="nav-link" href="#sidebar-submenu-media">Media</a>
        <div id="sidebar-submenu-media" class="collapse"><ul class="navdrawer-nav">
            <li><a href="${base}/station/1/files">Music Files</a></li>
        </ul></div></li><li class="nav-item" id="next-item">Next</li></ul>
        <div id="app"><main id="main"><div id="content">Native station content</div></main></div>
        <script id="azuracast-sweepers-navigation"></script>
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
    const hooks = new Set();
    const errors = new Set();
    const routes = [];
    const pushes = [];
    const replacements = [];
    function updateRoute(path) {
        location = new URL(path, location);
        window.location = location;
        router.currentRoute.value = {
            path: location.pathname.slice(base.length),
            fullPath: location.pathname.slice(base.length) + location.search + location.hash,
            params: {station_id: location.pathname.match(/\/station\/(\d+)/)?.[1]},
            query: Object.fromEntries(location.searchParams),
            hash: location.hash,
        };
        hooks.forEach((hook) => hook(router.currentRoute.value));
        return Promise.resolve();
    }
    const router = {
        currentRoute: {value: null},
        isReady: () => ready,
        getRoutes: () => [{
            path: "/station/:station_id", children: [{path: ""}],
            components: {default: {name: "StationLayout"}},
        }],
        addRoute(route) {
            routes.push(route);
            return () => routes.splice(routes.indexOf(route), 1);
        },
        afterEach(hook) {
            hooks.add(hook);
            return () => hooks.delete(hook);
        },
        onError(hook) {
            errors.add(hook);
            return () => errors.delete(hook);
        },
        replace(target) {
            replacements.push(target);
            if (typeof target === "string") return updateRoute(base + target);
            const query = new URLSearchParams(target.query).toString();
            return updateRoute(base + target.path + (query ? `?${query}` : "") + target.hash);
        },
        push(target) {
            pushes.push(target);
            return updateRoute(base + target);
        },
    };
    updateRoute(path);
    document.getElementById("app").__vue_app__ = {config: {globalProperties: {$router: router}}};
    const stop = mountNavigation(document, window, base);
    return {
        document, window, stop, router, routes, pushes, replacements, hooks, errors,
        item: () => document.getElementById("azuracast-sweepers-menu-item"),
        page: (view) => document.querySelector(`#sidebar-submenu-sweepers a[href$="/sweepers/${view}"]`),
        async flush() {
            await new Promise((resolve) => setImmediate(resolve));
            const pending = [...frames.values()];
            frames.clear();
            pending.forEach((callback) => callback());
        },
        navigate(path) {
            updateRoute(path);
        },
    };
}

test("station paths respect the installation prefix and reject unrelated pages", () => {
    assert.deepEqual(stationContext("/radio/station/12/files", "/radio"), {
        stationPath: "/radio/station/12", href: "/radio/station/12/sweepers/settings",
        links: {
            settings: "/radio/station/12/sweepers/settings",
            intros: "/radio/station/12/sweepers/intros",
            history: "/radio/station/12/sweepers/history",
        },
        apiBase: "/radio/sweepers/12",
    });
    for (const path of ["/public/1", "/admin", "/station/0", "/station/1x", "/station/1"]) {
        assert.equal(stationContext(path, "/radio"), null);
    }
    assert.equal(currentView("?sweepers=1"), "settings", "Former single-page links open the settings");
    assert.equal(currentView("?sweepers=history"), "history");
    assert.equal(currentView("?sweepers=other"), null);
    assert.equal(currentView(""), null);
});

test("adds one top-level Sweepers group after Media and does not loop on its own mutations", async () => {
    const f = fixture();
    const group = f.item().querySelector("a");
    assert.equal(group.getAttribute("href"), "#sidebar-submenu-sweepers");
    assert.equal(group.getAttribute("data-bs-toggle"), "collapse");
    assert.equal(group.getAttribute("aria-expanded"), "false");
    assert.equal(group.textContent, "Sweepers");
    const submenu = f.document.getElementById("sidebar-submenu-sweepers");
    assert.equal(submenu.parentElement, f.item());
    assert.equal(submenu.className, "collapse pb-2");
    assert.deepEqual([...submenu.querySelectorAll("a")].map((a) => [a.textContent, a.getAttribute("href"), a.className]), [
        ["Settings", "/station/1/sweepers/settings", "nav-link ps-4 py-2"],
        ["Intro Manager", "/station/1/sweepers/intros", "nav-link ps-4 py-2"],
        ["Playback Timeline", "/station/1/sweepers/history", "nav-link ps-4 py-2"],
    ]);
    assert.equal(f.item().parentElement.id, "station-menu");
    assert.equal(f.item().previousElementSibling.id, "media-item");
    assert.equal(f.item().nextElementSibling.id, "next-item");
    assert.equal(f.item().closest(".collapse"), null);
    assert.equal(group.className, "nav-link");
    assert.equal(f.item().querySelector("a").getAttribute("target"), null);
    assert.equal(f.item().querySelector("svg").getAttribute("aria-hidden"), "true");
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
    f.document.querySelector("#sidebar-submenu-media a").setAttribute("href", "/station/2/files");
    await f.flush();
    assert.equal(f.page("settings").getAttribute("href"), "/station/2/sweepers/settings");
    assert.equal(f.item().querySelector("a").getAttribute("target"), null);
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
    const f = fixture("/radio/dashboard", "/radio");
    assert.equal(f.item(), null);
    f.navigate("/radio/station/1/files");
    await f.flush();
    assert.equal(f.page("history").getAttribute("href"), "/radio/station/1/sweepers/history");
    f.document.querySelector("#sidebar-submenu-media li").remove();
    await f.flush();
    assert.equal(f.item(), null);
    f.document.querySelector("#sidebar-submenu-media").remove();
    await f.flush();
    assert.equal(f.item(), null);
    f.stop();
});

test("entering a station from the dashboard handles lazy sidebar creation", async () => {
    const f = fixture("/dashboard");
    const sidebar = f.document.querySelector("#station-menu");
    sidebar.remove();
    f.navigate("/station/1");
    await f.flush();
    assert.equal(f.item(), null);
    f.document.body.append(sidebar);
    await f.flush();
    assert.equal(f.page("intros").getAttribute("href"), "/station/1/sweepers/intros");
    f.stop();
});


test("replacing only the Media submenu retains one top-level link", async () => {
    const f = fixture();
    const menu = f.document.querySelector("#sidebar-submenu-media > ul");
    menu.replaceWith(menu.cloneNode(true));
    await f.flush();
    assert.equal(f.item().parentElement.id, "station-menu");
    assert.equal(f.document.querySelectorAll("#azuracast-sweepers-menu-item").length, 1);
    f.stop();
});

test("does not insert a link into an unexpected sidebar structure", async () => {
    const f = fixture();
    const menu = f.document.querySelector("#sidebar-submenu-media");
    f.document.body.append(menu);
    await f.flush();
    assert.equal(f.item(), null);
    f.stop();
});


test("legacy station links redirect without hiding or replacing native content", async () => {
    const f = fixture("/station/1?sweepers=1");
    await f.flush();
    assert.equal(f.window.location.pathname, "/station/1/sweepers/settings");
    assert.equal(f.document.getElementById("azuracast-sweepers-panel"), null);
    assert.equal(f.document.getElementById("content").textContent, "Native station content");
    assert.equal(f.document.getElementById("content").style.display ?? "", "");
    assert.equal(f.document.getElementById("station-menu") !== null, true);
    f.navigate("/station/1/files");
    await f.flush();
    assert.equal(f.document.getElementById("azuracast-sweepers-panel"), null);
    assert.equal(f.document.getElementById("content").style.display ?? "", "");
    f.stop();
});

test("each sub-page opens its view, expands the group and marks the current entry", async () => {
    const f = fixture("/station/1/sweepers/intros/");
    assert.equal(f.item().querySelector("a").className, "nav-link active");
    assert.equal(f.item().querySelector("a").getAttribute("aria-expanded"), "true");
    assert.equal(f.document.getElementById("sidebar-submenu-sweepers").className, "collapse pb-2 show");
    assert.equal(f.page("intros").className, "nav-link ps-4 py-2 active");
    assert.equal(f.page("intros").getAttribute("aria-current"), "page");
    assert.equal(f.page("history").className, "nav-link ps-4 py-2");
    f.navigate("/station/1/sweepers/history");
    await f.flush();
    assert.equal(f.page("history").className, "nav-link ps-4 py-2 active");
    assert.equal(f.page("intros").className, "nav-link ps-4 py-2");
    assert.equal(f.document.querySelectorAll("#azuracast-sweepers-panel").length, 0);
    assert.equal(f.document.querySelectorAll("#azuracast-sweepers-menu-item").length, 1);
    // A user's own open/close (Bootstrap toggles the classes) is kept while the page stays the same.
    f.document.getElementById("sidebar-submenu-sweepers").className = "collapse pb-2";
    await f.flush();
    assert.equal(f.document.getElementById("sidebar-submenu-sweepers").className, "collapse pb-2");
    f.stop();
});

test("normal sidebar clicks use the native router while modified clicks remain browser-owned", async () => {
    const f = fixture("/radio/station/1", "/radio");
    for (const modifier of ["ctrlKey", "metaKey", "shiftKey", "altKey"]) {
        const event = new f.window.Event("click", {bubbles: true, cancelable: true});
        event[modifier] = true;
        f.page("intros").dispatchEvent(event);
        assert.equal(event.defaultPrevented, false);
    }
    assert.deepEqual(f.pushes, []);
    const event = new f.window.Event("click", {bubbles: true, cancelable: true});
    f.page("intros").querySelector("span").dispatchEvent(event);
    await f.flush();
    assert.equal(event.defaultPrevented, true);
    assert.deepEqual(f.pushes, ["/station/1/sweepers/intros"]);
    assert.equal(f.page("intros").getAttribute("aria-current"), "page");
    f.stop();
    assert.equal(f.routes.length, 0);
    assert.equal(f.hooks.size, 0);
    assert.equal(f.errors.size, 0);
});

test("legacy redirects retain other query parameters and the hash", async () => {
    const f = fixture("/radio/station/1?sweepers=history&filter=played#timeline", "/radio");
    await f.flush();
    assert.equal(f.window.location.pathname, "/radio/station/1/sweepers/history");
    assert.equal(f.window.location.search, "?filter=played");
    assert.equal(f.window.location.hash, "#timeline");
    f.stop();
});

test("waits for the initial native navigation instead of replacing its pending route", async () => {
    let finish;
    const f = fixture("/station/1/sweepers/history", "", new Promise((resolve) => { finish = resolve; }));
    f.router.currentRoute.value = {path: "/", fullPath: "/", query: {}};
    await f.flush();
    assert.deepEqual(f.replacements, []);
    f.navigate("/station/1/sweepers/history");
    finish();
    await f.flush();
    assert.deepEqual(f.replacements, ["/station/1/sweepers/history"]);
    f.stop();
});

test("an unavailable native router produces a visible compatibility error and cleans up", () => {
    const f = fixture();
    f.stop();
    delete f.document.getElementById("app").__vue_app__;
    const errors = [];
    const original = f.window.console.error;
    f.window.console.error = (...args) => errors.push(args);
    const stop = mountNavigation(f.document, f.window);
    try {
        f.document.dispatchEvent(new f.window.Event("vue-ready"));
        const alert = f.document.getElementById("azuracast-sweepers-navigation-error");
        assert.equal(alert.getAttribute("role"), "alert");
        assert.match(alert.textContent, /AzuraCast compatibility/);
        assert.equal(errors.length, 1);
        assert.equal(f.document.getElementById("content").style.display ?? "", "");
    } finally {
        stop();
        f.window.console.error = original;
    }
    assert.equal(f.document.getElementById("azuracast-sweepers-navigation-error"), null);
});

test("registers lazy station pages with the native layout and station-scoped props", async () => {
    const layout = {render: () => null};
    let loads = 0;
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [{
            path: "/station/:station_id",
            component: layout,
            children: [{path: "", name: "stations:index", component: {render: () => null}}],
        }],
    });
    const remove = registerRoutes(router, "/radio", async () => {
        loads++;
        return {render: () => null};
    });
    for (const view of ["settings", "intros", "history"]) {
        await router.push(`/station/12/sweepers/${view}`);
        const route = router.currentRoute.value;
        assert.equal(route.name, `sweepers:${view}`);
        assert.equal(route.matched[0].components.default, layout);
        assert.deepEqual(route.matched[1].props.default(route), {base: "/radio/sweepers/12", view});
    }
    assert.equal(loads, 3);
    await router.push("/station/12");
    assert.equal(router.currentRoute.value.name, "stations:index");
    remove();
    assert.equal(router.hasRoute("sweepers:settings"), false);
    assert.equal(router.hasRoute("stations:index"), true);
    assert.throws(() => registerRoutes({getRoutes: () => []}, "", () => {}), /station layout/);
});
