<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

define('VEHICLES_JSON', __DIR__ . '/../data/vehicles.json');
define('IMAGES_DIR',    __DIR__ . '/../images/vehicles/');

function loadVehicles() {
    if (!file_exists(VEHICLES_JSON)) return ['vehicles' => []];
    return json_decode(file_get_contents(VEHICLES_JSON), true) ?: ['vehicles' => []];
}
function saveVehicles($data) {
    return file_put_contents(VEHICLES_JSON, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$messages = [];
$data     = loadVehicles();
$vehicles = &$data['vehicles'];

$allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

// 全画像一覧
$all_images = [];
foreach (glob(IMAGES_DIR . '*') as $f) {
    if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed_ext)) {
        $all_images[] = 'images/vehicles/' . basename($f);
    }
}

// 割り当て済み画像セット
$assigned_map = []; // img_path => [ref_id, ...]
foreach ($vehicles as $v) {
    foreach ($v['gallery'] ?? [] as $img) {
        $assigned_map[$img][] = $v['ref_id'];
    }
}

// =============================================
// 割り当て保存処理
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    $img_path = $_POST['img_path'] ?? '';
    $ref_id   = $_POST['ref_id']   ?? '';
    $position = $_POST['position'] ?? 'last'; // first or last

    if ($img_path && $ref_id) {
        foreach ($vehicles as &$v) {
            if ($v['ref_id'] === $ref_id) {
                if (!isset($v['gallery'])) $v['gallery'] = [];
                // 既存に含まれていなければ追加
                if (!in_array($img_path, $v['gallery'])) {
                    if ($position === 'first') {
                        array_unshift($v['gallery'], $img_path);
                    } else {
                        $v['gallery'][] = $img_path;
                    }
                }
                break;
            }
        }
        unset($v);
        saveVehicles($data);
        $messages[] = '✅ ' . basename($img_path) . ' を ' . $ref_id . ' に割り当てました。';
    }
    // 再読み込み
    $data     = loadVehicles();
    $vehicles = &$data['vehicles'];
    $assigned_map = [];
    foreach ($vehicles as $v) {
        foreach ($v['gallery'] ?? [] as $img) {
            $assigned_map[$img][] = $v['ref_id'];
        }
    }
}

// 割り当て解除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unassign') {
    $img_path = $_POST['img_path'] ?? '';
    $ref_id   = $_POST['ref_id']   ?? '';
    if ($img_path && $ref_id) {
        foreach ($vehicles as &$v) {
            if ($v['ref_id'] === $ref_id) {
                $v['gallery'] = array_values(array_filter($v['gallery'] ?? [], fn($g) => $g !== $img_path));
                break;
            }
        }
        unset($v);
        saveVehicles($data);
        $messages[] = '✅ ' . basename($img_path) . ' の割り当てを解除しました。';
    }
    $data     = loadVehicles();
    $vehicles = &$data['vehicles'];
    $assigned_map = [];
    foreach ($vehicles as $v) {
        foreach ($v['gallery'] ?? [] as $img) {
            $assigned_map[$img][] = $v['ref_id'];
        }
    }
}

// フィルター：特定画像にフォーカス
$focus_img = $_GET['img'] ?? '';

