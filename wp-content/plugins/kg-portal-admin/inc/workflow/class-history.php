<?php
defined('ABSPATH') || exit;

/** Read-only audit timeline and safe restore into NEW drafts, never direct publication. */
final class KGP_Operator_History {
    private const TYPES=['university','campus','program','intake','offering'];
    private static function scope(string $type,int $id): void {
        if (!in_array($type,self::TYPES,true)) throw new InvalidArgumentException('Unsupported entity type.');
        KGP_Entity_Repository::get($type,$id);
    }
    public static function events(string $type,int $id,int $page=1): array {
        self::scope($type,$id);
        global $wpdb;$t=KGP_DB::table('audit_events');$page=max(1,$page);
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE entity_type=%s AND entity_id=%d",$type,$id));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,actor_id,event_type,before_json,after_json,details_json,recorded_at FROM $t WHERE entity_type=%s AND entity_id=%d ORDER BY id DESC LIMIT 30 OFFSET %d",$type,$id,($page-1)*30),ARRAY_A);
        $rows = $rows ?: [];
        foreach ($rows as &$row) {
            foreach(['before_json','after_json','details_json'] as $k) $row[$k]=json_decode($row[$k]??'null',true);
        } unset($row);
        return ['items'=>$rows?:[],'total'=>$total,'page'=>$page,'per_page'=>30];
    }
    public static function field_versions(int $entry_id): array {
        global $wpdb;
        $entry=KGP_DB::require_row('field_entries',$entry_id);
        self::scope($entry['entity_type'],(int)$entry['entity_id']);
        $v=KGP_DB::table('field_versions');$l=KGP_DB::table('field_version_sources');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $v WHERE field_entry_id=%d ORDER BY version_number DESC LIMIT 100",$entry_id),ARRAY_A);
        $rows = $rows ?: [];
        foreach($rows as &$row) {
            $row['decoded_value']=json_decode((string)$row['value_json'],true);
            $row['source_links']=$wpdb->get_results($wpdb->prepare("SELECT source_id,original_locator,applies_to,valid_from,valid_to FROM $l WHERE field_version_id=%d ORDER BY id",(int)$row['id']),ARRAY_A)?:[];
        } unset($row);
        return ['entry'=>$entry,'versions'=>$rows?:[]];
    }
    public static function compare(int $entry_id,int $old_version,int $new_version): array {
        $history=self::field_versions($entry_id);$byId=[];
        foreach ($history['versions'] as $r) $byId[(int)$r['id']]=$r;
        if (!isset($byId[$old_version],$byId[$new_version])) throw new InvalidArgumentException('Both versions must belong to the selected field entry.');
        $a=$byId[$old_version];$b=$byId[$new_version];
        return ['old'=>['id'=>$a['id'],'value'=>$a['decoded_value'],'sources'=>$a['source_links'],'status'=>$a['review_status']],
            'new'=>['id'=>$b['id'],'value'=>$b['decoded_value'],'sources'=>$b['source_links'],'status'=>$b['review_status']],
            'value_changed'=>$a['decoded_value']!==$b['decoded_value'],
            'sources_changed'=>$a['source_links']!==$b['source_links']];
    }
    public static function restore_field(int $entry_id,int $source_version_id,int $expected_lock): array {
        KGP_Access::require_cap('kgp_edit_entries');
        $history=self::field_versions($entry_id);
        $entry=$history['entry'];
        KGP_Access::require_edit_for(KGP_Entity_Repository::university_id($entry['entity_type'],(int)$entry['entity_id']));
        $source=null;
        foreach($history['versions'] as $v) if ((int)$v['id']===$source_version_id) {$source=$v;break;}
        if (!$source) throw new InvalidArgumentException('Selected version does not belong to this field.');
        if ((int)$entry['lock_version']!==$expected_lock) throw new KGP_Domain_Exception('version_conflict','Field version changed; reload.');
        if ($entry['draft_version_id']) {
            $current=KGP_DB::require_row('field_versions',(int)$entry['draft_version_id']);
            if ($current['review_status']==='pending') throw new InvalidArgumentException('Cannot replace a pending review. Reject/finish it first.');
        }
        $links=[];
        foreach($source['source_links'] as $link) {
            KGP_DB::require_row('sources',(int)$link['source_id']);
            $links[]=['source_id'=>(int)$link['source_id']]+array_filter([
                'original_locator'=>$link['original_locator'],'applies_to'=>$link['applies_to'],
                'valid_from'=>$link['valid_from'],'valid_to'=>$link['valid_to']],static fn($x)=>$x!==null && $x!=='');
        }
        $datum=$source['datum_status'];
        $value=$datum==='available'?$source['decoded_value']:null;
        // No approved value is changed; all proof links must be reviewed again.
        $draft=KGP_Field_Repository::save_draft($entry['entity_type'],(int)$entry['entity_id'],$entry['section_code'],
            $entry['field_key'],$value,$links,$expected_lock,(string)$entry['item_key'],$datum);
        KGP_DB::audit('field.restore_draft',$entry['entity_type'],(int)$entry['entity_id'],
            ['from_version_id'=>$source_version_id],['new_version_id'=>$draft['version_id'],'source_links'=>$links]);
        return $draft+['restored_from_version_id'=>$source_version_id,'status'=>'draft'];
    }
    private static function source_identity_audit(string $type,int $id,int $audit_id): array {
        global $wpdb;
        $a=KGP_DB::table('audit_events');
        $log=$wpdb->get_row($wpdb->prepare("SELECT * FROM $a WHERE id=%d AND entity_type=%s AND entity_id=%d AND event_type IN ('identity.published','identity.unpublished')",$audit_id,$type,$id),ARRAY_A);
        if (!$log) throw new InvalidArgumentException('Selected identity publication was not found.');
        $from=$log['event_type']==='identity.unpublished'?$log['before_json']:$log['after_json'];
        $raw=json_decode((string)$from,true,512,JSON_THROW_ON_ERROR);
        if (!isset($raw['published_json'],$raw['published_source_id'])) throw new InvalidArgumentException('Historical snapshot does not contain restoreable evidence.');
        $snapshot=json_decode((string)$raw['published_json'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) throw new InvalidArgumentException('Historical snapshot is invalid.');
        return ['json'=>$raw['published_json'],'value'=>$snapshot,'source_id'=>(int)$raw['published_source_id']];
    }
    /** Restores a frozen historical identity into a private draft, without changing any public snapshot. */
    public static function restore_identity(string $type,int $id,int $audit_id,int $expected_lock,string $locator=''): array {
        self::scope($type,$id);
        if ($type==='university') KGP_Access::require_cap('kgp_manage_assignments');
        else KGP_Access::require_edit_for(KGP_Entity_Repository::university_id($type,$id));
        $historic=self::source_identity_audit($type,$id,$audit_id);
        $now=KGP_Identity_Repository::record($type,$id);
        if (!$now || (int)$now['lock_version']!==$expected_lock) throw new KGP_Domain_Exception('version_conflict','Identity changed; reload.');
        if ($now['draft_status']==='pending') throw new InvalidArgumentException('Pending identity review must finish before restoring.');
        $table=match($type){'university'=>'universities','campus'=>'campuses','program'=>'programs','intake'=>'intakes','offering'=>'offerings'};
        $current=KGP_DB::require_row($table,$id);
        foreach(['public_id','university_id','campus_id','program_id','intake_id','academic_year'] as $immutable) {
            if (array_key_exists($immutable,$historic['value']) && (string)($historic['value'][$immutable]??'')!==(string)($current[$immutable]??'')) {
                throw new InvalidArgumentException('Historical relationship or ID is no longer valid: '.$immutable);
            }
        }
        KGP_DB::require_row('sources',$historic['source_id']);
        $locator=$locator!==''?KGP_Validator::plain($locator,'Original locator',500):null;
        return KGP_DB::transaction(function()use($type,$id,$audit_id,$expected_lock,$historic,$locator){
            global $wpdb;$table=KGP_DB::table('entity_publications');
            $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE entity_type=%s AND entity_id=%d FOR UPDATE",$type,$id),ARRAY_A);
            if (!$locked || (int)$locked['lock_version']!==$expected_lock || $locked['draft_status']==='pending') throw new KGP_Domain_Exception('version_conflict','Identity changed during restore.');
            $data=['draft_json'=>$historic['json'],'draft_source_id'=>$historic['source_id'],'draft_source_locator'=>$locator,
                'draft_revision'=>KGP_Entity_Repository::revision($type,$id),'draft_status'=>'draft',
                'draft_by'=>get_current_user_id(),'draft_at'=>KGP_DB::now(),'rejection_reason'=>null,
                'lock_version'=>$expected_lock+1];
            if (KGP_DB::update('entity_publications',$data,['entity_type'=>$type,'entity_id'=>$id,'lock_version'=>$expected_lock])!==1) throw new KGP_Domain_Exception('version_conflict','Identity changed during restore.');
            KGP_DB::audit('identity.restore_draft',$type,$id,null,['from_audit_id'=>$audit_id,'source_id'=>$historic['source_id']]);
            return ['status'=>'draft','lock_version'=>$expected_lock+1,'from_audit_id'=>$audit_id];
        });
    }
    public static function submit_restored_identity(string $type,int $id,int $expected_lock): array {
        self::scope($type,$id);
        if ($type==='university') KGP_Access::require_cap('kgp_manage_assignments');
        else KGP_Access::require_edit_for(KGP_Entity_Repository::university_id($type,$id));
        return KGP_DB::transaction(function()use($type,$id,$expected_lock){
            global $wpdb;$table=KGP_DB::table('entity_publications');
            $pub=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE entity_type=%s AND entity_id=%d FOR UPDATE",$type,$id),ARRAY_A);
            if (!$pub || $pub['draft_status']!=='draft' || (int)$pub['lock_version']!==$expected_lock) throw new KGP_Domain_Exception('version_conflict','Restored draft not found or changed.');
            if ((int)$pub['draft_revision']!==KGP_Entity_Repository::revision($type,$id)) throw new KGP_Domain_Exception('version_conflict','Underlying identity changed after restoration.');
            if (KGP_DB::update('entity_publications',['draft_status'=>'pending','lock_version'=>$expected_lock+1],['id'=>(int)$pub['id'],'lock_version'=>$expected_lock])!==1) throw new KGP_Domain_Exception('version_conflict','Restore submission changed.');
            KGP_DB::audit('identity.restore_submitted',$type,$id,null,['identity_id'=>$pub['id']]);
            return ['status'=>'pending','lock_version'=>$expected_lock+1];
        });
    }
}
