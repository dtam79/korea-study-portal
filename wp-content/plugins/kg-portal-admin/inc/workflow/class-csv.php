<?php
defined('ABSPATH') || exit;

/** CSV staging is non-public. All approved rows become private Core drafts or metadata. */
final class KGP_Operator_CSV {
    public const MAX_BYTES=2097152;
    public const MAX_ROWS=500;
    public const HEADERS=['entity_type','public_id','university_ref','campus_ref','program_ref','intake_ref','issuer_ref',
        'official_name_ko','official_name_en','official_website','institution_type','region_code','is_primary',
        'field_code','level_code','language_code','department_url','academic_year','academic_term','admission_type',
        'start_date','end_date','application_url','variant_code','issuer_type','official_name','official_domain',
        'document_title','public_url','document_locator','applicable_to','valid_from','valid_to',
        'target_type','target_public_id','section_code','field_key','item_key','value_json','source_ref','source_locator'];
    private const TYPES=['issuer','university','campus','program','intake','offering','source','field'];
    private const ORDER=['issuer'=>1,'university'=>2,'campus'=>3,'program'=>4,'intake'=>5,'offering'=>6,'source'=>7,'field'=>8];
    private const TABLES=['issuer'=>'issuers','university'=>'universities','campus'=>'campuses',
        'program'=>'programs','intake'=>'intakes','offering'=>'offerings','source'=>'sources'];
    private const REQUIRED=[
        'issuer'=>['official_name','issuer_type'],
        'university'=>['official_name_ko','official_name_en','official_website'],
        'campus'=>['university_ref','official_name_ko'],
        'program'=>['university_ref','campus_ref','official_name_ko','field_code','level_code'],
        'intake'=>['university_ref','academic_year','academic_term','admission_type'],
        'offering'=>['program_ref','intake_ref'],
        'source'=>['issuer_ref','document_title','public_url'],
        'field'=>['target_type','target_public_id','section_code','field_key','value_json','source_ref'],
    ];
    private const FIELDS=[
        'issuer'=>['official_name','issuer_type','official_domain'],
        'university'=>['official_name_ko','official_name_en','official_website','institution_type'],
        'campus'=>['official_name_ko','official_name_en','region_code','is_primary'],
        'program'=>['official_name_ko','official_name_en','field_code','level_code','language_code','department_url'],
        'intake'=>['academic_year','academic_term','admission_type','start_date','end_date','application_url'],
        'offering'=>['variant_code'],
        'source'=>['document_title','public_url','document_locator','applicable_to','valid_from','valid_to'],
    ];
    private static function field_identity(array $r): string {
        return implode('|',[$r['target_type'],$r['target_public_id'],$r['section_code'],$r['field_key'],$r['item_key']??'']);
    }
    /** Split a UTF-8 CSV file with the standard RFC4180 quoting rules. Does not write any database row. */
    public static function parse(string $contents): array {
        if (strlen($contents)>self::MAX_BYTES) throw new InvalidArgumentException('CSV exceeds 2 MiB.');
        $contents=preg_replace('/^\xEF\xBB\xBF/','',$contents);
        if (str_contains($contents,"\0") || !preg_match('//u',$contents)) throw new InvalidArgumentException('CSV must be valid UTF-8 text.');
        $stream=fopen('php://temp','w+');fwrite($stream,$contents);rewind($stream);
        $header=fgetcsv($stream,0,',','"','');
        if ($header===false || !$header) throw new InvalidArgumentException('CSV header is missing.');
        $header=array_map(static fn($v)=>trim((string)$v),$header);
        if (count($header)!==count(array_unique($header))) throw new InvalidArgumentException('Duplicate CSV column name.');
        if (array_diff(['entity_type','public_id'],$header)) throw new InvalidArgumentException('Required columns entity_type and public_id are missing.');
        if (array_diff($header,self::HEADERS)) throw new InvalidArgumentException('Unsupported column(s): '.implode(', ',array_diff($header,self::HEADERS)));
        $rows=[];$line=1;
        while (($cells=fgetcsv($stream,0,',','"',''))!==false) {
            $line++;
            if (count($cells)===1 && trim((string)$cells[0])==='') continue;
            if (count($rows)>=self::MAX_ROWS) throw new InvalidArgumentException('Maximum 500 nonempty CSV rows.');
            if (count($cells)!==count($header)) {
                $rows[]=['line'=>$line,'raw'=>null,'errors'=>['Expected '.count($header).' columns, received '.count($cells)]];
                continue;
            }
            $raw=array_fill_keys(self::HEADERS,'');
            foreach ($cells as $i=>$cell) {
                $text=trim((string)$cell);
                if (strlen($text)>15000) {
                    $rows[]=['line'=>$line,'raw'=>null,'errors'=>['One or more cells exceed 15000 bytes.']];
                    continue 2;
                }
                $raw[$header[$i]]=$text;
            }
            $rows[]=['line'=>$line,'raw'=>$raw,'errors'=>[]];
        }
        fclose($stream);
        if (!$rows) throw new InvalidArgumentException('CSV has no rows.');
        return $rows;
    }
    private static function lookup(string $type,string $public_id): ?array {
        if (!isset(self::TABLES[$type]) || $public_id==='') return null;
        global $wpdb;$t=KGP_DB::table(self::TABLES[$type]);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE public_id=%s LIMIT 1",$public_id),ARRAY_A) ?: null;
    }
    private static function field_entry(array $r): ?array {
        global $wpdb;
        $target=self::lookup($r['target_type']??'', $r['target_public_id']??'');
        if (!$target) return null;
        $t=KGP_DB::table('field_entries');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE entity_type=%s AND entity_id=%d AND section_code=%s AND field_key=%s AND item_key=%s",$r['target_type'],(int)$target['id'],$r['section_code'],$r['field_key'],$r['item_key']),ARRAY_A) ?: null;
    }
    private static function validate(array $row,array $known): array {
        $r=$row; $type=$r['entity_type']??'';
        if (!in_array($type,self::TYPES,true)) throw new InvalidArgumentException('Invalid entity_type: '.$type);
        if ($type==='field') {
            foreach (self::REQUIRED['field'] as $key) if (($r[$key]??'')==='') throw new InvalidArgumentException('Missing '.$key);
            if (!in_array($r['target_type'],['university','campus','program','intake','offering'],true)) throw new InvalidArgumentException('Invalid target_type.');
            $r['target_public_id']=KGP_Validator::id($r['target_public_id']);
            $r['value']=json_decode($r['value_json'],true,512,JSON_THROW_ON_ERROR);
            if ($r['value']===null) throw new InvalidArgumentException('A publishable field needs a value; use the manual unpublished/under_review workflow for null.');
            KGP_Validator::field($r['section_code'],$r['field_key'],$r['value'],$r['item_key']??'');
        } else {
            $r['public_id']=KGP_Validator::id($r['public_id']);
            foreach (self::REQUIRED[$type] as $key) if (($r[$key]??'')==='') throw new InvalidArgumentException('Missing '.$key);
            foreach (self::FIELDS[$type] as $key) {
                if (($r[$key]??'')==='' || $key==='value_json') continue;
                if (in_array($key,['official_website','department_url','application_url','public_url'],true)) KGP_Validator::url($r[$key],$key);
                elseif (in_array($key,['valid_from','valid_to','start_date','end_date'],true)) KGP_Validator::date($r[$key],$key);
                elseif ($key==='issuer_type' && !in_array($r[$key],['university','government','public_agency','accredited_evaluator','official_application_provider'],true)) throw new InvalidArgumentException('Unknown official issuer type.');
                elseif ($key==='institution_type' && !in_array($r[$key],['public','private','other'],true)) throw new InvalidArgumentException('Invalid institution type.');
                elseif ($key==='language_code') KGP_Validator::check('locale_code',$r[$key]);
                elseif ($key==='academic_year') {$v=filter_var($r[$key],FILTER_VALIDATE_INT);if($v===false)throw new InvalidArgumentException('Invalid academic_year.');KGP_Validator::check('year',(int)$v);}
                elseif ($key==='is_primary' && !in_array($r[$key],['0','1'],true)) throw new InvalidArgumentException('is_primary must be 0 or 1.');
                else KGP_Validator::plain($r[$key],$key,2000);
            }
        }
        $refs=[
            'campus'=>['university_ref'=>'university'],'program'=>['university_ref'=>'university','campus_ref'=>'campus'],
            'intake'=>['university_ref'=>'university'],'offering'=>['program_ref'=>'program','intake_ref'=>'intake'],
            'source'=>['issuer_ref'=>'issuer'],
            'field'=>['target_public_id'=>$r['target_type'],'source_ref'=>'source'],
        ];
        foreach ($refs[$type]??[] as $key=>$refType) {
            $ref=$r[$key]??'';
            if ($ref==='') throw new InvalidArgumentException('Missing '.$key);
            if (!self::lookup($refType,$ref) && !isset($known[$refType.'|'.$ref])) throw new InvalidArgumentException('Unknown '.$key.': '.$ref);
        }
        if ($type==='field') return $r;
        if (isset($r['valid_from'],$r['valid_to']) && $r['valid_from']!=='' && $r['valid_to']!=='' && $r['valid_from']>$r['valid_to']) throw new InvalidArgumentException('valid_from exceeds valid_to.');
        if (isset($r['start_date'],$r['end_date']) && $r['start_date']!=='' && $r['end_date']!=='' && $r['start_date']>$r['end_date']) throw new InvalidArgumentException('start_date exceeds end_date.');
        return $r;
    }
    private static function preview_row(array $r): array {
        $type=$r['entity_type'];
        if ($type==='field') {
            $target=self::lookup($r['target_type'],$r['target_public_id']);
            if ($target) KGP_Entity_Repository::get($r['target_type'],(int)$target['id']);
            $e=self::field_entry($r);
            return [$e?'update':'create',$e?:null];
        }
        $existing=self::lookup($type,$r['public_id']);
        if ($existing && in_array($type,['university','campus','program','intake','offering'],true)) KGP_Entity_Repository::get($type,(int)$existing['id']);
        if (!$existing) {
            if ($type==='university') KGP_Access::require_cap('kgp_manage_assignments');
            if ($type==='issuer') KGP_Access::require_cap('kgp_manage_issuers');
            return ['create',null];
        }
        if ($type==='source' && $r['issuer_ref']!=='' && self::lookup('issuer',$r['issuer_ref']) &&
            (int)$existing['issuer_id']!==(int)self::lookup('issuer',$r['issuer_ref'])['id']) {
            throw new InvalidArgumentException('Existing source belongs to a different issuer. Create a new source ID.');
        }
        if (in_array($type,['campus','program','intake','offering'],true)) {
            $refColumns=match($type){
                'campus'=>['university_ref'=>['university','university_id']],
                'program'=>['university_ref'=>['university','university_id'],'campus_ref'=>['campus','campus_id']],
                'intake'=>['university_ref'=>['university','university_id']],
                'offering'=>['program_ref'=>['program','program_id'],'intake_ref'=>['intake','intake_id']],
            };
            foreach ($refColumns as $key=>[$target,$column]) {
                $parent=self::lookup($target,$r[$key]);
                if ($parent && (int)$existing[$column] !== (int)$parent['id']) {
                    throw new InvalidArgumentException('CSV cannot change immutable relationship: '.$key);
                }
            }
        }
        if ($type==='issuer' || $type==='source') {
            foreach (self::FIELDS[$type] as $k) if (($r[$k]??'')!=='' && (string)($existing[$k]??'')!==$r[$k]) {
                return ['error',$existing]; // Official source is immutable after registration; new public ID required.
            }
        }
        $changed=false;
        foreach (self::FIELDS[$type] as $k) if (($r[$k]??'')!=='' && (string)($existing[$k]??'')!==$r[$k]) $changed=true;
        return [$changed?'update':'keep',$existing];
    }
    public static function preview(string $bytes,string $filename): array {
        KGP_Access::require_cap('kgp_edit_entries');
        $hash=hash('sha256',$bytes);$batchTable=KGP_Operator_Workflow_Schema::table('csv_batches');
        global $wpdb;
        $previous=$wpdb->get_row($wpdb->prepare("SELECT * FROM $batchTable WHERE file_hash=%s AND created_by=%d LIMIT 1",$hash,get_current_user_id()),ARRAY_A);
        if ($previous && (int)$previous['created_by']===get_current_user_id()) return self::get_batch((int)$previous['id']);
        $rows=self::parse($bytes);
        $known=[];
        foreach ($rows as $row) {
            if (!$row['raw']) continue;
            $r=$row['raw'];$type=$r['entity_type']??'';
            $key=$type==='field'?($r['target_type'].'|'.$r['target_public_id'].'|'.$r['section_code'].'|'.$r['field_key'].'|'.$r['item_key']):($type.'|'.$r['public_id']);
            if (isset($known[$key])) $known[$key]['duplicate']=true;
            else $known[$key]=['line'=>$row['line']];
        }
        $normalized=[];
        foreach ($rows as $row) {
            $r=$row['raw'];$errors=$row['errors'];$action='error';$before=null;
            if ($r) {
                try {
                    $type=$r['entity_type'];
                    $key=$type==='field'?self::field_identity($r):($type.'|'.$r['public_id']);
                    if (($known[$key]['line']??0)!==$row['line'] || isset($known[$key]['duplicate'])) throw new InvalidArgumentException('Duplicate entity key within this CSV.');
                    $r=self::validate($r,$known);
                    [$action,$before]=self::preview_row($r);
                    if ($action==='error') throw new InvalidArgumentException('Issuer or source identity already exists with different details; use a new public_id.');
                } catch(Throwable $e) {$errors[]=$e->getMessage();}
            }
            $normalized[]=['line'=>$row['line'],'entity_type'=>$r['entity_type']??'','public_id'=>$r['public_id']??'',
                'status'=>$errors?'error':'ready','action'=>$errors?'error':$action,'raw'=>$r,'before'=>$before,'errors'=>$errors];
        }
        // A dependent row may not rely on an invalid parent imported in the same file.
        $usable=[];
        foreach ($normalized as $row) {
            if ($row['status']==='ready') {
                $r=$row['raw'];$key=$r['entity_type']==='field'?self::field_identity($r):$r['entity_type'].'|'.$r['public_id'];
                $usable[$key]=true;
            }
        }
        foreach ($normalized as &$row) {
            if ($row['status']!=='ready') continue;
            $r=$row['raw'];$refs=match($r['entity_type']) {
                'campus'=>['university_ref'=>'university'],
                'program'=>['university_ref'=>'university','campus_ref'=>'campus'],
                'intake'=>['university_ref'=>'university'],
                'offering'=>['program_ref'=>'program','intake_ref'=>'intake'],
                'source'=>['issuer_ref'=>'issuer'],
                'field'=>['target_public_id'=>$r['target_type'],'source_ref'=>'source'],
                default=>[]
            };
            foreach ($refs as $key=>$parent) {
                if (!self::lookup($parent,$r[$key]??'') && !isset($usable[$parent.'|'.($r[$key]??'')])) {
                    $row['errors'][]='Parent has invalid CSV row: '.$parent.' '.($r[$key]??'');
                    $row['status']='error';$row['action']='error';break;
                }
            }
        } unset($row);
        return KGP_DB::transaction(function () use($hash,$filename,$normalized){
            $batch=['file_hash'=>$hash,'original_filename'=>sanitize_file_name($filename),'created_by'=>get_current_user_id(),
                'status'=>'preview','row_count'=>count($normalized),'created_at'=>KGP_DB::now()];
            global $wpdb;
            $ok=$wpdb->insert(KGP_Operator_Workflow_Schema::table('csv_batches'),$batch);
            if ($ok===false) throw new RuntimeException('Unable to store CSV preview batch.');
            $id=(int)$wpdb->insert_id;
            foreach ($normalized as $r) {
                $ok=$wpdb->insert(KGP_Operator_Workflow_Schema::table('csv_rows'),[
                    'batch_id'=>$id,'csv_line'=>$r['line'],'entity_type'=>substr((string)$r['entity_type'],0,32),'public_id'=>substr((string)$r['public_id'],0,64),
                    'status'=>$r['status'],'action_code'=>$r['action'],
                    'normalized_json'=>wp_json_encode($r['raw'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                    'before_json'=>$r['before']?wp_json_encode($r['before'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,
                    'error_json'=>wp_json_encode($r['errors'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                ]);
                if ($ok===false) throw new RuntimeException('Unable to store CSV preview row.');
            }
            KGP_DB::audit('csv.preview_created','import_batch',$id,null,['rows'=>count($normalized),'hash'=>$hash]);
            return self::get_batch($id);
        });
    }
    public static function get_batch(int $id): array {
        global $wpdb;
        $batchTable=KGP_Operator_Workflow_Schema::table('csv_batches');$rowTable=KGP_Operator_Workflow_Schema::table('csv_rows');
        $batch=$wpdb->get_row($wpdb->prepare("SELECT * FROM $batchTable WHERE id=%d",$id),ARRAY_A);
        if (!$batch) throw new InvalidArgumentException('CSV batch not found.');
        if (!current_user_can('kgp_review_entries') && (int)$batch['created_by']!==get_current_user_id()) throw new KGP_Domain_Exception('forbidden','Not your CSV import batch.');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $rowTable WHERE batch_id=%d ORDER BY csv_line",$id),ARRAY_A);
        $rows = $rows ?: [];
        foreach ($rows as &$row) {
            $row['normalized']=json_decode($row['normalized_json']??'null',true);
            $row['before']=json_decode($row['before_json']??'null',true);
            $row['errors']=json_decode($row['error_json']??'[]',true);
            unset($row['normalized_json'],$row['before_json'],$row['error_json']);
        } unset($row);
        return ['batch'=>$batch,'rows'=>$rows?:[]];
    }
    public static function batches(): array {
        KGP_Access::require_cap('kgp_edit_entries');
        global $wpdb;$t=KGP_Operator_Workflow_Schema::table('csv_batches');
        if (current_user_can('kgp_review_entries')) $rows=$wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 25",ARRAY_A);
        else $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE created_by=%d ORDER BY id DESC LIMIT 25",get_current_user_id()),ARRAY_A);
        return ['items'=>$rows?:[]];
    }
    private static function resolve(string $type,string $public_id): int {
        $row=self::lookup($type,$public_id);
        if (!$row) throw new InvalidArgumentException("Referenced $type $public_id is not registered or was not selected in this import.");
        return (int)$row['id'];
    }
    private static function data_for(array $r): array {
        $type=$r['entity_type'];$data=['public_id'=>$r['public_id']];
        foreach(self::FIELDS[$type]??[] as $key) if (($r[$key]??'')!=='') {
            $value=$r[$key];
            if ($key==='is_primary') $value=(int)$value;
            if ($key==='academic_year') $value=(int)$value;
            $data[$key]=$value;
        }
        if (in_array($type,['campus','program','intake'],true)) $data['university_id']=self::resolve('university',$r['university_ref']);
        if ($type==='program') $data['campus_id']=self::resolve('campus',$r['campus_ref']);
        if ($type==='offering') {$data['program_id']=self::resolve('program',$r['program_ref']);$data['intake_id']=self::resolve('intake',$r['intake_ref']);}
        if ($type==='source') $data['issuer_id']=self::resolve('issuer',$r['issuer_ref']);
        return $data;
    }
    private static function draft_has_source(int $version_id,int $source_id,string $locator=''): bool {
        global $wpdb; $t=KGP_DB::table('field_version_sources');
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE field_version_id=%d AND source_id=%d AND COALESCE(original_locator,'')=%s LIMIT 1",$version_id,$source_id,$locator));
    }
    private static function apply_one(array $r): array {
        $type=$r['entity_type'];
        if ($type==='field') {
            $targetId=self::resolve($r['target_type'],$r['target_public_id']);$sourceId=self::resolve('source',$r['source_ref']);
            $entry=self::field_entry($r);
            if ($entry && $entry['draft_version_id']) {
                $draft=KGP_DB::require_row('field_versions',(int)$entry['draft_version_id']);
                if ($draft['review_status']!=='draft') throw new InvalidArgumentException('Existing draft is pending review and cannot be replaced by CSV.');
                $previous=json_decode($draft['value_json'],true);
                if ($previous===$r['value'] && self::draft_has_source((int)$draft['id'],$sourceId,$r['source_locator']??'')) return ['action'=>'keep','id'=>(int)$entry['id']];
            }
            if ($entry && !$entry['draft_version_id'] && $entry['published_version_id']) {
                $published=KGP_DB::require_row('field_versions',(int)$entry['published_version_id']);
                if (json_decode($published['value_json'],true)===$r['value'] && self::draft_has_source((int)$published['id'],$sourceId,$r['source_locator']??'')) return ['action'=>'keep','id'=>(int)$entry['id']];
            }
            $links=[['source_id'=>$sourceId,'original_locator'=>$r['source_locator']?:null]];
            $draft=KGP_Field_Repository::save_draft($r['target_type'],$targetId,$r['section_code'],$r['field_key'],
                $r['value'],$links,$entry?(int)$entry['lock_version']:0,$r['item_key'],'available');
            return ['action'=>$entry?'update':'create','id'=>$draft['entry_id']];
        }
        $existing=self::lookup($type,$r['public_id']);
        $data=self::data_for($r);
        if ($existing) {
            if ($type==='issuer'||$type==='source') {
                [$status]=self::preview_row($r);
                if ($status==='error') throw new InvalidArgumentException('Cannot overwrite an existing issuer or official source.');
                return ['action'=>'keep','id'=>(int)$existing['id']];
            }
            $immutable=['public_id','university_id','campus_id','program_id','intake_id','academic_year'];
            foreach ($immutable as $key) if (isset($data[$key]) && (string)($existing[$key]??'')!==(string)$data[$key]) throw new InvalidArgumentException('Immutable relationship changed: '.$key);
            $changes=[];
            foreach ($data as $key=>$value) {
                if (in_array($key,$immutable,true)) continue;
                if ((string)($existing[$key]??'')!==(string)$value) $changes[$key]=$value;
            }
            if (!$changes) return ['action'=>'keep','id'=>(int)$existing['id']];
            KGP_Entity_Repository::update($type,(int)$existing['id'],$changes,KGP_Entity_Repository::revision($type,(int)$existing['id']));
            return ['action'=>'update','id'=>(int)$existing['id']];
        }
        $id=match($type) {
            'issuer'=>KGP_Evidence_Repository::create_issuer($data),
            'source'=>KGP_Evidence_Repository::create_source($data),
            default=>KGP_Entity_Repository::create($type,$data),
        };
        return ['action'=>'create','id'=>(int)$id];
    }
    /** Apply only chosen valid rows. Each row commits/rolls back atomically, with a report. */
    public static function apply(int $batch_id,array $ids): array {
        KGP_Access::require_cap('kgp_edit_entries');
        if (!$ids || count($ids)>self::MAX_ROWS) throw new InvalidArgumentException('Choose at least one row (up to 500).');
        $ids=array_values(array_unique(array_map('intval',$ids)));
        $batch=self::get_batch($batch_id);
        if ((int)$batch['batch']['created_by']!==get_current_user_id() && !current_user_can('manage_options')) throw new KGP_Domain_Exception('forbidden','Only batch author or site admin may apply this import.');
        $byId=[];foreach ($batch['rows'] as $r) $byId[(int)$r['id']]=$r;
        foreach($ids as $id) if ($id<1||!isset($byId[$id])||$byId[$id]['status']!=='ready') throw new InvalidArgumentException('Selected row is not ready or belongs to another batch: '.$id);
        usort($ids,static fn($a,$b)=>(self::ORDER[$byId[$a]['entity_type']]??99)<=>(self::ORDER[$byId[$b]['entity_type']]??99) ?: ($byId[$a]['csv_line']<=>$byId[$b]['csv_line']));
        global $wpdb;$t=KGP_Operator_Workflow_Schema::table('csv_rows');
        $result=['applied'=>0,'kept'=>0,'failed'=>0,'rows'=>[]];
        foreach($ids as $id) {
            try {
                $out=KGP_DB::transaction(function()use($id,$t){
                    global $wpdb;
                    $locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d FOR UPDATE",$id),ARRAY_A);
                    if (!$locked || $locked['status']!=='ready') throw new InvalidArgumentException('Row already applied or no longer ready.');
                    $r=json_decode($locked['normalized_json'],true,512,JSON_THROW_ON_ERROR);
                    // Rerun validation at commit time: references, permissions and data can change.
                    $r=self::validate($r,[]);
                    $done=self::apply_one($r);
                    $updated=$wpdb->update($t,['status'=>'applied','action_code'=>$done['action'],
                        'result_id'=>$done['id'],'applied_by'=>get_current_user_id(),'applied_at'=>KGP_DB::now()],['id'=>$id]);
                    if ($updated===false) throw new RuntimeException('Failed to save import result.');
                    KGP_DB::audit('csv.row_applied','import_row',$id,null,$done);
                    return $done;
                });
                if ($out['action']==='keep') ++$result['kept']; else ++$result['applied'];
                $result['rows'][]=['id'=>$id,'status'=>'applied']+$out;
            } catch(Throwable $e) {
                ++$result['failed'];$result['rows'][]=['id'=>$id,'status'=>'error','error'=>$e->getMessage()];
                $wpdb->update($t,['status'=>'error','error_json'=>wp_json_encode([$e->getMessage()])],['id'=>$id]);
            }
        }
        $b=KGP_Operator_Workflow_Schema::table('csv_batches');
        $wpdb->update($b,['status'=>$result['failed']?'partial':'applied','applied_at'=>KGP_DB::now()],['id'=>$batch_id]);
        return $result;
    }
}
