<?php
defined('ABSPATH') || exit;

/** Every workflow endpoint is PRIVATE. No public routes, no direct publishing bypass. */
final class KGP_Operator_Workflow_Routes {
    private const NS='kgp-operator/v1';
    private static function route(string $path,string $method,callable $handler,string $cap): void {
        register_rest_route(self::NS,$path,[
            'methods'=>$method,
            'permission_callback'=>static fn() => is_user_logged_in() && KGP_Operator_Admin::ready() && (
                $cap==='kgpa_read' ? (current_user_can('kgp_edit_entries') || current_user_can('kgp_review_entries') || current_user_can('kgp_manage_assignments')) :
                ($cap==='kgpa_edit_manage' ? (current_user_can('kgp_edit_entries') || current_user_can('kgp_manage_assignments')) : current_user_can($cap))
            ),
            'callback'=>static function(WP_REST_Request $r)use($handler){
                try {
                    if (get_option('kgpa_workflow_schema_version')!==KGP_Operator_Workflow_Schema::VERSION) {
                        return new WP_Error('kgpa_migration_required','Admin workflow storage must be migrated by a site administrator.',['status'=>503]);
                    }
                    return rest_ensure_response($handler($r));
                } catch(KGP_Domain_Exception $e) {
                    $status=match($e->slug()){'forbidden'=>403,'not_found'=>404,'version_conflict'=>409,default=>422};
                    return new WP_Error('kgpa_'.$e->slug(),$e->getMessage(),['status'=>$status]);
                } catch(InvalidArgumentException|JsonException $e) {
                    return new WP_Error('kgpa_validation_error',$e->getMessage(),['status'=>422]);
                } catch(Throwable $e) {
                    error_log('[KGP Workflow] '.$e->getMessage());
                    return new WP_Error('kgpa_internal','Workflow failed. Check server log and input.',['status'=>500]);
                }
            },
        ]);
    }
    private static function body(WP_REST_Request $r): array {
        $data=$r->get_json_params();
        if (!is_array($data)||array_is_list($data)) throw new InvalidArgumentException('Expected JSON object.');
        return $data;
    }
    private static function int($raw,string $name,bool $zero=false): int {
        $v=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>$zero?0:1,'max_range'=>2147483647]]);
        if ($v===false) throw new InvalidArgumentException($name.' must be an integer.');
        return (int)$v;
    }
    private static function string($raw,string $name,int $max=1000): string {
        if (!is_string($raw)||trim($raw)===''||(function_exists('mb_strlen')?mb_strlen($raw):strlen($raw))>$max) throw new InvalidArgumentException($name.' must be nonempty text.');
        return trim($raw);
    }
    public static function register(): void {
        self::route('/translations/entry/(?P<id>\d+)','GET',static fn($r)=>KGP_Operator_Translations::candidates(self::int($r['id'],'entry ID')),'kgpa_read');
        self::route('/translations/version/(?P<id>\d+)','GET',static fn($r)=>KGP_Operator_Translations::get(self::int($r['id'],'version ID')),'kgpa_read');
        self::route('/translations/version/(?P<id>\d+)/save','POST',static function($r){$b=self::body($r);
            if (!array_key_exists('translation',$b)) throw new InvalidArgumentException('Translation value missing.');
            return KGP_Operator_Translations::save(self::int($r['id'],'version ID'),$b['translation'],self::int($b['expected_revision']??null,'revision',true));
        },'kgp_edit_entries');
        self::route('/translations/(?P<id>\d+)/submit','POST',static function($r){$b=self::body($r);
            return KGP_Operator_Translations::submit(self::int($r['id'],'draft ID'),self::int($b['expected_revision']??null,'revision',true));
        },'kgp_edit_entries');
        self::route('/translations/queue','GET',static fn($r)=>KGP_Operator_Translations::queue(self::int($r->get_param('page')??1,'page')),'kgp_review_entries');
        self::route('/translations/(?P<id>\d+)/approve','POST',static function($r){$b=self::body($r);
            return KGP_Operator_Translations::approve(self::int($r['id'],'draft ID'),self::int($b['expected_revision']??null,'revision',true));
        },'kgp_review_entries');
        self::route('/translations/(?P<id>\d+)/reject','POST',static function($r){$b=self::body($r);
            return KGP_Operator_Translations::reject(self::int($r['id'],'draft ID'),self::int($b['expected_revision']??null,'revision',true),self::string($b['reason']??null,'Reason',2000));
        },'kgp_review_entries');
        self::route('/csv/batches','GET',static fn($r)=>KGP_Operator_CSV::batches(),'kgp_edit_entries');
        self::route('/csv/preview','POST',static function($r){
            $files=$r->get_file_params(); $file=$files['file']??null;
            if (!is_array($file) || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('Upload a valid UTF-8 CSV file.');
            }
            if (($file['size']??0)>KGP_Operator_CSV::MAX_BYTES) throw new InvalidArgumentException('Maximum CSV size is 2 MiB.');
            if (!preg_match('/\.csv$/i',(string)($file['name']??''))) throw new InvalidArgumentException('File extension must be .csv.');
            $bytes=file_get_contents($file['tmp_name']);
            if (!is_string($bytes)) throw new RuntimeException('Cannot read uploaded CSV.');
            return KGP_Operator_CSV::preview($bytes,(string)$file['name']);
        },'kgp_edit_entries');
        self::route('/csv/(?P<id>\d+)','GET',static fn($r)=>KGP_Operator_CSV::get_batch(self::int($r['id'],'batch ID')),'kgp_edit_entries');
        self::route('/csv/(?P<id>\d+)/apply','POST',static function($r){$b=self::body($r);
            if (!isset($b['row_ids'])||!is_array($b['row_ids'])||!array_is_list($b['row_ids'])) throw new InvalidArgumentException('row_ids must be an array.');
            $ids=array_map(static fn($x)=>self::int($x,'row_id'),$b['row_ids']);
            return KGP_Operator_CSV::apply(self::int($r['id'],'batch ID'),$ids);
        },'kgp_edit_entries');
        self::route('/history/events','GET',static function($r){
            $type=self::string($r->get_param('type'),'Entity type',32);
            return KGP_Operator_History::events($type,self::int($r->get_param('entity_id'),'entity_id'),self::int($r->get_param('page')??1,'page'));
        },'kgpa_read');
        self::route('/history/entry/(?P<id>\d+)','GET',static fn($r)=>KGP_Operator_History::field_versions(self::int($r['id'],'entry ID')),'kgpa_read');
        self::route('/history/entry/(?P<id>\d+)/compare','GET',static fn($r)=>KGP_Operator_History::compare(self::int($r['id'],'entry ID'),self::int($r->get_param('old'),'old version'),self::int($r->get_param('new'),'new version')),'kgpa_read');
        self::route('/history/entry/(?P<id>\d+)/restore','POST',static function($r){$b=self::body($r);
            return KGP_Operator_History::restore_field(self::int($r['id'],'entry ID'),self::int($b['source_version_id']??null,'source version'),self::int($b['expected_lock']??null,'lock',true));
        },'kgp_edit_entries');
        self::route('/history/identity/(?P<type>university|campus|program|intake|offering)/(?P<id>\d+)/restore','POST',static function($r){$b=self::body($r);
            return KGP_Operator_History::restore_identity((string)$r['type'],self::int($r['id'],'entity ID'),self::int($b['audit_id']??null,'audit ID'),
                self::int($b['expected_lock']??null,'lock',true),(string)($b['source_locator']??''));
        },'kgpa_edit_manage');
        self::route('/history/identity/(?P<type>university|campus|program|intake|offering)/(?P<id>\d+)/submit-restored','POST',static function($r){$b=self::body($r);
            return KGP_Operator_History::submit_restored_identity((string)$r['type'],self::int($r['id'],'entity ID'),self::int($b['expected_lock']??null,'lock',true));
        },'kgpa_edit_manage');
    }
}
