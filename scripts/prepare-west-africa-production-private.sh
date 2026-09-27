#!/usr/bin/env bash
# Copy the live West Africa production master into the private directory.
# The script runs on Sakura over SSH. It prints status tokens only.
# It must not print file contents, and it must not send operational data to GitHub.
set -euo pipefail
set +x
umask 077

MODE="${1:-}"
WEB="${2:-}"
PRIV="${3:-}"

LIVE_WEB="/home/gltr/www/gloria-site"
LIVE_PRIV="/home/gltr/private/west-africa"
LIVE_SOURCE="$LIVE_WEB/frontend/data/vehicles.json"

LIST_FILE=""

cleanup() {
  if [ -n "$LIST_FILE" ]; then
    rm -f -- "$LIST_FILE"
  fi
}

trap cleanup EXIT

log() {
  printf '%s\n' "$1"
}

die() {
  printf '%s\n' "$1" >&2
  exit 1
}

assert_paths() {
  if [ "$MODE" != "prepare" ] && [ "$MODE" != "rollback" ] && [ "$MODE" != "check" ]; then
    die "mode_invalid"
  fi
  if [ -z "$WEB" ] || [ -z "$PRIV" ]; then
    die "path_missing"
  fi
  case "$WEB" in
    *".."*|*$'\n'*|*' '*|*-test|*-test/*) die "web_path_invalid" ;;
  esac
  case "$PRIV" in
    *".."*|*$'\n'*|*' '*|*-test|*-test/*) die "private_path_invalid" ;;
  esac
  case "$WEB" in
    *gloria-test*|*west-africa-test*) die "test_paths_refused" ;;
  esac
  case "$PRIV" in
    *gloria-test*|*west-africa-test*) die "test_paths_refused" ;;
  esac
  case "$PRIV" in
    "$WEB"|"$WEB"/*) die "private_inside_web" ;;
  esac
  if [ "${GLORIA_PRODUCTION_PREPARE_FIXTURE:-}" = "1" ]; then
    case "$WEB" in
      /home/gltr|/home/gltr/*) die "fixture_path_refused" ;;
    esac
    case "$PRIV" in
      /home/gltr|/home/gltr/*) die "fixture_path_refused" ;;
    esac
    return 0
  fi
  if [ "$WEB" != "$LIVE_WEB" ] || [ "$PRIV" != "$LIVE_PRIV" ]; then
    die "production_paths_required"
  fi
}

assert_real_directory() {
  local path="$1"
  local label="$2"
  if [ -L "$path" ] || [ ! -d "$path" ]; then
    die "${label}_not_directory"
  fi
  local resolved
  resolved="$(php -d display_errors=0 -d log_errors=0 -r 'echo realpath($argv[1]) ?: "";' "$path" 2>/dev/null || true)"
  if [ "$resolved" != "$path" ]; then
    die "${label}_resolved_unexpectedly"
  fi
}

assert_regular_file() {
  local path="$1"
  local label="$2"
  if [ -L "$path" ] || [ ! -f "$path" ]; then
    die "${label}_missing"
  fi
}

assert_source_is_local_master() {
  local source="$1"
  local resolved
  resolved="$(php -d display_errors=0 -d log_errors=0 -r 'echo realpath($argv[1]) ?: "";' "$source" 2>/dev/null || true)"
  case "$resolved" in
    *west-africa-test*|*gloria-test*) die "test_paths_refused" ;;
  esac
  if [ "${GLORIA_PRODUCTION_PREPARE_FIXTURE:-}" = "1" ]; then
    case "$resolved" in
      /home/gltr/*) die "fixture_path_refused" ;;
    esac
    return 0
  fi
  if [ "$resolved" != "$LIVE_SOURCE" ]; then
    die "production_source_required"
  fi
}

json_vehicle_count() {
  local path="$1"
  local summary status
  set +e
  summary="$(php -d display_errors=0 -d log_errors=0 -r '
    $raw = file_get_contents($argv[1]);
    if (!is_string($raw) || $raw === "") {
        exit(1);
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        exit(1);
    }
    if (!is_array($data) || !isset($data["vehicles"]) || !is_array($data["vehicles"])) {
        exit(1);
    }
    $ids = [];
    foreach ($data["vehicles"] as $row) {
        $ref = is_array($row) ? ($row["ref_id"] ?? null) : null;
        if (!is_string($ref) || preg_match("/^REF-[0-9]{3}$/", $ref) !== 1 || isset($ids[$ref])) {
            exit(1);
        }
        $ids[$ref] = true;
    }
    $got = array_keys($ids);
    $want = [];
    for ($i = 1; $i <= 26; $i++) {
        $want[] = sprintf("REF-%03d", $i);
    }
    sort($got);
    sort($want);
    echo "vehicles=" . count($got);
    if ($got !== $want) {
        exit(count($got) === 26 ? 3 : 0);
    }
  ' "$path" 2>/dev/null)"
  status=$?
  set -e
  if [ "$status" -eq 3 ]; then
    die "ref_set_unexpected"
  fi
  if [ "$status" -ne 0 ] || [[ ! "$summary" =~ ^vehicles=[0-9]+$ ]]; then
    die "json_invalid"
  fi
  printf '%s\n' "$summary"
}

require_count_26() {
  local summary="$1"
  if [ "$summary" != "vehicles=26" ]; then
    die "vehicle_count_unexpected"
  fi
}

file_sha256() {
  local path="$1"
  local hash
  hash="$(php -d display_errors=0 -d log_errors=0 -r 'echo hash_file("sha256", $argv[1]) ?: "";' "$path" 2>/dev/null || true)"
  if [[ ! "$hash" =~ ^[a-f0-9]{64}$ ]]; then
    die "hash_failed"
  fi
  printf '%s\n' "$hash"
}

mode_is() {
  local path="$1"
  local expected="$2"
  php -d display_errors=0 -d log_errors=0 -r '
    $mode = fileperms($argv[1]) & 0777;
    exit($mode === octdec($argv[2]) ? 0 : 1);
  ' "$path" "$expected" 2>/dev/null
}

install_bytes() {
  local src="$1"
  local dest="$2"
  local tmp="${dest}.partial"
  if ! cp -p "$src" "$tmp" 2>/dev/null; then
    rm -f -- "$tmp"
    die "copy_failed"
  fi
  chmod 600 "$tmp" 2>/dev/null || {
    rm -f -- "$tmp"
    die "chmod_failed"
  }
  if ! cmp -s "$src" "$tmp"; then
    rm -f -- "$tmp"
    die "copy_mismatch"
  fi
  if ! mv -f "$tmp" "$dest" 2>/dev/null; then
    rm -f -- "$tmp"
    die "copy_failed"
  fi
  chmod 600 "$dest" 2>/dev/null || die "chmod_failed"
  if ! cmp -s "$src" "$dest"; then
    die "copy_mismatch"
  fi
}

make_private_dirs() {
  local dir
  for dir in \
    "$(dirname "$PRIV")" \
    "$PRIV" \
    "$PRIV/backups" \
    "$PRIV/uploads" \
    "$PRIV/uploads/quotes" \
    "$PRIV/uploads/certificates" \
    "$PRIV/uploads/general" \
    "$PRIV/archive" \
    "$PRIV/archive/pre-cutover" \
    "$PRIV/archive/backups"
  do
    case "$dir" in
      *west-africa-test*|*gloria-test*) die "test_paths_refused" ;;
    esac
    if [ -L "$dir" ]; then
      die "directory_symlink"
    fi
    if [ ! -d "$dir" ] && ! mkdir -m 700 "$dir" 2>/dev/null; then
      die "mkdir_failed"
    fi
    chmod 700 "$dir" 2>/dev/null || die "chmod_failed"
    if ! mode_is "$dir" 700; then
      die "directory_mode"
    fi
  done
  assert_real_directory "$PRIV" "private_root"
  assert_real_directory "$WEB" "web_root"
}

bank_php() {
  local action="$1"
  local config="$WEB/admin/quote-pdf-config.php"
  local dest="$PRIV/invoice-bank.php"
  php -d display_errors=0 -d log_errors=0 -r '
    $action = $argv[1];
    $configPath = $argv[2];
    $dest = $argv[3];
    $keys = ["bank_name", "swift_code", "branch_name", "branch_phone", "account_name", "account_number", "branch_address"];
    if ($action === "write" && is_file($dest) && !is_link($dest)) {
        exit(0);
    }
    if (!is_file($configPath) || is_link($configPath)) {
        exit(1);
    }
    try {
        $config = require $configPath;
    } catch (Throwable $exception) {
        exit(1);
    }
    $bank = is_array($config) ? ($config["bank"] ?? null) : null;
    if (!is_array($bank)) {
        exit(1);
    }
    $clean = [];
    $nonEmpty = 0;
    foreach ($keys as $key) {
        $value = $bank[$key] ?? "";
        if (!is_string($value) || strlen($value) > 200) {
            exit(1);
        }
        if ($value !== "") {
            $nonEmpty++;
        }
        $clean[$key] = $value;
    }
    if ($nonEmpty === 0) {
        exit(2);
    }
    if ($action !== "write") {
        exit(0);
    }
    $encoded = "<?php\nreturn " . var_export($clean, true) . ";\n";
    $tmp = $dest . ".partial";
    if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
        @unlink($tmp);
        exit(1);
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $dest)) {
        @unlink($tmp);
        exit(1);
    }
    chmod($dest, 0600);
    exit(0);
  ' "$action" "$config" "$dest" >/dev/null 2>/dev/null
}

require_bank_source() {
  set +e
  bank_php check
  local status=$?
  set -e
  if [ "$status" -eq 0 ]; then
    log "invoice_bank=source_ok"
    return 0
  fi
  if [ "$status" -eq 2 ]; then
    die "invoice_bank=empty_source"
  fi
  die "invoice_bank=unreadable"
}

write_bank() {
  if [ -e "$PRIV/invoice-bank.php" ]; then
    if [ -L "$PRIV/invoice-bank.php" ]; then
      die "invoice_bank=symlink"
    fi
    chmod 600 "$PRIV/invoice-bank.php" 2>/dev/null || die "chmod_failed"
    if ! mode_is "$PRIV/invoice-bank.php" 600; then
      die "invoice_bank=mode"
    fi
    log "invoice_bank=kept"
    return 0
  fi
  set +e
  bank_php write
  local status=$?
  set -e
  if [ "$status" -eq 2 ]; then
    die "invoice_bank=empty_source"
  fi
  if [ "$status" -ne 0 ] || [ ! -f "$PRIV/invoice-bank.php" ] || [ -L "$PRIV/invoice-bank.php" ]; then
    die "invoice_bank=write_failed"
  fi
  if ! mode_is "$PRIV/invoice-bank.php" 600; then
    die "invoice_bank=mode"
  fi
  log "invoice_bank=written"
}

require_password_source() {
  local src="$WEB/admin/password.txt"
  if [ ! -e "$src" ]; then
    die "password_file=absent"
  fi
  if [ -L "$src" ] || [ ! -f "$src" ]; then
    die "password_file=symlink"
  fi
  if [ ! -s "$src" ]; then
    die "password_file=empty"
  fi
  log "password_file=source_ok"
}

copy_private_file() {
  local src="$1"
  local dest="$2"
  local label="$3"
  local required="$4"
  if [ ! -e "$src" ]; then
    if [ "$required" = "yes" ]; then
      die "${label}=absent"
    fi
    log "${label}=absent"
    return 0
  fi
  if [ -L "$src" ] || [ ! -f "$src" ]; then
    die "${label}=symlink"
  fi
  if [ -e "$dest" ]; then
    if [ -L "$dest" ]; then
      die "${label}=symlink"
    fi
    chmod 600 "$dest" 2>/dev/null || die "chmod_failed"
    log "${label}=kept"
    return 0
  fi
  install_bytes "$src" "$dest"
  log "${label}=copied"
}

copy_tree_no_clobber() {
  local src="$1"
  local dest="$2"
  local label="$3"
  if [ ! -e "$src" ]; then
    log "${label}=absent"
    return 0
  fi
  if [ -L "$src" ] || [ ! -d "$src" ]; then
    die "${label}=symlink"
  fi
  if [ -e "$dest" ] && [ -L "$dest" ]; then
    die "${label}=symlink"
  fi
  mkdir -p "$dest"
  chmod 700 "$dest" 2>/dev/null || die "chmod_failed"
  LIST_FILE="$(mktemp)"
  if ! find "$src" \( -type f -o -type l \) -print0 >"$LIST_FILE" 2>/dev/null; then
    die "${label}=unreadable"
  fi
  local copied=0
  local kept=0
  local file rel target
  while IFS= read -r -d '' file; do
    if [ -L "$file" ]; then
      die "${label}=symlink"
    fi
    rel="${file#"$src"/}"
    case "$rel" in
      ""|*..*) die "${label}=bad_path" ;;
    esac
    target="$dest/$rel"
    mkdir -p "$(dirname "$target")"
    chmod 700 "$(dirname "$target")" 2>/dev/null || die "chmod_failed"
    if [ -e "$target" ]; then
      kept=$((kept + 1))
    else
      install_bytes "$file" "$target"
      copied=$((copied + 1))
    fi
  done <"$LIST_FILE"
  rm -f -- "$LIST_FILE"
  LIST_FILE=""
  find "$dest" -type d -exec chmod 700 {} + 2>/dev/null || die "chmod_failed"
  find "$dest" -type f -exec chmod 600 {} + 2>/dev/null || die "chmod_failed"
  log "${label}_copied=$copied"
  log "${label}_kept=$kept"
}

prepare_private() {
  local source="$WEB/frontend/data/vehicles.json"
  assert_real_directory "$WEB" "web_root"
  assert_regular_file "$source" "public_master"
  assert_source_is_local_master "$source"
  local summary
  summary="$(json_vehicle_count "$source")"
  log "source_$summary"
  require_count_26 "$summary"
  require_password_source
  if [ ! -e "$PRIV/invoice-bank.php" ]; then
    require_bank_source
  else
    log "invoice_bank=already_present"
  fi
  make_private_dirs
  assert_source_is_local_master "$source"
  local archive="$PRIV/archive/pre-cutover/vehicles.json"
  if [ ! -e "$archive" ]; then
    install_bytes "$source" "$archive"
    log "pre_cutover=created"
  else
    if [ -L "$archive" ]; then
      die "pre_cutover=symlink"
    fi
    require_count_26 "$(json_vehicle_count "$archive")"
    log "pre_cutover=kept"
  fi
  write_bank
  if [ -e "$PRIV/vehicles.json" ]; then
    if [ -L "$PRIV/vehicles.json" ]; then
      die "private_master=symlink"
    fi
    if ! cmp -s "$source" "$PRIV/vehicles.json"; then
      die "private_master=differs"
    fi
    chmod 600 "$PRIV/vehicles.json" 2>/dev/null || die "chmod_failed"
    log "private_master=already_matches"
  else
    install_bytes "$source" "$PRIV/vehicles.json"
    log "private_master=copied"
  fi
  copy_private_file "$WEB/admin/password.txt" "$PRIV/password.txt" "password_file" "yes"
  copy_private_file "$WEB/frontend/data/proforma-invoice-sequence.json" "$PRIV/invoice-sequence.json" "sequence_file" "no"
  copy_tree_no_clobber "$WEB/frontend/uploads" "$PRIV/uploads" "uploads"
  copy_tree_no_clobber "$WEB/frontend/data/backup" "$PRIV/archive/pre-cutover/public-backup" "public_backup"
  summary="$(json_vehicle_count "$PRIV/vehicles.json")"
  log "private_$summary"
  require_count_26 "$summary"
  if ! cmp -s "$source" "$PRIV/vehicles.json"; then
    die "private_master=mismatch"
  fi
  if [ ! -f "$source" ] || [ -L "$source" ]; then
    die "public_master_missing_after_copy"
  fi
  if ! mode_is "$PRIV/vehicles.json" 600; then
    die "private_master=mode"
  fi
  if ! mode_is "$archive" 600; then
    die "pre_cutover=mode"
  fi
  if ! mode_is "$PRIV/password.txt" 600; then
    die "password_file=mode"
  fi
  log "source_sha256=$(file_sha256 "$source")"
  log "private_sha256=$(file_sha256 "$PRIV/vehicles.json")"
  log "pre_cutover_sha256=$(file_sha256 "$archive")"
  log "public_master=kept"
  log "prepare_ok"
}

rollback_private() {
  local source="$WEB/frontend/data/vehicles.json"
  local archive="$PRIV/archive/pre-cutover/vehicles.json"
  local dest="$PRIV/vehicles.json"
  assert_real_directory "$WEB" "web_root"
  if [ ! -d "$PRIV" ] || [ -L "$PRIV" ]; then
    die "private_root_missing"
  fi
  assert_regular_file "$archive" "pre_cutover"
  assert_regular_file "$dest" "private_master"
  assert_regular_file "$source" "public_master"
  assert_source_is_local_master "$source"
  require_count_26 "$(json_vehicle_count "$archive")"
  local stamp saved n=0
  stamp="$(date +%Y%m%d-%H%M%S)"
  saved="$PRIV/archive/before-rollback-$stamp.json"
  while [ -e "$saved" ]; do
    n=$((n + 1))
    saved="$PRIV/archive/before-rollback-$stamp-$n.json"
  done
  install_bytes "$dest" "$saved"
  install_bytes "$archive" "$dest"
  if ! cmp -s "$archive" "$dest"; then
    die "rollback_mismatch"
  fi
  if [ ! -f "$archive" ] || [ ! -f "$source" ] || [ ! -f "$saved" ]; then
    die "rollback_lost_a_copy"
  fi
  log "rollback_archive=created"
  log "private_$(json_vehicle_count "$dest")"
  log "private_sha256=$(file_sha256 "$dest")"
  log "pre_cutover_sha256=$(file_sha256 "$archive")"
  log "public_master=kept"
  log "rollback_ok"
}

check_private() {
  local source="$WEB/frontend/data/vehicles.json"
  assert_real_directory "$WEB" "web_root"
  if [ ! -d "$PRIV" ] || [ -L "$PRIV" ]; then
    die "private_root_missing"
  fi
  assert_regular_file "$source" "public_master"
  assert_source_is_local_master "$source"
  assert_regular_file "$PRIV/vehicles.json" "private_master"
  local summary
  summary="$(json_vehicle_count "$PRIV/vehicles.json")"
  log "private_$summary"
  require_count_26 "$summary"
  summary="$(json_vehicle_count "$source")"
  log "public_$summary"
  require_count_26 "$summary"
  if ! mode_is "$PRIV/vehicles.json" 600; then
    die "private_master=mode"
  fi
  if [ ! -f "$source" ] || [ -L "$source" ]; then
    die "public_master_missing"
  fi
  log "public_master=kept"
  log "check_ok"
}

assert_paths
case "$MODE" in
  prepare) prepare_private ;;
  rollback) rollback_private ;;
  check) check_private ;;
  *) die "mode_invalid" ;;
esac
