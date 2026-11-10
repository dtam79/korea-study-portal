<?php

defined('ABSPATH') || exit;

/** Authenticated admin endpoints and read-only, evidence-gated public endpoints. */
final class KGP_REST_Routes {
    private const NS = 'kgp/v1';
    private const ENTITY_PATTERN = '(?P<type>university|campus|program|intake|offering)';

    public static function boot(): void {
        add_action('rest_api_init',[self::class,'register']);
    }

    private static function r(string $route, string $methods, callable $callback, bool $admin = false): void {
        register_rest_route(self::NS,$route,[
            'methods'=>$methods,
            'callback'=>static function (WP_REST_Request $request) use ($callback) {
                try {
                    $result=$callback($request);
                    return rest_ensure_response($result);
                } catch (KGP_Domain_Exception $e) {
                    $slug=$e->slug();
                    $status=match($slug){'forbidden'=>403,'not_found'=>404,'version_conflict','duplicate_id'=>409,
                        'bad_table','database_error'=>500,default=>422};
                    return new WP_Error('kgp_'.$slug,$status>=500?'Internal server error.':$e->getMessage(),['status'=>$status]);
                } catch (Throwable $e) {
                    error_log('KGP REST failure: '. $e->getMessage());
                    return new WP_Error('kgp_internal_error','Internal server error.',['status'=>500]);
                }
            },
            'permission_callback'=>$admin?static function () {
                return is_user_logged_in() && (
                    current_user_can('kgp_edit_entries') || current_user_can('kgp_review_entries') ||
                    current_user_can('kgp_verify_sources') || current_user_can('kgp_manage_issuers') ||
                    current_user_can('kgp_manage_assignments')
                );
            }:'__return_true',
        ]);
    }

    private static function body(WP_REST_Request $req): array {
        $data=$req->get_json_params();
        if ($data===null) $data=$req->get_body_params();
        if (!is_array($data) || array_is_list($data)) KGP_Validator::fail('Request JSON body must be an object.');
        return $data;
    }
    private static function required_int(array $obj,string $key,bool $zero=false): int {
        if (!array_key_exists($key,$obj) || filter_var($obj[$key],FILTER_VALIDATE_INT)===false || (int)$obj[$key]<($zero?0:1)) {
            KGP_Validator::fail($key.' must be a '.($zero?'nonnegative':'positive').' integer.');
        }
        return (int)$obj[$key];
    }
    private static function input_string(array $obj,string $key): string {
        if (!isset($obj[$key]) || !is_string($obj[$key])) KGP_Validator::fail('Missing or invalid '.$key);
        return $obj[$key];
    }
    private static function read_permission(string $type,int $id): void {
        KGP_Entity_Repository::get($type,$id); // private Repository enforces assignment/reviewer cap
    }

