<?php
defined('ABSPATH') || exit;

/** Public form and completion pages: no private data leaves this component. */
final class KGP_Operator_Contact_Pages {
    public static function register_public_rest(): void {
        register_rest_route('kgpa/v1','/offerings', [
            'methods'=>'GET','permission_callback'=>'__return_true',
            'callback'=>static function(WP_REST_Request $r) {
                if (!KGP_Operator_Admin::ready()) return new WP_Error('kgpa_unavailable','Data service unavailable.',['status'=>503]);
                $id=(string)($r->get_param('university')??'');
                $lang=$r->get_param('lang')==='en'?'en':'ko';
                if (!preg_match('/^[A-Za-z0-9_-]{2,64}$/D',$id)) return new WP_Error('kgpa_bad_university','Invalid university.',['status'=>422]);
                $u=KGP_Public_Catalog::university($id,$lang);
                if (!$u) return new WP_Error('kgpa_unpublished','University is not published.',['status'=>404]);
                return rest_ensure_response(['items'=>array_map(static fn($o)=>[
                    'id'=>$o['offering_id'], 'label'=>trim(($o['program_name']??'').' · '.($o['academic_year']??'').' '.($o['academic_term']??'')),
                ],$u['offerings']??[])]);
            },
        ]);
    }
    public static function routes(): void {
        add_rewrite_rule('^(ko|en)/contact/complete/?$','index.php?kgpa_contact_done=1&kgpa_lang=$matches[1]','top');
        add_rewrite_rule('^(ko|en)/contact/?$','index.php?kgpa_contact=1&kgpa_lang=$matches[1]','top');
    }
    public static function vars(array $vars): array {return array_merge($vars,['kgpa_contact','kgpa_contact_done','kgpa_lang']);}
    public static function is_ours(): bool {return (bool)get_query_var('kgpa_contact') || (bool)get_query_var('kgpa_contact_done');}
    public static function template(string $path): string {
        return self::is_ours()?KGP_ADMIN_DIR.'templates/contact.php':$path;
    }
    public static function enqueue(): void {
        if (!self::is_ours()) return;
        /* KGP contact loads the same shared visual stylesheet as P01-P04. */
        $kgpa_fe_path = get_stylesheet_directory() . '/assets/css/site.css';
        if (is_file($kgpa_fe_path)) {
            wp_enqueue_style('kgpa-shared-public-fe',
                get_stylesheet_directory_uri() . '/assets/css/site.css',
                [], (string) filemtime($kgpa_fe_path));
        }

        wp_enqueue_style('kgpa-contact',plugins_url('assets/contact.css',KGP_ADMIN_DIR.'kg-portal-admin.php'),[],KGP_ADMIN_VERSION);
        wp_enqueue_script('kgpa-contact',plugins_url('assets/contact.js',KGP_ADMIN_DIR.'kg-portal-admin.php'),[],KGP_ADMIN_VERSION,['in_footer'=>true,'strategy'=>'defer']);
    }
    public static function status($preempt) {return self::is_ours()?true:$preempt;}
    public static function no_redirect($url) {return self::is_ours()?false:$url;}
    public static function no_cache(): void {
        if (self::is_ours()) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE',true);
            nocache_headers();
        }
    }
    public static function language(): string {return get_query_var('kgpa_lang')==='en'?'en':'ko';}
    public static function url(string $lang,string $path=''): string {
        return home_url('/'.($lang==='en'?'en':'ko').'/contact/'.ltrim($path,'/'));
    }
    public static function text(string $key,string $lang): string {
        $values=[
            'title'=>['운영자 문의','Contact the portal operator'],
            'intro'=>['포털 이용이나 공개 정보에 관한 문의는 운영자에게 보내주세요. 대학 입학 관련 문의는 해당 대학의 공식 연락처를 이용해 주세요.','For questions about this portal, contact the operator. For university admissions questions, use the university’s official contact information.'],
            'operator'=>['포털 운영자에게 문의','Ask the portal operator'],
            'university'=>['대학에 직접 문의','Contact a university directly'],
            'choose'=>['대학 선택(선택사항)','Preferred university (optional)'],
            'program'=>['전공·모집 회차(선택사항)','Program and intake (optional)'],
            'intake'=>['입학 희망 시기(선택사항)','Preferred admission period (optional)'],
            'email'=>['이메일','Email'],
            'language'=>['상담 언어','Consultation language'],
            'message'=>['문의 내용 (10~2,000자)','Message (10–2,000 characters)'],
            'no_docs'=>['여권번호·은행 서류·건강정보 등 민감한 내용이나 첨부파일은 보내지 마세요.','Do not submit passport numbers, financial documents, health data or attachments.'],
            'consent'=>['아래 개인정보 수집·이용 안내를 확인하고 동의합니다.','I have read and agree to the privacy collection and use notice below.'],
            'purpose'=>['수집 목적','Purpose of collection'],
            'retention'=>['보관 기간','Retention period'],
            'contact'=>['삭제·열람 문의','Deletion or access requests'],
            'recipient'=>['수신 주체: 포털 운영팀','Recipient: portal operator'],
            'submit'=>['문의 접수','Submit inquiry'],
            'closed'=>['운영자가 개인정보 처리 기준을 확정하기 전에는 문의를 접수하지 않습니다.','Inquiries are disabled until the operator confirms its privacy terms.'],
            'choose_action'=>['해당 대학 보기','View university contact information'],
            'official'=>['대학 공식 웹사이트','Official university website'],
            'no_contact'=>['확인된 대학 공식 문의처가 없습니다. 공식 홈페이지에서 담당 부서를 확인해 주세요.','No verified university contact details are published. Check the official website for the appropriate office.'],
            'external'=>['이 링크는 외부 사이트로 이동합니다. 포털은 대학에 문의 내용을 중계하지 않습니다.','This link opens an external site. The portal does not forward inquiries to universities.'],
            'done'=>['문의가 접수되었습니다.','Your inquiry has been received.'],
            'receipt'=>['접수번호','Receipt number'],
            'keep'=>['문의 상태 확인을 위해 접수번호를 보관해 주세요. 담당자가 별도 이메일로 답변할 수 있습니다.','Keep your receipt number for future reference. An operator may reply by a separate email.'],
            'home'=>['돌아가기','Back to portal'],
            'select'=>['선택하지 않음','Not selected'],
            'retry'=>['문의 입력으로 돌아가기','Return to inquiry form'],
            'privacy_ver'=>['정책 버전','Policy version'],
        ];
        $pair=$values[$key]??[$key,$key];return $lang==='en'?$pair[1]:$pair[0];
    }
    public static function approved_universities(): array {
        if (!KGP_Operator_Admin::ready()) return [];
        global $wpdb;
        $t=KGP_DB::table('universities');
        $rows=$wpdb->get_results("SELECT id FROM $t ORDER BY id ASC",ARRAY_A);
        $out=[];
        foreach ($rows?:[] as $row) {
            $item=KGP_Identity_Repository::published('university',(int)$row['id']);
            if ($item) $out[]=['id'=>$item['public_id'],'name_ko'=>$item['official_name_ko'],'name_en'=>$item['official_name_en']];
        }
        return $out;
    }
    public static function university_contact(string $public_id,string $lang): ?array {
        if (!KGP_Operator_Admin::ready()) return null;
        $uni=KGP_Public_Catalog::university($public_id,$lang);
        if (!$uni) return null;
        global $wpdb;
        $table=KGP_DB::table('universities');
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE public_id=%s",$public_id));
        $fields=KGP_Public_Reader::section('university',$id,'DET-11',$lang);
        $links=[];
        foreach ($fields as $f) {
            if ($f['field']!=='official_contacts' && $f['field']!=='international_office') continue;
            $v=$f['value'];if(!is_array($v))continue;
            if (!empty($v['official_url']) && filter_var($v['official_url'],FILTER_VALIDATE_URL)) $links[]=['label'=>$v['department']??'University contact','url'=>$v['official_url']];
            elseif (!empty($v['url']) && filter_var($v['url'],FILTER_VALIDATE_URL)) $links[]=['label'=>$v['department']??'University contact','url'=>$v['url']];
            if (!empty($v['email']) && is_email($v['email'])) $links[]=['label'=>$v['department']??'Official university email','url'=>'mailto:'.$v['email']];
        }
        return ['identity'=>$uni,'links'=>$links];
    }
    public static function token(): string {
        $token=wp_generate_password(38,false,false);
        set_transient('kgpa_form_'.hash('sha256',$token),true,45*MINUTE_IN_SECONDS);
        return $token;
    }
    private static function error(string $code,string $lang): never {
        $code=preg_replace('/[^a-z_]/','',$code);
        wp_safe_redirect(add_query_arg(['error'=>$code],self::url($lang)),303);exit;
    }
    public static function submit(): void {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') self::error('invalid','ko');
        $lang=isset($_POST['lang'])&&$_POST['lang']==='en'?'en':'ko';
        if (!KGP_Operator_Admin::ready() || !KGP_Operator_Inquiry_Settings::ready()) self::error('disabled',$lang);
        if (!empty($_POST['website_fax'])) self::error('invalid',$lang);
        $token=isset($_POST['form_token'])&&is_string($_POST['form_token'])?sanitize_text_field(wp_unslash($_POST['form_token'])):'';
        if (!preg_match('/^[A-Za-z0-9]{38}$/D',$token)) self::error('expired',$lang);
        if (KGP_Operator_Inquiries::already_submitted_token($token)) {
            wp_safe_redirect(add_query_arg(['receipt'=>KGP_Operator_Inquiries::receipt_for_token($token)],self::url($lang,'complete/')),303);exit;
        }
        if (!get_transient('kgpa_form_'.hash('sha256',$token))) self::error('expired',$lang);
        if (!isset($_POST['kgpa_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST['kgpa_nonce'])),'kgpa_submit_inquiry')) self::error('expired',$lang);
        $receipt=KGP_Operator_Inquiries::receipt_for_token($token);
        // Rate limiting uses a salted hash; never store or log a raw IP address.
        $ip=isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'';
        $bucket='kgpa_rate_'.substr(hash_hmac('sha256',$ip.'|'.gmdate('YmdH'),wp_salt('auth')),0,48);
        $count=(int)get_transient($bucket);
        if ($count >= (int)KGP_Operator_Inquiry_Settings::get()['max_submissions_per_hour']) self::error('rate',$lang);
        $allowed=['email','inquiry_locale','message_body','university_public_id','offering_public_id','preferred_intake','consent','policy_version'];
        $data=[];
        foreach ($allowed as $key) {
            $raw=$_POST[$key]??null;
            if (is_array($raw)||is_object($raw)) self::error('invalid',$lang);
            $data[$key]=is_string($raw)?wp_unslash($raw):$raw;
        }
        try {
            $result=KGP_Operator_Inquiries::submit($data,$token);
        } catch(Throwable $e) {
            error_log('[KGPA] Public inquiry submission validation/storage failure code '.get_class($e));
            self::error($e instanceof InvalidArgumentException?'validation':'unavailable',$lang);
        }
        if (!$result['duplicate']) {
            set_transient($bucket,$count+1,HOUR_IN_SECONDS);
            delete_transient('kgpa_form_'.hash('sha256',$token));
        }
        wp_safe_redirect(add_query_arg(['receipt'=>$receipt],self::url($lang,'complete/')),303);exit;
    }
    public static function error_message(string $code,string $lang): string {
        $map=[
            'invalid'=>['잘못된 요청입니다. 다시 시도해 주세요.','Invalid request. Please try again.'],
            'validation'=>['필수 입력값·동의·선택한 대학·입력 길이를 확인해 주세요.','Check required fields, consent, selected university and input length.'],
            'expired'=>['양식의 보안 유효기간이 만료되었습니다. 페이지를 새로고침해 주세요.','Form verification expired. Refresh the page and try again.'],
            'rate'=>['짧은 시간에 많은 문의가 접수되었습니다. 잠시 후 다시 시도해 주세요.','Too many submissions. Please try later.'],
            'unavailable'=>['문의 저장을 완료하지 못했습니다. 다시 시도해 주세요.','We could not complete your inquiry. Please try again.'],
            'disabled'=>['현재 문의 접수가 비활성화되어 있습니다.','Inquiry collection is currently disabled.'],
        ];
        $pair=$map[$code]??$map['invalid'];return $lang==='en'?$pair[1]:$pair[0];
    }
}
