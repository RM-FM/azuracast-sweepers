const itemId = "azuracast-sweepers-menu-item";

export function stationContext(pathname, basePath) {
    const prefix = `${basePath}/station/`;
    if (!pathname.startsWith(prefix)) {
        return null;
    }
    const match = pathname.slice(prefix.length).match(/^([1-9]\d*)(?:\/|$)/);
    return match ? {
        stationPath: `${prefix}${match[1]}`,
        href: `${basePath}/sweepers/${match[1]}`,
    } : null;
}

export function mountNavigation(document, window, basePath = "") {
    let stopped = false;
    let frame = null;

    function refresh() {
        frame = null;
        if (stopped) {
            return;
        }
        const context = stationContext(window.location.pathname, basePath);
        const menu = document.querySelector("#sidebar-submenu-media > .navdrawer-nav");
        const existing = document.getElementById(itemId);
        const hasMediaAccess = context && menu && [...menu.querySelectorAll("a[href]")].some((link) => {
            const url = new URL(link.href, window.location.href);
            return url.origin === window.location.origin && url.pathname === `${context.stationPath}/files`;
        });
        if (!hasMediaAccess) {
            existing?.remove();
            return;
        }
        if (existing?.parentElement === menu && existing.querySelector("a").getAttribute("href") === context.href) {
            return;
        }
        existing?.remove();
        const item = document.createElement("li");
        item.id = itemId;
        item.className = "nav-item";
        const link = document.createElement("a");
        link.className = "nav-link ps-4 py-2";
        link.setAttribute("href", context.href);
        link.textContent = "Sweepers";
        item.append(link);
        menu.append(item);
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
