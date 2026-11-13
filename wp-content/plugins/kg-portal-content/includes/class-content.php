<?php
defined('ABSPATH') || exit;

final class KGPC_Content {
    public const TYPES=['guide','faq','policy'];
    public const TOPICS=[
        'visa-preparation'=>['비자 준비 (D-2·D-4)','Visa preparation (D-2 / D-4)'],
        'part-time-work'=>['아르바이트 (시간제취업)','Part-time employment'],
        'arrival-settlement'=>['입국 후 정착','Settling in after arrival'],
        'post-graduation-visas'=>['졸업 후 진로 비자','Post-graduation visa pathways'],
        'preparation-timeline'=>['유학 준비 타임라인','Study preparation timeline'],
        'emergency-and-scams'=>['긴급 연락처와 사기 주의','Emergency contacts and scam awareness'],
    ];
    public const FAQ_CATS=[
        'admission'=>['입학','Admissions'],'cost'=>['비용','Costs'],
        'schedule'=>['일정','Schedules'],'living'=>['생활','Living in Korea'],
        'portal'=>['포털 이용','Portal usage'],
    ];
    public const POLICIES=[
        'privacy'=>['개인정보 처리방침','Privacy policy'],
        'terms'=>['이용 안내','Terms of use'],
        'about'=>['운영 소개','About this portal'],
    ];
    public static function activate(): void {
        self::register(); KGPC_Public::routes();
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table();$charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            version_no int(11) unsigned NOT NULL,
            snapshot longtext NOT NULL,
            created_at datetime NOT NULL,
            approved_by bigint(20) unsigned NOT NULL,
            source_hash char(64) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_version (post_id,version_no),
            KEY post_latest (post_id,id)
        ) $charset;");
        add_option('kgpc_schema_version','0.9.0');
        flush_rewrite_rules();
    }
    public static function table(): string {global $wpdb; return $wpdb->prefix.'kgpc_versions';}
    public static function ready(): bool {
        return class_exists('KGP_Operator_Admin') && KGP_Operator_Admin::ready()
            && class_exists('KGP_DB') && class_exists('KGP_Evidence_Repository')
            && get_option('kgpc_schema_version')==='0.9.0';
    }
    public static function permission(): bool {
        return current_user_can('kgp_edit_entries') || current_user_can('kgp_review_entries');
    }
    public static function register(): void {
        register_post_type('kgpc_article',[
            'label'=>'가이드·FAQ·정책','labels'=>['name'=>'콘텐츠 목록','singular_name'=>'공식 콘텐츠','add_new_item'=>'콘텐츠 초안 작성','edit_item'=>'콘텐츠 초안 수정'],
            'public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,
            'show_ui'=>true,'show_in_rest'=>true,'show_in_menu'=>'kgpc-console',
            'capability_type'=>['kgpc_article','kgpc_articles'],
            'capabilities'=>[
                'create_posts'=>'kgp_edit_entries','edit_posts'=>'kgp_edit_entries',
                'edit_post'=>'kgp_edit_entries','read_post'=>'kgp_edit_entries','delete_post'=>'kgp_review_entries',
                'edit_others_posts'=>'kgp_edit_entries','edit_published_posts'=>'kgp_edit_entries',
                'edit_private_posts'=>'kgp_edit_entries','publish_posts'=>'kgp_review_entries',
                'delete_posts'=>'kgp_review_entries','delete_others_posts'=>'kgp_review_entries',
                'delete_published_posts'=>'kgp_review_entries','delete_private_posts'=>'kgp_review_entries',
                'read_private_posts'=>'kgp_edit_entries',
            ],'map_meta_cap'=>false,
            'supports'=>['title','editor','revisions','author','custom-fields'],
            'rewrite'=>false,'query_var'=>false,'has_archive'=>false,'hierarchical'=>false,
        ]);
        // Gutenberg cannot expose an approved document: every WP post stays draft/pending.
        $schema=['type'=>'string','single'=>true,'show_in_rest'=>true,
            'auth_callback'=>static fn()=>current_user_can('kgp_edit_entries')];
        foreach (['kind','lang','slug','topic','category','audience','basis_date','checked_at','source_ids','source_locator','effective_at','revised_at','contact','translation_of'] as $key) {
            register_post_meta('kgpc_article','_kgpc_'.$key,$schema);
        }
    }
    public static function admin_menu(): void {
        if (!self::permission()) return;
        $cap=current_user_can('kgp_edit_entries')?'kgp_edit_entries':'kgp_review_entries';
        add_menu_page('유학 가이드와 FAQ','가이드·FAQ',$cap,'kgpc-console',[self::class,'landing'],'dashicons-media-document',32);
        if (current_user_can('kgp_edit_entries')) {
            add_submenu_page('kgpc-console','콘텐츠 목록','콘텐츠 초안','kgp_edit_entries','edit.php?post_type=kgpc_article');
            add_submenu_page('kgpc-console','새 콘텐츠','새 콘텐츠','kgp_edit_entries','post-new.php?post_type=kgpc_article');
        }
        if (current_user_can('kgp_edit_entries')) {
            add_submenu_page('kgpc-console','필수 정보 입력','필수 정보 입력','kgp_edit_entries','kgpc-metadata',[self::class,'metadata_page']);
        }
        if (current_user_can('kgp_review_entries')) {
            add_submenu_page('kgpc-console','검토 대기','검토·게시','kgp_review_entries','kgpc-review',['KGPC_Review','page']);
        }
    }
    public static function landing(): void {
        if (!self::permission()) wp_die('Permission denied');
        echo '<div class="wrap"><h1>한국 대학 유학 정보 포털 · 콘텐츠 관리</h1><p>워드프레스 블록 편집기로 6개 가이드·FAQ·정책을 작성합니다. 게시에는 별도 검토와 공식 근거가 필요합니다.</p>';
        // KGPC_FIX_META_V091: Review failures must be shown to the editor, not only in the URL.
        if (isset($_GET['kgpc_notice']) && is_scalar($_GET['kgpc_notice'])) {
            $notice=sanitize_text_field(wp_unslash((string)$_GET['kgpc_notice']));
            $danger=str_contains($notice,'실패') || str_contains($notice,'오류');
            echo '<div class="notice '.($danger?'notice-error':'notice-success').' is-dismissible"><p>'.esc_html($notice).'</p>';
            $notice_id=isset($_GET['post_id'])?absint($_GET['post_id']):0;
            if ($notice_id && get_post_type($notice_id)==='kgpc_article' && current_user_can('kgp_edit_entries')) {
                echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=kgpc-metadata&post_id='.$notice_id)).'">이 글의 필수 정보 입력·수정</a>';
            }
            echo '</div>';
        }
        if (current_user_can('kgp_edit_entries')) echo '<p><a class="button button-primary" href="'.esc_url(admin_url('post-new.php?post_type=kgpc_article')).'">새 콘텐츠 초안</a> <a class="button" href="'.esc_url(admin_url('edit.php?post_type=kgpc_article')).'">기존 초안 보기</a></p>';
        if (current_user_can('kgp_review_entries')) echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=kgpc-review')).'">검토 대기열</a></p>';
        echo '<h2>필수 가이드 6개 주제</h2><ol>';
        foreach (self::TOPICS as $topic) echo '<li>'.esc_html($topic[0]).'</li>';
        echo '</ol><p>실제 공식 원문을 등록·검증하기 전에는 공백 상태로 유지됩니다. 빈 주제 제목은 이용자에게 표시되지만 근거 없는 본문은 게시되지 않습니다.</p>';
        if (current_user_can('kgp_edit_entries')) KGPC_Review::editor_queue();
        echo '</div>'; 
    }
    /** Dedicated metadata editor: always visible outside Gutenberg's collapsed Meta Boxes area. */
    public static function metadata_page(): void {
        if (!current_user_can('kgp_edit_entries')) wp_die('Permission denied.','',['response'=>403]);
        $id=isset($_GET['post_id'])?absint($_GET['post_id']):0;
        $post=$id?get_post($id):null;
        echo '<div class="wrap"><h1>콘텐츠 필수 정보 입력</h1>';
        if (!$post || $post->post_type!=='kgpc_article') {
            echo '<p>글 목록에서 초안을 선택한 다음 필수 정보를 입력하세요.</p>';
            echo '<p><a class="button button-primary" href="'.esc_url(admin_url('edit.php?post_type=kgpc_article')).'">콘텐츠 목록으로 이동</a></p></div>';
            return;
        }
        echo '<p><strong>글 #'.(int)$id.': '.esc_html($post->post_title?:'(제목 없음)').'</strong></p>';
        echo '<p>먼저 블록 편집기에서 제목과 본문을 <strong>초안 저장</strong>하세요. 여기서는 유형·언어·URL 식별자·FAQ 분류·공식 출처 등 검토 필수 정보를 저장합니다.</p>';
        if (isset($_GET['kgpc_meta_notice']) && is_scalar($_GET['kgpc_meta_notice'])) {
            echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash((string)$_GET['kgpc_meta_notice']))).'</p></div>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="max-width:860px;background:#fff;border:1px solid #c3c4c7;padding:18px">';
        echo '<input type="hidden" name="action" value="kgpc_save_metadata">';
        echo '<input type="hidden" name="post_id" value="'.(int)$id.'">';
        self::meta_box($post); // Includes a dedicated kgpc_meta_nonce.
        echo '<p><button type="submit" class="button button-primary">필수 정보 저장</button></p></form>';
        echo '<p><a class="button" href="'.esc_url(get_edit_post_link($id)).'">본문 편집기로 돌아가기</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=kgpc-console')).'">콘텐츠 관리·검토 요청</a></p></div>';
    }
    /** Save metadata from the dedicated editor; do not bypass the review/publish workflow. */
    public static function metadata_save(): void {
        if (!current_user_can('kgp_edit_entries')) wp_die('Permission denied.','',['response'=>403]);
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') wp_die('Invalid request method.','',['response'=>405]);
        $id=isset($_POST['post_id'])?absint($_POST['post_id']):0;
        $post=$id?get_post($id):null;
        if (!$post || $post->post_type!=='kgpc_article') wp_die('Invalid content draft.','',['response'=>404]);
        if (!isset($_POST['kgpc_meta_nonce']) || !is_scalar($_POST['kgpc_meta_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST['kgpc_meta_nonce'])),'kgpc_meta_save')) {
            wp_die('Invalid metadata token.','',['response'=>403]);
        }
        self::save_post($id,$post,true);
        try {
            KGPC_Review::candidate($post); // Read-only preflight; approval is still a separate action.
            $message='필수 정보를 저장했습니다. 검토 요청을 진행할 수 있습니다.';
        } catch (Throwable $e) {
            $message='필수 정보는 저장했습니다. 검토 요청 전 확인: '.$e->getMessage();
        }
        wp_safe_redirect(add_query_arg([
            'page'=>'kgpc-metadata','post_id'=>$id,'kgpc_meta_notice'=>rawurlencode($message),
        ],admin_url('admin.php')),303);
        exit;
    }
    public static function meta_boxes(): void {
        add_meta_box('kgpc_metadata','공식 출처·언어·적용 범위',[self::class,'meta_box'],'kgpc_article','normal','high');
        add_meta_box('kgpc_review','검토·게시 상태',[self::class,'review_box'],'kgpc_article','side','high');
    }
    public static function meta_box(WP_Post $post): void {
        wp_nonce_field('kgpc_meta_save','kgpc_meta_nonce');
        $get=static fn($k)=>(string)get_post_meta($post->ID,'_kgpc_'.$k,true);
        $pick=static function($name,$options,$selected) {
            echo '<select name="kgpc_'.esc_attr($name).'" id="kgpc_'.esc_attr($name).'">';
            foreach ($options as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($selected,$key,false).'>'.esc_html($label).'</option>';
            echo '</select>';
        };
        echo '<p><label for="kgpc_kind"><strong>유형 *</strong></label> ';
        $pick('kind',['guide'=>'가이드','faq'=>'FAQ','policy'=>'정책·운영 소개'],$get('kind'));
        echo ' <label for="kgpc_lang"><strong>언어 *</strong></label> ';
        $pick('lang',['ko'=>'한국어 원문','en'=>'English translation'],$get('lang'));
        echo '</p><p><label for="kgpc_slug"><strong>공개 URL 식별자 *</strong></label><input style="width:100%" name="kgpc_slug" id="kgpc_slug" value="'.esc_attr($get('slug')).'" placeholder="visa-preparation 또는 privacy" maxlength="80"><small>같은 글의 한국어·영어는 같은 slug를 사용하세요.</small></p>';
        echo '<p><label for="kgpc_topic">가이드 주제</label> ';
        $pick('topic',[''=>'해당 없음']+array_map(static fn($a)=>$a[0],self::TOPICS),$get('topic'));
        echo ' <label for="kgpc_category">FAQ 분류</label> ';
        $pick('category',[''=>'해당 없음']+array_map(static fn($a)=>$a[0],self::FAQ_CATS),$get('category'));
        echo '</p>';
        foreach (['audience'=>'적용 대상 *','basis_date'=>'기준 시점 * (원문 기준, YYYY-MM-DD)','checked_at'=>'원문 확인일 * (YYYY-MM-DD)','source_ids'=>'Core 검증 출처 ID (쉼표 구분)','source_locator'=>'원문 위치 (페이지/표/절)','effective_at'=>'정책 시행일 (YYYY-MM-DD)','revised_at'=>'정책 개정일 (YYYY-MM-DD)','contact'=>'정책 운영 연락처','translation_of'=>'영문본의 한국어 원본 글 ID'] as $key=>$label) {
            echo '<p><label for="kgpc_'.esc_attr($key).'">'.esc_html($label).'</label><br><input style="width:100%" name="kgpc_'.esc_attr($key).'" id="kgpc_'.esc_attr($key).'" value="'.esc_attr($get($key)).'" maxlength="1500"></p>';
        }
        echo '<p><small>가이드·FAQ는 관리자 A03의 승인된 공식 출처 ID만 연결하세요. 공식 출처에서 기간을 명시하지 않았다면 해당 날짜를 만들어 넣지 마세요. 기준 시점과 확인일은 실제 확인한 날짜만 사용하세요. 정책 본문은 운영자 검토·승인 사항입니다.</small></p>';
    }
    public static function review_box(WP_Post $post): void {
        $status=get_post_meta($post->ID,'_kgpc_review_status',true)?:'draft';
        $active=(int)get_post_meta($post->ID,'_kgpc_active_version',true);
        echo '<p><strong>현재 초안:</strong> '.esc_html($status).'</p><p><strong>공개 승인 버전:</strong> '.($active?:'없음').'</p>';
        if (current_user_can('kgp_edit_entries')) {
            echo '<p>본문과 메타데이터를 먼저 저장한 후, 아래 콘텐츠 관리 화면에서 검토 요청을 진행하세요.</p>';
            echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=kgpc-metadata&post_id='.$post->ID)).'">필수 정보 입력·수정</a></p>';
            echo '<p><a href="'.esc_url(admin_url('admin.php?page=kgpc-console')).'">콘텐츠 관리·검토 요청</a></p>';
        }
        if ($active) echo '<p>공개본은 수정 중에도 유지되며, 재승인 시 새로운 버전으로 교체됩니다.</p>';
    }
    /** External native publish requests never turn WordPress drafts into public posts. */
    public static function prevent_native_publication(array $data,array $postarr): array {
        if (($data['post_type']??'')==='kgpc_article' && in_array($data['post_status']??'', ['publish','future','private'],true)) {
            $data['post_status']='draft';
        }
        return $data;
    }
    public static function save_post(int $id,WP_Post $post,bool $update): void {
        if (wp_is_post_revision($id) || (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)) return;
        $meta=[];
        if (isset($_POST['kgpc_meta_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST['kgpc_meta_nonce'])),'kgpc_meta_save') && current_user_can('kgp_edit_entries')) {
            $keys=['kind','lang','slug','topic','category','audience','basis_date','checked_at','source_ids','source_locator','effective_at','revised_at','contact','translation_of'];
            foreach ($keys as $key) {
                $raw=$_POST['kgpc_'.$key]??'';
                if (!is_scalar($raw)) continue;
                $v=sanitize_text_field(wp_unslash((string)$raw));
                if ($key==='slug') $v=sanitize_title($v);
                if ($key==='kind'&&!in_array($v,self::TYPES,true)) $v='';
                if ($key==='lang'&&!in_array($v,['ko','en'],true)) $v='';
                update_post_meta($id,'_kgpc_'.$key,$v);
            }
            $meta=true;
        }
        if ($meta || ($update && current_user_can('kgp_edit_entries'))) {
            update_post_meta($id,'_kgpc_review_status','draft');
            delete_post_meta($id,'_kgpc_rejection_reason');
        }
    }
    public static function meta(int $id,string $key): string {return (string)get_post_meta($id,'_kgpc_'.$key,true);}
    public static function source_ids(int $id): array {
        $raw=self::meta($id,'source_ids');
        if ($raw==='') return [];
        $parts=array_filter(array_map('trim',explode(',',$raw)),static fn($s)=>$s!=='');
        $ids=[];
        foreach ($parts as $part) {
            if (!ctype_digit($part)|| (int)$part<1) return [];
            $ids[]=(int)$part;
        }
        return array_values(array_unique($ids));
    }
    public static function sources_valid(array $ids): bool {
        if (!self::ready()||!$ids) return false;
        foreach ($ids as $id) {
            if (!is_int($id)||$id<1) return false;
            try {
                if (!KGP_Evidence_Repository::valid_source($id)) return false;
                $src=KGP_DB::require_row('sources',$id);
                if (empty($src['public_url'])||empty($src['document_title'])||empty($src['checked_at'])) return false;
            } catch(Throwable $e) {return false;}
        }
        return true;
    }
}