    public static function register(): void {
        // PUBLIC: no raw metadata or unapproved field versions can be read.
        self::r('/catalog','GET',static fn($r)=>KGP_Public_Catalog::search($r->get_params()));
        self::r('/admissions','GET',static fn($r)=>KGP_Public_Catalog::admissions_list($r->get_params()));
        self::r('/universities/(?P<public_id>[A-Za-z0-9_-]{2,64})','GET',static function ($r) {
            $u=KGP_Public_Catalog::university((string)$r['public_id'],(string)($r['locale']??'ko'));
            if (!$u) throw new KGP_Domain_Exception('not_found','Published university not found.');
            return $u;
        });
        self::r('/offerings/(?P<public_id>[A-Za-z0-9_-]{2,64})','GET',static function ($r) {
            $o=KGP_Public_Catalog::offering((string)$r['public_id'],(string)($r['locale']??'ko'));
            if (!$o) throw new KGP_Domain_Exception('not_found','Published offering not found.');
            return $o;
        });

        // ADMIN: everything below is private and requires site authentication.
        self::r('/admin/summary','GET',static fn()=>self::summary(),true);
        self::r('/admin/entities/'.self::ENTITY_PATTERN,'GET',static fn($r)=>self::entity_list((string)$r['type'],$r),true);
        self::r('/admin/entities/'.self::ENTITY_PATTERN,'POST',static function ($r) {
            $id=KGP_Entity_Repository::create((string)$r['type'],self::body($r));
            return ['id'=>$id,'record'=>KGP_Entity_Repository::get((string)$r['type'],$id)];
        },true);
        self::r('/admin/entities/'.self::ENTITY_PATTERN.'/(?P<id>\d+)','GET',static fn($r)=>KGP_Entity_Repository::get((string)$r['type'],(int)$r['id']),true);
        self::r('/admin/entities/'.self::ENTITY_PATTERN.'/(?P<id>\d+)','PATCH',static function ($r) {
            $b=self::body($r);$rev=self::required_int($b,'expected_revision',true);
            $changes=$b['changes']??null;
            if (!is_array($changes) || array_is_list($changes)) KGP_Validator::fail('changes must be an object.');
            return KGP_Entity_Repository::update((string)$r['type'],(int)$r['id'],$changes,$rev);
        },true);

        self::r('/admin/identities/'.self::ENTITY_PATTERN.'/(?P<id>\d+)','GET',static function ($r) {
            $type=(string)$r['type'];$id=(int)$r['id'];
            self::read_permission($type,$id);
            return ['entity'=>KGP_Entity_Repository::get($type,$id),'review'=>KGP_Identity_Repository::record($type,$id)];
        },true);
        self::r('/admin/identities/'.self::ENTITY_PATTERN.'/(?P<id>\d+)/submit','POST',static function ($r) {
            $b=self::body($r);
            $aliases=$b['aliases']??[];
            if (!is_array($aliases) || !array_is_list($aliases)) KGP_Validator::fail('aliases must be an array.');
            return KGP_Identity_Repository::submit((string)$r['type'],(int)$r['id'],
                self::required_int($b,'source_id'), self::required_int($b,'expected_lock',true),
                (string)($b['source_locator']??''),$aliases);
        },true);
        self::r('/admin/identities/'.self::ENTITY_PATTERN.'/(?P<id>\d+)/approve','POST',static function ($r) {
            $b=self::body($r);
            return KGP_Identity_Repository::approve((string)$r['type'],(int)$r['id'],self::required_int($b,'expected_lock',true));
        },true);
        self::r('/admin/identities/'.self::ENTITY_PATTERN.'/(?P<id>\d+)/reject','POST',static function ($r) {
            $b=self::body($r);
            return KGP_Identity_Repository::reject((string)$r['type'],(int)$r['id'],self::required_int($b,'expected_lock',true),self::input_string($b,'reason'));
        },true);
        self::r('/admin/identities/'.self::ENTITY_PATTERN.'/(?P<id>\d+)/unpublish','POST',static function ($r) {
            $b=self::body($r);
            KGP_Identity_Repository::unpublish((string)$r['type'],(int)$r['id'],self::input_string($b,'reason'));
            return ['status'=>'unpublished'];
        },true);

        self::r('/admin/issuers','GET',static fn($r)=>self::source_list('issuers',$r),true);
        self::r('/admin/issuers','POST',static fn($r)=>['id'=>KGP_Evidence_Repository::create_issuer(self::body($r))],true);
        self::r('/admin/issuers/(?P<id>\d+)/approve','POST',static function ($r) {
            KGP_Evidence_Repository::approve_issuer((int)$r['id']);return ['status'=>'approved'];
        },true);
        self::r('/admin/sources','GET',static fn($r)=>self::source_list('sources',$r),true);
        self::r('/admin/sources','POST',static fn($r)=>['id'=>KGP_Evidence_Repository::create_source(self::body($r))],true);
        self::r('/admin/sources/(?P<id>\d+)/verify','POST',static function ($r) {
            KGP_Evidence_Repository::verify_source((int)$r['id']);return ['status'=>'verified'];
        },true);
        self::r('/admin/sources/(?P<id>\d+)/invalidate','POST',static function ($r) {
            $count=KGP_Evidence_Repository::invalidate_source((int)$r['id'],self::input_string(self::body($r),'reason'));
            return ['status'=>'invalid','unpublished_fields'=>$count];
        },true);
        self::r('/admin/assignments','POST',static function ($r) {
            $b=self::body($r);
            return ['id'=>KGP_Access::assign_editor(self::required_int($b,'user_id'),self::required_int($b,'university_id'))];
        },true);
        self::r('/admin/fields','GET',static fn($r)=>self::field_list($r),true);
        self::r('/admin/fields','POST',static function ($r) {
            $b=self::body($r);
            $links=$b['source_links']??[];
            if (!is_array($links) || !array_is_list($links)) KGP_Validator::fail('source_links must be an array.');
            return KGP_Field_Repository::save_draft(
                self::input_string($b,'entity_type'),self::required_int($b,'entity_id'),
                self::input_string($b,'section'),self::input_string($b,'field'),$b['value']??null,
                $links,array_key_exists('expected_lock',$b)?self::required_int($b,'expected_lock',true):null,
                (string)($b['item_key']??''),(string)($b['datum_status']??'available')
            );
        },true);
        self::r('/admin/fields/(?P<id>\d+)/submit','POST',static function ($r) {
            $b=self::body($r);KGP_Field_Repository::submit((int)$r['id'],self::required_int($b,'expected_lock',true));
            return ['status'=>'pending'];
        },true);
        self::r('/admin/fields/(?P<id>\d+)/publish','POST',static function ($r) {
            KGP_Field_Repository::publish((int)$r['id']);return ['status'=>'published'];
        },true);
        self::r('/admin/fields/(?P<id>\d+)/reject','POST',static function ($r) {
            KGP_Field_Repository::reject((int)$r['id'],self::input_string(self::body($r),'reason'));
            return ['status'=>'rejected'];
        },true);
        self::r('/admin/fields/(?P<id>\d+)/unpublish','POST',static function ($r) {
            KGP_Field_Repository::unpublish((int)$r['id'],self::input_string(self::body($r),'reason'));
            return ['status'=>'unpublished'];
        },true);
    }

