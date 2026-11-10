<?php

defined('ABSPATH') || exit;

/** Public read model. Every returned identity is an approved, current snapshot. */
final class KGP_Public_Catalog {
    private static function locale(string $locale): string {
        if (!in_array($locale,['ko','en'],true)) KGP_Validator::fail('locale must be ko or en.');
        return $locale;
    }

    private const SECTION_EN = [
        'DET-01'=>'Admissions and eligibility', 'DET-02'=>'Language requirements',
        'DET-03'=>'Tuition and scholarships', 'DET-04'=>'Majors and academic information',
        'DET-05'=>'Language programs', 'DET-06'=>'Institution overview and recognized evaluations',
        'DET-07'=>'Accreditation and visa restrictions', 'DET-08'=>'Campus and living-cost guidance',
        'DET-09'=>'Dormitories', 'DET-10'=>'Official additional fees',
        'DET-11'=>'International student support', 'DET-12'=>'International student statistics',
        'DET-13'=>'Visa and residence support', 'DET-14'=>'Employment and career',
    ];

    private static function label(array $entity, string $locale): string {
        $en = trim((string)($entity['official_name_en'] ?? ''));
        return $locale === 'en' && $en !== '' ? $en : (string)($entity['official_name_ko'] ?? '');
    }

    private static function eid(string $table, string $public_id): ?int {
        global $wpdb;
        KGP_Validator::id($public_id);
        $t = KGP_DB::table($table);
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE public_id=%s", $public_id));
        return $id ? (int)$id : null;
    }

    public static function university(string $public_id, string $locale = 'ko'): ?array {
        $locale = self::locale($locale);
        $id = self::eid('universities',$public_id);
        if (!$id) return null;
        $u = KGP_Identity_Repository::published('university',$id);
        if (!$u) return null;
        $result = ['university_id'=>$u['public_id'], 'name'=>self::label($u,$locale),
            'official_name_ko'=>$u['official_name_ko'], 'official_name_en'=>$u['official_name_en'],
            'official_website'=>$u['official_website'], 'institution_type'=>$u['institution_type'],
            'official_source'=>$u['identity_evidence'], 'locale'=>$locale, 'offerings'=>[]];
        foreach (self::all_offering_ids($id) as $offering_id) {
            $record = self::build($offering_id,$locale,false);
            if ($record !== null) $result['offerings'][] = self::card($record);
        }
        return $result;
    }

    private static function all_offering_ids(?int $university_id = null): array {
        global $wpdb;
        $o=KGP_DB::table('offerings');$p=KGP_DB::table('programs');
        if ($university_id !== null) {
            $rows = KGP_DB::all($wpdb->prepare("SELECT o.id FROM $o o INNER JOIN $p p ON p.id=o.program_id WHERE p.university_id=%d ORDER BY o.id",$university_id));
        } else {
            $rows = KGP_DB::all("SELECT id FROM $o ORDER BY id");
        }
        return array_map(static fn($row)=>(int)$row['id'],$rows);
    }

    private static function scope_fields(string $type, int $id, string $section, string $locale): array {
        return KGP_Public_Reader::section($type,$id,$section,$locale);
    }

    private static function values(array $items, string $key): array {
        $result=[];
        foreach ($items as $item) if ($item['field']===$key) $result[]=$item['value'];
        return $result;
    }

    private static function known_bool(array $items, string $key): ?bool {
        foreach (self::values($items,$key) as $v) if (is_bool($v)) return $v;
        return null;
    }

    private static function tuition_rows(array $items): array {
        $rows=[];
        foreach (self::values($items,'tuition_items') as $v) {
            if (!is_array($v) || ($v['currency']??'')!=='KRW' || !isset($v['unit'])) continue;
            $min=$v['amount']??$v['min_amount']??null;
            $max=$v['amount']??$v['max_amount']??null;
            if ($min===null || $max===null) continue;
            $rows[]=['min'=>(float)$min,'max'=>(float)$max,'unit'=>(string)$v['unit'],'currency'=>'KRW'];
        }
        return $rows;
    }

    private static function teaching_languages(array $items): array {
        $result=[];
        foreach (self::values($items,'teaching_languages') as $value) {
            if (is_string($value) && !in_array($value,$result,true)) $result[]=$value;
        }
        return $result;
    }

