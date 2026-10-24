const itemId = "azuracast-sweepers-menu-item";

export function stationContext(pathname, basePath) {
    const prefix = `${basePath}/station/`;
    if (!pathname.startsWith(prefix)) {
        return null;
    }
    const match = pathname.slice(prefix.length).match(/^([1-9]\d*)(?:\/|$)/);
    return match ? {
        stationPath: `${prefix}${match[1]}`,
        href: `${prefix}${match[1]}?sweepers=1`,
        apiBase: `${basePath}/sweepers/${match[1]}`,
    } : null;
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

    function showPanel(context) {
        const content = document.getElementById("content");
        if (!content?.parentElement) {
            return;
        }
        if (panel?.isConnected && originalContent === content && panel.dataset.apiBase === context.apiBase) {
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
        const root = document.createElement("div");
        root.id = "app";
        root.className = "sweepers-page embedded";
        root.dataset.base = context.apiBase;
        root.dataset.csrf = "";
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
        const embedded = new URL(window.location.href).searchParams.get("sweepers") === "1";
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
        if (embedded) {
            showPanel(context);
        } else {
            removePanel();
        }
        if (existing?.parentElement === stationMenu && existing.previousElementSibling === mediaItem
            && existing.querySelector("a").getAttribute("href") === context.href) {
            return;
        }
        existing?.remove();
        const item = document.createElement("li");
        item.id = itemId;
        item.className = "nav-item";
        const link = document.createElement("a");
        link.className = "nav-link";
        link.setAttribute("href", context.href);
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
        item.append(link);
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
