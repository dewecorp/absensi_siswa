<?php
// Endpoint Generate Soal AI untuk guru: generate (upload materi Word/TXT + textarea),
// simpan batch ke tb_bank_soal, unduh PDF/XLSX. Konektor milik tiap guru (profil).
ob_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/ai_helper.php';

ensure_learning_schema($pdo);
ai_helper_schema($pdo);

if (!isAuthorized(['guru', 'wali'])) {
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

// Ambil teks materi dari upload Word (.docx)/TXT + textarea manual.
function ai_extract_materi_text(): array {
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
                return ['', 'Gagal membaca file .docx. Simpan sebagai .docx Word modern atau .txt.'];
            }
        } elseif (substr($name, -4) === '.doc') {
            return ['', 'Format .doc lama tidak didukung. Simpan ulang sebagai .docx atau .txt.'];
        } else {
            return ['', 'Format file harus .docx atau .txt.'];
        }
    }
    $gabung = trim($manual . ($from_file !== '' ? "\n\n" . $from_file : ''));
    // Batasi agar prompt tidak kepanjangan (~8000 karakter).
    if (mb_strlen($gabung) > 8000) {
        $gabung = mb_substr($gabung, 0, 8000);
    }
    return [$gabung, ''];
}

function ai_docx_esc(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function ai_docx_p(string $text, bool $bold = false, int $size = 22): string {
    $b = $bold ? '<w:b/>' : '';
    return '<w:p><w:r><w:rPr>' . $b . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">' . ai_docx_esc($text) . '</w:t></w:r></w:p>';
}

// Bangun ZIP minimal (metode stored, tanpa kompresi) untuk berkas DOCX.
// Ditulis field per field agar tidak bergantung pada hitungan format pack.
function ai_zip_stored(array $files): string {
    $local = '';
    $central = '';
    $offset = 0;
    $dosDate = ((2026 - 1980) << 9) | (1 << 5) | 1;
    foreach ($files as $name => $data) {
        $name = (string)$name;
        $data = (string)$data;
        $crc = crc32($data) & 0xFFFFFFFF;
        $len = strlen($data);
        $nlen = strlen($name);
        $lh = pack('V', 0x04034B50)
            . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', $dosDate)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nlen) . pack('v', 0);
        $local .= $lh . $name . $data;
        $central .= pack('V', 0x02014B50)
            . pack('v', 20) . pack('v', 20)
            . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', $dosDate)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nlen) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', 0) . pack('V', $offset)
            . $name;
        $offset += strlen($lh) + $nlen + $len;
    }
    $eocd = pack('V', 0x06054B50)
        . pack('v', 0) . pack('v', 0)
        . pack('v', count($files)) . pack('v', count($files))
        . pack('V', strlen($central)) . pack('V', $offset)
        . pack('v', 0);
    return $local . $central . $eocd;
}

function ai_parse_menjodohkan_text(string $pertanyaan): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($pertanyaan));
    $soal_lines = [];
    $tabel = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^(\d+)[\.\)]\s*(.*?)\s*\|\s*([A-Za-z])[\.\)]\s*(.*)$/u', $line, $m)) {
            $tabel[] = [
                'no' => (int)$m[1],
                'kiri' => trim($m[2]),
                'huruf' => strtoupper(trim($m[3])),
                'kanan' => trim($m[4])
            ];
        } else {
            if (preg_match_all('/(?:^|\s+)(\d+)[\.\)]\s*([^|]+?)\s*\|\s*([A-Za-z])[\.\)]\s*(.*?)(?=(?:\s+\d+[\.\)]|$))/u', $line, $all_matches, PREG_SET_ORDER)) {
                $first_pos = strpos($line, $all_matches[0][0]);
                if ($first_pos > 0) {
                    $prefix = trim(substr($line, 0, $first_pos));
                    if ($prefix !== '') $soal_lines[] = $prefix;
                }
                foreach ($all_matches as $am) {
                    $tabel[] = [
                        'no' => (int)$am[1],
                        'kiri' => trim($am[2]),
                        'huruf' => strtoupper(trim($am[3])),
                        'kanan' => trim($am[4])
                    ];
                }
            } else {
                $soal_lines[] = $line;
            }
        }
    }

    $clean_pertanyaan = trim(implode("\n", $soal_lines));
    if ($clean_pertanyaan === '') {
        $clean_pertanyaan = 'Jodohkan pernyataan pada kolom kiri dengan pilihan yang sesuai pada kolom kanan:';
    }

    return [$clean_pertanyaan, $tabel];
}

// Susun DOCX tabel menjodohkan 4 kolom: No | Soal | Huruf | Pilihan Jawaban
function ai_docx_tabel_jodoh(array $rows): string {
    if (empty($rows)) {
        return '';
    }
    $xml = '<w:tbl>'
        . '<w:tblPr>'
        . '<w:tblW w:w="9000" w:type="dxa"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
        . '<w:left w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
        . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
        . '<w:right w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
        . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>'
        . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>'
        . '</w:tblBorders>'
        . '</w:tblPr>'
        . '<w:tblGrid>'
        . '<w:gridCol w:w="700"/>'
        . '<w:gridCol w:w="3800"/>'
        . '<w:gridCol w:w="700"/>'
        . '<w:gridCol w:w="3800"/>'
        . '</w:tblGrid>';

    // Header row
    $xml .= '<w:tr>'
        . '<w:tc><w:tcPr><w:tcW w:w="700" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="EEEEEE"/></w:tcPr>' . ai_docx_p('No', true, 20) . '</w:tc>'
        . '<w:tc><w:tcPr><w:tcW w:w="3800" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="EEEEEE"/></w:tcPr>' . ai_docx_p('Soal', true, 20) . '</w:tc>'
        . '<w:tc><w:tcPr><w:tcW w:w="700" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="EEEEEE"/></w:tcPr>' . ai_docx_p('Huruf', true, 20) . '</w:tc>'
        . '<w:tc><w:tcPr><w:tcW w:w="3800" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="EEEEEE"/></w:tcPr>' . ai_docx_p('Pilihan Jawaban', true, 20) . '</w:tc>'
        . '</w:tr>';

    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $no = (string)($r['no'] ?? '');
        $kiri = (string)($r['kiri'] ?? '');
        $huruf = (string)($r['huruf'] ?? '');
        $kanan = (string)($r['kanan'] ?? '');

        $xml .= '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="700" w:type="dxa"/></w:tcPr>' . ai_docx_p($no, false, 20) . '</w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="3800" w:type="dxa"/></w:tcPr>' . ai_docx_p($kiri, false, 20) . '</w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="700" w:type="dxa"/></w:tcPr>' . ai_docx_p($huruf, true, 20) . '</w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="3800" w:type="dxa"/></w:tcPr>' . ai_docx_p($kanan, false, 20) . '</w:tc>'
            . '</w:tr>';
    }

    $xml .= '</w:tbl>';
    return $xml;
}

// Susun DOCX tabel kisi-kisi 9 kolom (sama seperti preview generator):
// No | Bentuk | Materi | CP | TP | Indikator | Level | Kesulitan | Bobot
function ai_docx_tabel_kisi(array $rows): string {
    if (empty($rows)) {
        return '';
    }
    // Lebar kolom (dxa), total 9000
    $cols = [500, 900, 1000, 1200, 1200, 1600, 700, 1000, 800];
    $heads = ['No', 'Bentuk', 'Materi', 'CP', 'TP', 'Indikator', 'Level', 'Kesulitan', 'Bobot'];
    $keys = ['no', 'bentuk', 'materi', 'cp', 'tp', 'indikator', 'level_kognitif', 'kesulitan', 'bobot'];

    $xml = '<w:tbl>'
        . '<w:tblPr>'
        . '<w:tblW w:w="9000" w:type="dxa"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
        . '<w:left w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
        . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
        . '<w:right w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
        . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>'
        . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>'
        . '</w:tblBorders>'
        . '</w:tblPr>'
        . '<w:tblGrid>';
    foreach ($cols as $w) {
        $xml .= '<w:gridCol w:w="' . $w . '"/>';
    }
    $xml .= '</w:tblGrid>';

    // Header row
    $xml .= '<w:tr>';
    foreach ($heads as $hi => $h) {
        $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $cols[$hi] . '" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="EEEEEE"/></w:tcPr>' . ai_docx_p($h, true, 18) . '</w:tc>';
    }
    $xml .= '</w:tr>';

    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $xml .= '<w:tr>';
        foreach ($keys as $ki => $k) {
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $cols[$ki] . '" w:type="dxa"/></w:tcPr>' . ai_docx_p((string)($r[$k] ?? ''), false, 18) . '</w:tc>';
        }
        $xml .= '</w:tr>';
    }

    $xml .= '</w:tbl>';
    return $xml;
}