    private static function certification(array $sections, string $level): ?bool {
        $today = gmdate('Y-m-d');
        $matched=false;$positive=false;
        foreach ($sections as $section) foreach ($section as $field) {
            if (!in_array($field['field'],['certification_records','degree_course_certification','language_course_certification'],true)) continue;
            $v=$field['value'];
            if (!is_array($v)) continue;
            $target=(string)($v['course_type']??'');
            if ($target !== '' && $target !== 'all') {
                $degree = $level === 'language' ? 'language' : 'degree';
                if ($target !== $degree && $target !== $level) continue;
            }
            if (!empty($v['valid_from']) && $v['valid_from'] > $today) continue;
            if (!empty($v['valid_to']) && $v['valid_to'] < $today) continue;
            $state=(string)($v['status']??'');
            if (in_array($state,['certified','active','approved'],true)) { $matched=true; $positive=true; }
            if (in_array($state,['not_certified','not_accredited'],true)) { $matched=true; }
        }
        return $matched ? $positive : null; // no record is NOT evidence of non-certification
    }

    private static function admissions(array $intake): array {
        $zone=(string)($intake['timezone_name']?:'Asia/Seoul');
        try { $today=(new DateTimeImmutable('now',new DateTimeZone($zone)))->format('Y-m-d'); }
        catch (Throwable $e) { $today=gmdate('Y-m-d'); }
        $from=$intake['start_date']??null;
        $to=$intake['end_date']??null;
        $override=$intake['recruitment_override']??null;
        $status='unknown';
        if ($override==='cancelled') $status='cancelled';
        elseif ($override==='closed') $status='closed';
        elseif ($to !== null && $today > $to) $status='closed';
        elseif ($from !== null && $today < $from) $status='upcoming';
        elseif ($from !== null && $to !== null && $today >= $from && $today <= $to) $status='open';
        // For precise timestamps, honor exact UTC instant (never invent 23:59 for date-only values).
        $now=gmdate('Y-m-d H:i:s');
        if ($override===null && !empty($intake['starts_at_utc']) && !empty($intake['ends_at_utc'])) {
            if ($now < $intake['starts_at_utc']) $status='upcoming';
            elseif ($now > $intake['ends_at_utc']) $status='closed';
            else $status='open';
        }
        return ['status'=>$status,'start_date'=>$from,'end_date'=>$to,
            'start_precision'=>$intake['start_precision'],'end_precision'=>$intake['end_precision'],
            'timezone'=>$intake['timezone_name'],'starts_at_utc'=>$intake['starts_at_utc'],'ends_at_utc'=>$intake['ends_at_utc']];
    }

