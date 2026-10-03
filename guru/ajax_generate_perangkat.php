<?php
// Endpoint Generate Perangkat Pembelajaran AI (pola sama seperti ajax_generate_soal.php):
// generate (form + upload materi), simpan ke tb_perangkat_pembelajaran,
// detail (JSON 1 perangkat), unduh_paket (PDF/DOCX/XLSX), unduh (dari preview).
ob_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/ai_helper.php';

ensure_learning_schema($pdo);
ai_helper_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Akses ditolak.']);
    exit;
}

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}
if ($guru_id <= 0) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Identitas guru tidak ditemukan.']);
    exit;
}

$aksi = $_POST['aksi'] ?? $_GET['aksi'] ?? null;
if ($aksi === null) {
    $raw_aksi = file_get_contents('php://input');
    $j_aksi = json_decode((string)$raw_aksi, true);
    if (is_array($j_aksi) && isset($j_aksi['aksi'])) {
        $aksi = $j_aksi['aksi'];
    }
}
if (!is_string($aksi) || $aksi === '') {
    $aksi = 'generate';
}

// Ambil teks materi dari upload Word (.docx)/TXT + textarea manual (maks 2MB, potong 8000 karakter).
function ai_perangkat_extract_materi(): array {
    $manual = trim((string)($_POST['materi'] ?? ''));
    $from_file = '';
    if (!empty($_FILES['materi_file']) && (int)$_FILES['materi_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = (string)$_FILES['materi_file']['tmp_name'];
        $name = strtolower((string)$_FILES['materi_file']['name']);
        $size = (int)$_FILES['materi_file']['size'];
        if ($size > 2 * 1024 * 1024) {
            return ['', 'Ukuran file materi maksimal 2 MB.'];
        }
        if (substr($name, -4) === '.txt') {
            $from_file = trim((string)@file_get_contents($tmp));
        } elseif (substr($name, -5) === '.docx') {
            if (!class_exists('ZipArchive')) {
                return ['', 'Ekstensi ZipArchive tidak aktif, tidak bisa membaca .docx.'];
            }
            $zip = new ZipArchive();
            if ($zip->open($tmp) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml !== false) {
                    $xml = preg_replace('/<w:p[^>]*>/i', "\n", (string)$xml);
                    $xml = preg_replace('/<[^>]+>/', ' ', $xml);
                    $from_file = trim(html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    $from_file = preg_replace("/[ \t]+/", ' ', $from_file);
                }
            }
            if ($from_file === '') {
                return ['', 'Gagal membaca file .docx.'];
            }
        } else {
            return ['', 'Format file materi harus .docx atau .txt.'];
        }
    }
    $gabung = trim($manual . ($from_file !== '' ? "\n\n" . $from_file : ''));
    if (mb_strlen($gabung) > 8000) {
        $gabung = mb_substr($gabung, 0, 8000);
    }
    return [$gabung, ''];
}

function ai_perangkat_nama_mapel_kelas(PDO $pdo, $id_mapel, $id_kelas): array {
    $mapel = '-';
    $kelas = '-';
    if ((int)$id_mapel > 0) {
        $st = $pdo->prepare("SELECT nama_mapel FROM tb_mata_pelajaran WHERE id_mapel = ? LIMIT 1");
        $st->execute([(int)$id_mapel]);
        $mapel = $st->fetchColumn() ?: '-';
    }
    if ((int)$id_kelas > 0) {
        $st = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ? LIMIT 1");
        $st->execute([(int)$id_kelas]);
        $kelas = $st->fetchColumn() ?: '-';
    }
    return [$mapel, $kelas];
}

$jenis_allow = [
    'CP/TP',
    'ATP',
    'Modul Ajar',
    'RPP',
    'Silabus',
    'Program Tahunan (Prota)',
    'Program Semester (Promes)',
    'Kriteria Ketercapaian (KKTP)',
    'LKPD (Lembar Kerja Peserta Didik)',
    'PPT (Slide Show) Materi Pembelajaran',
    'Materi Kokurikuler',
    'Lainnya'
];

if ($aksi === 'generate') {
    $jenis = trim((string)($_POST['jenis_perangkat'] ?? 'Modul Ajar'));
    if (!in_array($jenis, $jenis_allow, true)) {
        $jenis = 'Modul Ajar';
    }
    $kurikulum = in_array($_POST['kurikulum'] ?? '', ['PERMENDIKDASMEN_046', 'KMA_1503_KBC'], true) ? $_POST['kurikulum'] : 'PERMENDIKDASMEN_046';
    [$materi, $err] = ai_perangkat_extract_materi();
    if ($err !== '') {
        echo json_encode(['ok' => false, 'msg' => $err]);
        exit;
    }
    $id_mapel = (int)($_POST['id_mapel'] ?? 0);
    $id_kelas = (int)($_POST['id_kelas'] ?? 0);
    $semester = trim((string)($_POST['semester'] ?? ''));
    if (!in_array($semester, ['Semester 1', 'Semester 2'], true)) {
        $semester = $semester !== '' ? mb_substr($semester, 0, 20) : '';
    }
    $tahun_ajaran = trim((string)($_POST['tahun_ajaran'] ?? ''));
    [$mapel_nama, $kelas_nama] = ai_perangkat_nama_mapel_kelas($pdo, $id_mapel, $id_kelas);
    $topik = trim((string)($_POST['topik'] ?? ''));
    if ($topik === '') {
        echo json_encode(['ok' => false, 'msg' => 'Topik / materi pokok wajib diisi.']);
        exit;
    }
    $sub_topik = trim((string)($_POST['sub_topik'] ?? ''));
    $instruksi = trim((string)($_POST['instruksi_tambahan'] ?? ''));

    $prompt = ai_build_perangkat_prompt([
        'kurikulum' => $kurikulum === 'KMA_1503_KBC' ? 'KMA' : 'MENDIKDASMEN',
        'jenis_perangkat' => $jenis,
        'mapel' => $mapel_nama,
        'kelas' => $kelas_nama,
        'semester' => $semester,
        'tahun_ajaran' => $tahun_ajaran,
        'topik' => $topik,
        'sub_topik' => $sub_topik,
        'materi' => $materi,
        'instruksi_tambahan' => $instruksi,
    ]);

    $cfg = ai_guru_config($pdo, $guru_id);
    if ($cfg['provider'] === 'openai') {
        [$ok, $data] = ai_generate_perangkat('openai', $cfg['openai_key'], $cfg['openai_model'], $prompt, $cfg['openai_email'] ?? '');
    } else {
        [$ok, $data] = ai_generate_perangkat('gemini', $cfg['gemini_key'], $cfg['gemini_model'], $prompt, $cfg['gemini_email'] ?? '');
    }
    if (!$ok) {
        echo json_encode(['ok' => false, 'msg' => is_string($data) ? $data : 'Gagal generate perangkat.']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'provider' => $cfg['provider'],
        'jenis_perangkat' => $jenis,
        'dokumen' => $data,
    ]);
    exit;
}

if ($aksi === 'simpan') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $doc = is_array($payload['dokumen'] ?? null) ? $payload['dokumen'] : $payload;
    $jenis = trim((string)($payload['jenis_perangkat'] ?? $doc['jenis_perangkat'] ?? 'Modul Ajar'));
    if (!in_array($jenis, $jenis_allow, true)) {
        $jenis = 'Modul Ajar';
    }
    $judul = trim((string)($payload['judul'] ?? $doc['judul'] ?? ''));
    if ($judul === '') {
        $topik_j = trim((string)($payload['topik'] ?? ''));
        $judul = $jenis . ($topik_j !== '' ? ' — ' . mb_substr($topik_j, 0, 80) : '');
    }
    if ($judul === '') {
        echo json_encode(['ok' => false, 'msg' => 'Judul dokumen wajib diisi.']);
        exit;
    }
    $id_mapel = (int)($payload['id_mapel'] ?? 0) ?: null;
    $id_kelas = (int)($payload['id_kelas'] ?? 0) ?: null;
    $materi_tp = trim((string)($payload['topik'] ?? $doc['topik'] ?? ''));
    $semester = trim((string)($payload['semester'] ?? ''));
    if ($semester !== '' && !in_array($semester, ['Semester 1', 'Semester 2'], true)) {
        $semester = mb_substr($semester, 0, 20);
    }
    $tahun_ajaran = trim((string)($payload['tahun_ajaran'] ?? ''));
    $status = in_array($payload['status'] ?? '', ['Draft', 'Aktif', 'Arsip'], true) ? $payload['status'] : 'Aktif';
    $cp = trim((string)($doc['cp'] ?? ''));
    $tp = trim((string)($doc['tp'] ?? ''));
    $materi = trim((string)($payload['materi'] ?? $doc['materi'] ?? ''));
    $tujuan = trim((string)($doc['tujuan_pembelajaran'] ?? ''));
    $indikator = trim((string)($doc['indikator'] ?? ''));
    $deskripsi = trim((string)($doc['deskripsi'] ?? ''));
    $isi = trim((string)($doc['isi_dokumen'] ?? ''));
    if ($deskripsi === '') {
        $deskripsi = 'Dokumen ' . $jenis . ' hasil Generate AI.';
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO tb_perangkat_pembelajaran (
                id_guru, jenis_perangkat, judul, id_mapel, id_kelas, materi_tp,
                semester, tahun_ajaran, file_path, status, cp, tp, materi,
                tujuan_pembelajaran, indikator, deskripsi, isi_dokumen
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $guru_id, $jenis, $judul, $id_mapel, $id_kelas, ($materi_tp !== '' ? $materi_tp : null),
            ($semester !== '' ? $semester : null), ($tahun_ajaran !== '' ? $tahun_ajaran : null),
            $status,
            ($cp !== '' ? $cp : null), ($tp !== '' ? $tp : null), ($materi !== '' ? $materi : null),
            ($tujuan !== '' ? $tujuan : null), ($indikator !== '' ? $indikator : null), $deskripsi,
            ($isi !== '' ? $isi : null),
        ]);
        $new_id = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Gagal menyimpan: ' . $e->getMessage()]);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $new_id, 'judul' => $judul]);
    exit;
}

