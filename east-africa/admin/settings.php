<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
ea_require_admin();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ea_verify_same_origin();
        ea_verify_csrf($_POST['csrf_token'] ?? null);
        if (($_POST['action'] ?? '') !== 'change_password') {
            throw new RuntimeException('Unknown request.');
        }
        ea_change_admin_password(
            (string)($_POST['current_password'] ?? ''),
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['new_password_confirm'] ?? '')
        );
        ea_admin_logout();
        ea_admin_start_session();
        ea_flash('success', 'Password updated. Sign in with the new password.');
        ea_redirect('index.php');
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Change password | East Africa Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<header class="admin-header">
  <div>
    <p class="eyebrow">Gloria Trading</p>
    <strong>East Africa Admin</strong>
  </div>
  <nav aria-label="Admin navigation">
    <a href="index.php">Vehicle list</a>
    <a href="settings.php" aria-current="page">Change password</a>
    <a href="../vehicles.html" target="_blank" rel="noopener">View public vehicles</a>
  </nav>
</header>
<main class="admin-container form-container">
  <div class="page-heading">
    <div>
      <h1>Change password</h1>
      <p>The current password is required. Only a password hash is stored outside the public web root.</p>
    </div>
    <a class="button button-secondary" href="index.php">Back to vehicles</a>
  </div>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert"><strong>Could not change the password.</strong><ul><?php foreach ($errors as $error): ?><li><?= ea_h($error) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="stack-large" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= ea_h(ea_csrf_token()) ?>">
    <input type="hidden" name="action" value="change_password">
    <section class="form-card">
      <div class="card-heading">
        <h2>Administrator password</h2>
        <p>Use at least <?= EA_ADMIN_PASSWORD_MIN_LENGTH ?> characters. Forgotten passwords cannot be recovered from this page.</p>
      </div>
      <div class="form-grid">
        <div class="field">
          <label for="current_password">Current password <span aria-hidden="true">*</span></label>
          <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        </div>
        <div class="field">
          <label for="new_password">New password <span aria-hidden="true">*</span></label>
          <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="<?= EA_ADMIN_PASSWORD_MIN_LENGTH ?>" required>
        </div>
        <div class="field">
          <label for="new_password_confirm">Confirm new password <span aria-hidden="true">*</span></label>
          <input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" minlength="<?= EA_ADMIN_PASSWORD_MIN_LENGTH ?>" required>
        </div>
      </div>
    </section>
    <div class="form-actions">
      <a class="button button-secondary" href="index.php">Cancel</a>
      <button class="button button-primary" type="submit">Save password</button>
    </div>
  </form>
</main>
</body>
</html>
