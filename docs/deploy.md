# Production deployment

How to run the template on a server with the Docker stack, update it, back it up,
restore it and move a database between machines. Commands run from the project
directory on the server (e.g. `/opt/app`), next to `docker-compose.yml` and `.env`.

## 1. Server

1. Install Docker Engine with the Compose plugin.
2. Install nginx on the host. The `app` container serves plain HTTP and listens on
   `127.0.0.1:${APP_PORT}` only (8888 by default): the port is published on loopback,
   so it is not reachable from outside even with an open firewall. nginx takes the
   domain on 80/443, terminates TLS and proxies to the container.
3. Copy the code to the server (`git clone`, or `rsync` without `vendor`,
   `node_modules`, `storage/app/*` and `.env`).

The full nginx example is `docker/nginx.conf.example`. The essentials:

```nginx
upstream laravel_app {
    server 127.0.0.1:8888;   # APP_PORT from .env
    keepalive 16;
}

server {
    listen 80;
    server_name example.com;
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    http2 on;
    server_name example.com;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    # Matches upload_max_filesize/post_max_size in docker/php.ini (64M).
    client_max_body_size 64m;
    proxy_request_buffering off;

    location / {
        proxy_pass http://laravel_app;
        proxy_http_version 1.1;
        proxy_set_header Connection        "";
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;
        proxy_set_header X-Forwarded-Port  $server_port;
        proxy_connect_timeout 5s;
        proxy_send_timeout    120s;
        proxy_read_timeout    120s;
        gzip off;   # Caddy inside the container already compresses
    }

    location = /up {
        proxy_pass http://laravel_app;
        proxy_set_header Host $host;
        access_log off;
    }
}
```

Security headers (CSP, HSTS, X-Frame-Options) come from the application; do not
duplicate them in nginx.

## 2. `.env` on the server

Create `.env` from `.env.example` and set at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # php artisan key:generate --show, once and for good
APP_URL=https://example.com
APP_PORT=8888                 # loopback port nginx proxies to

DB_CONNECTION=mariadb
DB_HOST=db
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=...               # long random password
DB_ROOT_PASSWORD=...          # another long random password

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync

LOG_LEVEL=warning
LOG_STACK=stderr

TRUSTED_PROXIES=172.16.0.0/12

BACKUP_DISK=                  # empty = no offsite copy
```

- **`TRUSTED_PROXIES`** lists the addresses whose `X-Forwarded-*` headers are
  trusted (`config/trustedproxy.php`). nginx on the host reaches the container from
  the docker bridge gateway (e.g. `172.18.0.1`), not from `127.0.0.1`, so the Docker
  range `172.16.0.0/12` is needed (docker-compose.yml uses it by default). Without it
  the app sees nginx's address instead of the visitor's (the activity log and the
  login throttle break) and treats the connection as HTTP. Empty trusts `127.0.0.1`
  and `::1` only (nginx and PHP on one host without Docker), `none` trusts no one,
  `*` only when the app is reachable through the proxy alone.
- **Sessions and cache** live in the database; the stack has no Redis. Jobs (media
  processing, manual backups) run synchronously inside the request; there is no queue
  worker. The session list on the profile page works: any session can be ended.
- **`BACKUP_TIMEOUT`** (600 s by default) limits `mariadb-dump`. A manual backup from
  the admin panel runs inside the request, and nginx waits 120 s for the answer
  (`proxy_read_timeout` in `docker/nginx.conf.example`), so for a large database use
  `php artisan app:db-backup` in the `app` container.
- Media files live on the `public` disk by default (`MEDIA_DISK=public`, the
  `storage-data` volume). `MEDIA_IMAGE_FORMAT=avif` switches WebP to AVIF when GD in
  the image can encode it.

The server's `APP_KEY` does not have to match another machine's: the database stores
nothing encrypted with it, changing the key only ends the sessions.

## 3. First start

```bash
RUN_MIGRATIONS=true RUN_SEEDS=true docker compose up -d --build
docker compose ps
```

`RUN_MIGRATIONS=true` creates the schema and, on an empty database, the base roles
and permissions. `RUN_SEEDS=true` also creates the first administrator when `.env`
sets `ADMIN_PASSWORD` (and `ADMIN_EMAIL`/`ADMIN_NAME`); remove the password from `.env`
afterwards. Alternatively skip `RUN_SEEDS` and run
`docker compose exec -it -u www-data app php artisan app:create-admin` later.
Later restarts go without these variables.

`app` and `db` should be `healthy`; `scheduler` has no health check, `running` is
enough. Check the app: `curl -fsS http://127.0.0.1:8888/up`.

