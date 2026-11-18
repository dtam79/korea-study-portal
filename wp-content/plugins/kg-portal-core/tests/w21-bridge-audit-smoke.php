<?php
/** Pure W2-1 audit contract regression test; no WordPress/DB required. */
declare(strict_types=1);
define('ABSPATH', '/tmp/');
require dirname(__DIR__) . '/src/Database/FieldCatalog.php';
require dirname(__DIR__) . '/src/Service/PublicBridgeAudit.php';
function must(bool $v, string $name): void {
    if (!$v) throw new RuntimeException('FAIL: '.$name);
    echo 'PASS: '.$name."\n";
}
$catalog=KGP_Field_Catalog::sections();
must(KGP_Public_Bridge_Audit::catalog_coverage($catalog)===['sections'=>14,'fields'=>84],'Catalog has exactly 14 DET areas and 84 field definitions');
$card=[];
foreach(KGP_Public_Bridge_Audit::CARD_KEYS as $key) $card[$key]=null;
$card['offering_id']='o001';$card['university_id']='u001';
$card['tuition']=[['currency'=>'KRW','unit'=>'semester','min'=>4200000.0,'max'=>4200000.0]];
$card['teaching_languages']=['en'];$card['english_only']=true;
$card['admission']=['status'=>'open','start_date'=>'2027-01-01','end_date'=>'2027-02-01'];
$detail=$card;
$detail['sections']=[
    ['id'=>'DET-02','groups'=>[['scope'=>'offering','fields'=>[['field'=>'english_only_program','value'=>true,'source_documents'=>[['public_url'=>'https://example.org/a']]]]]]],
    ['id'=>'DET-03','groups'=>[['scope'=>'offering','fields'=>[['field'=>'tuition_items','value'=>['currency'=>'KRW','unit'=>'semester','amount'=>'4200000'],'source_documents'=>[['public_url'=>'https://example.org/b']]]]]]],
];
must(KGP_Public_Bridge_Audit::inspect($card,$detail,$catalog)===[],'Approved card/detail parity with valid DET-02 and DET-03 sources');
$wrong=$detail;$wrong['tuition'][0]['min']=4200001.0;
must(in_array('Card/detail mismatch: tuition',KGP_Public_Bridge_Audit::inspect($card,$wrong,$catalog),true),'Mismatching tuition detected');
$wrong=$detail;$wrong['sections'][0]['groups'][0]['fields'][0]['source_documents']=[];
must(count(KGP_Public_Bridge_Audit::inspect($card,$wrong,$catalog))===1,'Published field lacking evidence detected');
$wrong=$detail;$wrong['sections'][]=$detail['sections'][0];
must(count(KGP_Public_Bridge_Audit::inspect($card,$wrong,$catalog))===1,'Duplicate DET sections detected');
$wrong=$detail;$wrong['sections'][0]['id']='DET-99';
must(count(KGP_Public_Bridge_Audit::inspect($card,$wrong,$catalog))===1,'Unregistered DET section detected');
$en=$card;$en['campus_name']='English campus';$en['program_name']='English program';
must(KGP_Public_Bridge_Audit::inspect_languages($card,$en)===[],'Translated labels may differ without changing canonical data');
$en['admission']['status']='closed';
must(KGP_Public_Bridge_Audit::inspect_languages($card,$en)===['KO/EN shared field mismatch: admission'],'Language-specific status divergence detected');
$en=$card;$en['tuition'][0]['max']=4300000.0;
must(KGP_Public_Bridge_Audit::inspect_languages($card,$en)===['KO/EN shared field mismatch: tuition'],'Language-specific tuition divergence detected');
echo "SUCCESS: W2-1 P02/P03 bridge audit pure tests passed. No DB used.\n";
