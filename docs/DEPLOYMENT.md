# JourneyAI — deployment and operations guide

Written for whoever runs the site. It covers first-time setup, configuration, running, backups, releases and a
go-live checklist. It describes a **single-server** deployment (Apache + PHP + MySQL/MariaDB + one Python service).

## 1. What runs where

```
Browser ──HTTPS──▶ Apache + PHP (the website)  ──▶ MySQL/MariaDB   (database "project")
                          │
                          └─ http://127.0.0.1:5000 ──▶ Python "ML service" (recommendations, costs, routes, chat)
```
- The ML service must listen on **loopback only**. Never open port 5000 in the firewall; only PHP talks to it.
- Uploaded photos/videos live in `uploads/` on the web server's disk.

## 2. Requirements
- Apache 2.4 with `mod_rewrite`, `mod_headers`, `AllowOverride All` for the site folder (the `.htaccess` needs it)
- PHP 8.0+ with `mysqli`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd` or `iconv`
- MySQL 8 or MariaDB 10.4+
- Python 3.10 for the ML service (packages in `ml/requirements.txt`, including `waitress`)
- Outbound internet for: OpenStreetMap geocoding/places, the Gemini chat model (optional), SMTP

## 3. First-time install
1. Copy the project to the web root (for example `C:\xampp\htdocs\travel_journel`).
2. Create the database and run the SQL files **in this order** (all are safe to re-run):
   `sql/migrations.sql`, `sql/media.sql`, `sql/features.sql`, `sql/storybook.sql`, `sql/storybook-themes.sql`,
   `sql/admin.sql`, `sql/community.sql`, `sql/engage.sql`, `sql/prod.sql`
   ```
   mysql -u root -p -e "CREATE DATABASE project CHARACTER SET utf8mb4"
   for each file:  mysql -u root -p project < sql/<file>.sql
   ```
3. Create a least-privilege database user: edit the password in `sql/create-db-user.sql`, run it, then point the app at it (section 4).
4. Python service:
   ```
   py -3.10 -m venv ml\venv
   ml\venv\Scripts\python.exe -m pip install -r ml\requirements.txt
   ml\venv\Scripts\python.exe -m ml.rebuild_all --n 1500        # seeds demo data, builds models (a few minutes)
   ```
   `rebuild_all` needs the files in `ml/data/` (shipped). To refresh them from the web see `docs/INDIA_ROADMAP.md`.
5. Start the service: `ml\start_service.bat` (logs to `ml\logs\service.log`). Check `http://localhost/<site>/health.php`.
6. Open `admin-setup.php` once and create the **first admin** (the page disappears afterwards).
7. In **Admin > Email (SMTP)** enter the mail account and press "Save & send test email".
   Gmail: host `smtp.gmail.com`, port 587, TLS, and an *App password* (not your normal password).
8. In **Admin > Site & services** paste the Gemini API key (optional, enables the AI chat) and decide whether sign-ups are open.
9. Turn **off** "Local testing: show the reset link on screen" in Site & services (it is off by default).

## 4. Configuration
Database settings, in priority order: environment variables `JOURNEYAI_DB_HOST`, `_PORT`, `_USER`, `_PASS`, `_NAME` →
`config/app.local.php` → local XAMPP defaults (root, no password). Example `config/app.local.php` (this folder is
git-ignored and blocked from the web):
```php
<?php return ['host' => '127.0.0.1', 'user' => 'journeyai', 'pass' => 'a-long-random-password', 'db' => 'project'];
```
Apache can pass env vars with `SetEnv JOURNEYAI_DB_PASS ...` in the virtual host (not in `.htaccess`).
The ML service reads the same `JOURNEYAI_DB_*` variables, plus `JOURNEYAI_ML_HOST` / `JOURNEYAI_ML_PORT` (default 127.0.0.1:5000).

Secrets: SMTP password is stored **encrypted** in the `settings` table using `config/secret.key` (created on first use).
**Back this key up together with the database** — without it the SMTP password must be entered again.

## 5. Apache (HTTPS)
Serve only over HTTPS in production. Example virtual host fragment:
```apache
ServerTokens Prod
TraceEnable Off
<VirtualHost *:443>
    ServerName journeyai.example.com
    DocumentRoot "/var/www/journeyai"
    SSLEngine on
    SSLCertificateFile    /path/fullchain.pem
    SSLCertificateKeyFile /path/privkey.pem
    <Directory "/var/www/journeyai">
        AllowOverride All
        Require all granted
    </Directory>
    # SetEnv JOURNEYAI_DB_USER journeyai
    # SetEnv JOURNEYAI_DB_PASS ...
</VirtualHost>
<VirtualHost *:80>
    ServerName journeyai.example.com
    Redirect permanent / https://journeyai.example.com/
</VirtualHost>
```
The shipped `.htaccess` already: hides errors from visitors, sets HttpOnly + SameSite=Lax session cookies (and `Secure` + HSTS
when HTTPS is on), adds security headers, blocks `config/ sql/ ml/ docs/ ops/ tests/ logs/ .git` and source/data files,
and makes `uploads/` static-only (no script execution). If you put the site in a sub-folder the rules still apply.
Recommended `php.ini`: `expose_php=Off`, `display_errors=Off`, `log_errors=On`, `session.cookie_secure=1`.

