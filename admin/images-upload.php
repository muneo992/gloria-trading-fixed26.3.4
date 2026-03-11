<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

define('VEHICLES_JSON', __DIR__ . '/../data/vehicles.json');
define('IMAGES_DIR',    __DIR__ . '/../images/vehicles/');
define('IMAGES_URL',    '../images/vehicles/');

function loadVehicles() {
    if (!file_exists(VEHICLES_JSON)) return ['vehicles' => []];
    return json_decode(file_get_contents(VEHICLES_JSON), true) ?: ['vehicles' => []];
}
function saveVehicles($data) {
    return file_put_contents(VEHICLES_JSON, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// Ref IDを小文字・ハイフン形式に変換（例: REF-001 → ref-001）
function refToPrefix($ref_id) {
    return strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '', $ref_id));
}

// ファイル名からRef IDを推定（例: REF-001-1.jpg → REF-001）
function guessRefFromFilename($filename) {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    // パターン: ref-001-1, REF-001-2, ref001-1 など
    if (preg_match('/^(ref[-_]?\d+)/i', $base, $m)) {
        // 末尾の -数字 を除去
        $ref = preg_replace('/-\d+$/', '', $m[1]);
        return strtoupper(str_replace('_', '-', $ref));
    }
    return null;
}

$messages = [];
$errors   = [];
$data     = loadVehicles();
$vehicles = &$data['vehicles'];

// Ref ID一覧
$ref_ids = array_column($vehicles, 'ref_id');

if (!is_dir(IMAGES_DIR)) mkdir(IMAGES_DIR, 0755, true);

// =============================================
// A. ZIPファイル一括アップロード処理
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_zip') {
    if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'ZIPファイルのアップロードに失敗しました。';
    } else {
        $zip_tmp = $_FILES['zip_file']['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($zip_tmp) !== true) {
            $errors[] = 'ZIPファイルを開けませんでした。正しいZIPファイルか確認してください。';
        } else {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $extracted   = 0;
            $assigned    = 0;
            $unmatched   = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                // フォルダ・隠しファイルはスキップ
                if (substr($entry, -1) === '/' || strpos(basename($entry), '.') === 0) continue;
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext)) continue;

                $basename = basename($entry);
                $content  = $zip->getFromIndex($i);
                if ($content === false) continue;

                // ファイル名からRef IDを推定
                $guessed_ref = guessRefFromFilename($basename);

                // 保存先ファイル名を決定
                $safe_name = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $basename);
                $dest_path = IMAGES_DIR . $safe_name;
                $rel_path  = 'images/vehicles/' . $safe_name;

                // 同名ファイルが存在する場合は上書き（交換）
                file_put_contents($dest_path, $content);
                $extracted++;

                // vehicles.jsonへの自動割り当て
                if ($guessed_ref && in_array($guessed_ref, $ref_ids)) {
                    foreach ($vehicles as &$v) {
                        if ($v['ref_id'] === $guessed_ref) {
                            if (!isset($v['gallery'])) $v['gallery'] = [];
                            // 既存パスと重複しない場合のみ追加
                            if (!in_array($rel_path, $v['gallery'])) {
                                $v['gallery'][] = $rel_path;
                                $assigned++;
                            }
                            break;
                        }
                    }
                    unset($v);
                } else {
                    $unmatched[] = $basename;
                }
            }
            $zip->close();
            saveVehicles($data);

            $messages[] = "✅ {$extracted}枚の画像を展開しました。";
            if ($assigned > 0) $messages[] = "✅ {$assigned}枚を車両データに自動割り当てしました。";
            if (!empty($unmatched)) {
                $messages[] = "⚠ " . count($unmatched) . "枚はRef IDが特定できず未割り当てです：" . implode(', ', array_slice($unmatched, 0, 5)) . (count($unmatched) > 5 ? '...' : '');
            }
        }
    }
}

// =============================================
// B. FTPスキャン処理（既存ファイルを自動検出）
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan_ftp') {
    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $files = glob(IMAGES_DIR . '*');
    $scanned = 0;
    $assigned = 0;
    $unmatched = [];

    foreach ($files as $filepath) {
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) continue;

        $basename  = basename($filepath);
        $rel_path  = 'images/vehicles/' . $basename;
        $guessed   = guessRefFromFilename($basename);
        $scanned++;

        if ($guessed && in_array($guessed, $ref_ids)) {
            foreach ($vehicles as &$v) {
                if ($v['ref_id'] === $guessed) {
                    if (!isset($v['gallery'])) $v['gallery'] = [];
                    if (!in_array($rel_path, $v['gallery'])) {
                        $v['gallery'][] = $rel_path;
                        $assigned++;
                    }
                    break;
                }
            }
            unset($v);
        } else {
            $unmatched[] = $basename;
        }
    }
    saveVehicles($data);

    $messages[] = "✅ {$scanned}枚の画像ファイルをスキャンしました。";
    if ($assigned > 0) $messages[] = "✅ {$assigned}枚を新たに車両データに割り当てました。";
    if (!empty($unmatched)) {
        $messages[] = "⚠ " . count($unmatched) . "枚はRef IDが特定できず未割り当てです：" . implode(', ', array_slice($unmatched, 0, 5)) . (count($unmatched) > 5 ? '...' : '');
    }
}

