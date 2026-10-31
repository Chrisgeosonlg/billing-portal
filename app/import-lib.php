<?php
declare(strict_types=1);

// Small, bounded reader for customer lists; never evaluates spreadsheet formulas.
function import_xml(string $text): SimpleXMLElement {
    if (strlen($text) > 8 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) throw new RuntimeException('Unsupported or oversized spreadsheet XML.');
    $previous = libxml_use_internal_errors(true);
    try {
        $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) throw new RuntimeException('The spreadsheet contains invalid XML.');
        return $xml;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}
function import_text(SimpleXMLElement $node): string {
    $value = '';
    foreach ($node->xpath('.//*[local-name()="t"]') as $text) $value .= (string)$text;
    return $value;
}
function import_rows(string $path, string $extension): array {
    if (filesize($path) > 2 * 1024 * 1024) throw new RuntimeException('Upload a file smaller than 2 MB.');
    if ($extension === 'csv') {
        $file = fopen($path, 'rb'); $rows = [];
        try {
            $first = fgets($file); rewind($file);
            $delimiter = ','; $best = 0;
            foreach ([',',';',"\t"] as $candidate) { $count = count(str_getcsv($first ?: '', $candidate, '"', '')); if ($count > $best) { $best = $count; $delimiter = $candidate; } }
            while (($row = fgetcsv($file, 0, $delimiter, '"', '')) !== false) {
                if (count($rows) >= 1001 || count($row) > 50) throw new RuntimeException('Use at most 1,000 customer rows and 50 columns.');
                $rows[] = array_map(fn($v) => (string)$v, $row);
            }
        } finally { fclose($file); }
        return $rows;
    }
    if ($extension !== 'xlsx') throw new RuntimeException('Choose an .xlsx or .csv file. Save older .xls files as .xlsx first.');
    if (!class_exists('PharData') || !function_exists('simplexml_load_string')) throw new RuntimeException('Excel import needs PHP Phar and SimpleXML. Ask your host to enable them, or upload CSV.');
    // A private temporary ZIP name lets Phar read PHP upload files with .tmp names.
    $temp = tempnam(sys_get_temp_dir(), 'bp-import-');
    if ($temp === false) throw new RuntimeException('Temporary upload storage is unavailable.');
    $zipPath = $temp . '.zip';
    try {
        if (!copy($path, $zipPath)) throw new RuntimeException('Could not read the upload.');
        $zip = new PharData($zipPath);
        if (!$zip->isFileFormat(Phar::ZIP)) throw new RuntimeException('Choose a valid Excel .xlsx workbook.');
        $size = 0; $entries = 0;
        foreach (new RecursiveIteratorIterator($zip) as $entry) {
            $size += $entry->getSize();
            if (++$entries > 1000 || $size > 16 * 1024 * 1024) throw new RuntimeException('Workbook is too large when opened. Copy the customer sheet into a smaller workbook.');
        }
        $read = function(string $name) use ($zip): SimpleXMLElement {
            if (!isset($zip[$name]) || $zip[$name]->getSize() > 8 * 1024 * 1024) throw new RuntimeException('The workbook is incomplete or too large.');
            return import_xml($zip[$name]->getContent());
        };
        $book = $read('xl/workbook.xml');
        $sheets = $book->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
        if (!$sheets) throw new RuntimeException('No worksheet found.');
        $attrs = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $id = (string)$attrs['id'];
        $target = '';
        foreach ($read('xl/_rels/workbook.xml.rels')->children() as $rel) {
            if ((string)$rel['Id'] === $id && (string)$rel['TargetMode'] !== 'External') $target = (string)$rel['Target'];
        }
        $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        if (!preg_match('~^xl/worksheets/[a-zA-Z0-9_.-]+\.xml$~D', $target)) throw new RuntimeException('Unsupported worksheet layout. Save a new .xlsx copy from Excel.');
        $strings = [];
        if (isset($zip['xl/sharedStrings.xml'])) {
            foreach ($read('xl/sharedStrings.xml')->xpath('/*/*[local-name()="si"]') as $si) {
                if (count($strings) >= 50000) throw new RuntimeException('Too many text entries in this workbook.');
                $strings[] = import_text($si);
            }
        }
        $rows = [];
        foreach ($read($target)->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            $rowNumber = (int)$row['r'];
            if ($rowNumber < 1 || $rowNumber > 1001) throw new RuntimeException('Use the first row for headings and at most 1,000 customer rows. Remove extra formatted rows.');
            $values = [];
            foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                if (!preg_match('/^([A-Z]+)[0-9]+$/D', (string)$cell['r'], $match)) throw new RuntimeException('Invalid cell reference.');
                $col = 0; foreach (str_split($match[1]) as $letter) $col = $col * 26 + ord($letter) - 64;
                if ($col > 50) throw new RuntimeException('Use at most 50 columns.');
                if ($cell->xpath('./*[local-name()="f"]')) throw new RuntimeException('Formulas are not imported. Paste values only in the customer sheet.');
                $nodes = $cell->xpath('./*[local-name()="v"]'); $value = (string)($nodes[0] ?? '');
                $type = (string)$cell['t'];
                if ($type === 's') {
                    if (!ctype_digit($value) || !isset($strings[(int)$value])) throw new RuntimeException('Invalid shared text in workbook.');
                    $value = $strings[(int)$value];
                } elseif ($type === 'inlineStr') $value = import_text($cell);
                elseif ($type === 'e') throw new RuntimeException('Resolve spreadsheet cell errors before importing.');
                $values[$col - 1] = $value;
            }
            $rows[$rowNumber - 1] = $values;
        }
        ksort($rows); return $rows;
    } catch (UnexpectedValueException $error) {
        throw new RuntimeException('Could not open the Excel file. Upload an unencrypted .xlsx workbook or CSV.');
    } finally {
        unset($read, $zip, $entry);
        if (is_file($zipPath)) unlink($zipPath);
        unlink($temp);
    }
}
function import_validate(array $rows): array {
    $headers = $rows[0] ?? []; unset($rows[0]);
    $aliases = ['name'=>'name','customer'=>'name','customer name'=>'name','company'=>'name','company name'=>'name','contact name'=>'name','email'=>'email','email address'=>'email','phone'=>'phone','phone number'=>'phone','mobile'=>'phone','address'=>'address','service location'=>'address'];
    $map = [];
    foreach ($headers as $column => $heading) {
        $key = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$heading)));
        if (isset($aliases[$key])) {
            $field = $aliases[$key];
            if (isset($map[$field])) throw new RuntimeException('Use only one column for each field: Name, Email, Phone, Address.');
            $map[$field] = $column;
        }
    }
    if (!isset($map['name'])) throw new RuntimeException('The first row must include a Name, Customer Name, Company Name or Contact Name heading.');
    $result = [];
    foreach ($rows as $index => $row) {
        if (!array_filter($row, fn($v) => trim((string)$v) !== '')) continue;
        $values = []; $errors = [];
        foreach (['name'=>190,'email'=>190,'phone'=>80,'address'=>2000] as $field=>$max) {
            $value = trim((string)($row[$map[$field] ?? -1] ?? ''));
            if (preg_match('//u', $value) !== 1) throw new RuntimeException('Save your CSV using UTF-8 encoding, or use .xlsx.');
            if (strlen($value)>$max) $errors[] = ucfirst($field).' is too long';
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) $errors[] = 'Unsupported control characters';
            $values[$field] = $value;
        }
        if ($values['name'] === '') $errors[] = 'Name is required';
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';
        if (preg_match('/^[+-]?\d+(?:\.\d+)?e[+-]?\d+$/i', $values['phone'])) $errors[] = 'Phone uses scientific notation; format it as Text in Excel';
        $result[] = ['row'=>$index+1,'values'=>$values,'errors'=>$errors];
    }
    if (!$result) throw new RuntimeException('No customer rows were found.');
    return $result;
}
function import_key(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower(trim($value),'UTF-8') : strtolower(trim($value)); }
function import_classify(array $rows, array $existing): array {
    $names = []; $emails = [];
    foreach ($existing as $customer) { $names[import_key($customer['name'])] = true; if ($customer['email'] !== '') $emails[import_key($customer['email'])] = true; }
    foreach ($rows as &$row) {
        $name = import_key($row['values']['name']); $email = import_key($row['values']['email']);
        $row['status'] = $row['errors'] ? 'Invalid' : ((isset($names[$name]) || ($email !== '' && isset($emails[$email]))) ? 'Duplicate' : 'Ready');
        if ($row['status'] === 'Ready') { $names[$name] = true; if ($email !== '') $emails[$email] = true; }
    }
    unset($row); return $rows;
}