## 6. Keeping the ML service running
- Manual: `ml\start_service.bat`.
- Windows service (recommended): install [NSSM](https://nssm.cc) and run
  `nssm install JourneyAIML C:\path\ml\venv\Scripts\python.exe -m ml.app` with the start directory set to the project root, startup type *Automatic*.
  Or use Task Scheduler: trigger "At startup", action = `ml\start_service.bat`, "Run whether user is logged on or not".
- Monitoring: poll `GET /health.php` every minute. `200 {"status":"ok"}` = database and recommender both answer; `503` = degraded.
  Signed-in admins see extra detail (disk, uploads writable, email configured).
- The site degrades gracefully if the service is down (pages show an "offline" banner) — but recommendations, costs, routes and chat need it.

## 7. Logs
| What | Where |
|---|---|
| PHP errors (never shown to visitors) | Apache `error.log` (XAMPP: `C:\xampp\apache\logs\error.log`) |
| ML service | `ml\logs\service.log` |
| Emails sent / failed | Admin > Overview, Admin > Email (table `email_log`) |
| Failed logins / rate limits | tables `login_attempts`, `rate_limits` (auto-pruned) |
Rotate the log files weekly (Windows: a scheduled task that archives them; Linux: logrotate).

## 8. Backups and restore
- **Back up**: `powershell -ExecutionPolicy Bypass -File ops\backup.ps1 -Destination D:\backups\journeyai -KeepDays 30`
  writes a gzip SQL dump and a zip of `uploads/`. Schedule it daily in Task Scheduler. Also copy `config/secret.key` and `config/app.local.php` (once, to a safe place).
- **Restore**: create an empty database, then `gunzip -c journeyai-db-….sql.gz | mysql -u root -p project` (Windows: extract with 7-Zip and run `mysql -u root -p project < file.sql`); unzip the uploads archive into `uploads/`.
- **Test a restore** into a scratch database at least once a quarter. (Verified during development: row counts matched exactly.)
- Copy backups off the server (another disk or cloud storage).

## 9. Releasing a change
1. Run `powershell -File ops\check.ps1` — PHP/Python/JS syntax plus the 70-check smoke test (`tests/smoke.py`) against the running site. It must end with `ALL CHECKS PASSED`.
2. Take a backup (section 8).
3. Copy the new files over the old ones. Run any new `sql/*.sql` (they are re-runnable). Restart the ML service if `ml/` changed.
4. Open `health.php`, then click through Plan a Trip, Packing lists and Community.
5. Rollback: restore the previous files and, if a migration changed data, the backup.

## 10. Refreshing the travel data (optional, every few months)
```
ml\venv\Scripts\python.exe -m ml.scrape.wikivoyage --crawl --listings
ml\venv\Scripts\python.exe -m ml.scrape.wikipedia_views
ml\venv\Scripts\python.exe -m ml.scrape.build_india_dataset
ml\venv\Scripts\python.exe -m ml.scrape.build_highlights
ml\venv\Scripts\python.exe -m ml.geo.build_dest_coords      # coordinates for distance-based trip ranking (slow: ~1 request/second)
ml\venv\Scripts\python.exe -m ml.rebuild_all --n 1500
```
The scrapers respect `robots.txt`, identify themselves and cache. Wikivoyage/Wikipedia text is CC BY-SA and OpenStreetMap data is ODbL:
the site footer carries the required attribution, **keep it**. Do not remove the "costs are estimates" note.

## 11. Go-live checklist
- [ ] HTTPS working, HTTP redirects to it
- [ ] Database user is **not** root; password is long and random; MySQL not reachable from the internet
- [ ] Port 5000 (ML service) and 3306 (MySQL) are not exposed by the firewall
- [ ] First admin created with a strong password; no other admin accounts you do not recognise
- [ ] SMTP configured and the test email arrived (check spam); password reset tested end-to-end
- [ ] "Show the reset link on screen" is OFF; sign-ups open or closed as you want
- [ ] `ops\check.ps1` passes; `health.php` returns ok
- [ ] Daily backup scheduled **and** one restore tested; `config/secret.key` backed up
- [ ] ML service starts automatically after a reboot
- [ ] Demo data removed if you do not want it: Admin > Users > "Include demo/seed accounts" then delete, or run `python -m ml.seed.generate_corpus --truncate` and `python -m ml.seed.load_real_entries --truncate` followed by `ml.rebuild_all --no-seed --no-real` once real users have entries

## 12. Known limitations (be honest with stakeholders)
- **Single server.** PHP sessions and uploads are local files; scaling to several servers needs shared session storage and object storage for uploads.
- **No Content-Security-Policy yet.** The pages use inline scripts and several CDNs; a strict CSP needs nonce work. Other headers are set.
- **CSRF**: new endpoints use CSRF tokens; older JSON endpoints (storybook API, post likes) rely on SameSite=Lax cookies, which blocks cross-site form posts in modern browsers.
- **No email verification at sign-up and no two-factor login.** Password reset links expire after 1 hour and are single-use; login and reset requests are rate limited.
- **Recommendations and costs are estimates** built from public travel data and demo journals. The offline accuracy figures in `ml/notebooks/` describe that data, not real-user outcomes. Collect real journals to improve them.
- **Uploads are not virus-scanned** (type and size are checked; files are served static-only with `nosniff`).
- The AI chat depends on a third-party model (Gemini); when unavailable it falls back to the built-in planner.
