<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
ea_require_admin();

$errors = [];
$loaded = null;
try {
    $loaded = ea_load_vehicle_data(false, true);
} catch (Throwable $exception) {
    error_log('East Africa admin editor load failed: ' . $exception->getMessage());
    http_response_code(500);
    $errors[] = 'Vehicle data could not be loaded safely. Editing is disabled until the data or permissions are corrected.';
}

$queryRef = strtoupper(trim((string)($_GET['ref'] ?? '')));
$isEdit = $queryRef !== '';
$existing = [];
if ($loaded !== null && $isEdit) {
    $found = ea_find_vehicle($loaded['data'], $queryRef);
    if ($found === null) {
        http_response_code(404);
        $errors[] = 'The requested vehicle was not found.';
    } else {
        $existing = $found['vehicle'];
    }
}

$form = $existing + [
    'ref_id' => '',
    'display_name_en' => '',
    'make' => '',
    'model' => '',
    'grade' => '',
    'year' => '',
    'fuel_type' => '',
    'mileage_km' => '',
    'engine_cc' => '',
    'transmission' => '',
    'drive' => '',
    'steering' => '',
    'auction_grade' => '',
    'reference_price_usd' => null,
    'estimated_cif_mombasa_usd' => null,
    'price_as_of' => '',
    'gallery' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loaded !== null) {
    $createdImages = [];
    try {
        ea_verify_same_origin();
        ea_verify_csrf($_POST['csrf_token'] ?? null);
        if (($_POST['action'] ?? '') !== 'save') {
            throw new RuntimeException('Unknown request.');
        }
        $expectedVersion = (string)($_POST['data_version'] ?? '');
        if ($expectedVersion === '' || !hash_equals($loaded['version'], $expectedVersion)) {
            throw new EaConflictException('Vehicle data changed after this form was opened. Reload and try again.');
        }
        $originalRefRaw = trim((string)($_POST['original_ref'] ?? ''));
        $originalRef = $originalRefRaw === '' ? null : strtoupper($originalRefRaw);
        if ($isEdit && $originalRef !== $queryRef) {
            throw new EaConflictException('The edit target changed. Reload and try again.');
        }
        if (!$isEdit && $originalRef !== null) {
            throw new EaValidationException('New vehicle request is invalid.');
        }
        $postRef = strtoupper(trim((string)($_POST['ref_id'] ?? '')));
        $createdImages = ea_store_uploaded_images($_FILES['new_images'] ?? [], $postRef);
        $currentGallery = array_values($existing['gallery'] ?? []);
        $gallery = ea_gallery_from_order((string)($_POST['gallery_order'] ?? '[]'), $currentGallery, $createdImages);
        $record = ea_build_vehicle_record($existing, $_POST, $gallery);
        ea_save_vehicle_record($record, $originalRef, $expectedVersion);
        ea_flash('success', ($isEdit ? 'Vehicle updated: ' : 'Vehicle added: ') . $record['ref_id']);
        ea_redirect('index.php');
    } catch (Throwable $exception) {
        ea_remove_created_images($createdImages);
        $errors[] = $exception->getMessage();
        foreach ($form as $field => $value) {
            if ($field !== 'gallery' && array_key_exists($field, $_POST)) {
                $form[$field] = $_POST[$field];
            }
        }
    }
}

$canEdit = $loaded !== null && (!$isEdit || $existing !== []);
$gallery = array_values($existing['gallery'] ?? []);
$galleryTokens = array_map(static fn(string $path): string => 'old:' . $path, $gallery);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEdit ? 'Edit vehicle' : 'Add vehicle' ?> | East Africa Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <script src="assets/admin.js" defer></script>
</head>
<body>
<header class="admin-header">
  <div>
    <p class="eyebrow">Gloria Trading</p>
    <strong>East Africa Admin</strong>
  </div>
  <nav aria-label="Admin navigation">
    <a href="index.php">Vehicle list</a>
    <a href="../vehicles.html" target="_blank" rel="noopener">View public vehicles</a>
  </nav>
