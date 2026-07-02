<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/vehicle-data.php';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function usd($value): string
{
    return '$' . number_format((int)($value ?? 0));
}

function signed_usd($value): string
{
    $amount = (int)($value ?? 0);
    $prefix = $amount >= 0 ? '+$' : '-$';
    return $prefix . number_format(abs($amount));
}

function km($value): string
{
    return number_format((int)($value ?? 0)) . ' km';
}

function file_url(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }
    return '../' . ltrim($path, '/');
}

$ref_id = trim((string)($_GET['ref'] ?? ''));
$data = loadVehicles();
$vehicle = null;

foreach ($data['vehicles'] as $candidate) {
    if (($candidate['ref_id'] ?? '') === $ref_id) {
        $vehicle = $candidate;
        break;
    }
}

if ($vehicle === null) {
    http_response_code(404);
}

$quote = $vehicle['quote_spec_data'] ?? [];
$cost_rows = [
    'purchase_price_usd' => '仕入価格',
    'land_transport_usd' => '陸送費',
    'ocean_freight_usd' => '船賃',
    'insurance_usd' => '保険料',
    'inspection_fee_usd' => '検査費',
    'other_cost_usd' => 'その他費用',
    'profit_usd' => '利益',
];
$total_cost = 0;
foreach (array_keys($cost_rows) as $field) {
    $total_cost += (int)($quote[$field] ?? 0);
}
$target_price = (int)($quote['target_price_usd'] ?? 0);
$difference = $target_price - $total_cost;
$has_saved_user_quote_data = $vehicle !== null && gt_quote_has_saved_user_data($quote);
$has_customer_data = $vehicle !== null && trim(implode('', [
    $quote['customer_name'] ?? '',
    $quote['customer_contact'] ?? '',
    $quote['customer_address'] ?? '',
    $quote['customer_tel'] ?? '',
    $quote['customer_email'] ?? '',
    $quote['customer_attn'] ?? '',
    $quote['destination_country'] ?? '',
    $quote['port_of_discharge'] ?? '',
    $quote['quote_valid_until'] ?? '',
    $quote['quote_memo'] ?? '',
])) !== '';
$main_image = $vehicle['gallery'][0] ?? '';
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $vehicle ? h($vehicle['ref_id']) . ' 見積書プレビュー' : '見積書プレビュー' ?> - Gloria Trading Admin</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #eef2f6; color: #222; line-height: 1.6; }
.topbar { background: #1a1a2e; color: #fff; padding: 0.9rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
.topbar .brand { font-size: 1.1rem; font-weight: 700; color: #4da6ff; }
.topbar .nav a { color: #ccc; text-decoration: none; font-size: 0.9rem; margin-left: 1.2rem; }
.topbar .nav a:hover { color: #fff; }
.container { max-width: 1040px; margin: 0 auto; padding: 1.5rem; }
.toolbar { display: flex; justify-content: space-between; gap: 1rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
.toolbar h1 { font-size: 1.35rem; color: #1a1a2e; }
.btn { display: inline-block; padding: 0.55rem 1rem; border-radius: 6px; font-size: 0.9rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; }
.btn-primary { background: #0066cc; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-success { background: #198754; color: #fff; }
.btn-print { background: #1a1a2e; color: #fff; }
.notice { background: #fff8e1; border: 1px solid #ffe08a; color: #5f4700; border-radius: 8px; padding: 1rem 1.2rem; margin-bottom: 1rem; }
.error { background: #fff0f0; border: 1px solid #ffcccc; color: #b00020; border-radius: 8px; padding: 1rem 1.2rem; }
.quote-sheet { background: #fff; border-radius: 12px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); padding: 2rem; }
.quote-header { display: grid; grid-template-columns: 1.3fr 1fr; gap: 1.5rem; border-bottom: 3px solid #1a1a2e; padding-bottom: 1.2rem; margin-bottom: 1.5rem; }
.quote-title { font-size: 2rem; letter-spacing: 0.08em; color: #1a1a2e; font-weight: 800; }
.company { text-align: right; font-size: 0.92rem; color: #444; }
.company strong { display: block; font-size: 1.25rem; color: #0066cc; margin-bottom: 0.25rem; }
.meta-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; }
.meta-card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 1rem; background: #fafafa; }
.meta-card .label { font-size: 0.78rem; color: #666; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
.meta-card .value { font-size: 1.05rem; color: #111; font-weight: 700; margin-top: 0.25rem; }
.section { margin-top: 1.5rem; }
.section h2 { font-size: 1.05rem; color: #1a1a2e; border-left: 5px solid #0066cc; padding-left: 0.7rem; margin-bottom: 0.8rem; }
.vehicle-block { display: grid; grid-template-columns: 250px 1fr; gap: 1.2rem; }
.main-image { width: 100%; height: 180px; object-fit: cover; border-radius: 10px; border: 1px solid #ddd; background: #f2f2f2; }
.image-placeholder { height: 180px; border-radius: 10px; background: #f2f2f2; color: #999; display: flex; align-items: center; justify-content: center; border: 1px solid #ddd; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #e5e7eb; padding: 0.65rem 0.75rem; text-align: left; vertical-align: top; }
th { background: #f8fafc; color: #444; font-size: 0.86rem; }
td.money { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
.total-row th, .total-row td { background: #e8f0fe; color: #1a56db; font-weight: 800; }
.target-row th, .target-row td { background: #e8fff1; color: #146c43; font-weight: 800; }
.diff-positive { color: #146c43; }
.diff-negative { color: #b00020; }
.file-list { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.file-list ul { padding-left: 1.2rem; }
.file-list a { color: #0066cc; }
.memo-box { border: 1px solid #e5e7eb; background: #fafafa; border-radius: 8px; padding: 1rem; white-space: pre-wrap; }
.footer-note { margin-top: 1.5rem; font-size: 0.85rem; color: #666; border-top: 1px solid #e5e7eb; padding-top: 1rem; }
@media (max-width: 760px) {
  .quote-header, .meta-grid, .vehicle-block, .file-list { grid-template-columns: 1fr; }
  .company { text-align: left; }
}
@media print {
  body { background: #fff; }
  .topbar, .toolbar, .notice, .no-print { display: none !important; }
  .container { max-width: none; padding: 0; }
  .quote-sheet { box-shadow: none; border-radius: 0; padding: 0; }
  a { color: #000; text-decoration: none; }
}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">Gloria Trading 管理画面</div>
  <div class="nav">
    <a href="index.php">車両一覧</a>
    <a href="edit.php<?= $vehicle ? '?ref=' . urlencode($vehicle['ref_id']) : '' ?>">編集画面</a>
    <a href="../index.html" target="_blank">サイトを見る</a>
    <a href="index.php?logout=1">ログアウト</a>
  </div>
</div>

<div class="container">
  <div class="toolbar">
    <h1>見積書プレビュー</h1>
    <div>
      <a class="btn btn-secondary" href="index.php">車両一覧へ戻る</a>
      <?php if ($vehicle): ?>
      <a class="btn btn-primary" href="edit.php?ref=<?= urlencode($vehicle['ref_id']) ?>">車両を編集</a>
      <a class="btn btn-success" href="quote-pdf.php?ref=<?= urlencode($vehicle['ref_id']) ?>">Proforma PDFダウンロード</a>
      <button type="button" class="btn btn-print" onclick="window.print()">印刷</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$vehicle): ?>
    <div class="error">指定された車両が見つかりません。車両一覧から開き直してください。</div>
  <?php else: ?>
    <?php if (!$has_saved_user_quote_data && !$has_customer_data): ?>
      <div class="notice">この車両は、サイト表示用の車両基本データを元に見積書の初期表示を作成しています。顧客情報、輸出先、費用、利益などを加工する場合は、編集画面の <strong>見積書専用データ</strong> を入力し、<strong>見積書作成用だけ保存</strong> または <strong>両方を同時保存</strong> を実行してください。</div>
    <?php endif; ?>

    <article class="quote-sheet">
      <header class="quote-header">
        <div>
          <div class="quote-title">PROFORMA INVOICE</div>
          <p>Invoice No: <?= h($quote['invoice_no'] ?? '自動採番') ?> / Date: <?= h($quote['invoice_date'] ?? $today) ?></p>
        </div>
        <div class="company">
          <strong>Gloria Trading</strong>
          <div>Used Vehicles Export</div>
          <div>Japan to Ghana / Nigeria / Benin</div>
          <div>Ref: <?= h($vehicle['ref_id']) ?></div>
        </div>
      </header>

      <section class="meta-grid">
        <div class="meta-card">
          <div class="label">Customer</div>
          <div class="value"><?= h($quote['customer_name'] ?? '未入力') ?></div>
          <div><?= h($quote['customer_contact'] ?? '') ?></div>
          <div><?= h($quote['customer_address'] ?? '') ?></div>
          <div><?= h(trim(($quote['customer_tel'] ?? '') . ' ' . ($quote['customer_email'] ?? ''))) ?></div>
        </div>
        <div class="meta-card">
          <div class="label">Shipping</div>
          <div class="value"><?= h($quote['destination_country'] ?? '未入力') ?></div>
          <div>Loading: <?= h($quote['port_of_loading'] ?? 'Sendai Port, Japan') ?></div>
          <div>Discharge: <?= h($quote['port_of_discharge'] ?? ($quote['destination_country'] ?? '未入力')) ?></div>
        </div>
        <div class="meta-card">
          <div class="label">Date / Valid Until</div>
          <div class="value"><?= h($today) ?></div>
          <div>Valid Until: <?= h($quote['quote_valid_until'] ?? '未入力') ?></div>
          <div><?= h(($quote['incoterms'] ?? 'CIF') . ' / ' . ($quote['payment_terms'] ?? 'T/T in advance')) ?></div>
        </div>
      </section>

      <section class="section">
        <h2>車両情報</h2>
        <div class="vehicle-block">
          <div>
            <?php if ($main_image): ?>
              <img class="main-image" src="<?= h(file_url($main_image)) ?>" alt="<?= h($vehicle['display_name_en']) ?>">
            <?php else: ?>
              <div class="image-placeholder">No Image</div>
            <?php endif; ?>
          </div>
          <table>
            <tbody>
              <tr><th>Ref ID</th><td><?= h($vehicle['ref_id']) ?></td><th>車両名</th><td><?= h($vehicle['display_name_en']) ?></td></tr>
              <tr><th>年式</th><td><?= h($vehicle['year']) ?></td><th>メーカー / モデル</th><td><?= h(trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''))) ?></td></tr>
              <tr><th>ボディタイプ</th><td><?= h($vehicle['body_type']) ?></td><th>燃料 / ミッション</th><td><?= h(trim(($vehicle['fuel_type'] ?? '') . ' / ' . ($vehicle['transmission'] ?? ''))) ?></td></tr>
              <tr><th>走行距離</th><td><?= km($vehicle['mileage_km']) ?></td><th>参考価格</th><td><?= usd($vehicle['reference_price_usd']) ?></td></tr>
              <tr><th>車台番号</th><td><?= h($quote['chassis_no'] ?? ($vehicle['chassis_no'] ?? '未入力')) ?></td><th>通貨</th><td><?= h($quote['currency'] ?? 'USD') ?></td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="section">
        <h2>費用明細</h2>
        <table>
          <tbody>
            <?php foreach ($cost_rows as $field => $label): ?>
            <tr><th><?= h($label) ?></th><td class="money"><?= usd($quote[$field] ?? 0) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total-row"><th>費用合計</th><td class="money"><?= usd($total_cost) ?></td></tr>
            <tr class="target-row"><th>販売目標価格</th><td class="money"><?= usd($target_price) ?></td></tr>
            <tr><th>販売目標価格との差額</th><td class="money <?= $difference >= 0 ? 'diff-positive' : 'diff-negative' ?>"><?= signed_usd($difference) ?></td></tr>
          </tbody>
        </table>
      </section>

      <section class="section">
        <h2>添付ファイル</h2>
        <div class="file-list">
          <div>
            <h3>見積もり用スペックファイル</h3>
            <?php if (!empty($vehicle['quote_spec_files'])): ?>
            <ul>
              <?php foreach ($vehicle['quote_spec_files'] as $file): ?>
              <li><a href="<?= h(file_url($file)) ?>" target="_blank"><?= h(basename($file)) ?></a></li>
              <?php endforeach; ?>
            </ul>
            <?php else: ?><p>未登録</p><?php endif; ?>
          </div>
          <div>
            <h3>見積もり用画像</h3>
            <?php if (!empty($vehicle['quote_image_files'])): ?>
            <ul>
              <?php foreach ($vehicle['quote_image_files'] as $file): ?>
              <li><a href="<?= h(file_url($file)) ?>" target="_blank"><?= h(basename($file)) ?></a></li>
              <?php endforeach; ?>
            </ul>
            <?php else: ?><p>未登録</p><?php endif; ?>
          </div>
        </div>
      </section>

      <section class="section">
        <h2>備考</h2>
        <div class="memo-box"><?= h($quote['quote_memo'] ?? '見積メモ未入力') ?></div>
      </section>

      <div class="footer-note">
        <p><?= h($vehicle['disclaimer_short'] ?? '') ?></p>
        <p>この画面は管理者確認用のプレビューです。顧客送付用のPROFORMA INVOICEは上部の「Proforma PDFダウンロード」から出力できます。</p>
      </div>
    </article>
  <?php endif; ?>
</div>
</body>
</html>
