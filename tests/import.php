<?php
require __DIR__.'/../app/import-lib.php';
function import_check(bool $condition, string $message): void { if (!$condition) throw new Exception($message); }
function workbook(string $path, string $sheet, ?string $shared = null): void {
    $zip = new PharData($path,0,null,Phar::ZIP);
    $zip['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
    $zip['_rels/.rels'] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $zip['xl/workbook.xml'] = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Customers" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $zip['xl/_rels/workbook.xml.rels'] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
    $zip['xl/worksheets/sheet1.xml'] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="30" customWidth="1"/><col min="2" max="4" width="35" customWidth="1"/></cols><sheetData>'.$sheet.'</sheetData></worksheet>';
    if ($shared !== null) $zip['xl/sharedStrings.xml'] = $shared;
}
function cell(string $ref, string $value): string { return '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1,'UTF-8').'</t></is></c>'; }
$header = '<row r="1">'.cell('A1','Name').cell('B1','Email').cell('C1','Phone').cell('D1','Address').'</row>';
if (($argv[1] ?? '') === '--template') {
    workbook($argv[2],$header.'<row r="2">'.cell('A2','Example Customer').cell('B2','customer@example.com').cell('C2','+255700000000').cell('D2','Dar es Salaam, Tanzania').'</row>');
    echo "Excel template created.\n"; exit;
}
$temp = sys_get_temp_dir().'/bp-import-test-'.bin2hex(random_bytes(6)); mkdir($temp);
try {
    workbook($temp.'/inline.xlsx',$header.'<row r="2">'.cell('A2','A & B Trading').cell('C2','0712345678').cell('D2',"Line one\nLine two").'</row>');
    $rows = import_validate(import_rows($temp.'/inline.xlsx','xlsx'));
    import_check($rows[0]['values']['phone']==='0712345678','Phone leading zero lost');
    import_check($rows[0]['values']['email']==='','Sparse cells shifted');
    import_check($rows[0]['values']['name']==='A & B Trading','XML text decoding');
    workbook($temp.'/shared.xlsx',$header.'<row r="2"><c r="A2" t="s"><v>0</v></c></row>','<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Sample </t></r><r><t>Company</t></r></si></sst>');
    import_check(import_validate(import_rows($temp.'/shared.xlsx','xlsx'))[0]['values']['name']==='Sample Company','Shared rich text');
    file_put_contents($temp.'/sample.csv',"\xEF\xBB\xBFName;Email;Phone;Address\nAlpha;alpha@example.com;+255700000001;Office\nALPHA;other@example.com;;\nBeta;bad-email;;\nGamma;existing@example.com;;\n");
    $classified=import_classify(import_validate(import_rows($temp.'/sample.csv','csv')),[['name'=>'Existing','email'=>'existing@example.com']]);
    import_check(array_column($classified,'status')===['Ready','Duplicate','Invalid','Duplicate'],'Duplicate and invalid classification');
    foreach (['xml','formula','headers','xls','limit'] as $case) {
        try {
            if ($case==='xml') import_xml('<!DOCTYPE x [<!ENTITY test SYSTEM "file:///test">]><x/>');
            if ($case==='formula') { workbook($temp.'/formula.xlsx',$header.'<row r="2"><c r="A2"><f>1+1</f><v>2</v></c></row>'); import_rows($temp.'/formula.xlsx','xlsx'); }
            if ($case==='headers') import_validate([['Unknown'],['Person']]);
            if ($case==='xls') import_rows($temp.'/sample.csv','xls');
            if ($case==='limit') { file_put_contents($temp.'/large.csv',"Name\n".str_repeat("Person\n",1001)); import_rows($temp.'/large.csv','csv'); }
            throw new Exception('Expected rejection: '.$case);
        } catch (RuntimeException $expected) {}
    }
    echo "PASS: XLSX inline/shared text, sparse cells, leading zeros, CSV delimiters/BOM, duplicates, invalid emails, XML entities, formulas, headers and limits\n";
} finally { foreach (glob($temp.'/*') as $file) unlink($file); rmdir($temp); }
