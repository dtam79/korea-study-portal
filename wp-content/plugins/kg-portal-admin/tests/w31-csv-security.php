<?php
/** W3-1: exported spreadsheet cells must remain literal, even after Unicode trimming. */
define('ABSPATH', '/');
require dirname(__DIR__).'/inc/inquiry/class-export.php';
$dangerous = [
    '=HYPERLINK("https://bad.example")', '+SUM(1,2)', '-1+2', '@SUM(1,2)',
    " \t\r=2+3", "\xEF\xBB\xBF=1+1", "\u{00A0}=1+1",
    "\u{202F}+A1", "\u{200B}=1+1", "\u{200D}-1", "\u{2060}@SUM(1)",
];
foreach($dangerous as $value) {
    $sanitized=KGP_Operator_Inquiry_Export::cell($value);
    if($sanitized!=="'".$value)throw new RuntimeException('Spreadsheet formula cell not escaped: '.bin2hex($value));
}
foreach(['', 'university@example.edu', 'Normal text =2+2', "\u{200B}okay", '0', '1024'] as $safe) {
    if(KGP_Operator_Inquiry_Export::cell($safe)!==$safe)throw new RuntimeException('Harmless CSV text was modified.');
}
echo "PASS: W3-1 CSV export blocks hidden Unicode formula prefixes; harmless text preserved.\n";
