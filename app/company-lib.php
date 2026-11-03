<?php
declare(strict_types=1);
require_once __DIR__ . '/attachments-lib.php';

// Company details printed on each new Proforma and Tax Invoice. Start from config.php; the administrator can change them
// in the portal, which saves them to private-files/company/details.json (no database change needed).
const COMPANY_FIELDS = ['company' => 'Company name', 'address' => 'Address', 'email' => 'Email', 'phone' => 'Phone', 'payment_details' => 'Bank / payment details'];

function company_details(): array {
    global $config;
    static $details = null;
    if ($details !== null) return $details;
    $path = private_folder('company') . '/details.json';
    $saved = is_file($path) ? (array)json_decode((string)file_get_contents($path), true) : [];
    $details = ['currency' => (string)($config['currency'] ?? '')];
    foreach (COMPANY_FIELDS as $key => $label) $details[$key] = (string)($saved[$key] ?? $config[$key] ?? '');
    return $details;
}
function company_save(array $input): array {
    $details = [];
    foreach (COMPANY_FIELDS as $key => $label) {
        $value = trim(str_replace("\r", '', (string)($input[$key] ?? '')));
        $multiline = in_array($key, ['address', 'payment_details'], true);
        if (preg_match($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value)) throw new RuntimeException('Use plain text for the ' . strtolower($label) . '.');
        if (mb_strlen($value) > ($multiline ? 1000 : 190)) throw new RuntimeException('The ' . strtolower($label) . ' is too long.');
        $details[$key] = $value;
    }
    if ($details['company'] === '') throw new RuntimeException('Enter the company name.');
    if ($details['email'] !== '' && !filter_var($details['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid company email.');
    if (file_put_contents(private_folder('company', true) . '/details.json', json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false) throw new RuntimeException('The portal could not save the company details.');
    return $details;
}
// Company logos. An uploaded logo is kept in private-files/company and served by logo.php; without one, the
// default artwork in images/ is used. key => [label, default file, hint].
const COMPANY_LOGOS = [
    'document' => ['Document logo', 'logo.png', 'On Proformas, Tax Invoices, PDFs and the customer download page. Dark artwork on a white or transparent background works best.'],
    'sidebar' => ['Sidebar logo', 'logo-light.png', 'Shown on the dark sidebar, so use white or light artwork on a transparent background.'],
    'icon' => ['Browser icon', 'logo-icon.png', 'Small square icon shown in the browser tab.'],
];
const COMPANY_LOGO_MAX_BYTES = 2 * 1024 * 1024;

function company_logo_file(string $which): ?string {
    if (!isset(COMPANY_LOGOS[$which])) return null;
    foreach (['png', 'jpg'] as $ext) { $path = private_folder('company') . '/logo-' . $which . '.' . $ext; if (is_file($path)) return $path; }
    return null;
}
// File path for PDFs, and address for pages; both fall back to the default artwork.
function company_logo_path(string $which): string { return company_logo_file($which) ?? __DIR__ . '/images/' . COMPANY_LOGOS[$which][1]; }
function company_logo_url(string $which): string {
    $file = company_logo_file($which);
    return $file ? 'logo.php?which=' . $which . '&amp;v=' . filemtime($file) : 'images/' . COMPANY_LOGOS[$which][1];
}
function company_logo_save(string $which, array $upload): void {
    if (!isset(COMPANY_LOGOS[$which])) throw new RuntimeException('Choose which logo to replace.');
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a PNG or JPG image to upload.');
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('The image is larger than your hosting upload limit (' . ini_get('upload_max_filesize') . ').');
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException('The upload failed. Please try again.');
    $tmp = $upload['tmp_name'];
    if (filesize($tmp) > COMPANY_LOGO_MAX_BYTES) throw new RuntimeException('The logo must be 2 MB or smaller.');
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) throw new RuntimeException('Upload a PNG or JPG image. Other formats (SVG, PDF, HEIC, AI) are not accepted.');
    if ($info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) throw new RuntimeException('The logo should be between 16 and 6000 pixels wide and high.');
    $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    // The document logo goes into every PDF, so prove the PDF library can place it (it rejects interlaced and 16-bit PNGs).
    if ($which === 'document') {
        require_once __DIR__ . '/vendor/tfpdf/tfpdf.php';
        try { $pdf = new tFPDF(); $pdf->AddPage(); $pdf->Image($tmp, 10, 10, 40, 0, strtoupper($ext === 'jpg' ? 'jpeg' : 'png')); $pdf->Output('S'); }
        catch (Throwable $ex) { throw new RuntimeException('This image cannot be placed in a PDF (' . preg_replace('/^tFPDF error: ([^:]+).*$/s', '$1', $ex->getMessage()) . '). Open it in an image editor and save it again as a normal PNG or JPG.'); }
    }
    $dir = private_folder('company', true);
    if (!move_uploaded_file($tmp, $dir . '/logo-' . $which . '.' . $ext . '.new')) throw new RuntimeException('The portal could not save the logo.');
    foreach (['png', 'jpg'] as $old) if (is_file($dir . '/logo-' . $which . '.' . $old)) @unlink($dir . '/logo-' . $which . '.' . $old);
    rename($dir . '/logo-' . $which . '.' . $ext . '.new', $dir . '/logo-' . $which . '.' . $ext);
}
function company_logo_remove(string $which): void {
    $file = company_logo_file($which);
    if ($file && !@unlink($file)) throw new RuntimeException('The portal could not remove the logo.');
}

// Rewrites the company details stored with every existing document. Amounts, customers and payments are not touched.
function company_apply_existing(array $details): int {
    $set = []; $params = [];
    foreach (COMPANY_FIELDS as $key => $label) { $set[] = "'$." . $key . "', ?"; $params[] = $details[$key]; }
    return query('UPDATE documents SET company_snapshot=JSON_SET(company_snapshot,' . implode(',', $set) . ') WHERE JSON_VALID(company_snapshot)', $params)->rowCount();
}
