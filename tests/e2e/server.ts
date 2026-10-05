// Starts the admin for the browser tests (npm run test:e2e) on its own SQLite
// database. Playwright runs this script as its web server.
//
// The real database must stay out of reach, so the script:
//   1. writes .env.e2e and starts PHP with APP_ENV=e2e: Laravel then reads
//      .env.e2e and never .env, and the variables of this process win anyway;
//   2. points the config cache to a file that does not exist, so a cached
//      config of the working copy cannot bring the real connection back;
//   3. asks the booted application which database it uses and stops unless it
//      is the fresh SQLite file below;
//   4. only then migrates and seeds that file (admin@example.com / password123).
//
// The PHP built-in server is started directly: "php artisan serve" restarts
// PHP with the variables cleared, and the new process would read .env.
import { execFileSync, spawn } from "node:child_process";
import { randomBytes } from "node:crypto";
import { existsSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";

const root = resolve(import.meta.dirname, "../..");
const relDir = "storage/framework/testing/e2e";
const dir = resolve(root, relDir);
const slashes = (path: string) => path.replaceAll("\\", "/");
const database = slashes(resolve(dir, "e2e.sqlite"));
const port = process.env.E2E_PORT ?? "8765";
const php = process.env.E2E_PHP ?? "php";

if (!existsSync(resolve(root, "public/build/manifest.json"))) {
    console.error("e2e: no built assets, run npm run build first");
    process.exit(1);
}

rmSync(dir, { recursive: true, force: true });
mkdirSync(resolve(dir, "views"), { recursive: true });
writeFileSync(database, "");

const env: Record<string, string> = {
    APP_NAME: "Admin",
    APP_ENV: "e2e",
    APP_KEY: "base64:" + randomBytes(32).toString("base64"),
    APP_DEBUG: "true",
    APP_URL: "http://127.0.0.1:" + port,
    APP_LOCALE: "ru",
    // Relative paths: Laravel treats only "/" and "\" prefixes as absolute.
    APP_CONFIG_CACHE: relDir + "/config.php",
    APP_ROUTES_CACHE: relDir + "/routes.php",
    APP_EVENTS_CACHE: relDir + "/events.php",
    APP_SERVICES_CACHE: relDir + "/services.php",
    APP_PACKAGES_CACHE: relDir + "/packages.php",
    APP_MAINTENANCE_DRIVER: "cache",
    APP_MAINTENANCE_STORE: "array",
    BCRYPT_ROUNDS: "4",
    BROADCAST_CONNECTION: "null",
    CACHE_STORE: "array",
    DB_CONNECTION: "sqlite",
    DB_DATABASE: database,
    DB_URL: "",
    SESSION_DRIVER: "database",
    QUEUE_CONNECTION: "sync",
    MAIL_MAILER: "array",
    LOG_CHANNEL: "stderr",
    LOG_LEVEL: "warning",
    VIEW_COMPILED_PATH: slashes(resolve(dir, "views")),
    MEDIA_DISK: "public",
    BACKUP_PATH: relDir + "/backups",
    BACKUP_DISK: "",
    BACKUP_ENCRYPTION_KEY: "",
    PULSE_ENABLED: "false",
    TELESCOPE_ENABLED: "false",
    NIGHTWATCH_ENABLED: "false",
};

writeFileSync(
    resolve(root, ".env.e2e"),
    "# Written by tests/e2e/server.ts for every run; do not edit.\n" +
        Object.entries(env)
            .map(([key, value]) => key + '="' + value + '"\n')
            .join(""),
);

const childEnv = { ...process.env, ...env };
const run = (args: string[]) =>
    execFileSync(php, args, { cwd: root, env: childEnv, encoding: "utf8" });

// 3. The booted application must use the fresh SQLite file and nothing else.
const probe = JSON.parse(
    run([
        "-r",
        [
            "require 'vendor/autoload.php';",
            "$app = require 'bootstrap/app.php';",
            "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();",
            "$c = config('database.default');",
            "echo json_encode(['env' => app()->environment(), 'connection' => $c,",
            "'database' => config('database.connections.'.$c.'.database'),",
            "'cached' => app()->configurationIsCached()]);",
        ].join(" "),
    ]),
) as { env: string; connection: string; database: string; cached: boolean };

if (
    probe.env !== "e2e" ||
    probe.connection !== "sqlite" ||
    slashes(String(probe.database)).toLowerCase() !== database.toLowerCase() ||
    probe.cached
) {
    console.error("e2e: refusing to start, unexpected database", probe);
    process.exit(1);
}

// 4. Schema and the test accounts.
run(["artisan", "migrate", "--seed", "--force", "--no-interaction"]);

// The router script serves files from its working directory: start in public/.
const server = spawn(
    php,
    [
        "-S",
        "127.0.0.1:" + port,
        resolve(
            root,
            "vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php",
        ),
    ],
    {
        cwd: resolve(root, "public"),
        env: childEnv,
        stdio: ["ignore", "inherit", "pipe"],
    },
);

// The built-in server logs every request to stderr; pass on the rest
// (PHP warnings, Laravel errors from the stderr log channel).
const requestLine = /\d+\.\d+\.\d+\.\d+:\d+ (Accepted|Closing|\[\d{3}\]:)/;
server.stderr.setEncoding("utf8");
server.stderr.on("data", (chunk: string) => {
    for (const line of chunk.split(/\r?\n/)) {
        if (line.trim() && !requestLine.test(line)) console.error(line);
    }
});
server.on("exit", (code) => process.exit(code ?? 0));
for (const signal of ["SIGINT", "SIGTERM"] as const) {
    process.on(signal, () => server.kill());
}
