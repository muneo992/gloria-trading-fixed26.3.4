<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

define('VEHICLES_JSON', __DIR__ . '/../data/vehicles.json');
define('IMAGES_DIR', __DIR__ . '/../images/vehicles/');
define('IMAGES_URL', '../images/vehicles/');

function loadVehicles() {
    if (!file_exists(VEHICLES_JSON)) return ['vehicles' => []];
    return json_decode(file_get_contents(VEHICLES_JSON), true) ?: ['vehicles' => []];
}
function saveVehicles($data) {
    return file_put_contents(VEHICLES_JSON, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$ref_id = $_GET['ref'] ?? '';
$data = loadVehicles();
$vehicles = &$data['vehicles'];
$is_edit = false;
$vehicle = [
    'ref_id' => '', 'display_name_en' => '', 'year' => '', 'make' => '',
    'model' => '', 'grade' => '', 'body_type' => '', 'fuel_type' => 'Diesel',
    'transmission' => 'Manual', 'mileage_km' => '', 'engine_cc' => 0,
    'reference_price_usd' => '', 'basis_from' => '', 'basis_to' => '',
    'disclaimer_short' => 'Reference vehicle only. Not in stock. Photo for reference.',
    'best_for_resale_in' => '',
    'typical_buyer_use' => '',
    'similar_units' => '',
    'bulk_repeat_order' => '',
    'resale_markets' => '',
    'gallery' => [],
    'quote_spec_files' => [], 'quote_image_files' => [], 'vehicle_certificate_files' => [],
    'quote_spec_data' => ['auction_price_jpy'=>0,'exchange_rate_jpy_usd'=>0,'purchase_price_usd'=>0,'local_shipping_jpy'=>0,'export_fee_jpy'=>0,'inspection_jpy'=>0,'total_cost_jpy'=>0,'total_cost_usd'=>0,'margin_usd'=>0,'selling_price_usd'=>0,'notes'=>''],
    'export_document_data' => ['commercial_invoice_no'=>'','commercial_invoice_date'=>'','packing_list_no'=>'','bl_no'=>'','bl_date'=>'','vessel_name'=>'','voyage_no'=>'','port_of_loading'=>'Nagoya, Japan','port_of_discharge'=>'','destination_country'=>'','consignee_name'=>'','consignee_address'=>'','notify_party'=>'','shipping_marks'=>'','gross_weight_kg'=>0,'net_weight_kg'=>0,'measurement_cbm'=>0,'notes'=>''],
    'vehicle_certificate_data' => ['registration_no'=>'','registration_date'=>'','first_registration'=>'','vehicle_type'=>'','chassis_no'=>'','engine_no'=>'','color'=>'','notes'=>'']
];

if ($ref_id) {
    foreach ($vehicles as $v) {
        if ($v['ref_id'] === $ref_id) {
            $vehicle = array_merge($vehicle, $v);
            $is_edit = true;
            break;
        }
    }
}

$errors = [];
$success = false;

// --- 保存処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $new_ref = trim($_POST['ref_id'] ?? '');

    // 既存の全フィールドを保持しつつ更新（quote_spec_data等の複合フィールドを上書きしない）
    $existing_vehicle = $vehicle; // 現在のデータをベースにする

    $new_vehicle = array_merge($existing_vehicle, [
        'ref_id'               => $new_ref,
        'display_name_en'      => trim($_POST['display_name_en'] ?? ''),
        'year'                 => (int)($_POST['year'] ?? 0),
        'make'                 => trim($_POST['make'] ?? ''),
        'model'                => trim($_POST['model'] ?? ''),
        'grade'                => trim($_POST['grade'] ?? ''),
        'body_type'            => trim($_POST['body_type'] ?? ''),
        'fuel_type'            => trim($_POST['fuel_type'] ?? ''),
        'transmission'         => trim($_POST['transmission'] ?? ''),
        'mileage_km'           => (int)str_replace(',', '', $_POST['mileage_km'] ?? '0'),
        'engine_cc'            => (int)str_replace(',', '', $_POST['engine_cc'] ?? '0'),
        'reference_price_usd'  => (int)str_replace(',', '', $_POST['reference_price_usd'] ?? '0'),
        'basis_from'           => trim($_POST['basis_from'] ?? ''),
        'basis_to'             => trim($_POST['basis_to'] ?? ''),
        'disclaimer_short'     => trim($_POST['disclaimer_short'] ?? ''),
        'best_for_resale_in'   => trim($_POST['best_for_resale_in'] ?? ''),
        'typical_buyer_use'    => trim($_POST['typical_buyer_use'] ?? ''),
        'similar_units'        => trim($_POST['similar_units'] ?? ''),
        'bulk_repeat_order'    => trim($_POST['bulk_repeat_order'] ?? ''),
        'resale_markets'       => trim($_POST['resale_markets'] ?? ''),
        'gallery'              => json_decode($_POST['gallery_json'] ?? '[]', true) ?: []
    ]);

    // バリデーション
    if (empty($new_ref)) $errors[] = 'Ref IDは必須です。';
    if (empty($new_vehicle['display_name_en'])) $errors[] = '車両名は必須です。';
    if ($new_vehicle['year'] < 1990 || $new_vehicle['year'] > 2030) $errors[] = '年式を正しく入力してください。';

    // Ref IDの重複チェック（新規の場合）
    if (!$is_edit && empty($errors)) {
        foreach ($vehicles as $v) {
            if ($v['ref_id'] === $new_ref) {
                $errors[] = 'このRef IDはすでに使用されています。';
                break;
            }
        }
    }

    // 画像アップロード処理
    if (!is_dir(IMAGES_DIR)) mkdir(IMAGES_DIR, 0755, true);

    if (!empty($_FILES['new_images']['name'][0])) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        foreach ($_FILES['new_images']['tmp_name'] as $i => $tmp) {
            if ($_FILES['new_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $mime = mime_content_type($tmp);
            if (!in_array($mime, $allowed)) continue;
            $ext = pathinfo($_FILES['new_images']['name'][$i], PATHINFO_EXTENSION);
            $safe_ref = preg_replace('/[^a-zA-Z0-9\-]/', '', strtolower($new_ref));
            $filename = $safe_ref . '-' . (count($new_vehicle['gallery']) + 1) . '.' . strtolower($ext);
            $dest = IMAGES_DIR . $filename;
            if (move_uploaded_file($tmp, $dest)) {
                $new_vehicle['gallery'][] = 'images/vehicles/' . $filename;
            }
        }
    }

    if (empty($errors)) {
        if ($is_edit) {
            foreach ($vehicles as &$v) {
                if ($v['ref_id'] === $ref_id) { $v = $new_vehicle; break; }
            }
            unset($v);
        } else {
            $vehicles[] = $new_vehicle;
        }
        saveVehicles($data);
        header('Location: index.php?saved=1');
        exit;
    }
    $vehicle = $new_vehicle;
}

// --- 画像削除 ---
if (isset($_POST['action']) && $_POST['action'] === 'delete_image') {
    $img_path = $_POST['img_path'] ?? '';
    foreach ($vehicles as &$v) {
        if ($v['ref_id'] === $ref_id) {
            $v['gallery'] = array_values(array_filter($v['gallery'], fn($g) => $g !== $img_path));
            $vehicle = array_merge($vehicle, $v);
            break;
        }
    }
    unset($v);
    saveVehicles($data);
    // ファイル削除
    $full_path = __DIR__ . '/../' . $img_path;
    if (file_exists($full_path)) @unlink($full_path);
    header('Location: edit.php?ref=' . urlencode($ref_id) . '&img_deleted=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $is_edit ? '車両編集' : '新規車両追加' ?> - Gloria Trading Admin</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #333; }
.topbar { background: #1a1a2e; color: #fff; padding: 0.9rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
.topbar .brand { font-size: 1.1rem; font-weight: 700; color: #4da6ff; }
.topbar .nav a { color: #ccc; text-decoration: none; font-size: 0.9rem; margin-left: 1.2rem; }
.topbar .nav a:hover { color: #fff; }
.container { max-width: 900px; margin: 0 auto; padding: 1.5rem; }
.page-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
.page-header h1 { font-size: 1.4rem; color: #1a1a2e; }
.btn { display: inline-block; padding: 0.6rem 1.2rem; border-radius: 6px; font-size: 0.9rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
.btn-primary { background: #0066cc; color: #fff; }
.btn-primary:hover { background: #0052a3; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-secondary:hover { background: #5a6268; }
.btn-danger { background: #dc3545; color: #fff; font-size: 0.8rem; padding: 0.3rem 0.7rem; }
.card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 1.5rem; overflow: hidden; }
.card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #eee; font-weight: 600; color: #1a1a2e; background: #fafafa; font-size: 1rem; }
.card-body { padding: 1.5rem; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.form-group { margin-bottom: 1rem; }
.form-group.full { grid-column: 1 / -1; }
label { display: block; font-size: 0.85rem; font-weight: 600; color: #444; margin-bottom: 0.4rem; }
label .req { color: #dc3545; margin-left: 2px; }
input[type=text], input[type=number], select, textarea {
  width: 100%; padding: 0.65rem 0.9rem; border: 1px solid #ddd; border-radius: 6px;
  font-size: 0.95rem; outline: none; transition: border-color 0.2s; background: #fff;
}
input:focus, select:focus, textarea:focus { border-color: #0066cc; box-shadow: 0 0 0 3px rgba(0,102,204,0.1); }
textarea { resize: vertical; min-height: 80px; }
.hint { font-size: 0.78rem; color: #888; margin-top: 0.3rem; }
.errors { background: #fff0f0; border: 1px solid #ffcccc; color: #cc0000; padding: 1rem 1.2rem; border-radius: 6px; margin-bottom: 1rem; }
.errors ul { margin-left: 1.2rem; }
.alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }

/* 画像アップロードエリア */
.upload-area {
  border: 2px dashed #ccc; border-radius: 8px; padding: 2rem; text-align: center;
  cursor: pointer; transition: all 0.2s; background: #fafafa; position: relative;
}
.upload-area:hover, .upload-area.dragover { border-color: #0066cc; background: #f0f7ff; }
.upload-area input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.upload-area .icon { font-size: 2.5rem; margin-bottom: 0.5rem; }
.upload-area p { color: #666; font-size: 0.9rem; }
.upload-area .sub { font-size: 0.8rem; color: #999; margin-top: 0.3rem; }

/* 画像ギャラリー */
.gallery-grid { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem; }
.gallery-item { position: relative; width: 130px; }
.gallery-item img { width: 130px; height: 95px; object-fit: cover; border-radius: 6px; border: 2px solid #eee; display: block; }
.gallery-item .badge { position: absolute; top: 4px; left: 4px; background: #0066cc; color: #fff; font-size: 0.65rem; padding: 2px 6px; border-radius: 3px; }
.gallery-item .del-btn { position: absolute; top: 4px; right: 4px; background: rgba(220,53,69,0.9); color: #fff; border: none; border-radius: 3px; padding: 2px 6px; font-size: 0.7rem; cursor: pointer; }
.gallery-item .del-btn:hover { background: #c82333; }
.gallery-item .move-btn { position: absolute; bottom: 4px; left: 4px; background: rgba(0,0,0,0.5); color: #fff; border: none; border-radius: 3px; padding: 2px 5px; font-size: 0.7rem; cursor: pointer; }

.preview-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.75rem; }
.preview-item { width: 100px; height: 75px; object-fit: cover; border-radius: 4px; border: 2px solid #0066cc; }

.form-actions { display: flex; gap: 1rem; justify-content: flex-end; padding-top: 1rem; border-top: 1px solid #eee; }

@media (max-width: 640px) {
  .form-grid { grid-template-columns: 1fr; }
  .gallery-item { width: 100px; }
  .gallery-item img { width: 100px; height: 75px; }
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
    <h1><?= $is_edit ? '車両データ編集' : '新規車両を追加' ?></h1>
    <?php if ($is_edit): ?>
    <span style="background:#e8f0fe;color:#1a56db;padding:0.3rem 0.8rem;border-radius:4px;font-size:0.85rem;font-weight:600;"><?= htmlspecialchars($ref_id) ?></span>
    <?php endif; ?>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="errors">
    <strong>入力エラー：</strong>
    <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <?php if (isset($_GET['img_deleted'])): ?>
  <div class="alert-success">画像を削除しました。</div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="vehicle-form">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="gallery_json" id="gallery_json" value="<?= htmlspecialchars(json_encode($vehicle['gallery'])) ?>">

    <!-- 基本情報 -->
    <div class="card">
      <div class="card-header">基本情報</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Ref ID <span class="req">*</span></label>
            <input type="text" name="ref_id" value="<?= htmlspecialchars($vehicle['ref_id']) ?>"
              placeholder="例: REF-006" <?= $is_edit ? 'readonly style="background:#f5f5f5;"' : '' ?>>
            <div class="hint">例: REF-001, REF-002 （一度登録すると変更できません）</div>
          </div>
          <div class="form-group">
            <label>車両名（英語）<span class="req">*</span></label>
            <input type="text" name="display_name_en" value="<?= htmlspecialchars($vehicle['display_name_en']) ?>" placeholder="例: Toyota Hiace 2020">
          </div>
          <div class="form-group">
            <label>メーカー（Make）</label>
            <input type="text" name="make" value="<?= htmlspecialchars($vehicle['make']) ?>" placeholder="例: Toyota">
          </div>
          <div class="form-group">
            <label>モデル（Model）</label>
            <input type="text" name="model" value="<?= htmlspecialchars($vehicle['model']) ?>" placeholder="例: Hiace">
          </div>
          <div class="form-group">
            <label>グレード（Grade）</label>
            <input type="text" name="grade" value="<?= htmlspecialchars($vehicle['grade'] ?? '') ?>" placeholder="例: DX, GL, Super GL">
          </div>
          <div class="form-group">
            <label>年式（Year）<span class="req">*</span></label>
            <input type="number" name="year" value="<?= htmlspecialchars($vehicle['year']) ?>" placeholder="例: 2020" min="1990" max="2030">
          </div>
          <div class="form-group">
            <label>ボディタイプ（Body Type）</label>
            <select name="body_type">
              <?php foreach (['Hatchback / Compact','Sedan','SUV','Van','Pickup','Wagon','Minivan','Truck','Bus','Other'] as $bt): ?>
              <option value="<?= $bt ?>" <?= ($vehicle['body_type'] === $bt) ? 'selected' : '' ?>><?= $bt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- スペック -->
    <div class="card">
      <div class="card-header">スペック</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="form-group">
            <label>燃料タイプ（Fuel Type）</label>
            <select name="fuel_type">
              <?php foreach (['Diesel','Petrol','Hybrid','Electric','LPG'] as $ft): ?>
              <option value="<?= $ft ?>" <?= ($vehicle['fuel_type'] === $ft) ? 'selected' : '' ?>><?= $ft ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>ミッション（Transmission）</label>
            <select name="transmission">
              <?php foreach (['Manual','Automatic','CVT'] as $tr): ?>
              <option value="<?= $tr ?>" <?= ($vehicle['transmission'] === $tr) ? 'selected' : '' ?>><?= $tr ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>走行距離 km（Mileage）</label>
            <input type="number" name="mileage_km" value="<?= htmlspecialchars($vehicle['mileage_km']) ?>" placeholder="例: 45000" min="0">
          </div>
          <div class="form-group">
            <label>排気量 cc（Engine CC）</label>
            <input type="number" name="engine_cc" value="<?= htmlspecialchars($vehicle['engine_cc'] ?? 0) ?>" placeholder="例: 2000" min="0">
          </div>
        </div>
      </div>
    </div>

    <!-- 価格 -->
    <div class="card">
      <div class="card-header">価格・参考期間</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="form-group">
            <label>参考価格 USD（Reference Price）</label>
            <input type="number" name="reference_price_usd" value="<?= htmlspecialchars($vehicle['reference_price_usd'] ?? '') ?>" placeholder="例: 5500" min="0">
            <div class="hint">FOB参考価格（USD）</div>
          </div>
          <div class="form-group">
            <label>参考期間（開始）Basis From</label>
            <input type="text" name="basis_from" value="<?= htmlspecialchars($vehicle['basis_from']) ?>" placeholder="例: 2023-01">
            <div class="hint">形式: YYYY-MM または日付</div>
          </div>
          <div class="form-group">
            <label>参考期間（終了）Basis To</label>
            <input type="text" name="basis_to" value="<?= htmlspecialchars($vehicle['basis_to']) ?>" placeholder="例: 2023-06">
            <div class="hint">形式: YYYY-MM または仕入先名</div>
          </div>
          <div class="form-group full">
            <label>免責事項（Disclaimer）</label>
            <textarea name="disclaimer_short"><?= htmlspecialchars($vehicle['disclaimer_short']) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- 輸出市場情報 -->
    <div class="card">
      <div class="card-header">輸出市場情報（Resale Markets）</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="form-group full">
            <label>販売市場（Resale Markets）</label>
            <input type="text" name="resale_markets" value="<?= htmlspecialchars($vehicle['resale_markets'] ?? '') ?>" placeholder="例: Ghana (Tema) / Nigeria (Lagos) / Benin (Cotonou)">
            <div class="hint">スラッシュ区切りで複数市場を入力。例: Ghana (Tema) / Nigeria (Lagos) / Benin (Cotonou) / Cote d'Ivoire (Abidjan)</div>
          </div>
          <div class="form-group full">
            <label>典型的な購入用途（Typical Buyer Use）</label>
            <input type="text" name="typical_buyer_use" value="<?= htmlspecialchars($vehicle['typical_buyer_use'] ?? '') ?>" placeholder="例: Taxi resale, private daily use, entry-level retail stock">
            <div class="hint">カンマ区切りで用途を入力</div>
          </div>
          <div class="form-group full">
            <label>最適な再販市場（Best for Resale In）</label>
            <input type="text" name="best_for_resale_in" value="<?= htmlspecialchars($vehicle['best_for_resale_in'] ?? '') ?>" placeholder="例: Ghana, Nigeria">
          </div>
          <div class="form-group">
            <label>類似車両（Similar Units）</label>
            <input type="text" name="similar_units" value="<?= htmlspecialchars($vehicle['similar_units'] ?? '') ?>" placeholder="例: REF-002, REF-005">
          </div>
          <div class="form-group">
            <label>まとめ買い・リピート（Bulk / Repeat Order）</label>
            <input type="text" name="bulk_repeat_order" value="<?= htmlspecialchars($vehicle['bulk_repeat_order'] ?? '') ?>" placeholder="例: 3+ units available">
          </div>
        </div>
      </div>
    </div>

    <!-- 画像管理 -->
    <div class="card">
      <div class="card-header">画像管理</div>
      <div class="card-body">
        <?php if (!empty($vehicle['gallery'])): ?>
        <p style="font-size:0.9rem;color:#555;margin-bottom:0.75rem;">登録済み画像（最初の画像がメイン画像になります）</p>
        <div class="gallery-grid" id="gallery-grid">
          <?php foreach ($vehicle['gallery'] as $i => $img_path): ?>
          <div class="gallery-item" data-path="<?= htmlspecialchars($img_path) ?>">
            <img src="<?= htmlspecialchars('../' . $img_path) ?>" alt="">
            <?php if ($i === 0): ?><span class="badge">メイン</span><?php endif; ?>
            <?php if ($is_edit): ?>
            <button type="button" class="del-btn" onclick="deleteImage('<?= htmlspecialchars($img_path, ENT_QUOTES) ?>')">✕</button>
            <?php endif; ?>
            <?php if ($i > 0): ?>
            <button type="button" class="move-btn" onclick="moveImageUp(this)">↑ 前へ</button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:1.25rem;">
          <p style="font-size:0.9rem;color:#555;margin-bottom:0.75rem;">新しい画像を追加（複数選択可）</p>
          <div class="upload-area" id="upload-area">
            <input type="file" name="new_images[]" id="file-input" multiple accept="image/*">
            <div class="icon">📷</div>
            <p>クリックまたはドラッグ＆ドロップで画像を追加</p>
            <p class="sub">JPEG / PNG / WebP 対応 ・ 複数枚同時アップロード可</p>
          </div>
          <div class="preview-list" id="preview-list"></div>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <a href="index.php" class="btn btn-secondary">キャンセル</a>
      <button type="submit" class="btn btn-primary">💾 保存する</button>
    </div>
  </form>
</div>
<script>
// 画像プレビュー
document.getElementById('file-input').addEventListener('change', function() {
  const list = document.getElementById('preview-list');
  list.innerHTML = '';
  Array.from(this.files).forEach(file => {
    const reader = new FileReader();
    reader.onload = e => {
      const img = document.createElement('img');
      img.src = e.target.result;
      img.className = 'preview-item';
      img.title = file.name;
      list.appendChild(img);
    };
    reader.readAsDataURL(file);
  });
});
// ドラッグ＆ドロップ
const uploadArea = document.getElementById('upload-area');
uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('dragover'); });
uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
uploadArea.addEventListener('drop', e => {
  e.preventDefault();
  uploadArea.classList.remove('dragover');
  const input = document.getElementById('file-input');
  input.files = e.dataTransfer.files;
  input.dispatchEvent(new Event('change'));
});
// 画像の順序変更（↑ 前へ）
function moveImageUp(btn) {
  const item = btn.closest('.gallery-item');
  const prev = item.previousElementSibling;
  if (prev) {
    item.parentNode.insertBefore(item, prev);
    updateGalleryJson();
    updateBadges();
  }
}
function updateGalleryJson() {
  const items = document.querySelectorAll('#gallery-grid .gallery-item');
  const paths = Array.from(items).map(el => el.dataset.path);
  document.getElementById('gallery_json').value = JSON.stringify(paths);
}
// 画像削除（Ajaxで送信）
function deleteImage(imgPath) {
  if (!confirm('この画像を削除しますか？')) return;
  const form = document.createElement('form');
  form.method = 'post';
  form.style.display = 'none';
  const actionInput = document.createElement('input');
  actionInput.name = 'action';
  actionInput.value = 'delete_image';
  const pathInput = document.createElement('input');
  pathInput.name = 'img_path';
  pathInput.value = imgPath;
  form.appendChild(actionInput);
  form.appendChild(pathInput);
  document.body.appendChild(form);
  form.submit();
}
function updateBadges() {
  const items = document.querySelectorAll('#gallery-grid .gallery-item');
  items.forEach((el, i) => {
    const badge = el.querySelector('.badge');
    if (i === 0) {
      if (!badge) {
        const b = document.createElement('span');
        b.className = 'badge';
        b.textContent = 'メイン';
        el.appendChild(b);
      }
    } else {
      if (badge) badge.remove();
    }
    const moveBtn = el.querySelector('.move-btn');
    if (i === 0) {
      if (moveBtn) moveBtn.style.display = 'none';
    } else {
      if (moveBtn) moveBtn.style.display = '';
    }
  });
}
</script>
</body>
</html>
