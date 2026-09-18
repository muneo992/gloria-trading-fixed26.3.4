<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        sa_verify_same_origin();
        sa_verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            sa_attempt_login((string)($_POST['password'] ?? ''));
            sa_redirect('index.php');
        }
        if ($action === 'bootstrap') {
            sa_guarded_bootstrap(
                (string)($_POST['setup_key'] ?? ''),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['password_confirm'] ?? '')
            );
            sa_redirect('index.php');
        }
        if ($action === 'logout') {
            if (sa_admin_is_logged_in()) {
                sa_admin_logout();
            }
            header('Location: index.php', true, 303);
            exit;
        }
        throw new RuntimeException('Unknown request.');
    } catch (Throwable $exception) {
        $loginError = $exception->getMessage();
    }
}

$loggedIn = sa_admin_is_logged_in();
$configured = sa_admin_password_is_configured();
if (!$loggedIn):
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>South Africa Admin Login | Gloria Trading</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
  <main class="login-card">
    <p class="eyebrow">Gloria Trading</p>
    <h1>South Africa Admin</h1>
    <?php if (sa_admin_can_bootstrap()): ?>
      <p>Create the South Africa administrator password. This form is available only while a server-side setup key exists and no password hash has been stored.</p>
      <?php if ($loginError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= sa_h($loginError) ?></div>
      <?php endif; ?>
      <form method="post" class="stack" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= sa_h(sa_csrf_token()) ?>">
        <input type="hidden" name="action" value="bootstrap">
        <label for="setup_key">Setup key</label>
        <input id="setup_key" name="setup_key" type="password" autocomplete="off" required autofocus>
        <label for="new_password">New password</label>
        <input id="new_password" name="password" type="password" autocomplete="new-password" minlength="12" required>
        <label for="password_confirm">Confirm password</label>
        <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="12" required>
        <p class="field-help">Use at least 12 characters. The setup key is the one-time value from the Initialize South Africa Admin GitHub Actions summary, not the password. After this succeeds, the setup key is deleted and this form is closed.</p>
        <button type="submit" class="button button-primary">Save password</button>
      </form>
    <?php else: ?>
      <p>Sign in to manage South Africa sample vehicles.</p>
      <?php if (!$configured): ?>
        <div class="alert alert-error" role="alert">Authentication is not configured. Initial setup is locked until a setup key is created on the server.</div>
      <?php endif; ?>
      <?php if (isset($_GET['expired'])): ?>
        <div class="alert alert-info" role="status">Your session ended. Please sign in again.</div>
      <?php endif; ?>
      <?php if ($loginError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= sa_h($loginError) ?></div>
      <?php endif; ?>
      <form method="post" class="stack">
        <input type="hidden" name="csrf_token" value="<?= sa_h(sa_csrf_token()) ?>">
        <input type="hidden" name="action" value="login">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
        <button type="submit" class="button button-primary" <?= $configured ? '' : 'disabled' ?>>Sign in</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
<?php
exit;
endif;

$flash = sa_take_flash();
$dataError = '';
$vehicles = [];
$source = '';
try {
    $loaded = sa_load_vehicle_data(false, true);
    $vehicles = $loaded['data']['vehicles'];
    $source = $loaded['source'];
} catch (Throwable $exception) {
    error_log('South Africa admin list failed: ' . $exception->getMessage());
    $dataError = 'Vehicle data could not be loaded safely. Check the runtime JSON and file permissions before editing.';
}

function sa_admin_price_label(array $vehicle): string
{
    if (!isset($vehicle['reference_price_usd']) || $vehicle['reference_price_usd'] === null) {
        return 'Quotation';
    }
    return 'USD ' . number_format((int)$vehicle['reference_price_usd']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>South Africa Vehicle Admin | Gloria Trading</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<header class="admin-header">
  <div>
    <p class="eyebrow">Gloria Trading</p>
    <strong>South Africa Admin</strong>
  </div>
  <nav aria-label="Admin navigation">
    <a href="../vehicles.html" target="_blank" rel="noopener">View public vehicles</a>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= sa_h(sa_csrf_token()) ?>">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="link-button">Sign out</button>
    </form>
  </nav>
</header>
<main class="admin-container">
  <div class="page-heading">
    <div>
      <h1>Vehicles</h1>
      <p><?= count($vehicles) ?> registered vehicle<?= count($vehicles) === 1 ? '' : 's' ?>. Sample listings are not current stock.</p>
    </div>
    <?php if ($dataError === ''): ?><a class="button button-primary" href="edit.php">Add vehicle</a><?php endif; ?>
  </div>

  <?php if ($flash): ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-info' ?>" role="status"><?= sa_h($flash['message'] ?? '') ?></div>
  <?php endif; ?>
  <?php if ($dataError !== ''): ?>
    <div class="alert alert-error" role="alert"><?= sa_h($dataError) ?></div>
  <?php else: ?>
    <div class="data-source">Data source: <strong><?= $source === 'runtime' ? 'runtime operational data' : 'Git seed/fallback' ?></strong></div>
    <div class="table-card">
      <table>
        <thead><tr><th>Image</th><th>Ref</th><th>Vehicle</th><th>Year</th><th>Type</th><th>Status</th><th>FOB Japan</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($vehicles as $vehicle): ?>
          <?php $title = trim((string)($vehicle['display_name_en'] ?? '')) ?: trim(implode(' ', array_filter([$vehicle['year'] ?? '', $vehicle['make'] ?? '', $vehicle['model'] ?? '']))); ?>
          <tr>
            <td>
              <?php if (!empty($vehicle['gallery'][0])): ?>
                <img class="vehicle-thumb" src="../<?= sa_h($vehicle['gallery'][0]) ?>" alt="">
              <?php else: ?>
                <span class="thumb-placeholder">No photo</span>
              <?php endif; ?>
            </td>
            <td><strong><?= sa_h($vehicle['ref_id'] ?? '') ?></strong></td>
            <td><?= sa_h($title) ?></td>
            <td><?= sa_h($vehicle['year'] ?? '') ?></td>
            <td><?= sa_h(($vehicle['listing_type'] ?? '') === 'available' ? 'Available' : 'Sample') ?></td>
            <td><?= sa_h(($vehicle['status'] ?? '') === 'published' ? 'Published' : 'Draft') ?></td>
            <td><?= sa_h(sa_admin_price_label($vehicle)) ?></td>
            <td><a class="button button-secondary button-small" href="edit.php?ref=<?= urlencode((string)($vehicle['ref_id'] ?? '')) ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($vehicles === []): ?>
          <tr><td colspan="8" class="empty-table">No vehicles are registered.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
