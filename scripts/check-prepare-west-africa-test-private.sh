#!/usr/bin/env bash
# Local checks for the West Africa TEST private prepare script.
# Runtime output must not contain the synthetic fixture values.
set -euo pipefail
set +x

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PREPARE="$ROOT/scripts/prepare-west-africa-test-private.sh"
WORKFLOW="$ROOT/.github/workflows/deploy-test.yml"
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

TEXT_MARKERS=(
  'SYNTHETIC-BANK-TOKEN'
  'Synthetic Customer Wa'
  'synthetic-password-value'
  'SYNCHASSIS'
  'DIVERGED-MARKER'
)

assert_clean() {
  local file="$1"
  local marker line
  for marker in "${TEXT_MARKERS[@]}"; do
    if grep -F -q -- "$marker" "$file"; then
      fail "output contained a private marker"
    fi
  done
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      *_sha256=[a-f0-9]*) continue ;;
    esac
    case "$line" in
      *111222*|*0000000000*) fail "output contained a private marker" ;;
    esac
  done <"$file"
}

assert_tokens() {
  local file="$1"
  local line
  while IFS= read -r line || [ -n "$line" ]; do
    [ -z "$line" ] && continue
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
  bash "$PREPARE" "$mode" "$web" "$priv" >"$out" 2>"$err"
  local status=$?
  set -e
  assert_clean "$out"
  assert_clean "$err"
  assert_tokens "$out"
  assert_tokens "$err"
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

bank_matches() {
  php -d display_errors=0 -d log_errors=0 -r '
    $bank = require $argv[1];
    $ok = is_array($bank)
      && ($bank["bank_name"] ?? null) === "SYNTHETIC-BANK-TOKEN"
      && ($bank["account_number"] ?? null) === "0000000000"
      && ($bank["swift_code"] ?? null) === ""
      && count($bank) === 7;
    exit($ok ? 0 : 1);
  ' "$1" >/dev/null 2>/dev/null
}

static_script_audit() {
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
    fail "test workflow contains --delete"
  fi
  if ! grep -F -q 'deploy:DEPLOY_TEST|prepare:PREPARE_WA_TEST_PRIVATE|rollback:ROLLBACK_WA_TEST_PRIVATE' "$WORKFLOW"; then
    fail "test workflow confirmation pairing is missing"
  fi
  if ! grep -F -q 'bash --noprofile --norc -se' "$WORKFLOW"; then
    fail "test workflow does not run the remote script under bash"
  fi
  if ! grep -F -q 'wa-workflow/scripts/prepare-west-africa-test-private.sh' "$WORKFLOW"; then
    fail "test workflow does not pipe the prepare script"
  fi
  if ! grep -F -q -- "--exclude='/wa-workflow/'" "$WORKFLOW"; then
    fail "test workflow does not exclude the second checkout"
  fi
  if ! grep -F -q "inputs.private_data == 'deploy'" "$WORKFLOW"; then
    fail "test workflow does not limit rsync to deploy"
  fi
  if ! grep -F -q '/home/gltr/private/west-africa-test' "$WORKFLOW"; then
    fail "test workflow lost the test private path"
  fi
  if grep -F -q '/home/gltr/www/gloria-site' "$WORKFLOW"; then
    fail "test workflow names the production web root"
  fi
  if grep -E -q '/home/gltr/private/west-africa([^/-]|$)' "$WORKFLOW"; then
    fail "test workflow names the production private directory"
  fi
  if grep -n 'ref:.*inputs.deploy_ref' "$WORKFLOW" >/dev/null; then
    fail "checkout still receives deploy_ref directly"
  fi
  if ! grep -F -q 'scripts/resolve-deploy-ref.sh' "$WORKFLOW"; then
    fail "workflow does not expand deploy_ref before checkout"
  fi
  if ! grep -F -q 'fetch-depth: 0' "$WORKFLOW"; then
    fail "workflow does not fetch history before resolving a short SHA"
  fi
}

check_deploy_ref_resolution() {
  local full short branch line err
  full="$(git -C "$ROOT" rev-parse HEAD)"
  short="$(git -C "$ROOT" rev-parse --short=7 HEAD)"
  branch="$(git -C "$ROOT" rev-parse --abbrev-ref HEAD)"
  line="$(cd "$ROOT" && bash scripts/resolve-deploy-ref.sh "$short")"
  [ "$line" = "sha=$full" ] || fail "short SHA was not expanded to the workflow HEAD"
  line="$(cd "$ROOT" && bash scripts/resolve-deploy-ref.sh "$full")"
  [ "$line" = "sha=$full" ] || fail "full SHA was not kept"
  line="$(cd "$ROOT" && bash scripts/resolve-deploy-ref.sh "$branch")"
  [ "$line" = "sha=$full" ] || fail "branch name was not resolved to the workflow HEAD"
  err="$(mktemp)"
  if (cd "$ROOT" && bash scripts/resolve-deploy-ref.sh "aaaaaaaa") >/dev/null 2>"$err"; then
    rm -f -- "$err"
    fail "unknown SHA was accepted"
  fi
  if ! grep -F -x -q 'deploy_ref_not_found' "$err"; then
    rm -f -- "$err"
    fail "unknown SHA did not stop cleanly"
  fi
  if (cd "$ROOT" && bash scripts/resolve-deploy-ref.sh "../outside") >/dev/null 2>"$err"; then
    rm -f -- "$err"
    fail "invalid deploy ref was accepted"
  fi
  if ! grep -F -x -q 'deploy_ref_invalid' "$err"; then
    rm -f -- "$err"
    fail "invalid deploy ref did not stop cleanly"
  fi
  rm -f -- "$err"
}

write_bank_config() {
  local path="$1"
  local bank_name="$2"
  local account_number="$3"
  php -d display_errors=0 -d log_errors=0 -r '
    $bank = [
      "bank_name" => $argv[2],
      "swift_code" => "",
      "branch_name" => "",
      "branch_phone" => "",
      "account_name" => "",
      "account_number" => $argv[3],
      "branch_address" => "",
    ];
    $encoded = "<?php\nreturn [\"bank\" => " . var_export($bank, true) . "];\n";
    if (file_put_contents($argv[1], $encoded) === false) {
      exit(1);
    }
  ' "$path" "$bank_name" "$account_number" >/dev/null
}

write_vehicles() {
  local path="$1"
  local ref="$2"
  local extra="$3"
  php -d display_errors=0 -d log_errors=0 -r '
    $row = ["ref_id" => $argv[2]];
    if ($argv[3] !== "") {
      $row["customer_name"] = $argv[3];
      $row["chassis_no"] = $argv[4];
      $row["quote_spec_data"] = ["auction_price_jpy" => (int)$argv[5]];
    }
    $payload = json_encode(["vehicles" => [$row]], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || file_put_contents($argv[1], $payload) === false) {
      exit(1);
    }
  ' "$path" "$ref" "$extra" "SYNCHASSIS" "111222" >/dev/null
}

prepare_values_fixture() {
  BASE="$(mktemp -d)"
  WEB="$BASE/web"
  PARENT="$BASE/priv-parent"
  PRIV="$PARENT/store"
  mkdir -p "$WEB/frontend/data/backup" "$WEB/frontend/uploads/quotes" "$WEB/admin" "$PARENT"
  write_vehicles "$WEB/frontend/data/vehicles.json" "SYN-001" "Synthetic Customer Wa"
  write_bank_config "$WEB/admin/quote-pdf-config.php" "SYNTHETIC-BANK-TOKEN" "0000000000"
  printf '%s' 'synthetic-password-value' >"$WEB/admin/password.txt"
  printf '%s\n' '{"next":1}' >"$WEB/frontend/data/proforma-invoice-sequence.json"
  printf '%s\n' 'quote-bytes' >"$WEB/frontend/uploads/quotes/quote.txt"
  printf '%s\n' '{"vehicles":[]}' >"$WEB/frontend/data/backup/old.json"
}

static_script_audit
check_deploy_ref_resolution

prepare_values_fixture
OUT="$BASE/out"
ERR="$BASE/err"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "0" ] || fail "valid prepare failed"
require_line "$OUT" "source_vehicles=1"
require_line "$OUT" "invoice_bank=source_ok"
require_line "$OUT" "pre_cutover=created"
require_line "$OUT" "invoice_bank=written"
require_line "$OUT" "private_master=copied"
require_line "$OUT" "password_file=copied"
require_line "$OUT" "sequence_file=copied"
require_line "$OUT" "uploads_copied=1"
require_line "$OUT" "uploads_kept=0"
require_line "$OUT" "public_backup_copied=1"
require_line "$OUT" "private_vehicles=1"
require_line "$OUT" "public_master=kept"
require_line "$OUT" "prepare_ok"
cmp -s "$WEB/frontend/data/vehicles.json" "$PRIV/vehicles.json" || fail "private master differs from the public master"
cmp -s "$WEB/frontend/data/vehicles.json" "$PRIV/archive/pre-cutover/vehicles.json" || fail "pre-cutover differs from the public master"
cmp -s "$WEB/admin/password.txt" "$PRIV/password.txt" || fail "password copy differs"
cmp -s "$WEB/frontend/data/proforma-invoice-sequence.json" "$PRIV/invoice-sequence.json" || fail "sequence copy differs"
cmp -s "$WEB/frontend/uploads/quotes/quote.txt" "$PRIV/uploads/quotes/quote.txt" || fail "upload copy differs"
[ "$(mode_of "$PARENT")" = "700" ] || fail "private parent mode"
[ "$(mode_of "$PRIV")" = "700" ] || fail "private root mode"
[ "$(mode_of "$PRIV/uploads")" = "700" ] || fail "upload directory mode"
[ "$(mode_of "$PRIV/vehicles.json")" = "600" ] || fail "private master mode"
[ "$(mode_of "$PRIV/archive/pre-cutover/vehicles.json")" = "600" ] || fail "pre-cutover mode"
[ "$(mode_of "$PRIV/password.txt")" = "600" ] || fail "password mode"
[ "$(mode_of "$PRIV/invoice-bank.php")" = "600" ] || fail "bank file mode"
bank_matches "$PRIV/invoice-bank.php" || fail "bank file was not stored"
PRE_INODE="$(stat -c %i "$PRIV/archive/pre-cutover/vehicles.json")"
PUBLIC_HASH="$(php -r 'echo hash_file("sha256", $argv[1]);' "$WEB/frontend/data/vehicles.json")"

STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "0" ] || fail "identical second prepare failed"
require_line "$OUT" "pre_cutover=kept"
require_line "$OUT" "private_master=already_matches"
require_line "$OUT" "invoice_bank=kept"
require_line "$OUT" "uploads_kept=1"
[ "$(stat -c %i "$PRIV/archive/pre-cutover/vehicles.json")" = "$PRE_INODE" ] || fail "second prepare replaced pre-cutover"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$WEB/frontend/data/vehicles.json")" = "$PUBLIC_HASH" ] || fail "second prepare changed the public master"

printf '%s\n' 'private-upload-bytes' >"$PRIV/uploads/quotes/quote.txt"
UPLOAD_HASH="$(php -r 'echo hash_file("sha256", $argv[1]);' "$PRIV/uploads/quotes/quote.txt")"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "0" ] || fail "no-clobber prepare failed"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$PRIV/uploads/quotes/quote.txt")" = "$UPLOAD_HASH" ] || fail "prepare replaced an existing private upload"

php -r '
  $payload = json_encode(["vehicles" => [["ref_id" => "SYN-002", "note" => "DIVERGED-MARKER"]]], JSON_UNESCAPED_SLASHES);
  if (!is_string($payload) || file_put_contents($argv[1], $payload) === false) { exit(1); }
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
cmp -s "$PRIV/archive/pre-cutover/vehicles.json" "$PRIV/vehicles.json" || fail "rollback did not restore pre-cutover"
cmp -s "$WEB/frontend/data/vehicles.json" "$PRIV/archive/pre-cutover/vehicles.json" || fail "rollback removed the public or pre-cutover copy"
ROLLBACK_SAVED="$(find "$PRIV/archive" -maxdepth 1 -type f -name 'before-rollback-*.json' -print)"
[ -n "$ROLLBACK_SAVED" ] || fail "rollback archive was not created"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$ROLLBACK_SAVED")" = "$DIVERGED_HASH" ] || fail "rollback archive does not keep the replaced private master"
[ "$(php -r 'echo hash_file("sha256", $argv[1]);' "$WEB/frontend/data/vehicles.json")" = "$PUBLIC_HASH" ] || fail "rollback changed the public master"

