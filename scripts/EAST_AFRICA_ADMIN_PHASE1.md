# East Africa Admin Phase 1 Operations

## Scope

The East Africa admin at `/admin/` manages only the isolated East Africa dataset. It does not read or write the West Africa admin, `frontend/data/vehicles.json`, deployment workflows, GitHub, quotations, PDFs, or a database. Public pages continue to request `data/vehicles.json` and continue to display every vehicle as **Reference vehicle · Not in stock**.

The Git-tracked `east-africa/data/vehicles.json` remains the immutable seed and fallback. The operational master is `/home/gltr/ea-admin-data/vehicles.json`. It is created automatically on the first successful admin save. New uploads are stored persistently under `/home/gltr/ea-admin-data/images/<REF-ID>/`, while their public paths remain `images/<REF-ID>/<filename>` through an internal image rewrite. Existing Git-tracked images remain in `east-africa/images/` unchanged. Do not copy the seed manually unless recovering from an exceptional server problem.

## Sakura requirements to verify before deployment

| Requirement | Required state |
|---|---|
| PHP | PHP 8.1 or newer. PHP 8.3 is recommended. |
| PHP extensions | `json`, `session`, `fileinfo`; standard `password_*` and `getimagesize()` functions available |
| Apache | `.htaccess`, `mod_rewrite`, Apache 2.4 authorization directives, and `Options -Indexes` allowed. The vhost must permit at least `AllowOverride FileInfo Options AuthConfig` (or `AllowOverride All`). |
| HTTPS | `https://ea.gloriatrading.com` must be enforced. The application redirects HTTP GET requests, rejects HTTP POST requests, uses a `Secure` cookie, and sends HSTS over HTTPS. |
| PHP upload limits | `upload_max_filesize >= 10M`, `post_max_size >= 50M`, `max_file_uploads >= 20` |
| Runtime permissions | PHP must read/write `/home/gltr/ea-admin-data/` and its subdirectories |
| Image permissions | PHP must create files and directories under `/home/gltr/ea-admin-data/images/`; the deployed `east-africa/images/` only needs to be readable |
| Session storage | PHP session storage must be writable and persistent for the site user |

The implementation writes runtime data and uploaded images with mode `0600`, and runtime directories with `0700`. Uploaded images are served through PHP and are not directly web-readable. The PHP process must run as the Sakura account owner or another identity with equivalent access.

If Sakura terminates TLS before forwarding to Apache/PHP, configure `GLORIA_EA_TRUST_PROXY_HTTPS=1` only when that trusted proxy strips client-supplied `X-Forwarded-Proto` and sets it itself. Do not enable this override on a directly reachable backend that accepts arbitrary forwarded headers. With direct Apache TLS, no override is required.

## Initial runtime directory setup

Run these commands over SSH as the Sakura account that owns `/home/gltr/www/gloria-ea`. The current repository and deployment configuration indicate that account path as `/home/gltr`.

```sh
set -eu
umask 077
install -d -m 700 /home/gltr/ea-admin-data
install -d -m 700 /home/gltr/ea-admin-data/backups
install -d -m 700 /home/gltr/ea-admin-data/login-attempts
install -d -m 700 /home/gltr/ea-admin-data/images
test -r /home/gltr/www/gloria-ea/data/vehicles.json
test -w /home/gltr/ea-admin-data
test -r /home/gltr/www/gloria-ea/images/EA-PBX-001/EA-PBX-001-01.jpg
```

Do **not** create `/home/gltr/ea-admin-data/vehicles.json` during initial setup. Before the first browser save, the admin and public feed use the tracked seed. The first successful save creates the operational file and a verified backup of the seed state.

## Initial password hash setup

Use a unique password of at least 12 characters. The following commands read the password without echoing it and do not put it on the shell command line or in shell history.

```sh
set -eu
umask 077
read -r -s -p 'New East Africa admin password (12+ characters): ' EA_PASSWORD
echo
if [ "${#EA_PASSWORD}" -lt 12 ]; then
  echo 'Password must be at least 12 characters.' >&2
  unset EA_PASSWORD
  exit 1
fi
export EA_PASSWORD
php -r 'echo password_hash(getenv("EA_PASSWORD"), PASSWORD_DEFAULT), PHP_EOL;' \
  > /home/gltr/ea-admin-data/admin-password.hash
unset EA_PASSWORD
chmod 600 /home/gltr/ea-admin-data/admin-password.hash
test -s /home/gltr/ea-admin-data/admin-password.hash
php -r '$h=trim(file_get_contents("/home/gltr/ea-admin-data/admin-password.hash")); if ((password_get_info($h)["algoName"] ?? "unknown") === "unknown") { exit(1); } echo "Password hash: ok\n";'
```

