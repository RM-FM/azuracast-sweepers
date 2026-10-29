import { createApp, h } from "vue";
import App from "./App.vue";
import "@vuepic/vue-datepicker/dist/main.css";
import "./style.css";

export function mountSweepers(root) {
    const app = createApp(App, {
        base: root.dataset.base,
        csrf: root.dataset.csrf,
        view: root.dataset.view || "settings",
        standalone: !root.classList.contains("embedded"),
    });
    app.mount(root);
    return () => app.unmount();
}

// Options hooks run in AzuraCast's Vue runtime; the plugin app owns only this host's children.
export const SweepersPage = {
    props: ["base", "view"],
    data: () => ({unmountSweepers: null}),
    computed: {
        pageKey() {
            return `${this.base}|${this.view}`;
        },
    },
    watch: {
        pageKey: "mountPage",
    },
    mounted() {
        this.mountPage();
    },
    beforeUnmount() {
        this.unmountSweepers?.();
    },
    methods: {
        mountPage() {
            this.unmountSweepers?.();
            this.$el.dataset.base = this.base;
            this.$el.dataset.view = this.view;
            this.unmountSweepers = mountSweepers(this.$el);
        },
    },
    render() {
        return h("div", {class: "sweepers-page embedded"});
    },
};

const root = document.querySelector("#app.sweepers-page:not(.embedded)[data-base]");
if (root) {
    let theme;
    try {
        const stored = window.localStorage.getItem("theme");
        theme = stored === "dark" || stored === "light" ? stored : JSON.parse(stored);
    } catch {
        theme = null;
    }
    if (theme !== "dark" && theme !== "light") {
        theme = window.matchMedia?.("(prefers-color-scheme: dark)")?.matches ? "dark" : "light";
    }
    document.documentElement.setAttribute("data-bs-theme", theme);
    mountSweepers(root);
}
