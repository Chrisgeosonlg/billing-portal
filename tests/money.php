<?php
require __DIR__ . '/../app/lib.php';
function check(bool $condition, string $message): void { if (!$condition) throw new Exception($message); }
$t = totals(['Professional services','Supplies'],['2.5','1'],['1000.10','50'],'50','18');
check($t['subtotal'] === 255025, 'Fractional quantities');
check($t['tax'] === 45005, 'Tax rounding after discount');
check($t['total'] === 295030, 'Final total');
check(units('0.01') === 1, 'Smallest amount');
check(totals(['Free visit'],['1'],['0'],'0','0')['total'] === 0, 'Zero-priced service');
foreach (['-1','1e5','1.001','NaN',''] as $bad) {
    try { units($bad); throw new Exception('Accepted invalid money'); } catch (RuntimeException $expected) {}
}
try { totals(['Service'],['1'],['10'],'11','0'); throw new Exception('Accepted excessive discount'); } catch (RuntimeException $expected) {}
try { date_input('2026-02-30'); throw new Exception('Accepted impossible date'); } catch (RuntimeException $expected) {}
check(date_input('2028-02-29') === '2028-02-29','Leap year');
check(e('<script>') === '&lt;script&gt;', 'HTML escaping');
echo "PASS: monetary calculations, validation, dates and escaping\n";