    private static function entity_list(string $type,WP_REST_Request $request): array {
        $map=['university'=>'universities','campus'=>'campuses','program'=>'programs','intake'=>'intakes','offering'=>'offerings'];
        $table=KGP_DB::table($map[$type]);
        $page=max(1,min(100000,(int)($request['page']??1)));
        $size=max(1,min(100,(int)($request['per_page']??20)));
        global $wpdb;
        $all=KGP_DB::all("SELECT * FROM $table ORDER BY id DESC");
        $filtered=[];
        foreach ($all as $row) {
            try { self::read_permission($type,(int)$row['id']); }
            catch (KGP_Domain_Exception $e) { if ($e->slug()==='forbidden') continue; throw $e; }
            $filtered[]=$row;
        }
        $total=count($filtered);
        return ['items'=>array_slice($filtered,($page-1)*$size,$size),'page'=>$page,'per_page'=>$size,'total'=>$total];
    }

    private static function source_list(string $table, WP_REST_Request $r): array {
        if (!(current_user_can('kgp_edit_entries') || current_user_can('kgp_verify_sources') || current_user_can('kgp_manage_issuers'))) {
            throw new KGP_Domain_Exception('forbidden','Source access denied.');
        }
        $page=max(1,min(100000,(int)($r['page']??1)));$size=max(1,min(100,(int)($r['per_page']??20)));
        $t=KGP_DB::table($table);global $wpdb;
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
        return ['items'=>KGP_DB::all($wpdb->prepare("SELECT * FROM $t ORDER BY id DESC LIMIT %d OFFSET %d",$size,($page-1)*$size)),
            'page'=>$page,'total'=>$total,'per_page'=>$size];
    }

    private static function field_list(WP_REST_Request $r): array {
        $type=(string)($r['entity_type']??'');$id=(int)($r['entity_id']??0);
        self::read_permission($type,$id);
        global $wpdb;
        $t=KGP_DB::table('field_entries');$versions=KGP_DB::table('field_versions');
        $entries=KGP_DB::all($wpdb->prepare("SELECT * FROM $t WHERE entity_type=%s AND entity_id=%d ORDER BY section_code,field_key,item_key",$type,$id));
        foreach ($entries as &$entry) {
            $entry['versions']=[];
            foreach (['draft_version_id','published_version_id'] as $col) {
                $vid=(int)$entry[$col];
                $entry['versions'][$col]=$vid ? KGP_DB::one($wpdb->prepare("SELECT * FROM $versions WHERE id=%d",$vid)) : null;
            }
        }
        unset($entry);
        return ['items'=>$entries,'count'=>count($entries)];
    }

    private static function summary(): array {
        if (!(current_user_can('kgp_review_entries') || current_user_can('kgp_manage_assignments'))) {
            throw new KGP_Domain_Exception('forbidden','Summary requires review or portal management access.');
        }
        global $wpdb;
        $f=KGP_DB::table('field_versions');$e=KGP_DB::table('entity_publications');
        return ['pending_field_versions'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $f WHERE review_status='pending'"),
            'pending_identities'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $e WHERE draft_status='pending'")];
    }
}
