<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
sa_require_admin();

$errors = [];
$loaded = null;
try {
    $loaded = sa_load_vehicle_data(false, true);
} catch (Throwable $exception) {
    error_log('South Africa admin editor load failed: ' . $exception->getMessage());
    http_response_code(500);
    $errors[] = 'Vehicle data could not be loaded safely. Editing is disabled until the data or permissions are corrected.';
}

$queryRef = strtoupper(trim((string)($_GET['ref'] ?? '')));
$isEdit = $queryRef !== '';
$existing = [];
if ($loaded !== null && $isEdit) {
    $found = sa_find_vehicle($loaded['data'], $queryRef);
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
    'year' => '',
    'mileage_km' => '',
    'powertrain' => '',
    'battery' => '',
    'range' => '',
    'body' => '',
    'doors' => '',
    'transmission' => '',
    'steering' => 'RHD',
    'notes' => '',
    'auction_condition' => '',
    'video_url' => '',
    'listing_type' => 'sample',
    'status' => 'draft',
    'reference_price_usd' => null,
    'price_as_of' => '',
    'gallery' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loaded !== null) {
    $createdImages = [];
    try {
        sa_verify_same_origin();
        sa_verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');
        $expectedVersion = (string)($_POST['data_version'] ?? '');
        $originalRefRaw = trim((string)($_POST['original_ref'] ?? ''));
        $originalRef = $originalRefRaw === '' ? null : strtoupper($originalRefRaw);
        if ($isEdit && $originalRef !== $queryRef) {
            throw new SaConflictException('The edit target changed. Reload and try again.');
        }
        if ($action === 'delete') {
            if (!$isEdit || $originalRef === null) {
                throw new SaValidationException('Only a saved vehicle can be deleted.');
            }
            if (($_POST['confirm_delete'] ?? '') !== 'yes') {
                throw new SaValidationException('Tick the confirmation box to delete this vehicle.');
            }
            sa_delete_vehicle_record($originalRef, $expectedVersion);
            sa_flash('success', 'Vehicle deleted: ' . $originalRef);
            sa_redirect('index.php');
        }
        if ($action !== 'save') {
            throw new RuntimeException('Unknown request.');
        }
        if (!$isEdit && $originalRef !== null) {
            throw new SaValidationException('New vehicle request is invalid.');
        }
        $postRef = strtoupper(trim((string)($_POST['ref_id'] ?? '')));
        $createdImages = sa_store_uploaded_images($_FILES['new_images'] ?? [], $postRef);
        $currentGallery = array_values($existing['gallery'] ?? []);
        $gallery = sa_gallery_from_order((string)($_POST['gallery_order'] ?? '[]'), $currentGallery, $createdImages);
        $record = sa_build_vehicle_record($existing, $_POST, $gallery);
        sa_save_vehicle_record($record, $originalRef, $expectedVersion);
        sa_flash('success', ($isEdit ? 'Vehicle updated: ' : 'Vehicle added: ') . $record['ref_id']);
        sa_redirect('index.php');
    } catch (Throwable $exception) {
        sa_remove_created_images($createdImages);
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
  <title><?= $isEdit ? 'Edit vehicle' : 'Add vehicle' ?> | South Africa Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <script src="assets/admin.js" defer></script>
</head>
<body>
<header class="admin-header">
  <div>
    <p class="eyebrow">Gloria Trading</p>
    <strong>South Africa Admin</strong>
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
      <p>Leave unknown fields blank. Do not guess battery, range, price or import eligibility.</p>
    </div>
    <a class="button button-secondary" href="index.php">Cancel</a>
  </div>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert"><strong>Could not save.</strong><ul><?php foreach ($errors as $error): ?><li><?= sa_h($error) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if ($canEdit): ?>
  <form method="post" enctype="multipart/form-data" id="vehicle-form" class="stack-large">
    <input type="hidden" name="csrf_token" value="<?= sa_h(sa_csrf_token()) ?>">
    <input type="hidden" name="original_ref" value="<?= $isEdit ? sa_h($queryRef) : '' ?>">
    <input type="hidden" name="data_version" value="<?= sa_h($loaded['version']) ?>">
    <input type="hidden" name="gallery_order" id="gallery-order" value="<?= sa_h(json_encode($galleryTokens, JSON_UNESCAPED_SLASHES)) ?>">

    <section class="form-card">
      <div class="card-heading"><h2>Listing</h2></div>
      <div class="form-grid">
        <div class="field">
          <label for="ref_id">Reference No. <span aria-hidden="true">*</span></label>
          <input id="ref_id" name="ref_id" value="<?= sa_h($form['ref_id']) ?>" pattern="SA-[A-Z0-9]+(?:-[A-Z0-9]+)*" maxlength="50" required <?= $isEdit ? 'readonly' : '' ?> placeholder="SA-LEAF-001">
          <p class="field-help">Uppercase letters, numbers and hyphens. Cannot be changed later.</p>
        </div>
        <div class="field">
          <label for="listing_type">Listing type <span aria-hidden="true">*</span></label>
          <select id="listing_type" name="listing_type" required>
            <option value="sample" <?= ($form['listing_type'] ?? '') === 'available' ? '' : 'selected' ?>>Sample — not current stock</option>
            <option value="available" <?= ($form['listing_type'] ?? '') === 'available' ? 'selected' : '' ?>>Available — confirm before purchase</option>
          </select>
        </div>
        <div class="field">
          <label for="status">Status <span aria-hidden="true">*</span></label>
          <select id="status" name="status" required>
            <option value="draft" <?= ($form['status'] ?? '') === 'published' ? '' : 'selected' ?>>Draft — hidden from the public site</option>
            <option value="published" <?= ($form['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published — shown on Vehicles</option>
          </select>
        </div>
      </div>
    </section>

    <section class="form-card">
      <div class="card-heading"><h2>Basic information</h2></div>
      <div class="form-grid">
        <div class="field"><label for="make">Make <span aria-hidden="true">*</span></label><input id="make" name="make" value="<?= sa_h($form['make']) ?>" maxlength="100" required></div>
        <div class="field"><label for="model">Model <span aria-hidden="true">*</span></label><input id="model" name="model" value="<?= sa_h($form['model']) ?>" maxlength="100" required></div>
        <div class="field"><label for="display_name_en">Display name</label><input id="display_name_en" name="display_name_en" value="<?= sa_h($form['display_name_en']) ?>" maxlength="150"></div>
        <div class="field"><label for="year">Model year</label><input id="year" name="year" type="number" min="1900" max="2100" value="<?= sa_h($form['year'] ?? '') ?>"><p class="field-help">Leave blank if not confirmed.</p></div>
        <div class="field"><label for="mileage_km">Mileage (km)</label><input id="mileage_km" name="mileage_km" type="number" min="0" value="<?= sa_h($form['mileage_km'] ?? '') ?>"></div>
        <div class="field">
          <label for="powertrain">EV / PHEV</label>
          <select id="powertrain" name="powertrain">
            <option value="" <?= ($form['powertrain'] ?? '') === '' ? 'selected' : '' ?>>Not confirmed</option>
            <option value="EV" <?= ($form['powertrain'] ?? '') === 'EV' ? 'selected' : '' ?>>EV</option>
            <option value="PHEV" <?= ($form['powertrain'] ?? '') === 'PHEV' ? 'selected' : '' ?>>PHEV</option>
          </select>
        </div>
      </div>
    </section>

    <section class="form-card">
      <div class="card-heading"><h2>EV / PHEV details</h2><p>Leave blank when unknown. Do not estimate.</p></div>
      <div class="form-grid">
        <div class="field"><label for="battery">Battery capacity (kWh)</label><input id="battery" name="battery" value="<?= sa_h($form['battery'] ?? '') ?>" maxlength="150"></div>
        <div class="field"><label for="range">Estimated / stated driving range</label><input id="range" name="range" value="<?= sa_h($form['range'] ?? '') ?>" maxlength="150"></div>
        <div class="field"><label for="body">Body type</label><input id="body" name="body" value="<?= sa_h($form['body'] ?? '') ?>" maxlength="150" list="body-options"><datalist id="body-options"><option value="Hatchback"><option value="Sedan"><option value="SUV"></datalist></div>
        <div class="field"><label for="doors">Number of doors</label><input id="doors" name="doors" value="<?= sa_h($form['doors'] ?? '') ?>" maxlength="150" placeholder="e.g. 4"></div>
        <div class="field"><label for="transmission">Transmission</label><input id="transmission" name="transmission" value="<?= sa_h($form['transmission'] ?? '') ?>" maxlength="150" list="transmission-options"><datalist id="transmission-options"><option value="Automatic"><option value="Manual"><option value="CVT"></datalist></div>
        <div class="field"><label for="steering">Steering</label><input id="steering" name="steering" value="<?= sa_h($form['steering'] ?? '') ?>" maxlength="150" placeholder="RHD"></div>
      </div>
    </section>

    <section class="form-card">
      <div class="card-heading"><h2>Price</h2></div>
      <div class="form-grid">
        <div class="field"><label for="reference_price_usd">FOB Japan price (USD)</label><input id="reference_price_usd" name="reference_price_usd" type="number" min="1" value="<?= sa_h($form['reference_price_usd'] ?? '') ?>" placeholder="Leave blank if not confirmed"></div>
        <div class="field"><label for="price_as_of">Price reference date</label><input id="price_as_of" name="price_as_of" type="date" value="<?= sa_h($form['price_as_of'] ?? '') ?>"><p class="field-help">Required when a price is entered. Sample prices are shown as reference only.</p></div>
      </div>
    </section>

    <section class="form-card">
      <div class="card-heading"><h2>Notes</h2></div>
      <div class="field"><label for="auction_condition">Auction / condition information</label><textarea id="auction_condition" name="auction_condition" rows="3" maxlength="2000"><?= sa_h($form['auction_condition'] ?? '') ?></textarea></div>
      <div class="field"><label for="notes">Vehicle notes</label><textarea id="notes" name="notes" rows="3" maxlength="2000"><?= sa_h($form['notes'] ?? '') ?></textarea></div>
      <div class="field"><label for="video_url">Video URL</label><input id="video_url" name="video_url" type="url" value="<?= sa_h($form['video_url'] ?? '') ?>" maxlength="300" placeholder="https://www.youtube.com/..."><p class="field-help">YouTube or Vimeo HTTPS links only.</p></div>
    </section>

    <section class="form-card">
      <div class="card-heading">
        <div><h2>Photos</h2><p>The first photo is the main image. Use Move up / Move down / Remove.</p></div>
      </div>
      <div id="gallery-list" class="gallery-admin" aria-live="polite">
        <?php foreach ($gallery as $index => $path): ?>
          <article class="gallery-admin-item" data-token="old:<?= sa_h($path) ?>">
            <img src="../<?= sa_h($path) ?>" alt="">
            <div><strong class="gallery-position"><?= $index === 0 ? 'Main photo' : 'Photo ' . ($index + 1) ?></strong><span><?= sa_h(basename($path)) ?></span></div>
            <div class="gallery-actions">
              <button class="button button-small button-secondary move-up" type="button">Move up</button>
              <button class="button button-small button-secondary move-down" type="button">Move down</button>
              <button class="button button-small button-danger remove-photo" type="button">Remove</button>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="field upload-field">
        <label for="new_images">Add photos</label>
        <input id="new_images" name="new_images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
        <p class="field-help">JPEG, PNG, or WebP. Maximum 20 files, 10 MiB each, 40 MiB total.</p>
      </div>
    </section>

    <div class="form-actions">
      <a class="button button-secondary" href="index.php">Cancel</a>
      <button class="button button-primary" type="submit" name="action" value="save">Save vehicle</button>
    </div>
  </form>

  <?php if ($isEdit): ?>
  <form method="post" class="danger-card" onsubmit="return confirm('Delete this vehicle from the South Africa site?');">
    <input type="hidden" name="csrf_token" value="<?= sa_h(sa_csrf_token()) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="original_ref" value="<?= sa_h($queryRef) ?>">
    <input type="hidden" name="data_version" value="<?= sa_h($loaded['version']) ?>">
    <h2>Delete vehicle</h2>
    <p>This removes the listing from the South Africa site. It does not change East Africa or the main website.</p>
    <label class="confirm-delete"><input type="checkbox" name="confirm_delete" value="yes" required> I want to delete <?= sa_h($queryRef) ?></label>
    <button class="button button-danger" type="submit">Delete vehicle</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
