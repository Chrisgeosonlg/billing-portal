<?php
declare(strict_types=1);

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money(int $value): string { return number_format($value / 100, 2); }
function units(string $input, int $max = 100000000000): int {
    if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $input)) throw new RuntimeException('Use a positive number with up to two decimal places.');
    $parts = explode('.', $input);
    $value = ((int)$parts[0] * 100) + (int)str_pad($parts[1] ?? '', 2, '0');
    if ($value > $max) throw new RuntimeException('The amount is too large.');
    return $value;
}
function totals(array $descriptions, array $quantities, array $prices, string $discount, string $rate): array {
    if (count($descriptions) > 30) throw new RuntimeException('Use at most 30 service lines.');
    $items = []; $subtotal = 0;
    foreach ($descriptions as $i => $description) {
        $description = trim((string)$description);
        if ($description === '') continue;
        if (strlen($description) > 500) throw new RuntimeException('Service descriptions must be under 500 characters.');
        $quantity = units((string)($quantities[$i] ?? ''), 1000000);
        $price = units((string)($prices[$i] ?? ''), 1000000000);
        if ($quantity === 0) throw new RuntimeException('Quantity must be greater than zero.');
        $amount = intdiv($quantity * $price + 50, 100);
        $subtotal += $amount;
        $items[] = compact('description', 'quantity', 'price', 'amount');
    }
    if (!$items) throw new RuntimeException('Add at least one service.');
    $discount = units($discount);
    if ($discount > $subtotal) throw new RuntimeException('Discount cannot exceed the subtotal.');
    $tax_bps = units($rate, 10000);
    $tax = intdiv(($subtotal - $discount) * $tax_bps + 5000, 10000);
    $total = $subtotal - $discount + $tax;
    return compact('items', 'subtotal', 'discount', 'tax_bps', 'tax', 'total');
}
function date_input(string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new RuntimeException('Enter a valid date.');
    return $value;
}
function field(string $key, int $max = 190, bool $required = false): string {
    $value = trim((string)($_POST[$key] ?? ''));
    if (strlen($value) > $max || ($required && $value === '')) throw new RuntimeException('Please check the ' . str_replace('_', ' ', $key) . ' field.');
    return $value;
}
// Name shown for a document kind: quotations are Proformas, invoices are Tax Invoices.
function kind_name(string $kind): string { return $kind === 'invoice' ? 'Tax Invoice' : 'Proforma'; }
// Shortest password accepted when one is created or changed.
const PASSWORD_MIN = 8;
// Each type has its own running number, continuing the paper books: PF-1128… and INV-1701….
// Documents made before this numbering keep their original QUO-/INV- number from the database id.
const DOC_PREFIX = ['quotation' => 'PF-', 'invoice' => 'INV-'];
const DOC_FIRST_NO = ['quotation' => 1128, 'invoice' => 1701];
function number(array $doc): string {
    $no = array_key_exists('doc_no', $doc) ? $doc['doc_no'] : doc_no_of((int)($doc['id'] ?? 0));
    if ($no) return DOC_PREFIX[$doc['kind']] . $no;
    return ($doc['kind'] === 'invoice' ? 'INV-' : 'QUO-') . str_pad((string)$doc['id'], 6, '0', STR_PAD_LEFT);
}
// For callers that only pass kind and id.
function doc_no_of(int $id): ?int {
    static $known = [];
    if (!$id || !numbering_ready()) return null;
    if (!isset($known[$id])) { $no = one('SELECT doc_no FROM documents WHERE id=?', [$id])['doc_no'] ?? null; if (!$no) return null; $known[$id] = (int)$no; }
    return $known[$id];
}
// Adds the number column and counters the first time the portal runs, so no phpMyAdmin step is needed.
// Must run outside a transaction: MySQL commits on ALTER TABLE.
function numbering_ready(): bool {
    global $db;
    static $ready = null;
    if ($ready !== null) return $ready;
    try { query('SELECT doc_no FROM documents LIMIT 1'); query('SELECT next_no FROM doc_counters LIMIT 1'); return $ready = true; }
    catch (PDOException $error) {}
    if ($db->inTransaction()) return $ready = false;
    try {
        try { $db->exec('ALTER TABLE documents ADD COLUMN doc_no INT UNSIGNED NULL AFTER kind, ADD UNIQUE KEY documents_kind_no (kind,doc_no)'); } catch (PDOException $error) {}
        $db->exec("CREATE TABLE IF NOT EXISTS doc_counters (kind ENUM('quotation','invoice') PRIMARY KEY, next_no INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        query('INSERT IGNORE INTO doc_counters (kind,next_no) VALUES (?,?),(?,?)', ['quotation', DOC_FIRST_NO['quotation'], 'invoice', DOC_FIRST_NO['invoice']]);
        query('SELECT doc_no FROM documents LIMIT 1');
        return $ready = true;
    } catch (PDOException $error) { error_log('Billing Portal: could not add document numbering. Import numbering-schema.sql.'); return $ready = false; }
}
// Gives a new document the next number of its type. Runs in the caller's transaction (or its own), so numbers never repeat.
function doc_no_assign(int $id, string $kind): void {
    global $db;
    if (!numbering_ready()) return;
    $own = !$db->inTransaction(); if ($own) $db->beginTransaction();
    try {
        $next = (int)one('SELECT next_no FROM doc_counters WHERE kind=? FOR UPDATE', [$kind])['next_no'];
        query('UPDATE documents SET doc_no=? WHERE id=?', [$next, $id]);
        query('UPDATE doc_counters SET next_no=? WHERE kind=?', [$next + 1, $kind]);
        if ($own) $db->commit();
    } catch (Throwable $error) { if ($own && $db->inTransaction()) $db->rollBack(); throw $error; }
}
// The numbers the next Proforma and Tax Invoice will get.
function doc_no_next(): array {
    if (!numbering_ready()) return [];
    return array_map('intval', array_column(query('SELECT kind,next_no FROM doc_counters')->fetchAll(), 'next_no', 'kind'));
}
// Changes where a type continues from. Never at or below a number already used, so numbers stay unique.
function doc_no_set_next(string $kind, int $next): void {
    $used = (int)(one('SELECT MAX(doc_no) AS n FROM documents WHERE kind=?', [$kind])['n'] ?? 0);
    if ($next < 1 || $next > 99999999) throw new RuntimeException('Enter a document number between 1 and 99999999.');
    if ($next <= $used) throw new RuntimeException('The next ' . kind_name($kind) . ' number must be above ' . DOC_PREFIX[$kind] . $used . ', which is already used.');
    query('UPDATE doc_counters SET next_no=? WHERE kind=?', [$next, $kind]);
}
// Organisation-specific wording, set in config.php for each client.
function brand(string $key): string {
    global $config;
    $defaults = ['brand' => 'Billing Portal', 'short_name' => 'Billing', 'location' => 'City, Country', 'company' => 'Your Company Limited'];
    // The company name follows Company details when the administrator has changed it in the portal.
    $value = $key === 'company' && function_exists('company_details') ? company_details()['company'] : (($config ?? [])[$key] ?? $defaults[$key] ?? '');
    return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$value));
}
// Payment status tag above an invoice's title: [label, tone]. Quotations have none.
function payment_stamp(array $doc, int $paid): ?array {
    if ($doc['kind'] !== 'invoice') return null;
    if ($paid >= (int)$doc['total']) return ['PAID', 'paid'];
    return $paid > 0 ? ['PARTLY PAID', 'part'] : ['UNPAID', 'unpaid'];
}
// Collapsible action row on the document page: title plus a status chip (tone: ok, warn, bad or blank).
function panel_start(string $id, string $title, string $status, string $tone = '', bool $open = false, bool $force = false): void {
    echo '<details class="panel" id="' . e($id) . '"' . ($open || $force ? ' open' : '') . ($force ? ' data-force="1"' : '') . '><summary><span class="panel-title">' . e($title) . '</span><span class="chip' . ($tone !== '' ? ' chip-' . e($tone) : '') . '">' . e($status) . '</span></summary><div class="panel-body">';
}
function panel_end(): void { echo '</div></details>'; }
