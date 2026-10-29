const itemId = "azuracast-sweepers-menu-item";
const submenuId = "sidebar-submenu-sweepers";
const views = [["settings", "Settings"], ["intros", "Intro Manager"], ["history", "Playback Timeline"]];

export function stationContext(pathname, basePath) {
    const prefix = `${basePath}/station/`;
    if (!pathname.startsWith(prefix)) {
        return null;
    }
    const match = pathname.slice(prefix.length).match(/^([1-9]\d*)(?:\/|$)/);
    if (!match) {
        return null;
    }
    const stationPath = `${prefix}${match[1]}`;

    return {
        stationPath,
        href: `${stationPath}/sweepers/settings`,
        links: Object.fromEntries(views.map(([view]) => [view, `${stationPath}/sweepers/${view}`])),
        apiBase: `${basePath}/sweepers/${match[1]}`,
    };
}

// "1" is the former single-page link.
export function currentView(search) {
    const value = new URLSearchParams(search).get("sweepers");
    if (value === "1") {
        return "settings";
    }

    return views.some(([view]) => view === value) ? value : null;
}

export function registerRoutes(router, basePath, loadPage) {
    const station = router.getRoutes().find((route) =>
        route.path === "/station/:station_id" && route.children.some((child) => child.path === ""));
    if (!station?.components?.default) {
        throw new Error("AzuraCast's station layout route is unavailable.");
    }

    return router.addRoute({
        path: "/station/:station_id/sweepers",
        component: station.components.default,
        children: views.map(([view]) => ({
            path: view,
            name: `sweepers:${view}`,
            component: loadPage,
            props: (route) => ({
                base: `${basePath}/sweepers/${route.params.station_id}`,
                view,
            }),
        })),
    });
}

function loadStyles(document, href) {
    return new Promise((resolve, reject) => {
        const link = document.createElement("link");
        link.rel = "stylesheet";
        link.href = href;
        link.onload = resolve;
        link.onerror = () => {
            link.remove();
            reject(new Error("Unable to load the Sweepers stylesheet."));
        };
        document.head.append(link);
    });
}

