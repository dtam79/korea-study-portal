<?php
/** W2-2 pure-PHP guards; no DB, real data, or WordPress environment. */
define('ABSPATH','/');
require __DIR__ . '/../../kg-portal-core/src/Support/DomainException.php';
require __DIR__ . '/../inc/workflow/class-csv.php';
require __DIR__ . '/../inc/workflow/class-translations.php';
function w22_test(bool $ok,string $name):void { if (!$ok) throw new RuntimeException('FAIL: '.$name); echo "PASS: $name\n"; }
function w22_conflict(callable $task):bool {
    try { $task(); } catch (KGP_Domain_Exception $e) { return $e->slug()==='version_conflict'; }
    return false;
}
KGP_Operator_CSV::assert_preview_snapshot(null,null);
w22_test(true,'Create rows remain absent before apply');
$original=['id'=>'17','public_id'=>'QA-U01','lock_version'=>'5','official_name_ko'=>'Original'];
KGP_Operator_CSV::assert_preview_snapshot($original,$original);
w22_test(true,'Unchanged row can be applied');
w22_test(w22_conflict(static fn()=>KGP_Operator_CSV::assert_preview_snapshot(null,$original)), 'Concurrent creation rejected');
w22_test(w22_conflict(static fn()=>KGP_Operator_CSV::assert_preview_snapshot($original,null)), 'Concurrent deletion rejected');
$modified=$original; $modified['official_name_ko']='Changed by another operator';
w22_test(w22_conflict(static fn()=>KGP_Operator_CSV::assert_preview_snapshot($original,$modified)), 'Concurrent value edit rejected');
$modified=$original; $modified['lock_version']='6';
w22_test(w22_conflict(static fn()=>KGP_Operator_CSV::assert_preview_snapshot($original,$modified)), 'Concurrent revision edit rejected');
$modified=$original; $modified['extra']='unexpected';
w22_test(w22_conflict(static fn()=>KGP_Operator_CSV::assert_preview_snapshot($original,$modified)), 'Snapshot schema mismatch rejected');
$val=new ReflectionMethod(KGP_Operator_Translations::class,'validate');
$original=['title'=>'공식 장학금','amount_krw'=>100000,'public_url'=>'https://example.test/official'];
$translated=['title'=>'Official scholarship','amount_krw'=>100000,'public_url'=>'https://example.test/official'];
w22_test($val->invoke(null,'scholarship',$original,$translated)===$translated,'Translation may change text only');
$invalid=$translated; $invalid['amount_krw']=200000;
$threw=false; try {$val->invoke(null,'scholarship',$original,$invalid);} catch (InvalidArgumentException $e) {$threw=true;}
w22_test($threw,'Translation cannot change shared amounts');
$invalid=$translated; $invalid['public_url']='https://example.test/other';
$threw=false; try {$val->invoke(null,'scholarship',$original,$invalid);} catch (InvalidArgumentException $e) {$threw=true;}
w22_test($threw,'Translation cannot change an official link');
echo "PASS: W2-2 CSV snapshot and translation contracts passed.\n";
