<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ea_verify_same_origin();
        ea_verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            ea_attempt_login((string)($_POST['password'] ?? ''));
            ea_redirect('index.php');
        }
        if ($action === 'logout') {
            if (ea_admin_is_logged_in()) {
                ea_admin_logout();
            }
            header('Location: index.php', true, 303);
            exit;
        }
        throw new RuntimeException('Unknown request.');
    } catch (Throwable $exception) {
        $loginError = $exception->getMessage();
    }
}

$loggedIn = ea_admin_is_logged_in();
$configured = ea_admin_password_is_configured();
if (!$loggedIn):
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>East Africa Admin Login | Gloria Trading</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
  <main class="login-card">
    <p class="eyebrow">Gloria Trading</p>
    <h1>East Africa Admin</h1>
    <p>Sign in to manage East Africa reference vehicles.</p>
    <?php if (!$configured): ?>
      <div class="alert alert-error" role="alert">Authentication is not configured. Complete the server setup described in <code>scripts/EAST_AFRICA_ADMIN_PHASE1.md</code>.</div>
    <?php endif; ?>
    <p class="field-help">There is no public password reset. If you cannot sign in, an operator can replace only the password hash with GitHub Actions <code>Initialize East Africa Admin Runtime</code> using <code>RESET_EA_ADMIN_PASSWORD</code> after updating the <code>EA_ADMIN_PASSWORD</code> secret. Vehicle data and images are not changed.</p>
    <?php if (isset($_GET['expired'])): ?>
      <div class="alert alert-info" role="status">Your session ended. Please sign in again.</div>
    <?php endif; ?>
    <?php if ($loginError !== ''): ?>
      <div class="alert alert-error" role="alert"><?= ea_h($loginError) ?></div>
    <?php endif; ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf_token" value="<?= ea_h(ea_csrf_token()) ?>">
      <input type="hidden" name="action" value="login">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
      <button type="submit" class="button button-primary" <?= $configured ? '' : 'disabled' ?>>Sign in</button>
    </form>
  </main>
</body>
</html>
<?php
exit;
endif;

$flash = ea_take_flash();
$dataError = '';
$vehicles = [];
$source = '';
try {
    $loaded = ea_load_vehicle_data(false, true);
    $vehicles = $loaded['data']['vehicles'];
    $source = $loaded['source'];
} catch (Throwable $exception) {
    error_log('East Africa admin list failed: ' . $exception->getMessage());
    $dataError = 'Vehicle data could not be loaded safely. Check the runtime JSON and file permissions before editing.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>East Africa Vehicle Admin | Gloria Trading</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<header class="admin-header">
  <div>
    <p class="eyebrow">Gloria Trading</p>
    <strong>East Africa Admin</strong>
  </div>
  <nav aria-label="Admin navigation">
    <a href="settings.php">Change password</a>
    <a href="../vehicles.html" target="_blank" rel="noopener">View public vehicles</a>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= ea_h(ea_csrf_token()) ?>">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="link-button">Sign out</button>
    </form>
  </nav>
</header>
<main class="admin-container">
  <div class="page-heading">
    <div>
      <h1>Vehicles</h1>
      <p><?= count($vehicles) ?> registered reference vehicle<?= count($vehicles) === 1 ? '' : 's' ?>.</p>
    </div>
    <?php if ($dataError === ''): ?><a class="button button-primary" href="edit.php">Add vehicle</a><?php endif; ?>
  </div>

  <?php if ($flash): ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-info' ?>" role="status"><?= ea_h($flash['message'] ?? '') ?></div>
  <?php endif; ?>
  <?php if ($dataError !== ''): ?>
    <div class="alert alert-error" role="alert"><?= ea_h($dataError) ?></div>
  <?php else: ?>
    <div class="data-source">Data source: <strong><?= $source === 'runtime' ? 'runtime operational data' : 'Git seed/fallback' ?></strong></div>
    <div class="table-card">
      <table>
        <thead><tr><th>Image</th><th>Ref ID</th><th>Vehicle</th><th>Year</th><th>Fuel</th><th>FOB</th><th>Estimated CIF Mombasa</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($vehicles as $vehicle): ?>
          <?php $title = trim((string)($vehicle['display_name_en'] ?? '')) ?: trim(implode(' ', array_filter([$vehicle['year'] ?? '', $vehicle['make'] ?? '', $vehicle['model'] ?? '', $vehicle['grade'] ?? '']))); ?>
          <tr>
            <td>
              <?php if (!empty($vehicle['gallery'][0])): ?>
                <img class="vehicle-thumb" src="../<?= ea_h($vehicle['gallery'][0]) ?>" alt="">
              <?php else: ?>
                <span class="thumb-placeholder">No photo</span>
              <?php endif; ?>
            </td>
            <td><strong><?= ea_h($vehicle['ref_id'] ?? '') ?></strong></td>
            <td><?= ea_h($title) ?></td>
            <td><?= ea_h($vehicle['year'] ?? '') ?></td>
            <td><?= ea_h($vehicle['fuel_type'] ?? 'Not provided') ?></td>
            <td><?= isset($vehicle['reference_price_usd']) && $vehicle['reference_price_usd'] !== null ? 'USD ' . number_format((int)$vehicle['reference_price_usd']) : 'Quotation' ?></td>
            <td><?= isset($vehicle['estimated_cif_mombasa_usd']) && $vehicle['estimated_cif_mombasa_usd'] !== null ? 'USD ' . number_format((int)$vehicle['estimated_cif_mombasa_usd']) : 'Quotation' ?></td>
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
