<?php
defined('ABSPATH') || exit;

if (!function_exists('kgpa_inquiry_strlen')) {function kgpa_inquiry_strlen(string $text): int {return function_exists('mb_strlen')?mb_strlen($text):strlen($text);}}

/** Operator-owned, explicitly approved inquiry policy. No fabricated default retention. */
final class KGP_Operator_Inquiry_Settings {
    public const OPTION = 'kgpa_inquiry_settings';
    public static function get(): array {
        return array_merge([
            'intake_enabled'=>false, 'policy_version'=>'', 'retention_days'=>0,
            'purpose_ko'=>'', 'purpose_en'=>'', 'notice_ko'=>'', 'notice_en'=>'',
            'deletion_contact'=>'', 'notify_email'=>'', 'max_submissions_per_hour'=>5,
        ], (array)get_option(self::OPTION, []));
    }
    public static function ready(?array $s=null): bool {
        $s=$s ?? self::get();
        return !empty($s['intake_enabled']) && self::valid_configuration($s);
    }
    public static function valid_configuration(array $s): bool {
        if (!is_string($s['policy_version']??null) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $s['policy_version'])) return false;
        if (!is_int($s['retention_days']??null) || $s['retention_days']<1 || $s['retention_days']>3650) return false;
        foreach (['purpose_ko','purpose_en','notice_ko','notice_en'] as $key) {
            $v=$s[$key]??null;
            if (!is_string($v) || kgpa_inquiry_strlen(trim($v))<10 || kgpa_inquiry_strlen($v)>8000) return false;
        }
        foreach (['deletion_contact','notify_email'] as $key) {
            if (!is_string($s[$key]??null) || !is_email($s[$key]) || strlen($s[$key])>254) return false;
        }
        $rate=$s['max_submissions_per_hour']??0;
        return is_int($rate) && $rate>=1 && $rate<=20;
    }
    public static function update(array $input): array {
        if (!current_user_can('kgp_manage_assignments')) throw new KGP_Domain_Exception('forbidden','Portal manager permission required.');
        $old=self::get();
        $allowed=['policy_version','retention_days','purpose_ko','purpose_en','notice_ko','notice_en','deletion_contact','notify_email','max_submissions_per_hour','intake_enabled'];
        if (array_diff(array_keys($input),$allowed)) throw new InvalidArgumentException('Unknown settings key.');
        $new=$old;
        foreach ($input as $key=>$value) {
            if (in_array($key,['retention_days','max_submissions_per_hour'],true)) {
                $new[$key]=filter_var($value,FILTER_VALIDATE_INT);
            } elseif ($key==='intake_enabled') {
                if (!in_array($value,[true,false,1,0],true)) throw new InvalidArgumentException('intake_enabled must be a boolean.');
                $new[$key]=(bool)$value;
            } else {
                if (!is_string($value)) throw new InvalidArgumentException($key.' must be text.');
                $new[$key]=trim(wp_strip_all_tags($value));
            }
        }
        if ($new['intake_enabled'] && !self::valid_configuration($new)) {
            throw new InvalidArgumentException('Cannot accept real inquiries until a complete purpose, bilingual notice, retention period, policy version and valid contact emails are set.');
        }
        update_option(self::OPTION,$new,false);
        if (class_exists('KGP_DB')) KGP_DB::audit('inquiry.settings_updated','settings',0,null,[
            'changed_keys'=>array_keys($input),'intake_enabled'=>$new['intake_enabled'],
            'retention_days'=>$new['retention_days'],'policy_version'=>$new['policy_version'],
        ]);
        return $new;
    }
    public static function summary(): array {
        $s=self::get();
        return ['settings'=>$s,'ready'=>self::ready($s),'configured'=>self::valid_configuration($s),
            'cron_next'=>wp_next_scheduled('kgpa_inquiry_retention_cleanup')];
    }
}
