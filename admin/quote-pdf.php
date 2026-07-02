<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/vehicle-data.php';

function quote_pdf_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/quote-pdf-config.php';
    }
    return $config;
}

function pdf_money($value, string $currency = 'USD'): string
{
    return $currency . ' ' . number_format((float)($value ?? 0), 2);
}

function pdf_signed_money($value, string $currency = 'USD'): string
{
    $amount = (float)($value ?? 0);
    $prefix = $amount >= 0 ? '+' . $currency . ' ' : '-' . $currency . ' ';
    return $prefix . number_format(abs($amount), 2);
}

function pdf_km($value): string
{
    return number_format((int)($value ?? 0)) . ' km';
}

function pdf_clean_text($value): string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') return '-';
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    if ($converted === false || $converted === '') {
        $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    }
    $converted = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $converted) ?? '';
    $converted = str_replace(["\r", "\n", "\t"], ' ', $converted);
    return trim($converted) !== '' ? trim($converted) : '-';
}

function pdf_value($value, string $fallback = '-'): string
{
    $text = trim((string)($value ?? ''));
    return $text === '' ? $fallback : $text;
}

function pdf_escape(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function pdf_wrap_text(string $text, int $maxChars): array
{
    $text = pdf_clean_text($text);
    $words = preg_split('/\s+/', $text) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        if ($line === '') { $line = $word; continue; }
        if (strlen($line . ' ' . $word) <= $maxChars) {
            $line .= ' ' . $word;
        } else {
            $lines[] = $line;
            $line = $word;
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines ?: ['-'];
}

function pdf_estimated_width(string $text, int $size): float
{
    return strlen(pdf_clean_text($text)) * $size * 0.48;
}

final class QuotePdfBuilder
{
    private string $content = '';
    private array $images = [];

    public function text(float $x, float $y, string $text, int $size = 10, string $font = 'F1', float $r = 0, float $g = 0, float $b = 0): void
    {
        $safe = pdf_escape(pdf_clean_text($text));
        $this->content .= sprintf("%.3F %.3F %.3F rg BT /%s %d Tf %.2F %.2F Td (%s) Tj ET 0 0 0 rg\n", $r, $g, $b, $font, $size, $x, $y, $safe);
    }

    public function rightText(float $rightX, float $y, string $text, int $size = 10, string $font = 'F1', float $r = 0, float $g = 0, float $b = 0): void
    {
        $this->text($rightX - pdf_estimated_width($text, $size), $y, $text, $size, $font, $r, $g, $b);
    }

    public function centerText(float $centerX, float $y, string $text, int $size = 10, string $font = 'F1', float $r = 0, float $g = 0, float $b = 0): void
    {
        $this->text($centerX - (pdf_estimated_width($text, $size) / 2), $y, $text, $size, $font, $r, $g, $b);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.35, float $r = 0.78, float $g = 0.78, float $b = 0.78): void
    {
        // No ruled lines are drawn. The proforma invoice uses a plain white background.
    }

    public function rect(float $x, float $y, float $w, float $h, float $width = 0.35, float $r = 0.78, float $g = 0.78, float $b = 0.78): void
    {
        // No borders are drawn. The proforma invoice uses a plain white background.
    }

    public function filledRect(float $x, float $y, float $w, float $h, float $r = 0.95, float $g = 0.95, float $b = 0.95): void
    {
        // No filled backgrounds are drawn. The proforma invoice uses a plain white background.
    }

    public function image(string $path, float $x, float $y, float $w, float $h): void
    {
        if (!is_readable($path)) return;
        $info = @getimagesize($path);
        if (!$info || (($info[2] ?? null) !== IMAGETYPE_JPEG)) return;
        $key = array_search($path, array_column($this->images, 'path'), true);
        if ($key === false) {
            $key = count($this->images);
            $this->images[] = [
                'name' => 'Im' . ($key + 1),
                'path' => $path,
                'width' => (int)$info[0],
                'height' => (int)$info[1],
                'data' => file_get_contents($path),
            ];
        }
        $name = $this->images[$key]['name'];
        $this->content .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $y, $name);
    }

    public function build(): string
    {
        $stream = $this->content;
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

        $imageStartObj = 7;
        $xobjectParts = [];
        foreach ($this->images as $idx => $img) {
            $xobjectParts[] = '/' . $img['name'] . ' ' . ($imageStartObj + $idx) . ' 0 R';
        }
        $xobject = empty($xobjectParts) ? '' : ' /XObject << ' . implode(' ', $xobjectParts) . ' >>';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>' . $xobject . ' >> /Contents 6 0 R >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        foreach ($this->images as $img) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $img['width'] . ' /Height ' . $img['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($img['data']) . " >>\nstream\n" . $img['data'] . "\nendstream";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }
}

function quote_ref_list(): array
{
    $refs = [];
    if (isset($_GET['refs']) && is_array($_GET['refs'])) {
        $refs = $_GET['refs'];
    } elseif (isset($_GET['refs'])) {
        $refs = preg_split('/[,\s]+/', (string)$_GET['refs']) ?: [];
    } else {
        $refs = preg_split('/[,\s]+/', (string)($_GET['ref'] ?? '')) ?: [];
    }
    return array_values(array_unique(array_filter(array_map('trim', $refs))));
}

function find_quote_vehicles(array $refIds): array
{
    $data = loadVehicles();
    $byRef = [];
    foreach ($data['vehicles'] as $candidate) $byRef[(string)($candidate['ref_id'] ?? '')] = $candidate;
    $vehicles = [];
    foreach ($refIds as $ref) if (isset($byRef[$ref])) $vehicles[] = $byRef[$ref];
    return $vehicles;
}

function invoice_number(array $vehicles): string
{
    $firstQuote = $vehicles[0]['quote_spec_data'] ?? [];
    if (trim((string)($firstQuote['invoice_no'] ?? '')) !== '') return pdf_clean_text($firstQuote['invoice_no']);
    $refs = array_map(fn($v) => preg_replace('/[^A-Za-z0-9]/', '', (string)($v['ref_id'] ?? '')), $vehicles);
    $refs = array_values(array_filter($refs));
    $suffix = strtoupper(implode('-', array_slice($refs, 0, 3))) ?: '001';
    if (count($refs) > 3) $suffix .= '-' . count($refs) . 'UNITS';
    return 'PI-' . date('Ymd') . '-' . $suffix;
}

function vehicle_title(array $vehicle): string
{
    $title = (string)($vehicle['display_name_en'] ?? '');
    if (trim($title) !== '') return $title;
    return trim((string)($vehicle['year'] ?? '') . ' ' . (string)($vehicle['make'] ?? '') . ' ' . (string)($vehicle['model'] ?? '')) ?: '-';
}

function customer_contact_parts(array $quote): array
{
    $tel = trim((string)($quote['customer_tel'] ?? ''));
    $email = trim((string)($quote['customer_email'] ?? ''));
    $contact = trim((string)($quote['customer_contact'] ?? ''));
    if ($contact !== '') {
        if ($email === '' && filter_var($contact, FILTER_VALIDATE_EMAIL)) $email = $contact;
        elseif ($tel === '') $tel = $contact;
    }
    return ['tel' => $tel, 'email' => $email];
}

function draw_section_header(QuotePdfBuilder $pdf, float $x, float $y, float $w, string $title): void
{
    $pdf->filledRect($x, $y, $w, 18, 0.94, 0.945, 0.95);
    $pdf->rect($x, $y, $w, 18, 0.35, 0.72, 0.72, 0.72);
    $pdf->text($x + 7, $y + 5.5, strtoupper($title), 8, 'F2', 0.18, 0.18, 0.18);
}

function draw_info_row(QuotePdfBuilder $pdf, float $x, float $y, float $w, string $label, string $value, float $labelW = 96): void
{
    $pdf->rect($x, $y, $w, 20, 0.30, 0.80, 0.80, 0.80);
    $pdf->filledRect($x, $y, $labelW, 20, 0.975, 0.975, 0.975);
    $pdf->line($x + $labelW, $y, $x + $labelW, $y + 20, 0.30, 0.80, 0.80, 0.80);
    $pdf->text($x + 7, $y + 6.5, $label, 7, 'F2', 0.20, 0.20, 0.20);

    $valueX = $x + $labelW + 7;
    $valueW = max(10, $w - $labelW - 14);
    $maxChars = max(10, (int)floor($valueW / (7 * 0.48)));
    $lines = array_slice(pdf_wrap_text($value, $maxChars), 0, 2);
    if (count($lines) <= 1) {
        $pdf->text($valueX, $y + 6.5, $lines[0] ?? '-', 7, 'F1', 0.12, 0.12, 0.12);
    } else {
        $pdf->text($valueX, $y + 10.5, $lines[0], 7, 'F1', 0.12, 0.12, 0.12);
        $pdf->text($valueX, $y + 2.5, $lines[1], 7, 'F1', 0.12, 0.12, 0.12);
    }
}

function draw_total_row(QuotePdfBuilder $pdf, float $x, float $y, float $labelW, float $valueW, string $label, string $value, bool $dark = false): void
{
    $pdf->filledRect($x, $y, $labelW, 20, $dark ? 0.22 : 0.975, $dark ? 0.22 : 0.975, $dark ? 0.22 : 0.975);
    $pdf->rect($x, $y, $labelW + $valueW, 20, 0.30, 0.78, 0.78, 0.78);
    $pdf->line($x + $labelW, $y, $x + $labelW, $y + 20, 0.30, 0.78, 0.78, 0.78);
    $pdf->rightText($x + $labelW - 8, $y + 6.5, $label, $dark ? 8 : 7, 'F2', 0.18, 0.18, 0.18);
    $pdf->rightText($x + $labelW + $valueW - 8, $y + 6.5, $value, $dark ? 8 : 7, $dark ? 'F2' : 'F1', 0.12, 0.12, 0.12);
}

function quote_amounts(array $vehicle): array
{
    $quote = $vehicle['quote_spec_data'] ?? [];
    $purchasePrice = (float)($quote['purchase_price_usd'] ?? 0);
    $landTransport = (float)($quote['land_transport_usd'] ?? 0);
    $inspection = (float)($quote['inspection_fee_usd'] ?? 0);
    $otherCost = (float)($quote['other_cost_usd'] ?? 0);
    $profit = (float)($quote['profit_usd'] ?? 0);
    $targetPrice = (float)($quote['target_price_usd'] ?? 0);
    $fobCharges = $landTransport + $inspection + $otherCost + $profit;
    $calculatedFob = $purchasePrice + $fobCharges;
    $fobPrice = $targetPrice > 0 ? $targetPrice : $calculatedFob;
    return [
        'purchase' => $purchasePrice,
        'fob_charges' => $fobCharges,
        'fob_price' => $fobPrice,
        'freight' => (float)($quote['ocean_freight_usd'] ?? 0),
        'insurance' => (float)($quote['insurance_usd'] ?? 0),
        'total_cost' => $purchasePrice + $landTransport + (float)($quote['ocean_freight_usd'] ?? 0) + $inspection + $otherCost + $profit,
        'target' => $targetPrice,
    ];
}

function document_type(): string
{
    $type = strtolower(trim((string)($_GET['type'] ?? $_GET['doc'] ?? 'proforma')));
    $aliases = [
        'quote' => 'quotation',
        'estimate' => 'quotation',
        'quotation' => 'quotation',
        'proforma' => 'proforma',
        'proforma_invoice' => 'proforma',
        'commercial' => 'commercial_invoice',
        'commercial_invoice' => 'commercial_invoice',
        'invoice' => 'commercial_invoice',
        'packing' => 'packing_list',
        'packing_list' => 'packing_list',
        'pl' => 'packing_list',
    ];
    return $aliases[$type] ?? 'proforma';
}

function document_profile(string $type): array
{
    $profiles = [
        'proforma' => ['title' => 'PROFORMA INVOICE', 'prefix' => 'PI', 'label' => 'Invoice No.', 'filename' => 'proforma_invoice', 'show_bank' => true, 'show_amounts' => true, 'show_totals' => true, 'buyer_title' => 'Sold To / Buyer', 'vehicle_title' => 'Vehicle Details'],
        'quotation' => ['title' => 'QUOTATION', 'prefix' => 'QT', 'label' => 'Quotation No.', 'filename' => 'quotation', 'show_bank' => false, 'show_amounts' => true, 'show_totals' => true, 'buyer_title' => 'Customer / Buyer', 'vehicle_title' => 'Vehicle Details'],
        'commercial_invoice' => ['title' => 'COMMERCIAL INVOICE', 'prefix' => 'CI', 'label' => 'Invoice No.', 'filename' => 'commercial_invoice', 'show_bank' => false, 'show_amounts' => true, 'show_totals' => true, 'buyer_title' => 'Consignee', 'vehicle_title' => 'Description of Goods'],
        'packing_list' => ['title' => 'PACKING LIST', 'prefix' => 'PL', 'label' => 'Packing List No.', 'filename' => 'packing_list', 'show_bank' => false, 'show_amounts' => false, 'show_totals' => false, 'buyer_title' => 'Consignee', 'vehicle_title' => 'Packing Details'],
    ];
    return $profiles[$type] ?? $profiles['proforma'];
}

function document_number(array $vehicles, string $type): string
{
    $first = $vehicles[0];
    $quote = $first['quote_spec_data'] ?? [];
    $docData = $first['export_document_data'] ?? [];
    if ($type === 'commercial_invoice' && trim((string)($docData['commercial_invoice_no'] ?? '')) !== '') return pdf_clean_text($docData['commercial_invoice_no']);
    if ($type === 'packing_list' && trim((string)($docData['packing_list_no'] ?? '')) !== '') return pdf_clean_text($docData['packing_list_no']);
    if (($type === 'proforma' || $type === 'quotation') && trim((string)($quote['invoice_no'] ?? '')) !== '') return pdf_clean_text($quote['invoice_no']);
    $profile = document_profile($type);
    $refs = array_map(fn($v) => preg_replace('/[^A-Za-z0-9]/', '', (string)($v['ref_id'] ?? '')), $vehicles);
    $refs = array_values(array_filter($refs));
    $suffix = strtoupper(implode('-', array_slice($refs, 0, 3))) ?: '001';
    if (count($refs) > 3) $suffix .= '-' . count($refs) . 'UNITS';
    return $profile['prefix'] . '-' . date('Ymd') . '-' . $suffix;
}

function document_date(array $vehicles, string $type): string
{
    $first = $vehicles[0];
    $quote = $first['quote_spec_data'] ?? [];
    $docData = $first['export_document_data'] ?? [];
    if ($type === 'commercial_invoice') return pdf_value($docData['commercial_invoice_date'] ?? $quote['invoice_date'] ?? date('Y-m-d'), date('Y-m-d'));
    if ($type === 'packing_list') return pdf_value($docData['packing_list_date'] ?? $quote['invoice_date'] ?? date('Y-m-d'), date('Y-m-d'));
    return pdf_value($quote['invoice_date'] ?? date('Y-m-d'), date('Y-m-d'));
}

function doc_line_value(array $data, string $field, string $fallback = ''): string
{
    return pdf_value($data[$field] ?? $fallback);
}

function vehicle_certificate(array $vehicle): array
{
    if (!empty($vehicle['vehicle_certificate_data']) && is_array($vehicle['vehicle_certificate_data'])) return $vehicle['vehicle_certificate_data'];
    return gt_vehicle_certificate_data_value($vehicle);
}

function vehicle_chassis_for_doc(array $vehicle): string
{
    $cert = vehicle_certificate($vehicle);
    $quote = $vehicle['quote_spec_data'] ?? [];
    return pdf_clean_text($cert['chassis_no'] ?? $quote['chassis_no'] ?? $vehicle['chassis_no'] ?? '');
}

function vehicle_engine_cc_for_doc(array $vehicle): string
{
    $cert = vehicle_certificate($vehicle);
    $quote = $vehicle['quote_spec_data'] ?? [];
    $cc = gt_int_value($cert['engine_cc'] ?? $quote['engine_cc'] ?? $vehicle['engine_cc'] ?? 0);
    if ($cc <= 0 && trim((string)($cert['displacement_l'] ?? '')) !== '') $cc = (int)round(((float)$cert['displacement_l']) * 1000);
    return $cc > 0 ? number_format($cc) . ' cc' : '-';
}

function draw_document_party_rows(QuotePdfBuilder $pdf, float $x, float $startY, float $w, array $rows, float $labelW = 96): void
{
    $rowY = $startY;
    foreach ($rows as $row) { draw_info_row($pdf, $x, $rowY, $w, $row[0], $row[1], $labelW); $rowY -= 20; }
}

function build_document_pdf(array $vehicles, string $type = 'proforma'): string
{
    $config = quote_pdf_config();
    $company = $config['company'];
    $bank = $config['bank'];
    $doc = $config['document'];
    $assets = $config['assets'];
    $profile = document_profile($type);
    $first = $vehicles[0];
    $quote = $first['quote_spec_data'] ?? [];
    $docData = $first['export_document_data'] ?? [];
    $currency = pdf_value($quote['currency'] ?? $doc['currency'], $doc['currency']);
    $issueDate = document_date($vehicles, $type);
    $destination = pdf_value($docData['final_destination'] ?? $quote['destination_country'] ?? '');
    $portDischarge = pdf_value($quote['port_of_discharge'] ?? $destination);
    $incoterms = strtoupper(pdf_value($quote['incoterms'] ?? $doc['incoterms'], $doc['incoterms']));
    $isCif = ($incoterms === 'CIF');

    $subTotal = 0.0; $freight = 0.0; $insurance = 0.0; $amountsByRef = [];
    foreach ($vehicles as $vehicle) {
        $a = quote_amounts($vehicle);
        $amountsByRef[(string)($vehicle['ref_id'] ?? '')] = $a;
        $subTotal += $a['fob_price'];
        $freight += $a['freight'];
        $insurance += $a['insurance'];
    }
    $grandTotal = $isCif ? ($subTotal + $freight + $insurance) : $subTotal;

    $pdf = new QuotePdfBuilder();
    $pdf->image($assets['logo'] ?? '', 42, 754, 45, 45);
    $pdf->text(94, 784, $company['name'], 13, 'F2', 0.12, 0.12, 0.12);
    $pdf->text(94, 766, $company['address'], 7, 'F1', 0.28, 0.28, 0.28);
    $pdf->text(94, 755, 'Tel: ' . $company['tel'] . '  Fax: ' . $company['fax'], 7, 'F1', 0.28, 0.28, 0.28);
    $pdf->text(94, 744, 'E-mail: ' . $company['email'], 7, 'F1', 0.28, 0.28, 0.28);

    $pdf->filledRect(378, 770, 177, 34, 0.965, 0.965, 0.965);
    $pdf->rect(378, 770, 177, 34, 0.35, 0.76, 0.76, 0.76);
    $pdf->centerText(466.5, 782, $profile['title'], 13, 'F2', 0.18, 0.18, 0.18);
    $pdf->rightText(555, 754, $profile['label'] . ': ' . document_number($vehicles, $type), 8, 'F1', 0.18, 0.18, 0.18);
    $pdf->rightText(555, 742, 'Date: ' . $issueDate, 8, 'F1', 0.18, 0.18, 0.18);
    $pdf->rightText(555, 730, 'Ref ID: ' . implode(', ', array_map(fn($v) => (string)($v['ref_id'] ?? ''), $vehicles)), 8, 'F1', 0.18, 0.18, 0.18);
    $pdf->line(42, 722, 555, 722, 0.45, 0.70, 0.70, 0.70);

    draw_section_header($pdf, 42, 695, 247, $profile['buyer_title']);
    draw_section_header($pdf, 299, 695, 256, $type === 'commercial_invoice' || $type === 'packing_list' ? 'Notify Party / Shipping' : 'Shipping Information');
    $contactParts = customer_contact_parts($quote);
    if ($type === 'commercial_invoice' || $type === 'packing_list') {
        $leftRows = [
            ['Name:', doc_line_value($docData, 'consignee_name', $quote['customer_name'] ?? '')],
            ['Address:', doc_line_value($docData, 'consignee_address', $quote['customer_address'] ?? '')],
            ['Tel:', doc_line_value($docData, 'consignee_tel', $contactParts['tel'] ?? '')],
            ['Email:', doc_line_value($docData, 'consignee_email', $contactParts['email'] ?? '')],
            ['Final Dest.:', $destination],
        ];
        $rightRows = [
            ['Notify:', doc_line_value($docData, 'notify_name')],
            ['Address:', doc_line_value($docData, 'notify_address')],
            ['Port Disch.:', $portDischarge],
            ['Vessel/Voy:', trim(doc_line_value($docData, 'vessel_name', '') . ' ' . doc_line_value($docData, 'voyage_no', '')) ?: '-'],
            ['B/L or Booking:', pdf_value($docData['bl_no'] ?? $docData['booking_no'] ?? '')],
        ];
    } else {
        $leftRows = [
            ['Company:', pdf_value($quote['customer_name'] ?? '')],
            ['Address:', pdf_value($quote['customer_address'] ?? '')],
            ['Tel:', pdf_value($contactParts['tel'] ?? '')],
            ['Email:', pdf_value($contactParts['email'] ?? '')],
            ['Attn:', pdf_value($quote['customer_attn'] ?? '')],
        ];
        $rightRows = [
            ['Port of Loading:', pdf_value($quote['port_of_loading'] ?? $doc['port_of_loading'], $doc['port_of_loading'])],
            ['Port of Discharge:', $portDischarge],
            ['Incoterms:', $incoterms],
            ['Payment Terms:', pdf_value($quote['payment_terms'] ?? $doc['payment_terms'], $doc['payment_terms'])],
            ['Currency:', $currency],
        ];
    }
    draw_document_party_rows($pdf, 42, 675, 247, $leftRows, 96);
    draw_document_party_rows($pdf, 299, 675, 256, $rightRows, 100);

    draw_section_header($pdf, 42, 555, 513, $profile['vehicle_title']);
    $tableY = 535;
    if ($type === 'packing_list') {
        $colX = [42, 62, 120, 177, 255, 323, 386, 448, 506];
        $colW = [20, 58, 57, 78, 68, 63, 62, 58, 49];
        $headers = ['#', 'MAKE', 'MODEL', 'CHASSIS NO', 'MODEL CODE', 'ENGINE', 'WEIGHT', 'DIMENSIONS', 'QTY'];
    } else {
        $colX = [42, 62, 117, 177, 225, 315, 375, 455];
        $colW = [20, 55, 60, 48, 90, 60, 80, 100];
        $headers = ['#', 'MAKE', 'MODEL', 'YEAR', 'CHASSIS NO', 'ENGINE CC', $type === 'commercial_invoice' ? 'MODEL/ENGINE' : 'MILEAGE', $isCif ? 'CIF AMOUNT' : 'FOB AMOUNT'];
    }
    foreach ($headers as $idx => $header) {
        $pdf->filledRect($colX[$idx], $tableY, $colW[$idx], 18, 0.95, 0.95, 0.95);
        $pdf->rect($colX[$idx], $tableY, $colW[$idx], 18, 0.30, 0.78, 0.78, 0.78);
        $pdf->centerText($colX[$idx] + ($colW[$idx] / 2), $tableY + 6, $header, 6, 'F2', 0.20, 0.20, 0.20);
    }
    $lineY = $tableY - 22;
    $maxRows = min(count($vehicles), 8);
    for ($i = 0; $i < $maxRows; $i++) {
        $vehicle = $vehicles[$i];
        $cert = vehicle_certificate($vehicle);
        $a = $amountsByRef[(string)($vehicle['ref_id'] ?? '')];
        $amount = $isCif ? ($a['fob_price'] + $a['freight'] + $a['insurance']) : $a['fob_price'];
        if ($type === 'packing_list') {
            $weight = pdf_value($cert['gross_weight_kg'] ?? $cert['vehicle_weight_kg'] ?? '') . ' kg';
            $dims = trim(pdf_value($cert['length_cm'] ?? '', '') . 'x' . pdf_value($cert['width_cm'] ?? '', '') . 'x' . pdf_value($cert['height_cm'] ?? '', ''));
            if ($dims === 'xx') $dims = '-'; else $dims .= ' cm';
            $values = [(string)($i + 1), pdf_clean_text($vehicle['make'] ?? $cert['make_en'] ?? ''), pdf_clean_text($vehicle['model'] ?? ''), vehicle_chassis_for_doc($vehicle), pdf_clean_text($cert['model_code'] ?? ''), pdf_clean_text($cert['engine_model'] ?? ''), $weight, $dims, '1'];
        } else {
            $modelEngine = trim(pdf_clean_text($cert['model_code'] ?? '') . ' / ' . pdf_clean_text($cert['engine_model'] ?? ''));
            if ($modelEngine === '/ -' || $modelEngine === '- / -') $modelEngine = pdf_km($vehicle['mileage_km'] ?? 0);
            $values = [(string)($i + 1), pdf_clean_text($vehicle['make'] ?? $cert['make_en'] ?? ''), pdf_clean_text($vehicle['model'] ?? ''), pdf_clean_text($vehicle['year'] ?? ''), vehicle_chassis_for_doc($vehicle), vehicle_engine_cc_for_doc($vehicle), $type === 'commercial_invoice' ? $modelEngine : pdf_km($vehicle['mileage_km'] ?? 0), pdf_money($amount, $currency)];
        }
        foreach ($values as $idx => $value) {
            $pdf->rect($colX[$idx], $lineY, $colW[$idx], 22, 0.30, 0.82, 0.82, 0.82);
            $font = ($type !== 'packing_list' && $idx === count($values) - 1) ? 'F2' : 'F1';
            $pdf->centerText($colX[$idx] + ($colW[$idx] / 2), $lineY + 8, $value, 6, $font, 0.12, 0.12, 0.12);
        }
        $lineY -= 22;
    }
    if (count($vehicles) > $maxRows) $pdf->text(42, $lineY + 6, 'Additional vehicles omitted on this one-page PDF. Please split the document.', 6, 'F2');

    if ($profile['show_totals']) {
        $totalX = 328; $totalY = 482;
        draw_total_row($pdf, $totalX, $totalY, 105, 122, 'FOB Total (' . count($vehicles) . ' vehicle):', pdf_money($subTotal, $currency));
        if ($isCif) {
            draw_total_row($pdf, $totalX, $totalY - 20, 105, 122, 'Freight:', pdf_money($freight, $currency));
            draw_total_row($pdf, $totalX, $totalY - 40, 105, 122, 'Insurance:', pdf_money($insurance, $currency));
            draw_total_row($pdf, $totalX, $totalY - 60, 105, 122, 'CIF Price:', pdf_money($grandTotal, $currency), true);
        } else {
            draw_total_row($pdf, $totalX, $totalY - 20, 105, 122, 'FOB TOTAL:', pdf_money($grandTotal, $currency), true);
        }
    } else {
        $pdf->text(42, 486, 'Total Quantity: ' . count($vehicles) . ' unit(s).  Marks & Numbers: ' . pdf_value($docData['marks_and_numbers'] ?? 'AS PER INVOICE'), 7, 'F1', 0.22, 0.22, 0.22);
    }

    if (trim((string)($quote['quote_memo'] ?? '')) !== '' && $type !== 'packing_list') {
        $memoLines = pdf_wrap_text((string)$quote['quote_memo'], 92);
        $pdf->text(42, 426, 'Memo: ' . ($memoLines[0] ?? '-'), 7, 'F1', 0.38, 0.38, 0.38);
    }
    if (trim((string)($docData['shipping_memo'] ?? '')) !== '') {
        $memoLines = pdf_wrap_text((string)$docData['shipping_memo'], 92);
        $pdf->text(42, 414, 'Shipping Memo: ' . ($memoLines[0] ?? '-'), 7, 'F1', 0.38, 0.38, 0.38);
    }

    if ($profile['show_bank']) {
        draw_section_header($pdf, 42, 382, 513, 'Bank Information');
        $bankRows = [
            ['Bank Name:', $bank['bank_name']], ['Swift Code:', $bank['swift_code']], ['Branch Name:', $bank['branch_name']], ['Branch Phone:', $bank['branch_phone']], ['Account Name:', $bank['account_name']], ['Account Number:', $bank['account_number']], ['Branch Address:', $bank['branch_address']],
        ];
        $bankY = 362;
        foreach ($bankRows as $row) { draw_info_row($pdf, 42, $bankY, 513, $row[0], $row[1], 145); $bankY -= 20; }
        $sigY = 192;
    } else {
        $pdf->text(42, 382, $type === 'quotation' ? 'This quotation excludes bank details. Payment instructions will be provided separately when required.' : 'Prepared for export documentation based on available vehicle and shipping information.', 7, 'F1', 0.38, 0.38, 0.38);
        $sigY = 292;
    }

    draw_section_header($pdf, 42, $sigY, 247, 'Authorized Signature');
    draw_section_header($pdf, 299, $sigY, 256, 'Company Stamp');
    $pdf->rect(42, $sigY - 50, 247, 50, 0.30, 0.82, 0.82, 0.82);
    $pdf->rect(299, $sigY - 50, 256, 50, 0.30, 0.82, 0.82, 0.82);
    $pdf->line(55, $sigY - 38, 276, $sigY - 38, 0.30, 0.72, 0.72, 0.72);
    $pdf->centerText(165.5, $sigY - 47, 'Muneo Sato / Gloria Trading', 7, 'F1', 0.22, 0.22, 0.22);
    $pdf->image($assets['stamp'] ?? '', 394, $sigY - 46, 62, 62);

    $pdf->line(42, 130, 555, 130, 0.30, 0.82, 0.82, 0.82);
    $footer = $type === 'quotation' ? 'This is a quotation only and not a demand for payment.' : ($type === 'packing_list' ? 'This packing list is prepared for customs and shipping reference.' : ($type === 'commercial_invoice' ? 'This commercial invoice is prepared for export documentation.' : $doc['footer_note']));
    $pdf->centerText(298.5, 112, $footer, 7, 'F1', 0.42, 0.42, 0.42);
    return $pdf->build();
}

function build_quote_pdf(array $vehicles): string
{
    return build_document_pdf($vehicles, 'proforma');
}

$refIds = quote_ref_list();
$vehicles = find_quote_vehicles($refIds);
if (empty($vehicles) || count($vehicles) !== count($refIds)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Vehicle not found';
    exit;
}

$type = document_type();
$pdfBytes = build_document_pdf($vehicles, $type);
$safeRef = preg_replace('/[^A-Za-z0-9_-]/', '_', implode('_', array_map(fn($v) => (string)($v['ref_id'] ?? 'quote'), $vehicles))) ?: 'quote';
$fileName = document_profile($type)['filename'] . '_' . $safeRef . '_' . date('Ymd') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($pdfBytes));
echo $pdfBytes;
