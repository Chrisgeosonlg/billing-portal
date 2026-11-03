<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/tfpdf/tfpdf.php';
require_once __DIR__.'/attachments-lib.php';
require_once __DIR__.'/marks-lib.php';

// Corporate A4 layout: navy/lime top bar, logo and title, details panel, banded item table, totals box, footer.
const PDF_NAVY=[16,45,68]; const PDF_GREEN=[36,101,72]; const PDF_LIME=[180,218,120]; const PDF_INK=[24,52,75]; const PDF_GREY=[95,110,122]; const PDF_TINT=[243,246,248];
const PDF_COLS=[['#',10,'C'],['Description',90,'L'],['Qty',20,'R'],['Unit price',30,'R'],['Amount',30,'R']];

class PortalDocumentPdf extends tFPDF {
    public string $documentNumber = '';
    public string $footerText = '';
    public function Header() {
        $this->SetFillColor(...PDF_NAVY); $this->Rect(0,0,210,4,'F');
        $this->SetFillColor(...PDF_LIME); $this->Rect(0,4,210,1.2,'F');
        if ($this->PageNo()>1) {
            $this->SetXY(15,10); $this->SetFont('DejaVu','B',9); $this->SetTextColor(...PDF_GREY);
            $this->Cell(0,6,$this->documentNumber.' (continued)',0,1,'R'); $this->SetY(20);
        }
    }
    public function Footer() {
        $this->SetDrawColor(220,227,232); $this->Line(15,280,195,280);
        $this->SetXY(15,282); $this->SetFont('DejaVu','B',8); $this->SetTextColor(...PDF_GREEN);
        $this->Cell(90,4.5,'Thank you for your business.',0,0,'L');
        $this->SetFont('DejaVu','',8); $this->SetTextColor(...PDF_GREY);
        // {nb} is swapped for the page total after layout, so widen the cell by the difference to keep the text flush right.
        $pages='Page '.$this->PageNo().' of '; $slack=$this->GetStringWidth('{nb}')-$this->GetStringWidth((string)$this->PageNo());
        $this->Cell(90+$slack,4.5,$this->documentNumber.'  ·  '.$pages.'{nb}',0,1,'R');
        $this->SetX(15); $this->Cell(180,4.5,$this->footerText,0,0,'L');
    }
    public function label(string $text, float $x, float $w): void {
        $this->SetX($x); $this->SetFont('DejaVu','B',7.5); $this->SetTextColor(...PDF_GREEN); $this->Cell($w,5,mb_strtoupper($text),0,2);
    }
    public function block(string $text, float $x, float $w, float $size=9, bool $bold=false, array $color=PDF_GREY, float $line=4.6): void {
        if ($text==='') return;
        $this->SetX($x); $this->SetFont('DejaVu',$bold?'B':'',$size); $this->SetTextColor(...$color); $this->MultiCell($w,$line,$text,0,'L');
    }
    // Number of lines MultiCell will use for $text at width $w with the current font.
    public function lineCount(string $text, float $w): int {
        $max=$w-2*$this->cMargin; $count=0;
        foreach (explode("\n",str_replace("\r",'',$text)) as $para) {
            $lineWidth=0; $count++;
            foreach (preg_split('/(\s+)/u',$para,-1,PREG_SPLIT_DELIM_CAPTURE) as $word) {
                $wordWidth=$this->GetStringWidth($word);
                if ($lineWidth+$wordWidth>$max && $lineWidth>0 && trim($word)!=='') { $count++; $lineWidth=0; }
                while ($wordWidth>$max) { $count++; $wordWidth-=$max; }
                $lineWidth+=$wordWidth;
            }
        }
        return max(1,$count);
    }
    public function tableHead(): void {
        $this->SetX(15); $this->SetFillColor(...PDF_NAVY); $this->SetTextColor(255,255,255); $this->SetFont('DejaVu','B',8.5);
        foreach (PDF_COLS as [$title,$w,$align]) $this->Cell($w,8,$title,0,0,$align,true);
        $this->Ln(8);
    }
}
// 2.50 → "2.5", 1.00 → "1".
function pdf_quantity(int $value): string { return rtrim(rtrim(money($value),'0'),'.'); }

