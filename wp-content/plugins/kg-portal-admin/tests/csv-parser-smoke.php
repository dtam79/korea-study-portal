<?php
/** Offline pure-PHP test, no WordPress or DB required. */
define('ABSPATH','/tmp/');
require dirname(__DIR__).'/inc/workflow/class-csv.php';
function check($value,string $label):void {if (!$value) throw new RuntimeException('FAIL: '.$label);}
$csv="\xEF\xBB\xBFentity_type,public_id,official_name_ko\r\nuniversity,U-test,\"예시, 대학\"\r\nuniversity,U-demo,\"Test \"\"quoted\"\" name\"\r\n";
$rows=KGP_Operator_CSV::parse($csv);
check(count($rows)===2,'CSV row count');
check($rows[0]['raw']['official_name_ko']==='예시, 대학','UTF-8 and comma in quoted field');
check($rows[1]['raw']['official_name_ko']==='Test "quoted" name','RFC 4180 quoting');
$bad=false;try{KGP_Operator_CSV::parse("entity_type,public_id,public_id\nuniversity,A,B\n");}catch(InvalidArgumentException $e){$bad=true;}
check($bad,'Duplicate column rejection');
$bad=false;try{KGP_Operator_CSV::parse("entity_type,public_id\nuniversity,A\x00\n");}catch(InvalidArgumentException $e){$bad=true;}
check($bad,'NUL byte rejection');
$bad=false;try{KGP_Operator_CSV::parse("entity_type,public_id\nuniversity,A,B\n");}catch(Exception $e){$bad=true;}
// Row-level errors are intentionally reported, not suppressed by throwing the entire batch.
check(!$bad,'Per-row length error reported rather than dropping entire file');
check(KGP_Operator_CSV::parse("entity_type,public_id\nuniversity,A,B\n")[0]['errors']!==[],'Column count error reported');
$big="entity_type,public_id\n".str_repeat("university,U-0000001\n",501);
$bad=false;try{KGP_Operator_CSV::parse($big);}catch(InvalidArgumentException $e){$bad=true;}
check($bad,'500 row limit');
echo "PASS: UTF-8, quoting, errors, column validation and row limit.\n";
