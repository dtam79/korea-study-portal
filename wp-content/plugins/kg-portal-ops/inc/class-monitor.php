<?php
defined('ABSPATH') || exit;
/**
 * Network probes are *signals*, never evidence approval. Only short fingerprints and
 * metadata are persisted, never unlicensed third-party document bodies.
 */
final class KGPO_Monitor {
    private const VALID_RESULTS=['baseline','unchanged','changed','unavailable','unsafe_url','source_invalid'];
    private const REASONS=['possible_change','unreachable','unsafe_url','source_invalid'];
    private const TASK_STATES=['pending','in_progress','resolved'];
    private static ?string $lock_value=null;
    public static function source(int $id): array {
        if ($id<1 || !KGPO_Policy::core_ready())throw new InvalidArgumentException('Core source service unavailable.');
        $source=KGP_DB::require_row('sources',$id);
        if (!is_array($source))throw new RuntimeException('Source could not be read.');
        return $source;
    }
    public static function checked_source(int $id): array {
        $source=self::source($id);
        $source['valid_for_publication']=KGP_Evidence_Repository::valid_source($id);
        return $source;
    }
    public static function state(int $source_id): ?array {
        global $wpdb;$t=KGPO_Schema::table('source_state');
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE source_id=%d",$source_id),ARRAY_A);
        return $r?:null;
    }
    public static function ensure_state(int $id,string $category='other'): array {
        if(!isset(KGPO_Policy::CATEGORIES[$category]))throw new InvalidArgumentException('Invalid category.');
        self::source($id);
        global $wpdb;$t=KGPO_Schema::table('source_state');
        $now=KGPO_Policy::utc();$days=KGPO_Policy::CATEGORIES[$category][1];
        // Atomic INSERT IGNORE. Do not reset the existing cadence or baseline.
        $sql=$wpdb->prepare("INSERT IGNORE INTO $t (source_id,category,cadence_days,next_check_at,updated_at) VALUES (%d,%s,%d,%s,%s)",$id,$category,$days,$now,$now);
        $wpdb->query($sql);
        if ($wpdb->last_error)throw new RuntimeException('Failed to initialize source monitoring.');
        return self::state($id);
    }
    public static function configure_source(int $id,array $input): array {
        if(!KGPO_Policy::can_manage())throw new DomainException('Portal manager permission required.');
        $existing=self::ensure_state($id);
        if (array_diff(array_keys($input),['category','cadence_days','assigned_to','next_check_at']))throw new InvalidArgumentException('Unsupported source policy input.');
        $changes=[];
        if (array_key_exists('category',$input)) {
            if(!is_string($input['category'])||!isset(KGPO_Policy::CATEGORIES[$input['category']]))throw new InvalidArgumentException('Unknown source category.');
            $changes['category']=$input['category'];
            if(!array_key_exists('cadence_days',$input))$changes['cadence_days']=KGPO_Policy::CATEGORIES[$input['category']][1];
        }
        if(array_key_exists('cadence_days',$input)){
            $days=filter_var($input['cadence_days'],FILTER_VALIDATE_INT);
            if ($days===false || $days<1 || $days>365)throw new InvalidArgumentException('Review cadence must be 1–365 days.');
            $changes['cadence_days']=(int)$days;
        }
        if(array_key_exists('assigned_to',$input)){
            $idUser=$input['assigned_to'];
            if($idUser===null || $idUser==='')$changes['assigned_to']=null;
            else {
                $candidate=filter_var($idUser,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($candidate===false || !user_can((int)$candidate,'kgp_review_entries'))throw new InvalidArgumentException('Assignee must have reviewer capability.');
                $changes['assigned_to']=(int)$candidate;
            }
        }
        if (array_key_exists('next_check_at',$input)) {
            $date=$input['next_check_at'];
            if(!is_string($date) || !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D',$date))throw new InvalidArgumentException('next_check_at must be UTC YYYY-MM-DD HH:MM:SS.');
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,new DateTimeZone('UTC'));
            if(!$parsed || $parsed->format('Y-m-d H:i:s')!==$date)throw new InvalidArgumentException('Invalid UTC date.');
            $changes['next_check_at']=$date;
        }
        if($changes){
            if((isset($changes['category']) || isset($changes['cadence_days'])) && !isset($changes['next_check_at'])){
                $days=(int)($changes['cadence_days']??$existing['cadence_days']);
                $anchor=$existing['last_checked_at']?strtotime($existing['last_checked_at'].' UTC'):time();
                $computed=gmdate('Y-m-d H:i:s',$anchor+$days*DAY_IN_SECONDS);
                // Narrower cadence should not quietly postpone a pending review.
                $changes['next_check_at']=min((string)$existing['next_check_at'],$computed);
            }
            $changes['updated_at']=KGPO_Policy::utc();
            global $wpdb;$ok=$wpdb->update(KGPO_Schema::table('source_state'),$changes,['source_id'=>$id]);
            if($ok===false)throw new RuntimeException('Failed to update source cadence.');
        }
        return self::state($id);
    }
    public static function safe_public_url(string $raw): bool {
        if (strlen($raw)>2048 || preg_match('/[\x00-\x20\x7F]/',$raw))return false;
        $url=wp_parse_url($raw);
        if (!is_array($url) || !in_array(strtolower($url['scheme']??''),['http','https'],true) || empty($url['host']))return false;
        if(isset($url['user']) || isset($url['pass']) || (isset($url['port'])&&!in_array((int)$url['port'],[80,443],true)))return false;
        $host=strtolower(rtrim((string)$url['host'],'.'));
        if(!preg_match('/^[a-z0-9.-]{4,253}$/D',$host) || !str_contains($host,'.') || preg_match('/(^|\.)(localhost|local|internal|test|invalid)$/',$host))return false;
        // WordPress validates resolved destination, blocking local/private ranges.
        return (bool)wp_http_validate_url($raw);
    }
    /** Pure result classification with no guess about the factual content's correctness. */
    public static function classify(?array $old,array $new): string {
        if (empty($new['ok']))return (string)($new['reason']??'unavailable');
        if ($new['status']===304 && $old && ($old['etag']||$old['last_modified']))return 'unchanged';
        if(!$old || empty($old['baseline_created_at']))return 'baseline';
        $fingerprintChanged = ($new['hash']!==null && $old['content_hash']!==null && $new['hash']!==$old['content_hash']);
        $etagChanged = ($new['etag']!==null && $old['etag']!==null && $new['etag']!==$old['etag']);
        $modifiedChanged = ($new['modified']!==null && $old['last_modified']!==null && $new['modified']!==$old['last_modified']);
        return $fingerprintChanged||$etagChanged||$modifiedChanged?'changed':'unchanged';
    }
    public static function probe(string $url,?array $prior=null): array {
        if (!self::safe_public_url($url))return ['ok'=>false,'reason'=>'unsafe_url','status'=>null,'etag'=>null,'modified'=>null,'hash'=>null,'size'=>0,'error'=>'unsafe_destination'];
        $settings=KGPO_Policy::get();
        $headers=['Accept'=>'text/html,application/pdf,application/json,text/plain,*/*;q=0.4',
            'User-Agent'=>'KGPortalSourceReview/1.0 (official-source availability check)'];
        if (!empty($prior['etag']))$headers['If-None-Match']=substr((string)$prior['etag'],0,200);
        if (!empty($prior['last_modified']))$headers['If-Modified-Since']=substr((string)$prior['last_modified'],0,100);
        $response=wp_safe_remote_get($url,[
            'timeout'=>$settings['fetch_timeout'],'redirection'=>0,'reject_unsafe_urls'=>true,
            'limit_response_size'=>$settings['max_sample_bytes'], 'headers'=>$headers,
            'sslverify'=>true,'cookies'=>[], 'blocking'=>true,
        ]);
        if (is_wp_error($response))return ['ok'=>false,'reason'=>'unavailable','status'=>null,'etag'=>null,'modified'=>null,'hash'=>null,'size'=>0,'error'=>'network_error'];
        $status=(int)wp_remote_retrieve_response_code($response);
        $etag=wp_remote_retrieve_header($response,'etag');$modified=wp_remote_retrieve_header($response,'last-modified');
        $etag=is_string($etag)&&$etag!==''?substr($etag,0,200):null;
        $modified=is_string($modified)&&$modified!==''?substr($modified,0,100):null;
        if($status===304 && $prior && ($prior['etag'] || $prior['last_modified']))return ['ok'=>true,'reason'=>null,'status'=>304,
            'etag'=>$prior['etag'],'modified'=>$prior['last_modified'],'hash'=>$prior['content_hash'],'size'=>(int)$prior['sample_size'],'error'=>null];
        if ($status!==200) return ['ok'=>false,'reason'=>'unavailable','status'=>$status,
            'etag'=>$etag,'modified'=>$modified,'hash'=>null,'size'=>0,'error'=>'http_status'];
        $body=(string)wp_remote_retrieve_body($response);
        // A limited sample is only a change hint; never infer the publication's factual correctness.
        return ['ok'=>true,'reason'=>null,'status'=>200,'etag'=>$etag,'modified'=>$modified,
            'hash'=>hash('sha256',$body),'size'=>strlen($body),'error'=>null];
    }
    private static function event(int $id,string $result,array $probe,?array $old): int {
        global $wpdb;$table=KGPO_Schema::table('source_checks');
        $before=$old?['etag'=>$old['etag'],'last_modified'=>$old['last_modified'],'content_hash'=>$old['content_hash'],'sample_size'=>(int)$old['sample_size']]:null;
        $after=['etag'=>$probe['etag'],'last_modified'=>$probe['modified'],'content_hash'=>$probe['hash'],'sample_size'=>$probe['size']];
        $ok=$wpdb->insert($table,['source_id'=>$id,'checked_at'=>KGPO_Policy::utc(),'result_code'=>$result,
            'http_status'=>$probe['status'],'etag'=>$probe['etag'],'last_modified'=>$probe['modified'],'content_hash'=>$probe['hash'],
            'sample_size'=>$probe['size'],'error_code'=>$probe['error'],
            'before_json'=>wp_json_encode($before),'after_json'=>wp_json_encode($after)]);
        if($ok===false)throw new RuntimeException('Source check history could not be stored.');
        return (int)$wpdb->insert_id;
    }
    private static function queue(int $source_id,string $reason,int $check_id,?int $assigned): void {
        if (!in_array($reason,self::REASONS,true))throw new InvalidArgumentException('Invalid reason.');
        global $wpdb;$t=KGPO_Schema::table('review_tasks');
        $found=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE source_id=%d AND reason_code=%s AND status_code IN ('pending','in_progress') LIMIT 1",$source_id,$reason));
        if ($found) {
            $wpdb->update($t,['check_id'=>$check_id,'updated_at'=>KGPO_Policy::utc()],['id'=>$found]);
            return;
        }
        $now=KGPO_Policy::utc();
        $ok=$wpdb->insert($t,['source_id'=>$source_id,'reason_code'=>$reason,'status_code'=>'pending',
            'assigned_to'=>$assigned,'check_id'=>$check_id,'opened_at'=>$now,'updated_at'=>$now,'open_slot'=>1]);
        if($ok===false){
            // Another manual check may have created the same active task.
            $concurrent=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE source_id=%d AND reason_code=%s AND open_slot=1 LIMIT 1",$source_id,$reason));
            if($concurrent){$wpdb->update($t,['check_id'=>$check_id,'updated_at'=>$now],['id'=>$concurrent]);return;}
            throw new RuntimeException('Review task creation failed.');
        }
    }
    public static function check(int $id,bool $force=false): array {
        if(!KGPO_Schema::ready() || !KGPO_Policy::core_ready())throw new RuntimeException('Install operations schema and activate the Core plugin first.');
        $source=self::checked_source($id);
        $prior=self::ensure_state($id);
        if (!$force && strcmp((string)$prior['next_check_at'],KGPO_Policy::utc())>0)return ['source_id'=>$id,'status'=>'not_due'];
        $valid=(bool)$source['valid_for_publication'];
        $probe=$valid?self::probe((string)$source['public_url'],$prior):['ok'=>false,'reason'=>'source_invalid','status'=>null,'etag'=>null,'modified'=>null,'hash'=>null,'size'=>0,'error'=>'source_not_current'];
        $result=self::classify($prior,$probe);
        $eventId=self::event($id,$result,$probe,$prior);
        $next=KGPO_Policy::next_at($result==='unavailable'?min(7,(int)$prior['cadence_days']):(int)$prior['cadence_days']);
        // Preserve the last successful baseline on failure. Do not overwrite with empty metadata.
        $changes=['last_checked_at'=>KGPO_Policy::utc(),'last_result'=>$result,'last_http_status'=>$probe['status'],
            'next_check_at'=>$next,'updated_at'=>KGPO_Policy::utc(),
            'failure_count'=>$probe['ok']?0:min(1000,(int)$prior['failure_count']+1)];
        if($probe['ok']){
            $changes['etag']=$probe['etag'];$changes['last_modified']=$probe['modified'];
            $changes['content_hash']=$probe['hash'];$changes['sample_size']=$probe['size'];
            if($result==='baseline')$changes['baseline_created_at']=KGPO_Policy::utc();
        }
        global $wpdb;$ok=$wpdb->update(KGPO_Schema::table('source_state'),$changes,['source_id'=>$id]);
        if($ok===false)throw new RuntimeException('Source state could not be updated.');
        $reason=match($result){'changed'=>'possible_change','unavailable'=>'unreachable','unsafe_url'=>'unsafe_url','source_invalid'=>'source_invalid',default=>null};
        if($reason!==null)self::queue($id,$reason,$eventId,$prior['assigned_to']!==null?(int)$prior['assigned_to']:null);
        return ['source_id'=>$id,'result'=>$result,'check_id'=>$eventId,'http_status'=>$probe['status'],
            'next_check_at'=>$next,'review_needed'=>$reason!==null];
    }
    private static function acquire_lock(): bool {
        // Atomic add_option, with compare-and-delete of stale entries. Avoid taking
        // over a newly acquired lease from another scheduler process.
        global $wpdb;
        $key='kgpo_scan_lock';$now=time();
        $previous=(string)get_option($key,'');
        if($previous!=='') {
            $expires=(int)explode(':',$previous,2)[0];
            if($expires>$now)return false;
            $removed=$wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,$previous));
            if($removed!==1)return false;
            wp_cache_delete($key,'options');
        }
        $token=wp_generate_password(24,false,false);
        $value=($now+15*MINUTE_IN_SECONDS).':'.$token;
        if(!add_option($key,$value,'','no'))return false;
        self::$lock_value=$value;
        return true;
    }
    private static function release_lock(): void {
        if(self::$lock_value===null)return;
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",'kgpo_scan_lock',self::$lock_value));
        wp_cache_delete('kgpo_scan_lock','options');
        self::$lock_value=null;
    }
    public static function due(int $limit=15,bool $force=false): array {
        if ($limit<1||$limit>25)throw new InvalidArgumentException('Scan limit must be 1–25.');
        if (!KGPO_Policy::core_ready())throw new RuntimeException('KGP Core is not ready.');
        if (!self::acquire_lock())return ['status'=>'locked','checked'=>0,'results'=>[]];
        try {
            global $wpdb;
            // Register only source IDs that exist in Core, without modifying their approval state.
            $sources=KGP_DB::table('sources');$states=KGPO_Schema::table('source_state');
            $now=KGPO_Policy::utc();
            $initialDays=KGPO_Policy::CATEGORIES['other'][1];
            // One bounded insert-select, not a database query per source.
            $seed=$wpdb->prepare("INSERT IGNORE INTO $states (source_id,category,cadence_days,next_check_at,updated_at)
                SELECT s.id,'other',%d,%s,%s FROM $sources s LEFT JOIN $states m ON m.source_id=s.id
                WHERE m.source_id IS NULL ORDER BY s.id ASC LIMIT 10000",$initialDays,$now,$now);
            if($wpdb->query($seed)===false)throw new RuntimeException('Could not register source monitoring metadata.');
            $query=$wpdb->prepare("SELECT source_id FROM $states WHERE next_check_at<=%s ORDER BY next_check_at ASC,source_id ASC LIMIT %d",$now,$limit);
            $candidates=$wpdb->get_col($query);
            $results=[];
            $started=microtime(true);
            foreach ($candidates?:[] as $id) {
                // Keep one web request within a bounded wall-clock budget. Unprocessed
                // candidates keep their prior next_check_at for the next scheduled run.
                if(microtime(true)-$started>=14.0)break;
                try {$results[]=self::check((int)$id,$force);} catch(Throwable $e) {
                    error_log('[KGPO] source #'.(int)$id.' check failed: '.$e->getMessage());
                    $results[]=['source_id'=>(int)$id,'result'=>'internal_error'];
                }
            }
            $all=['status'=>'completed','checked'=>count($results),'results'=>$results];
            $settings=KGPO_Policy::get();$settings['last_run_at']=$now;$settings['last_run_result']=$all['status'];
            update_option(KGPO_Policy::OPTION,$settings,false);
            self::notification_digest($results);
            return $all;
        } finally {self::release_lock();}
    }
    private static function notification_digest(array $results): void {
        $changes=array_values(array_filter($results,static fn($x)=>!empty($x['review_needed'])));
        if(!$changes)return;
        $to=KGPO_Policy::get()['notifications_email'];if(!$to||!is_email($to))return;
        // Do not email source bodies or sensitive data; all details stay in private admin queue.
        wp_mail($to,'[KGP] Sources require review: '.count($changes),
            count($changes).' source URLs require human review. Open the authenticated Study Portal monitoring console.');
    }
    public static function scheduled(): void {
        $settings=KGPO_Policy::get();
        if(!$settings['monitor_enabled'])return;
        try{self::due((int)$settings['batch_size']);}
        catch(Throwable $e){error_log('[KGPO] scheduled scan failed: '.$e->getMessage());}
    }
    public static function list_sources(int $page=1,string $filter=''): array {
        if(!KGPO_Policy::can_view())throw new DomainException('Reviewer access required.');
        if($page<1||$page>100000)throw new InvalidArgumentException('Invalid page.');
        global $wpdb;$sources=KGP_DB::table('sources');$states=KGPO_Schema::table('source_state');
        $where='';$args=[];
        if($filter==='due'){$where=' WHERE (m.next_check_at IS NULL OR m.next_check_at<=%s)';$args[]=KGPO_Policy::utc();}
        elseif($filter==='flagged')$where=" WHERE m.last_result IN ('changed','unavailable','unsafe_url','source_invalid')";
        elseif($filter!=='')throw new InvalidArgumentException('Invalid filter.');
        $count=(int)$wpdb->get_var($args?$wpdb->prepare("SELECT COUNT(*) FROM $sources s LEFT JOIN $states m ON m.source_id=s.id $where",...$args):"SELECT COUNT(*) FROM $sources s LEFT JOIN $states m ON m.source_id=s.id");
        $query="SELECT s.id,s.document_title,s.public_url,s.evidence_status,s.checked_at,m.category,m.cadence_days,m.next_check_at,m.last_checked_at,m.last_result,m.assigned_to FROM $sources s LEFT JOIN $states m ON m.source_id=s.id $where ORDER BY s.id ASC LIMIT %d OFFSET %d";
        $rows=$wpdb->get_results($wpdb->prepare($query,...[...$args,25,($page-1)*25]),ARRAY_A);
        return ['items'=>$rows?:[],'total'=>$count,'page'=>$page,'per_page'=>25,'total_pages'=>(int)ceil($count/25)];
    }
    public static function list_tasks(int $page=1,string $status='pending'): array {
        if(!KGPO_Policy::can_view())throw new DomainException('Reviewer access required.');
        if(!in_array($status,['pending','in_progress','resolved'],true)||$page<1||$page>100000)throw new InvalidArgumentException('Invalid task list filter.');
        global $wpdb;$t=KGPO_Schema::table('review_tasks');$s=KGP_DB::table('sources');
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE status_code=%s",$status));
        $sql="SELECT t.*,s.document_title,s.public_url,s.evidence_status FROM $t t JOIN $s s ON s.id=t.source_id WHERE t.status_code=%s ORDER BY t.updated_at DESC,t.id DESC LIMIT %d OFFSET %d";
        $rows=$wpdb->get_results($wpdb->prepare($sql,$status,25,($page-1)*25),ARRAY_A);
        return ['items'=>$rows?:[],'total'=>$total,'page'=>$page,'per_page'=>25,'total_pages'=>(int)ceil($total/25)];
    }
    public static function history(int $id): array {
        if(!KGPO_Policy::can_view())throw new DomainException('Reviewer access required.');
        self::source($id);global $wpdb;$t=KGPO_Schema::table('source_checks');
        return ['state'=>self::state($id),'items'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE source_id=%d ORDER BY id DESC LIMIT 60",$id),ARRAY_A)?:[]];
    }
    public static function decide(int $id,string $status,string $note,string $resolution=''): array {
        if(!current_user_can('kgp_review_entries'))throw new DomainException('Reviewer capability required.');
        if(!in_array($status,['in_progress','resolved'],true))throw new InvalidArgumentException('Invalid review status.');
        if($status==='resolved' && !in_array($resolution,['verified_no_change','updated_source','invalidated_in_core','deferred_to_editor'],true))throw new InvalidArgumentException('Choose a grounded resolution.');
        $note=trim(wp_strip_all_tags($note));
        if(strlen($note)<10 || strlen($note)>2000)throw new InvalidArgumentException('A 10–2,000 character review note is required.');
        global $wpdb;$t=KGPO_Schema::table('review_tasks');
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$id),ARRAY_A);
        if(!$r)throw new InvalidArgumentException('Review task not found.');
        if($r['status_code']==='resolved')throw new InvalidArgumentException('Resolved tasks cannot be reapproved without a new scan alert.');
        if($status==='resolved' && $resolution==='updated_source' && KGP_Evidence_Repository::valid_source((int)$r['source_id'])===false)throw new InvalidArgumentException('Source is not Core-verified; cannot claim updated source approval.');
        if($status==='resolved' && $resolution==='invalidated_in_core' && KGP_Evidence_Repository::valid_source((int)$r['source_id'])!==false)throw new InvalidArgumentException('Source is still Core-verified. Invalidate in the original source admin before resolving.');
        $data=['status_code'=>$status,'review_note'=>$note,'updated_at'=>KGPO_Policy::utc()];
        if($status==='resolved')$data+=['resolved_at'=>KGPO_Policy::utc(),'resolved_by'=>get_current_user_id(),'resolution_code'=>$resolution,'open_slot'=>null];
        $ok=$wpdb->update($t,$data,['id'=>$id,'status_code'=>$r['status_code']]);
        if($ok!==1)throw new RuntimeException('Task state changed concurrently. Reload the queue.');
        return ['id'=>$id,'status'=>$status];
    }
    public static function counts_cli(): array {
        global $wpdb;$s=KGPO_Schema::table('source_state');$t=KGPO_Schema::table('review_tasks');
        return ['due'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $s WHERE next_check_at<=%s",KGPO_Policy::utc())),
            'pending'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status_code='pending'"),
            'in_progress'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status_code='in_progress'")];
    }
    public static function counts(): array {
        if(!KGPO_Policy::can_view())throw new DomainException('Reviewer access required.');
        global $wpdb;$s=KGPO_Schema::table('source_state');$t=KGPO_Schema::table('review_tasks');
        return ['due'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $s WHERE next_check_at<=%s",KGPO_Policy::utc())),
            'pending'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status_code='pending'"),
            'in_progress'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status_code='in_progress'"),
            'resolved'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status_code='resolved'")];
    }
}
