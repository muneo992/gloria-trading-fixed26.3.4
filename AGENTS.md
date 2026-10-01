# AGENTS.md

## Cursor Cloud specific instructions

This repo is a **static marketing site + a pure-PHP admin panel** for "Gloria Trading" (Japanese used-car export). There is **no `package.json`, `composer.json`, build step, or automated test suite**. Deployment is `rsync` to Sakura via manual GitHub Actions (`.github/workflows/deploy-*.yml`); those workflows smoke-test live URLs and are not runnable locally.

### Components
- `frontend/` — static HTML/CSS/JS. The catalog (`catalog.html`) fetches `data/vehicles.json` at runtime, so it must be served over **HTTP** (not `file://`).
- `admin/` — PHP admin panel (login, add/edit/delete vehicles, image upload, CSV import/export, quote PDFs). It reads/writes `frontend/data/vehicles.json` and `frontend/images/vehicles/`. Paths are resolved in `admin/bootstrap.php` relative to `../frontend`.

### Running the dev server (both frontend + admin from one server)
PHP is the required runtime (installed by the update script). The site normally relies on Apache `.htaccess` rewrites; PHP's built-in server has none, so use the repo's own workaround script first:

1. `bash scripts/sakura-publish-links.sh .` — creates root-level symlinks (`css`, `js`, `data`, `images`, `assets`, `uploads`, page `*.html`) and a root `vehicles.json` so paths resolve without mod_rewrite.
2. `php -S 0.0.0.0:8000` from the **repo root** (not `frontend/`, because admin references `../frontend`).

Then: frontend at `http://localhost:8000/`, catalog at `/catalog.html`, admin at `/admin/index.php`, and `admin/health.php` is a no-login diagnostics endpoint.

### Non-obvious caveats
- **Do not commit the artifacts created by `sakura-publish-links.sh`** (the root symlinks and root `vehicles.json`). They show up as untracked files and are dev-only.
- **Admin password**: set `admin/password.txt` (gitignored, one line) or env `GLORIA_ADMIN_PASSWORD`. Without it, admin login shows "password not configured". This file is intentionally not in git and must be recreated per environment.
- Adding/editing a vehicle writes to the tracked `frontend/data/vehicles.json`. If you only did this for testing, revert it (`git checkout -- frontend/data/vehicles.json`) so test data isn't committed.
- Clean URLs like `/west-africa/ghana` come from `.htaccess`/`netlify.toml` rewrites and will **404 under the PHP built-in server**; the underlying `frontend/west_africa/*.html` pages still load directly.
- Lint = PHP syntax check: `for f in index.php admin/*.php; do php -l "$f"; done`. There are no unit tests.