    private static function build(int $offering_id, string $locale, bool $details): ?array {
        $offer=KGP_Identity_Repository::published('offering',$offering_id);
        if (!$offer) return null;
        $program_id=(int)$offer['program_id'];$intake_id=(int)$offer['intake_id'];
        $program=KGP_Identity_Repository::published('program',$program_id);
        $intake=KGP_Identity_Repository::published('intake',$intake_id);
        if (!$program || !$intake || (int)$program['university_id'] !== (int)$intake['university_id']) return null;
        $campus_id=(int)$program['campus_id'];
        $campus=KGP_Identity_Repository::published('campus',$campus_id);
        $uni_id=(int)$program['university_id'];
        $university=KGP_Identity_Repository::published('university',$uni_id);
        if (!$campus || !$university || (int)$campus['university_id'] !== $uni_id) return null;
        $fields=[];
        foreach (['university'=>$uni_id,'program'=>$program_id,'offering'=>$offering_id] as $type=>$id) {
            $fields[$type]=[
                'DET-02'=>self::scope_fields($type,$id,'DET-02',$locale),
                'DET-03'=>self::scope_fields($type,$id,'DET-03',$locale),
                'DET-07'=>self::scope_fields($type,$id,'DET-07',$locale),
            ];
        }
        $languageItems=array_merge($fields['program']['DET-02'],$fields['offering']['DET-02']);
        $englishOnly=self::known_bool($fields['offering']['DET-02'],'english_only_program');
        if ($englishOnly===null) $englishOnly=self::known_bool($fields['program']['DET-02'],'english_only_program');
        $allCert=[$fields['university']['DET-07'],$fields['program']['DET-07'],$fields['offering']['DET-07']];
        $card = [
            'offering_id'=>$offer['public_id'], 'university_id'=>$university['public_id'],
            'university_name'=>self::label($university,$locale),
            'university_aliases'=>$university['approved_aliases']??[],
            'search_names'=>array_merge([$university['official_name_ko'],$university['official_name_en'],
                $program['official_name_ko'],$program['official_name_en']],$university['approved_aliases']??[]),
            'campus_name'=>self::label($campus,$locale), 'campus_region'=>$campus['region_code'],
            'program_name'=>self::label($program,$locale), 'field'=>$program['field_code'],
            'level'=>$program['level_code'],'university_internal_id'=>$uni_id,
            'teaching_languages'=>self::teaching_languages($languageItems),
            'english_only'=>$englishOnly,
            'tuition'=>self::tuition_rows($fields['offering']['DET-03']),
            'certified'=>self::certification($allCert,$program['level_code']),
            'academic_year'=>(int)$intake['academic_year'],'academic_term'=>$intake['academic_term'],
            'admission'=>self::admissions($intake),'locale'=>$locale,
        ];
        if ($details) {
            $scopes=['university'=>$uni_id,'campus'=>$campus_id,'program'=>$program_id,'intake'=>$intake_id,'offering'=>$offering_id];
            $sections=[];
            foreach (KGP_Field_Catalog::sections() as $section=>$meta) {
                $available=[];
                foreach ($scopes as $type=>$id) {
                    $values=self::scope_fields($type,$id,$section,$locale);
                    if ($values) $available[]=['scope'=>$type,'fields'=>$values];
                }
                if ($available) $sections[]=['id'=>$section,'title'=>$locale==='en'?(self::SECTION_EN[$section]??$meta['title']):$meta['title'],'groups'=>$available];
            }
            $card['university']=['name'=>self::label($university,$locale),'official_website'=>$university['official_website'],'source'=>$university['identity_evidence']];
            $card['campus']=['name'=>self::label($campus,$locale),'source'=>$campus['identity_evidence']];
            $card['program']=['name'=>self::label($program,$locale),'department_url'=>$program['department_url'],'source'=>$program['identity_evidence']];
            $card['intake']=['academic_year'=>$intake['academic_year'],'academic_term'=>$intake['academic_term'],'admission_type'=>$intake['admission_type'],'application_url'=>$intake['application_url'],'source'=>$intake['identity_evidence']];
            $card['sections']=$sections;
        }
        return $card;
    }

    private static function card(array $record): array {
        unset($record['university_internal_id'],$record['university_aliases'],$record['search_names']);
        return $record;
    }

    public static function offering(string $public_id, string $locale='ko'): ?array {
        $id=self::eid('offerings',$public_id);
        return $id ? self::card_or_detail($id,self::locale($locale)) : null;
    }
    private static function card_or_detail(int $id, string $locale): ?array {
        $row=self::build($id,$locale,true);
        return $row===null ? null : self::card($row);
    }

