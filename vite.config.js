import { defineConfig } from "vite";
import { fileURLToPath, URL } from "node:url";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                "resources/js/admin/app.js",
                // Public section (separate bundle) — add when it appears:
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
        // host:true is needed when Vite runs inside a Docker container (compose.dev.yaml):
        // the server listens on 0.0.0.0, while the browser's HMR client connects to localhost:5173.
        // In a native run (npm run dev) the variables are unset — behavior stays as before.
        host: process.env.VITE_HOST || "localhost",
        hmr: process.env.VITE_HMR_HOST
            ? { host: process.env.VITE_HMR_HOST, clientPort: 5173 }
            : undefined,
        watch: {
            ignored: ["**/storage/framework/views/**"],
        },
    },
});