if ($aksi === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'ID tidak valid.']);
        exit;
    }
    $st = $pdo->prepare("
        SELECT p.*, m.nama_mapel, k.nama_kelas
        FROM tb_perangkat_pembelajaran p
        LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = p.id_mapel
        LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
        WHERE p.id = ? AND p.id_guru = ?
    ");
    $st->execute([$id, $guru_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => 'Dokumen tidak ditemukan.']);
        exit;
    }
    echo json_encode(['ok' => true, 'dokumen' => $row]);
    exit;
}

if ($aksi === 'resolve_image') {
    $desc = trim((string)($_GET['desc'] ?? $_POST['desc'] ?? ''));
    if ($desc === '') {
        echo json_encode(['ok' => false, 'url' => '']);
        exit;
    }
    [$filePath, $data] = ai_resolve_image_file($desc);
    $hash = md5($desc);
    if ($filePath !== '' && is_file($filePath)) {
        echo json_encode(['ok' => true, 'url' => '../uploads/ai_images/img_' . $hash . '.jpg']);
    } else {
        echo json_encode(['ok' => false, 'url' => '']);
    }
    exit;
}

if ($aksi === 'unduh' || $aksi === 'unduh_paket') {
    // Unduh PDF/DOCX/XLSX. Sumber: payload JSON preview (aksi=unduh) atau id DB (aksi=unduh_paket).
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $format = strtolower(trim((string)($_GET['format'] ?? $_POST['format'] ?? '')));

    if ($aksi === 'unduh') {
        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        if ($format === '' && !empty($payload['format'])) {
            $format = strtolower(trim((string)$payload['format']));
        }
        if ($format === 'xls') $format = 'xlsx';
        if ($format === 'doc') $format = 'docx';
        if (!in_array($format, ['pdf', 'docx', 'xlsx'], true)) $format = 'pdf';

        $doc = is_array($payload['dokumen'] ?? null) ? $payload['dokumen'] : $payload;
        $jenis = trim((string)($payload['jenis_perangkat'] ?? $doc['jenis_perangkat'] ?? 'Modul Ajar'));
        if (!in_array($jenis, $jenis_allow, true)) $jenis = 'Modul Ajar';
        $judul = trim((string)($payload['judul'] ?? $doc['judul'] ?? $jenis));
        $dok = [
            'jenis_perangkat' => $jenis,
            'judul' => $judul !== '' ? $judul : $jenis,
            'cp' => trim((string)($doc['cp'] ?? '')),
            'tp' => trim((string)($doc['tp'] ?? '')),
            'materi' => trim((string)($doc['materi'] ?? '')),
            'tujuan_pembelajaran' => trim((string)($doc['tujuan_pembelajaran'] ?? '')),
            'indikator' => trim((string)($doc['indikator'] ?? '')),
            'deskripsi' => trim((string)($doc['deskripsi'] ?? '')),
            'isi_dokumen' => trim((string)($doc['isi_dokumen'] ?? '')),
            'mapel' => trim((string)($payload['mapel'] ?? '')),
            'kelas' => trim((string)($payload['kelas'] ?? '')),
            'semester' => trim((string)($payload['semester'] ?? '')),
            'tahun_ajaran' => trim((string)($payload['tahun_ajaran'] ?? '')),
            'topik' => trim((string)($payload['topik'] ?? '')),
        ];
    } else {
        if ($format === 'xls') $format = 'xlsx';
        if ($format === 'doc') $format = 'docx';
        if (!in_array($format, ['pdf', 'docx', 'xlsx'], true)) $format = 'pdf';

        if ($id <= 0) {
            while (ob_get_level()) { ob_end_clean(); }
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'ID tidak valid.']);
            exit;
        }

        $user_level = getUserLevel();
        if (in_array($user_level, ['admin', 'tata_usaha', 'kepala_madrasah'], true)) {
            $st = $pdo->prepare("
                SELECT p.*, m.nama_mapel, k.nama_kelas
                FROM tb_perangkat_pembelajaran p
                LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = p.id_mapel
                LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
                WHERE p.id = ?
            ");
            $st->execute([$id]);
        } else {
            $st = $pdo->prepare("
                SELECT p.*, m.nama_mapel, k.nama_kelas
                FROM tb_perangkat_pembelajaran p
                LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = p.id_mapel
                LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
                WHERE p.id = ? AND (p.id_guru = ? OR p.id_guru = 0 OR p.id_guru IS NULL)
            ");
            $st->execute([$id, $guru_id]);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            while (ob_get_level()) { ob_end_clean(); }
            header('Content-Type: application/json');
            http_response_code(404);
            echo json_encode(['ok' => false, 'msg' => 'Dokumen tidak ditemukan.']);
            exit;
        }
        $dok = [
            'jenis_perangkat' => $row['jenis_perangkat'] ?? 'Dokumen',
            'judul' => $row['judul'] ?? 'Dokumen',
            'cp' => (string)($row['cp'] ?? ''),
            'tp' => (string)($row['tp'] ?? ''),
            'materi' => (string)($row['materi'] ?? ''),
            'tujuan_pembelajaran' => (string)($row['tujuan_pembelajaran'] ?? ''),
            'indikator' => (string)($row['indikator'] ?? ''),
            'deskripsi' => (string)($row['deskripsi'] ?? ''),
            'isi_dokumen' => (function () use ($row) {
                $isi0 = trim((string)($row['isi_dokumen'] ?? ''));
                if ($isi0 !== '') return $isi0;
                // Data lama yang isi_dokumen-nya kosong: susun dari field terpisah
                $parts = [];
                $add = function ($j, $v) use (&$parts) {
                    $v = trim((string)$v);
                    if ($v !== '' && $v !== '-') $parts[] = $j . "\n" . $v;
                };
                $add('A. CAPAIAN PEMBELAJARAN (CP)', $row['cp'] ?? '');
                $add('B. TUJUAN PEMBELAJARAN (TP)', $row['tp'] ?? '');
                $add('C. MATERI POKOK', trim(trim((string)($row['materi_tp'] ?? '')) . "\n" . trim((string)($row['materi'] ?? ''))));
                $add('D. TUJUAN PEMBELAJARAN KHUSUS', $row['tujuan_pembelajaran'] ?? '');
                $add('E. INDIKATOR KETERCAPAIAN', $row['indikator'] ?? '');
                $add('F. DESKRIPSI / CATATAN', $row['deskripsi'] ?? '');
                return implode("\n\n", $parts);
            })(),
            'mapel' => (string)($row['nama_mapel'] ?? ''),
            'kelas' => (string)($row['nama_kelas'] ?? ''),
            'semester' => (string)($row['semester'] ?? ''),
            'tahun_ajaran' => (string)($row['tahun_ajaran'] ?? ''),
            'topik' => (string)($row['materi_tp'] ?? ''),
        ];
    }

    require_once '../vendor/autoload.php';
    while (ob_get_level()) { ob_end_clean(); }
    header_remove('Content-Type');

    $fname_base = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$dok['judul']));
    if ($fname_base === '') $fname_base = 'perangkat';
    $fname_base = substr($fname_base, 0, 80);

    if ($format === 'xlsx') {
        $xlsx_out = ai_build_perangkat_xlsx($dok);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname_base . '.xlsx"');
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Content-Length: ' . strlen($xlsx_out));
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        echo $xlsx_out;
        exit;
    }

    if ($format === 'docx' || $format === 'doc') {
        $doc_out = ai_build_perangkat_docx($dok);
        $ext_out = $format === 'doc' ? 'doc' : 'docx';
        $mime_out = $format === 'doc' ? 'application/msword' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        header('Content-Type: ' . $mime_out);
        header('Content-Disposition: attachment; filename="' . $fname_base . '.' . $ext_out . '"');
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Content-Length: ' . strlen($doc_out));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $doc_out;
        exit;
    }

    // Default PDF via Dompdf (Format Kertas Landscape F4 / Folio 330mm x 215mm)
    if (!class_exists('Dompdf\Dompdf')) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'Dompdf tidak tersedia.']);
        exit;
    }
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<style>'
        . '@page { size: 330mm 215mm landscape; margin: 12mm 15mm; }'
        . 'body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 9pt; color: #111; line-height: 1.45; }'
        . 'h2 { text-align: center; font-size: 14pt; font-weight: bold; margin-bottom: 2px; }'
        . 'h3 { font-size: 11pt; border-bottom: 1.5px solid #2563eb; color: #1e3a8a; padding-bottom: 3px; margin-top: 14px; }'
        . 'table { border-collapse: collapse; width: 100%; margin: 8px 0; table-layout: auto; }'
        . 'th, td { border: 1px solid #666; padding: 4px 6px; vertical-align: top; font-size: 8pt; word-wrap: break-word; }'
        . 'th { background-color: #e2e8f0; font-weight: bold; text-align: center; }'
        . '</style></head><body>';
    $html .= '<h2>' . htmlspecialchars($dok['judul']) . '</h2>';
    $html .= '<p style="text-align:center;color:#555;font-size:8.5pt;">' . htmlspecialchars($dok['jenis_perangkat'])
        . ($dok['mapel'] !== '' ? ' | ' . htmlspecialchars($dok['mapel']) : '')
        . ($dok['kelas'] !== '' ? ' | Kelas ' . htmlspecialchars($dok['kelas']) : '')
        . ($dok['semester'] !== '' ? ' | ' . htmlspecialchars($dok['semester']) : '')
        . ($dok['tahun_ajaran'] !== '' ? ' | ' . htmlspecialchars($dok['tahun_ajaran']) : '')
        . '</p>';
    $isi_raw = trim((string)$dok['isi_dokumen']);
    $isi_html = ai_format_perangkat_html($isi_raw, true);
    $has_identitas_pdf = (bool)preg_match('/A\.\s+IDENTITAS/i', $isi_raw);
    if (!$has_identitas_pdf) {
        $html .= '<h3>Identitas &amp; Capaian</h3><table>';
        foreach ([
            'Topik' => $dok['topik'], 'CP' => $dok['cp'], 'TP' => $dok['tp'],
            'Materi' => $dok['materi'], 'Tujuan Pembelajaran' => $dok['tujuan_pembelajaran'],
            'Indikator' => $dok['indikator'], 'Deskripsi' => $dok['deskripsi'],
        ] as $label => $val) {
            if (trim($val) === '') continue;
            $html .= '<tr><th style="width:160px;text-align:left;">' . htmlspecialchars($label) . '</th><td>' . nl2br(htmlspecialchars($val)) . '</td></tr>';
        }
        $html .= '</table>';
    }
    $html .= '<div style="margin-top: 10px;">' . $isi_html . '</div></body></html>';
    $dompdf = new Dompdf\Dompdf(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true, 'defaultFont' => 'sans-serif']);
    $dompdf->loadHtml($html);
    // Kertas F4 / Folio Landscape: 330mm x 215mm = 935.43pt x 609.45pt
    $dompdf->setPaper([0, 0, 609.45, 935.43], 'landscape');
    $dompdf->render();
    $pdf_out = $dompdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname_base . '.pdf"');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Content-Length: ' . strlen($pdf_out));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $pdf_out;
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal.']);
