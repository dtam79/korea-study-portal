<?php

defined('ABSPATH') || exit;

/**
 * Official identity metadata is NOT public just because a private entity exists.
 * A verified source and a reviewer-signed, immutable snapshot are required.
 * Updates to the mutable entity do not silently rewrite the published snapshot.
 */
final class KGP_Identity_Repository {
    private const MAP = [
        'university' => 'universities', 'campus' => 'campuses',
        'program' => 'programs', 'intake' => 'intakes', 'offering' => 'offerings',
    ];
    private const FIELDS = [
        'university' => ['public_id','official_name_ko','official_name_en','official_website','institution_type','logo_attachment_id'],
        'campus' => ['public_id','university_id','official_name_ko','official_name_en','region_code','address_ko','address_en','is_primary'],
        'program' => ['public_id','university_id','campus_id','official_name_ko','official_name_en','field_code','level_code','language_code','department_url'],
        'intake' => ['public_id','university_id','academic_year','academic_term','admission_type','start_date','end_date','starts_at_utc','ends_at_utc','start_precision','end_precision','timezone_name','application_url','recruitment_override'],
        'offering' => ['public_id','program_id','intake_id','variant_code'],
    ];

    private static function assert_type(string $type): void {
        if (!isset(self::MAP[$type])) throw new KGP_Domain_Exception('bad_type','Unknown entity type.');
    }

    private static function permission(string $type, int $id): void {
        self::assert_type($type);
        if ($type === 'university') KGP_Access::require_cap('kgp_manage_assignments');
        else KGP_Access::require_edit_for(KGP_Entity_Repository::university_id($type, $id));
    }

    public static function record(string $type, int $id): ?array {
        self::assert_type($type);
        global $wpdb;
        $table = KGP_DB::table('entity_publications');
        return KGP_DB::one($wpdb->prepare("SELECT * FROM $table WHERE entity_type = %s AND entity_id = %d", $type, $id));
    }

    private static function capture(string $type, int $id, array $aliases): array {
        $row = KGP_DB::require_row(self::MAP[$type], $id);
        $snapshot = [];
        foreach (self::FIELDS[$type] as $field) {
            $snapshot[$field] = $row[$field] ?? null;
        }
        if ($type === 'university') {
            if (count($aliases) > 30) KGP_Validator::fail('Too many verified aliases (maximum 30).');
            $unique = [];
            foreach ($aliases as $alias) {
                $name = KGP_Validator::plain($alias, 'Approved alias', 255);
                if (!in_array($name, $unique, true)) $unique[] = $name;
            }
            $snapshot['approved_aliases'] = $unique;
        } elseif ($aliases) {
            KGP_Validator::fail('Aliases are supported only for university identity.');
        }
        return $snapshot;
    }

    /** Submit the current private metadata for evidence review; never publish it directly. */
    public static function submit(string $type, int $id, int $source_id, int $expected_lock, string $source_locator = '', array $aliases = []): array {
        self::permission($type, $id);
        $source = KGP_DB::require_row('sources', $source_id);
        if ($source_locator !== '') $source_locator = KGP_Validator::plain($source_locator, 'Source locator', 500);
        if (preg_match('/\.pdf$/i', (string)(wp_parse_url($source['public_url'], PHP_URL_PATH) ?: ''))
            && $source_locator === '' && !$source['document_locator']) {
            KGP_Validator::fail('PDF evidence needs a page or section locator.');
        }
        $snapshot = self::capture($type, $id, $aliases);
        $revision = KGP_Entity_Repository::revision($type, $id);
        return KGP_DB::transaction(function () use ($type, $id, $source_id, $expected_lock, $source_locator, $snapshot, $revision) {
            global $wpdb;
            $table = KGP_DB::table('entity_publications');
            $pub = KGP_DB::one($wpdb->prepare("SELECT * FROM $table WHERE entity_type=%s AND entity_id=%d FOR UPDATE", $type, $id));
            $current = $pub ? (int)$pub['lock_version'] : 0;
            if ($current !== $expected_lock) throw new KGP_Domain_Exception('version_conflict','Identity was changed. Reload before editing.');
            $data = [
                'draft_json'=>wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'draft_source_id'=>$source_id, 'draft_source_locator'=>$source_locator ?: null,
                'draft_revision'=>$revision, 'draft_status'=>'pending',
                'draft_by'=>get_current_user_id(), 'draft_at'=>KGP_DB::now(),
                'rejection_reason'=>null, 'lock_version'=>$current+1,
            ];
            if ($pub) KGP_DB::update('entity_publications',$data,['id'=>(int)$pub['id']]);
            else KGP_DB::insert('entity_publications',['entity_type'=>$type,'entity_id'=>$id]+$data);
            KGP_DB::audit('identity.submitted', $type, $id, null, ['source_id'=>$source_id,'identity_lock'=>$current+1]);
            return ['status'=>'pending','lock_version'=>$current+1,'entity_revision'=>$revision];
        });
    }

