<?php
$file = __DIR__ . '/admin/data_nilai_ujian.php';
$lines = file($file);

// Fix line 178: Move session_type_js declaration before if statement
// Find and fix the declaration
for ($i = 0; $i < count($lines); $i++) {
    // Fix line with session_type_js declaration (should be before if)
    if (trim($lines[$i]) == '$session_type_js = $_SESSION[\'level\'] ?? \'admin\';' && 
        trim($lines[$i+1]) == 'if (!isset($js_page)) {') {
        // Move it before the if
        $temp = $lines[$i];
        $lines[$i] = $lines[$i+1];
        $lines[$i+1] = $temp;
    }
    
    // Fix line 238: Excel export - change "$session_type_js" to proper PHP output
    if (strpos($lines[$i], 'f.action = \'../config/excel_export.php?session_type=\' + "$session_type_js";') !== false) {
        $lines[$i] = "    f.action = '../config/excel_export.php?session_type=' . \"'\" + \$session_type_js . \"'\";\n";
    }
    
    // Fix line 272: PDF export
    if (strpos($lines[$i], 'f.action = \'../config/pdf_export.php?session_type=\' + "$session_type_js";') !== false) {
        $lines[$i] = "    f.action = '../config/pdf_export.php?session_type=' . \"'\" + \$session_type_js . \"'\";\n";
    }
}

file_put_contents($file, implode('', $lines));
echo "Fixed!\n";