function ai_build_soal_docx(array $items, array $payload): string {
    $kur = ($payload['kurikulum'] ?? '') === 'KMA_1503_KBC' ? 'KMA 1503 + KBC' : 'Permendikdasmen CP 046';
    $judul = trim((string)($payload['jenis_asesmen'] ?? ''));
    if ($judul === '') {
        $judul = 'Hasil Generate Soal AI (Paket Soal)';
    }
    $body = ai_docx_p($judul, true, 28);
    $meta = [];
    if (trim((string)($payload['topik'] ?? '')) !== '') {
        $meta[] = 'Topik: ' . trim((string)$payload['topik']);
    }
    if (trim((string)($payload['sub_topik'] ?? '')) !== '') {
        $meta[] = 'Sub Topik: ' . trim((string)$payload['sub_topik']);
    }
    if (trim((string)($payload['semester'] ?? '')) !== '') {
        $meta[] = 'Semester: ' . trim((string)$payload['semester']);
    }
    $meta[] = 'Kurikulum: ' . $kur;
    $kisi_awal = is_array($payload['kisi_kisi'] ?? null) ? array_values(array_filter($payload['kisi_kisi'], 'is_array')) : [];
    $meta[] = 'Jumlah: ' . ($kisi_awal ? count($kisi_awal) : count($items)) . ' butir';
    foreach ($meta as $m) {
        $body .= ai_docx_p($m);
    }
    $body .= ai_docx_p('');
    // Kisi-kisi dalam bentuk TABEL 9 kolom (sama seperti preview generator).
    $kisi = is_array($payload['kisi_kisi'] ?? null) ? $payload['kisi_kisi'] : [];
    if ($kisi) {
        $body .= ai_docx_p('KISI-KISI', true, 24);
        $body .= ai_docx_tabel_kisi(array_values(array_filter($kisi, 'is_array')));
        $body .= ai_docx_p('');
    }
    foreach ($items as $i => $it) {
        $b_it = $it['bentuk'] ?? 'Soal';
        $pert_it = (string)($it['pertanyaan'] ?? '');
        $tbl_docx = (!empty($it['tabel']) && is_array($it['tabel'])) ? $it['tabel'] : [];

        if ($b_it === 'Menjodohkan') {
            if (empty($tbl_docx)) {
                [$clean_p, $tbl_docx] = ai_parse_menjodohkan_text($pert_it);
                $pert_it = $clean_p;
            } else {
                [$clean_p] = ai_parse_menjodohkan_text($pert_it);
                $pert_it = $clean_p;
            }
        }

        $body .= ai_docx_p(($i + 1) . '. [' . (string)$b_it . '][' . (string)($it['level_kognitif'] ?? 'L2') . '] ' . $pert_it, true);
        if ($b_it === 'Menjodohkan' && !empty($tbl_docx)) {
            $body .= ai_docx_tabel_jodoh($tbl_docx);
        } else {
            $opsi = (isset($it['opsi']) && is_array($it['opsi'])) ? $it['opsi'] : [];
            foreach (['A', 'B', 'C', 'D'] as $k) {
                if (trim((string)($opsi[$k] ?? '')) !== '') {
                    $body .= ai_docx_p($k . '. ' . (string)$opsi[$k]);
                }
            }
        }
        $kunci = trim((string)($it['kunci'] ?? ''));
        $body .= ai_docx_p('Kunci: ' . ($kunci !== '' ? $kunci : '-'), true);
        if (trim((string)($it['pembahasan'] ?? '')) !== '') {
            $body .= ai_docx_p('Pembahasan: ' . (string)$it['pembahasan']);
        }
        $body .= ai_docx_p('');
    }
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
        . '</w:body></w:document>';
    $types = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';
    return ai_zip_stored([
        '[Content_Types].xml' => $types,
        '_rels/.rels' => $rels,
        'word/document.xml' => $doc,
    ]);
}

