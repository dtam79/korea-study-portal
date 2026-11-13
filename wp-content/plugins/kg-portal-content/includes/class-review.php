<?php
defined('ABSPATH') || exit;

final class KGPC_Review {
    public static function nonce(int $id,string $action): void {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST['_wpnonce'])),'kgpc_'.$action.'_'.$id)) wp_die('Invalid request token.','', ['response'=>403]);
    }
    private static function require_perm(string $permission): void {
        if (!current_user_can($permission)) wp_die('Permission denied.','',['response'=>403]);
    }
    public static function hash(WP_Post $p): string {
        $keys=['kind','lang','slug','topic','category','audience','basis_date','checked_at','source_ids','source_locator','effective_at','revised_at','contact','translation_of'];
        $meta=[];
        foreach ($keys as $key) $meta[$key]=KGPC_Content::meta($p->ID,$key);
        return hash('sha256',wp_json_encode([$p->post_title,$p->post_content,$meta],JSON_UNESCAPED_UNICODE));
    }
    private static function post(int $id): WP_Post {
        $p=get_post($id);
        if (!$p || $p->post_type!=='kgpc_article') throw new InvalidArgumentException('Content draft not found.');
        return $p;
    }
    private static function date(string $value,string $name,bool $required): void {
        if ($value===''&&!$required)return;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$d || $d->format('Y-m-d')!==$value) throw new InvalidArgumentException($name.' must be YYYY-MM-DD. Never invent an unknown date.');
    }
    public static function candidate(WP_Post $post): array {
        $m=static fn($key)=>KGPC_Content::meta($post->ID,$key);
        $kind=$m('kind');$lang=$m('lang');$slug=$m('slug');
        if (!in_array($kind,KGPC_Content::TYPES,true)||!in_array($lang,['ko','en'],true))throw new InvalidArgumentException('Select a valid content type and language.');
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)||strlen($slug)>80)throw new InvalidArgumentException('Enter a lowercase URL slug using letters/numbers/hyphens.');
        if (trim($post->post_title)===''||trim(wp_strip_all_tags($post->post_content))==='')throw new InvalidArgumentException('A title and nonempty article body are required.');
        if ($kind==='guide'&&!isset(KGPC_Content::TOPICS[$m('topic')]))throw new InvalidArgumentException('Choose one of the six approved guide topics.');
        if ($kind==='faq'&&!isset(KGPC_Content::FAQ_CATS[$m('category')]))throw new InvalidArgumentException('Choose a valid FAQ category.');
        if ($kind==='policy'&&!isset(KGPC_Content::POLICIES[$slug]))throw new InvalidArgumentException('Policies use privacy, terms or about as their slug.');
        if ($m('audience')==='' && $kind!=='policy')throw new InvalidArgumentException('Applicability/audience is required.');
        self::date($m('basis_date'),'Reference date',false);
        self::date($m('checked_at'),'Checked date',$kind!=='policy');
        self::date($m('effective_at'),'Policy effective date',$kind==='policy');
        self::date($m('revised_at'),'Policy revision date',$kind==='policy');
        if ($kind==='policy' && (!is_email($m('contact')) || strlen($m('contact'))>254))throw new InvalidArgumentException('Policy requires a verified operator contact email.');
        $ids=KGPC_Content::source_ids($post->ID);
        if ($m('source_ids')!=='' && !$ids) throw new InvalidArgumentException('Official source IDs must be positive numeric IDs separated by commas.');
        $sources_required=($kind==='guide'||($kind==='faq' && $m('category')!=='portal'));
        if ($sources_required && (!$ids||!KGPC_Content::sources_valid($ids)))throw new InvalidArgumentException('Valid, approved official source IDs with source check dates are required.');
        if ($ids&&!KGPC_Content::sources_valid($ids))throw new InvalidArgumentException('One or more attached sources are unverified or no longer valid.');
        $origin=null;$original_version=0;
        if ($lang==='en') {
            $origin_id=filter_var($m('translation_of'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($origin_id===false) throw new InvalidArgumentException('English content must link to an approved Korean original post ID.');
            $origin=self::active((int)$origin_id);
            if (!$origin || $origin['lang']!=='ko'||$origin['kind']!==$kind||$origin['slug']!==$slug || !self::is_current($origin)) throw new InvalidArgumentException('Korean original is not the current verified published version.');
            $original_version=(int)$origin['_version_id'];
        }
        return [
            'post_id'=>$post->ID,'kind'=>$kind,'lang'=>$lang,'slug'=>$slug,
            'title'=>sanitize_text_field($post->post_title),'content'=>$post->post_content,
            'topic'=>$m('topic'),'category'=>$m('category'),'audience'=>$m('audience'),
            'basis_date'=>$m('basis_date'),'checked_at'=>$m('checked_at'),
            'source_ids'=>$ids,'source_locator'=>$m('source_locator'),
            'effective_at'=>$m('effective_at'),'revised_at'=>$m('revised_at'),'contact'=>$m('contact'),
            'translation_of'=>$lang==='en'?(int)$origin_id:0,'translation_source_version'=>$original_version,
            'approved_at'=>'',
        ];
    }
    public static function active(int $post_id): ?array {
        $id=(int)get_post_meta($post_id,'_kgpc_active_version',true);
        if (!$id) return null;
        global $wpdb;$t=KGPC_Content::table();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d AND post_id=%d",$id,$post_id),ARRAY_A);
        if (!$row) return null;
        $snapshot=json_decode((string)$row['snapshot'],true);
        if (!is_array($snapshot)) return null;
        $snapshot['_version_id']=(int)$row['id'];
        return $snapshot;
    }
    public static function is_current(array $snap): bool {
        if (!KGPC_Content::ready()) return false;
        $sources=$snap['source_ids']??[];
        if ($sources && !KGPC_Content::sources_valid($sources))return false;
        if (($snap['kind']==='guide'||($snap['kind']==='faq'&&$snap['category']!=='portal')) && !$sources)return false;
        if ($snap['lang']==='en') {
            $origin=self::active((int)($snap['translation_of']??0));
            if (!$origin||($origin['lang']??'')!=='ko'||(int)$origin['_version_id']!==(int)$snap['translation_source_version']||!self::is_current($origin))return false;
        }
        return true;
    }
    private static function audit(int $post_id,string $action,array $details=[]): void {
        $events=get_post_meta($post_id,'_kgpc_review_events',true);
        if (!is_array($events))$events=[];
        $events[]=['action'=>$action,'by'=>get_current_user_id(),'at'=>current_time('mysql',true),'data'=>$details];
        update_post_meta($post_id,'_kgpc_review_events',$events);
    }
    private static function back(string $message): never {
        $args=['page'=>'kgpc-console','kgpc_notice'=>rawurlencode($message)];
        if (isset($_POST['post_id']) && absint($_POST['post_id'])>0) $args['post_id']=absint($_POST['post_id']);
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php')),303);exit;
    }
    public static function submit(): void {
        self::require_perm('kgp_edit_entries');
        $id=(int)($_POST['post_id']??0);self::nonce($id,'submit');
        try {
            $post=self::post($id);
            if (KGPC_Content::meta($id,'lang')==='ko'||KGPC_Content::meta($id,'lang')==='en')self::candidate($post);
            else throw new InvalidArgumentException('Select a language.');
            if (get_post_meta($id,'_kgpc_review_status',true)==='pending')throw new InvalidArgumentException('Already submitted.');
            update_post_meta($id,'_kgpc_submitted_hash',self::hash($post));
            update_post_meta($id,'_kgpc_submitted_by',get_current_user_id());
            update_post_meta($id,'_kgpc_review_status','pending');
            self::audit($id,'submitted');self::back('검토 요청이 접수되었습니다.');
        } catch(Throwable $e) {self::back('검토 요청 실패: '.$e->getMessage());}
    }
    private static function version_count(int $post_id): int {
        global $wpdb;$t=KGPC_Content::table();
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_no),0) FROM $t WHERE post_id=%d",$post_id));
    }
    private static function conflict(array $snap,int $exclude): bool {
        global $wpdb;$posts=$wpdb->posts;$meta=$wpdb->postmeta;
        $ids=$wpdb->get_col($wpdb->prepare("SELECT pm.post_id FROM $meta pm JOIN $posts p ON p.ID=pm.post_id WHERE pm.meta_key='_kgpc_active_version' AND p.post_type='kgpc_article' AND pm.post_id<>%d",$exclude));
        foreach ($ids?:[] as $id) {
            $old=self::active((int)$id);
            if ($old && $old['kind']===$snap['kind']&&$old['lang']===$snap['lang']&&$old['slug']===$snap['slug'])return true;
        }
        return false;
    }
    public static function decide(): void {
        self::require_perm('kgp_review_entries');
        $id=(int)($_POST['post_id']??0);self::nonce($id,'decide');
        $action=isset($_POST['decision'])&&is_scalar($_POST['decision'])?(string)$_POST['decision']:'';
        if(!in_array($action,['approve','reject'],true))self::back('알 수 없는 검토 동작입니다.');
        try {
            if (!KGPC_Content::ready())throw new RuntimeException('Core/content schema not ready.');
            $post=self::post($id);
            $status=get_post_meta($id,'_kgpc_review_status',true);
            if ($status!=='pending')throw new InvalidArgumentException('Only submitted drafts can be reviewed.');
            // KGPC_SELF_REVIEW_V092: authors and submitters with REVIEW capability may decide.
            // Do not relax source verification, snapshot hash checks or the reviewer capability.
            $is_self_review = ((int)$post->post_author===get_current_user_id()
                || (int)get_post_meta($id,'_kgpc_submitted_by',true)===get_current_user_id());
            $current=self::hash($post);
            $given=isset($_POST['expected_hash'])&&is_scalar($_POST['expected_hash'])?(string)$_POST['expected_hash']:'';
            if (!$given||!hash_equals($given,$current)||!hash_equals((string)get_post_meta($id,'_kgpc_submitted_hash',true),$current))throw new InvalidArgumentException('Draft changed after submission; resubmit before review.');
            if ($action==='approve' && ($_POST['verified_manually']??'')!=='yes') throw new InvalidArgumentException('Manual source and content comparison confirmation is required.');
            if ($action==='reject') {
                $reason=isset($_POST['reason'])&&is_scalar($_POST['reason'])?trim(sanitize_textarea_field(wp_unslash((string)$_POST['reason']))):'';
                if ($reason==='')throw new InvalidArgumentException('Rejection reason required.');
                update_post_meta($id,'_kgpc_review_status','rejected');
                update_post_meta($id,'_kgpc_rejection_reason',$reason);
                self::audit($id,'rejected',['reason'=>$reason,'self_review'=>$is_self_review]);self::back('반려 처리를 완료했습니다.');
            }
            $snap=self::candidate($post);
            if(self::conflict($snap,$id))throw new InvalidArgumentException('Another approved article already uses this content type, language and URL slug.');
            $snap['approved_at']=current_time('mysql',true);
            global $wpdb;$table=KGPC_Content::table();
            $wpdb->query('START TRANSACTION');
            try {
                $num=self::version_count($id)+1;
                $inserted=$wpdb->insert($table,[
                    'post_id'=>$id,'version_no'=>$num,'snapshot'=>wp_json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    'created_at'=>current_time('mysql',true),'approved_by'=>get_current_user_id(),
                    'source_hash'=>hash('sha256',wp_json_encode([$snap['source_ids'],$snap['source_locator'],$snap['translation_source_version']])),
                ]);
                if(!$inserted)throw new RuntimeException('Could not store frozen public snapshot: '.$wpdb->last_error);
                $version_id=(int)$wpdb->insert_id;
                if (!update_post_meta($id,'_kgpc_active_version',$version_id)) {
                    if ((int)get_post_meta($id,'_kgpc_active_version',true)!==$version_id)throw new RuntimeException('Could not activate snapshot.');
                }
                update_post_meta($id,'_kgpc_review_status','approved');
                self::audit($id,'approved',['version_id'=>$version_id,'self_review'=>$is_self_review]);
                $wpdb->query('COMMIT');
            } catch(Throwable $e) {$wpdb->query('ROLLBACK');throw $e;}
            clean_post_cache($id);
            self::back('새 승인 버전이 공개되었습니다.');
        } catch(Throwable $e) {self::back('검토 실패: '.$e->getMessage());}
    }
    public static function unpublish(): void {
        self::require_perm('kgp_review_entries');
        $id=(int)($_POST['post_id']??0);self::nonce($id,'unpublish');
        self::post($id);
        $reason=isset($_POST['reason'])&&is_scalar($_POST['reason'])?trim(sanitize_textarea_field(wp_unslash((string)$_POST['reason']))):'';
        if ($reason==='')self::back('비공개 사유를 입력하세요.');
        delete_post_meta($id,'_kgpc_active_version');
        update_post_meta($id,'_kgpc_review_status','draft');
        self::audit($id,'unpublished',['reason'=>$reason]);
        self::back('게시본이 비공개 처리되었습니다.');
    }
    public static function editor_queue(): void {
        if (!current_user_can('kgp_edit_entries'))return;
        $posts=get_posts(['post_type'=>'kgpc_article','post_status'=>['draft','pending','private'],'numberposts'=>50,'orderby'=>'modified','order'=>'DESC']);
        echo '<h2>최근 콘텐츠 초안</h2><table class="widefat striped"><thead><tr><th>글</th><th>상태</th><th>필수 정보</th><th>검토 요청</th></tr></thead><tbody>';
        foreach($posts as $post) {
            $status=get_post_meta($post->ID,'_kgpc_review_status',true)?:'draft';
            echo '<tr><td><a href="'.esc_url(get_edit_post_link($post->ID)).'">'.esc_html($post->post_title?:'(제목 없음)').'</a></td><td>'.esc_html($status).'</td><td><a class="button" href="'.esc_url(admin_url('admin.php?page=kgpc-metadata&post_id='.$post->ID)).'">입력·수정</a></td><td>';
            if ($status!=='pending') {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kgpc_submit"><input type="hidden" name="post_id" value="'.(int)$post->ID.'">';
                wp_nonce_field('kgpc_submit_'.$post->ID);
                echo '<button class="button" type="submit">검토 요청</button></form>';
            } else echo '검토 중';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    public static function page(): void {
        self::require_perm('kgp_review_entries');
        echo '<div class="wrap"><h1>가이드·FAQ·정책 검토 대기</h1><p>원문 URL·발행기관·적용 대상·확인일·번역 일치 여부를 직접 확인한 뒤 승인하세요. 작성자도 검토 권한이 있다면 직접 승인할 수 있습니다. 본인 검토는 기록에 표시됩니다.</p>';
        $posts=get_posts(['post_type'=>'kgpc_article','post_status'=>['draft','pending','private'],'numberposts'=>150,'orderby'=>'modified','order'=>'DESC']);
        $total=0;
        foreach ($posts as $post) {
            if (get_post_meta($post->ID,'_kgpc_review_status',true)!=='pending')continue;
            $total++;$hash=self::hash($post);
            echo '<section style="max-width:960px;background:white;border:1px solid #ccd0d4;padding:18px;margin:15px 0;border-radius:8px">';
            echo '<h2>'.esc_html($post->post_title).' <small>글 #'.(int)$post->ID.'</small></h2>';
            echo '<p><b>작성자:</b> '.(int)$post->post_author.' · <b>언어:</b> '.esc_html(KGPC_Content::meta($post->ID,'lang')).' · <b>종류:</b> '.esc_html(KGPC_Content::meta($post->ID,'kind')).'</p>';
            echo '<p><b>적용 대상:</b> '.esc_html(KGPC_Content::meta($post->ID,'audience')).' · <b>기준일:</b> '.esc_html(KGPC_Content::meta($post->ID,'basis_date')).' · <b>원문 확인일:</b> '.esc_html(KGPC_Content::meta($post->ID,'checked_at')).'</p>';
            echo '<details><summary>제출된 본문 보기</summary><div style="background:#f6f7f7;padding:12px">'.wp_kses_post(do_blocks($post->post_content)).'</div></details>';
            $ids=KGPC_Content::source_ids($post->ID);
            echo '<h3>공식 원문</h3>';
            if (!$ids)echo '<p>연결된 출처 없음 (포털 이용 FAQ/정책만 예외 가능)</p>';
            foreach($ids as $id) {
                try {$src=KGP_DB::require_row('sources',$id);$verified=KGPC_Content::sources_valid([$id]);
                echo '<p>'.($verified?'[유효] ':'[미검증] ').esc_html($src['document_title']??'출처').': <a href="'.esc_url($src['public_url']??'').'" target="_blank" rel="noopener noreferrer">공식 원문 열기 ↗</a> · 확인일 '.esc_html($src['checked_at']??'미확인').'</p>';
                } catch(Throwable $e) {echo '<p>출처 #'.(int)$id.' 검증 불가</p>';}
            }
            echo '<p><b>원문 위치:</b> '.esc_html(KGPC_Content::meta($post->ID,'source_locator')).'</p>';
            if ((int)$post->post_author===get_current_user_id() || (int)get_post_meta($post->ID,'_kgpc_submitted_by',true)===get_current_user_id()) {
                echo '<p style="padding:10px;border-left:4px solid #dba617;background:#fff8db"><strong>본인 검토:</strong> 본인이 작성 또는 제출한 글입니다. 검토 권한이 있다면 직접 승인할 수 있으나, 공식 원문과 내용·적용 범위를 반드시 확인하세요. 검토자는 기록에 남습니다.</p>';
            }
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kgpc_decide"><input type="hidden" name="post_id" value="'.(int)$post->ID.'"><input type="hidden" name="expected_hash" value="'.esc_attr($hash).'">';
                wp_nonce_field('kgpc_decide_'.$post->ID);
                echo '<p><label><input type="checkbox" name="verified_manually" value="yes" required> 공식 원문과 글의 내용·적용 대상·기준 시점을 직접 대조했습니다.</label></p><p><label>반려 사유 <input style="width:70%" name="reason" maxlength="2000"></label></p>';
                echo '<button class="button button-primary" name="decision" value="approve">승인·게시</button> <button class="button" name="decision" value="reject" formnovalidate>반려</button></form>';
            echo '</section>';
        }
        if (!$total)echo '<p>검토 대기 중인 글이 없습니다.</p>';
        echo '<h2>승인 완료된 글 비공개 처리</h2>';
        self::unpublish_list();
        echo '</div>';
    }
    private static function unpublish_list(): void {
        global $wpdb;$table=$wpdb->postmeta;
        $ids=$wpdb->get_col("SELECT post_id FROM $table WHERE meta_key='_kgpc_active_version' AND meta_value<>'' ORDER BY post_id DESC LIMIT 100");
        foreach($ids?:[] as $id) {
            $post=get_post((int)$id);if(!$post||$post->post_type!=='kgpc_article')continue;
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="padding:8px 0;border-bottom:1px solid #ddd"><input type="hidden" name="action" value="kgpc_unpublish"><input type="hidden" name="post_id" value="'.(int)$id.'">';
            wp_nonce_field('kgpc_unpublish_'.(int)$id);
            echo '<strong>'.esc_html($post->post_title).'</strong> · <label>사유 <input name="reason" required maxlength="2000" style="width:38%"></label> <button class="button">비공개</button></form>';
        }
    }
}