STATUS="$(run_script check "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "0" ] || fail "check failed after rollback"
require_line "$OUT" "check_ok"
rm -f -- "$PRIV/vehicles.json"
STATUS="$(run_script check "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "check succeeded without a private master"
require_line "$ERR" "private_master_missing"
[ -f "$WEB/frontend/data/vehicles.json" ] || fail "failed check removed the public master"

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
[ ! -e "$PRIV/vehicles.json" ] || fail "invalid JSON created a private master"
[ ! -d "$PRIV" ] || fail "invalid JSON created the private directory"

cleanup
BASE=""

BASE="$(mktemp -d)"
WEB="$BASE/web"
PRIV="$BASE/priv-parent/store"
mkdir -p "$WEB/frontend/data" "$WEB/admin" "$(dirname "$PRIV")"
write_vehicles "$WEB/frontend/data/vehicles.json" "SYN-001" ""
write_bank_config "$WEB/admin/quote-pdf-config.php" "" ""
OUT="$BASE/out"
ERR="$BASE/err"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "empty bank source was accepted"
require_line "$ERR" "invoice_bank=empty_source"
[ ! -e "$PRIV/vehicles.json" ] || fail "empty bank created a private master"
[ ! -d "$PRIV" ] || fail "empty bank created the private directory"

