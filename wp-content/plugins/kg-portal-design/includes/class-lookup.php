<?php
/** Private, read-only relationship search. Mutations remain in Core. */
defined('ABSPATH') || exit;
final class KGDS_Lookup {
    private const TABLES=['university'=>'universities','campus'=>'campuses','program'=>'programs','intake'=>'intakes','offering'=>'offerings'];
    public static function permitted($r): bool {
        if (!is_user_logged_in() || !class_exists('KGP_DB')) return false;
        $type=(string)$r->get_param('type');
        $caps=match($type){
            'user'=>['kgp_manage_assignments','manage_options'],
            'source','issuer'=>['kgp_edit_entries','kgp_review_entries','kgp_verify_sources','kgp_manage_issuers'],
            default=>['kgp_edit_entries','kgp_review_entries','kgp_manage_assignments'],
        };
        foreach($caps as $cap) if(current_user_can($cap))return true;
        return false;
    }
    public static function register(): void {
        register_rest_route('kgds/v1','/lookup',['methods'=>'GET','permission_callback'=>[self::class,'permitted'],'callback'=>[self::class,'search']]);
    }
    /** Canonical identity projection used in entity and field lookups. */
    private static function entities(string $type): string {
        $u=KGP_DB::table('universities');$p=KGP_DB::table('programs');$i=KGP_DB::table('intakes');
        $t=KGP_DB::table(self::TABLES[$type]);
        return match($type){
            'university'=>"SELECT e.id,e.official_name_ko AS label,CONCAT(e.official_name_en,' · ',e.public_id) AS detail,e.id AS university_id,0 AS campus_id,0 AS program_id FROM $t e",
            'campus','program'=>"SELECT e.id,e.official_name_ko AS label,CONCAT(u.official_name_ko,' · ',COALESCE(e.official_name_en,''),' · ',e.public_id) AS detail,e.university_id,".($type==='program'?'e.campus_id':'e.id')." AS campus_id,".($type==='program'?'e.id':'0')." AS program_id FROM $t e JOIN $u u ON u.id=e.university_id",
            'intake'=>"SELECT e.id,CONCAT(e.academic_year,' · ',e.academic_term,' · ',e.admission_type) AS label,CONCAT(u.official_name_ko,' · ',e.public_id) AS detail,e.university_id,0 AS campus_id,0 AS program_id FROM $t e JOIN $u u ON u.id=e.university_id",
            'offering'=>"SELECT e.id,CONCAT(p.official_name_ko,' · ',i.academic_year,' ',i.academic_term,' · ',e.variant_code) AS label,CONCAT(u.official_name_ko,' · ',i.admission_type,' · ',e.public_id) AS detail,p.university_id,p.campus_id,e.program_id FROM $t e JOIN $p p ON p.id=e.program_id JOIN $i i ON i.id=e.intake_id JOIN $u u ON u.id=p.university_id",
        };
    }
    public static function search($r) {
        global $wpdb;
        try {
            $type=(string)$r->get_param('type');$q=$r->get_param('q')??'';
            if(!is_string($q)||(function_exists('mb_strlen')?mb_strlen($q):strlen($q))>120)throw new InvalidArgumentException('검색어를 120자 이내로 입력해 주세요.');
            $page=max(1,min(10000,absint($r->get_param('page')??1)));$ids=$r->get_param('ids');
            $where=[];$scoped=false;
            if(isset(self::TABLES[$type])){$sql=self::entities($type);$scoped=true;}
            elseif($type==='entry'){
                $parts=[]; $f=KGP_DB::table('field_entries');
                foreach(array_keys(self::TABLES) as $kind){$e=self::entities($kind);$parts[]="SELECT f.id,CONCAT(e.label,' · ',f.section_code,' / ',f.field_key) AS label,CONCAT(e.detail,' · ',f.item_key) AS detail,e.university_id,e.campus_id,e.program_id FROM $f f JOIN ($e) e ON e.id=f.entity_id WHERE f.entity_type='$kind'";}
                $sql=implode(' UNION ALL ',$parts);$scoped=true;
            } elseif($type==='source'){
                $s=KGP_DB::table('sources');$i=KGP_DB::table('issuers');
                $sql="SELECT s.id,s.document_title AS label,CONCAT(i.official_name,' · ',s.evidence_status,' · ',COALESCE(s.checked_at,'미확인')) AS detail FROM $s s JOIN $i i ON i.id=s.issuer_id";
            } elseif($type==='issuer'){
                $t=KGP_DB::table('issuers');$sql="SELECT id,official_name AS label,CONCAT(COALESCE(official_domain,''),' · ',approval_status) AS detail FROM $t";
            } elseif($type==='article'){
                $sql="SELECT ID AS id,post_title AS label,CONCAT(post_status,' · ',post_date) AS detail FROM {$wpdb->posts} WHERE post_type='kgpc_article' AND post_status NOT IN ('trash','auto-draft')";
                $sql.=$wpdb->prepare(" AND ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_kgpc_lang' AND meta_value=%s)",'ko');
            } elseif($type==='user'){
                $sql="SELECT ID AS id,display_name AS label,user_login AS detail FROM {$wpdb->users}";
            } else throw new InvalidArgumentException('지원하지 않는 항목입니다.');
            if($scoped){
                if(!current_user_can('manage_options')&&!current_user_can('kgp_review_entries')&&!current_user_can('kgp_manage_assignments')){
                    $a=KGP_DB::table('user_assignments');$where[]=$wpdb->prepare("EXISTS (SELECT 1 FROM $a a WHERE a.university_id=x.university_id AND a.user_id=%d AND a.role_code='editor')",get_current_user_id());
                }
                foreach(['university_id','campus_id','program_id'] as $parent){if($r->get_param($parent))$where[]=$wpdb->prepare("x.$parent=%d",absint($r->get_param($parent)));}
            }
            if($ids!==null){
                if(!is_string($ids)||!preg_match('/^\d+(,\d+){0,49}$/D',$ids))throw new InvalidArgumentException('잘못된 선택값입니다.');
                $nums=array_map('absint',explode(',',$ids));$where[]='x.id IN ('.implode(',',$nums).')';
            }elseif(trim($q)!==''){
                $like='%'.$wpdb->esc_like(trim($q)).'%';$where[]=$wpdb->prepare('(x.label LIKE %s OR x.detail LIKE %s OR x.id=%d)',$like,$like,absint($q));
            }
            $condition=$where?' WHERE '.implode(' AND ',$where):'';
            $limit=$ids!==null?50:21;$offset=$ids!==null?0:($page-1)*20;
            $rows=$wpdb->get_results($wpdb->prepare("SELECT x.* FROM ($sql) x $condition ORDER BY x.id DESC LIMIT %d OFFSET %d",$limit,$offset),ARRAY_A);
            if($wpdb->last_error)throw new RuntimeException('lookup query failed');
            $more=$ids===null&&count($rows)>20;if($more)array_pop($rows);
            foreach($rows as &$row){
                $row['id']=(int)$row['id'];$row['selectable']=true;
                if($type==='source'){
                    $s=KGP_DB::require_row('sources',$row['id']);
                    $row['selectable']=KGP_Evidence_Repository::valid_source($row['id'])&&!empty($s['checked_at'])&&!empty($s['document_title']);
                    if(!$row['selectable'])$row['detail'].=' · 검증·확인일 확인 필요';
                }
            }
            unset($row);
            $response=rest_ensure_response(['items'=>$rows,'has_more'=>$more,'page'=>$page]);
            $response->header('Cache-Control','no-store');return $response;
        }catch(InvalidArgumentException $e){return new WP_Error('kgds_invalid',$e->getMessage(),['status'=>422]);}
        catch(Throwable $e){error_log('[KGDS lookup] '.$e->getMessage());return new WP_Error('kgds_lookup_failed','목록을 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.',['status'=>500]);}
    }
}
add_action('rest_api_init',['KGDS_Lookup','register']);
