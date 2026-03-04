<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

define('VEHICLES_JSON', __DIR__ . '/../data/vehicles.json');

function loadVehicles() {
    if (!file_exists(VEHICLES_JSON)) return ['vehicles' => []];
    return json_decode(file_get_contents(VEHICLES_JSON), true) ?: ['vehicles' => []];
}

function saveVehicles($data) {
    return file_put_contents(VEHICLES_JSON, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$result = null;
$preview = [];
$errors = [];

// CSVインポート処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // --- プレビュー ---
    if ($_POST['action'] === 'preview' && isset($_FILES['csv_file'])) {
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'ファイルのアップロードに失敗しました。';
        } else {
            $tmp = $_FILES['csv_file']['tmp_name'];
            // BOM除去
            $content = file_get_contents($tmp);
            $content = ltrim($content, "\xEF\xBB\xBF");
            file_put_contents($tmp, $content);

            $handle = fopen($tmp, 'r');
            $header_row = fgetcsv($handle);

            if (!$header_row) {
                $errors[] = 'CSVファイルが空か、形式が正しくありません。';
            } else {
                // ヘッダーのインデックスマッピング
                $col_map = [
                    'ref_id'           => null,
                    'display_name_en'  => null,
                    'year'             => null,
                    'make'             => null,
                    'model'            => null,
                    'body_type'        => null,
                    'fuel_type'        => null,
                    'transmission'     => null,
                    'mileage_km'       => null,
                    'price_low_usd'    => null,
                    'price_high_usd'   => null,
                    'basis_from'       => null,
                    'basis_to'         => null,
                    'disclaimer_short' => null,
                    'gallery'          => null,
                ];

                // ヘッダー名からカラムを自動検出
                $header_keywords = [
                    'ref_id'           => ['ref id', 'ref_id', 'refid'],
                    'display_name_en'  => ['車両名', 'display_name', 'vehicle name', '名前'],
                    'year'             => ['年式', 'year'],
                    'make'             => ['メーカー', 'make', 'brand'],
                    'model'            => ['モデル', 'model'],
                    'body_type'        => ['ボディ', 'body', 'type'],
                    'fuel_type'        => ['燃料', 'fuel'],
                    'transmission'     => ['ミッション', 'transmission', 'trans'],
                    'mileage_km'       => ['走行距離', 'mileage', 'km'],
                    'price_low_usd'    => ['価格下限', 'price_low', 'low'],
                    'price_high_usd'   => ['価格上限', 'price_high', 'high'],
                    'basis_from'       => ['参考期間(開始)', 'basis_from', 'basis from', '開始'],
                    'basis_to'         => ['参考期間(終了)', 'basis_to', 'basis to', '終了'],
                    'disclaimer_short' => ['免責', 'disclaimer'],
                    'gallery'          => ['画像', 'gallery', 'image', 'photo'],
                ];

                foreach ($header_row as $i => $h) {
                    $h_lower = mb_strtolower(trim($h));
                    foreach ($header_keywords as $field => $keywords) {
                        foreach ($keywords as $kw) {
                            if (str_contains($h_lower, mb_strtolower($kw))) {
                                $col_map[$field] = $i;
                                break 2;
                            }
                        }
                    }
                }

                if ($col_map['ref_id'] === null) {
                    $errors[] = '「Ref ID」列が見つかりません。テンプレートを使用してください。';
                } else {
                    $rows = [];
                    $line = 1;
                    while (($row = fgetcsv($handle)) !== false) {
                        $line++;
                        if (empty(array_filter($row))) continue; // 空行スキップ
                        $ref = trim($row[$col_map['ref_id']] ?? '');
                        if (empty($ref)) continue;

                        $gallery_raw = trim($row[$col_map['gallery'] ?? -1] ?? '');
                        $gallery = $gallery_raw ? array_filter(array_map('trim', explode(';', $gallery_raw))) : [];

                        $rows[] = [
                            'ref_id'           => $ref,
                            'display_name_en'  => trim($row[$col_map['display_name_en'] ?? -1] ?? ''),
                            'year'             => (int)($row[$col_map['year'] ?? -1] ?? 0),
                            'make'             => trim($row[$col_map['make'] ?? -1] ?? ''),
                            'model'            => trim($row[$col_map['model'] ?? -1] ?? ''),
                            'body_type'        => trim($row[$col_map['body_type'] ?? -1] ?? ''),
                            'fuel_type'        => trim($row[$col_map['fuel_type'] ?? -1] ?? 'Diesel'),
                            'transmission'     => trim($row[$col_map['transmission'] ?? -1] ?? 'Manual'),
                            'mileage_km'       => (int)str_replace(',', '', $row[$col_map['mileage_km'] ?? -1] ?? '0'),
                            'price_low_usd'    => (int)str_replace(',', '', $row[$col_map['price_low_usd'] ?? -1] ?? '0'),
                            'price_high_usd'   => (int)str_replace(',', '', $row[$col_map['price_high_usd'] ?? -1] ?? '0'),
                            'basis_from'       => trim($row[$col_map['basis_from'] ?? -1] ?? ''),
                            'basis_to'         => trim($row[$col_map['basis_to'] ?? -1] ?? ''),
                            'disclaimer_short' => trim($row[$col_map['disclaimer_short'] ?? -1] ?? 'Reference vehicle only. Not in stock. Photo for reference.'),
                            'gallery'          => array_values($gallery),
                        ];
                    }

                    if (empty($rows)) {
                        $errors[] = 'データ行が見つかりませんでした。';
                    } else {
                        // セッションに保存してプレビュー表示
                        $_SESSION['import_preview'] = $rows;
                        $preview = $rows;
                    }
                }
            }
            fclose($handle);
        }
    }

    // --- 確定インポート ---
    if ($_POST['action'] === 'confirm_import') {
        $mode = $_POST['import_mode'] ?? 'merge'; // merge or replace
        $rows = $_SESSION['import_preview'] ?? [];

        if (empty($rows)) {
            $errors[] = 'インポートデータが見つかりません。再度アップロードしてください。';
        } else {
            $data = loadVehicles();

            if ($mode === 'replace') {
                // 全置換
                $data['vehicles'] = $rows;
            } else {
                // マージ（既存Ref IDは上書き、新規は追加）
                $existing = [];
                foreach ($data['vehicles'] as $v) {
                    $existing[$v['ref_id']] = $v;
                }
                foreach ($rows as $r) {
                    $existing[$r['ref_id']] = $r;
                }
                $data['vehicles'] = array_values($existing);
            }

            saveVehicles($data);
            unset($_SESSION['import_preview']);
            $count = count($rows);
            header('Location: index.php?imported=' . $count . '&mode=' . $mode);
            exit;
        }
    }

    // キャンセル
    if ($_POST['action'] === 'cancel_import') {
        unset($_SESSION['import_preview']);
        header('Location: import.php');
        exit;
    }
}

