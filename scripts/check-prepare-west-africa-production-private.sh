#!/usr/bin/env bash
# Local checks for the production private prepare script.
# Runtime output must not contain synthetic passwords, bank values, or auction markers.
set -euo pipefail
set +x

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PREPARE="$ROOT/scripts/prepare-west-africa-production-private.sh"
WORKFLOW="$ROOT/.github/workflows/deploy-production.yml"
BASE=""

fail() {
  printf '%s\n' "$1" >&2
  exit 1
}

cleanup() {
  if [ -n "$BASE" ] && [ -d "$BASE" ]; then
    rm -rf -- "$BASE"
  fi
}
trap cleanup EXIT

assert_clean() {
  local file="$1"
  local marker
  for marker in \
    'SYNTHETIC-BANK-TOKEN' \
    'Synthetic Customer Wa' \
    'synthetic-password-value' \
    'SECRET-AUCTION-MARKER' \
    '0000000000'
  do
    if grep -F -q -- "$marker" "$file"; then
      fail "output contained a private marker"
    fi
  done
  local line
  while IFS= read -r line || [ -n "$line" ]; do
    [ -z "$line" ] && continue
    case "$line" in
      *_sha256=[a-f0-9]*) continue ;;
    esac
    if [[ ! "$line" =~ ^[A-Za-z0-9_.=-]+$ ]]; then
      fail "output contained a non-token line"
    fi
  done <"$file"
}

run_script() {
  local mode="$1"
  local web="$2"
  local priv="$3"
  local out="$4"
  local err="$5"
  set +e
  GLORIA_PRODUCTION_PREPARE_FIXTURE=1 bash "$PREPARE" "$mode" "$web" "$priv" >"$out" 2>"$err"
  local status=$?
  set -e
  assert_clean "$out"
  assert_clean "$err"
  printf '%s' "$status"
}

require_line() {
  local file="$1"
  local expected="$2"
  if ! grep -F -x -q -- "$expected" "$file"; then
    fail "missing status token: $expected"
  fi
}

mode_of() {
  php -d display_errors=0 -d log_errors=0 -r 'printf("%o", fileperms($argv[1]) & 0777);' "$1"
}

static_audit() {
  if grep -n 'set -x' "$PREPARE" >/dev/null; then
    fail "prepare script enables command tracing"
  fi
  if grep -E 'rm[[:space:]]+-rf|rm[[:space:]]+-r' "$PREPARE" >/dev/null; then
    fail "prepare script recursively removes files"
  fi
  local line
  while IFS= read -r line; do
    case "$line" in
      *'rm -f -- "$tmp"'*|*'rm -f -- "$LIST_FILE"'*) ;;
      *) fail "prepare script removes an unexpected path" ;;
    esac
  done < <(grep -n 'rm ' "$PREPARE" || true)
  if grep -n -- '--delete' "$WORKFLOW" >/dev/null; then
    fail "production workflow contains --delete"
  fi
  if ! grep -F -q 'deploy:DEPLOY_PRODUCTION|prepare:PREPARE_WA_PRODUCTION_PRIVATE' "$WORKFLOW"; then
    fail "production workflow confirmation pairing is missing"
  fi
  if ! grep -F -q 'bash --noprofile --norc -se -- prepare /home/gltr/www/gloria-site /home/gltr/private/west-africa' "$WORKFLOW"; then
    fail "production prepare command is missing"
  fi
  if ! grep -F -q 'bash --noprofile --norc -se -- check /home/gltr/www/gloria-site /home/gltr/private/west-africa' "$WORKFLOW"; then
    fail "production check command is missing"
  fi
  if grep -F -q 'gloria-test' "$WORKFLOW" || grep -F -q 'west-africa-test' "$WORKFLOW"; then
    fail "production workflow names the test site"
  fi
  if grep -F -q 'GLORIA_PRODUCTION_PREPARE_FIXTURE' "$WORKFLOW"; then
    fail "production workflow enables the local fixture"
  fi
  if grep -F -q 'upload-artifact' "$WORKFLOW" || grep -F -q 'actions/upload-artifact' "$WORKFLOW"; then
    fail "production workflow uploads files to GitHub"
  fi
  if ! grep -F -q -- "--exclude='admin/password.txt'" "$WORKFLOW"; then
    fail "production deploy no longer excludes the admin password file"
  fi
  if ! grep -F -q -- "--exclude='/frontend/data/vehicles.json'" "$WORKFLOW"; then
    fail "production deploy no longer excludes the vehicle master"
  fi
  if ! grep -F -q '/home/gltr/backups/site' "$WORKFLOW"; then
    fail "production backups must stay outside the web root"
  fi
  if grep -n 'git add' "$WORKFLOW" "$PREPARE" >/dev/null; then
    fail "production prepare must not add files to git"
  fi
  awk '
    /^  prepare:/ { prepare = 1 }
    /^  deploy-production:/ { prepare = 0 }
    prepare && /rsync/ { found = 1 }
    END { exit found ? 1 : 0 }
  ' "$WORKFLOW" || fail "prepare job must not rsync"
  if ! git -C "$ROOT" check-ignore -q -- admin/password.txt; then
    fail "admin/password.txt is not gitignored"
  fi
  if git -C "$ROOT" ls-files --error-unmatch -- admin/password.txt >/dev/null 2>&1; then
    fail "admin/password.txt is tracked"
  fi
}