</header>
<main class="admin-container form-container">
  <div class="page-heading">
    <div>
      <h1><?= $isEdit ? 'Edit vehicle' : 'Add vehicle' ?></h1>
      <p>Public behavior remains “Reference vehicle · Not in stock”.</p>
    </div>
    <a class="button button-secondary" href="index.php">Cancel</a>
  </div>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert"><strong>Could not save.</strong><ul><?php foreach ($errors as $error): ?><li><?= ea_h($error) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if ($canEdit): ?>
  <form method="post" enctype="multipart/form-data" id="vehicle-form" class="stack-large">
    <input type="hidden" name="csrf_token" value="<?= ea_h(ea_csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="original_ref" value="<?= $isEdit ? ea_h($queryRef) : '' ?>">
    <input type="hidden" name="data_version" value="<?= ea_h($loaded['version']) ?>">
    <input type="hidden" name="gallery_order" id="gallery-order" value="<?= ea_h(json_encode($galleryTokens, JSON_UNESCAPED_SLASHES)) ?>">

    <section class="form-card">
      <div class="card-heading"><h2>Basic information</h2></div>
      <div class="form-grid">
        <div class="field">
          <label for="ref_id">Ref ID <span aria-hidden="true">*</span></label>
          <input id="ref_id" name="ref_id" value="<?= ea_h($form['ref_id']) ?>" pattern="EA-[A-Z0-9]+(?:-[A-Z0-9]+)*" maxlength="50" required <?= $isEdit ? 'readonly' : '' ?> placeholder="EA-PBX-004">
          <p class="field-help">Uppercase letters, numbers, and hyphens. It cannot be changed later.</p>
        </div>
        <div class="field">
          <label for="display_name_en">Display name</label>
          <input id="display_name_en" name="display_name_en" value="<?= ea_h($form['display_name_en']) ?>" maxlength="150" placeholder="2025 Toyota Probox Hybrid">
        </div>
        <div class="field">
          <label for="make">Make <span aria-hidden="true">*</span></label>
          <input id="make" name="make" value="<?= ea_h($form['make']) ?>" maxlength="100" required>
        </div>
        <div class="field">
          <label for="model">Model <span aria-hidden="true">*</span></label>
          <input id="model" name="model" value="<?= ea_h($form['model']) ?>" maxlength="100" required>
        </div>
        <div class="field">
          <label for="grade">Grade</label>
          <input id="grade" name="grade" value="<?= ea_h($form['grade']) ?>" maxlength="150">
        </div>
        <div class="field">
          <label for="year">Year <span aria-hidden="true">*</span></label>
          <input id="year" name="year" type="number" min="1900" max="2100" value="<?= ea_h($form['year']) ?>" required>
        </div>
      </div>
    </section>

    <section class="form-card">
      <div class="card-heading"><h2>Specifications</h2></div>
      <div class="form-grid">
        <div class="field"><label for="fuel_type">Fuel type</label><input id="fuel_type" name="fuel_type" value="<?= ea_h($form['fuel_type']) ?>" maxlength="150" list="fuel-options"><datalist id="fuel-options"><option value="Petrol"><option value="Diesel"><option value="Hybrid"><option value="Electric"><option value="LPG"></datalist></div>
        <div class="field"><label for="transmission">Transmission</label><input id="transmission" name="transmission" value="<?= ea_h($form['transmission']) ?>" maxlength="150" list="transmission-options"><datalist id="transmission-options"><option value="Automatic"><option value="Manual"><option value="CVT"></datalist></div>
        <div class="field"><label for="mileage_km">Mileage (km)</label><input id="mileage_km" name="mileage_km" type="number" min="0" value="<?= ea_h($form['mileage_km']) ?>"></div>
        <div class="field"><label for="engine_cc">Engine (cc)</label><input id="engine_cc" name="engine_cc" type="number" min="0" value="<?= ea_h($form['engine_cc']) ?>"></div>
        <div class="field"><label for="drive">Drive</label><input id="drive" name="drive" value="<?= ea_h($form['drive']) ?>" maxlength="150" placeholder="2WD or 4WD"></div>
        <div class="field"><label for="steering">Steering</label><input id="steering" name="steering" value="<?= ea_h($form['steering']) ?>" maxlength="150" placeholder="Right hand drive"></div>
        <div class="field"><label for="auction_grade">Auction grade</label><input id="auction_grade" name="auction_grade" value="<?= ea_h($form['auction_grade']) ?>" maxlength="150"></div>
      </div>
    </section>

    <section class="form-card">
      <div class="card-heading"><h2>Reference prices</h2></div>
      <div class="form-grid">
        <div class="field"><label for="reference_price_usd">FOB / reference price (USD)</label><input id="reference_price_usd" name="reference_price_usd" type="number" min="1" value="<?= ea_h($form['reference_price_usd'] ?? '') ?>" placeholder="Leave blank for quotation"></div>
        <div class="field"><label for="estimated_cif_mombasa_usd">Estimated CIF Mombasa (USD)</label><input id="estimated_cif_mombasa_usd" name="estimated_cif_mombasa_usd" type="number" min="1" value="<?= ea_h($form['estimated_cif_mombasa_usd'] ?? '') ?>" placeholder="Leave blank for quotation"></div>
        <div class="field"><label for="price_as_of">Price reference date</label><input id="price_as_of" name="price_as_of" type="date" value="<?= ea_h($form['price_as_of']) ?>"><p class="field-help">Required when either price is entered.</p></div>
      </div>
    </section>

    <section class="form-card">
      <div class="card-heading">
        <div><h2>Vehicle photos</h2><p>The first photo is used as the list image. Phase 1 does not delete image files.</p></div>
      </div>
      <div id="gallery-list" class="gallery-admin" aria-live="polite">
        <?php foreach ($gallery as $index => $path): ?>
          <article class="gallery-admin-item" data-token="old:<?= ea_h($path) ?>">
            <img src="../<?= ea_h($path) ?>" alt="">
            <div><strong class="gallery-position"><?= $index === 0 ? 'Main photo' : 'Photo ' . ($index + 1) ?></strong><span><?= ea_h(basename($path)) ?></span></div>
            <div class="gallery-actions"><button class="button button-small button-secondary move-up" type="button">Move up</button><button class="button button-small button-secondary move-down" type="button">Move down</button></div>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="field upload-field">
        <label for="new_images">Add photos</label>
        <input id="new_images" name="new_images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
        <p class="field-help">JPEG, PNG, or WebP. Maximum 20 files, 10 MiB each, 40 MiB total.</p>
      </div>
    </section>

    <div class="form-actions"><a class="button button-secondary" href="index.php">Cancel</a><button class="button button-primary" type="submit">Save vehicle</button></div>
  </form>
  <?php endif; ?>
</main>
</body>
</html>
