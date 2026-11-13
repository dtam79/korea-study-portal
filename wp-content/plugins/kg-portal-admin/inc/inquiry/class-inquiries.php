<?php
defined('ABSPATH') || exit;

/** Private inquiry persistence and retention. Public routes must never list these records. */
final class KGP_Operator_Inquiries {
    private const STATUS=['new','in_progress','completed'];
    public static function table(): string { return KGP_DB::table('inquiries'); }
    private static function events(): string { return KGP_DB::table('inquiry_events'); }
    private static function manager(): void {
        if (!current_user_can('kgp_manage_inquiries')) throw new KGP_Domain_Exception('forbidden','Inquiry manager permission required.');
    }
    private static function valid_locale($lang): string {
        if (!is_string($lang) || !in_array($lang,['ko','en'],true)) throw new InvalidArgumentException('Consultation language must be ko or en.');
        return $lang;
    }
    private static function message($raw): string {
        if (!is_string($raw)) throw new InvalidArgumentException('Message is required.');
        $text=trim($raw);
        if (kgpa_inquiry_strlen($text)<10 || kgpa_inquiry_strlen($text)>2000 || wp_strip_all_tags($text)!==$text) throw new InvalidArgumentException('Message must be plain text (10 to 2,000 characters).');
        return $text;
    }
    private static function check_optional($raw,int $max): ?string {
        if ($raw===null || $raw==='') return null;
        if (!is_string($raw) || kgpa_inquiry_strlen($raw)>$max || wp_strip_all_tags($raw)!==$raw) throw new InvalidArgumentException('Invalid optional value.');
        return trim($raw);
    }
    private static function event(int $id,string $kind,?string $note=null,?int $actor=null): void {
        KGP_DB::insert('inquiry_events',['inquiry_id'=>$id,'actor_id'=>$actor,'event_type'=>$kind,
            'internal_note'=>$note,'recorded_at'=>KGP_DB::now()]);
    }
    private static function lookup_receipt(string $receipt): ?array {
        global $wpdb;
        $t=self::table();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE receipt_code=%s",$receipt),ARRAY_A);
        return $row?:null;
    }
    private static function public_university(?string $public_id): ?int {
        if ($public_id===null || $public_id==='') return null;
        if (!preg_match('/^[A-Za-z0-9_-]{2,64}$/D',$public_id)) throw new InvalidArgumentException('Invalid university ID.');
        $approved=KGP_Public_Catalog::university($public_id,'ko');
        if (!$approved) throw new InvalidArgumentException('Selected university is not publicly available.');
        global $wpdb;
        $t=KGP_DB::table('universities');
        $id=$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE public_id=%s",$public_id));
        if (!$id) throw new InvalidArgumentException('University not found.');
        return (int)$id;
    }
    private static function public_program(?string $offering_public_id,?int $university_id): ?array {
        if ($offering_public_id===null || $offering_public_id==='') return null;
        if (!$university_id || !preg_match('/^[A-Za-z0-9_-]{2,64}$/D',$offering_public_id)) throw new InvalidArgumentException('Select a university before an offering.');
        $offering=KGP_Public_Catalog::offering($offering_public_id,'ko');
        if (!$offering) throw new InvalidArgumentException('Selected offering is not published.');
        global $wpdb;
        $ot=KGP_DB::table('offerings');$pt=KGP_DB::table('programs');
        $found=$wpdb->get_row($wpdb->prepare("SELECT p.id AS program_id,p.university_id FROM $ot o JOIN $pt p ON p.id=o.program_id WHERE o.public_id=%s",$offering_public_id),ARRAY_A);
        if (!$found || (int)$found['university_id']!==$university_id) throw new InvalidArgumentException('Offering does not belong to selected university.');
        return ['program_id'=>(int)$found['program_id']];
    }
    public static function receipt_for_token(string $token): string {
        return 'KGP-' . strtoupper(substr(hash_hmac('sha256',$token,wp_salt('auth')),0,24));
    }
    public static function already_submitted_token(string $token): bool {
        return self::lookup_receipt(self::receipt_for_token($token)) !== null;
    }
    /** Caller must check one-time anti-spam challenge, consent and rate-limit before calling this. */
    public static function submit(array $data,string $token): array {
        $settings=KGP_Operator_Inquiry_Settings::get();
        if (!KGP_Operator_Inquiry_Settings::ready($settings)) throw new KGP_Domain_Exception('unavailable','Operator inquiry collection is not enabled.');
        $receipt=self::receipt_for_token($token);
        $existing=self::lookup_receipt($receipt);
        if ($existing) return ['receipt_code'=>$receipt,'duplicate'=>true];
        if (($data['consent']??null)!=='yes' || ($data['policy_version']??null)!==$settings['policy_version']) throw new InvalidArgumentException('Please agree to the current privacy notice.');
        $email=trim((string)($data['email']??''));
        if (strlen($email)>254 || !is_email($email)) throw new InvalidArgumentException('Invalid email address.');
        $language=self::valid_locale($data['inquiry_locale']??null);
        $body=self::message($data['message_body']??null);
        $university=self::public_university(self::check_optional($data['university_public_id']??null,64));
        $program=self::public_program(self::check_optional($data['offering_public_id']??null,64),$university);
        $intake=self::check_optional($data['preferred_intake']??null,100);
        $now=KGP_DB::now();
        $end=(new DateTimeImmutable($now,new DateTimeZone('UTC')))->modify('+'.$settings['retention_days'].' days')->format('Y-m-d H:i:s');
        $insert=['receipt_code'=>$receipt,'email'=>$email,'inquiry_locale'=>$language,'message_body'=>$body,
            'university_id'=>$university,'program_id'=>$program['program_id']??null,'preferred_intake'=>$intake,
            'consent_policy_version'=>$settings['policy_version'],'consented_at'=>$now,'assigned_to'=>null,
            'handling_status'=>'new','email_delivery_status'=>'not_attempted','delete_after'=>$end,'created_at'=>$now];
        try {
            $id=KGP_DB::transaction(static function()use($insert){
                $id=KGP_DB::insert('inquiries',$insert);
                self::event($id,'created',null,null);
                return $id;
            });
        } catch (Throwable $e) {
            $existing=self::lookup_receipt($receipt);
            if ($existing) return ['receipt_code'=>$receipt,'duplicate'=>true];
            throw $e;
        }
        // Notification contains NO email address, message content, or other personal information.
        $link=admin_url('admin.php?page=kgp-operator-inquiries');
        try {
            $sent=(bool)wp_mail($settings['notify_email'],'[Study Portal] New inquiry '.$receipt,
                'New operator inquiry: '.$receipt."\nReview securely in WordPress Admin: ".$link);
        } catch(Throwable $mail_error) {
            $sent=false;
            error_log('[KGPA] notification mail threw for internal inquiry ID '.$id);
        }
        try {
            KGP_DB::transaction(static function()use($id,$sent){
                KGP_DB::update('inquiries',['email_delivery_status'=>$sent?'sent':'failed'],['id'=>$id]);
                self::event($id,$sent?'notification_sent':'notification_failed',null,null);
            });
        } catch (Throwable $e) {
            error_log('[KGPA] inquiry notification status recording failed for internal ID '.$id);
        }
        return ['receipt_code'=>$receipt,'duplicate'=>false];
    }
    public static function list(array $params): array {
        self::manager();
        global $wpdb;
        $t=self::table();$where=['1=1'];$bindings=[];
        $status=(string)($params['status']??'');
        if ($status!=='' && !in_array($status,self::STATUS,true)) throw new InvalidArgumentException('Invalid inquiry status.');
        if ($status!=='') {$where[]='handling_status=%s';$bindings[]=$status;}
        $search=trim((string)($params['q']??''));
        if ($search!=='' && kgpa_inquiry_strlen($search)>120) throw new InvalidArgumentException('Search term too long.');
        if ($search!=='') {
            $where[]='(receipt_code LIKE %s OR email LIKE %s)';
            $pattern='%'.$wpdb->esc_like($search).'%';$bindings[]=$pattern;$bindings[]=$pattern;
        }
        $page=filter_var($params['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        $per=filter_var($params['per_page']??20,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($page===false||$per===false) throw new InvalidArgumentException('Invalid pagination.');
        $sql=' WHERE '.implode(' AND ',$where);
        $queryCount="SELECT COUNT(*) FROM $t".$sql;
        $total=(int)$wpdb->get_var($bindings?$wpdb->prepare($queryCount,...$bindings):$queryCount);
        $q="SELECT id,receipt_code,email,inquiry_locale,university_id,program_id,handling_status,assigned_to,email_delivery_status,created_at,delete_after FROM $t".$sql.' ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d';
        $rows=$wpdb->get_results($wpdb->prepare($q,...[...$bindings,$per,($page-1)*$per]),ARRAY_A);
        return ['items'=>$rows?:[],'page'=>(int)$page,'per_page'=>(int)$per,'total'=>$total,'total_pages'=>(int)ceil($total/$per)];
    }
    public static function deletion_log(int $page=1): array {
        self::manager();
        if ($page<1 || $page>100000) throw new InvalidArgumentException('Invalid page.');
        global $wpdb;
        $t=KGP_Operator_Inquiry_Schema::table('deletion_audit');
        $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
        $items=$wpdb->get_results($wpdb->prepare("SELECT id,actor_id,reason_code,erased_at FROM $t ORDER BY id DESC LIMIT 20 OFFSET %d",($page-1)*20),ARRAY_A);
        return ['items'=>$items?:[],'page'=>$page,'total'=>$count];
    }
    public static function detail(int $id): array {
        self::manager();
        $row=KGP_DB::require_row('inquiries',$id);
        global $wpdb;$t=self::events();
        $events=$wpdb->get_results($wpdb->prepare("SELECT id,actor_id,event_type,internal_note,recorded_at FROM $t WHERE inquiry_id=%d ORDER BY recorded_at DESC,id DESC",$id),ARRAY_A);
        return ['inquiry'=>$row,'events'=>$events?:[]];
    }
    public static function update(int $id,array $fields): array {
        self::manager();
        $allowed=['handling_status','assigned_to'];
        if (!$fields || array_diff(array_keys($fields),$allowed)) throw new InvalidArgumentException('Unsupported update fields.');
        return KGP_DB::transaction(static function()use($id,$fields){
            $row=KGP_DB::require_row('inquiries',$id,true);
            $clean=[];
            if (isset($fields['handling_status'])) {
                if (!in_array($fields['handling_status'],self::STATUS,true)) throw new InvalidArgumentException('Invalid handling_status.');
                $clean['handling_status']=$fields['handling_status'];
            }
            if (array_key_exists('assigned_to',$fields)) {
                $user=$fields['assigned_to'];
                if ($user===null || $user==='') $clean['assigned_to']=null;
                else {
                    $idUser=filter_var($user,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                    if ($idUser===false||!user_can((int)$idUser,'kgp_manage_inquiries')) throw new InvalidArgumentException('Assignee must be an inquiry manager.');
                    $clean['assigned_to']=(int)$idUser;
                }
            }
            KGP_DB::update('inquiries',$clean,['id'=>$id]);
            self::event($id,'updated','Status: '. $row['handling_status'].' -> '.($clean['handling_status']??$row['handling_status']).'; Assignee ID: '.($row['assigned_to']??'none').' -> '.($clean['assigned_to']??$row['assigned_to']??'none'),get_current_user_id());
            return self::detail($id);
        });
    }
    public static function note(int $id,string $note): array {
        self::manager();
        $note=trim($note);
        if (kgpa_inquiry_strlen($note)<1||kgpa_inquiry_strlen($note)>2000||wp_strip_all_tags($note)!==$note) throw new InvalidArgumentException('Note must be plain text up to 2,000 characters.');
        return KGP_DB::transaction(static function()use($id,$note){
            KGP_DB::require_row('inquiries',$id,true);
            self::event($id,'internal_note',$note,get_current_user_id());
            return self::detail($id);
        });
    }
    /** Time-triggered cleanup. Call only from registered cron event, not from unauthenticated requests. */
    public static function delete_expired(): int {
        if (!doing_action('kgpa_inquiry_retention_cleanup')) throw new KGP_Domain_Exception('forbidden','Internal cron only.');
        global $wpdb;
        $t=self::table();
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE delete_after<=%s ORDER BY id ASC LIMIT 100",KGP_DB::now()));
        $count=0;
        foreach ($ids?:[] as $id) {
            try {if(self::erase((int)$id,'retention_expired',0)) ++$count;}
            catch(Throwable $e) {error_log('[KGPA] retention deletion failed for inquiry ID '.(int)$id.': '.$e->getMessage());}
        }
        return $count;
    }
    /** Permanent deletion, including private notes. The audit receipt hash has no raw email/message. */
    public static function erase(int $id,string $reason,int $actor=0): bool {
        if ($actor>0) self::manager();
        else if (!doing_action('kgpa_inquiry_retention_cleanup')) throw new KGP_Domain_Exception('forbidden','Only the retention job may erase without an operator.');
        if (!in_array($reason,['retention_expired','operator_request'],true)) throw new InvalidArgumentException('Invalid deletion reason.');
        return KGP_DB::transaction(static function()use($id,$reason,$actor){
            global $wpdb;
            $t=self::table();$ev=self::events();
            $row=KGP_DB::require_row('inquiries',$id,true);
            $hash=hash_hmac('sha256',(string)$row['receipt_code'],wp_salt('auth'));
            if ($wpdb->query($wpdb->prepare("DELETE FROM $ev WHERE inquiry_id=%d",$id))===false) throw new RuntimeException('Deletion failed.');
            if ($wpdb->query($wpdb->prepare("DELETE FROM $t WHERE id=%d",$id))!==1) throw new RuntimeException('Deletion failed.');
            $audit=KGP_Operator_Inquiry_Schema::table('deletion_audit');
            if ($wpdb->insert($audit,['receipt_hash'=>$hash,'actor_id'=>$actor?:null,
                'reason_code'=>$reason,'erased_at'=>KGP_DB::now()])===false) throw new RuntimeException('Deletion audit recording failed.');
            return true;
        });
    }
}