    /** Search is evaluated from current approved evidence, never private metadata or a stale cache. */
    public static function search(array $query, bool $include_unknown_deadline = false): array {
        $locale=self::locale((string)($query['locale']??'ko'));
        $page=filter_var($query['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if ($page===false) KGP_Validator::fail('page must be a positive integer.');
        $sort=(string)($query['sort']??'name');
        if (!in_array($sort,['name','tuition','deadline'],true)) KGP_Validator::fail('Invalid sorting.');
        $single=[];$filterKeys=['region','field','level','teaching_lang','admission_status'];
        foreach ($filterKeys as $key) {
            $raw=$query[$key]??'';
            if (is_array($raw)) $raw=implode(',',$raw);
            if (!is_string($raw)) KGP_Validator::fail('Invalid filter value.');
            $list=$raw===''?[]:array_values(array_filter(array_unique(array_map('trim',explode(',',$raw))),static fn($s)=>$s!==''));
            if (count($list)>20) KGP_Validator::fail('Too many filter values.');
            foreach ($list as $item) if (!preg_match('/^[a-zA-Z0-9_-]{1,48}$/D',$item)) KGP_Validator::fail('Invalid filter code.');
            $single[$key]=$list;
        }
        $unit=(string)($query['cost_unit']??'');
        if ($unit!=='' && !in_array($unit,['semester','month','year','one_time','program_total','term','other'],true)) KGP_Validator::fail('Invalid cost unit.');
        $min=array_key_exists('tuition_min',$query)&&$query['tuition_min']!=='' ? (float)KGP_Validator::decimal($query['tuition_min'],'tuition_min') : null;
        $max=array_key_exists('tuition_max',$query)&&$query['tuition_max']!=='' ? (float)KGP_Validator::decimal($query['tuition_max'],'tuition_max') : null;
        if ($min!==null && $max!==null && $min>$max) KGP_Validator::fail('tuition_min is greater than tuition_max.');
        if (($min!==null || $max!==null) && $unit==='') KGP_Validator::fail('cost_unit is required for a tuition range.');
        $english=self::parse_bool($query,'english_only');
        $cert=self::parse_bool($query,'certified');
        if ($sort==='tuition' && ($unit==='' || count($single['level'])!==1)) KGP_Validator::fail('Tuition sorting requires one level and a cost_unit.');
        if (!is_string($query['q']??'')) KGP_Validator::fail('Search text must be a string.');
        $q=trim((string)($query['q']??''));
        if ((function_exists('mb_strlen')?mb_strlen($q):strlen($q))>120) KGP_Validator::fail('Search query too long.');
        $found=[];
        foreach (self::all_offering_ids() as $id) {
            $row=self::build($id,$locale,false);
            if (!$row) continue;
            if ($q!=='') {
                $names=$row['search_names'];
                $match=false;
                foreach ($names as $name) if ((function_exists('mb_stripos')?mb_stripos((string)$name,$q):stripos((string)$name,$q))!==false) { $match=true;break; }
                if (!$match) continue;
            }
            $checks=['region'=>$row['campus_region'],'field'=>$row['field'],'level'=>$row['level'], 'admission_status'=>$row['admission']['status']];
            $rejected=false;
            foreach ($checks as $key=>$actual) if ($single[$key] && !in_array($actual,$single[$key],true)) {$rejected=true;break;}
            if ($rejected) continue;
            if ($single['teaching_lang'] && !array_intersect($single['teaching_lang'],$row['teaching_languages'])) continue;
            if ($english!==null && $row['english_only']!==$english) continue;
            if ($cert!==null && $row['certified']!==$cert) continue;
            if ($min!==null || $max!==null || $unit!=='') {
                $match=false;
                foreach ($row['tuition'] as $cost) {
                    if ($unit!=='' && $cost['unit']!==$unit) continue;
                    if ($min!==null && $cost['min']<$min) continue;
                    if ($max!==null && $cost['max']>$max) continue;
                    $match=true; break;
                }
                if (!$match) continue;
            }
            if ($sort==='tuition') {
                $costs=array_values(array_filter($row['tuition'],static fn($v)=>$v['unit']===$unit));
                if (!$costs) continue;
                $row['_sort_cost']=min(array_column($costs,'min'));
            }
            if ($sort==='deadline' && !$include_unknown_deadline && !$row['admission']['end_date'] && !$row['admission']['ends_at_utc']) continue;
            $found[]=$row;
        }
        usort($found,static function ($a,$b) use ($sort) {
            if ($sort==='tuition') $cmp=$a['_sort_cost']<=>$b['_sort_cost'];
            elseif ($sort==='deadline') {
                $da=$a['admission']['ends_at_utc'] ?: $a['admission']['end_date'];
                $db=$b['admission']['ends_at_utc'] ?: $b['admission']['end_date'];
                $cmp=($da===null?1:0)<=>($db===null?1:0);
                if ($cmp===0 && $da!==null) $cmp=strcmp((string)$da,(string)$db);
            }
            else $cmp=strnatcasecmp($a['university_name'],$b['university_name']);
            return $cmp!==0?$cmp:strcmp($a['offering_id'],$b['offering_id']);
        });
        $total=count($found);$perPage=12;
        $slice=array_slice($found,($page-1)*$perPage,$perPage);
        return ['items'=>array_map([self::class,'card'],$slice),'total'=>$total,'page'=>(int)$page,
            'per_page'=>$perPage,'total_pages'=>(int)ceil($total/$perPage),'locale'=>$locale];
    }

    private static function parse_bool(array $q,string $key): ?bool {
        if (!array_key_exists($key,$q) || $q[$key]==='') return null;
        if (in_array($q[$key],[true,1,'1','true'],true)) return true;
        if (in_array($q[$key],[false,0,'0','false'],true)) return false;
        KGP_Validator::fail($key.' must be true or false.');
    }

    public static function admissions_list(array $query): array {
        $locale=self::locale((string)($query['locale']??'ko'));
        $q=['locale'=>$locale,'region'=>$query['region']??'', 'level'=>$query['level']??'',
            'admission_status'=>$query['admission_status']??'', 'field'=>$query['field']??'', 'page'=>$query['page']??1, 'sort'=>'deadline'];
        // Admissions contain approved offerings with known deadlines; no inferred deadline times.
        $result=self::search($q,true);
        return $result;
    }
}