Run commands inside the container with `-u www-data`, otherwise cache and log files
are created as root.

## 4. Updating the code

```bash
git pull
RUN_MIGRATIONS=true docker compose up -d --build
```

`app` applies the migrations on start; `scheduler` waits until `app` is `healthy`.
An existing database is never re-seeded, so permissions configured in the admin panel
are kept. Ordinary restarts without a code change go without `RUN_MIGRATIONS`.

## 5. Backups

- The scheduler (`php artisan schedule:work`, the `scheduler` service) writes a dump to
  `storage/app/backups` (the `storage-data` volume) every night and keeps 7 scheduled
  and 5 manual copies (`BACKUP_KEEP`, `BACKUP_KEEP_MANUAL`). The same night it prunes
  the activity log (`model:prune`) and deletes files the media library no longer
  references (`media:prune-orphans`).
- If the nightly dump fails, the backups page shows a warning and the error goes to the
  activity log and the server log. The page also warns when there is no scheduled copy
  or the newest is older than 26 hours (the scheduler is not running). The next
  successful run clears the warning.
- Dumps hold the database only. Without `BACKUP_DISK` (an offsite copy) take the dumps
  and the media files off the server yourself from time to time:

  ```bash
  docker compose cp app:/app/storage/app/backups ./backups-$(date +%F)
  docker compose exec app tar -czf - -C storage/app/public . > media-$(date +%F).tar.gz
  ```

## 6. Restoring a backup

```bash
docker compose stop scheduler
# A dump from another machine: first docker compose cp ... app:/app/storage/app/backups/
# and chown www-data:www-data, as in section 7.
docker compose exec -u www-data app php artisan app:db-restore db-<date>.sql --force
docker compose exec -u www-data app php artisan migrate --force
docker compose start scheduler
```

`app:db-restore`:

- puts the app into maintenance mode and brings it back afterwards, also on failure
  (maintenance enabled before the command stays on);
- drops every table and view of the current schema and loads the dump into the empty
  schema, so tables of migrations newer than the dump do not survive;
- clears the application and permission caches (`cache:clear`,
  `permission:cache-reset`): settings and permissions of the replaced database live
  there;
- without `--no-safety-backup` first saves the current state as `db-…-prerestore`,
  which the same command can restore.

Stop the scheduler first: otherwise it writes to the database while it is being
replaced. `migrate` catches the schema up when the code is newer than the dump; without
schema changes it does nothing.

## 7. Moving a database to another machine

1. On the source machine: `php artisan app:db-backup --tag=transfer --keep=0` (set
   `BACKUP_BINARY_PATH` when `mariadb-dump` is not in `PATH`). The command checks that
   the dump was written completely. Pack the media files:
   `tar -czf transfer/media.tar.gz -C storage/app/public .`
2. Copy both files to the server (`scp`). `transfer/`, `*.sql` and
   `storage/app/backups` are ignored by Git and Docker.
3. On the server (the entrypoint creates `storage/app/backups` in the container):

   ```bash
   docker compose stop scheduler
   docker compose cp transfer/db-<date>-transfer.sql app:/app/storage/app/backups/
   docker compose exec app chown www-data:www-data storage/app/backups/db-<date>-transfer.sql
   docker compose exec -u www-data app php artisan app:db-restore db-<date>-transfer.sql --force --no-safety-backup
   docker compose exec -u www-data app php artisan migrate --force

   docker compose cp transfer/media.tar.gz app:/tmp/media.tar.gz
   docker compose exec app sh -c 'tar -xzf /tmp/media.tar.gz -C storage/app/public && chown -R www-data:www-data storage/app/public && rm /tmp/media.tar.gz'
   docker compose start scheduler
   ```

4. Check: `/up` answers 200, `docker compose ps` shows `app` and `db` `healthy` and
   `scheduler` `running`, you can sign in to `/admin`, the media library shows the
   files, and a manual backup on the backups page appears in the list.
5. Delete the transfer files afterwards: they hold real user data.

## 8. Monitoring

- `/up` checks that the database answers and `storage/app` is writable; the `app`
  container's health check uses it.
- `/up?full=1` additionally answers 500 when a queued job has waited for a worker longer
  than 15 minutes (with `QUEUE_CONNECTION=sync` nothing waits, so the check never
  fires). Point external monitoring at it: `https://<domain>/up?full=1`.
- Logs: `docker compose logs -f app scheduler`.

## 9. Maintenance mode

`docker compose exec -u www-data app php artisan down` shows the maintenance page;
`php artisan up` brings the app back.
