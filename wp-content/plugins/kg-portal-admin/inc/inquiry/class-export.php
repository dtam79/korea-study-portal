<?php
defined('ABSPATH') || exit;

final class KGP_Operator_Inquiry_Export {
    /** Stop spreadsheet formula injection even when CSV values begin with whitespace. */
    public static function cell($value): string {
        $text=(string)($value??'');
        // Treat leading Unicode separators, BOM and zero-width format marks as
        // ignorable: spreadsheet apps may discard them before parsing formulas.
        // Keep original text unchanged after the apostrophe escape.
        if (preg_match('/^[\x00-\x20\p{Z}\x{FEFF}\x{200B}-\x{200F}\x{2060}]*[=+\-@]/u', $text)) return "'".$text;
        return $text;
    }
    public static function export(): void {
        if (!current_user_can('kgp_manage_inquiries')) wp_die('Permission denied.','', ['response'=>403]);
        check_admin_referer('kgpa_inquiry_export');
        $input=($_SERVER['REQUEST_METHOD']??'GET')==='POST' ? $_POST : $_GET;
        $status=isset($input['status']) && is_scalar($input['status']) ? sanitize_key(wp_unslash((string)$input['status'])) : '';
        $q=isset($input['q']) && is_scalar($input['q']) ? sanitize_text_field(wp_unslash((string)$input['q'])) : '';
        $language=isset($input['language']) && is_scalar($input['language']) ? sanitize_key(wp_unslash((string)$input['language'])) : '';
        $assignee=isset($input['assignee']) && is_scalar($input['assignee']) ? sanitize_text_field(wp_unslash((string)$input['assignee'])) : '';
        try {KGP_Operator_Inquiries::list_filters(['language'=>$language,'assignee'=>$assignee]);}
        catch (InvalidArgumentException $e) {wp_die('Invalid export filters.','',['response'=>422]);}
        if (!in_array($status,['','new','in_progress','completed'],true)) wp_die('Invalid status.');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="kgp-inquiries-'.gmdate('Ymd-His').'.csv"');
        header('X-Content-Type-Options: nosniff');
        $stream=fopen('php://output','wb');
        fwrite($stream,"\xEF\xBB\xBF");
        // Export only necessary metadata; no message body, notes or consent contents.
        $cols=['receipt_code','email','inquiry_locale','handling_status','assigned_to','university_id',
            'program_id','preferred_intake','email_delivery_status','created_at','delete_after'];
        fputcsv($stream,$cols);
        for ($page=1;$page<=10000;$page++) {
            $result=KGP_Operator_Inquiries::list(['status'=>$status,'q'=>$q,'language'=>$language,'assignee'=>$assignee,'page'=>$page,'per_page'=>100]);
            foreach ($result['items'] as $row) {
                // Additional fields such as preferred_intake require the detail record.
                $detail=KGP_Operator_Inquiries::detail((int)$row['id'])['inquiry'];
                fputcsv($stream,array_map(static fn($key)=>self::cell($detail[$key]??''),$cols));
            }
            if ($page >= $result['total_pages']) break;
        }
        fclose($stream); exit;
    }
}
