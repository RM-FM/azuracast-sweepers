import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
    plugins: [vue()],
    build: {
        lib: {
            entry: "frontend/main.js",
            formats: ["es"],
            fileName: () => "app.js",
            cssFileName: "app",
        },
        outDir: "dist",
        emptyOutDir: true,
    },
});