write_bank_config() {
  local path="$1"
  php -d display_errors=0 -d log_errors=0 -r '
    $bank = [
      "bank_name" => "SYNTHETIC-BANK-TOKEN",
      "swift_code" => "",
      "branch_name" => "",
      "branch_phone" => "",
      "account_name" => "",
      "account_number" => "0000000000",
      "branch_address" => "",
    ];
    $encoded = "<?php\nreturn [\"bank\" => " . var_export($bank, true) . "];\n";
    if (file_put_contents($argv[1], $encoded) === false) {
      exit(1);
    }
  ' "$path" >/dev/null
}

write_vehicles() {
  local path="$1"
  local count="$2"
  php -d display_errors=0 -d log_errors=0 -r '
    $count = (int) $argv[2];
    $vehicles = [];
    for ($i = 1; $i <= $count; $i++) {
      $row = ["ref_id" => sprintf("REF-%03d", $i), "make" => "Synthetic"];
      if ($i === 1) {
        $row["customer_name"] = "Synthetic Customer Wa";
        $row["quote_spec_data"] = ["auction_price_jpy" => "SECRET-AUCTION-MARKER"];
      }
      $vehicles[] = $row;
    }
    $payload = json_encode(["vehicles" => $vehicles], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || file_put_contents($argv[1], $payload) === false) {
      exit(1);
    }
  ' "$path" "$count" >/dev/null
}

static_audit