function ai_nama_mapel_kelas(PDO $pdo, $id_mapel, $id_kelas): array {
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

if ($aksi === 'generate') {
    $bentuk_allow = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
    // Paket multi-bentuk: paket[Nama Bentuk] = jumlah. Fallback legacy bentuk+jumlah.
    // Catatan: PHP mengubah spasi/titik pada nama field menjadi underscore,
    // mis. paket[Pilihan Ganda] terbaca sebagai paket[Pilihan_Ganda].
    $paket = [];
    if (!empty($_POST['paket']) && is_array($_POST['paket'])) {
        $posted = [];
        foreach ($_POST['paket'] as $k => $v) {
            $posted[$k] = $v;
            $posted[str_replace('_', ' ', (string)$k)] = $v;
        }
        foreach ($bentuk_allow as $b) {
            $n = (int)($posted[$b] ?? 0);
            if ($n > 0) {
                $paket[$b] = $n;
            }
        }
    }
    if (!$paket) {
        $bentuk = trim((string)($_POST['bentuk'] ?? 'Pilihan Ganda'));
        if (!in_array($bentuk, $bentuk_allow, true)) {
            $bentuk = 'Pilihan Ganda';
        }
        $paket = [$bentuk => max(1, (int)($_POST['jumlah'] ?? 5))];
    }
    $total_minta = array_sum($paket);
    if ($total_minta < 1) {
        echo json_encode(['ok' => false, 'msg' => 'Pilih minimal 1 bentuk soal dengan jumlah minimal 1.']);
        exit;
    }
    $kurikulum = in_array($_POST['kurikulum'] ?? '', ['PERMENDIKDASMEN_046', 'KMA_1503_KBC'], true) ? $_POST['kurikulum'] : 'PERMENDIKDASMEN_046';
    $jenis_asesmen = trim((string)($_POST['jenis_asesmen'] ?? ''));
    if (!in_array($jenis_asesmen, ai_asesmen_list(), true)) {
        $jenis_asesmen = ai_asesmen_list()[0];
    }
    [$materi, $err] = ai_extract_materi_text();
    if ($err !== '') {
        echo json_encode(['ok' => false, 'msg' => $err]);
        exit;
    }
    // Materi detail opsional: bila kosong, AI buat dari mapel/kelas/semester/topik.
    // Kesulitan dibuat acak merata oleh AI per bentuk (tanpa input dominan).
    $kesulitan = '';
    $id_mapel = (int)($_POST['id_mapel'] ?? 0);
    $id_kelas = (int)($_POST['id_kelas'] ?? 0);
    $semester = trim((string)($_POST['semester'] ?? ''));
    if (!in_array($semester, ['Semester 1', 'Semester 2', '1', '2', 'Ganjil', 'Genap'], true)) {
        $semester = $semester !== '' ? mb_substr($semester, 0, 20) : '';
    }
    [$mapel_nama, $kelas_nama] = ai_nama_mapel_kelas($pdo, $id_mapel, $id_kelas);
    $topik = trim((string)($_POST['topik'] ?? ''));
    if ($topik === '') {
        echo json_encode(['ok' => false, 'msg' => 'Topik/pokok bahasan wajib diisi.']);
        exit;
    }
    $sub_topik = trim((string)($_POST['sub_topik'] ?? ''));
    $instruksi_tambahan = trim((string)($_POST['instruksi_tambahan'] ?? ''));

    $prompt = ai_build_soal_prompt([
        'kurikulum' => $kurikulum === 'KMA_1503_KBC' ? 'KMA' : 'MENDIKDASMEN',
        'jenis_asesmen' => $jenis_asesmen,
        'paket' => $paket,
        'mapel' => $mapel_nama,
        'kelas' => $kelas_nama,
        'semester' => $semester,
        'topik' => $topik,
        'sub_topik' => $sub_topik,
        'materi' => $materi,
        'instruksi_tambahan' => $instruksi_tambahan,
        'kesulitan' => $kesulitan,
    ]);

    $cfg = ai_guru_config($pdo, $guru_id);
    if ($cfg['provider'] === 'openai') {
        [$ok, $data] = ai_generate_soal('openai', $cfg['openai_key'], $cfg['openai_model'], $prompt, $cfg['openai_email'] ?? '');
    } else {
        [$ok, $data] = ai_generate_soal('gemini', $cfg['gemini_key'], $cfg['gemini_model'], $prompt, $cfg['gemini_email'] ?? '');
    }
    if (!$ok) {
        echo json_encode(['ok' => false, 'msg' => is_string($data) ? $data : 'Gagal generate soal.']);
        exit;
    }
    // Normalisasi: tiap butir wajib punya bentuk valid + nomor urut paket.
    // Catatan: Menjodohkan adalah 1 butir soal dengan N baris tabel pasangan.
    $expected = [];
    foreach ($paket as $b => $n) {
        if ($b === 'Menjodohkan') {
            $expected[] = 'Menjodohkan';
        } else {
            for ($i = 0; $i < $n; $i++) {
                $expected[] = $b;
            }
        }
    }
    $norm_level = function ($v) {
        $v = strtoupper(trim((string)$v));
        if (in_array($v, ['L1', 'L2', 'L3', 'L4'], true)) {
            return $v;
        }
        if (preg_match('/C\s*([1-6])/', $v, $m)) {
            $c = (int)$m[1];
            if ($c <= 1) {
                return 'L1';
            }
            if ($c <= 2) {
                return 'L2';
            }
            if ($c <= 4) {
                return 'L3';
            }
            return 'L4';
        }
        if (preg_match('/\bL\s*([1-4])/', $v, $m)) {
            return 'L' . $m[1];
        }
        return '';
    };
    $norm_tabel = function ($t) {
        if (!is_array($t)) {
            return [];
        }
        // Toleran bila AI kirim objek asosiatif, bukan array.
        if (!array_is_list($t)) {
            $t = [$t];
        }
        $out = [];
        $huruf_seq = range('A', 'Z');
        foreach (array_slice($t, 0, 15) as $idx => $r) {
            if (!is_array($r)) {
                continue;
            }
            $no = (int)($r['no'] ?? ($idx + 1));
            if ($no <= 0) {
                $no = $idx + 1;
            }
            $kiri = trim((string)($r['kiri'] ?? ($r['soal'] ?? ($r['kiri_soal'] ?? ''))));
            $huruf = strtoupper(trim((string)($r['huruf'] ?? '')));
            if (!preg_match('/^[A-Z]$/', $huruf)) {
                $huruf = $huruf_seq[$idx] ?? chr(65 + $idx);
            }
            $kanan = trim((string)($r['kanan'] ?? ($r['jawaban'] ?? ($r['pilihan'] ?? ''))));
            if ($kiri === '' && $kanan === '') {
                continue;
            }
            $out[] = ['no' => $no, 'kiri' => $kiri, 'huruf' => $huruf, 'kanan' => $kanan];
        }
        return $out;
    };
    $diff_cycle = ['Mudah', 'Sedang', 'Sukar'];
    $diff_count = [];
    $soal = array_values(is_array($data['soal'] ?? null) ? $data['soal'] : []);
    foreach ($soal as $i => &$it) {
        if (!is_array($it)) {
            $it = ['pertanyaan' => ''];
        }
        $b = trim((string)($it['bentuk'] ?? ''));
        if (!in_array($b, $bentuk_allow, true)) {
            $b = $expected[$i] ?? $expected[0] ?? 'Pilihan Ganda';
        }
        $it['bentuk'] = $b;
        $it['no'] = $i + 1;
        if (!isset($diff_count[$b])) {
            $diff_count[$b] = 0;
        }
        if (!in_array($it['kesulitan'] ?? '', ['Mudah', 'Sedang', 'Sukar'], true)) {
            $it['kesulitan'] = $diff_cycle[$diff_count[$b] % 3];
        }
        $diff_count[$b]++;
        $lv = $norm_level($it['level_kognitif'] ?? '');
        $it['level_kognitif'] = $lv !== '' ? $lv : 'L2';
        $it['cp'] = trim((string)($it['cp'] ?? ''));
        $it['tp'] = trim((string)($it['tp'] ?? ''));
        $it['bobot'] = (float)($it['bobot'] ?? 1);
        if (!empty($it['opsi']) && is_array($it['opsi'])) {
            $it['opsi'] = [
                'A' => (string)($it['opsi']['A'] ?? ''),
                'B' => (string)($it['opsi']['B'] ?? ''),
                'C' => (string)($it['opsi']['C'] ?? ''),
                'D' => (string)($it['opsi']['D'] ?? ''),
            ];
        } else {
            $it['opsi'] = new stdClass();
        }
        $it['tabel'] = $norm_tabel($it['tabel'] ?? []);
        // Fallback: bila Menjodohkan tanpa tabel tapi opsi terisi, konversi opsi jadi kolom kanan.
        if ($b === 'Menjodohkan' && count($it['tabel']) === 0) {
            $ops = is_array($it['opsi'] ?? null) ? $it['opsi'] : [];
            $vals = [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $k) {
                if (trim((string)($ops[$k] ?? '')) !== '') {
                    $vals[] = trim((string)$ops[$k]);
                }
            }
            if ($vals) {
                $tb = [];
                foreach ($vals as $j => $v) {
                    $tb[] = ['no' => $j + 1, 'kiri' => '', 'huruf' => chr(65 + $j), 'kanan' => $v];
                }
                $it['tabel'] = $tb;
            }
        }
    }
    unset($it);
    $kisi = is_array($data['kisi_kisi'] ?? null) ? $data['kisi_kisi'] : [];
    foreach ($kisi as $i => &$k) {
        if (!is_array($k)) {
            $k = [];
        }
        $b = trim((string)($k['bentuk'] ?? ''));
        if (!in_array($b, $bentuk_allow, true)) {
            $b = $expected[$i] ?? $expected[0] ?? 'Pilihan Ganda';
        }
        $k['bentuk'] = $b;
        $k['no'] = $i + 1;
        $lv = $norm_level($k['level_kognitif'] ?? '');
        // Samakan level kisi dengan soal bila kosong.
        if ($lv === '' && isset($soal[$i]['level_kognitif'])) {
            $lv = $soal[$i]['level_kognitif'];
        }
        $k['level_kognitif'] = $lv !== '' ? $lv : 'L2';
        $k['materi'] = trim((string)($k['materi'] ?? ''));
        $k['cp'] = trim((string)($k['cp'] ?? ''));
        $k['tp'] = trim((string)($k['tp'] ?? ''));
        $k['indikator'] = trim((string)($k['indikator'] ?? ''));
        if (!in_array($k['kesulitan'] ?? '', ['Mudah', 'Sedang', 'Sukar'], true)) {
            $k['kesulitan'] = $soal[$i]['kesulitan'] ?? 'Sedang';
        }
        $k['bobot'] = (float)($k['bobot'] ?? ($soal[$i]['bobot'] ?? 1));

        // Sinkronisasi CP dan TP ke soal
        if (isset($soal[$i])) {
            if (empty($soal[$i]['cp'])) { $soal[$i]['cp'] = $k['cp']; }
            if (empty($soal[$i]['tp'])) { $soal[$i]['tp'] = $k['tp']; }
            if (empty($soal[$i]['indikator'])) { $soal[$i]['indikator'] = $k['indikator']; }
            if (empty($soal[$i]['materi'])) { $soal[$i]['materi'] = $k['materi']; }
        }
    }
    unset($k);
    echo json_encode([
        'ok' => true,
        'provider' => $cfg['provider'],
        'jenis_asesmen' => $jenis_asesmen,
        'paket' => $paket,
        'kisi_kisi' => array_values($kisi),
        'soal' => array_values($soal),
    ]);
    exit;
}

if ($aksi === 'simpan') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $items = $payload['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        echo json_encode(['ok' => false, 'msg' => 'Tidak ada soal untuk disimpan.']);
        exit;
    }
    $bentuk_allow = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
    $bentuk_default = trim((string)($payload['bentuk'] ?? 'Pilihan Ganda'));
    if (!in_array($bentuk_default, $bentuk_allow, true)) {
        $bentuk_default = 'Pilihan Ganda';
    }
    $kurikulum = in_array($payload['kurikulum'] ?? '', ['PERMENDIKDASMEN_046', 'KMA_1503_KBC'], true) ? $payload['kurikulum'] : 'PERMENDIKDASMEN_046';
    $jenis_asesmen_simpan = trim((string)($payload['jenis_asesmen'] ?? ''));
    if (!in_array($jenis_asesmen_simpan, ai_asesmen_list(), true)) {
        $jenis_asesmen_simpan = ai_asesmen_list()[0];
    }
    $id_mapel = (int)($payload['id_mapel'] ?? 0) ?: null;
    $id_kelas = (int)($payload['id_kelas'] ?? 0) ?: null;
    $materi = trim((string)($payload['materi'] ?? ''));
    $topik_simpan = trim((string)($payload['topik'] ?? ''));
    $sub_topik_simpan = trim((string)($payload['sub_topik'] ?? ''));
    if ($topik_simpan === '') {
        // Toleran untuk preview lama: turunkan dari sub-topik/materi agar tetap tersimpan.
        $fb = trim((string)($payload['materi'] ?? ''));
        if ($sub_topik_simpan !== '') {
            $topik_simpan = mb_substr($sub_topik_simpan, 0, 255);
        } elseif ($fb !== '') {
            $topik_simpan = mb_substr(preg_replace('/\s+/', ' ', $fb), 0, 255);
        } else {
            $topik_simpan = 'Hasil Generate AI';
        }
    }
    $status = in_array($payload['status'] ?? '', ['Draft', 'Aktif', 'Arsip'], true) ? $payload['status'] : 'Aktif';

    $tersimpan = 0;
    try {
        $semester_simpan = trim((string)($payload['semester'] ?? ''));
        if ($semester_simpan !== '' && !in_array($semester_simpan, ['Semester 1', 'Semester 2'], true)) {
            $semester_simpan = mb_substr($semester_simpan, 0, 20);
        }
        $kode_paket = 'PKT-' . strtoupper(substr(uniqid('', true), -10));
        $stmt = $pdo->prepare("
            INSERT INTO tb_bank_soal (
                id_guru, kode_soal, kode_paket, jenis_soal, id_mapel, id_kelas, kurikulum, semester, jenis_asesmen, topik, sub_topik, materi_tp,
                cp, tp, indikator, level_kognitif, tingkat_kesulitan, bobot, pertanyaan,
                pilihan_jawaban, jawaban_benar, pembahasan, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $pertanyaan = trim((string)($it['pertanyaan'] ?? ''));
            if ($pertanyaan === '') {
                continue;
            }
            $bentuk_item = trim((string)($it['bentuk'] ?? $bentuk_default));
            if (!in_array($bentuk_item, $bentuk_allow, true)) {
                $bentuk_item = $bentuk_default;
            }
            $opsi = null;
            if (!empty($it['opsi']) && is_array($it['opsi'])) {
                $opsi = json_encode([
                    'A' => (string)($it['opsi']['A'] ?? ''),
                    'B' => (string)($it['opsi']['B'] ?? ''),
                    'C' => (string)($it['opsi']['C'] ?? ''),
                    'D' => (string)($it['opsi']['D'] ?? ''),
                ], JSON_UNESCAPED_UNICODE);
            }
            $tabel_item = [];
            if (!empty($it['tabel']) && is_array($it['tabel'])) {
                foreach (array_slice($it['tabel'], 0, 6) as $tr) {
                    if (!is_array($tr)) {
                        continue;
                    }
                    $tabel_item[] = [
                        'no' => (int)($tr['no'] ?? 0),
                        'kiri' => trim((string)($tr['kiri'] ?? '')),
                        'huruf' => strtoupper(trim((string)($tr['huruf'] ?? ''))),
                        'kanan' => trim((string)($tr['kanan'] ?? '')),
                    ];
                }
            }
            // Simpan tabel menjodohkan ke pilihan_jawaban dan gabung ke pertanyaan
            if ($bentuk_item === 'Menjodohkan' && $tabel_item) {
                $opsi = json_encode($tabel_item, JSON_UNESCAPED_UNICODE);
                $baris = [];
                foreach ($tabel_item as $tr) {
                    $baris[] = $tr['no'] . '. ' . $tr['kiri'] . '  |  ' . $tr['huruf'] . '. ' . $tr['kanan'];
                }
                $pertanyaan = trim($pertanyaan . "\n" . implode("\n", $baris));
            }
            $lv_item = strtoupper(trim((string)($it['level_kognitif'] ?? '')));
            if (!in_array($lv_item, ['L1', 'L2', 'L3', 'L4'], true)) {
                $lv_item = 'L2';
            }
            $kisi_list = is_array($payload['kisi_kisi'] ?? null) ? $payload['kisi_kisi'] : [];
            $cp_item = trim((string)($it['cp'] ?? ($kisi_list[$idx]['cp'] ?? '')));
            $tp_item = trim((string)($it['tp'] ?? ($kisi_list[$idx]['tp'] ?? '')));
            if ($cp_item === '') {
                $cp_item = 'Memahami dan menguasai materi ' . $topik_simpan . ' sesuai capaian pembelajaran kurikulum.';
            }
            if ($tp_item === '') {
                $tp_item = 'Menganalisis, mengidentifikasi, dan menerapkan konsep ' . $topik_simpan . ' dalam pemecahan masalah.';
            }
            $stmt->execute([
                $guru_id,
                'SOAL-' . strtoupper(substr(uniqid(), -6)),
                $kode_paket,
                $bentuk_item,
                $id_mapel, $id_kelas, $kurikulum, ($semester_simpan !== '' ? $semester_simpan : null), $jenis_asesmen_simpan,
                $topik_simpan, ($sub_topik_simpan !== '' ? $sub_topik_simpan : null),
                ($materi !== '' ? $materi : null),
                $cp_item, $tp_item,
                trim((string)($it['indikator'] ?? ($kisi_list[$idx]['indikator'] ?? ''))) ?: null, $lv_item,
                in_array($it['kesulitan'] ?? '', ['Mudah', 'Sedang', 'Sukar'], true) ? $it['kesulitan'] : 'Sedang',
                (float)($it['bobot'] ?? 1),
                $pertanyaan, $opsi,
                trim((string)($it['kunci'] ?? '')) ?: null,
                trim((string)($it['pembahasan'] ?? '')) ?: null,
                $status,
            ]);
            $tersimpan++;
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Gagal menyimpan: ' . $e->getMessage()]);
        exit;
    }
    echo json_encode(['ok' => true, 'tersimpan' => $tersimpan, 'kode_paket' => $kode_paket]);
    exit;
}

if ($aksi === 'unduh') {
    // Unduh PDF/XLSX/DOCX dari hasil preview (dikirim sebagai JSON atau POST). Output file, bukan JSON.
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload) && !empty($_POST)) {
        $payload = $_POST;
    }
    if (!is_array($payload)) {
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Payload unduhan tidak valid.']);
        exit;
    }
    $format = strtolower((string)($payload['format'] ?? 'pdf'));
    if ($format === 'xls') { $format = 'xlsx'; }
    if ($format === 'doc') { $format = 'docx'; }
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $bentuk_allow_dl = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
    $bentuk_default_dl = trim((string)($payload['bentuk'] ?? 'Paket Soal'));
    if (count($items) === 0) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Tidak ada soal untuk diunduh.']);
        exit;
    }
    $items = array_values(array_filter($items, 'is_array'));
    foreach ($items as $idx => &$it_dl) {
        $bb = trim((string)($it_dl['bentuk'] ?? $bentuk_default_dl));
        if (!in_array($bb, $bentuk_allow_dl, true)) {
            $bb = in_array($bentuk_default_dl, $bentuk_allow_dl, true) ? $bentuk_default_dl : 'Pilihan Ganda';
        }
        $it_dl['bentuk'] = $bb;
        $it_dl['no'] = $idx + 1;
    }
    unset($it_dl);
    $bentuk = 'Paket Soal';
    $jenis_asesmen_dl = trim((string)($payload['jenis_asesmen'] ?? ''));
    if (!in_array($jenis_asesmen_dl, ai_asesmen_list(), true)) {
        $jenis_asesmen_dl = ai_asesmen_list()[0];
    }
    $nama_ctx = [];
    $dl_mapel_id = (int)($payload['id_mapel'] ?? 0);
    $dl_kelas_id = (int)($payload['id_kelas'] ?? 0);
    $mapel_in = trim((string)($payload['mapel'] ?? ''));
    $kelas_in = trim((string)($payload['kelas'] ?? ''));
    if ($mapel_in !== '' && $mapel_in !== '-') {
        $nama_ctx['mapel'] = $mapel_in;
    } else {
        [$nm_dl, $kl_dl] = ai_nama_mapel_kelas($pdo, $dl_mapel_id, $dl_kelas_id);
        $nama_ctx['mapel'] = ($nm_dl !== '' && $nm_dl !== '-') ? $nm_dl : 'Mapel';
    }
    if ($kelas_in !== '' && $kelas_in !== '-') {
        $nama_ctx['kelas'] = $kelas_in;
    } else {
        if (!isset($kl_dl)) { [$nm_dl, $kl_dl] = ai_nama_mapel_kelas($pdo, $dl_mapel_id, $dl_kelas_id); }
        $nama_ctx['kelas'] = ($kl_dl !== '' && $kl_dl !== '-') ? $kl_dl : 'Kelas';
    }
    $sem_dl = trim((string)($payload['semester'] ?? ''));
    if (stripos($sem_dl, '1') !== false || stripos(strtolower($sem_dl), 'ganjil') !== false) {
        $sem_dl = 'Ganjil';
    } elseif (stripos($sem_dl, '2') !== false || stripos(strtolower($sem_dl), 'genap') !== false) {
        $sem_dl = 'Genap';
    }
    $nama_ctx['semester'] = $sem_dl !== '' ? $sem_dl : 'Semester';
    $school_dl = getSchoolProfile($pdo);
    $nama_ctx['tahun'] = trim((string)($school_dl['tahun_ajaran'] ?? date('Y')));
    $file_pdf = ai_nama_file_asesmen($jenis_asesmen_dl, 'pdf', $nama_ctx);
    $file_xlsx = ai_nama_file_asesmen($jenis_asesmen_dl, 'xlsx', $nama_ctx);
    $file_docx = ai_nama_file_asesmen($jenis_asesmen_dl, 'docx', $nama_ctx);
    require_once '../vendor/autoload.php';

    while (ob_get_level()) {
        ob_end_clean();
    }
    header_remove('Content-Type');

    if ($format === 'xlsx') {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => 'PhpSpreadsheet tidak tersedia.']);
            exit;
        }
        $ai_tabel_txt = function ($it) {
            if (empty($it['tabel']) || !is_array($it['tabel'])) {
                return '';
            }
            $baris = [];
            foreach ($it['tabel'] as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $baris[] = (string)($r['no'] ?? '') . '. ' . (string)($r['kiri'] ?? '') . ' | ' . (string)($r['huruf'] ?? '') . '. ' . (string)($r['kanan'] ?? '');
            }
            return implode("\n", $baris);
        };
        $ss = new PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $ss->getActiveSheet();
        $kisi_dl = is_array($payload['kisi_kisi'] ?? null) ? array_values(array_filter($payload['kisi_kisi'], 'is_array')) : [];
        // Sheet 1: Kisi-kisi (No, Bentuk, Materi, CP, TP, Indikator, Level, Kesulitan, Bobot).
        $sh->setTitle('Kisi-Kisi');
        $sh->fromArray(['No', 'Bentuk', 'Materi', 'CP', 'TP', 'Indikator', 'Level Kognitif', 'Kesulitan', 'Bobot'], null, 'A1');
        $row = 2;
        foreach ($kisi_dl as $k) {
            $sh->fromArray([
                (string)($k['no'] ?? ''), (string)($k['bentuk'] ?? ''),
                (string)($k['materi'] ?? ''), (string)($k['cp'] ?? ''), (string)($k['tp'] ?? ''),
                (string)($k['indikator'] ?? ''), (string)($k['level_kognitif'] ?? 'L2'),
                (string)($k['kesulitan'] ?? ''), (string)($k['bobot'] ?? ''),
            ], null, 'A' . $row);
            $row++;
        }
        foreach (range('A', 'I') as $c) {
            $sh->getColumnDimension($c)->setAutoSize(true);
        }
        // Sheet 2: Soal (No, Bentuk, Level, Pertanyaan, Tabel Jodoh, Opsi A-D, Kunci, Pembahasan).
        $sh2 = $ss->createSheet();
        $sh2->setTitle('Soal');
        $sh2->fromArray(['No', 'Bentuk', 'Level Kognitif', 'Pertanyaan', 'Tabel Menjodohkan (No|Soal|Huruf|Jawaban)', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Kunci', 'Pembahasan'], null, 'A1');
        $row = 2;
        foreach ($items as $i => $it) {
            $opsi_dl = (isset($it['opsi']) && is_array($it['opsi'])) ? $it['opsi'] : [];
            $sh2->fromArray([
                $i + 1, (string)($it['bentuk'] ?? 'Pilihan Ganda'), (string)($it['level_kognitif'] ?? 'L2'),
                (string)($it['pertanyaan'] ?? ''), $ai_tabel_txt($it),
                (string)($opsi_dl['A'] ?? ''), (string)($opsi_dl['B'] ?? ''),
                (string)($opsi_dl['C'] ?? ''), (string)($opsi_dl['D'] ?? ''),
                (string)($it['kunci'] ?? ''), (string)($it['pembahasan'] ?? ''),
            ], null, 'A' . $row);
            $row++;
        }
        foreach (range('A', 'K') as $c) {
            $sh2->getColumnDimension($c)->setAutoSize(true);
        }
        $ss->setActiveSheetIndex(0);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $file_xlsx . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        $w = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $w->save('php://output');
        exit;
    }

    if ($format === 'docx') {
        // DOCX murni tanpa PhpWord: ZIP manual (stored) + WordprocessingML minimal.
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $file_docx . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        echo ai_build_soal_docx($items, $payload);
        exit;
    }

    // Default PDF via Dompdf.
    if (!class_exists('Dompdf\Dompdf')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'Dompdf tidak tersedia.']);
        exit;
    }
    $ai_tabel_html = function ($it) {
        if (($it['bentuk'] ?? '') !== 'Menjodohkan' || empty($it['tabel']) || !is_array($it['tabel'])) {
            return '';
        }
        $h = '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:11px;">'
            . '<thead><tr><th width="8%">No</th><th>Soal</th><th width="10%">Huruf</th><th>Pilihan Jawaban</th></tr></thead><tbody>';
        foreach ($it['tabel'] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $h .= '<tr><td style="text-align:center;">' . htmlspecialchars((string)($r['no'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($r['kiri'] ?? '')) . '</td>'
                . '<td style="text-align:center;"><b>' . htmlspecialchars((string)($r['huruf'] ?? '')) . '</b></td>'
                . '<td>' . htmlspecialchars((string)($r['kanan'] ?? '')) . '</td></tr>';
        }
        return $h . '</tbody></table>';
    };
    $kisi_pdf = is_array($payload['kisi_kisi'] ?? null) ? array_values(array_filter($payload['kisi_kisi'], 'is_array')) : [];
    $html = '<h3>' . htmlspecialchars($jenis_asesmen_dl) . '</h3>';
    if ($kisi_pdf) {
        $html .= '<h4>Kisi-Kisi</h4><table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:11px;">'
            . '<thead><tr><th>No</th><th>Bentuk</th><th>Materi</th><th>CP</th><th>TP</th><th>Indikator</th><th>Level</th><th>Kesulitan</th></tr></thead><tbody>';
        foreach ($kisi_pdf as $k) {
            $html .= '<tr><td>' . htmlspecialchars((string)($k['no'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['bentuk'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['materi'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['cp'] ?? '-')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['tp'] ?? '-')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['indikator'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['level_kognitif'] ?? 'L2')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['kesulitan'] ?? '')) . '</td></tr>';
        }
        $html .= '</tbody></table><br/>';
    }
    $html .= '<h4>Soal</h4><ol>';
    foreach ($items as $it) {
        $html .= '<li><p><b>[' . htmlspecialchars((string)($it['bentuk'] ?? 'Soal')) . '][' . htmlspecialchars((string)($it['level_kognitif'] ?? 'L2')) . ']</b> ' . nl2br(htmlspecialchars((string)($it['pertanyaan'] ?? ''))) . '</p>';
        $tbl = $ai_tabel_html($it);
        if ($tbl !== '') {
            $html .= $tbl;
        } elseif (!empty($it['opsi']) && is_array($it['opsi'])) {
            $html .= '<ul>';
            foreach (['A', 'B', 'C', 'D'] as $k) {
                if (trim((string)($it['opsi'][$k] ?? '')) !== '') {
                    $html .= '<li><b>' . $k . '.</b> ' . htmlspecialchars((string)$it['opsi'][$k]) . '</li>';
                }
            }
            $html .= '</ul>';
        }
        $html .= '<p><b>Kunci:</b> ' . htmlspecialchars((string)($it['kunci'] ?? '-')) . '</p>';
        $html .= '<p><i>' . nl2br(htmlspecialchars((string)($it['pembahasan'] ?? ''))) . '</i></p></li>';
    }
    $html .= '</ol>';
    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $file_pdf . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    echo $dompdf->output();
    exit;
}

if ($aksi === 'unduh_paket') {
    @ini_set('display_errors', '0');
    $kode_paket = trim((string)($_GET['kode_paket'] ?? ''));
    $format = strtolower(trim((string)($_GET['format'] ?? 'pdf')));
    if ($format === 'xls') { $format = 'xlsx'; }
    if ($format === 'doc') { $format = 'docx'; }
    if ($kode_paket === '') {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Kode paket tidak valid.']);
        exit;
    }
    $st = $pdo->prepare("
        SELECT b.*, m.nama_mapel, k.nama_kelas
        FROM tb_bank_soal b
        LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
        LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
        WHERE b.kode_paket = ? AND b.id_guru = ?
        ORDER BY b.id ASC
    ");
    $st->execute([$kode_paket, $guru_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $st2 = $pdo->prepare("
            SELECT b.*, m.nama_mapel, k.nama_kelas
            FROM tb_bank_soal b
            LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
            LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
            WHERE b.kode_paket = ?
            ORDER BY b.id ASC
        ");
        $st2->execute([$kode_paket]);
        $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$rows) {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => 'Paket tidak ditemukan.']);
        exit;
    }
    // Kisi-kisi hanya ditulis bila paket menyimpan data kisi asli (bukan upload manual).
    $has_kisi_pk = false;
    foreach ($rows as $rr) {
        $ind0 = trim((string)($rr['indikator'] ?? ''));
        $cp0 = trim((string)($rr['cp'] ?? ''));
        $tp0 = trim((string)($rr['tp'] ?? ''));
        if ($ind0 !== '' && $ind0 !== '-' && (($cp0 !== '' && $cp0 !== '-') || ($tp0 !== '' && $tp0 !== '-'))) {
            $has_kisi_pk = true;
            break;
        }
    }
    $items = [];
    $kisi_pk = [];
    foreach ($rows as $idx_r => $r) {
        $opsi_arr = [];
        $tabel_arr = [];
        if ($r['pilihan_jawaban']) {
            $parsed_pj = json_decode($r['pilihan_jawaban'], true) ?: [];
            if (isset($parsed_pj[0]['no']) || isset($parsed_pj[0]['kiri'])) {
                $tabel_arr = $parsed_pj;
            } else {
                $opsi_arr = $parsed_pj;
            }
        }
        $pertanyaan_val = $r['pertanyaan'];
        if ($r['jenis_soal'] === 'Menjodohkan') {
            if (empty($tabel_arr)) {
                [$clean_p, $tabel_arr] = ai_parse_menjodohkan_text($pertanyaan_val);
                $pertanyaan_val = $clean_p;
            } else {
                [$clean_p] = ai_parse_menjodohkan_text($pertanyaan_val);
                $pertanyaan_val = $clean_p;
            }
        }
        $items[] = [
            'bentuk' => $r['jenis_soal'],
            'level_kognitif' => $r['level_kognitif'] ?? 'L2',
            'pertanyaan' => $pertanyaan_val,
            'opsi' => $opsi_arr,
            'tabel' => $tabel_arr,
            'kunci' => $r['jawaban_benar'] ?? '',
            'pembahasan' => $r['pembahasan'] ?? '',
        ];
        $materi_item = $r['topik'] ?? ($r['materi_tp'] ?? '-');
        $cp_val = trim((string)($r['cp'] ?? ''));
        $tp_val = trim((string)($r['tp'] ?? ''));
        if ($cp_val === '' || $cp_val === '-') {
            $cp_val = 'Memahami dan menguasai materi ' . $materi_item . ' sesuai capaian pembelajaran kurikulum.';
        }
        if ($tp_val === '' || $tp_val === '-') {
            $tp_val = 'Menganalisis, mengidentifikasi, dan menerapkan konsep ' . $materi_item . ' dalam pemecahan masalah.';
        }
        if ($r['jenis_soal'] === 'Menjodohkan' && !empty($tabel_arr)) {
            foreach ($tabel_arr as $sub_i => $tr) {
                $sub_kiri = trim((string)($tr['kiri'] ?? ''));
                $sub_kanan = trim((string)($tr['kanan'] ?? ''));
                $sub_ind = "Peserta didik dapat menjodohkan " . ($sub_kiri ?: "butir ke-" . ($sub_i + 1)) . " dengan " . ($sub_kanan ?: "pasangannya yang tepat") . ".";
                $kisi_pk[] = [
                    'no' => count($kisi_pk) + 1,
                    'materi' => $materi_item,
                    'cp' => $cp_val,
                    'tp' => $tp_val,
                    'indikator' => $sub_ind,
                    'bentuk' => 'Menjodohkan',
                    'level_kognitif' => $r['level_kognitif'] ?? 'L2',
                    'kesulitan' => $r['tingkat_kesulitan'] ?? 'Sedang',
                    'bobot' => 1,
                ];
            }
        } else {
            $kisi_pk[] = [
                'no' => count($kisi_pk) + 1,
                'materi' => $materi_item,
                'cp' => $cp_val,
                'tp' => $tp_val,
                'indikator' => $r['indikator'] ?? '-',
                'bentuk' => $r['jenis_soal'] ?? 'Pilihan Ganda',
                'level_kognitif' => $r['level_kognitif'] ?? 'L2',
                'kesulitan' => $r['tingkat_kesulitan'] ?? 'Sedang',
                'bobot' => (float)($r['bobot'] ?? 1),
            ];
        }
    }
    $first = $rows[0];
    if (!$has_kisi_pk) {
        $kisi_pk = [];
    }
    $jenis_asesmen_pk = $first['jenis_asesmen'] ?? '';
    if (!in_array($jenis_asesmen_pk, ai_asesmen_list(), true)) {
        $jenis_asesmen_pk = ai_asesmen_list()[0];
    }
    $nama_ctx = [];
    $nama_ctx['mapel'] = ($first['nama_mapel'] ?? '') !== '' ? $first['nama_mapel'] : 'Mapel';
    $nama_ctx['kelas'] = ($first['nama_kelas'] ?? '') !== '' ? $first['nama_kelas'] : 'Kelas';
    $sem_pk = trim((string)($first['semester'] ?? ''));
    if (stripos($sem_pk, '1') !== false || stripos(strtolower($sem_pk), 'ganjil') !== false) {
        $sem_pk = 'Ganjil';
    } elseif (stripos($sem_pk, '2') !== false || stripos(strtolower($sem_pk), 'genap') !== false) {
        $sem_pk = 'Genap';
    }
    $nama_ctx['semester'] = $sem_pk !== '' ? $sem_pk : 'Semester';
    $school_pk = getSchoolProfile($pdo);
    $nama_ctx['tahun'] = trim((string)($school_pk['tahun_ajaran'] ?? date('Y')));
    $payload_pk = [
        'jenis_asesmen' => $jenis_asesmen_pk,
        'kurikulum' => $first['kurikulum'] ?? 'PERMENDIKDASMEN_046',
        'topik' => $first['topik'] ?? '',
        'sub_topik' => $first['sub_topik'] ?? '',
        'semester' => $first['semester'] ?? '',
        'kisi_kisi' => $kisi_pk,
    ];

    require_once '../vendor/autoload.php';

    while (ob_get_level()) {
        ob_end_clean();
    }
    header_remove('Content-Type');

    if ($format === 'xlsx') {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => 'PhpSpreadsheet tidak tersedia.']);
            exit;
        }
        $file_pk = ai_nama_file_asesmen($jenis_asesmen_pk, 'xlsx', $nama_ctx);
        $ss = new PhpOffice\PhpSpreadsheet\Spreadsheet();
        // Sheet 1: Kisi-Kisi
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Kisi-Kisi');
        $sh->fromArray(['No', 'Bentuk', 'Materi', 'CP', 'TP', 'Indikator', 'Level Kognitif', 'Kesulitan', 'Bobot'], null, 'A1');
        $row = 2;
        foreach ($kisi_pk as $k) {
            $sh->fromArray([
                (string)($k['no'] ?? ''), (string)($k['bentuk'] ?? ''),
                (string)($k['materi'] ?? ''), (string)($k['cp'] ?? ''), (string)($k['tp'] ?? ''),
                (string)($k['indikator'] ?? ''), (string)($k['level_kognitif'] ?? 'L2'),
                (string)($k['kesulitan'] ?? ''), (string)($k['bobot'] ?? ''),
            ], null, 'A' . $row);
            $row++;
        }
        foreach (range('A', 'I') as $c) {
            $sh->getColumnDimension($c)->setAutoSize(true);
        }
        // Sheet 2: Soal
        $sh2 = $ss->createSheet();
        $sh2->setTitle('Soal');
        $sh2->fromArray(['No', 'Bentuk', 'Level', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Kunci', 'Pembahasan'], null, 'A1');
        $row = 2;
        foreach ($items as $i => $it) {
            $o = is_array($it['opsi']) ? $it['opsi'] : [];
            $sh2->fromArray([
                $i + 1, $it['bentuk'], $it['level_kognitif'], $it['pertanyaan'],
                $o['A'] ?? '', $o['B'] ?? '', $o['C'] ?? '', $o['D'] ?? '',
                $it['kunci'], $it['pembahasan'],
            ], null, 'A' . $row);
            $row++;
        }
        foreach (range('A', 'J') as $c) {
            $sh2->getColumnDimension($c)->setAutoSize(true);
        }
        $ss->setActiveSheetIndex(0);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $file_pk . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        $w = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $w->save('php://output');
        exit;
    }

    if ($format === 'docx') {
        $file_pk = ai_nama_file_asesmen($jenis_asesmen_pk, 'docx', $nama_ctx);
        $doc_out = ai_build_soal_docx($items, $payload_pk);
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $file_pk . '"');
        header('Content-Length: ' . strlen($doc_out));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $doc_out;
        exit;
    }

    if (!class_exists('Dompdf\\Dompdf')) {
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Dompdf tidak tersedia.']);
        exit;
    }
    $file_pk = ai_nama_file_asesmen($jenis_asesmen_pk, 'pdf', $nama_ctx);
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<style>'
        . 'body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 11pt; color: #222; line-height: 1.4; padding: 10px; }'
        . 'h3 { text-align: center; margin-bottom: 2px; font-size: 15pt; }'
        . 'h4 { margin-top: 14px; margin-bottom: 6px; font-size: 12pt; border-bottom: 1px solid #ccc; padding-bottom: 3px; }'
        . 'table { border-collapse: collapse; width: 100%; font-size: 9.5pt; margin-bottom: 12px; }'
        . 'th, td { border: 1px solid #888; padding: 4px 6px; }'
        . 'th { background-color: #f0f0f0; }'
        . 'ol { padding-left: 20px; }'
        . 'li { margin-bottom: 12px; }'
        . 'ul { list-style: none; padding-left: 10px; margin: 4px 0; }'
        . 'ul li { margin-bottom: 2px; }'
        . '</style></head><body>';
    $html .= '<h3>' . htmlspecialchars($jenis_asesmen_pk) . '</h3>';
    if (!empty($nama_ctx['mapel']) || !empty($nama_ctx['kelas'])) {
        $html .= '<p style="text-align:center;font-size:10pt;color:#555;margin-top:0;">'
            . 'Mata Pelajaran: <b>' . htmlspecialchars($nama_ctx['mapel']) . '</b> | Kelas: <b>' . htmlspecialchars($nama_ctx['kelas']) . '</b>'
            . '</p>';
    }
    if (!empty($kisi_pk)) {
        $html .= '<h4>Kisi-Kisi</h4><table>'
            . '<thead><tr><th width="5%" style="text-align:center;">No</th><th>Bentuk</th><th>Materi</th><th>CP</th><th>TP</th><th>Indikator</th><th width="8%" style="text-align:center;">Level</th><th width="8%" style="text-align:center;">Kesulitan</th><th width="6%" style="text-align:center;">Bobot</th></tr></thead><tbody>';
        foreach ($kisi_pk as $k) {
            $html .= '<tr><td style="text-align:center;">' . htmlspecialchars((string)($k['no'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['bentuk'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['materi'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['cp'] ?? '-')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['tp'] ?? '-')) . '</td>'
                . '<td>' . htmlspecialchars((string)($k['indikator'] ?? '')) . '</td>'
                . '<td style="text-align:center;">' . htmlspecialchars((string)($k['level_kognitif'] ?? 'L2')) . '</td>'
                . '<td style="text-align:center;">' . htmlspecialchars((string)($k['kesulitan'] ?? '')) . '</td>'
                . '<td style="text-align:center;">' . htmlspecialchars((string)($k['bobot'] ?? '1')) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $html .= '<h4>Soal</h4><ol>';
    foreach ($items as $it) {
        $html .= '<li><p style="margin:0 0 4px;"><b>[' . htmlspecialchars($it['bentuk']) . '][' . htmlspecialchars($it['level_kognitif']) . ']</b> ' . nl2br(htmlspecialchars($it['pertanyaan'])) . '</p>';
        if ($it['bentuk'] === 'Menjodohkan' && !empty($it['tabel']) && is_array($it['tabel'])) {
            $html .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:10pt;margin:8px 0;">'
                . '<thead><tr style="background:#f0f0f0;"><th width="6%" style="text-align:center;">No</th><th>Soal</th><th width="8%" style="text-align:center;">Huruf</th><th>Pilihan Jawaban</th></tr></thead><tbody>';
            foreach ($it['tabel'] as $tr) {
                if (!is_array($tr)) continue;
                $html .= '<tr><td style="text-align:center;">' . htmlspecialchars((string)($tr['no'] ?? '')) . '</td>'
                    . '<td>' . htmlspecialchars((string)($tr['kiri'] ?? '')) . '</td>'
                    . '<td style="text-align:center;font-weight:bold;">' . htmlspecialchars((string)($tr['huruf'] ?? '')) . '</td>'
                    . '<td>' . htmlspecialchars((string)($tr['kanan'] ?? '')) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } elseif (!empty($it['opsi']) && is_array($it['opsi'])) {
            $html .= '<ul>';
            foreach (['A', 'B', 'C', 'D'] as $k) {
                if (trim((string)($it['opsi'][$k] ?? '')) !== '') {
                    $html .= '<li><b>' . $k . '.</b> ' . htmlspecialchars((string)$it['opsi'][$k]) . '</li>';
                }
            }
            $html .= '</ul>';
        }
        $html .= '<p style="margin:4px 0 2px;"><b>Kunci:</b> ' . htmlspecialchars($it['kunci'] ?: '-') . '</p>';
        if (trim((string)$it['pembahasan']) !== '') {
            $html .= '<p style="margin:2px 0 0;color:#555;"><i>Pembahasan: ' . nl2br(htmlspecialchars($it['pembahasan'])) . '</i></p>';
        }
        $html .= '</li>';
    }
    $html .= '</ol></body></html>';
    $dompdf = new Dompdf\Dompdf([
        'isHtml5ParserEnabled' => true,
        'isRemoteEnabled' => true,
        'defaultFont' => 'sans-serif'
    ]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdf_out = $dompdf->output();

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $file_pk . '"');
    header('Content-Length: ' . strlen($pdf_out));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $pdf_out;
    exit;
}

if ($aksi === 'detail_paket') {
    $kode_paket = trim((string)($_GET['kode_paket'] ?? ''));
    if ($kode_paket === '') {
        echo json_encode(['ok' => false, 'msg' => 'Kode paket tidak valid.']);
        exit;
    }
    $st = $pdo->prepare("
        SELECT b.*, m.nama_mapel, k.nama_kelas
        FROM tb_bank_soal b
        LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
        LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
        WHERE b.kode_paket = ? AND b.id_guru = ?
        ORDER BY b.id ASC
    ");
    $st->execute([$kode_paket, $guru_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo json_encode(['ok' => false, 'msg' => 'Paket tidak ditemukan.']);
        exit;
    }
    // Kisi-kisi hanya dianggap ADA bila paket menyimpan data kisi asli
    // (indikator + CP/TP terisi) — bukan paket upload manual tanpa kisi.
    $has_kisi = false;
    foreach ($rows as $rr) {
        $ind0 = trim((string)($rr['indikator'] ?? ''));
        $cp0 = trim((string)($rr['cp'] ?? ''));
        $tp0 = trim((string)($rr['tp'] ?? ''));
        if ($ind0 !== '' && $ind0 !== '-' && (($cp0 !== '' && $cp0 !== '-') || ($tp0 !== '' && $tp0 !== '-'))) {
            $has_kisi = true;
            break;
        }
    }
    $items = [];
    $kisi_items = [];
    foreach ($rows as $idx_r => $r) {
        $opsi_arr = [];
        $tabel_arr = [];
        if ($r['pilihan_jawaban']) {
            $parsed_pj = json_decode($r['pilihan_jawaban'], true) ?: [];
            if (isset($parsed_pj[0]['no']) || isset($parsed_pj[0]['kiri'])) {
                $tabel_arr = $parsed_pj;
            } else {
                $opsi_arr = $parsed_pj;
            }
        }
        $pertanyaan_val = $r['pertanyaan'];
        if ($r['jenis_soal'] === 'Menjodohkan') {
            if (empty($tabel_arr)) {
                [$clean_p, $tabel_arr] = ai_parse_menjodohkan_text($pertanyaan_val);
                $pertanyaan_val = $clean_p;
            } else {
                [$clean_p] = ai_parse_menjodohkan_text($pertanyaan_val);
                $pertanyaan_val = $clean_p;
            }
        }
        $materi_item = $r['topik'] ?? ($r['materi_tp'] ?? '-');
        $cp_val = trim((string)($r['cp'] ?? ''));
        $tp_val = trim((string)($r['tp'] ?? ''));
        if ($cp_val === '' || $cp_val === '-') {
            $cp_val = 'Memahami dan menguasai materi ' . $materi_item . ' sesuai capaian pembelajaran kurikulum.';
        }
        if ($tp_val === '' || $tp_val === '-') {
            $tp_val = 'Menganalisis, mengidentifikasi, dan menerapkan konsep ' . $materi_item . ' dalam pemecahan masalah.';
        }
        $items[] = [
            'id' => (int)$r['id'],
            'kode_soal' => $r['kode_soal'],
            'bentuk' => $r['jenis_soal'],
            'level_kognitif' => $r['level_kognitif'] ?? 'L2',
            'tingkat_kesulitan' => $r['tingkat_kesulitan'] ?? 'Sedang',
            'pertanyaan' => $pertanyaan_val,
            'opsi' => $opsi_arr,
            'tabel' => $tabel_arr,
            'kunci' => $r['jawaban_benar'] ?? '',
            'pembahasan' => $r['pembahasan'] ?? '',
            'cp' => $cp_val,
            'tp' => $tp_val,
            'indikator' => $r['indikator'] ?? '',
        ];
        if ($r['jenis_soal'] === 'Menjodohkan' && !empty($tabel_arr)) {
            foreach ($tabel_arr as $sub_i => $tr) {
                $sub_kiri = trim((string)($tr['kiri'] ?? ''));
                $sub_kanan = trim((string)($tr['kanan'] ?? ''));
                $sub_ind = "Peserta didik dapat menjodohkan " . ($sub_kiri ?: "butir ke-" . ($sub_i + 1)) . " dengan " . ($sub_kanan ?: "pasangannya yang tepat") . ".";
                $kisi_items[] = [
                    'no' => count($kisi_items) + 1,
                    'materi' => $materi_item,
                    'cp' => $cp_val,
                    'tp' => $tp_val,
                    'indikator' => $sub_ind,
                    'bentuk' => 'Menjodohkan',
                    'level_kognitif' => $r['level_kognitif'] ?? 'L2',
                    'kesulitan' => $r['tingkat_kesulitan'] ?? 'Sedang',
                    'bobot' => 1,
                ];
            }
        } else {
            $kisi_items[] = [
                'no' => count($kisi_items) + 1,
                'materi' => $materi_item,
                'cp' => $cp_val,
                'tp' => $tp_val,
                'indikator' => $r['indikator'] ?? '-',
                'bentuk' => $r['jenis_soal'] ?? 'Pilihan Ganda',
                'level_kognitif' => $r['level_kognitif'] ?? 'L2',
                'kesulitan' => $r['tingkat_kesulitan'] ?? 'Sedang',
                'bobot' => (float)($r['bobot'] ?? 1),
            ];
        }
    }
    $first = $rows[0];
    if (!$has_kisi) {
        $kisi_items = [];
    }
    // File asli paket (bila paket berasal dari upload manual) — preview memakai format asli.
    // Isi file ditanam base64 di JSON agar pratinjau tidak memakai request URL
    // (request URL PDF dibajak pengelola unduhan/IDM menjadi dialog download).
    $file_asli = null;
    $file_ext = '';
    $file_base64 = null;
    foreach ($rows as $r) {
        $fs = trim((string)($r['file_soal'] ?? ''));
        if ($fs !== '') {
            $url = function_exists('guru_file_url') ? guru_file_url('bank_soal', $fs) : '../uploads/bank_soal/' . ltrim($fs, '/');
            if ($url) {
                $file_asli = $url;
                $file_ext = strtolower(pathinfo($fs, PATHINFO_EXTENSION));
                $fp = dirname(__DIR__) . '/uploads/bank_soal/' . ltrim(str_replace('\\', '/', $fs), '/');
                if (is_file($fp) && filesize($fp) > 0 && filesize($fp) <= 8 * 1024 * 1024) {
                    $file_base64 = base64_encode((string)@file_get_contents($fp));
                }
                break;
            }
        }
    }
    echo json_encode([
        'ok' => true,
        'kode_paket' => $kode_paket,
        'jenis_asesmen' => $first['jenis_asesmen'] ?? '-',
        'mapel' => $first['nama_mapel'] ?? '-',
        'kelas' => $first['nama_kelas'] ?? '-',
        'kurikulum' => $first['kurikulum'] ?? 'PERMENDIKDASMEN_046',
        'semester' => $first['semester'] ?? '-',
        'topik' => $first['topik'] ?? '-',
        'sub_topik' => $first['sub_topik'] ?? '',
        'jumlah' => count($items),
        'has_kisi' => $has_kisi,
        'kisi_kisi' => $kisi_items,
        'items' => $items,
        'file_soal' => $file_asli,
        'file_ext' => $file_ext,
        'file_base64' => $file_base64,
    ]);
    exit;
}

if ($aksi === 'upload') {
    if (empty($_FILES['file_soal']) || (int)$_FILES['file_soal']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'Pilih file soal untuk diupload.']);
        exit;
    }
    $tmp = (string)$_FILES['file_soal']['tmp_name'];
    $name = strtolower((string)$_FILES['file_soal']['name']);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    if (!in_array($ext, ['xlsx', 'docx'], true)) {
        echo json_encode(['ok' => false, 'msg' => 'Format file harus .xlsx atau .docx.']);
        exit;
    }

    $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
    $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
    $kurikulum = in_array($_POST['kurikulum'] ?? '', ['PERMENDIKDASMEN_046', 'KMA_1503_KBC'], true) ? $_POST['kurikulum'] : 'PERMENDIKDASMEN_046';
    $jenis_asesmen = trim((string)($_POST['jenis_asesmen'] ?? ''));
    if (!in_array($jenis_asesmen, ai_asesmen_list(), true)) {
        $jenis_asesmen = ai_asesmen_list()[0];
    }
    $semester = trim((string)($_POST['semester'] ?? ''));
    if ($semester !== '' && !in_array($semester, ['Semester 1', 'Semester 2'], true)) {
        $semester = mb_substr($semester, 0, 20);
    }
    $topik = trim((string)($_POST['topik'] ?? ''));
    if ($topik === '') {
        $topik = pathinfo($name, PATHINFO_FILENAME);
    }
    $status = in_array($_POST['status'] ?? '', ['Draft', 'Aktif', 'Arsip'], true) ? $_POST['status'] : 'Aktif';

    require_once '../vendor/autoload.php';

    $parsed_items = [];

    if ($ext === 'xlsx') {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            echo json_encode(['ok' => false, 'msg' => 'Library PhpSpreadsheet tidak tersedia.']);
            exit;
        }
        try {
            $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
            // Cari sheet "Soal" bila ada, kalau tidak ambil sheet aktif
            $sheet = $spreadsheet->getSheetByName('Soal') ?: $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            if (count($rows) < 2) {
                echo json_encode(['ok' => false, 'msg' => 'File XLSX tidak memiliki data soal.']);
                exit;
            }
            $header = array_map(function($h) { return strtolower(trim((string)$h)); }, $rows[0]);
            
            // Map kolom berdasarkan nama header atau index default
            // Header template: No, Bentuk, Level [Kognitif], Pertanyaan, Tabel Menjodohkan, Opsi A, Opsi B, Opsi C, Opsi D, Kunci, Pembahasan
            $col_pertanyaan = array_search('pertanyaan', $header);
            if ($col_pertanyaan === false) { $col_pertanyaan = 3; }
            $col_bentuk = array_search('bentuk', $header);
            if ($col_bentuk === false) { $col_bentuk = 1; }
            $col_level = -1;
            foreach ($header as $idx => $h) {
                if (strpos($h, 'level') !== false) { $col_level = $idx; break; }
            }
            if ($col_level === -1) { $col_level = 2; }
            $col_opsi_a = array_search('opsi a', $header);
            $col_opsi_b = array_search('opsi b', $header);
            $col_opsi_c = array_search('opsi c', $header);
            $col_opsi_d = array_search('opsi d', $header);
            if ($col_opsi_a === false) { $col_opsi_a = 5; }
            if ($col_opsi_b === false) { $col_opsi_b = 6; }
            if ($col_opsi_c === false) { $col_opsi_c = 7; }
            if ($col_opsi_d === false) { $col_opsi_d = 8; }
            $col_kunci = array_search('kunci', $header);
            if ($col_kunci === false) { $col_kunci = 9; }
            $col_pembahasan = array_search('pembahasan', $header);
            if ($col_pembahasan === false) { $col_pembahasan = 10; }

            for ($i = 1; $i < count($rows); $i++) {
                $r = $rows[$i];
                $pertanyaan = trim((string)($r[$col_pertanyaan] ?? ''));
                if ($pertanyaan === '') { continue; }
                $bentuk = trim((string)($r[$col_bentuk] ?? 'Pilihan Ganda'));
                $level = strtoupper(trim((string)($r[$col_level] ?? 'L2')));
                if (!in_array($level, ['L1', 'L2', 'L3', 'L4'], true)) { $level = 'L2'; }
                $opsi = [
                    'A' => trim((string)($r[$col_opsi_a] ?? '')),
                    'B' => trim((string)($r[$col_opsi_b] ?? '')),
                    'C' => trim((string)($r[$col_opsi_c] ?? '')),
                    'D' => trim((string)($r[$col_opsi_d] ?? '')),
                ];
                $kunci = trim((string)($r[$col_kunci] ?? ''));
                $pembahasan = trim((string)($r[$col_pembahasan] ?? ''));
                $parsed_items[] = [
                    'bentuk' => $bentuk,
                    'level' => $level,
                    'pertanyaan' => $pertanyaan,
                    'opsi' => $opsi,
                    'kunci' => $kunci,
                    'pembahasan' => $pembahasan,
                ];
            }
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Gagal membaca XLSX: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($ext === 'docx') {
        if (!class_exists('ZipArchive')) {
            echo json_encode(['ok' => false, 'msg' => 'Ekstensi ZipArchive tidak aktif.']);
            exit;
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                // Pecah berdasarkan tag paragraf
                preg_match_all('/<w:p[^>]*>(.*?)<\/w:p>/is', $xml, $matches);
                $lines = [];
                foreach ($matches[1] as $p) {
                    $t = strip_tags($p);
                    $t = trim(html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($t !== '') { $lines[] = $t; }
                }
                // Parse soal dari lines: pola "1. [Bentuk][Level] Pertanyaan"
                $current_soal = null;
                foreach ($lines as $line) {
                    if (preg_match('/^(\d+)[\.\)]\s*(?:\[(.*?)\])?(?:\[(.*?)\])?\s*(.*)$/i', $line, $m)) {
                        if ($current_soal !== null && !empty($current_soal['pertanyaan'])) {
                            $parsed_items[] = $current_soal;
                        }
                        $b = trim($m[2] ?? '');
                        $lv = strtoupper(trim($m[3] ?? ''));
                        if (!in_array($lv, ['L1', 'L2', 'L3', 'L4'], true)) { $lv = 'L2'; }
                        $current_soal = [
                            'bentuk' => $b ?: 'Pilihan Ganda',
                            'level' => $lv,
                            'pertanyaan' => trim($m[4] ?? ''),
                            'opsi' => ['A' => '', 'B' => '', 'C' => '', 'D' => ''],
                            'kunci' => '',
                            'pembahasan' => '',
                        ];
                    } elseif ($current_soal !== null) {
                        if (preg_match('/^([A-D])[\.\)]\s*(.*)$/i', $line, $om)) {
                            $current_soal['opsi'][strtoupper($om[1])] = trim($om[2]);
                        } elseif (preg_match('/^kunci\s*:\s*(.*)$/i', $line, $km)) {
                            $current_soal['kunci'] = trim($km[1]);
                        } elseif (preg_match('/^pembahasan\s*:\s*(.*)$/i', $line, $pm)) {
                            $current_soal['pembahasan'] = trim($pm[1]);
                        } else {
                            $current_soal['pertanyaan'] .= "\n" . $line;
                        }
                    }
                }
                if ($current_soal !== null && !empty($current_soal['pertanyaan'])) {
                    $parsed_items[] = $current_soal;
                }
            }
        }
    }

    if (empty($parsed_items)) {
        echo json_encode(['ok' => false, 'msg' => 'Tidak ada butir soal yang berhasil dibaca dari file.']);
        exit;
    }

    $kode_paket = 'PKT-' . strtoupper(substr(uniqid('', true), -10));
    $stmt = $pdo->prepare("
        INSERT INTO tb_bank_soal (
            id_guru, kode_soal, kode_paket, jenis_soal, id_mapel, id_kelas, kurikulum, semester, jenis_asesmen, topik,
            level_kognitif, tingkat_kesulitan, bobot, pertanyaan, pilihan_jawaban, jawaban_benar, pembahasan, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
    ");
    $tersimpan = 0;
    foreach ($parsed_items as $item) {
        $kode_soal = 'SOAL-' . strtoupper(substr(uniqid(), -6));
        $opsi_json = (!empty($item['opsi']['A']) || !empty($item['opsi']['B'])) ? json_encode($item['opsi'], JSON_UNESCAPED_UNICODE) : null;
        $stmt->execute([
            $guru_id, $kode_soal, $kode_paket,
            $item['bentuk'], $id_mapel, $id_kelas, $kurikulum,
            ($semester !== '' ? $semester : null), $jenis_asesmen, $topik,
            $item['level'], 'Sedang',
            $item['pertanyaan'], $opsi_json,
            ($item['kunci'] !== '' ? $item['kunci'] : null),
            ($item['pembahasan'] !== '' ? $item['pembahasan'] : null),
            $status
        ]);
        $tersimpan++;
    }

    echo json_encode([
        'ok' => true,
        'tersimpan' => $tersimpan,
        'kode_paket' => $kode_paket,
        'msg' => $tersimpan . ' butir soal berhasil diupload ke Bank Soal.'
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal.']);