// 未割り当て画像
$unassigned = array_filter($all_images, fn($img) => empty($assigned_map[$img]));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>画像割り当て - Gloria Trading Admin</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #333; }
.topbar { background: #1a1a2e; color: #fff; padding: 0.9rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
.topbar .brand { font-size: 1.1rem; font-weight: 700; color: #4da6ff; }
.topbar .nav a { color: #ccc; text-decoration: none; font-size: 0.9rem; margin-left: 1.2rem; }
.topbar .nav a:hover { color: #fff; }
.container { max-width: 1300px; margin: 0 auto; padding: 1.5rem; }
.page-header { margin-bottom: 1.2rem; }
.page-header h1 { font-size: 1.4rem; color: #1a1a2e; }
.page-header p { color: #666; font-size: 0.9rem; margin-top: 0.3rem; }
.btn { display: inline-block; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.85rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
.btn-primary { background: #0066cc; color: #fff; }
.btn-primary:hover { background: #0052a3; }
.btn-success { background: #28a745; color: #fff; }
.btn-success:hover { background: #218838; }
.btn-danger { background: #dc3545; color: #fff; }
.btn-danger:hover { background: #c82333; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-secondary:hover { background: #5a6268; }
.btn-sm { padding: 0.3rem 0.7rem; font-size: 0.78rem; }
.alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 0.6rem; font-size: 0.9rem; }
.alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }

/* レイアウト */
.layout { display: grid; grid-template-columns: 340px 1fr; gap: 1.5rem; align-items: start; }
.panel { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); overflow: hidden; }
.panel-header { padding: 0.9rem 1.2rem; border-bottom: 1px solid #eee; font-weight: 600; color: #1a1a2e; background: #fafafa; font-size: 0.95rem; display: flex; align-items: center; justify-content: space-between; }
.panel-body { padding: 1rem; }

/* 画像リスト（左パネル） */
.img-list { max-height: 75vh; overflow-y: auto; }
.img-list-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.8rem; border-radius: 6px; cursor: pointer; transition: background 0.15s; border: 2px solid transparent; margin-bottom: 0.3rem; }
.img-list-item:hover { background: #f0f7ff; }
.img-list-item.selected { background: #e8f0fe; border-color: #0066cc; }
.img-list-item.assigned { opacity: 0.6; }
.img-list-item img { width: 64px; height: 46px; object-fit: cover; border-radius: 4px; flex-shrink: 0; }
.img-list-item .info { flex: 1; min-width: 0; }
.img-list-item .fname { font-size: 0.82rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.img-list-item .status { font-size: 0.75rem; margin-top: 0.2rem; }
.badge-assigned { color: #28a745; }
.badge-unassigned { color: #dc3545; }

/* 右パネル：割り当て操作 */
.selected-img-preview { text-align: center; padding: 1rem; border-bottom: 1px solid #eee; }
.selected-img-preview img { max-width: 100%; max-height: 200px; border-radius: 8px; object-fit: contain; }
.selected-img-preview .fname { font-size: 0.85rem; color: #555; margin-top: 0.5rem; }

.vehicle-assign-list { max-height: 55vh; overflow-y: auto; padding: 0.5rem; }
.vehicle-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.8rem; border-radius: 6px; margin-bottom: 0.4rem; background: #f8f9fa; }
.vehicle-row img { width: 56px; height: 40px; object-fit: cover; border-radius: 4px; flex-shrink: 0; background: #eee; }
.vehicle-row .vinfo { flex: 1; min-width: 0; }
.vehicle-row .vname { font-size: 0.85rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vehicle-row .vref { font-size: 0.75rem; color: #1a56db; }
.vehicle-row .vactions { display: flex; gap: 0.4rem; flex-shrink: 0; }
.already-badge { font-size: 0.75rem; background: #d4edda; color: #155724; padding: 0.2rem 0.5rem; border-radius: 3px; }

.no-selection { text-align: center; padding: 3rem 1rem; color: #aaa; }
.no-selection .icon { font-size: 3rem; margin-bottom: 0.75rem; }

/* フィルター */
.filter-bar { display: flex; gap: 0.5rem; margin-bottom: 0.75rem; }
.filter-bar input { flex: 1; padding: 0.5rem 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 0.85rem; }
.filter-btn { padding: 0.4rem 0.8rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600; border: none; cursor: pointer; }
.filter-btn.active { background: #0066cc; color: #fff; }
.filter-btn:not(.active) { background: #e9ecef; color: #555; }

@media (max-width: 900px) {
  .layout { grid-template-columns: 1fr; }
  .img-list { max-height: 40vh; }
}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">Gloria Trading 管理画面</div>
  <div class="nav">
    <a href="index.php">← 車両一覧</a>
    <a href="images-upload.php">画像アップロード</a>
    <a href="../index.html" target="_blank">サイトを見る</a>
    <a href="index.php?logout=1">ログアウト</a>
  </div>
</div>

<div class="container">
  <div class="page-header">
    <h1>🖼 画像割り当て</h1>
    <p>左の画像を選択し、右の車両に割り当ててください。</p>
  </div>

  <?php foreach ($messages as $msg): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
  <?php endforeach; ?>

  <?php if (empty($all_images)): ?>
  <div style="background:#fff;border-radius:10px;padding:3rem;text-align:center;color:#999;box-shadow:0 2px 8px rgba(0,0,0,0.07);">
    <div style="font-size:3rem;margin-bottom:1rem;">📂</div>
    <p>images/vehicles/ フォルダに画像がありません。</p>
    <a href="images-upload.php" class="btn btn-primary" style="margin-top:1rem;">画像をアップロードする →</a>
  </div>
  <?php else: ?>

  <div class="layout">
    <!-- 左パネル：画像一覧 -->
    <div class="panel">
      <div class="panel-header">
        <span>画像一覧 (<?= count($all_images) ?>枚)</span>
        <span style="font-size:0.8rem;color:#888;">未割り当て: <?= count($unassigned) ?>枚</span>
      </div>
      <div class="panel-body" style="padding:0.75rem;">
        <div class="filter-bar">
          <input type="text" id="img-search" placeholder="ファイル名で検索..." oninput="filterImages()">
          <button class="filter-btn active" id="filter-all" onclick="setFilter('all')">全て</button>
          <button class="filter-btn" id="filter-unassigned" onclick="setFilter('unassigned')">未割当</button>
        </div>
        <div class="img-list" id="img-list">
          <?php foreach ($all_images as $img): ?>
          <?php
            $is_assigned = !empty($assigned_map[$img]);
            $assigned_to = $is_assigned ? implode(', ', $assigned_map[$img]) : '';
            $is_focus    = ($focus_img === $img);
          ?>
          <div class="img-list-item <?= $is_assigned ? 'assigned' : '' ?> <?= $is_focus ? 'selected' : '' ?>"
               data-img="<?= htmlspecialchars($img) ?>"
               data-assigned="<?= $is_assigned ? '1' : '0' ?>"
               onclick="selectImage(this, '<?= htmlspecialchars($img, ENT_QUOTES) ?>')">
            <img src="<?= htmlspecialchars('../' . $img) ?>" alt="">
            <div class="info">
              <div class="fname"><?= htmlspecialchars(basename($img)) ?></div>
              <div class="status">
                <?php if ($is_assigned): ?>
                <span class="badge-assigned">✓ <?= htmlspecialchars($assigned_to) ?></span>
                <?php else: ?>
                <span class="badge-unassigned">未割り当て</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- 右パネル：割り当て操作 -->
    <div class="panel" id="right-panel">
      <?php if ($focus_img && in_array($focus_img, $all_images)): ?>
      <!-- フォーカス画像がある場合は初期表示 -->
      <div class="selected-img-preview">
        <img src="<?= htmlspecialchars('../' . $focus_img) ?>" alt="">
        <div class="fname"><?= htmlspecialchars(basename($focus_img)) ?></div>
      </div>
      <div class="panel-header" style="font-size:0.9rem;">車両を選択して割り当て</div>
      <div class="vehicle-assign-list">
        <?php foreach ($vehicles as $v): ?>
        <?php $already = in_array($focus_img, $v['gallery'] ?? []); ?>
        <div class="vehicle-row">
          <?php if (!empty($v['gallery'][0])): ?>
          <img src="<?= htmlspecialchars('../' . $v['gallery'][0]) ?>" alt="">
          <?php else: ?>
          <div style="width:56px;height:40px;background:#e9ecef;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:0.65rem;color:#999;flex-shrink:0;">No img</div>
          <?php endif; ?>
          <div class="vinfo">
            <div class="vname"><?= htmlspecialchars($v['display_name_en'] ?? '') ?></div>
            <div class="vref"><?= htmlspecialchars($v['ref_id'] ?? '') ?></div>
          </div>
          <div class="vactions">
            <?php if ($already): ?>
            <span class="already-badge">✓ 割当済</span>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="unassign">
              <input type="hidden" name="img_path" value="<?= htmlspecialchars($focus_img) ?>">
              <input type="hidden" name="ref_id" value="<?= htmlspecialchars($v['ref_id']) ?>">
              <button type="submit" class="btn btn-danger btn-sm">解除</button>
            </form>
            <?php else: ?>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="assign">
              <input type="hidden" name="img_path" value="<?= htmlspecialchars($focus_img) ?>">
              <input type="hidden" name="ref_id" value="<?= htmlspecialchars($v['ref_id']) ?>">
              <input type="hidden" name="position" value="last">
              <button type="submit" class="btn btn-success btn-sm">+ 追加</button>
            </form>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="assign">
              <input type="hidden" name="img_path" value="<?= htmlspecialchars($focus_img) ?>">
              <input type="hidden" name="ref_id" value="<?= htmlspecialchars($v['ref_id']) ?>">
              <input type="hidden" name="position" value="first">
              <button type="submit" class="btn btn-primary btn-sm">メインに</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="no-selection">
        <div class="icon">👈</div>
        <p>左の画像を選択してください</p>
        <p style="font-size:0.82rem;margin-top:0.5rem;">選択した画像を車両に割り当てられます</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>

<script>
// 車両データ（PHP → JS）
const vehicles = <?= json_encode(array_map(fn($v) => [
    'ref_id'          => $v['ref_id'],
    'display_name_en' => $v['display_name_en'] ?? '',
    'thumb'           => !empty($v['gallery'][0]) ? '../' . $v['gallery'][0] : '',
    'gallery'         => $v['gallery'] ?? []
], $vehicles), JSON_UNESCAPED_UNICODE) ?>;

let selectedImg = <?= $focus_img ? json_encode($focus_img) : 'null' ?>;
let filterMode = 'all';

function selectImage(el, imgPath) {
  document.querySelectorAll('.img-list-item').forEach(i => i.classList.remove('selected'));
  el.classList.add('selected');
  selectedImg = imgPath;
  renderRightPanel(imgPath);
}

function renderRightPanel(imgPath) {
  const panel = document.getElementById('right-panel');
  const assignedRefs = [];
  vehicles.forEach(v => {
    if (v.gallery.includes(imgPath)) assignedRefs.push(v.ref_id);
  });

  let html = `
    <div class="selected-img-preview">
      <img src="../${imgPath}" alt="">
      <div class="fname">${imgPath.split('/').pop()}</div>
    </div>
    <div class="panel-header" style="font-size:0.9rem;">車両を選択して割り当て</div>
    <div class="vehicle-assign-list">`;

  vehicles.forEach(v => {
    const already = v.gallery.includes(imgPath);
    const thumb = v.thumb
      ? `<img src="${v.thumb}" alt="">`
      : `<div style="width:56px;height:40px;background:#e9ecef;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:0.65rem;color:#999;flex-shrink:0;">No img</div>`;

    const actions = already
      ? `<span class="already-badge">✓ 割当済</span>
         <form method="post" style="display:inline;">
           <input type="hidden" name="action" value="unassign">
           <input type="hidden" name="img_path" value="${imgPath}">
           <input type="hidden" name="ref_id" value="${v.ref_id}">
           <button type="submit" class="btn btn-danger btn-sm">解除</button>
         </form>`
      : `<form method="post" style="display:inline;">
           <input type="hidden" name="action" value="assign">
           <input type="hidden" name="img_path" value="${imgPath}">
           <input type="hidden" name="ref_id" value="${v.ref_id}">
           <input type="hidden" name="position" value="last">
           <button type="submit" class="btn btn-success btn-sm">+ 追加</button>
         </form>
         <form method="post" style="display:inline;">
           <input type="hidden" name="action" value="assign">
           <input type="hidden" name="img_path" value="${imgPath}">
           <input type="hidden" name="ref_id" value="${v.ref_id}">
           <input type="hidden" name="position" value="first">
           <button type="submit" class="btn btn-primary btn-sm">メインに</button>
         </form>`;

    html += `
      <div class="vehicle-row">
        ${thumb}
        <div class="vinfo">
          <div class="vname">${v.display_name_en}</div>
          <div class="vref">${v.ref_id}</div>
        </div>
        <div class="vactions">${actions}</div>
      </div>`;
  });

  html += `</div>`;
  panel.innerHTML = html;
}

function filterImages() {
  const q = document.getElementById('img-search').value.toLowerCase();
  document.querySelectorAll('.img-list-item').forEach(el => {
    const fname = el.querySelector('.fname').textContent.toLowerCase();
    const isAssigned = el.dataset.assigned === '1';
    const matchSearch = fname.includes(q);
    const matchFilter = filterMode === 'all' || (filterMode === 'unassigned' && !isAssigned);
    el.style.display = matchSearch && matchFilter ? '' : 'none';
  });
}

function setFilter(mode) {
  filterMode = mode;
  document.getElementById('filter-all').classList.toggle('active', mode === 'all');
  document.getElementById('filter-unassigned').classList.toggle('active', mode === 'unassigned');
  filterImages();
}
</script>
</body>
</html>
