<?php
declare(strict_types=1);

const CUSTOMER_SORTS = ['name'=>'Name A–Z','newest'=>'Newest first','oldest'=>'Oldest first','balance'=>'Highest balance'];
const CUSTOMERS_PER_PAGE = 50;

// Search across name, email, phone and address. Returns [WHERE clause, parameters].
function customers_filter(string $search): array {
    $search=trim(substr($search,0,190));
    if ($search==='') return ['1=1',[]];
    $like='%'.addcslashes($search,'%_\\').'%';
    return ['(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)',[$like,$like,$like,$like]];
}
// Customers with their document counts and outstanding invoice balance (amounts in cents).
function customers_list(string $search, string $sort, ?int $limit=null, int $offset=0): array {
    [$where,$params]=customers_filter($search);
    $order=['name'=>'c.name,c.id','newest'=>'c.id DESC','oldest'=>'c.id','balance'=>'outstanding DESC,c.name'][$sort] ?? 'c.name,c.id';
    $sql="SELECT c.*,
        (SELECT COUNT(*) FROM documents d WHERE d.customer_id=c.id AND d.kind='quotation') AS quotations,
        (SELECT COUNT(*) FROM documents d WHERE d.customer_id=c.id AND d.kind='invoice') AS invoices,
        (SELECT COALESCE(SUM(d.total),0) FROM documents d WHERE d.customer_id=c.id AND d.kind='invoice')
          - (SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN documents d ON d.id=p.document_id WHERE d.customer_id=c.id AND d.kind='invoice') AS outstanding,
        (SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN documents d ON d.id=p.document_id WHERE d.customer_id=c.id) AS paid_total
        FROM customers c WHERE $where ORDER BY $order";
    if ($limit!==null) $sql.=' LIMIT '.max(1,$limit).' OFFSET '.max(0,$offset);
    return query($sql,$params)->fetchAll();
}
function customers_count(string $search): int {
    [$where,$params]=customers_filter($search);
    return (int)one("SELECT COUNT(*) AS n FROM customers c WHERE $where",$params)['n'];
}
// Deletes the chosen customers, or every customer matching $search when $ids is null, together with their Proformas,
// Tax Invoices and payments. Customers with documents are only deleted when the administrator confirmed the extra warning.
// Returns [deleted names, deleted document numbers].
function customers_delete(?array $ids, string $search, bool $confirmed): array {
    global $db;
    [$where,$params]=customers_filter($search);
    if ($ids!==null) {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
        if (!$ids) throw new RuntimeException('Tick at least one customer to delete.');
        $where='c.id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; $params=$ids;
    }
    $db->beginTransaction();
    try {
        $rows=query("SELECT c.id,c.name FROM customers c WHERE $where FOR UPDATE",$params)->fetchAll();
        $docs=[];
        if ($rows) {
            $docs=query('SELECT * FROM documents WHERE customer_id IN ('.implode(',',array_map(fn($r)=>(int)$r['id'],$rows)).') FOR UPDATE')->fetchAll();
            if ($docs && !$confirmed) throw new RuntimeException('These customers have '.count($docs).' Proforma'.(count($docs)===1?'':'s').' or Tax Invoice'.(count($docs)===1?'':'s').'. Confirm the warning to delete them too.');
            $numbers=documents_purge($docs,$confirmed);
            query('DELETE FROM customers WHERE id IN ('.implode(',',array_map(fn($r)=>(int)$r['id'],$rows)).')');
            $names=array_column($rows,'name');
            activity('Deleted '.count($names).' customer'.(count($names)===1?'':'s').': '.implode(', ',array_slice($names,0,15)).(count($names)>15?' and '.(count($names)-15).' more':'').($numbers?' – with '.documents_summary($numbers):''));
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    foreach ($docs as $doc) documents_remove_files((int)$doc['id']);
    return [array_column($rows,'name'),array_map('number',$docs)];
}
// Plain-language warning for customers that have documents, for the second confirmation. Empty when there are none.
function customers_risk(string $where, array $params): string {
    global $config;
    $r=one("SELECT COUNT(DISTINCT c.id) AS customers, COUNT(d.id) AS docs, COALESCE((SELECT SUM(p.amount) FROM payments p JOIN documents d2 ON d2.id=p.document_id JOIN customers c2 ON c2.id=d2.customer_id WHERE ".str_replace('c.','c2.',$where)."),0) AS paid FROM customers c JOIN documents d ON d.customer_id=c.id WHERE $where",[...$params,...$params]);
    if (!(int)$r['docs']) return '';
    return (int)$r['customers'].' customer'.((int)$r['customers']===1?' has ':'s have ').(int)$r['docs'].' Proforma/Tax Invoice'.((int)$r['docs']===1?'':'s').((int)$r['paid']?' with '.($config['currency']??'').' '.money((int)$r['paid']).' in recorded payments':'');
}
// Spreadsheet download of the (filtered) customer list. Cells that a spreadsheet would run as a formula are prefixed with an apostrophe.
function customers_csv(string $search, string $sort): never {
    global $config;
    $cell=fn($v)=>preg_match('/^[=+\-@\t\r]/',(string)$v) ? "'".$v : (string)$v;
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="customers-'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','w');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Name','Email','Phone','Address','Added','Proformas','Tax Invoices','Outstanding ('.($config['currency']??'').')']);
    foreach (customers_list($search,$sort) as $c) fputcsv($out,array_map($cell,[$c['name'],$c['email'],$c['phone'],$c['address'],substr((string)$c['created_at'],0,10),$c['quotations'],$c['invoices'],number_format((int)$c['outstanding']/100,2,'.','')]));
    fclose($out); exit;
}
