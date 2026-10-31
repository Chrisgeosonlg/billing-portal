<?php
declare(strict_types=1);
require_once __DIR__ . '/attachments-lib.php';

// Deleting Proformas and Tax Invoices (administrator only). Along with each document go its payments, customer links,
// recurring schedule (copies already created stay) and attached files. A Tax Invoice converted from a deleted Proforma
// stays; it only loses the link back to it. Payments are only deleted when the administrator confirmed the extra warning.
function documents_staff_filter(string $staff): array {
    if ($staff === '') return ['1=1', []];
    return $staff === 'none' ? ['d.assigned_to IS NULL', []] : ['d.assigned_to=?', [$staff]];
}
// Deletes the chosen ids, or every document matching the staff filter when $ids is null. Returns the deleted numbers.
function documents_delete(?array $ids, string $staff, bool $confirmed): array {
    global $db;
    [$where, $params] = documents_staff_filter($staff);
    if ($ids !== null) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) throw new RuntimeException('Tick at least one document to delete.');
        $where = 'd.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $params = $ids;
    }
    $db->beginTransaction();
    try {
        $docs = query("SELECT d.* FROM documents d WHERE $where FOR UPDATE", $params)->fetchAll();
        $numbers = documents_purge($docs, $confirmed);
        if ($numbers) activity('Deleted ' . documents_summary($numbers));
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    foreach ($docs as $doc) documents_remove_files((int)$doc['id']);
    return $numbers;
}
// Removes the documents and everything attached to them. Runs inside the caller's transaction; files are removed afterwards.
function documents_purge(array $docs, bool $confirmed): array {
    if (!$docs) return [];
    $in = implode(',', array_map(fn($d) => (int)$d['id'], $docs)); // integers only
    $paid = one("SELECT COUNT(DISTINCT document_id) AS n, COALESCE(SUM(amount),0) AS total FROM payments WHERE document_id IN ($in)");
    if ((int)$paid['n'] && !$confirmed) throw new RuntimeException('This includes ' . (int)$paid['n'] . ' Tax Invoice' . ((int)$paid['n'] === 1 ? '' : 's') . ' with payments recorded. Confirm the warning to delete them.');
    if (recurring_tables_ready()) {
        $schedules = query("SELECT id FROM recurring_quotations WHERE template_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        $sIn = $schedules ? implode(',', array_map('intval', $schedules)) : '0';
        query("DELETE FROM recurring_runs WHERE schedule_id IN ($sIn) OR document_id IN ($in)");
        query("DELETE FROM recurring_quotations WHERE id IN ($sIn)");
    }
    if (shares_table_ready()) query("DELETE FROM document_shares WHERE document_id IN ($in)");
    query("DELETE FROM payments WHERE document_id IN ($in)");
    query("UPDATE documents SET source_id=NULL WHERE source_id IN ($in)");
    query("DELETE FROM documents WHERE id IN ($in)");
    return array_map('number', $docs);
}
function documents_summary(array $numbers): string {
    return count($numbers) . ' document' . (count($numbers) === 1 ? '' : 's') . ': ' . implode(', ', array_slice($numbers, 0, 20)) . (count($numbers) > 20 ? ' and ' . (count($numbers) - 20) . ' more' : '');
}
// Plain-language warning for documents with payments, for the second confirmation. Empty when there are none.
function documents_risk(string $where, array $params): string {
    $r = one("SELECT COUNT(DISTINCT p.document_id) AS n, COALESCE(SUM(p.amount),0) AS total FROM payments p JOIN documents d ON d.id=p.document_id WHERE $where", $params);
    global $config;
    return (int)$r['n'] ? (int)$r['n'] . ' Tax Invoice' . ((int)$r['n'] === 1 ? '' : 's') . ' with ' . ($config['currency'] ?? '') . ' ' . money((int)$r['total']) . ' in recorded payments' : '';
}
function documents_remove_files(int $id): void {
    $dir = attachments_dir($id);
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $name) if (is_file($dir . '/' . $name)) @unlink($dir . '/' . $name);
    @rmdir($dir);
}
function recurring_tables_ready(): bool {
    try { query('SELECT id FROM recurring_runs LIMIT 1'); query('SELECT id FROM recurring_quotations LIMIT 1'); return true; }
    catch (PDOException $error) { return false; }
}
function shares_table_ready(): bool {
    try { query('SELECT id FROM document_shares LIMIT 1'); return true; }
    catch (PDOException $error) { return false; }
}
