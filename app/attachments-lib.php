<?php
declare(strict_types=1);

// Supporting PDFs (such as a TRA EFD receipt) kept with a document and sent along with it.
// Files live in a private folder, never served directly: <id>--<name>.pdf, one folder per document.
const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024;
const ATTACHMENT_MAX_TOTAL = 8 * 1024 * 1024;
const ATTACHMENT_MAX_FILES = 5;

function attachments_root(): string {
    global $config;
    $dir = rtrim((string)(($config ?? [])['attachments_dir'] ?? ''), '/\\');
    return $dir !== '' ? $dir : __DIR__ . '/private-files';
}
function attachments_dir(int $docId, bool $create = false): string {
    return private_folder('doc-' . $docId, $create);
}
// A subfolder of the private files folder, created (with its web block) on first use.
function private_folder(string $name, bool $create = false): string {
    $root = attachments_root(); $dir = $root . '/' . $name;
    if ($create && !is_dir($dir)) {
        if (!is_dir($root) && !@mkdir($root, 0750, true)) throw new RuntimeException('The portal cannot create its private-files folder. Ask your host to allow PHP to write to the portal folder.');
        // Belt and braces: the main .htaccess also blocks this folder.
        if (!is_file($root . '/.htaccess')) @file_put_contents($root . '/.htaccess', "Require all denied\n");
        if (!is_file($root . '/index.html')) @file_put_contents($root . '/index.html', '');
        if (!@mkdir($dir, 0750) && !is_dir($dir)) throw new RuntimeException('The portal cannot save uploaded files. Ask your host to allow PHP to write to the portal folder.');
    }
    return $dir;
}
function attachment_name(string $original): string {
    $name = preg_replace('/\.pdf$/i', '', basename(str_replace('\\', '/', $original)));
    $name = trim(preg_replace('/[^A-Za-z0-9 ._()-]+/', '-', $name), ' .-');
    return substr($name !== '' ? $name : 'attachment', 0, 80) . '.pdf';
}
function attachments_list(int $docId): array {
    $files = [];
    foreach (glob(attachments_dir($docId) . '/*--*.pdf') ?: [] as $path) {
        if (!preg_match('/^([a-f0-9]{32})--(.+\.pdf)$/D', basename($path), $m)) continue;
        $files[] = ['id' => $m[1], 'name' => $m[2], 'size' => (int)filesize($path), 'time' => (int)filemtime($path), 'path' => $path];
    }
    usort($files, fn($a, $b) => [$a['time'], $a['name']] <=> [$b['time'], $b['name']]);
    return $files;
}
function attachment_get(int $docId, string $id): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
    foreach (attachments_list($docId) as $file) if ($file['id'] === $id) return $file;
    return null;
}
function attachment_size(int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}
// Saves the uploaded PDFs from one <input type="file" multiple>. Returns the saved names.
function attachments_add(int $docId, array $upload): array {
    $count = is_array($upload['name'] ?? null) ? count($upload['name']) : 0;
    $files = [];
    for ($i = 0; $i < $count; $i++) {
        $error = (int)$upload['error'][$i];
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('"' . attachment_name((string)$upload['name'][$i]) . '" is larger than your hosting upload limit (' . ini_get('upload_max_filesize') . ').');
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'][$i])) throw new RuntimeException('The upload failed. Please try again.');
        $files[] = ['name' => attachment_name((string)$upload['name'][$i]), 'tmp' => $upload['tmp_name'][$i], 'size' => (int)filesize($upload['tmp_name'][$i])];
    }
    if (!$files) throw new RuntimeException('Choose at least one PDF file to attach.');
    $existing = attachments_list($docId);
    if (count($existing) + count($files) > ATTACHMENT_MAX_FILES) throw new RuntimeException('A document can have at most ' . ATTACHMENT_MAX_FILES . ' attachments. Remove one first.');
    $total = array_sum(array_column($existing, 'size'));
    foreach ($files as $file) {
        if ($file['size'] > ATTACHMENT_MAX_BYTES) throw new RuntimeException('"' . $file['name'] . '" is larger than 5 MB. Scan it at a lower resolution or compress it.');
        if (file_get_contents($file['tmp'], false, null, 0, 5) !== '%PDF-') throw new RuntimeException('"' . $file['name'] . '" is not a PDF file. Only PDF attachments are accepted.');
        $total += $file['size'];
    }
    if ($total > ATTACHMENT_MAX_TOTAL) throw new RuntimeException('All attachments on one document must add up to 8 MB or less, so the email is not rejected.');
    $dir = attachments_dir($docId, true);
    foreach ($files as $file) {
        if (!move_uploaded_file($file['tmp'], $dir . '/' . bin2hex(random_bytes(16)) . '--' . $file['name'])) throw new RuntimeException('The portal could not save "' . $file['name'] . '".');
    }
    return array_column($files, 'name');
}
function attachment_remove(int $docId, string $id): string {
    $file = attachment_get($docId, $id);
    if (!$file) throw new RuntimeException('That attachment was already removed.');
    if (!@unlink($file['path'])) throw new RuntimeException('The portal could not remove the attachment.');
    return $file['name'];
}
function attachment_download(array $file): never {
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
    header('Content-Length: ' . $file['size']); header('Cache-Control: private, no-store');
    readfile($file['path']); exit;
}
