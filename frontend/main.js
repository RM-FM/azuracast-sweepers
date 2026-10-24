import { createApp } from "vue";
import App from "./App.vue";
import "./style.css";

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

const root = document.getElementById("app");

createApp(App, {
    base: root.dataset.base,
    csrf: root.dataset.csrf,
}).mount(root);