    public static function approve(string $type, int $id, int $expected_lock): array {
        KGP_Access::require_cap('kgp_review_entries');
        return KGP_DB::transaction(function () use ($type, $id, $expected_lock) {
            $pub = self::locked($type, $id);
            if ($pub['draft_status'] !== 'pending' || (int)$pub['lock_version'] !== $expected_lock) {
                throw new KGP_Domain_Exception('version_conflict','No matching submitted identity snapshot.');
            }
            if (KGP_Entity_Repository::revision($type,$id) !== (int)$pub['draft_revision']) {
                throw new KGP_Domain_Exception('version_conflict','Entity changed after identity was submitted. Submit again.');
            }
            if (!KGP_Evidence_Repository::valid_source((int)$pub['draft_source_id'])) {
                KGP_Validator::fail('Identity evidence must be approved, verified and current.');
            }
            KGP_DB::update('entity_publications', [
                'published_json'=>$pub['draft_json'], 'published_source_id'=>(int)$pub['draft_source_id'],
                'published_source_locator'=>$pub['draft_source_locator'],
                'published_by'=>get_current_user_id(), 'published_at'=>KGP_DB::now(),
                'draft_status'=>'none','draft_json'=>null,'draft_source_id'=>null,
                'draft_source_locator'=>null,'draft_revision'=>null,'lock_version'=>$expected_lock+1,
            ], ['id'=>(int)$pub['id']]);
            KGP_DB::audit('identity.published',$type,$id,
                ['published_json'=>$pub['published_json'],'published_source_id'=>$pub['published_source_id']],
                ['published_json'=>$pub['draft_json'],'published_source_id'=>(int)$pub['draft_source_id']]);
            do_action('kgp_identity_publication_changed',$type,$id);
            return ['status'=>'published','lock_version'=>$expected_lock+1];
        });
    }

    public static function reject(string $type, int $id, int $expected_lock, string $reason): array {
        KGP_Access::require_cap('kgp_review_entries');
        $reason = KGP_Validator::plain($reason, 'Rejection reason', 2000);
        return KGP_DB::transaction(function () use ($type,$id,$expected_lock,$reason) {
            $pub = self::locked($type,$id);
            if ($pub['draft_status'] !== 'pending' || (int)$pub['lock_version'] !== $expected_lock) {
                throw new KGP_Domain_Exception('version_conflict','No matching pending snapshot.');
            }
            KGP_DB::update('entity_publications', [
                'draft_status'=>'rejected','rejection_reason'=>$reason,'lock_version'=>$expected_lock+1,
            ], ['id'=>(int)$pub['id']]);
            KGP_DB::audit('identity.rejected',$type,$id,null,['reason'=>$reason]);
            return ['status'=>'rejected','lock_version'=>$expected_lock+1];
        });
    }

    public static function unpublish(string $type, int $id, string $reason): void {
        KGP_Access::require_cap('kgp_review_entries');
        $reason = KGP_Validator::plain($reason,'Unpublish reason',2000);
        KGP_DB::transaction(function () use ($type,$id,$reason) {
            $pub = self::locked($type,$id);
            KGP_DB::update('entity_publications', [
                'published_json'=>null,'published_source_id'=>null,'published_source_locator'=>null,
                'published_by'=>null,'published_at'=>null,'lock_version'=>(int)$pub['lock_version']+1,
            ], ['id'=>(int)$pub['id']]);
            KGP_DB::audit('identity.unpublished',$type,$id,
                ['published_json'=>$pub['published_json'],'published_source_id'=>$pub['published_source_id']],
                ['published_json'=>null,'reason'=>$reason]);
            do_action('kgp_identity_publication_changed',$type,$id);
        });
    }

    private static function locked(string $type, int $id): array {
        global $wpdb;
        self::assert_type($type);
        $table = KGP_DB::table('entity_publications');
        $pub = KGP_DB::one($wpdb->prepare("SELECT * FROM $table WHERE entity_type=%s AND entity_id=%d FOR UPDATE",$type,$id));
        if (!$pub) throw new KGP_Domain_Exception('not_found','Entity identity not submitted.');
        return $pub;
    }

    /** Returns no draft values or metadata. Public details are source-gated on every request. */
    public static function published(string $type, int $id): ?array {
        $pub = self::record($type,$id);
        if (!$pub || !$pub['published_json'] || !$pub['published_source_id']) return null;
        if (!KGP_Evidence_Repository::valid_source((int)$pub['published_source_id'])) return null;
        $data = json_decode((string)$pub['published_json'],true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) return null;
        $source = KGP_DB::require_row('sources',(int)$pub['published_source_id']);
        $data['identity_evidence'] = [
            'title'=>$source['document_title'], 'public_url'=>$source['public_url'],
            'locator'=>$pub['published_source_locator'] ?: $source['document_locator'],
            'checked_at'=>$source['checked_at'],
        ];
        return $data;
    }
}
