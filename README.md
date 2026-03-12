# Test Task: PHP + MySQL

Minimal application for a test assignment:

- **Backend:** PHP 8.3 (PDO + MySQL)
- **Frontend:** HTML/CSS/JavaScript
- **Database:** MySQL 8.4
- **Transport:** JSON API

## Features

- **Report 1:** Total time across all workers for a selected date.
- **Report 2:** Total time for one worker by day over a date range.
- **Business rule for corrections** (`id_aktivnosti = 3`):
  - If there was a correction (`3`) between start (`2`) and end (`6`), that start is ignored.
  - The last valid start before the end is used.

## Time Calculation Logic

- Time is computed from valid `start(2) -> end(6)` pairs per `id_posla`.
- If there is a correction (`3`) between `start` and `end`, the start before that correction is excluded.
- Report totals are the sum of durations of all valid tasks in the period.
- Within one employee + job + day group, overlapping intervals are merged; totals across different jobs for the same day can still exceed 24 hours.
- The “work day” boundary (cutoff time) is taken from the `WORKDAY_CUTOFF_TIME` environment variable (format `HH:MM:SS`); if it is not set, the default `06:00:00` is used.

### How employee working time is calculated

- **Raw data**: the table `test_log_r` contains events for task start (`id_aktivnosti = 2`), correction (`3`) and end (`6`) per job (`id_posla`) and worker (`id_radnika`).
- For each job, the app builds intervals `(start_dt, end_dt)` using the rules above (last valid start before end, corrections between start and end invalidate that start).
- Each interval is attributed to a **factory work day** based on the cutoff time (by default 06:00–05:59 of the next calendar day).
- **Report 1 (by day)**:
  - For the selected work day, all intervals that belong to this day are taken.
  - Intervals are grouped by worker and job; inside each group overlapping intervals are merged and converted to seconds.
  - The UI shows the per‑worker totals and the grand total across all workers for this day.
- **Report 2 (by worker)**:
  - For a given worker and date range, all intervals of this worker whose work day is inside the range are taken.
  - Intervals are grouped by work day and job; inside each group overlapping intervals are merged and converted to seconds.
  - The result is a list of rows “date + job + duration” for the selected worker.

## Project Structure

- `docker-compose.yml` — runs services `db`, `php`, `nginx`
- `docker/php/Dockerfile` — PHP-FPM with `pdo_mysql`
- `docker/php/php.dev.ini` — dev settings (OPcache off)
- `docker/nginx/default.conf` — nginx config for local dev
- `app/public/index.php` — API routing and page delivery
- `app/src/Db.php` — DB connection (PDO)
- `app/src/ReportsService.php` — report SQL logic
- `app/src/Http.php` — JSON response helpers
- `app/public/index.html` — UI
- `app/public/assets/app.js` — API calls and table rendering

## Run locally

```bash
docker compose up -d --build
```

Then check:

- **UI:** http://localhost:8080
- **API:**
  - http://localhost:8080/api/report/day?date=2025-11-03
  - http://localhost:8080/api/report/worker?id=0099&from=2025-11-01&to=2025-11-05

## Dev behaviour (no container restart)

- App code is bind-mounted: `./app -> /var/www/html`.
- Changes in PHP/HTML/CSS/JS are visible immediately.
- OPcache is disabled in `docker/php/php.dev.ini` so PHP updates apply without restart.

## Database setup

The app expects the database `test_php` and the table from `task/test_log_r.sql`. Load the schema and sample data once:

```bash
docker exec -i testphp_db mysql -uapp -papp test_php < task/test_log_r.sql
```

To reset the database (drops all data):

```bash
docker compose down -v
docker compose up -d --build
```

Then run the command above again to load `task/test_log_r.sql`.

## Environment variables

See `.env.example`:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `WORKDAY_CUTOFF_TIME` — optional, work day cutoff time (`HH:MM:SS`, default `06:00:00`)

Defaults are for the Docker network (`db`, user `app`). The app loads `app/.env` if present — on deploy, create it with your real DB credentials.

## Deploy and load data

**“Database connection failed”** means the app cannot reach the DB.

1. **Configure DB access**
   - Either create `app/.env` (copy from `.env.example`) and set:
     ```env
     DB_HOST=your_db_host
     DB_PORT=3306
     DB_NAME=test_php
     DB_USER=your_user
     DB_PASS=your_password
     ```
   - Or set the same variables in the server environment (hosting panel, systemd, Docker env, etc.).

2. **Create the database and load data**
   - Create the database on the server if it does not exist:
     ```sql
     CREATE DATABASE test_php CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
   - Load the script from the repo (creates the table and sample data):
     ```bash
     docker exec -i testphp_db mysql -uapp -papp test_php < task/test_log_r.sql
     ```
     Or run `task/test_log_r.sql` in your MySQL client against the `test_php` database.

   After that, the app will have sample data (e.g. 2025-11-01–2025-11-02) and reports will work.

3. **Your own data**
   Table `test_log_r` columns: `id`, `id_posla`, `id_aktivnosti`, `id_radnika`, `ime_radnika`, `datum`, `vreme`.  
   `id_aktivnosti`: 2 = task start, 3 = correction, 6 = task end. Load your own dumps or import CSV into this table.

## Domain and TLS

Nginx configs:

- **Local:** `docker/nginx/default.conf` — localhost, port 8080.
- **Production (domain):** `docker/nginx/production.conf` — port 80 and location for Certbot.
- **HTTPS:** `docker/nginx/production-ssl.conf` and `docker/nginx/ssl-params.conf` — enable after obtaining a certificate.

### SSL with Certbot (simplest approach)

On the server, in the project directory:

**1. DNS**  
Create an A record: your domain (e.g. `tst-php.vc-manager.me`) → server IP. Wait for propagation (e.g. `ping your-domain`).

**2. Directory for Let’s Encrypt challenge**
```bash
mkdir -p certbot-webroot
```

**3. Start with production config**
```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

**4. Obtain certificate (Certbot via Docker, no host install)**
```bash
docker run --rm \
  -v "$(pwd)/certbot-webroot:/var/www/certbot" \
  -v test_php_certbot-data:/etc/letsencrypt \
  certbot/certbot certonly --webroot \
  -w /var/www/certbot \
  -d tst-php.vc-manager.me \
  --email emailyour@tst-php.vc-manager.me \
  --agree-tos --no-eff-email
```
Replace `tst-php.vc-manager.me` and `your@email.com` with your domain and email. If your project (folder) name is not `test_php`, use `your_folder_certbot-data` instead of `test_php_certbot-data` (check with `docker volume ls` after the next step).

**5. Enable HTTPS in nginx**  
In `docker-compose.prod.yml`, uncomment the SSL sections (the `certbot-data` volume, and the mounts for `ssl-params.conf`, `production-ssl.conf`, and `certbot-data` in the `nginx` service). Then:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

The site should then be available at `https://your-domain`. You can optionally add an HTTP→HTTPS redirect in `production.conf`.
