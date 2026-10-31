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
// Rewrites the company details stored with every existing document. Amounts, customers and payments are not touched.
function company_apply_existing(array $details): int {
    $set = []; $params = [];
    foreach (COMPANY_FIELDS as $key => $label) { $set[] = "'$." . $key . "', ?"; $params[] = $details[$key]; }
    return query('UPDATE documents SET company_snapshot=JSON_SET(company_snapshot,' . implode(',', $set) . ') WHERE JSON_VALID(company_snapshot)', $params)->rowCount();
}
