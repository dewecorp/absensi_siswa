<?php
// Endpoint Generate Soal AI untuk guru: generate (upload materi Word/TXT + textarea),
// simpan batch ke tb_bank_soal, unduh PDF/XLSX. Konektor milik tiap guru (profil).
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/ai_helper.php';

ensure_learning_schema($pdo);
ai_helper_schema($pdo);

header('Content-Type: application/json');

if (!isAuthorized(['guru', 'wali'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Akses ditolak.']);
    exit;
}

$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}
if ($guru_id <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Identitas guru tidak ditemukan.']);
    exit;
}

$aksi = $_POST['aksi'] ?? $_GET['aksi'] ?? 'generate';

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
        $lh = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, $dosDate, $crc, $len, $len, $nlen, 0);
        $local .= $lh . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, $dosDate, $crc, $len, $len, $nlen, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($lh) + $nlen + $len;
    }
    return $local . $central . pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
}

// Susun DOCX paket soal: judul + meta + butir (opsi, kunci, pembahasan).
function ai_docx_tabel_jodoh(array $rows): string {
    $xml = '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">Tabel Menjodohkan (No | Soal | Huruf | Pilihan Jawaban):</w:t></w:r></w:p>';
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $xml .= ai_docx_p(
            (string)($r['no'] ?? '') . ' | ' . (string)($r['kiri'] ?? '') . ' | '
            . (string)($r['huruf'] ?? '') . ' | ' . (string)($r['kanan'] ?? '')
        );
    }
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
    $meta[] = 'Jumlah: ' . count($items) . ' butir';
    foreach ($meta as $m) {
        $body .= ai_docx_p($m);
    }
    $body .= ai_docx_p('');
    // Kisi-kisi ringkas (bila dikirim).
    $kisi = is_array($payload['kisi_kisi'] ?? null) ? $payload['kisi_kisi'] : [];
    if ($kisi) {
        $body .= ai_docx_p('KISI-KISI', true, 24);
        foreach ($kisi as $k) {
            if (!is_array($k)) {
                continue;
            }
            $body .= ai_docx_p(
                (string)($k['no'] ?? '') . '. [' . (string)($k['bentuk'] ?? '') . '][' . (string)($k['level_kognitif'] ?? 'L2') . '] '
                . (string)($k['materi'] ?? '')
            );
            if (trim((string)($k['cp'] ?? '')) !== '') {
                $body .= ai_docx_p('CP: ' . (string)$k['cp']);
            }
            if (trim((string)($k['tp'] ?? '')) !== '') {
                $body .= ai_docx_p('TP: ' . (string)$k['tp']);
            }
            if (trim((string)($k['indikator'] ?? '')) !== '') {
                $body .= ai_docx_p('Indikator: ' . (string)$k['indikator'] . ' | Kesulitan: ' . (string)($k['kesulitan'] ?? ''));
            }
        }
        $body .= ai_docx_p('');
    }
    foreach ($items as $i => $it) {
        $body .= ai_docx_p(($i + 1) . '. [' . (string)($it['bentuk'] ?? 'Soal') . '][' . (string)($it['level_kognitif'] ?? 'L2') . '] ' . (string)($it['pertanyaan'] ?? ''), true);
        if (($it['bentuk'] ?? '') === 'Menjodohkan' && !empty($it['tabel']) && is_array($it['tabel'])) {
            $body .= ai_docx_tabel_jodoh($it['tabel']);
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
        'kesulitan' => $kesulitan,
    ]);

    $cfg = ai_guru_config($pdo, $guru_id);
    if ($cfg['provider'] === 'openai') {
        [$ok, $data] = ai_generate_soal('openai', $cfg['openai_key'], $cfg['openai_model'], $prompt);
    } else {
        [$ok, $data] = ai_generate_soal('gemini', $cfg['gemini_key'], $cfg['gemini_model'], $prompt);
    }
    if (!$ok) {
        echo json_encode(['ok' => false, 'msg' => is_string($data) ? $data : 'Gagal generate soal.']);
        exit;
    }
    // Normalisasi: tiap butir wajib punya bentuk valid + nomor urut paket.
    $expected = [];
    foreach ($paket as $b => $n) {
        for ($i = 0; $i < $n; $i++) {
            $expected[] = $b;
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
        $huruf_seq = ['A', 'B', 'C', 'D', 'E'];
        foreach (array_slice($t, 0, 6) as $idx => $r) {
            if (!is_array($r)) {
                continue;
            }
            $no = (int)($r['no'] ?? ($idx + 1));
            if ($no <= 0) {
                $no = $idx + 1;
            }
            $kiri = trim((string)($r['kiri'] ?? ($r['soal'] ?? ($r['kiri_soal'] ?? ''))));
            $huruf = strtoupper(trim((string)($r['huruf'] ?? '')));
            if (!preg_match('/^[A-E]$/', $huruf)) {
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
        $stmt = $pdo->prepare("
            INSERT INTO tb_bank_soal (
                id_guru, kode_soal, jenis_soal, id_mapel, id_kelas, kurikulum, semester, jenis_asesmen, topik, sub_topik, materi_tp,
                cp, tp, indikator, level_kognitif, tingkat_kesulitan, bobot, pertanyaan,
                pilihan_jawaban, jawaban_benar, pembahasan, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            // Gabung tabel menjodohkan ke pertanyaan agar tersimpan rapi di bank soal.
            if ($bentuk_item === 'Menjodohkan' && $tabel_item) {
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
            $cp_item = trim((string)($it['cp'] ?? ''));
            $tp_item = trim((string)($it['tp'] ?? ''));
            $stmt->execute([
                $guru_id,
                'SOAL-' . strtoupper(substr(uniqid(), -6)),
                $bentuk_item,
                $id_mapel, $id_kelas, $kurikulum, ($semester_simpan !== '' ? $semester_simpan : null), $jenis_asesmen_simpan,
                $topik_simpan, ($sub_topik_simpan !== '' ? $sub_topik_simpan : null),
                ($materi !== '' ? $materi : null),
                ($cp_item !== '' ? $cp_item : null), ($tp_item !== '' ? $tp_item : null),
                trim((string)($it['indikator'] ?? '')) ?: null, $lv_item,
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
    echo json_encode(['ok' => true, 'tersimpan' => $tersimpan]);
    exit;
}

if ($aksi === 'unduh') {
    // Unduh PDF/XLSX/DOCX dari hasil preview (dikirim sebagai JSON). Output file, bukan JSON.
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Payload unduhan tidak valid.']);
        exit;
    }
    $format = strtolower((string)($payload['format'] ?? 'pdf'));
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $bentuk_allow_dl = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
    $bentuk_default_dl = trim((string)($payload['bentuk'] ?? 'Paket Soal'));
    if (count($items) === 0) {
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
    [$nm_dl, $kl_dl] = ai_nama_mapel_kelas($pdo, $dl_mapel_id, $dl_kelas_id);
    $nama_ctx['mapel'] = ($nm_dl !== '' && $nm_dl !== '-') ? $nm_dl : 'Mapel';
    $nama_ctx['kelas'] = ($kl_dl !== '' && $kl_dl !== '-') ? $kl_dl : 'Kelas';
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

    if ($format === 'xlsx') {
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            http_response_code(500);
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
        $w = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $w->save('php://output');
        exit;
    }

    if ($format === 'docx') {
        // DOCX murni tanpa PhpWord: ZIP manual (stored) + WordprocessingML minimal.
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $file_docx . '"');
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
    echo $dompdf->output();
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal.']);