// $marks: 'stamp' / 'signature' to show. Null uses the document's saved choice (when marks-lib is loaded).
function document_pdf(array $doc, int $paid=0, ?array $marks=null): string {
    if (!function_exists('mb_strlen')) throw new RuntimeException('PDF generation requires PHP mbstring. Ask your host to enable it.');
    $company=json_decode($doc['company_snapshot'],true,512,JSON_THROW_ON_ERROR);
    $customer=json_decode($doc['customer_snapshot'],true,512,JSON_THROW_ON_ERROR);
    $items=json_decode($doc['items_json'],true,512,JSON_THROW_ON_ERROR);
    $invoice=$doc['kind']==='invoice'; $currency=$company['currency']; $balance=(int)$doc['total']-$paid;
    $pdf=new PortalDocumentPdf();
    $pdf->AddFont('DejaVu','','DejaVuSans.ttf',true); $pdf->AddFont('DejaVu','B','DejaVuSans-Bold.ttf',true);
    $pdf->documentNumber=number($doc); $pdf->SetTitle(kind_name($doc['kind']).' '.number($doc),true);
    $pdf->footerText=implode('  ·  ',array_filter([$company['company'],$company['email'],$company['phone']]));
    $pdf->SetAuthor($company['company'],true); $pdf->SetMargins(15,15,15); $pdf->SetAutoPageBreak(true,22); $pdf->AliasNbPages(); $pdf->AddPage();

    // Logo left; payment status tag, title and number right.
    // Fit any client logo inside a 44 × 26 mm box above the company details.
    $logo=company_logo_path('document'); [$lw,$lh]=getimagesize($logo); $scale=min(44/$lw,26/$lh);
    $pdf->Image($logo,15,13,$lw*$scale,$lh*$scale);
    if ($stamp=payment_stamp($doc,$paid)) {
        // The screen colours at 40% strength on white.
        $pdf->SetTextColor(...['paid'=>[161,199,181],'part'=>[225,186,157],'unpaid'=>[223,170,175]][$stamp[1]]);
        $pdf->SetFont('DejaVu','B',18);
        $pdf->SetXY(105,12); $pdf->Cell(90,9,$stamp[0],0,0,'R');
    }
    $pdf->SetXY(105,22); $pdf->SetFont('DejaVu','B',24); $pdf->SetTextColor(...PDF_NAVY); $pdf->Cell(90,11,mb_strtoupper(kind_name($doc['kind'])),0,2,'R');
    $pdf->SetFont('DejaVu','',11); $pdf->SetTextColor(...PDF_GREY); $pdf->Cell(90,6,'No. '.number($doc),0,2,'R');

    // Company details under the logo.
    $pdf->SetY(42);
    $pdf->block($company['company'],15,95,9.5,true,PDF_INK,4.8);
    $pdf->block($company['address'],15,95,8.5,false,PDF_GREY,4.2);
    $pdf->block(trim($company['email'].'  ·  '.$company['phone'],' ·'),15,95,8.5,false,PDF_GREY,4.2);

    // Customer left, document details panel right.
    $top=max($pdf->GetY(),46)+8; $pdf->SetY($top);
    $pdf->label($invoice?'Billed to':'Prepared for',15,95);
    $pdf->block($customer['name'],15,95,12,true,PDF_INK,6);
    $pdf->block($customer['address'],15,95);
    $pdf->block(trim($customer['email'].'  ·  '.$customer['phone'],' ·'),15,95);
    $leftBottom=$pdf->GetY();
    $rows=[[$invoice?'Invoice date':'Proforma date',$doc['document_date']],[$invoice?'Due date':'Valid until',$doc['due_date']],[$invoice?'Amount due':'Total',$currency.' '.money($invoice?max(0,$balance):(int)$doc['total'])]];
    $panelH=8+count($rows)*7.5;
    $pdf->SetFillColor(...PDF_TINT); $pdf->Rect(115,$top,80,$panelH,'F');
    $pdf->SetFillColor(...PDF_GREEN); $pdf->Rect(115,$top,1.2,$panelH,'F');
    $pdf->SetY($top+4);
    foreach ($rows as $i=>[$label,$value]) {
        $last=$i===count($rows)-1;
        $pdf->SetX(120); $pdf->SetFont('DejaVu','',8.5); $pdf->SetTextColor(...PDF_GREY); $pdf->Cell(30,7.5,$label,0,0,'L');
        $pdf->SetFont('DejaVu','B',$last?10.5:9); $pdf->SetTextColor(...($last?PDF_NAVY:PDF_INK)); $pdf->Cell(42,7.5,$value,0,1,'R');
    }
    $pdf->SetY(max($leftBottom,$top+$panelH)+8);

    // Items: navy header, banded rows, repeated header on new pages.
    $pdf->tableHead();
    foreach ($items as $i=>$item) {
        $pdf->SetFont('DejaVu','',9);
        $h=$pdf->lineCount($item['description'],PDF_COLS[1][1])*4.8+5;
        if ($pdf->GetY()+$h>272) { $pdf->AddPage(); $pdf->tableHead(); $pdf->SetFont('DejaVu','',9); }
        $y=$pdf->GetY();
        if ($i%2===1) { $pdf->SetFillColor(...PDF_TINT); $pdf->Rect(15,$y,180,$h,'F'); }
        $pdf->SetXY(15,$y+2.5); $pdf->SetTextColor(...PDF_GREY); $pdf->Cell(10,4.8,(string)($i+1),0,0,'C');
        $pdf->SetTextColor(...PDF_INK); $pdf->SetXY(25,$y+2.5); $pdf->MultiCell(90,4.8,$item['description'],0,'L');
        $pdf->SetXY(115,$y+2.5);
        $pdf->Cell(20,4.8,pdf_quantity((int)$item['quantity']),0,0,'R'); $pdf->Cell(30,4.8,money((int)$item['price']),0,0,'R');
        $pdf->SetFont('DejaVu','B',9); $pdf->Cell(30,4.8,money((int)$item['amount']),0,0,'R');
        $pdf->SetDrawColor(225,232,237); $pdf->Line(15,$y+$h,195,$y+$h); $pdf->SetY($y+$h);
    }

    // Totals box right, payment details left.
    $totals=[['Subtotal',(int)$doc['subtotal']]];
    if ((int)$doc['discount']>0) $totals[]=['Discount','- '.money((int)$doc['discount'])];
    if ((int)$doc['tax_bps']>0) $totals[]=['Tax ('.pdf_quantity((int)$doc['tax_bps']).'%)',(int)$doc['tax']];
    if ($invoice) { $totals[]=['Total',(int)$doc['total']]; $totals[]=['Paid',$paid]; }
    if ($pdf->GetY()+count($totals)*6.5+26>272) $pdf->AddPage();
    $pdf->Ln(6); $top=$pdf->GetY();
    foreach ($totals as [$label,$amount]) {
        $pdf->SetX(115); $pdf->SetFont('DejaVu',$label==='Total'?'B':'',9); $pdf->SetTextColor(...($label==='Total'?PDF_INK:PDF_GREY));
        $pdf->Cell(40,6.5,$label,0,0,'L'); $pdf->SetTextColor(...PDF_INK); $pdf->Cell(40,6.5,is_int($amount)?money($amount):$amount,0,1,'R');
    }
    $pdf->Ln(1.5); $pdf->SetX(115); $pdf->SetFillColor(...PDF_NAVY); $pdf->SetTextColor(255,255,255); $pdf->SetFont('DejaVu','B',9.5);
    $pdf->Cell(40,10,'  '.($invoice?'Balance due':'Total').' · '.$currency,0,0,'L',true); $pdf->SetFont('DejaVu','B',11.5);
    $pdf->Cell(40,10,money($invoice?$balance:(int)$doc['total']).'  ',0,1,'R',true);
    $after=$pdf->GetY();
    if ($invoice && $company['payment_details']!=='') {
        $pdf->SetY($top); $pdf->label('Payment details',15,92); $pdf->block($company['payment_details'],15,92,8.5,false,PDF_INK,4.4);
        $after=max($after,$pdf->GetY());
    }
    $pdf->SetY($after);
    if ($doc['notes']!=='') {
        $pdf->Ln(7); if ($pdf->GetY()>255) $pdf->AddPage();
        $pdf->label('Notes & terms',15,180); $pdf->block($doc['notes'],15,180,8.5,false,PDF_INK,4.4);
    }
    $marks ??= function_exists('doc_marks') && isset($doc['id']) ? doc_marks((int)$doc['id']) : [];
    if ($marks) document_pdf_marks($pdf,$marks);
    return $pdf->Output('S');
}
// Signature (with line, name and title) on the left, company stamp beside it.
function document_pdf_marks(PortalDocumentPdf $pdf, array $marks): void {
    // The block reaches about 35 mm below its top; the footer starts at 280 mm.
    if ($pdf->GetY()>234) $pdf->AddPage();
    $pdf->Ln(8); $top=$pdf->GetY(); $bottom=$top; $x=15;
    if (in_array('signature',$marks,true) && ($path=mark_path('signature'))) {
        [$w,$h]=mark_fit($path,55,22); $pdf->Image($path,15,$top+22-$h,$w,$h,'PNG');
        $pdf->SetDrawColor(120,130,140); $pdf->Line(15,$top+24,75,$top+24);
        $pdf->SetXY(15,$top+25); $pdf->SetTextColor(...PDF_INK); $person=signatory();
        $pdf->SetFont('DejaVu','B',9); $pdf->Cell(60,5,$person['name']!==''?$person['name']:'Authorised signature',0,2);
        $pdf->SetFont('DejaVu','',8); $pdf->SetTextColor(...PDF_GREY); $pdf->Cell(60,4.5,$person['title']!==''?$person['title']:($person['name']!==''?'Authorised signature':''),0,2);
        $bottom=$pdf->GetY(); $x=88;
    }
    if (in_array('stamp',$marks,true) && ($path=mark_path('stamp'))) {
        [$w,$h]=mark_fit($path,40,36); $pdf->Image($path,$x,$top-4,$w,$h,'PNG'); $bottom=max($bottom,$top-4+$h);
    }
    $pdf->SetY($bottom+4);
}

function download_document_pdf(array $doc): never {
    $paid=(int)one('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE document_id=?',[$doc['id']])['paid'];
    $bytes=document_pdf($doc,$paid);
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.number($doc).'.pdf"'); header('Content-Length: '.strlen($bytes));
    header('Cache-Control: private, no-store'); echo $bytes; exit;
}