cleanup
BASE=""

BASE="$(mktemp -d)"
WEB="$BASE/web"
PRIV="$BASE/priv-parent/store"
mkdir -p "$WEB/frontend/data" "$(dirname "$PRIV")"
printf '%s\n' '{"vehicles":[{"ref_id":"SYN-001"}]}' >"$WEB/frontend/data/vehicles-real.json"
ln -s vehicles-real.json "$WEB/frontend/data/vehicles.json"
OUT="$BASE/out"
ERR="$BASE/err"
STATUS="$(run_script prepare "$WEB" "$PRIV" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "symlink master was accepted"
require_line "$ERR" "public_master_missing"
[ ! -e "$PRIV" ] || fail "symlink master created the private directory"

cleanup
BASE=""

BASE="$(mktemp -d)"
mkdir -p "$BASE/web/frontend/data" "$BASE/priv-parent"
printf '%s\n' '{"vehicles":[{"ref_id":"SYN-001"}]}' >"$BASE/web/frontend/data/vehicles.json"
ln -s "$BASE/web" "$BASE/web-link"
OUT="$BASE/out"
ERR="$BASE/err"
STATUS="$(run_script prepare "$BASE/web-link" "$BASE/priv-parent/store" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "symlink web root was accepted"
require_line "$ERR" "web_root_not_directory"

cleanup
BASE=""

BASE="$(mktemp -d)"
OUT="$BASE/out"
ERR="$BASE/err"
STATUS="$(run_script prepare "$BASE/site" "$BASE/site/hidden" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "private directory inside the web root was accepted"
require_line "$ERR" "private_inside_web"
STATUS="$(run_script prepare "/home/gltr/www/gloria-site" "$BASE/store" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "production web root was accepted"
require_line "$ERR" "production_web_refused"
STATUS="$(run_script prepare "$BASE/web" "/home/gltr/private/west-africa" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "production private directory was accepted"
require_line "$ERR" "production_private_refused"
STATUS="$(run_script prepare "$BASE/web" "/home/gltr/private/west-africa/child" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "production private child was accepted"
require_line "$ERR" "production_private_refused"
STATUS="$(run_script prepare "/home/gltr/www/gloria-test" "$BASE/store" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "unpaired test web root was accepted"
require_line "$ERR" "test_paths_must_stay_paired"
STATUS="$(run_script prepare "$BASE/web" "/home/gltr/private/west-africa-test" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "unpaired test private directory was accepted"
require_line "$ERR" "test_paths_must_stay_paired"

if [ -e /home/gltr/www/gloria-test ] || [ -e /home/gltr/private/west-africa ] || [ -e /home/gltr/private/west-africa-test ]; then
  fail "refusing to run against a live Sakura path from this machine"
fi
STATUS="$(run_script prepare "/home/gltr/www/gloria-test" "/home/gltr/private/west-africa-test" "$OUT" "$ERR")"
[ "$STATUS" = "1" ] || fail "missing live test web root was treated as success"
[ ! -e /home/gltr/private/west-africa-test ] || fail "prepare created the live test private directory"
[ ! -e /home/gltr/private/west-africa ] || fail "prepare created the production private directory"

cleanup
rm -f -- "$OUT" "$ERR"
BASE=""

printf '%s\n' "prepare script checks passed"
