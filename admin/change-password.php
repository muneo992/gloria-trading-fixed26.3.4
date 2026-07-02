<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$password_file = __DIR__ . '/password.txt';
$message = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $new_password = trim((string) ($_POST['new_password'] ?? ''));
    $confirm_password = trim((string) ($_POST['confirm_password'] ?? ''));

    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = '不正なリクエストです。もう一度お試しください。';
    } elseif (strlen($new_password) < 12) {
        $error = '新しいパスワードは12文字以上にしてください。';
    } elseif ($new_password !== $confirm_password) {
        $error = '確認用パスワードが一致しません。';
    } else {
        if (file_put_contents($password_file, $new_password . PHP_EOL, LOCK_EX) === false) {
            $error = 'password.txt への書き込みに失敗しました。admin フォルダの権限を確認してください。';
        } else {
            @chmod($password_file, 0600);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $message = '管理画面パスワードを更新しました。次回ログインから新しいパスワードを使用してください。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>パスワード変更 - Gloria Trading Admin</title>
<style>
body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:#f5f6fa; color:#222; }
.topbar { background:#1a1a2e; color:#fff; padding:0.8rem 1.2rem; display:flex; justify-content:space-between; align-items:center; }
.brand { font-weight:700; }
.nav a { color:#fff; text-decoration:none; margin-left:1rem; font-size:0.9rem; }
.container { max-width:620px; margin:2rem auto; background:#fff; border-radius:10px; padding:1.8rem; box-shadow:0 2px 8px rgba(0,0,0,.08); }
label { display:block; margin-top:1rem; font-weight:600; }
input[type=password] { width:100%; padding:.7rem; margin-top:.35rem; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; }
button { margin-top:1.4rem; padding:.75rem 1.2rem; border:none; border-radius:6px; background:#0066cc; color:#fff; font-weight:700; cursor:pointer; }
button:hover { background:#0052a3; }
.message { background:#e8f5e9; color:#1b5e20; padding:.8rem 1rem; border-radius:6px; margin-bottom:1rem; }
.error { background:#fdecea; color:#b00020; padding:.8rem 1rem; border-radius:6px; margin-bottom:1rem; }
.note { color:#666; font-size:.92rem; line-height:1.6; }
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">Gloria Trading 管理画面</div>
  <div class="nav"><a href="index.php">← 車両一覧</a><a href="index.php?logout=1">ログアウト</a></div>
</div>
<div class="container">
  <h1>管理パスワード変更</h1>
  <p class="note">新しいパスワードはサーバー上の <code>admin/password.txt</code> に保存されます。このファイルはGitHubには保存されません。</p>
  <?php if ($message): ?><div class="message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <label for="new_password">新しいパスワード</label>
    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="12">
    <label for="confirm_password">新しいパスワード（確認）</label>
    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required minlength="12">
    <button type="submit">パスワードを更新</button>
  </form>
</div>
</body>
</html>