The alternative `GLORIA_EA_ADMIN_PASSWORD_HASH` environment variable is supported and takes precedence over the file. Store only a `password_hash()` result, never the plaintext password. Do not reuse the West Africa password file or `GLORIA_ADMIN_PASSWORD`.

## Change password while signed in

Use **Change password** at `/admin/settings.php`. The current password must verify, the new password and confirmation must match, and only a new `password_hash()` result is written to `/home/gltr/ea-admin-data/admin-password.hash`. After a successful change the session ends and the new password is required to sign in again.

If `GLORIA_EA_ADMIN_PASSWORD_HASH` is set on the server, the form cannot change it. After a UI password change, also update the GitHub secret `EA_ADMIN_PASSWORD` to the same new value so a later recovery job does not revert to the old secret.

## Forgotten password (signed out)

Do not add a public reset form or email reset. There is no way to display the current password.

The supported recovery is GitHub Actions **Initialize East Africa Admin Runtime** on `main`, with confirm value `RESET_EA_ADMIN_PASSWORD`. First update repository secret `EA_ADMIN_PASSWORD` to the new password (12+ characters). The job replaces only `admin-password.hash`. It does not modify vehicle JSON, images, or the public site. Do not run `INIT_EA_ADMIN` on an already initialized server.

## Pre-deployment server checks

After files have been placed on a non-production review location, run:

```sh
php -v
php -m | grep -E '^(fileinfo|json|session)$'
php -l /home/gltr/www/gloria-ea/lib/vehicle-store.php
php -l /home/gltr/www/gloria-ea/data/vehicle-feed.php
php -l /home/gltr/www/gloria-ea/data/vehicle-image.php
php -l /home/gltr/www/gloria-ea/admin/bootstrap.php
php -l /home/gltr/www/gloria-ea/admin/index.php
php -l /home/gltr/www/gloria-ea/admin/edit.php
php -l /home/gltr/www/gloria-ea/admin/settings.php
curl --fail --silent --show-error https://ea.gloriatrading.com/data/vehicles.json | php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); if (!isset($d["vehicles"]) || count($d["vehicles"]) < 1) exit(1); echo "Public feed: ok\n";'
curl --fail --silent --show-error -I https://ea.gloriatrading.com/admin/ | grep -i '^x-robots-tag: noindex, nofollow, noarchive'
curl --silent --show-error -I http://ea.gloriatrading.com/admin/ | grep -i '^location: https://ea.gloriatrading.com/admin/'
```

Open `/admin/` only over HTTPS. Confirm that a failed login is rejected, the configured password works, logout works, existing three vehicles load, and `EA-PBX-001` shows its eight photos in the original order. After the first successful save, confirm that the admin list says `runtime operational data`; this proves that `/data/vehicles.json` is not silently serving only the Git seed.

## Backups and recovery

Each successful change first creates a verified JSON backup under `/home/gltr/ea-admin-data/backups/`. Uploaded image files are immutable in Phase 1 and remain under `/home/gltr/ea-admin-data/images/`, so restored JSON backups can continue to reference them. Backups are not automatically deleted in Phase 1. Monitor the free space of `/home/gltr`; a save is refused before replacement when a verified backup cannot be created safely. Include the complete `/home/gltr/ea-admin-data/` directory in the hosting-account backup policy because per-write JSON backups do not protect against loss of the entire account volume.

To inspect available backups:

```sh
ls -lt /home/gltr/ea-admin-data/backups/vehicles-*.json
```

To restore one backup, first stop admin edits, then run the following with the selected absolute backup path:

```sh
set -eu
RESTORE_FROM='/home/gltr/ea-admin-data/backups/vehicles-YYYYMMDD-HHMMSS-RANDOM.json'
RUNTIME='/home/gltr/ea-admin-data/vehicles.json'
test -s "$RESTORE_FROM"
php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$RESTORE_FROM"
cp -p "$RUNTIME" "$RUNTIME.before-manual-restore-$(date -u +%Y%m%d-%H%M%S)"
install -m 600 "$RESTORE_FROM" "$RUNTIME.tmp"
mv -f "$RUNTIME.tmp" "$RUNTIME"
```

After restore, verify that every restored gallery path resolves through either the deployed static images or `/home/gltr/ea-admin-data/images/`. If the runtime JSON is unavailable or invalid, the public feed logs the failure and falls back to the tracked seed. The admin intentionally refuses unsafe editing when runtime data exists but fails validation.

## Local validation

From the repository root:

```sh
node --check east-africa/js/ea.js
node --check east-africa/admin/assets/admin.js
python3 scripts/check-east-africa.py
php scripts/check-east-africa-admin.php
php scripts/check-east-africa-admin-concurrency.php
find east-africa -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l
```