// 再読み込み
$data     = loadVehicles();
$vehicles = $data['vehicles'];

// images/vehicles/ 内の全画像一覧
$all_images = [];
$allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
foreach (glob(IMAGES_DIR . '*') as $f) {
    if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed_ext)) {
        $all_images[] = 'images/vehicles/' . basename($f);
    }
}

// 割り当て済み画像のセット
$assigned_images = [];
foreach ($vehicles as $v) {
    foreach ($v['gallery'] ?? [] as $img) {
        $assigned_images[] = $img;
    }
}
$unassigned_images = array_diff($all_images, $assigned_images);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>画像一括管理 - Gloria Trading Admin</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #333; }
.topbar { background: #1a1a2e; color: #fff; padding: 0.9rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
.topbar .brand { font-size: 1.1rem; font-weight: 700; color: #4da6ff; }
.topbar .nav a { color: #ccc; text-decoration: none; font-size: 0.9rem; margin-left: 1.2rem; }
.topbar .nav a:hover { color: #fff; }
.container { max-width: 1000px; margin: 0 auto; padding: 1.5rem; }
.page-header { margin-bottom: 1.5rem; }
.page-header h1 { font-size: 1.4rem; color: #1a1a2e; }
.page-header p { color: #666; font-size: 0.9rem; margin-top: 0.3rem; }
.btn { display: inline-block; padding: 0.6rem 1.2rem; border-radius: 6px; font-size: 0.9rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
.btn-primary { background: #0066cc; color: #fff; }
.btn-primary:hover { background: #0052a3; }
.btn-success { background: #28a745; color: #fff; }
.btn-success:hover { background: #218838; }
.btn-warning { background: #ffc107; color: #333; }
.btn-warning:hover { background: #e0a800; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-secondary:hover { background: #5a6268; }
.card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 1.5rem; overflow: hidden; }
.card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #eee; font-weight: 600; color: #1a1a2e; background: #fafafa; display: flex; align-items: center; gap: 0.5rem; }
.card-body { padding: 1.5rem; }
.alert { padding: 0.85rem 1.1rem; border-radius: 6px; margin-bottom: 0.6rem; font-size: 0.9rem; }
.alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
.alert-warning { background: #fff8e1; border: 1px solid #ffe082; color: #856404; }
.alert-info { background: #e8f4fd; border: 1px solid #bee5eb; color: #0c5460; }

/* タブ */
.tabs { display: flex; border-bottom: 2px solid #eee; margin-bottom: 1.5rem; }
.tab { padding: 0.75rem 1.5rem; cursor: pointer; font-weight: 600; font-size: 0.9rem; color: #888; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; }
.tab.active { color: #0066cc; border-bottom-color: #0066cc; }
.tab-content { display: none; }
.tab-content.active { display: block; }

/* ZIPアップロードエリア */
.upload-zone { border: 2px dashed #ccc; border-radius: 10px; padding: 2.5rem; text-align: center; cursor: pointer; transition: all 0.2s; background: #fafafa; position: relative; }
.upload-zone:hover, .upload-zone.dragover { border-color: #0066cc; background: #f0f7ff; }
.upload-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.upload-zone .icon { font-size: 3rem; margin-bottom: 0.75rem; }
.upload-zone h3 { color: #333; margin-bottom: 0.4rem; }
.upload-zone p { color: #888; font-size: 0.9rem; }

/* 命名規則 */
.naming-rule { background: #f8f9fa; border: 1px solid #e5e5e5; border-radius: 8px; padding: 1.2rem; margin-top: 1rem; }
.naming-rule h4 { font-size: 0.9rem; margin-bottom: 0.6rem; color: #333; }
.naming-rule code { background: #e8f0fe; color: #1a56db; padding: 0.15rem 0.4rem; border-radius: 3px; font-size: 0.85rem; font-family: monospace; }
.naming-rule table { width: 100%; font-size: 0.85rem; margin-top: 0.5rem; }
.naming-rule th { background: #eee; padding: 0.4rem 0.6rem; }
.naming-rule td { padding: 0.4rem 0.6rem; border-bottom: 1px solid #f0f0f0; }

/* FTPスキャン */
.scan-box { background: #f8f9fa; border: 1px solid #e5e5e5; border-radius: 8px; padding: 1.5rem; }
.scan-box h4 { margin-bottom: 0.5rem; }
.scan-box p { font-size: 0.9rem; color: #555; margin-bottom: 1rem; }
.ftp-path { background: #1a1a2e; color: #4da6ff; padding: 0.6rem 1rem; border-radius: 6px; font-family: monospace; font-size: 0.9rem; margin: 0.5rem 0 1rem; }

/* 未割り当て画像 */
.img-grid { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem; }
.img-item { position: relative; width: 120px; text-align: center; }
.img-item img { width: 120px; height: 85px; object-fit: cover; border-radius: 6px; border: 2px solid #eee; }
.img-item .img-name { font-size: 0.7rem; color: #666; margin-top: 0.3rem; word-break: break-all; }
.img-item .assign-link { display: block; font-size: 0.72rem; color: #0066cc; text-decoration: none; margin-top: 0.2rem; }
.img-item .assign-link:hover { text-decoration: underline; }
.empty-msg { color: #999; font-size: 0.9rem; padding: 1.5rem; text-align: center; }

/* 統計 */
.stats-row { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.stat-box { background: #fff; border-radius: 8px; padding: 1rem 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.07); text-align: center; min-width: 120px; }
.stat-box .num { font-size: 1.8rem; font-weight: 700; color: #0066cc; }
.stat-box .lbl { font-size: 0.8rem; color: #666; }
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">Gloria Trading 管理画面</div>
  <div class="nav">
    <a href="index.php">← 車両一覧</a>
    <a href="images-assign.php">画像割り当て</a>
    <a href="../index.html" target="_blank">サイトを見る</a>
    <a href="index.php?logout=1">ログアウト</a>
  </div>
</div>

<div class="container">
  <div class="page-header">
    <h1>🖼 画像一括管理</h1>
    <p>ZIPで一括アップロード、またはFTPでアップロード済みの画像を自動スキャンして車両に割り当てます。</p>
  </div>

  <?php foreach ($messages as $msg): ?>
  <div class="alert <?= strpos($msg, '⚠') !== false ? 'alert-warning' : 'alert-success' ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $err): ?>
  <div class="alert" style="background:#fff0f0;border:1px solid #ffcccc;color:#cc0000;"><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <!-- 統計 -->
  <div class="stats-row">
    <div class="stat-box">
      <div class="num"><?= count($all_images) ?></div>
      <div class="lbl">総画像数</div>
    </div>
    <div class="stat-box">
      <div class="num"><?= count($assigned_images) ?></div>
      <div class="lbl">割り当て済み</div>
    </div>
    <div class="stat-box">
      <div class="num" style="color:<?= count($unassigned_images) > 0 ? '#ffc107' : '#28a745' ?>;"><?= count($unassigned_images) ?></div>
      <div class="lbl">未割り当て</div>
    </div>
    <div class="stat-box">
      <div class="num"><?= count(array_filter($vehicles, fn($v) => empty($v['gallery']))) ?></div>
      <div class="lbl">画像なし車両</div>
    </div>
  </div>

  <!-- タブ -->
  <div class="tabs">
    <div class="tab active" onclick="switchTab('zip')">📦 A. ZIP一括アップロード</div>
    <div class="tab" onclick="switchTab('ftp')">📡 B. FTPスキャン</div>
    <div class="tab" onclick="switchTab('unassigned')">🔍 C. 未割り当て画像 (<?= count($unassigned_images) ?>)</div>
  </div>

  <!-- タブA: ZIP -->
  <div class="tab-content active" id="tab-zip">
    <div class="card">
      <div class="card-header">📦 ZIPファイルで一括アップロード</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" id="zip-form">
          <input type="hidden" name="action" value="upload_zip">
          <div class="upload-zone" id="zip-zone">
            <input type="file" name="zip_file" id="zip-file" accept=".zip">
            <div class="icon">📦</div>
            <h3>ZIPファイルをドラッグ＆ドロップ</h3>
            <p>または クリックしてZIPファイルを選択</p>
          </div>
          <div id="zip-name" style="margin-top:0.75rem;font-size:0.9rem;color:#555;display:none;"></div>
          <div style="margin-top:1rem;">
            <button type="submit" class="btn btn-primary" id="zip-btn" disabled>📤 アップロードして自動割り当て</button>
          </div>
        </form>

        <div class="naming-rule">
          <h4>📋 ファイル命名規則（自動割り当てのため）</h4>
          <p style="font-size:0.85rem;color:#555;margin-bottom:0.5rem;">ファイル名の先頭にRef IDを含めると、自動的に対応する車両に割り当てられます。</p>
          <table>
            <tr><th>ファイル名の例</th><th>割り当て先</th></tr>
            <tr><td><code>REF-001-1.jpg</code></td><td>REF-001 の1枚目</td></tr>
            <tr><td><code>REF-001-2.jpg</code></td><td>REF-001 の2枚目</td></tr>
            <tr><td><code>REF-002-1.jpg</code></td><td>REF-002 の1枚目</td></tr>
            <tr><td><code>ref-003-front.jpg</code></td><td>REF-003（大文字小文字不問）</td></tr>
          </table>
          <p style="font-size:0.8rem;color:#888;margin-top:0.6rem;">※ 命名規則に合わない画像は「未割り当て」になります。後から「画像割り当て」画面で手動割り当てできます。</p>
        </div>
      </div>
    </div>
  </div>

  <!-- タブB: FTPスキャン -->
  <div class="tab-content" id="tab-ftp">
    <div class="card">
      <div class="card-header">📡 FTPアップロード済み画像の自動スキャン</div>
      <div class="card-body">
        <div class="scan-box">
          <h4>手順</h4>
          <p>FTPクライアント（FileZillaなど）で以下のフォルダに画像をアップロードしてから、「スキャン実行」ボタンを押してください。</p>
          <div class="ftp-path">📁 /public_html/images/vehicles/</div>
          <p>ファイル名がRef IDで始まっている場合（例: <code>REF-001-1.jpg</code>）、自動的に対応する車両に割り当てられます。</p>
          <form method="post">
            <input type="hidden" name="action" value="scan_ftp">
            <button type="submit" class="btn btn-success">🔍 スキャン実行</button>
          </form>
        </div>

        <div class="naming-rule" style="margin-top:1rem;">
          <h4>📋 現在 images/vehicles/ にある画像ファイル（<?= count($all_images) ?>枚）</h4>
          <?php if (empty($all_images)): ?>
          <p style="color:#999;font-size:0.85rem;margin-top:0.5rem;">まだ画像がありません。</p>
          <?php else: ?>
          <div class="img-grid" style="margin-top:0.75rem;">
            <?php foreach (array_slice($all_images, 0, 20) as $img): ?>
            <div class="img-item">
              <img src="<?= htmlspecialchars('../' . $img) ?>" alt="">
              <div class="img-name"><?= htmlspecialchars(basename($img)) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (count($all_images) > 20): ?>
            <div style="display:flex;align-items:center;color:#888;font-size:0.85rem;">他 <?= count($all_images) - 20 ?> 枚...</div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- タブC: 未割り当て画像 -->
  <div class="tab-content" id="tab-unassigned">
    <div class="card">
      <div class="card-header">🔍 未割り当て画像一覧</div>
      <div class="card-body">
        <?php if (empty($unassigned_images)): ?>
        <div class="empty-msg">✅ 未割り当ての画像はありません。すべての画像が車両に割り当て済みです。</div>
        <?php else: ?>
        <p style="font-size:0.9rem;color:#555;margin-bottom:1rem;">以下の画像はまだどの車両にも割り当てられていません。「割り当て」リンクから各車両の編集画面へ移動できます。</p>
        <div class="img-grid">
          <?php foreach ($unassigned_images as $img): ?>
          <div class="img-item">
            <img src="<?= htmlspecialchars('../' . $img) ?>" alt="">
            <div class="img-name"><?= htmlspecialchars(basename($img)) ?></div>
            <a href="images-assign.php?img=<?= urlencode($img) ?>" class="assign-link">+ 車両に割り当て</a>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:1.5rem;">
          <a href="images-assign.php" class="btn btn-primary">🖼 画像割り当て画面へ →</a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function switchTab(name) {
  document.querySelectorAll('.tab').forEach((t, i) => {
    const names = ['zip', 'ftp', 'unassigned'];
    t.classList.toggle('active', names[i] === name);
  });
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
}

// ZIP ドラッグ＆ドロップ
const zipFile = document.getElementById('zip-file');
const zipBtn  = document.getElementById('zip-btn');
const zipName = document.getElementById('zip-name');
const zipZone = document.getElementById('zip-zone');

if (zipFile) {
  zipFile.addEventListener('change', function() {
    if (this.files.length > 0) {
      zipName.textContent = '選択: ' + this.files[0].name;
      zipName.style.display = 'block';
      zipBtn.disabled = false;
    }
  });
}
if (zipZone) {
  zipZone.addEventListener('dragover', e => { e.preventDefault(); zipZone.classList.add('dragover'); });
  zipZone.addEventListener('dragleave', () => zipZone.classList.remove('dragover'));
  zipZone.addEventListener('drop', e => {
    e.preventDefault();
    zipZone.classList.remove('dragover');
    if (e.dataTransfer.files.length > 0) {
      zipFile.files = e.dataTransfer.files;
      zipFile.dispatchEvent(new Event('change'));
    }
  });
}
</script>
</body>
</html>
