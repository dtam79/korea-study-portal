<?php
/** WP-CLI: ddev wp eval-file .../public-data-wp-readonly.php; never writes data. */
declare(strict_types=1);
if (!defined('ABSPATH')) {fwrite(STDERR,"WordPress bootstrap required\n");exit(2);}
function kgp_real_assert(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    echo 'PASS: '.$message."\n";
}
kgp_real_assert(class_exists('KGP_Public_Catalog'),'Core public read model registered');
foreach (['ko','en'] as $locale) {
    $query=['locale'=>$locale,'page'=>1];
    $list=KGP_Public_Catalog::search($query);
    $request=new WP_REST_Request('GET','/kgp/v1/catalog');
    $request->set_query_params($query);
    $response=rest_do_request($request);
    kgp_real_assert((int)$response->get_status()===200,'Public REST catalog responds for '.$locale);
    $rest=$response->get_data();
    kgp_real_assert(is_array($rest) && $rest['total']===$list['total'] && $rest['items']===$list['items'],
        'REST and Core approved catalog outputs match for '.$locale);
    $items=array_slice($list['items'],0,8);
    if (!$items) {echo 'SKIP: No approved offering in the '.$locale.' catalog. Real-data consistency requires approved fixtures.\n';continue;}
    foreach ($items as $card) {
        $detail=KGP_Public_Catalog::offering((string)$card['offering_id'],$locale);
        kgp_real_assert(is_array($detail),'Approved detail exists: '.$card['offering_id']);
        foreach (['offering_id','university_id','program_name','campus_name','level',
                  'academic_year','academic_term','teaching_languages','english_only',
                  'tuition','certified','admission','card_source_checked_at'] as $field) {
            kgp_real_assert(($detail[$field]??null)===($card[$field]??null),
                $locale.' catalog/detail parity '.$card['offering_id'].': '.$field);
        }
        $uni=KGP_Public_Catalog::university((string)$card['university_id'],$locale);
        kgp_real_assert(is_array($uni),'Parent approved university exists for '.$card['offering_id']);
        $matches=array_values(array_filter($uni['offerings'],static fn($row)=>$row['offering_id']===$card['offering_id']));
        kgp_real_assert(count($matches)===1,'Offering belongs to exactly one published parent university');
        if ($card['card_source_checked_at']!==null) {
            kgp_real_assert(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$card['card_source_checked_at'])===1,
                'Card check date is valid date-only metadata');
        }
        foreach (($detail['sections']??[]) as $section) foreach (($section['groups']??[]) as $group) foreach (($group['fields']??[]) as $field) {
            $sources=$field['source_documents']??[];
            kgp_real_assert(is_array($sources) && count($sources)>0,'Public field has an approved original source');
            foreach ($sources as $source) kgp_real_assert(!empty($source['publisher']) && !empty($source['public_url']) && !empty($source['checked_at']),
                'Public item evidence includes issuer, official URL and verification date');
        }
    }
}
echo "SUCCESS: P02/P03 approved read-only consistency. No database modifications.\n";
