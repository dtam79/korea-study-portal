<?php
defined('ABSPATH') || exit;
final class KGPO_Policy {
    public const OPTION='kgpo_settings';
    public const CATEGORIES=[
        'admissions'=>['Admissions schedules',7],
        'fees'=>['Tuition / scholarships / language criteria',30],
        'university'=>['University profile / location',90],
        'certification'=>['Certification / accreditation',30],
        'visa'=>['Visas / residency rules',90],
        'statistics'=>['Official annual disclosures',90],
        'other'=>['Other official source',90],
    ];
    public static function get(): array {
        $saved=get_option(self::OPTION,[]);
        if (!is_array($saved))$saved=[];
        return array_merge(['monitor_enabled'=>false,'strict_no_cache'=>true,'batch_size'=>15,
            'fetch_timeout'=>7,'max_sample_bytes'=>131072,'notifications_email'=>'',
            'last_run_at'=>null,'last_run_result'=>null],$saved);
    }
    public static function update(array $input): array {
        if (!current_user_can('kgp_manage_assignments')) throw new DomainException('Portal manager capability required.');
        $allowed=['monitor_enabled','strict_no_cache','batch_size','fetch_timeout','max_sample_bytes','notifications_email'];
        if(array_diff(array_keys($input),$allowed))throw new InvalidArgumentException('Unknown setting.');
        $settings=self::get();
        foreach ($input as $key=>$value) {
            if (in_array($key,['monitor_enabled','strict_no_cache'],true)){
                if(!is_bool($value))throw new InvalidArgumentException($key.' must be boolean.');
            }elseif ($key==='notifications_email'){
                if(!is_string($value)||strlen($value)>254||($value!==''&&!is_email($value))) throw new InvalidArgumentException('Invalid notification address.');
            }else{
                $n=filter_var($value,FILTER_VALIDATE_INT);
                $bounds=match($key){'batch_size'=>[1,25],'fetch_timeout'=>[3,12],default=>[16384,262144]};
                if($n===false || $n<$bounds[0]||$n>$bounds[1])throw new InvalidArgumentException('Out-of-range setting: '.$key);
                $value=(int)$n;
            }
            $settings[$key]=$value;
        }
        update_option(self::OPTION,$settings,false);
        return $settings;
    }
    public static function core_ready(): bool {
        return class_exists('KGP_DB') && class_exists('KGP_Evidence_Repository') && class_exists('KGP_Identity_Repository');
    }
    public static function can_manage(): bool {return current_user_can('kgp_manage_assignments');}
    public static function can_review(): bool {return current_user_can('kgp_review_entries') || self::can_manage();}
    public static function can_view(): bool {return self::can_review();}
    public static function utc(): string {return gmdate('Y-m-d H:i:s');}
    public static function next_at(int $days): string {return gmdate('Y-m-d H:i:s',time()+$days*DAY_IN_SECONDS);}
}