BASE="$(mktemp -d)"
WEB="$BASE/web"
PRIV="$BASE/priv-parent/store"
mkdir -p "$WEB/frontend/data/backup" "$WEB/frontend/uploads/quotes" "$WEB/admin" "$(dirname "$PRIV")"
write_vehicles "$WEB/frontend/data/vehicles.json" 26
write_bank_config "$WEB/admin/quote-pdf-config.php"
printf '%s' 'synthetic-password-value' >"$WEB/admin/password.txt"
printf '%s\n' '{"next":1}' >"$WEB/frontend/data/proforma-invoice-sequence.json"
printf '%s\n' 'quote-bytes' >"$WEB/frontend/uploads/quotes/quote.txt"
printf '%s\n' '{"vehicles":[]}' >"$WEB/frontend/data/backup/old.json"
OUT="$BASE/out"
ERR="$BASE/err"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "0" ] || fail "valid 26-vehicle prepare failed"
require_line "$OUT" "source_vehicles=26"
require_line "$OUT" "password_file=source_ok"
require_line "$OUT" "invoice_bank=source_ok"
require_line "$OUT" "pre_cutover=created"
require_line "$OUT" "private_master=copied"
require_line "$OUT" "password_file=copied"
require_line "$OUT" "sequence_file=copied"
require_line "$OUT" "uploads_copied=1"
require_line "$OUT" "public_backup_copied=1"
require_line "$OUT" "private_vehicles=26"
require_line "$OUT" "public_master=kept"
require_line "$OUT" "prepare_ok"
cmp -s "$WEB/frontend/data/vehicles.json" "$PRIV/vehicles.json" || fail "private master differs from the production master"
cmp -s "$WEB/frontend/data/vehicles.json" "$PRIV/archive/pre-cutover/vehicles.json" || fail "pre-cutover differs from the production master"
cmp -s "$WEB/admin/password.txt" "$PRIV/password.txt" || fail "password copy differs"
[ "$(mode_of "$PRIV")" = "700" ] || fail "private root mode"
[ "$(mode_of "$PRIV/vehicles.json")" = "600" ] || fail "private master mode"
[ "$(mode_of "$PRIV/password.txt")" = "600" ] || fail "password mode"
PUBLIC_HASH="$(php -r 'echo hash_file("sha256", $argv[1]);' "$WEB/frontend/data/vehicles.json")"
PRE_INODE="$(stat -c %i "$PRIV/archive/pre-cutover/vehicles.json")"

STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "0" ] || fail "identical second prepare failed"
require_line "$OUT" "private_master=already_matches"
require_line "$OUT" "password_file=kept"
require_line "$OUT" "pre_cutover=kept"
[ "$(stat -c %i "$PRIV/archive/pre-cutover/vehicles.json")" = "$PRE_INODE" ] || fail "second prepare replaced pre-cutover"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$WEB/frontend/data/vehicles.json")" = "$PUBLIC_HASH" ] || fail "second prepare changed the public master"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$WEB/admin/password.txt")" = "$(php -r 'echo hash_file("sha256", $argv[1]);' "$PRIV/password.txt")" ] || fail "second prepare changed the password file"

printf '%s\n' 'private-upload-bytes' >"$PRIV/uploads/quotes/quote.txt"
UPLOAD_HASH="$(php -r 'echo hash_file("sha256", $argv[1]);' "$PRIV/uploads/quotes/quote.txt")"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "0" ] || fail "no-clobber prepare failed"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$PRIV/uploads/quotes/quote.txt")" = "$UPLOAD_HASH" ] || fail "prepare replaced an existing private upload"

php -r '
  $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  $data["vehicles"][0]["make"] = "Diverged";
  file_put_contents($argv[1], json_encode($data));
' "$PRIV/vehicles.json" >/dev/null
DIVERGED_HASH="$(php -r 'echo hash_file("sha256", $argv[1]);' "$PRIV/vehicles.json")"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "diverged private master was not refused"
require_line "$ERR" "private_master=differs"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$PRIV/vehicles.json")" = "$DIVERGED_HASH" ] || fail "refused prepare changed the private master"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$WEB/frontend/data/vehicles.json")" = "$PUBLIC_HASH" ] || fail "refused prepare changed the public master"

STATUS="$(run_script rollback "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "0" ] || fail "rollback failed"
require_line "$OUT" "rollback_ok"
require_line "$OUT" "public_master=kept"
require_line "$OUT" "private_vehicles=26"
cmp -s "$PRIV/archive/pre-cutover/vehicles.json" "$PRIV/vehicles.json" || fail "rollback did not restore pre-cutover"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$WEB/frontend/data/vehicles.json")" = "$PUBLIC_HASH" ] || fail "rollback changed the public master"

STATUS="$(run_script check "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "0" ] || fail "check failed"
require_line "$OUT" "private_vehicles=26"
require_line "$OUT" "public_vehicles=26"
require_line "$OUT" "check_ok"

cleanup
BASE=""

