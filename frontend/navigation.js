const itemId = "azuracast-sweepers-menu-item";
const submenuId = "sidebar-submenu-sweepers";
const views = [["settings", "Settings"], ["intros", "Intros"], ["history", "Playback Timeline"]];

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
        href: `${stationPath}?sweepers=settings`,
        links: Object.fromEntries(views.map(([view]) => [view, `${stationPath}?sweepers=${view}`])),
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

export function mountNavigation(document, window, basePath = "") {
    let stopped = false;
    let frame = null;
    let panel = null;
    let unmount = null;
    let originalContent = null;
    let originalDisplay = "";

    function removePanel() {
        unmount?.();
        unmount = null;
        panel?.remove();
        panel = null;
        if (originalContent?.isConnected) {
            originalContent.style.display = originalDisplay;
        }
        originalContent = null;
    }

    function showPanel(context, view) {
        const content = document.getElementById("content");
        if (!content?.parentElement) {
            return;
        }
        if (panel?.isConnected && originalContent === content && panel.dataset.apiBase === context.apiBase
            && panel.dataset.view === view) {
            return;
        }
        removePanel();
        originalContent = content;
        originalDisplay = content.style.display;
        content.style.display = "none";
        panel = document.createElement("div");
        panel.id = "azuracast-sweepers-panel";
        panel.className = "container";
        panel.dataset.apiBase = context.apiBase;
        panel.dataset.view = view;
        const root = document.createElement("div");
        root.id = "app";
        root.className = "sweepers-page embedded";
        root.dataset.base = context.apiBase;
        root.dataset.csrf = "";
        root.dataset.view = view;
        root.textContent = "Loading Sweepers…";
        panel.append(root);
        content.after(panel);
        const css = document.createElement("link");
        css.rel = "stylesheet";
        css.href = `${context.apiBase}/assets/app.css`;
        panel.prepend(css);
        import(`${context.apiBase}/assets/app.js`).then(({mountSweepers}) => {
            if (panel?.contains(root)) {
                unmount = mountSweepers(root);
            }
        }).catch(() => {
            root.textContent = "Unable to load Sweepers. Reload the page or check the plugin frontend build.";
            if (originalContent === content) content.style.display = originalDisplay;
        });
    }

    function refresh() {
        frame = null;
        if (stopped) {
            return;
        }
        const context = stationContext(window.location.pathname, basePath);
        const view = currentView(new URL(window.location.href).search);
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
            removePanel();
            return;
        }
        if (view) {
            showPanel(context, view);
        } else {
            removePanel();
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

    const observer = new window.MutationObserver(schedule);
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ["href"],
    });
    window.addEventListener("popstate", schedule);
    window.addEventListener("pageshow", schedule);
    refresh();

    return () => {
        stopped = true;
        observer.disconnect();
        window.removeEventListener("popstate", schedule);
        window.removeEventListener("pageshow", schedule);
        if (frame !== null) {
            window.cancelAnimationFrame(frame);
        }
        document.getElementById(itemId)?.remove();
        removePanel();
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
