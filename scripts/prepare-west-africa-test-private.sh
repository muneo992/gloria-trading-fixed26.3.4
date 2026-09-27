#!/usr/bin/env bash
# Prepare or roll back the West Africa TEST private data directory.
# The script prints status tokens only. It must not print file contents.
set -euo pipefail
set +x
umask 077

MODE="${1:-}"
WEB="${2:-}"
PRIV="${3:-}"
PROD_WEB_ARG="${4:-}"

LIST_FILE=""
MERGE_FILE=""
PHP_FILE=""

cleanup() {
  if [ -n "$LIST_FILE" ]; then
    rm -f -- "$LIST_FILE"
  fi
  if [ -n "$MERGE_FILE" ]; then
    rm -f -- "$MERGE_FILE"
  fi
  if [ -n "$PHP_FILE" ]; then
    rm -f -- "$PHP_FILE"
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

assert_token() {
  local value="$1"
  local label="$2"
  if [[ ! "$value" =~ ^[A-Za-z0-9_.=-]+$ ]]; then
    die "$label"
  fi
}

assert_paths() {
  if [ "$MODE" != "prepare" ] && [ "$MODE" != "rollback" ] && [ "$MODE" != "check" ] && [ "$MODE" != "append-missing" ]; then
    die "mode_invalid"
  fi
  if [ -z "$WEB" ] || [ -z "$PRIV" ]; then
    die "path_missing"
  fi
  case "$WEB" in
    *".."*|*$'\n'*|*' '*) die "web_path_invalid" ;;
  esac
  case "$PRIV" in
    *".."*|*$'\n'*|*' '*) die "private_path_invalid" ;;
  esac
  case "$WEB" in
    /home/gltr/www/gloria-site|/home/gltr/www/gloria-site/*) die "production_web_refused" ;;
  esac
  case "$PRIV" in
    /home/gltr/private/west-africa|/home/gltr/private/west-africa/*) die "production_private_refused" ;;
  esac
  case "$PRIV" in
    "$WEB"|"$WEB"/*) die "private_inside_web" ;;
  esac
  if [ "$WEB" = "/home/gltr/www/gloria-test" ] && [ "$PRIV" != "/home/gltr/private/west-africa-test" ]; then
    die "test_paths_must_stay_paired"
  fi
  if [ "$PRIV" = "/home/gltr/private/west-africa-test" ] && [ "$WEB" != "/home/gltr/www/gloria-test" ]; then
    die "test_paths_must_stay_paired"
  fi
}

assert_production_absent() {
  if [ "$WEB" != "/home/gltr/www/gloria-test" ]; then
    return 0
  fi
  if [ -e /home/gltr/private/west-africa ] || [ -L /home/gltr/private/west-africa ]; then
    die "production_private_exists"
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

json_vehicle_count() {
  local path="$1"
  local summary
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
    $count = 0;
    foreach ($data["vehicles"] as $row) {
        if (!is_array($row) || !is_string($row["ref_id"] ?? null) || $row["ref_id"] === "") {
            exit(1);
        }
        $count++;
    }
    if ($count < 1) {
        exit(1);
    }
    echo "vehicles=" . $count;
  ' "$path" 2>/dev/null || true)"
  if [[ ! "$summary" =~ ^vehicles=[0-9]+$ ]]; then
    die "json_invalid"
  fi
  printf '%s\n' "$summary"
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
  if [ "$PRIV" = "/home/gltr/private/west-africa-test" ]; then
    assert_real_directory "$PRIV" "private_root"
    assert_real_directory "$WEB" "web_root"
  fi
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

copy_optional_file() {
  local src="$1"
  local dest="$2"
  local label="$3"
  if [ ! -e "$src" ]; then
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
  assert_production_absent
  assert_real_directory "$WEB" "web_root"
  assert_regular_file "$source" "public_master"
  local summary
  summary="$(json_vehicle_count "$source")"
  log "source_$summary"
  if [ ! -e "$PRIV/invoice-bank.php" ]; then
    require_bank_source
  else
    log "invoice_bank=already_present"
  fi
  make_private_dirs
  assert_production_absent
  local archive="$PRIV/archive/pre-cutover/vehicles.json"
  if [ ! -e "$archive" ]; then
    install_bytes "$source" "$archive"
    log "pre_cutover=created"
  else
    if [ -L "$archive" ]; then
      die "pre_cutover=symlink"
    fi
    json_vehicle_count "$archive" >/dev/null
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
  copy_optional_file "$WEB/admin/password.txt" "$PRIV/password.txt" "password_file"
  copy_optional_file "$WEB/frontend/data/proforma-invoice-sequence.json" "$PRIV/invoice-sequence.json" "sequence_file"
  copy_tree_no_clobber "$WEB/frontend/uploads" "$PRIV/uploads" "uploads"
  copy_tree_no_clobber "$WEB/frontend/data/backup" "$PRIV/archive/pre-cutover/public-backup" "public_backup"
  summary="$(json_vehicle_count "$PRIV/vehicles.json")"
  log "private_$summary"
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
  log "source_sha256=$(file_sha256 "$source")"
  log "private_sha256=$(file_sha256 "$PRIV/vehicles.json")"
  log "pre_cutover_sha256=$(file_sha256 "$archive")"
  log "public_master=kept"
  assert_production_absent
  log "prepare_ok"
}

rollback_private() {
  local source="$WEB/frontend/data/vehicles.json"
  local archive="$PRIV/archive/pre-cutover/vehicles.json"
  local dest="$PRIV/vehicles.json"
  assert_production_absent
  assert_real_directory "$WEB" "web_root"
  assert_regular_directory_or_private
  assert_regular_file "$archive" "pre_cutover"
  assert_regular_file "$dest" "private_master"
  assert_regular_file "$source" "public_master"
  json_vehicle_count "$archive" >/dev/null
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
  assert_production_absent
  log "rollback_ok"
}

assert_regular_directory_or_private() {
  if [ ! -d "$PRIV" ] || [ -L "$PRIV" ]; then
    die "private_root_missing"
  fi
}

check_private() {
  local source="$WEB/frontend/data/vehicles.json"
  assert_production_absent
  assert_real_directory "$WEB" "web_root"
  assert_regular_directory_or_private
  assert_regular_file "$source" "public_master"
  assert_regular_file "$PRIV/vehicles.json" "private_master"
  local summary
  summary="$(json_vehicle_count "$PRIV/vehicles.json")"
  json_vehicle_count "$source" >/dev/null
  if ! mode_is "$PRIV/vehicles.json" 600; then
    die "private_master=mode"
  fi
  log "private_$summary"
  log "public_master=kept"
  assert_production_absent
  log "check_ok"
}

append_missing() {
  local prod_web prod_json merge stamp saved line
  if [ "${GLORIA_APPEND_FIXTURE:-}" = "1" ]; then
    prod_web="$PROD_WEB_ARG"
    case "$prod_web" in
      ""|/home/gltr/*|*".."*) die "append_paths_refused" ;;
    esac
    case "$WEB" in
      /home/gltr/*) die "append_paths_refused" ;;
    esac
    case "$PRIV" in
      /home/gltr/*) die "append_paths_refused" ;;
    esac
  else
    if [ "$WEB" != "/home/gltr/www/gloria-test" ] || [ "$PRIV" != "/home/gltr/private/west-africa-test" ]; then
      die "append_paths_refused"
    fi
    prod_web="/home/gltr/www/gloria-site"
  fi
  prod_json="$prod_web/frontend/data/vehicles.json"
  assert_regular_file "$prod_json" "production_master"
  assert_regular_file "$PRIV/vehicles.json" "private_master"
  assert_real_directory "$WEB" "web_root"
  local before_prod before_public
  before_prod="$(file_sha256 "$prod_json")"
  if [ -f "$WEB/frontend/data/vehicles.json" ] && [ ! -L "$WEB/frontend/data/vehicles.json" ]; then
    before_public="$(file_sha256 "$WEB/frontend/data/vehicles.json")"
  fi
  stamp="$(date +%Y%m%d-%H%M%S)"
  saved="$PRIV/archive/before-append-$stamp.json"
  local n=0
  while [ -e "$saved" ]; do
    n=$((n + 1))
    saved="$PRIV/archive/before-append-$stamp-$n.json"
  done
  install_bytes "$PRIV/vehicles.json" "$saved"
  MERGE_FILE="$PRIV/vehicles.json.appending"
  PHP_FILE="$(mktemp)"
  cat >"$PHP_FILE" <<'PHP'
<?php
$prodPath = $argv[1] ?? '';
$testPath = $argv[2] ?? '';
$outPath = $argv[3] ?? '';
$fixture = getenv('GLORIA_APPEND_FIXTURE') === '1';
$liveProd = '/home/gltr/www/gloria-site/frontend/data/vehicles.json';
$liveTest = '/home/gltr/private/west-africa-test/vehicles.json';
if (!$fixture) {
    if ($prodPath !== $liveProd || $testPath !== $liveTest || $outPath !== $liveTest . '.appending') {
        fwrite(STDERR, "append_path_refused\n");
        exit(1);
    }
} elseif ($prodPath === '' || str_starts_with($prodPath, '/home/gltr/') || str_starts_with($testPath, '/home/gltr/') || str_starts_with($outPath, '/home/gltr/') || str_contains($prodPath, '..') || str_contains($testPath, '..') || str_contains($outPath, '..')) {
    fwrite(STDERR, "append_path_refused\n");
    exit(1);
}
$load = static function (string $path): array {
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('unreadable');
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['vehicles']) || !is_array($data['vehicles'])) {
        throw new RuntimeException('invalid');
    }
    return $data;
};
try {
    $prod = $load($prodPath);
    $test = $load($testPath);
} catch (Throwable $exception) {
    fwrite(STDERR, "append_json_invalid\n");
    exit(1);
}
$ordered = [];
$index = [];
foreach ($test['vehicles'] as $row) {
    $ref = $row['ref_id'] ?? null;
    if (!is_array($row) || !is_string($ref) || !preg_match('/^REF-[0-9]{3}$/', $ref) || isset($index[$ref])) {
        fwrite(STDERR, "append_ref_invalid\n");
        exit(1);
    }
    $index[$ref] = count($ordered);
    $ordered[] = $row;
}
$originalCount = count($ordered);
$added = [];
$images = [];
foreach ($prod['vehicles'] as $row) {
    $ref = $row['ref_id'] ?? null;
    if (!is_array($row) || !is_string($ref) || !preg_match('/^REF-[0-9]{3}$/', $ref)) {
        fwrite(STDERR, "append_ref_invalid\n");
        exit(1);
    }
    if (isset($index[$ref])) {
        continue;
    }
    $added[] = $row;
    $index[$ref] = count($ordered);
    $ordered[] = $row;
    $gallery = $row['gallery'] ?? [];
    if (!is_array($gallery)) {
        fwrite(STDERR, "append_gallery_invalid\n");
        exit(1);
    }
    foreach ($gallery as $path) {
        if (!is_string($path) || !preg_match('#^images/vehicles/[A-Za-z0-9._-]+$#', $path)) {
            fwrite(STDERR, "append_gallery_invalid\n");
            exit(1);
        }
        $images[$path] = true;
    }
}
if ($added === []) {
    fwrite(STDERR, "append_none\n");
    exit(2);
}
for ($i = 0; $i < $originalCount; $i++) {
    if ($ordered[$i] !== $test['vehicles'][$i]) {
        fwrite(STDERR, "append_existing_changed\n");
        exit(1);
    }
}
$test['vehicles'] = $ordered;
$encoded = json_encode($test, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($outPath, $encoded . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "append_write_failed\n");
    exit(1);
}
chmod($outPath, 0600);
echo 'appended=' . count($added) . "\n";
foreach ($added as $row) {
    echo 'appended_ref=' . $row['ref_id'] . "\n";
}
foreach (array_keys($images) as $path) {
    echo 'image=' . $path . "\n";
}
echo "existing_unchanged=yes\n";
echo 'private_vehicles=' . count($ordered) . "\n";
echo "merge_ok\n";
PHP
  local out err
  out="$(mktemp)"
  err="$(mktemp)"
  set +e
  GLORIA_APPEND_FIXTURE="${GLORIA_APPEND_FIXTURE:-}" php -d display_errors=0 -d log_errors=0 "$PHP_FILE" "$prod_json" "$PRIV/vehicles.json" "$MERGE_FILE" >"$out" 2>"$err"
  local status=$?
  set -e
  if [ "$status" -eq 2 ]; then
    rm -f -- "$out" "$err" "$MERGE_FILE"
    MERGE_FILE=""
    die "append_none"
  fi
  if [ "$status" -ne 0 ]; then
    rm -f -- "$out" "$err" "$MERGE_FILE"
    MERGE_FILE=""
    die "append_failed"
  fi
  while IFS= read -r line || [ -n "$line" ]; do
    [ -z "$line" ] && continue
    if [[ ! "$line" =~ ^(appended=[0-9]+|appended_ref=REF-[0-9]{3}|image=images/vehicles/[A-Za-z0-9._-]+|existing_unchanged=yes|private_vehicles=[0-9]+|merge_ok)$ ]]; then
      rm -f -- "$out" "$err" "$MERGE_FILE"
      MERGE_FILE=""
      die "append_output_rejected"
    fi
  done <"$out"
  if ! grep -F -x -q "merge_ok" "$out"; then
    rm -f -- "$out" "$err"
    die "append_failed"
  fi
  local copied=0 kept=0 absent=0
  while IFS= read -r line; do
    case "$line" in
      image=*)
        local rel="${line#image=}"
        local src="$prod_web/frontend/$rel"
        if [ ! -f "$src" ] || [ -L "$src" ]; then
          absent=$((absent + 1))
          continue
        fi
        local dest="$WEB/frontend/$rel"
        if [ -e "$dest" ]; then
          kept=$((kept + 1))
          continue
        fi
        mkdir -p "$(dirname "$dest")"
        local img_tmp="${dest}.partial"
        if ! cp -- "$src" "$img_tmp" 2>/dev/null; then
          rm -f -- "$img_tmp"
          die "copy_failed"
        fi
        chmod 644 "$img_tmp" 2>/dev/null || {
          rm -f -- "$img_tmp"
          die "chmod_failed"
        }
        if ! cmp -s "$src" "$img_tmp"; then
          rm -f -- "$img_tmp"
          die "copy_mismatch"
        fi
        if ! mv -f "$img_tmp" "$dest" 2>/dev/null; then
          rm -f -- "$img_tmp"
          die "copy_failed"
        fi
        copied=$((copied + 1))
        ;;
      appended=*|appended_ref=*|existing_unchanged=yes|private_vehicles=*|merge_ok)
        log "$line"
        ;;
    esac
  done <"$out"
  log "images_copied=$copied"
  log "images_kept=$kept"
  log "images_absent=$absent"
  install_bytes "$MERGE_FILE" "$PRIV/vehicles.json"
  rm -f -- "$MERGE_FILE"
  MERGE_FILE=""
  if [ "$(file_sha256 "$prod_json")" != "$before_prod" ]; then
    die "production_source_changed"
  fi
  if [ -n "${before_public:-}" ] && [ "$(file_sha256 "$WEB/frontend/data/vehicles.json")" != "$before_public" ]; then
    die "public_master_changed"
  fi
  json_vehicle_count "$PRIV/vehicles.json" >/dev/null
  log "append_backup=created"
  log "public_master=kept"
  log "append_ok"
  rm -f -- "$out" "$err" "$PHP_FILE"
  PHP_FILE=""
}

assert_paths
case "$MODE" in
  prepare) prepare_private ;;
  rollback) rollback_private ;;
  check) check_private ;;
  append-missing) append_missing ;;
  *) die "mode_invalid" ;;
esac
