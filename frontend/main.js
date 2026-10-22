import { createApp } from "vue";
import App from "./App.vue";
import "./style.css";

const root = document.getElementById("app");

createApp(App, {
    base: root.dataset.base,
    csrf: root.dataset.csrf,
}).mount(root);
