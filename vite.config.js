import { defineConfig } from "vite";
import { fileURLToPath, URL } from "node:url";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                "resources/js/admin/app.js",
                // Публичная часть (отдельный бандл) — добавить, когда появится:
                // "resources/js/public/app.js",
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
    ],
    resolve: {
        alias: {
            "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
        },
    },
    server: {
        // host:true нужен, когда Vite крутится в Docker-контейнере (compose.dev.yaml):
        // сервер слушает 0.0.0.0, а HMR-клиент в браузере стучится на localhost:5173.
        // В нативном запуске (npm run dev) переменные не заданы — поведение прежнее.
        host: process.env.VITE_HOST || "localhost",
        hmr: process.env.VITE_HMR_HOST
            ? { host: process.env.VITE_HMR_HOST, clientPort: 5173 }
            : undefined,
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});
