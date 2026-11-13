<?php

defined('ABSPATH') || exit;

/** Private read-only convenience API. All mutations reuse KGP Core's audited endpoints. */
final class KGP_Operator_REST {
    private const NS = 'kgp-operator/v1';
    private const TYPES = ['university','campus','program','intake','offering'];
    private const TABLES = ['university'=>'universities','campus'=>'campuses','program'=>'programs','intake'=>'intakes','offering'=>'offerings'];

    public static function register(): void {
        $common = static fn() => KGP_Operator_Admin::ready() && KGP_Operator_Admin::permitted();
        $review = static fn() => KGP_Operator_Admin::ready() && current_user_can('kgp_review_entries');
        $editor = static fn() => KGP_Operator_Admin::ready() && (current_user_can('kgp_edit_entries') || current_user_can('kgp_review_entries') || current_user_can('kgp_manage_assignments'));
        $routes = [
            ['/catalog', $common, [self::class,'catalog']],
            ['/entities', $editor, [self::class,'entities']],
            ['/queue', $review, [self::class,'queue']],
            ['/sources/(?P<id>\d+)', $common, [self::class,'source']],
            ['/versions/(?P<id>\d+)', $editor, [self::class,'versions']],
            ['/health', $common, [self::class,'health']],
        ];
        foreach ($routes as [$route,$permission,$callback]) {
            register_rest_route(self::NS, $route, [
                'methods'=>'GET',
                'permission_callback'=>$permission,
                'callback'=>static function (WP_REST_Request $request) use ($callback) {
                    try { return rest_ensure_response($callback($request)); }
                    catch (Throwable $e) {
                        $bad = $e instanceof InvalidArgumentException;
                        if (!$bad) error_log('[KGP Operator] Read-only API failed: ' . $e->getMessage());
                        return new WP_Error($bad?'kgp_invalid_request':'kgp_query_failed', $bad?$e->getMessage():'Unable to load operator data.', ['status'=>$bad?422:500]);
                    }
                },
            ]);
        }
    }
    private static function positive($value, string $key, int $max=1000000): int {
        $v = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>$max]]);
        if ($v === false) throw new InvalidArgumentException($key . ' must be a positive integer.');
        return (int)$v;
    }
    private static function page(WP_REST_Request $request): array {
        $page = self::positive($request->get_param('page') ?? 1,'page');
        $size = self::positive($request->get_param('per_page') ?? 20, 'per_page',100);
        return [$page,$size,($page-1)*$size];
    }
    private static function query_all(string $query): array {
        global $wpdb;
        $rows = $wpdb->get_results($query,ARRAY_A);
        if ($wpdb->last_error) throw new RuntimeException('Query failed.');
        return $rows ?: [];
    }
    private static function query_one(string $query): ?array {
        global $wpdb;
        $row = $wpdb->get_row($query,ARRAY_A);
        if ($wpdb->last_error) throw new RuntimeException('Query failed.');
        return $row ?: null;
    }
    private static function count(string $query): int {
        global $wpdb;
        $count = $wpdb->get_var($query);
        if ($wpdb->last_error) throw new RuntimeException('Count query failed.');
        return (int)$count;
    }
    private static function schema_ready(): void {
        $s=KGP_Schema::status();
        if (!$s['ok']) throw new RuntimeException('Database schema is incomplete.');
    }
    public static function health(WP_REST_Request $r): array {
        $state=KGP_Schema::status();
        return ['core_version'=>KGP_CORE_VERSION,'db_complete'=>(bool)$state['ok'],
            'schema_version'=>KGP_Schema::VERSION, 'table_count'=>count(KGP_Schema::names()),
            'plugin_version'=>KGP_ADMIN_VERSION];
    }
    public static function catalog(WP_REST_Request $r): array {
        return ['sections'=>KGP_Field_Catalog::sections(), 'entity_types'=>self::TYPES];
    }
    /** Scoped private entity search. No cross-university details leaked in totals. */
    public static function entities(WP_REST_Request $r): array {
        self::schema_ready();
        global $wpdb;
        $type=(string)($r->get_param('type') ?? 'university');
        if (!isset(self::TABLES[$type])) throw new InvalidArgumentException('Unsupported entity type.');
        [$page,$perPage,$offset]=self::page($r);
        $query=$r->get_param('q') ?? '';
        if (!is_string($query) || (function_exists('mb_strlen') ? mb_strlen($query) : strlen($query))>120) throw new InvalidArgumentException('Search query is too long.');
        $t=KGP_DB::table(self::TABLES[$type]);
        $where=[];
        $search=trim($query);
        if ($search!=='') {
            $pattern='%'.$wpdb->esc_like($search).'%';
            $terms=['e.public_id LIKE %s'];
            if (in_array($type,['university','campus','program'],true)) {
                $terms[]='e.official_name_ko LIKE %s';
                $terms[]='e.official_name_en LIKE %s';
            } elseif ($type==='intake') {
                $terms[]='e.academic_term LIKE %s';
                $terms[]='CAST(e.academic_year AS CHAR) LIKE %s';
            } else $terms[]='e.variant_code LIKE %s';
            $where[] = '(' . $wpdb->prepare(implode(' OR ',$terms),...array_fill(0,count($terms),$pattern)) . ')';
        }
        if (!current_user_can('kgp_review_entries') && !current_user_can('kgp_manage_assignments')) {
            $a=KGP_DB::table('user_assignments');
            $column = match ($type) {
                'university'=>'e.id', 'offering'=>null, default=>'e.university_id',
            };
            if ($type==='offering') {
                $p=KGP_DB::table('programs');
                $where[]=$wpdb->prepare("EXISTS (SELECT 1 FROM $p p JOIN $a a ON a.university_id = p.university_id WHERE p.id = e.program_id AND a.user_id = %d AND a.role_code = 'editor')",get_current_user_id());
            } else {
                $where[]=$wpdb->prepare("EXISTS (SELECT 1 FROM $a a WHERE a.university_id = $column AND a.user_id = %d AND a.role_code = 'editor')",get_current_user_id());
            }
        }
        $condition=$where?' WHERE '.implode(' AND ',$where):'';
        $count=self::count("SELECT COUNT(*) FROM $t e $condition");
        $records=self::query_all($wpdb->prepare("SELECT e.* FROM $t e $condition ORDER BY e.id DESC LIMIT %d OFFSET %d",$perPage,$offset));
        return ['items'=>$records,'total'=>$count,'page'=>$page,'per_page'=>$perPage,'total_pages'=>(int)ceil($count/$perPage),'type'=>$type];
    }
    private static function source_row(int $id): ?array {
        global $wpdb;
        $s=KGP_DB::table('sources');$i=KGP_DB::table('issuers');
        return self::query_one($wpdb->prepare("SELECT s.*, i.official_name AS issuer_name, i.approval_status AS issuer_status FROM $s s LEFT JOIN $i i ON i.id=s.issuer_id WHERE s.id=%d",$id));
    }
    public static function source(WP_REST_Request $r): array {
        $id=self::positive($r->get_param('id'),'id');
        $s=self::source_row($id);
        if (!$s) throw new InvalidArgumentException('Source not found.');
        $s['currently_valid']=KGP_Evidence_Repository::valid_source($id);
        return $s;
    }
    private static function source_links(int $version_id): array {
        global $wpdb;
        $l=KGP_DB::table('field_version_sources');
        $rows=self::query_all($wpdb->prepare("SELECT * FROM $l WHERE field_version_id=%d ORDER BY id",$version_id));
        foreach ($rows as &$link) {
            $src=self::source_row((int)$link['source_id']);
            $link['source']=$src ? [
                'id'=>$src['id'],'document_title'=>$src['document_title'],'public_url'=>$src['public_url'],
                'evidence_status'=>$src['evidence_status'],'issuer_name'=>$src['issuer_name'],
                'issuer_status'=>$src['issuer_status'],'checked_at'=>$src['checked_at'],
                'currently_valid'=>KGP_Evidence_Repository::valid_source((int)$link['source_id']),
            ] : null;
        }
        unset($link);
        return $rows;
    }
    public static function versions(WP_REST_Request $r): array {
        global $wpdb;
        $id=self::positive($r->get_param('id'),'id');
        $entry=KGP_DB::require_row('field_entries',$id);
        // Same scope gate as KGP Core private editor reads.
        KGP_Entity_Repository::get((string)$entry['entity_type'],(int)$entry['entity_id']);
        $v=KGP_DB::table('field_versions');
        $history=self::query_all($wpdb->prepare("SELECT * FROM $v WHERE field_entry_id=%d ORDER BY version_number DESC LIMIT 100",$id));
        foreach ($history as &$row) $row['source_links']=self::source_links((int)$row['id']);
        unset($row);
        return ['entry'=>$entry,'versions'=>$history];
    }
    /** Reviewer-only queue, showing submitted snapshots and exact evidence, not just counts. */
    public static function queue(WP_REST_Request $r): array {
        self::schema_ready();
        global $wpdb;
        $kind=(string)($r->get_param('kind')??'field');
        if (!in_array($kind,['field','identity'],true)) throw new InvalidArgumentException('kind must be field or identity.');
        [$page,$perPage,$offset]=self::page($r);
        if ($kind==='identity') {
            $table=KGP_DB::table('entity_publications');
            $total=self::count("SELECT COUNT(*) FROM $table WHERE draft_status='pending'");
            $rows=self::query_all($wpdb->prepare("SELECT * FROM $table WHERE draft_status='pending' ORDER BY draft_at ASC,id ASC LIMIT %d OFFSET %d",$perPage,$offset));
            foreach ($rows as &$row) {
                $row['draft_value']=json_decode((string)$row['draft_json'],true);
                unset($row['published_json']);
                $s=$row['draft_source_id'] ? self::source_row((int)$row['draft_source_id']) : null;
                $row['source']=$s;
                $row['source_valid']=$s ? KGP_Evidence_Repository::valid_source((int)$row['draft_source_id']) : false;
            }
            unset($row);
        } else {
            $entries=KGP_DB::table('field_entries');$versions=KGP_DB::table('field_versions');
            $base="FROM $entries e INNER JOIN $versions v ON v.id=e.draft_version_id WHERE v.review_status='pending'";
            $total=self::count("SELECT COUNT(*) $base");
            $rows=self::query_all($wpdb->prepare("SELECT e.id AS entry_id, e.entity_type,e.entity_id,e.section_code,e.field_key,e.item_key,e.lock_version,v.id AS version_id,v.version_number,v.value_json,v.value_type,v.datum_status,v.created_by,v.created_at $base ORDER BY v.created_at ASC, e.id ASC LIMIT %d OFFSET %d",$perPage,$offset));
            foreach ($rows as &$row) {
                $row['value']=json_decode((string)$row['value_json'],true);
                unset($row['value_json']);
                $row['source_links']=self::source_links((int)$row['version_id']);
            }
            unset($row);
        }
        return ['items'=>$rows,'kind'=>$kind,'page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>(int)ceil($total/$perPage)];
    }
}
