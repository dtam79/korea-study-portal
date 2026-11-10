<?php

defined('ABSPATH') || exit;

/** End-to-end REST API check using real WordPress DB; synthetic data rolled back. */
final class KGP_Api_Command {
    public function check(): void {
        $schema=KGP_Schema::status();
        if (!$schema['ok'] || $schema['installed_version']!==KGP_Schema::VERSION) {
            WP_CLI::error('Schema is outdated. Run ddev wp kgp schema install.');
        }
        $required=['/kgp/v1/catalog','/kgp/v1/admissions','/kgp/v1/admin/fields'];
        $routes=rest_get_server()->get_routes();
        foreach ($required as $path) if (!array_key_exists($path,$routes)) WP_CLI::error('Missing REST route: '.$path);
        WP_CLI::success('Schema, API routes and version verified.');
    }

    private static function call(string $path,string $method='GET',?array $payload=null): WP_REST_Response|WP_Error {
        $parsed=wp_parse_url($path);
        $route=is_array($parsed)?($parsed['path']??$path):$path;
        $req=new WP_REST_Request($method,$route);
        if (is_array($parsed) && !empty($parsed['query'])) {
            parse_str($parsed['query'],$query);
            $req->set_query_params($query);
        }
        if ($payload!==null) $req->set_body_params($payload);
        return rest_do_request($req);
    }
    private static function assert_ok($resp,string $where): array {
        if ($resp instanceof WP_Error) throw new RuntimeException($where.': '.$resp->get_error_message());
        if ($resp->get_status()>=400) throw new RuntimeException($where.': HTTP '.$resp->get_status().' '.wp_json_encode($resp->get_data()));
        return $resp->get_data();
    }
    private static function eq($actual,$expected,string $message): void {
        if ($actual!==$expected) throw new RuntimeException($message.'; actual: '.wp_json_encode($actual));
    }

