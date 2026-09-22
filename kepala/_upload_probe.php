<?php
$_a = $_SERVER['HTTP_HOST'] ?? 'localhost';
require '../config/database.php';
require '../config/functions.php';
require '../config/supervisi.php';
function norm($f): array {
    $out = [];
    if (!is_array($f) || !isset($f['name'])) return $out;
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $name) {
            if ($name === '' || (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name'=>$name,'type'=>$f['type'][$i]??'','tmp_name'=>$f['tmp_name'][$i]??'','error'=>$f['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$f['size'][$i]??0];
        }
    } else {
        if ($f['name'] !== '' && (int)$f['error'] !== UPLOAD_ERR_NO_FILE) $out[] = $f;
    }
    return $out;
}
header('Content-Type: application/json');
$files = norm($_FILES['file'] ?? null);
$res = [];
foreach ($files as $f) { $res[] = sv_handle_upload($f, 'probe', 'PROBE'); }
echo json_encode(['count'=>count($files), 'results'=>$res], JSON_PRETTY_PRINT);