// セッションからプレビューデータを復元
if (empty($preview) && !empty($_SESSION['import_preview'])) {
    $preview = $_SESSION['import_preview'];
}

$existing_data = loadVehicles();
$existing_count = count($existing_data['vehicles']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>一括インポート - Gloria Trading Admin</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #333; }
.topbar { background: #1a1a2e; color: #fff; padding: 0.9rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
.topbar .brand { font-size: 1.1rem; font-weight: 700; color: #4da6ff; }
.topbar .nav a { color: #ccc; text-decoration: none; font-size: 0.9rem; margin-left: 1.2rem; }
.topbar .nav a:hover { color: #fff; }
.container { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }
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
.btn-danger { background: #dc3545; color: #fff; }
.btn-danger:hover { background: #c82333; }
.btn-sm { padding: 0.4rem 0.8rem; font-size: 0.82rem; }
.card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 1.5rem; overflow: hidden; }
.card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #eee; font-weight: 600; color: #1a1a2e; background: #fafafa; }
.card-body { padding: 1.5rem; }
.alert { padding: 0.85rem 1.1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
.alert-danger { background: #fff0f0; border: 1px solid #ffcccc; color: #cc0000; }
.alert-info { background: #e8f4fd; border: 1px solid #bee5eb; color: #0c5460; }
.alert-warning { background: #fff8e1; border: 1px solid #ffe082; color: #856404; }

/* アップロードエリア */
.upload-zone { border: 2px dashed #ccc; border-radius: 10px; padding: 2.5rem; text-align: center; cursor: pointer; transition: all 0.2s; background: #fafafa; position: relative; }
.upload-zone:hover, .upload-zone.dragover { border-color: #0066cc; background: #f0f7ff; }
.upload-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.upload-zone .icon { font-size: 3rem; margin-bottom: 0.75rem; }
.upload-zone h3 { color: #333; margin-bottom: 0.4rem; }
.upload-zone p { color: #888; font-size: 0.9rem; }

/* ステップ */
.steps { display: flex; gap: 0; margin-bottom: 1.5rem; }
.step { flex: 1; padding: 0.75rem 1rem; text-align: center; background: #f0f2f5; font-size: 0.85rem; color: #888; border-bottom: 3px solid #ddd; }
.step.active { background: #e8f0fe; color: #0066cc; border-bottom-color: #0066cc; font-weight: 600; }
.step.done { background: #e8f5e9; color: #28a745; border-bottom-color: #28a745; }

/* プレビューテーブル */
.preview-table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
th { background: #f8f9fa; padding: 0.6rem 0.8rem; text-align: left; color: #555; border-bottom: 2px solid #eee; white-space: nowrap; }
td { padding: 0.55rem 0.8rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafbff; }
.badge-new { background: #d4edda; color: #155724; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.75rem; font-weight: 600; }
.badge-update { background: #fff3cd; color: #856404; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.75rem; font-weight: 600; }
.ref-badge { background: #e8f0fe; color: #1a56db; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 600; }

/* モード選択 */
.mode-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1rem 0; }
.mode-card { border: 2px solid #ddd; border-radius: 8px; padding: 1.2rem; cursor: pointer; transition: all 0.2s; }
.mode-card:has(input:checked) { border-color: #0066cc; background: #f0f7ff; }
.mode-card input[type=radio] { margin-right: 0.5rem; }
.mode-card h4 { display: inline; font-size: 0.95rem; }
.mode-card p { font-size: 0.82rem; color: #666; margin-top: 0.5rem; }

/* テンプレートダウンロード */
.template-box { background: #f8f9fa; border: 1px solid #e5e5e5; border-radius: 8px; padding: 1.2rem; display: flex; align-items: center; justify-content: space-between; }
.template-box .info h4 { font-size: 0.95rem; margin-bottom: 0.3rem; }
.template-box .info p { font-size: 0.82rem; color: #666; }

.form-actions { display: flex; gap: 1rem; align-items: center; margin-top: 1.5rem; }

@media (max-width: 640px) {
  .mode-cards { grid-template-columns: 1fr; }
  .template-box { flex-direction: column; gap: 1rem; align-items: flex-start; }
}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">Gloria Trading 管理画面</div>
  <div class="nav">
    <a href="index.php">← 車両一覧</a>
    <a href="../index.html" target="_blank">サイトを見る</a>
    <a href="index.php?logout=1">ログアウト</a>
  </div>
</div>

<div class="container">
  <div class="page-header">
    <h1>📥 一括インポート</h1>
    <p>CSVファイルで複数の車両データを一度にインポートできます。</p>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div>⚠ <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ステップ表示 -->
  <div class="steps">
    <div class="step <?= empty($preview) ? 'active' : 'done' ?>">① CSVをアップロード</div>
    <div class="step <?= !empty($preview) ? 'active' : '' ?>">② 内容を確認</div>
    <div class="step">③ インポート完了</div>
  </div>

  <?php if (empty($preview)): ?>
  <!-- ステップ1: アップロード -->

  <!-- テンプレートダウンロード -->
  <div class="card">
    <div class="card-header">CSVテンプレート</div>
    <div class="card-body">
      <div class="template-box">
        <div class="info">
          <h4>📄 テンプレートCSVをダウンロード</h4>
          <p>Excelで開いて編集できます。サンプルデータ入りのテンプレートです。</p>
          <p style="margin-top:0.4rem;font-size:0.8rem;color:#999;">列: Ref ID / 車両名 / 年式 / メーカー / モデル / ボディタイプ / 燃料 / ミッション / 走行距離 / 価格下限 / 価格上限 / 参考期間 / 免責事項 / 画像パス</p>
        </div>
        <a href="export.php?format=template" class="btn btn-secondary">⬇ テンプレートDL</a>
      </div>
    </div>
  </div>

  <!-- アップロードフォーム -->
  <div class="card">
    <div class="card-header">CSVファイルをアップロード</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" id="upload-form">
        <input type="hidden" name="action" value="preview">
        <div class="upload-zone" id="upload-zone">
          <input type="file" name="csv_file" id="csv-file" accept=".csv,text/csv">
          <div class="icon">📂</div>
          <h3>CSVファイルをドラッグ＆ドロップ</h3>
          <p>または クリックしてファイルを選択</p>
          <p style="margin-top:0.5rem;font-size:0.8rem;color:#aaa;">.csv ファイル対応 ・ Excel保存のCSVも可</p>
        </div>
        <div id="file-name" style="margin-top:0.75rem;font-size:0.9rem;color:#555;display:none;"></div>
        <div style="margin-top:1rem;">
          <button type="submit" class="btn btn-primary" id="preview-btn" disabled>次へ：内容を確認 →</button>
        </div>
      </form>
    </div>
  </div>

  <div class="alert alert-info">
    <strong>ヒント：</strong> 既存のデータをエクスポートして編集し、再インポートすることで一括更新ができます。
    <a href="export.php" style="color:#0c5460;font-weight:600;">現在のデータをエクスポート →</a>
  </div>

  <?php else: ?>
  <!-- ステップ2: プレビュー・確認 -->

  <?php
  $existing_refs = [];
  foreach ($existing_data['vehicles'] as $v) {
      $existing_refs[] = $v['ref_id'];
  }
  $new_count = 0;
  $update_count = 0;
  foreach ($preview as $r) {
      if (in_array($r['ref_id'], $existing_refs)) $update_count++;
      else $new_count++;
  }
  ?>

  <div class="card">
    <div class="card-header">インポート内容の確認</div>
    <div class="card-body">
      <div style="display:flex;gap:1.5rem;margin-bottom:1.2rem;flex-wrap:wrap;">
        <div style="background:#d4edda;border-radius:6px;padding:0.75rem 1.2rem;text-align:center;">
          <div style="font-size:1.6rem;font-weight:700;color:#155724;"><?= $new_count ?></div>
          <div style="font-size:0.82rem;color:#155724;">新規追加</div>
        </div>
        <div style="background:#fff3cd;border-radius:6px;padding:0.75rem 1.2rem;text-align:center;">
          <div style="font-size:1.6rem;font-weight:700;color:#856404;"><?= $update_count ?></div>
          <div style="font-size:0.82rem;color:#856404;">既存を更新</div>
        </div>
        <div style="background:#e8f0fe;border-radius:6px;padding:0.75rem 1.2rem;text-align:center;">
          <div style="font-size:1.6rem;font-weight:700;color:#1a56db;"><?= count($preview) ?></div>
          <div style="font-size:0.82rem;color:#1a56db;">合計</div>
        </div>
      </div>

      <div class="preview-table-wrap">
        <table>
          <thead>
            <tr>
              <th>状態</th>
              <th>Ref ID</th>
              <th>車両名</th>
              <th>年式</th>
              <th>メーカー</th>
              <th>モデル</th>
              <th>燃料</th>
              <th>走行距離</th>
              <th>価格(USD)</th>
              <th>参考期間</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview as $r): ?>
            <tr>
              <td>
                <?php if (in_array($r['ref_id'], $existing_refs)): ?>
                <span class="badge-update">更新</span>
                <?php else: ?>
                <span class="badge-new">新規</span>
                <?php endif; ?>
              </td>
              <td><span class="ref-badge"><?= htmlspecialchars($r['ref_id']) ?></span></td>
              <td><?= htmlspecialchars($r['display_name_en']) ?></td>
              <td><?= htmlspecialchars($r['year']) ?></td>
              <td><?= htmlspecialchars($r['make']) ?></td>
              <td><?= htmlspecialchars($r['model']) ?></td>
              <td><?= htmlspecialchars($r['fuel_type']) ?></td>
              <td><?= $r['mileage_km'] ? number_format($r['mileage_km']) . ' km' : '-' ?></td>
              <td>$<?= number_format($r['price_low_usd']) ?> – $<?= number_format($r['price_high_usd']) ?></td>
              <td><?= htmlspecialchars($r['basis_from'] . ' – ' . $r['basis_to']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- インポートモード選択 -->
  <div class="card">
    <div class="card-header">インポート方法を選択</div>
    <div class="card-body">
      <form method="post" id="confirm-form">
        <input type="hidden" name="action" value="confirm_import">
        <div class="mode-cards">
          <label class="mode-card">
            <input type="radio" name="import_mode" value="merge" checked>
            <h4>マージ（推奨）</h4>
            <p>既存データはRef IDが一致するものだけ上書き更新。新規Ref IDは追加。既存データは保持されます。</p>
          </label>
          <label class="mode-card">
            <input type="radio" name="import_mode" value="replace">
            <h4>全置換</h4>
            <p>現在の全車両データをCSVの内容で完全に置き換えます。<strong style="color:#dc3545;">既存データは削除されます。</strong></p>
          </label>
        </div>
        <div class="form-actions">
          <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="cancel_import">
            <button type="submit" class="btn btn-secondary">← やり直す</button>
          </form>
          <button type="submit" form="confirm-form" class="btn btn-success">✅ インポートを実行する (<?= count($preview) ?>件)</button>
        </div>
      </form>
    </div>
  </div>

  <?php endif; ?>
</div>

<script>
const fileInput = document.getElementById('csv-file');
const previewBtn = document.getElementById('preview-btn');
const fileNameDiv = document.getElementById('file-name');
const uploadZone = document.getElementById('upload-zone');

if (fileInput) {
  fileInput.addEventListener('change', function() {
    if (this.files.length > 0) {
      const name = this.files[0].name;
      fileNameDiv.textContent = '選択されたファイル: ' + name;
      fileNameDiv.style.display = 'block';
      previewBtn.disabled = false;
      previewBtn.style.background = '#0066cc';
    }
  });
}

if (uploadZone) {
  uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
  uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
  uploadZone.addEventListener('drop', e => {
    e.preventDefault();
    uploadZone.classList.remove('dragover');
    if (e.dataTransfer.files.length > 0) {
      fileInput.files = e.dataTransfer.files;
      fileInput.dispatchEvent(new Event('change'));
    }
  });
}
</script>
</body>
</html>
