<?php
defined('ABSPATH') || exit;

final class KGP_Operator_Inquiry_Routes {
    private const NS='kgp-operator/v1';
    private static function route(string $path,string $method,callable $handler,string $cap): void {
        register_rest_route(self::NS,$path,[
            'methods'=>$method,
            'permission_callback'=>static fn()=>is_user_logged_in() && KGP_Operator_Admin::ready() && current_user_can($cap),
            'callback'=>static function(WP_REST_Request $request)use($handler){
                try {
                    if (get_option('kgpa_inquiry_schema_version')!==KGP_Operator_Inquiry_Schema::VERSION) {
                        return new WP_Error('kgpa_schema_required','Install the inquiry schema first.',['status'=>503]);
                    }
                    return rest_ensure_response($handler($request));
                } catch(KGP_Domain_Exception $e) {
                    return new WP_Error('kgpa_'.$e->slug(),$e->getMessage(),['status'=>$e->slug()==='forbidden'?403:($e->slug()==='not_found'?404:422)]);
                } catch(InvalidArgumentException $e) {
                    return new WP_Error('kgpa_validation',$e->getMessage(),['status'=>422]);
                } catch(Throwable $e) {
                    error_log('[KGPA Inquiry REST] '.$e->getMessage());
                    return new WP_Error('kgpa_internal_error','Inquiry operation failed.',['status'=>500]);
                }
            },
        ]);
    }
    private static function json(WP_REST_Request $r): array {
        $input=$r->get_json_params();
        if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Expected JSON object.');
        return $input;
    }
    private static function id(WP_REST_Request $r): int {
        $id=filter_var($r['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false) throw new InvalidArgumentException('Invalid inquiry ID.');
        return (int)$id;
    }
    public static function register(): void {
        self::route('/inquiries','GET',static fn($r)=>KGP_Operator_Inquiries::list($r->get_params()),'kgp_manage_inquiries');
        self::route('/inquiries/(?P<id>\d+)','GET',static fn($r)=>KGP_Operator_Inquiries::detail(self::id($r)),'kgp_manage_inquiries');
        self::route('/inquiries/(?P<id>\d+)/update','POST',static fn($r)=>KGP_Operator_Inquiries::update(self::id($r),self::json($r)),'kgp_manage_inquiries');
        self::route('/inquiries/(?P<id>\d+)/note','POST',static function($r){$b=self::json($r);
            return KGP_Operator_Inquiries::note(self::id($r),(string)($b['note']??''));
        },'kgp_manage_inquiries');
        self::route('/inquiries/(?P<id>\d+)/erase','POST',static function($r){$b=self::json($r);
            if (($b['confirm']??null)!=='DELETE') throw new InvalidArgumentException('Confirm permanent deletion by entering DELETE.');
            return ['erased'=>KGP_Operator_Inquiries::erase(self::id($r),'operator_request',get_current_user_id())];
        },'kgp_manage_inquiries');
        self::route('/inquiry-deletions','GET',static fn($r)=>KGP_Operator_Inquiries::deletion_log(max(1,(int)($r->get_param('page')??1))),'kgp_manage_inquiries');
        self::route('/inquiry-settings','GET',static fn()=>KGP_Operator_Inquiry_Settings::summary(),'kgp_manage_assignments');
        self::route('/inquiry-settings','POST',static fn($r)=>['settings'=>KGP_Operator_Inquiry_Settings::update(self::json($r))],'kgp_manage_assignments');
    }
}