BASE="$(mktemp -d)"
WEB="$BASE/web"
PRIV="$BASE/priv-parent/store"
mkdir -p "$WEB/frontend/data" "$WEB/admin" "$(dirname "$PRIV")"
write_vehicles "$WEB/frontend/data/vehicles.json" 19
write_bank_config "$WEB/admin/quote-pdf-config.php"
printf '%s' 'synthetic-password-value' >"$WEB/admin/password.txt"
OUT="$BASE/out"
ERR="$BASE/err"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "19-vehicle master was accepted"
require_line "$OUT" "source_vehicles=19"
require_line "$ERR" "vehicle_count_unexpected"
[ ! -d "$PRIV" ] || fail "19-vehicle prepare created the private directory"
[ -f "$WEB/frontend/data/vehicles.json" ] || fail "19-vehicle prepare removed the public master"

cleanup
BASE=""

BASE="$(mktemp -d)"
WEB="$BASE/web"
PRIV="$BASE/priv-parent/store"
mkdir -p "$WEB/frontend/data" "$(dirname "$PRIV")"
printf '{' >"$WEB/frontend/data/vehicles.json"
OUT="$BASE/out"
ERR="$BASE/err"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "invalid JSON was accepted"
require_line "$ERR" "json_invalid"
[ ! -d "$PRIV" ] || fail "invalid JSON created the private directory"

cleanup
BASE=""

BASE="$(mktemp -d)"
WEB="$BASE/web"
PRIV="$BASE/priv-parent/store"
mkdir -p "$WEB/frontend/data" "$WEB/admin" "$(dirname "$PRIV")"
write_vehicles "$WEB/frontend/data/vehicles.json" 26
php -d display_errors=0 -d log_errors=0 -r 'file_put_contents($argv[1], "<?php\nreturn [\"bank\" => [\"bank_name\" => \"\", \"swift_code\" => \"\", \"branch_name\" => \"\", \"branch_phone\" => \"\", \"account_name\" => \"\", \"account_number\" => \"\", \"branch_address\" => \"\"]];\n");' "$WEB/admin/quote-pdf-config.php" >/dev/null
printf '%s' 'synthetic-password-value' >"$WEB/admin/password.txt"
OUT="$BASE/out"
ERR="$BASE/err"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "empty bank source was accepted"
require_line "$ERR" "invoice_bank=empty_source"
[ ! -d "$PRIV" ] || fail "empty bank created the private directory"

rm -f -- "$WEB/admin/password.txt"
write_bank_config "$WEB/admin/quote-pdf-config.php"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "missing password was accepted"
require_line "$ERR" "password_file=absent"
[ ! -d "$PRIV" ] || fail "missing password created the private directory"
[ -f "$WEB/frontend/data/vehicles.json" ] || fail "missing password removed the public master"

STATUS="$(run_script prepare "/home/gltr/www/gloria-test" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "test web root was accepted"
require_line "$ERR" "web_path_invalid"
STATUS="$(run_script prepare "$WEB" "/home/gltr/private/west-africa-test" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "test private directory was accepted"
require_line "$ERR" "private_path_invalid"
STATUS="$(run_script prepare "/home/gltr/www/gloria-site" "/home/gltr/private/west-africa" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "fixture mode accepted live production paths"
require_line "$ERR" "fixture_path_refused"

if [ -e /home/gltr/www/gloria-site ] || [ -e /home/gltr/private/west-africa ] || [ -e /home/gltr/private/west-africa-test ]; then
  fail "refusing to run against a live Sakura path from this machine"
fi
set +e
bash "$PREPARE" prepare "/home/gltr/www/gloria-site" "/home/gltr/private/west-africa" >"$OUT" 2>"$ERR"
STATUS=$?
set -e
[ "$STATUS" = "1" ] || fail "missing live production web root was treated as success"
[ ! -e /home/gltr/private/west-africa ] || fail "prepare created the live production private directory"
[ ! -e /home/gltr/private/west-africa-test ] || fail "prepare created the test private directory"

cleanup
BASE=""

printf '%s\n' "production prepare script checks passed"
