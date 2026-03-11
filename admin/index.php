<?php
session_start();

// --- 設定 ---
define('ADMIN_PASSWORD', 'gloria2025'); // ← ここでパスワードを変更してください
define('VEHICLES_JSON', __DIR__ . '/../data/vehicles.json');
define('IMAGES_DIR', __DIR__ . '/../images/vehicles/');
define('IMAGES_URL', '../images/vehicles/');

// --- ログイン処理 ---
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        $login_error = 'パスワードが違います。';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$logged_in = !empty($_SESSION['admin_logged_in']);

// --- ログインページ ---
if (!$logged_in) {
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>管理画面ログイン - Gloria Trading</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.login-box { background: #fff; padding: 2.5rem; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); width: 100%; max-width: 380px; }
.login-box h1 { font-size: 1.4rem; color: #1a1a2e; margin-bottom: 0.3rem; }
.login-box p { color: #666; font-size: 0.9rem; margin-bottom: 1.5rem; }
label { display: block; font-size: 0.85rem; font-weight: 600; color: #333; margin-bottom: 0.4rem; }
input[type=password] { width: 100%; padding: 0.75rem 1rem; border: 1px solid #ddd; border-radius: 6px; font-size: 1rem; outline: none; transition: border-color 0.2s; }
input[type=password]:focus { border-color: #0066cc; }
button { width: 100%; padding: 0.85rem; background: #0066cc; color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 1rem; transition: background 0.2s; }
button:hover { background: #0052a3; }
.error { background: #fff0f0; border: 1px solid #ffcccc; color: #cc0000; padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.9rem; margin-bottom: 1rem; }
.logo { text-align: center; margin-bottom: 1.5rem; font-size: 1.6rem; font-weight: 700; color: #0066cc; }
</style>
</head>
<body>
<div class="login-box">
  <div class="logo">Gloria Trading</div>
  <h1>管理画面</h1>
  <p>車両データ管理システム</p>
  <?php if (!empty($login_error)): ?>
  <div class="error"><?= htmlspecialchars($login_error) ?></div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="login">
    <label for="password">パスワード</label>
    <input type="password" id="password" name="password" placeholder="パスワードを入力" autofocus>
    <button type="submit">ログイン</button>
  </form>
</div>
</body>
</html>
<?php
    exit;
}

// --- 管理画面（ログイン済み） ---

// vehicles.json を読み込む
function loadVehicles() {
    if (!file_exists(VEHICLES_JSON)) return ['vehicles' => []];
    $json = file_get_contents(VEHICLES_JSON);
    return json_decode($json, true) ?: ['vehicles' => []];
}

function saveVehicles($data) {
    return file_put_contents(VEHICLES_JSON, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// --- 車両削除 ---
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $ref_id = $_POST['ref_id'] ?? '';
    $data = loadVehicles();
    $data['vehicles'] = array_values(array_filter($data['vehicles'], fn($v) => $v['ref_id'] !== $ref_id));
    saveVehicles($data);
    header('Location: index.php?deleted=1');
    exit;
}

$data = loadVehicles();
$vehicles = $data['vehicles'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>車両管理 - Gloria Trading Admin</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #333; }
.topbar { background: #1a1a2e; color: #fff; padding: 0.9rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
.topbar .brand { font-size: 1.1rem; font-weight: 700; color: #4da6ff; }
.topbar .nav a { color: #ccc; text-decoration: none; font-size: 0.9rem; margin-left: 1.2rem; }
.topbar .nav a:hover { color: #fff; }
.container { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
.page-header h1 { font-size: 1.4rem; color: #1a1a2e; }
.btn { display: inline-block; padding: 0.6rem 1.2rem; border-radius: 6px; font-size: 0.9rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
.btn-primary { background: #0066cc; color: #fff; }
.btn-primary:hover { background: #0052a3; }
.btn-success { background: #28a745; color: #fff; }
.btn-success:hover { background: #218838; }
.btn-danger { background: #dc3545; color: #fff; font-size: 0.8rem; padding: 0.4rem 0.8rem; }
.btn-danger:hover { background: #c82333; }
.btn-edit { background: #ffc107; color: #333; font-size: 0.8rem; padding: 0.4rem 0.8rem; }
.btn-edit:hover { background: #e0a800; }
.btn-import { background: #17a2b8; color: #fff; }
.btn-import:hover { background: #138496; }
.btn-export { background: #6f42c1; color: #fff; }
.btn-export:hover { background: #5a32a3; }
.alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
.alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
.header-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
.card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); overflow: hidden; }
.card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #eee; font-weight: 600; color: #1a1a2e; background: #fafafa; }
table { width: 100%; border-collapse: collapse; }
th { background: #f8f9fa; padding: 0.75rem 1rem; text-align: left; font-size: 0.85rem; color: #666; border-bottom: 2px solid #eee; white-space: nowrap; }
td { padding: 0.75rem 1rem; border-bottom: 1px solid #f0f0f0; font-size: 0.9rem; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafbff; }
.thumb { width: 70px; height: 50px; object-fit: cover; border-radius: 4px; background: #eee; }
.thumb-placeholder { width: 70px; height: 50px; background: #e9ecef; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; color: #999; }
.ref-badge { display: inline-block; background: #e8f0fe; color: #1a56db; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 600; }
.actions { display: flex; gap: 0.4rem; }
.empty-state { text-align: center; padding: 3rem; color: #999; }
.stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
.stat-card { background: #fff; border-radius: 10px; padding: 1.2rem 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
.stat-card .num { font-size: 2rem; font-weight: 700; color: #0066cc; }
.stat-card .label { font-size: 0.85rem; color: #666; margin-top: 0.2rem; }
@media (max-width: 768px) {
  .stats { grid-template-columns: 1fr; }
  table { font-size: 0.8rem; }
  td, th { padding: 0.5rem 0.6rem; }
}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">Gloria Trading 管理画面</div>
  <div class="nav">
    <a href="images-upload.php">🖼 画像管理</a>
    <a href="import.php">📥 インポート</a>
    <a href="export.php">📤 エクスポート</a>
    <a href="../index.html" target="_blank">サイトを見る</a>
    <a href="?logout=1">ログアウト</a>
  </div>
</div>

<div class="container">
  <?php if (isset($_GET['deleted'])): ?>
  <div class="alert alert-success">✅ 車両データを削除しました。</div>
  <?php endif; ?>
  <?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-success">✅ 車両データを保存しました。</div>
  <?php endif; ?>
  <?php if (isset($_GET['imported'])): ?>
  <div class="alert alert-success">✅ <?= (int)$_GET['imported'] ?>件の車両データをインポートしました（<?= $_GET['mode'] === 'replace' ? '全置換' : 'マージ' ?>）。</div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat-card">
      <div class="num"><?= count($vehicles) ?></div>
      <div class="label">登録車両数</div>
    </div>
    <div class="stat-card">
      <div class="num"><?= count(array_filter($vehicles, fn($v) => !empty($v['gallery']))) ?></div>
      <div class="label">画像あり</div>
    </div>
    <div class="stat-card">
      <div class="num"><?= count(array_filter($vehicles, fn($v) => empty($v['gallery']))) ?></div>
      <div class="label">画像なし</div>
    </div>
  </div>

  <div class="page-header">
    <h1>車両一覧</h1>
    <div class="header-actions">
      <a href="import.php" class="btn btn-import">📥 一括インポート</a>
      <a href="export.php" class="btn btn-export">📤 CSVエクスポート</a>
      <a href="edit.php" class="btn btn-primary">＋ 新規車両を追加</a>
    </div>
  </div>

  <div class="card">
    <div class="card-header">登録済み車両 (<?= count($vehicles) ?>件)</div>
    <?php if (empty($vehicles)): ?>
    <div class="empty-state">
      <p>まだ車両が登録されていません。</p>
      <a href="edit.php" class="btn btn-primary" style="margin-top:1rem;">最初の車両を追加する</a>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr>
          <th>画像</th>
          <th>Ref ID</th>
          <th>車両名</th>
          <th>年式</th>
          <th>走行距離</th>
          <th>価格 (USD)</th>
          <th>基準期間</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($vehicles as $v): ?>
        <tr>
          <td>
            <?php if (!empty($v['gallery'][0])): ?>
            <img src="<?= htmlspecialchars('../' . $v['gallery'][0]) ?>" class="thumb" alt="">
            <?php else: ?>
            <div class="thumb-placeholder">No img</div>
            <?php endif; ?>
          </td>
          <td><span class="ref-badge"><?= htmlspecialchars($v['ref_id'] ?? '') ?></span></td>
          <td><?= htmlspecialchars($v['display_name_en'] ?? '') ?></td>
          <td><?= htmlspecialchars($v['year'] ?? '') ?></td>
          <td><?= isset($v['mileage_km']) ? number_format($v['mileage_km']) . ' km' : '-' ?></td>
          <td>$<?= isset($v['price_low_usd']) ? number_format($v['price_low_usd']) : '-' ?> – $<?= isset($v['price_high_usd']) ? number_format($v['price_high_usd']) : '-' ?></td>
          <td><?= htmlspecialchars(($v['basis_from'] ?? '') . ' – ' . ($v['basis_to'] ?? '')) ?></td>
          <td>
            <div class="actions">
              <a href="edit.php?ref=<?= urlencode($v['ref_id'] ?? '') ?>" class="btn btn-edit">編集</a>
              <form method="post" onsubmit="return confirm('「<?= htmlspecialchars($v['display_name_en'] ?? '') ?>」を削除しますか？');" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="ref_id" value="<?= htmlspecialchars($v['ref_id'] ?? '') ?>">
                <button type="submit" class="btn btn-danger">削除</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
