<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/tfpdf/tfpdf.php';

// Optional company stamp and signature images, added to a document's PDF when chosen on that document.
// Stored as private-files/marks/<type>.png; each document's choice is private-files/doc-<id>/marks.json.
const MARK_TYPES = ['stamp' => 'Company stamp', 'signature' => 'Signature'];
const MARK_MAX_BYTES = 1024 * 1024;

function mark_path(string $type): ?string {
    if (!isset(MARK_TYPES[$type])) return null;
    $path = private_folder('marks') . '/' . $type . '.png';
    return is_file($path) ? $path : null;
}
function marks_available(): array {
    return array_filter(MARK_TYPES, fn($type) => mark_path($type) !== null, ARRAY_FILTER_USE_KEY);
}
function mark_save(string $type, array $upload): void {
    if (!isset(MARK_TYPES[$type])) throw new RuntimeException('Choose the stamp or the signature.');
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a PNG image to upload.');
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('The image is larger than your hosting upload limit (' . ini_get('upload_max_filesize') . ').');
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException('The upload failed. Please try again.');
    $tmp = $upload['tmp_name'];
    if (filesize($tmp) > MARK_MAX_BYTES) throw new RuntimeException('The image must be 1 MB or smaller. Crop it closer or save it at a smaller size.');
    $info = @getimagesize($tmp);
    if (!$info || $info[2] !== IMAGETYPE_PNG) throw new RuntimeException('Upload a PNG image. Other formats (JPG, PDF, HEIC) are not accepted.');
    if ($info[0] < 20 || $info[1] < 20 || $info[0] > 4000 || $info[1] > 4000) throw new RuntimeException('The image should be between 20 and 4000 pixels wide and high.');
    // Prove the PDF library can place it (it rejects interlaced and 16-bit PNGs).
    try { $pdf = new tFPDF(); $pdf->AddPage(); $pdf->Image($tmp, 10, 10, 40, 0, 'PNG'); $pdf->Output('S'); }
    catch (Throwable $ex) { throw new RuntimeException('This PNG cannot be placed in a PDF (' . preg_replace('/^tFPDF error: ([^:]+).*$/s', '$1', $ex->getMessage()) . '). Open it in an image editor and save it again as a normal 8-bit PNG.'); }
    if (!move_uploaded_file($tmp, private_folder('marks', true) . '/' . $type . '.png')) throw new RuntimeException('The portal could not save the image.');
}
function mark_remove(string $type): void {
    $path = mark_path($type);
    if ($path && !@unlink($path)) throw new RuntimeException('The portal could not remove the image.');
}
function signatory(): array {
    $path = private_folder('marks') . '/signatory.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    return ['name' => (string)($data['name'] ?? ''), 'title' => (string)($data['title'] ?? '')];
}
function signatory_save(string $name, string $title): void {
    if (preg_match('/[\x00-\x1F\x7F]/', $name . $title)) throw new RuntimeException('Use plain text for the name and title.');
    if (file_put_contents(private_folder('marks', true) . '/signatory.json', json_encode(['name' => $name, 'title' => $title], JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('The portal could not save the signatory.');
}
// Which marks this document's PDF shows. Only images that exist are returned.
function doc_marks(int $docId): array {
    $path = attachments_dir($docId) . '/marks.json';
    $chosen = is_file($path) ? (array)json_decode((string)file_get_contents($path), true) : [];
    return array_values(array_filter(array_keys(MARK_TYPES), fn($type) => in_array($type, $chosen, true) && mark_path($type) !== null));
}
function doc_marks_save(int $docId, array $types): void {
    $types = array_values(array_intersect(array_keys(MARK_TYPES), $types));
    $path = attachments_dir($docId, (bool)$types) . '/marks.json';
    if (!$types) { if (is_file($path)) @unlink($path); return; }
    if (file_put_contents($path, json_encode($types)) === false) throw new RuntimeException('The portal could not save this choice.');
}
// Carries the choice over to a document created from another (quotation to invoice, recurring copies).
function doc_marks_copy(int $fromId, int $toId): void {
    try { $path = attachments_dir($fromId) . '/marks.json'; if (is_file($path)) doc_marks_save($toId, (array)json_decode((string)file_get_contents($path), true)); }
    catch (Throwable $ex) { error_log('Billing Portal: stamp/signature choice not copied: ' . $ex->getMessage()); }
}
// Fits an image inside a box, keeping its proportions. Returns [width, height] in mm.
function mark_fit(string $path, float $maxW, float $maxH): array {
    [$w, $h] = getimagesize($path); $width = $maxW; $height = $maxW * $h / $w;
    if ($height > $maxH) { $height = $maxH; $width = $maxH * $w / $h; }
    return [$width, $height];
}
