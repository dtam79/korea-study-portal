<?php
defined('ABSPATH') || exit;

/** Field-version-bound language drafts. Shared monetary/date/ID data is immutable. */
final class KGP_Operator_Translations {
    private const LOCALE = 'en';
    private const TEXT_KEYS = ['name','title','description','benefit','details','guidance','notes','address','department',
        'condition','method','applicability','label','organization','target_population','instructions','explanation',
        'requirements','document_name','translation','content'];
    private const STRUCTURED_TYPES = ['official_address','official_contact','document','scholarship','structured_schedule',
        'language_requirement','waiver','official_guidance','official_program','official_case','official_major',
        'official_evaluation','document_authentication','certification','restriction','published_statistic'];
    private static function type(string $type): string {return str_starts_with($type,'repeat:') ? substr($type,7) : $type;}
    private static function source(int $version_id): array {
        $v=KGP_DB::require_row('field_versions',$version_id);
        $e=KGP_DB::require_row('field_entries',(int)$v['field_entry_id']);
        KGP_Entity_Repository::get($e['entity_type'],(int)$e['entity_id']);
        if (!in_array(self::type((string)$v['value_type']),array_merge(['localized_text'],self::STRUCTURED_TYPES),true)) {
            throw new InvalidArgumentException('This field type shares its value across languages; it cannot be independently translated.');
        }
        if ($v['datum_status']!=='available') throw new InvalidArgumentException('Only available source content may be translated.');
        $original=json_decode((string)$v['value_json'],true,512,JSON_THROW_ON_ERROR);
        return [$v,$e,$original];
    }
    public static function candidates(int $entry_id): array {
        $entry=KGP_DB::require_row('field_entries',$entry_id);
        KGP_Entity_Repository::get((string)$entry['entity_type'],(int)$entry['entity_id']);
        global $wpdb;
        $v=KGP_DB::table('field_versions');
        $versions=$wpdb->get_results($wpdb->prepare("SELECT id,version_number,value_type,value_json,review_status FROM $v WHERE field_entry_id=%d ORDER BY version_number DESC LIMIT 30",$entry_id),ARRAY_A);
        return ['entry'=>$entry,'versions'=>$versions?:[]];
    }
    private static function check_text(string $v): string {
        if (trim($v)==='' || (function_exists('mb_strlen')?mb_strlen($v):strlen($v))>15000 || preg_match('/<\/?[a-z][^>]*>/i',$v)) {
            throw new InvalidArgumentException('Translation requires nonempty plain text without HTML.');
        }
        return trim($v);
    }
    private static function check_shape($original,$translation,string $parent='',int $depth=0) {
        if ($depth>8) throw new InvalidArgumentException('Translation nesting is too deep.');
        if (is_array($original)) {
            if (!is_array($translation) || array_keys($original)!==array_keys($translation)) throw new InvalidArgumentException('Translation must preserve original object/array keys and ordering.');
            foreach ($original as $key=>$value) {
                $translation[$key]=self::check_shape($value,$translation[$key],is_string($key)?$key:$parent,$depth+1);
            }
            return $translation;
        }
        if (is_string($original) && in_array($parent,self::TEXT_KEYS,true)) {
            if (!is_string($translation)) throw new InvalidArgumentException('Translated description must be text.');
            return self::check_text($translation);
        }
        if ($original!==$translation) throw new InvalidArgumentException('Cannot translate or change shared numeric, URL, date, schedule, currency, status, reference, or identifier fields: '.$parent);
        return $original;
    }
    private static function validate(string $type,$original,$translation) {
        if (self::type($type)==='localized_text') {
            if (!is_string($translation)) throw new InvalidArgumentException('Localized text must be a string.');
            return self::check_text($translation);
        }
        return self::check_shape($original,$translation);
    }
    public static function get(int $version_id): array {
        self::source($version_id);
        global $wpdb; $t=KGP_Operator_Workflow_Schema::table('translation_drafts');
        $draft=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE field_version_id=%d AND locale=%s",$version_id,self::LOCALE),ARRAY_A);
        $v=KGP_DB::require_row('field_versions',$version_id);
        return ['draft'=>$draft,'source_version_id'=>$version_id,'source_value'=>json_decode($v['value_json'],true),
            'source_value_type'=>$v['value_type']];
    }
    public static function save(int $version_id,$value,int $expected_revision): array {
        KGP_Access::require_cap('kgp_edit_entries');
        [$version,$entry,$original]=self::source($version_id);
        KGP_Access::require_edit_for(KGP_Entity_Repository::university_id($entry['entity_type'],(int)$entry['entity_id']));
        $translated=self::validate((string)$version['value_type'],$original,$value);
        return KGP_DB::transaction(function()use($version_id,$entry,$translated,$expected_revision){
            global $wpdb;
            $t=KGP_Operator_Workflow_Schema::table('translation_drafts');
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE field_version_id=%d AND locale=%s FOR UPDATE",$version_id,self::LOCALE),ARRAY_A);
            if (($old?(int)$old['revision']:0)!==$expected_revision) throw new KGP_Domain_Exception('version_conflict','Reload the current translation draft before saving.');
            if ($old && $old['status']==='pending') throw new InvalidArgumentException('A submitted translation cannot be edited. Review it first.');
            $data=['translated_json'=>wp_json_encode($translated,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                'status'=>'draft','revision'=>$expected_revision+1,'updated_at'=>KGP_DB::now(),'reviewed_by'=>null,
                'reviewed_at'=>null,'rejection_reason'=>null];
            if ($old) {
                $wpdb->update($t,$data,['id'=>(int)$old['id']]);$id=(int)$old['id'];
            } else {
                $data+=['field_version_id'=>$version_id,'locale'=>self::LOCALE,'created_by'=>get_current_user_id(),
                    'created_at'=>KGP_DB::now()];
                $wpdb->insert($t,$data);$id=(int)$wpdb->insert_id;
            }
            if ($wpdb->last_error) throw new RuntimeException('Translation draft save failed.');
            KGP_DB::audit('translation.draft_saved',$entry['entity_type'],(int)$entry['entity_id'],
                $old?['status'=>$old['status'],'translated_json'=>$old['translated_json']]:null,
                ['translation_id'=>$id,'field_version_id'=>$version_id,'locale'=>self::LOCALE,'value'=>$translated]);
            return ['id'=>$id,'status'=>'draft','revision'=>$expected_revision+1];
        });
    }
    public static function submit(int $id,int $revision): array {
        KGP_Access::require_cap('kgp_edit_entries');
        return self::transition($id,$revision,'pending');
    }
    private static function transition(int $id,int $revision,string $status,string $reason=''): array {
        return KGP_DB::transaction(function()use($id,$revision,$status,$reason){
            $t=KGP_Operator_Workflow_Schema::table('translation_drafts');
            global $wpdb;
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d FOR UPDATE",$id),ARRAY_A);
            if (!$r) throw new InvalidArgumentException('Translation draft not found.');
            [$v,$e,$original]=self::source((int)$r['field_version_id']);
            if ((int)$r['revision']!==$revision) throw new KGP_Domain_Exception('version_conflict','Translation draft changed.');
            if ($status==='pending') {
                KGP_Access::require_edit_for(KGP_Entity_Repository::university_id($e['entity_type'],(int)$e['entity_id']));
                if (!in_array($r['status'],['draft','rejected'],true)) throw new InvalidArgumentException('Only drafts can be submitted.');
                $changes=['status'=>'pending','submitted_by'=>get_current_user_id(),'submitted_at'=>KGP_DB::now(),
                    'rejection_reason'=>null,'revision'=>$revision+1,'updated_at'=>KGP_DB::now()];
            } else {
                KGP_Access::require_cap('kgp_review_entries');
                if ($r['status']!=='pending') throw new InvalidArgumentException('Only pending translations can be reviewed.');
                if ((int)$r['created_by']===get_current_user_id() || (int)$r['submitted_by']===get_current_user_id()) {
                    throw new KGP_Domain_Exception('forbidden','Translator cannot approve or reject own submission.');
                }
                $changes=['status'=>$status,'reviewed_by'=>get_current_user_id(),'reviewed_at'=>KGP_DB::now(),
                    'rejection_reason'=>$reason?:null,'revision'=>$revision+1,'updated_at'=>KGP_DB::now()];
                if ($status==='approved') {
                    if ((int)$e['published_version_id']!==(int)$v['id'] || $v['review_status']!=='approved') throw new InvalidArgumentException('Source version is not the currently published version.');
                    if (!KGP_Evidence_Repository::verified_sources_for_version((int)$v['id'])) throw new InvalidArgumentException('Original evidence is no longer verified/current.');
                    $translated=json_decode($r['translated_json'],true,512,JSON_THROW_ON_ERROR);
                    self::validate($v['value_type'],$original,$translated);
                    self::sync_to_core((int)$v['id'],self::LOCALE,$r['translated_json'],(int)$r['created_by']);
                }
            }
            if ($wpdb->update($t,$changes,['id'=>$id])===false) throw new RuntimeException('Translation state update failed.');
            KGP_DB::audit('translation.'.$status,$e['entity_type'],(int)$e['entity_id'],
                ['translation_id'=>$id,'status'=>$r['status']],['translation_id'=>$id,'status'=>$status]);
            return ['id'=>$id,'status'=>$status,'revision'=>$revision+1];
        });
    }
    /** Write only approved translation to the table consumed by KGP_Public_Reader. */
    private static function sync_to_core(int $version_id,string $locale,string $encoded,int $translator_id): void {
        global $wpdb; $t=KGP_DB::table('translations');
        $columns=$wpdb->get_results("SHOW COLUMNS FROM $t",ARRAY_A);
        $names=array_column($columns?:[],'Field');
        foreach (['field_version_id','locale','translated_json','status'] as $name) {
            if (!in_array($name,$names,true)) throw new RuntimeException('Core translation storage schema is incompatible: '.$name);
        }
        $data=['field_version_id'=>$version_id,'locale'=>$locale,'translated_json'=>$encoded,'status'=>'approved'];
        $known=['created_at'=>KGP_DB::now(),'updated_at'=>KGP_DB::now(),'reviewed_at'=>KGP_DB::now(),
            'approved_at'=>KGP_DB::now(),'reviewed_by'=>get_current_user_id(),'approved_by'=>get_current_user_id(),
            'created_by'=>$translator_id,'translator_id'=>$translator_id];
        foreach ($known as $k=>$v) if (in_array($k,$names,true)) $data[$k]=$v;
        $previous=$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE field_version_id=%d AND locale=%s LIMIT 1",$version_id,$locale));
        if ($previous) {
            // Existing creator attribution is immutable even when a translation is reapproved.
            unset($data['created_by']);
            $ok=$wpdb->update($t,$data,['id'=>(int)$previous]);
        } else $ok=$wpdb->insert($t,$data);
        if ($ok===false) throw new RuntimeException('Core translation approval sync failed: '.$wpdb->last_error);
    }
    public static function approve(int $id,int $revision): array {return self::transition($id,$revision,'approved');}
    public static function reject(int $id,int $revision,string $reason): array {
        if (trim($reason)==='') throw new InvalidArgumentException('Rejection reason is required.');
        return self::transition($id,$revision,'rejected',KGP_Validator::plain($reason,'Reason',2000));
    }
    public static function queue(int $page=1): array {
        KGP_Access::require_cap('kgp_review_entries');
        global $wpdb;$t=KGP_Operator_Workflow_Schema::table('translation_drafts');
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='pending'");
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status='pending' ORDER BY submitted_at ASC LIMIT 30 OFFSET %d",($page-1)*30),ARRAY_A);
        $rows = $rows ?: [];
        foreach ($rows as &$row) {[$v,$e,$original]=self::source((int)$row['field_version_id']);
            $row['entry']=['entity_type'=>$e['entity_type'],'entity_id'=>$e['entity_id'],'section_code'=>$e['section_code'],'field_key'=>$e['field_key']];
            $row['source_value']=$original;
            $links=KGP_DB::table('field_version_sources');$sources=KGP_DB::table('sources');
            $row['sources']=$wpdb->get_results($wpdb->prepare("SELECT s.document_title,s.public_url,s.checked_at,l.original_locator,l.applies_to FROM $links l INNER JOIN $sources s ON s.id=l.source_id WHERE l.field_version_id=%d",(int)$v['id']),ARRAY_A) ?: [];
        } unset($row);
        return ['items'=>$rows?:[],'total'=>$total,'page'=>$page,'per_page'=>30];
    }
}