export function mountNavigation(document, window, basePath = "") {
    let stopped = false;
    let frame = null;
    let router = null;
    let removeRoutes = null;
    let removeAfterEach = null;
    let removeOnError = null;
    let pageModule = null;
    let stylesheet = null;
    let connectionFailed = false;
    let lastError = null;

    function reportError(error) {
        if (stopped || error === lastError) return;
        lastError = error;
        window.console.error("AzuraCast Sweepers:", error);
        let alert = document.getElementById("azuracast-sweepers-navigation-error");
        if (!alert) {
            alert = document.createElement("div");
            alert.id = "azuracast-sweepers-navigation-error";
            alert.className = "alert alert-danger";
            alert.setAttribute("role", "alert");
            (document.getElementById("content") || document.getElementById("dashboard") || document.body).prepend(alert);
        }
        alert.textContent = "Unable to load Sweepers. Reload the page or check the plugin frontend build and AzuraCast compatibility.";
    }

    function navigate(target, replace = false) {
        lastError = null;
        document.getElementById("azuracast-sweepers-navigation-error")?.remove();
        router[replace ? "replace" : "push"](target).catch(reportError);
    }

    function redirectLegacy(route) {
        const view = currentView(new URL(route.fullPath, window.location.origin).search);
        if (view && /^\/station\/[1-9]\d*\/?$/.test(route.path)) {
            const query = {...route.query};
            delete query.sweepers;
            navigate({path: `${route.path.replace(/\/$/, "")}/sweepers/${view}`, query, hash: route.hash}, true);
            return true;
        }
        return false;
    }

    function connectRouter() {
        if (router || connectionFailed) return;
        const nativeRouter = document.getElementById("dashboard")?.__vue_app__?.config.globalProperties.$router;
        if (!nativeRouter) return;
        try {
            removeRoutes = registerRoutes(nativeRouter, basePath, () => {
                if (!pageModule) {
                    const assets = `${basePath}/sweepers/assets`;
                    stylesheet ??= loadStyles(document, `${assets}/app.css`).catch((error) => {
                        stylesheet = null;
                        throw error;
                    });
                    pageModule = Promise.all([
                        import(`${assets}/app.js`),
                        stylesheet,
                    ]).then(([module]) => module.SweepersPage).catch((error) => {
                        pageModule = null;
                        throw error;
                    });
                }
                return pageModule;
            });
            router = nativeRouter;
            removeAfterEach = router.afterEach((to, from, failure) => {
                if (!failure) redirectLegacy(to);
                schedule();
            });
            removeOnError = router.onError((error, to) => {
                if (/^\/station\/[1-9]\d*\/sweepers\//.test(to.path)) reportError(error);
            });
            router.isReady().then(() => {
                if (stopped) return;
                const route = router.currentRoute.value;
                if (!redirectLegacy(route)
                    && /^\/station\/[1-9]\d*\/sweepers\/(?:settings|intros|history)\/?$/.test(route.path)
                    && !route.name?.startsWith("sweepers:")) {
                    // Direct links may have resolved before the plugin registered its routes.
                    navigate(route.fullPath, true);
                }
                schedule();
            }).catch(reportError);
        } catch (error) {
            connectionFailed = true;
            reportError(error);
        }
    }

    function refresh() {
        frame = null;
        if (stopped) {
            return;
        }
        connectRouter();
        const context = stationContext(window.location.pathname, basePath);
        const pathname = window.location.pathname.replace(/\/$/, "");
        const view = context && views.find(([key]) =>
            pathname === `${context.stationPath}/sweepers/${key}`)?.[0];
        const menu = document.querySelector("#sidebar-submenu-media > .navdrawer-nav");
        const mediaItem = menu?.parentElement.closest("li.nav-item");
        const stationMenu = mediaItem?.parentElement;
        const existing = document.getElementById(itemId);
        const hasMediaAccess = context && menu && [...menu.querySelectorAll("a[href]")].some((link) => {
            const url = new URL(link.href, window.location.href);
            return url.origin === window.location.origin && url.pathname === `${context.stationPath}/files`;
        });
        if (!hasMediaAccess || !stationMenu?.matches("ul.navdrawer-nav")) {
            existing?.remove();
            return;
        }
        // Rebuilding would reset the user's own open/close of the group.
        const signature = `${context.stationPath}|${view ?? ""}`;
        if (existing?.parentElement === stationMenu && existing.previousElementSibling === mediaItem
            && existing.dataset.signature === signature) {
            return;
        }
        existing?.remove();
        const item = document.createElement("li");
        item.id = itemId;
        item.className = "nav-item";
        item.dataset.signature = signature;
        const link = document.createElement("a");
        link.className = view ? "nav-link active" : "nav-link";
        link.setAttribute("href", `#${submenuId}`);
        link.setAttribute("data-bs-toggle", "collapse");
        link.setAttribute("role", "button");
        link.setAttribute("aria-controls", submenuId);
        link.setAttribute("aria-expanded", view ? "true" : "false");
        const icon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        icon.setAttribute("class", "navdrawer-nav-icon");
        icon.setAttribute("viewBox", "0 0 24 24");
        icon.setAttribute("width", "1em");
        icon.setAttribute("height", "1em");
        icon.setAttribute("aria-hidden", "true");
        icon.setAttribute("focusable", "false");
        const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
        path.setAttribute("fill", "currentColor");
        path.setAttribute("d", "M4 9h4v12H4zm6-6h4v18h-4zm6 10h4v8h-4z");
        icon.append(path);
        const label = document.createElement("span");
        label.className = "might-overflow";
        label.textContent = "Sweepers";
        link.append(icon, label);
        const submenu = document.createElement("div");
        submenu.id = submenuId;
        submenu.className = view ? "collapse pb-2 show" : "collapse pb-2";
        const list = document.createElement("ul");
        list.className = "navdrawer-nav";
        for (const [key, text] of views) {
            const entry = document.createElement("li");
            entry.className = "nav-item";
            const anchor = document.createElement("a");
            anchor.className = key === view ? "nav-link ps-4 py-2 active" : "nav-link ps-4 py-2";
            anchor.setAttribute("href", context.links[key]);
            if (key === view) {
                anchor.setAttribute("aria-current", "page");
            }
            const caption = document.createElement("span");
            caption.className = "might-overflow";
            caption.textContent = text;
            anchor.append(caption);
            entry.append(anchor);
            list.append(entry);
        }
        submenu.append(list);
        item.append(link, submenu);
        mediaItem.after(item);
    }

    function schedule() {
        if (!stopped && frame === null) {
            frame = window.requestAnimationFrame(refresh);
        }
    }

    function onClick(event) {
        const anchor = event.target.closest?.(`#${itemId} a[href]`);
        if (!router || !anchor || event.defaultPrevented || event.button > 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || anchor.hasAttribute("download") || (anchor.target && anchor.target !== "_self")
            || anchor.getAttribute("href").startsWith("#")) {
            return;
        }
        const url = new URL(anchor.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        event.preventDefault();
        navigate(`${url.pathname.slice(basePath.length)}${url.search}${url.hash}`);
    }

    function onReady() {
        connectRouter();
        if (!router && !connectionFailed) {
            connectionFailed = true;
            reportError(new Error("AzuraCast's Vue Router is unavailable."));
        }
        schedule();
    }

    const observer = new window.MutationObserver(schedule);
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ["href"],
    });
    document.addEventListener("click", onClick);
    document.addEventListener("vue-ready", onReady);
    window.addEventListener("pageshow", schedule);
    refresh();

    return () => {
        stopped = true;
        observer.disconnect();
        document.removeEventListener("click", onClick);
        document.removeEventListener("vue-ready", onReady);
        window.removeEventListener("pageshow", schedule);
        if (frame !== null) {
            window.cancelAnimationFrame(frame);
        }
        document.getElementById(itemId)?.remove();
        document.getElementById("azuracast-sweepers-navigation-error")?.remove();
        removeAfterEach?.();
        removeOnError?.();
        removeRoutes?.();
    };
}

if (typeof window !== "undefined" && typeof document !== "undefined") {
    const path = new URL(import.meta.url).pathname;
    const suffix = "/sweepers/assets/navigation.js";
    if (path.endsWith(suffix)) {
        window.azuracastSweepersNavigation?.();
        window.azuracastSweepersNavigation = mountNavigation(document, window, path.slice(0, -suffix.length));
    }
}
