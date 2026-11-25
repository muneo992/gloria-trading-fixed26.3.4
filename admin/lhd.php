<?php
declare(strict_types=1);

require_once __DIR__ . '/lhd-vehicle-data.php';
gt_admin_register_error_handler();
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php', true, 303);
    exit;
}

function lhd_h(string|int $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$_SESSION['lhd_csrf'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['lhd_csrf'];
session_write_close();
$today = gmdate('Y-m-d');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$editing = array_key_exists('ref', $_GET);
$ref = $_GET['ref'] ?? '';

if (!is_string($ref) || ($ref !== '' && !preg_match(GT_LHD_REF_PATTERN, $ref))) {
    http_response_code(404);
    exit;
}
if ($isPost && (!is_string($_POST['csrf'] ?? null) || !hash_equals($token, $_POST['csrf']))) {
    http_response_code(403);
    exit('操作を確認できませんでした。ページを開き直してください。');
}

$record = null;
$image = null;
$records = [];
$error = '';
$storageAvailable = false;
try {
    $directory = gt_lhd_directory();
    $records = gt_lhd_read($directory);
    $storageAvailable = $directory !== null;
    $record = $records[$ref] ?? null;
    if ($ref !== '' && $record === null) {
        http_response_code(404);
        exit('車両が見つかりません。');
    }
    $image = $record === null ? null : gt_lhd_image_path($directory, $record);
    if (isset($_GET['image'])) {
        gt_lhd_send_image($image);
        exit;
    }
    if ($isPost && $editing && $storageAvailable) {
        $action = $_POST['action'] ?? '';
        $version = $_POST['version'] ?? '';
        if (!is_string($version)) {
            throw new DomainException('ページを開き直してください。');
        }
        if ($action === 'draft') {
            $next = gt_lhd_validate($_POST);
            $next['published'] = false;
            $next['remove_image'] = ($_POST['remove_image'] ?? '') === '1';
            $photo = $_FILES['photo'] ?? null;
        } elseif ($record !== null && in_array($action, ['publish', 'stop'], true)) {
            $next = $record;
            $next['published'] = $action === 'publish';
            if ($next['published'] && (!gt_lhd_eligible($next, $today) || ($_POST['approve'] ?? '') !== '1'
                || (in_array($next['trade_term'], ['CFR', 'CIF'], true) && $next['destination_port'] === ''))) {
                throw new DomainException('LHD・調達可否・掲載内容の確認、現在有効な確認日と期限、C&F・CIFの場合は仕向港が必要です。');
            }
            $photo = null;
        } else {
            throw new DomainException('操作を確認できませんでした。');
        }
        $directory = gt_lhd_directory(true);
        $saved = gt_lhd_save($directory, $ref, $version, $next, $photo);
        header('Location: lhd.php?ref=' . rawurlencode($saved) . '&saved=1#preview', true, 303);
        exit;
    }
} catch (DomainException $exception) {
    http_response_code(422);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    $error = 'LHD車両データを処理できませんでした。';
}

function lhd_status(array $record, string $today): string
{
    if (gt_lhd_eligible($record, $today)) {
        return '掲載中';
    }
    return ($record['published'] ?? false) ? '期限切れ・非表示' : '非公開';
}

$fields = [
    'make' => ['メーカー（公開）', 'text', true],
    'model' => ['車種（公開）', 'text', true],
    'year' => ['年式（公開）', 'number', true],
    'mileage' => ['走行距離 km（公開）', 'number', false],
    'confirmed_on' => ['調達可否を確認した日（UTC）', 'date', false],
    'expires_on' => ['掲載期限（UTC・当日を含む）', 'date', false],
    'price' => ['販売価格', 'text', false],
    'destination_port' => ['仕向港（C&F・CIFでは必須）', 'text', false],
];
$selects = [
    'price_mode' => ['価格表示', ['quote' => '個別見積もり', 'display' => '価格を表示']],
    'currency' => ['通貨', array_combine(GT_LHD_CURRENCIES, GT_LHD_CURRENCIES)],
    'trade_term' => ['取引条件', ['' => '未指定', 'FOB' => 'FOB', 'CFR' => 'C&F (CFR)', 'CIF' => 'CIF']],
];
$form = $record ?? [];
if ($isPost && ($_POST['action'] ?? '') === 'draft' && $error !== '') {
    foreach (array_keys($fields + $selects + ['confirmed' => []]) as $field) {
        $form[$field] = is_string($_POST[$field] ?? null) ? substr($_POST[$field], 0, 120) : '';
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>LHD調達候補 | Gloria Trading 管理</title>
  <link rel="stylesheet" href="lhd.css">
  <link rel="stylesheet" href="sourcing-nav.css">
</head>
<body>
<main>
  <?php $sourcingSection = 'lhd'; require __DIR__ . '/sourcing-nav.php'; ?>
  <p><a href="../lhd.html">LHD公開ページ</a></p>
  <h1>LHD調達候補</h1>
  <?php if ($error !== ''): ?><p role="alert" class="notice"><?= lhd_h($error) ?></p><?php endif; ?>
  <?php if (!$storageAvailable): ?><p role="status">西アフリカの非公開領域が未設定です。</p><?php endif; ?>
  <?php if (!$editing): ?>
    <?php if ($storageAvailable): ?><p><a class="button" href="lhd.php?ref=">調達候補を登録</a></p><?php endif; ?>
    <?php if ($records === []): ?><p>登録済みの調達候補はありません。</p><?php endif; ?>
    <ul class="records">
      <?php foreach ($records as $id => $candidate): ?>
        <li><a href="lhd.php?ref=<?= lhd_h(rawurlencode($id)) ?>"><?= lhd_h($candidate['year'] . ' ' . $candidate['make'] . ' ' . $candidate['model']) ?></a>
          <span><?= lhd_h($id) ?> · <?= lhd_h(lhd_status($candidate, $today)) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php elseif ($storageAvailable): ?>
    <p><?= $record === null ? '新規登録' : lhd_h($ref . ' · ' . lhd_status($record, $today)) ?></p>
    <?php if (isset($_GET['saved'])): ?><p role="status">保存しました。</p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="lhd.php?ref=<?= lhd_h(rawurlencode($ref)) ?>">
      <input type="hidden" name="csrf" value="<?= lhd_h($token) ?>">
      <input type="hidden" name="version" value="<?= lhd_h($isPost && $error !== '' && is_string($_POST['version'] ?? null) ? substr($_POST['version'], 0, 64) : gt_lhd_version($record)) ?>">
      <div class="fields">
        <?php foreach ($fields as $key => [$label, $type, $required]): ?>
          <label><?= lhd_h($label) ?><input name="<?= lhd_h($key) ?>" type="<?= lhd_h($type) ?>"
            value="<?= lhd_h($form[$key] ?? '') ?>" <?= $required ? 'required' : '' ?>></label>
        <?php endforeach; ?>
        <?php foreach ($selects as $key => [$label, $options]): ?>
          <label><?= lhd_h($label) ?><select name="<?= lhd_h($key) ?>">
            <?php foreach ($options as $value => $label): ?>
              <option value="<?= lhd_h($value) ?>" <?= ($form[$key] ?? null) === $value ? 'selected' : '' ?>><?= lhd_h($label) ?></option>
            <?php endforeach; ?>
          </select></label>
        <?php endforeach; ?>
      </div>
      <label class="check"><input type="checkbox" name="confirmed" value="1" <?= !empty($form['confirmed']) ? 'checked' : '' ?>>
        UAE市場で左ハンドルと調達可否を確認済み</label>
      <label>掲載用写真（JPEG / PNG、8 MBまで）<input type="file" name="photo" accept="image/jpeg,image/png"></label>
      <?php if ($image !== null): ?><label class="check"><input type="checkbox" name="remove_image" value="1">登録済み写真を取り除く</label><?php endif; ?>
      <p>下書き保存すると掲載を停止します。編集内容をプレビューしてから再公開できます。</p>
      <button name="action" value="draft">下書き保存</button>
    </form>
    <?php if ($record !== null): ?>
      <?php $preview = gt_lhd_public_record($ref, $record, $image !== null); ?>
      <section id="preview">
        <h2>公開前プレビュー</h2>
        <?php if ($image !== null): ?><img src="lhd.php?ref=<?= lhd_h(rawurlencode($ref)) ?>&amp;image=1" alt="掲載予定写真"><?php endif; ?>
        <h3><?= lhd_h($preview['year'] . ' ' . $preview['make'] . ' ' . $preview['model']) ?></h3>
        <p><?= lhd_h($ref) ?> · Subject to Availability</p>
        <?php if ($preview['mileage'] !== ''): ?><p><?= lhd_h(number_format((int) $preview['mileage'])) ?> km</p><?php endif; ?>
        <p><?= isset($preview['price']) ? lhd_h($preview['currency'] . ' ' . $preview['price']) : 'Price on request' ?></p>
        <?php if ($preview['trade_term'] !== ''): ?><p><?= lhd_h($selects['trade_term'][1][$preview['trade_term']] . ' ' . $preview['destination_port']) ?></p><?php endif; ?>
        <p>Request a Quotation</p>
        <form method="post" action="lhd.php?ref=<?= lhd_h(rawurlencode($ref)) ?>">
          <input type="hidden" name="csrf" value="<?= lhd_h($token) ?>">
          <input type="hidden" name="version" value="<?= lhd_h(gt_lhd_version($record)) ?>">
          <?php if (gt_lhd_eligible($record, $today)): ?>
            <button name="action" value="stop">掲載停止</button>
          <?php else: ?>
            <label class="check"><input type="checkbox" name="approve" value="1" required>写真の掲載許可と、表示する販売価格・取引条件を確認済み</label>
            <button name="action" value="publish">公開する</button>
          <?php endif; ?>
        </form>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