    public function smoke(): void {
        $host=strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST));
        if (!str_ends_with($host,'.ddev.site')) WP_CLI::error('Only allowed on local DDEV *.ddev.site.');
        if (!current_user_can('manage_options')) WP_CLI::error('Use ddev wp --user=localadmin kgp api smoke');
        $state=KGP_Schema::status();
        if (!$state['ok'] || $state['installed_version']!==KGP_Schema::VERSION) WP_CLI::error('Run wp kgp schema install first.');
        $prefix='QA'.substr(str_replace('-','',wp_generate_uuid4()),0,16);
        $steps=[];
        try {
            KGP_DB::transaction(function () use ($prefix,&$steps) {
                // The public endpoints must not expose raw, unreviewed university identities.
                $issuer=KGP_Evidence_Repository::create_issuer(['public_id'=>$prefix.'-issuer','official_name'=>'Synthetic Test Issuer','issuer_type'=>'university']);
                $source=KGP_Evidence_Repository::create_source(['public_id'=>$prefix.'-src','issuer_id'=>$issuer,
                    'document_title'=>'Synthetic QA prospectus','public_url'=>'https://example.org/qa.pdf','document_locator'=>'page 1']);
                $u=KGP_Entity_Repository::create('university',['public_id'=>$prefix.'-uni','official_name_ko'=>'테스트 대학교',
                    'official_name_en'=>'QA University','official_website'=>'https://example.org']);
                $c=KGP_Entity_Repository::create('campus',['public_id'=>$prefix.'-cam','university_id'=>$u,'official_name_ko'=>'테스트캠퍼스','region_code'=>'seoul']);
                $p=KGP_Entity_Repository::create('program',['public_id'=>$prefix.'-prg','university_id'=>$u,'campus_id'=>$c,
                    'official_name_ko'=>'컴퓨터공학','official_name_en'=>'Computer Engineering','field_code'=>'engineering','level_code'=>'bachelor']);
                $i=KGP_Entity_Repository::create('intake',['public_id'=>$prefix.'-in','university_id'=>$u,'academic_year'=>2027,
                    'academic_term'=>'fall','admission_type'=>'new','start_date'=>'2027-03-01','end_date'=>'2027-05-30']);
                $o=KGP_Entity_Repository::create('offering',['public_id'=>$prefix.'-off','program_id'=>$p,'intake_id'=>$i]);
                $hidden=self::assert_ok(self::call('/kgp/v1/catalog'),'before publication');
                $existing=array_column($hidden['items'],'offering_id');
                if (in_array($prefix.'-off',$existing,true)) throw new RuntimeException('Unapproved offering was exposed.');
                $absent=self::call('/kgp/v1/universities/'.$prefix.'-uni');
                self::eq($absent->get_status(),404,'Unapproved university was not hidden');
                $steps[]='Unapproved identity and offering hidden';

                KGP_Evidence_Repository::approve_issuer($issuer);
                KGP_Evidence_Repository::verify_source($source);
                foreach (['university'=>$u,'campus'=>$c,'program'=>$p,'intake'=>$i,'offering'=>$o] as $type=>$id) {
                    $submitted=self::assert_ok(self::call('/kgp/v1/admin/identities/'.$type.'/'.$id.'/submit','POST',[
                        'source_id'=>$source,'expected_lock'=>0,'source_locator'=>'page 1',
                        'aliases'=>$type==='university'?['QA Uni']:[],
                    ]),'identity submit '.$type);
                    self::eq($submitted['status'],'pending','Identity submit status');
                    self::assert_ok(self::call('/kgp/v1/admin/identities/'.$type.'/'.$id.'/approve','POST',[
                        'expected_lock'=>$submitted['lock_version']
                    ]),'identity approval '.$type);
                }
                $pub=self::assert_ok(self::call('/kgp/v1/catalog?q=QA&region=seoul&field=engineering&level=bachelor'),'approved identities');
                if (!in_array($prefix.'-off',array_column($pub['items'],'offering_id'),true)) throw new RuntimeException('Approved offering omitted from public catalog.');
                $steps[]='Submitted and reviewed identity published through REST';

                $draft=self::assert_ok(self::call('/kgp/v1/admin/fields','POST',[
                    'entity_type'=>'offering','entity_id'=>$o,'section'=>'DET-03','field'=>'tuition_items','item_key'=>'semester-1',
                    'value'=>['currency'=>'KRW','unit'=>'semester','amount'=>'4200000','label'=>'Synthetic tuition'],
                    'source_links'=>[['source_id'=>$source,'original_locator'=>'page 1']], 'expected_lock'=>0,
                ]),'field draft');
                $detail=self::assert_ok(self::call('/kgp/v1/offerings/'.$prefix.'-off'),'draft-only detail');
                if ($detail['tuition']) throw new RuntimeException('Unpublished tuition leaked.');
                self::assert_ok(self::call('/kgp/v1/admin/fields/'.$draft['entry_id'].'/submit','POST',['expected_lock'=>$draft['lock_version']]),'field submit');
                self::assert_ok(self::call('/kgp/v1/admin/fields/'.$draft['entry_id'].'/publish','POST',[]),'field publication');
                $search=self::assert_ok(self::call('/kgp/v1/catalog?q=QA&level=bachelor&cost_unit=semester&tuition_min=4000000&tuition_max=4300000&sort=tuition'),'tuition filter');
                if (!in_array($prefix.'-off',array_column($search['items'],'offering_id'),true)) throw new RuntimeException('Verified tuition not searchable.');
                $wrong=self::assert_ok(self::call('/kgp/v1/catalog?q=QA&region=busan&field=engineering'),'wrong filter');
                if (in_array($prefix.'-off',array_column($wrong['items'],'offering_id'),true)) throw new RuntimeException('AND filter matched wrong region.');
                $detail=self::assert_ok(self::call('/kgp/v1/offerings/'.$prefix.'-off'),'offering detail');
                $hasSource=false;
                foreach ($detail['sections'] as $section) foreach ($section['groups'] as $group) foreach ($group['fields'] as $field) {
                    if ($field['field']==='tuition_items' && $field['source_documents']) $hasSource=true;
                }
                if (!$hasSource) throw new RuntimeException('Source provenance missing from detail.');
                $steps[]='Approved tuition searchable; source provenance preserved';

                $original=get_current_user_id();
                wp_set_current_user(0);
                try {
                    $unauthorized=self::call('/kgp/v1/admin/entities/university','POST',['public_id'=>$prefix.'-attack']);
                    if (!$unauthorized instanceof WP_Error && $unauthorized->get_status()<400) throw new RuntimeException('Admin API accessible without authentication.');
                    $public=self::assert_ok(self::call('/kgp/v1/catalog?q=QA&region=seoul'),'anonymous public lookup');
                    if (!in_array($prefix.'-off',array_column($public['items'],'offering_id'),true)) throw new RuntimeException('Anonymous public lookup denied.');
                } finally {
                    wp_set_current_user($original);
                }
                $steps[]='Anonymous reads allowed; anonymous writes denied';

                self::assert_ok(self::call('/kgp/v1/admin/identities/university/'.$u.'/unpublish','POST',['reason'=>'Synthetic test']),'identity unpublish');
                $after=self::assert_ok(self::call('/kgp/v1/catalog?q=QA&region=seoul'),'after unpublish');
                if (in_array($prefix.'-off',array_column($after['items'],'offering_id'),true)) throw new RuntimeException('Unpublished identity remained searchable.');
                $steps[]='Identity unpublish immediately hides entire offering';
                throw new KGP_Api_Test_Rollback('ROLLBACK_TEST_ONLY');
            });
        } catch(KGP_Api_Test_Rollback $e) {
            // Transaction deliberately rolled back, leaving the production data untouched.
        } catch(Throwable $e) {
            WP_CLI::error('API smoke failed and rolled back: '.$e->getMessage());
        }
        foreach($steps as $step) WP_CLI::log('PASS: '.$step);
        WP_CLI::success('API integration smoke passed; synthetic data rolled back.');
    }
}
final class KGP_Api_Test_Rollback extends RuntimeException {}
