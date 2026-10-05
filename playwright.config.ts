import { defineConfig } from "@playwright/test";

// Browser tests of the admin: npm run build, then npm run test:e2e.
// tests/e2e/server.ts starts the app on its own SQLite file; the working
// database is never touched.
const port = process.env.E2E_PORT ?? "8765";
const results = "storage/framework/testing/e2e-results";

export default defineConfig({
    testDir: "./tests/e2e",
    outputDir: results + "/artifacts",
    timeout: 30_000,
    expect: { timeout: 5_000 },
    fullyParallel: false,
    workers: 1,
    reporter: "list",
    use: {
        baseURL: "http://127.0.0.1:" + port,
        browserName: "chromium",
        locale: "ru-RU",
        viewport: { width: 1280, height: 860 },
        reducedMotion: "reduce",
        trace: "retain-on-failure",
    },
    projects: [
        { name: "login", testMatch: /auth\.setup\.ts/ },
        {
            name: "admin",
            testMatch: /\.spec\.ts/,
            dependencies: ["login"],
            use: { storageState: results + "/admin-state.json" },
        },
    ],
    webServer: {
        command: "node tests/e2e/server.ts",
        url: "http://127.0.0.1:" + port + "/admin/login",
        env: { E2E_PORT: port },
        reuseExistingServer: false,
        timeout: 120_000,
        stdout: "ignore",
        stderr: "pipe",
    },
});
