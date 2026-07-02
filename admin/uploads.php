<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/bootstrap.php';

function gt_safe_filename($name) {
    $name = basename($name);
    $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    return $name ?: ('file_' . time());
}

if (!is_dir(GENERAL_UPLOAD_DIR)) mkdir(GENERAL_UPLOAD_DIR, 0755, true);
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (empty($_FILES['files']['name'][0])) {
        $errors[] = 'アップロードするファイルを選択してください。';
    } else {
        $allowed = [
            'image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain','text/csv',
            'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip','application/x-zip-compressed'
        ];
        $count = 0;
        foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $mime = mime_content_type($tmp);
            if (!in_array($mime, $allowed, true)) { $errors[] = $_FILES['files']['name'][$i] . ' は許可されていない形式です。'; continue; }
            $filename = date('YmdHis') . '_' . sprintf('%02d', $i + 1) . '_' . gt_safe_filename($_FILES['files']['name'][$i]);
            if (move_uploaded_file($tmp, GENERAL_UPLOAD_DIR . $filename)) $count++;
        }
        if ($count > 0) $messages[] = $count . '件のファイルをアップロードしました。';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $name = gt_safe_filename($_POST['name'] ?? '');
    $path = GENERAL_UPLOAD_DIR . $name;
    if ($name && file_exists($path)) {
        @unlink($path);
        $messages[] = 'ファイルを削除しました。';
    }
}

$files = [];
foreach (glob(GENERAL_UPLOAD_DIR . '*') as $f) {
    if (is_file($f)) {
        $files[] = [
            'name' => basename($f),
            'url' => '../' . GENERAL_UPLOAD_URL . basename($f),
            'size' => filesize($f),
            'mtime' => filemtime($f),
            'mime' => mime_content_type($f),
        ];
    }
}
usort($files, function ($a, $b) {
    if ($a['mtime'] == $b['mtime']) return 0;
    return ($a['mtime'] < $b['mtime']) ? 1 : -1;
});
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>汎用アップロード - Gloria Trading Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f2f5;color:#333}.topbar{background:#1a1a2e;color:#fff;padding:.9rem 1.5rem;display:flex;align-items:center;justify-content:space-between}.topbar .brand{font-size:1.1rem;font-weight:700;color:#4da6ff}.topbar .nav a{color:#ccc;text-decoration:none;font-size:.9rem;margin-left:1.2rem}.topbar .nav a:hover{color:#fff}.container{max-width:1000px;margin:0 auto;padding:1.5rem}.page-header{margin-bottom:1.5rem}.page-header h1{font-size:1.4rem;color:#1a1a2e}.page-header p{color:#666;font-size:.9rem;margin-top:.3rem}.card{background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.07);margin-bottom:1.5rem;overflow:hidden}.card-header{padding:1rem 1.5rem;border-bottom:1px solid #eee;font-weight:600;color:#1a1a2e;background:#fafafa}.card-body{padding:1.5rem}.btn{display:inline-block;padding:.55rem 1rem;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;cursor:pointer;border:none}.btn-primary{background:#0066cc;color:#fff}.btn-danger{background:#dc3545;color:#fff}.alert{padding:.8rem 1rem;border-radius:6px;margin-bottom:.7rem;font-size:.9rem}.alert-success{background:#d4edda;border:1px solid #c3e6cb;color:#155724}.alert-danger{background:#fff0f0;border:1px solid #ffcccc;color:#cc0000}.upload-zone{border:2px dashed #ccc;border-radius:10px;padding:2rem;text-align:center;background:#fafafa;margin-bottom:1rem}table{width:100%;border-collapse:collapse}th{background:#f8f9fa;padding:.7rem;text-align:left;border-bottom:2px solid #eee;font-size:.85rem}td{padding:.7rem;border-bottom:1px solid #eee;font-size:.85rem;vertical-align:middle}.file-url{font-family:monospace;font-size:.78rem;color:#555;word-break:break-all}.thumb{width:72px;height:54px;object-fit:cover;border-radius:4px;border:1px solid #ddd}
</style>
</head>
<body>
<div class="topbar"><div class="brand">Gloria Trading 管理画面</div><div class="nav"><a href="index.php">← 車両一覧</a><a href="images-upload.php">画像管理</a><a href="../index.html" target="_blank">サイトを見る</a><a href="index.php?logout=1">ログアウト</a></div></div>
<div class="container">
  <div class="page-header"><h1>汎用アップロード</h1><p>見積もりやカタログ車両とは直接関係しないファイルを保存できます。保存先は <code>uploads/general/</code> です。</p></div>
  <?php foreach ($messages as $m): ?><div class="alert alert-success"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>
  <?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  <div class="card"><div class="card-header">ファイルをアップロード</div><div class="card-body">
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload"><div class="upload-zone"><input type="file" name="files[]" multiple><p>複数ファイルを選択できます。画像、PDF、Word、Excel、CSV、TXT、ZIPに対応。</p></div><button class="btn btn-primary" type="submit">アップロード</button></form>
  </div></div>
  <div class="card"><div class="card-header">アップロード済みファイル（<?= count($files) ?>件）</div><div class="card-body">
    <?php if (empty($files)): ?><p style="color:#888;">まだファイルがありません。</p><?php else: ?>
    <table><thead><tr><th>プレビュー</th><th>ファイル名</th><th>URL</th><th>サイズ</th><th>更新日</th><th>操作</th></tr></thead><tbody>
    <?php foreach ($files as $f): ?><tr>
      <td><?php if (strpos($f['mime'], 'image/') === 0): ?><img src="<?= htmlspecialchars($f['url']) ?>" class="thumb"><?php else: ?>—<?php endif; ?></td>
      <td><a href="<?= htmlspecialchars($f['url']) ?>" target="_blank"><?= htmlspecialchars($f['name']) ?></a></td>
      <td class="file-url"><?= htmlspecialchars(str_replace('../', '', $f['url'])) ?></td>
      <td><?= number_format($f['size'] / 1024, 1) ?> KB</td>
      <td><?= date('Y-m-d H:i', $f['mtime']) ?></td>
      <td><form method="post" onsubmit="return confirm('削除しますか？');"><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= htmlspecialchars($f['name']) ?>"><button class="btn btn-danger" type="submit">削除</button></form></td>
    </tr><?php endforeach; ?></tbody></table>
    <?php endif; ?>
  </div></div>
</div>
</body>
</html>
