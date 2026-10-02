<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/ai_helper.php';

ensure_learning_schema($pdo);
ai_helper_schema($pdo);

if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$message = null;

// Auto-assign kode_paket untuk soal lepas (tanpa paket)
try {
    $stmtLepas = $pdo->prepare("SELECT id, topik, id_mapel, id_kelas, jenis_asesmen FROM tb_bank_soal WHERE id_guru = ? AND (kode_paket IS NULL OR kode_paket = '')");
    $stmtLepas->execute([$guru_id]);
    $soalLepas = $stmtLepas->fetchAll(PDO::FETCH_ASSOC);
    if ($soalLepas) {
        $groups = [];
        foreach ($soalLepas as $sl) {
            $key = ($sl['topik'] ?? 'Lain-lain') . '|' . ($sl['id_mapel'] ?? 0) . '|' . ($sl['id_kelas'] ?? 0) . '|' . ($sl['jenis_asesmen'] ?? '');
            $groups[$key][] = (int)$sl['id'];
        }
        foreach ($groups as $ids) {
            $kp = 'PKT-' . strtoupper(substr(uniqid('', true), -10));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE tb_bank_soal SET kode_paket = ? WHERE id IN ($placeholders) AND id_guru = ?")->execute(array_merge([$kp], $ids, [$guru_id]));
        }
    }
} catch (Exception $e) {}

// Helper: parse teks soal berformat nomor
function parse_text_lines_to_soal(string $text): array {
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $items = [];
    $current = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^(?:No\.?\s*)?(\d+)[\.\)]\s*(?:\[(.*?)\])?(?:\[(.*?)\])?\s*(.*)$/i', $line, $m)) {
            if ($current !== null && !empty($current['pertanyaan'])) {
                $items[] = $current;
            }
            $bentuk = trim($m[2] ?? '');
            $level = strtoupper(trim($m[3] ?? ''));
            if (!in_array($level, ['L1', 'L2', 'L3', 'L4'], true)) {
                $level = 'L2';
            }
            $current = [
                'bentuk' => $bentuk ?: 'Pilihan Ganda',
                'level' => $level,
                'pertanyaan' => trim($m[4] ?? ''),
                'opsi' => ['A' => '', 'B' => '', 'C' => '', 'D' => ''],
                'kunci' => '',
                'pembahasan' => '',
            ];
        } elseif ($current !== null) {
            if (preg_match('/^([A-D])[\.\)]\s*(.*)$/i', $line, $om)) {
                $current['opsi'][strtoupper($om[1])] = trim($om[2]);
            } elseif (preg_match('/^(?:Kunci(?:\s*Jawaban)?|Jawaban Benar)\s*:\s*(.*)$/i', $line, $km)) {
                $current['kunci'] = trim($km[1]);
            } elseif (preg_match('/^Pembahasan\s*:\s*(.*)$/i', $line, $pm)) {
                $current['pembahasan'] = trim($pm[1]);
            } else {
                $current['pertanyaan'] .= "\n" . $line;
            }
        }
    }
    if ($current !== null && !empty($current['pertanyaan'])) {
        $items[] = $current;
    }
    return $items;
}

// Helper: baca butir soal dari file yang diupload (.xlsx, .xls, .docx, .doc, .pdf, .txt)
function parse_uploaded_soal_file(string $tmpPath, string $originalName): array {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $parsed_items = [];

    if ($ext === 'xlsx' || $ext === 'xls') {
        require_once '../vendor/autoload.php';
        if (class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            try {
                $spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
                $sheet = $spreadsheet->getSheetByName('Soal') ?: $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();
                if (count($rows) >= 2) {
                    $header = array_map(function($h) { return strtolower(trim((string)$h)); }, $rows[0]);
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
                }
            } catch (Exception $e) {}
        }
    } elseif ($ext === 'docx') {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml !== false) {
                    preg_match_all('/<w:p[^>]*>(.*?)<\/w:p>/is', $xml, $matches);
                    $lines = [];
                    foreach ($matches[1] as $p) {
                        $t = strip_tags($p);
                        $t = trim(html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        if ($t !== '') { $lines[] = $t; }
                    }
                    $parsed_items = parse_text_lines_to_soal(implode("\n", $lines));
                }
            }
        }
    } elseif ($ext === 'pdf') {
        $content = @file_get_contents($tmpPath);
        $text = '';
        if ($content && preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/is', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $unc = @gzuncompress($stream);
                if ($unc === false) { $unc = @gzinflate($stream); }
                if ($unc === false) { $unc = $stream; }
                if (preg_match_all('/\((.*?)\)\s*Tj/is', $unc, $tjs)) {
                    $text .= implode(" ", $tjs[1]) . "\n";
                } elseif (preg_match_all('/\[(.*?)\]\s*TJ/is', $unc, $tjs)) {
                    foreach ($tjs[1] as $tj) {
                        if (preg_match_all('/\((.*?)\)/is', $tj, $parts)) {
                            $text .= implode("", $parts[1]);
                        }
                    }
                    $text .= "\n";
                }
            }
        }
        if (trim($text) === '' && $content) {
            if (preg_match_all('/\((.*?)\)/', $content, $m)) {
                $text = implode(" ", $m[1]);
            }
        }
        if (trim($text) !== '') {
            $parsed_items = parse_text_lines_to_soal($text);
        }
    } elseif ($ext === 'txt') {
        $text = (string)@file_get_contents($tmpPath);
        if (trim($text) !== '') {
            $parsed_items = parse_text_lines_to_soal($text);
        }
    } elseif ($ext === 'doc') {
        $content = (string)@file_get_contents($tmpPath);
        if ($content !== '') {
            $text = preg_replace('/[^\x20-\x7E\xA0-\xFF\r\n\t]/', '', $content);
            $parsed_items = parse_text_lines_to_soal($text);
        }
    }

    return $parsed_items;
}

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $kode_paket = trim((string)($_POST['kode_paket'] ?? ''));
        $jenis_asesmen = trim((string)($_POST['jenis_asesmen'] ?? ''));
        if (!in_array($jenis_asesmen, ai_asesmen_list(), true)) {
            $jenis_asesmen = ai_asesmen_list()[0];
        }
        $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
        $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $topik = trim((string)($_POST['topik'] ?? ''));
        $jumlah_soal = max(1, min(100, (int)($_POST['jumlah_soal'] ?? 5)));
        $tanggal_upload = trim((string)($_POST['tanggal_upload'] ?? ''));
        if ($tanggal_upload === '') {
            $tanggal_upload = date('Y-m-d');
        }
        $created_at = $tanggal_upload . ' ' . date('H:i:s');

        // Cek file upload
        $has_file = !empty($_FILES['file_soal']) && (int)$_FILES['file_soal']['error'] === UPLOAD_ERR_OK;
        $file_items = [];
        if ($has_file) {
            $file_items = parse_uploaded_soal_file((string)$_FILES['file_soal']['tmp_name'], (string)$_FILES['file_soal']['name']);
        }

        if ($topik === '') {
            $message = ['type' => 'warning', 'text' => 'Materi / Topik soal wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $kode_paket_new = 'PKT-' . strtoupper(substr(uniqid('', true), -10));
                    $stmtIns = $pdo->prepare("
                        INSERT INTO tb_bank_soal (
                            id_guru, kode_soal, kode_paket, jenis_soal, id_mapel, id_kelas,
                            jenis_asesmen, topik, pertanyaan, pilihan_jawaban, jawaban_benar, pembahasan,
                            level_kognitif, tingkat_kesulitan, bobot, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sedang', 1.0, 'Aktif', ?)
                    ");

                    if (!empty($file_items)) {
                        foreach ($file_items as $item) {
                            $kode_soal = 'SOAL-' . strtoupper(substr(uniqid(), -6));
                            $opsi_json = (!empty($item['opsi']['A']) || !empty($item['opsi']['B'])) ? json_encode($item['opsi'], JSON_UNESCAPED_UNICODE) : null;
                            $stmtIns->execute([
                                $guru_id, $kode_soal, $kode_paket_new,
                                $item['bentuk'] ?? 'Pilihan Ganda', $id_mapel, $id_kelas,
                                $jenis_asesmen, $topik, $item['pertanyaan'],
                                $opsi_json, ($item['kunci'] !== '' ? $item['kunci'] : null),
                                ($item['pembahasan'] !== '' ? $item['pembahasan'] : null),
                                $item['level'] ?? 'L2', $created_at
                            ]);
                        }
                        $total_saved = count($file_items);
                        $message = ['type' => 'success', 'text' => "Paket soal berhasil ditambahkan dari file ($total_saved butir soal)."];
                    } else {
                        for ($i = 1; $i <= $jumlah_soal; $i++) {
                            $kode_soal = 'SOAL-' . strtoupper(substr(uniqid(), -6));
                            $pertanyaan = "Butir Soal {$i}: Tuliskan butir pertanyaan di sini...";
                            $stmtIns->execute([
                                $guru_id, $kode_soal, $kode_paket_new,
                                'Pilihan Ganda', $id_mapel, $id_kelas,
                                $jenis_asesmen, $topik, $pertanyaan,
                                null, null, null,
                                'L2', $created_at
                            ]);
                        }
                        $message = ['type' => 'success', 'text' => 'Paket soal berhasil ditambahkan (' . $jumlah_soal . ' butir).'];
                    }
                } else {
                    // Update metadata paket
                    $stmtUpd = $pdo->prepare("
                        UPDATE tb_bank_soal SET
                            jenis_asesmen = ?,
                            id_mapel = ?,
                            id_kelas = ?,
                            topik = ?,
                            created_at = ?
                        WHERE kode_paket = ? AND id_guru = ?
                    ");
                    $stmtUpd->execute([
                        $jenis_asesmen, $id_mapel, $id_kelas, $topik, $created_at,
                        $kode_paket, $guru_id
                    ]);

                    if (!empty($file_items)) {
                        // Jika ada file baru diupload saat edit, ganti butir soal
                        $pdo->prepare("DELETE FROM tb_bank_soal WHERE kode_paket = ? AND id_guru = ?")->execute([$kode_paket, $guru_id]);
                        $stmtIns = $pdo->prepare("
                            INSERT INTO tb_bank_soal (
                                id_guru, kode_soal, kode_paket, jenis_soal, id_mapel, id_kelas,
                                jenis_asesmen, topik, pertanyaan, pilihan_jawaban, jawaban_benar, pembahasan,
                                level_kognitif, tingkat_kesulitan, bobot, status, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sedang', 1.0, 'Aktif', ?)
                        ");
                        foreach ($file_items as $item) {
                            $kode_soal = 'SOAL-' . strtoupper(substr(uniqid(), -6));
                            $opsi_json = (!empty($item['opsi']['A']) || !empty($item['opsi']['B'])) ? json_encode($item['opsi'], JSON_UNESCAPED_UNICODE) : null;
                            $stmtIns->execute([
                                $guru_id, $kode_soal, $kode_paket,
                                $item['bentuk'] ?? 'Pilihan Ganda', $id_mapel, $id_kelas,
                                $jenis_asesmen, $topik, $item['pertanyaan'],
                                $opsi_json, ($item['kunci'] !== '' ? $item['kunci'] : null),
                                ($item['pembahasan'] !== '' ? $item['pembahasan'] : null),
                                $item['level'] ?? 'L2', $created_at
                            ]);
                        }
                        $total_saved = count($file_items);
                        $message = ['type' => 'success', 'text' => "Paket soal berhasil diperbarui dengan file baru ($total_saved butir soal)."];
                    } else {
                        // Sesuaikan jumlah butir jika berubah secara manual
                        $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM tb_bank_soal WHERE kode_paket = ? AND id_guru = ?");
                        $stmtCnt->execute([$kode_paket, $guru_id]);
                        $current_count = (int)$stmtCnt->fetchColumn();

                        if ($jumlah_soal > $current_count) {
                            $stmtIns = $pdo->prepare("
                                INSERT INTO tb_bank_soal (
                                    id_guru, kode_soal, kode_paket, jenis_soal, id_mapel, id_kelas,
                                    jenis_asesmen, topik, pertanyaan, level_kognitif, tingkat_kesulitan,
                                    bobot, status, created_at
                                ) VALUES (?, ?, ?, 'Pilihan Ganda', ?, ?, ?, ?, ?, 'L2', 'Sedang', 1.0, 'Aktif', ?)
                            ");
                            for ($i = $current_count + 1; $i <= $jumlah_soal; $i++) {
                                $kode_soal = 'SOAL-' . strtoupper(substr(uniqid(), -6));
                                $pertanyaan = "Butir Soal {$i}: Tuliskan butir pertanyaan di sini...";
                                $stmtIns->execute([
                                    $guru_id, $kode_soal, $kode_paket,
                                    $id_mapel, $id_kelas,
                                    $jenis_asesmen, $topik, $pertanyaan,
                                    $created_at
                                ]);
                            }
                        } elseif ($jumlah_soal < $current_count) {
                            $hapus_count = $current_count - $jumlah_soal;
                            $stmtDel = $pdo->prepare("
                                DELETE FROM tb_bank_soal
                                WHERE id IN (
                                    SELECT id FROM (
                                        SELECT id FROM tb_bank_soal
                                        WHERE kode_paket = ? AND id_guru = ?
                                        ORDER BY id DESC LIMIT $hapus_count
                                    ) tmp
                                )
                            ");
                            $stmtDel->execute([$kode_paket, $guru_id]);
                        }
                        $message = ['type' => 'success', 'text' => 'Paket soal berhasil diperbarui.'];
                    }
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM tb_bank_soal WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Soal berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    } elseif ($action === 'hapus_paket') {
        $kode_paket_del = trim((string)($_POST['kode_paket'] ?? ''));
        if ($kode_paket_del !== '') {
            try {
                $stmt = $pdo->prepare("DELETE FROM tb_bank_soal WHERE kode_paket = ? AND id_guru = ?");
                $stmt->execute([$kode_paket_del, $guru_id]);
                $message = ['type' => 'success', 'text' => 'Paket soal berhasil dihapus.'];
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menghapus paket: ' . $e->getMessage()];
            }
        }
    }
}

// Master lists (hanya mapel yang diajar guru login; kelas hanya yang diajar guru login)
$mapel_list = function_exists('getGuruTaughtMapels') ? getGuruTaughtMapels($pdo, $guru_id) : getFilteredSubjects($pdo);
$kelas_list = function_exists('getGuruTaughtClasses') ? getGuruTaughtClasses($pdo, $guru_id) : $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$jenis_soal_options = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
$kurikulum_options = ['PERMENDIKDASMEN_046' => 'Permendikdasmen CP 046', 'KMA_1503_KBC' => 'KMA 1503 + KBC'];
$asesmen_options = ai_asesmen_list();
$session_q = isset($_GET['session_type']) ? '?session_type=' . urlencode((string)$_GET['session_type']) : '';

// Filters
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_status = trim((string)($_GET['f_status'] ?? ''));
$f_asesmen = trim((string)($_GET['f_asesmen'] ?? ''));

$where = ["b.id_guru = ?"];
$params = [$guru_id];

if ($f_mapel > 0) {
    $where[] = "b.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_kelas > 0) {
    $where[] = "b.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_status !== '') {
    $where[] = "b.status = ?";
    $params[] = $f_status;
}
if ($f_asesmen !== '') {
    $where[] = "b.jenis_asesmen = ?";
    $params[] = $f_asesmen;
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT b.kode_paket,
           MIN(b.jenis_asesmen) AS jenis_asesmen,
           MIN(b.id_mapel) AS id_mapel,
           MIN(b.id_kelas) AS id_kelas,
           MIN(m.nama_mapel) AS nama_mapel,
           MIN(k.nama_kelas) AS nama_kelas,
           MIN(b.topik) AS topik,
           MIN(b.kurikulum) AS kurikulum,
           MIN(b.semester) AS semester,
           MIN(b.status) AS status,
           COUNT(*) AS jumlah_butir,
           MAX(b.created_at) AS created_at
    FROM tb_bank_soal b
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = b.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = b.id_kelas
    WHERE $where_sql AND b.kode_paket IS NOT NULL AND b.kode_paket != ''
    GROUP BY b.kode_paket
    ORDER BY MAX(b.id) DESC
");
$stmt->execute($params);
$paket_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Daftar Bank Soal';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-paket').length) {
        $('#table-paket').DataTable({
            'order': [[0, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [8] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada paket ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $(document).on('change', '#filter-soal-form [data-auto-submit]', function() {
        $('#filter-soal-form').submit();
    });

    $('#btnTambahSoal').on('click', function() {
        $('#formSoalAction').val('tambah');
        $('#inp_kode_paket').val('');
        $('#modalSoalTitle').text('Tambah Soal');
        $('#formSoal')[0].reset();
        $('#inp_tanggal_upload').val(new Date().toISOString().substring(0, 10));
        $('#inp_jumlah_soal').val(5);
        $('#modalSoal').modal('show');
    });

    $(document).on('click', '.btn-edit-paket', function() {
        var data = $(this).data('json');
        $('#formSoalAction').val('edit');
        $('#inp_kode_paket').val(data.kode_paket);
        $('#modalSoalTitle').text('Edit Soal — ' + data.kode_paket);
        $('#inp_asesmen').val(data.jenis_asesmen || '');
        $('#inp_mapel').val(data.id_mapel || '');
        $('#inp_kelas').val(data.id_kelas || '');
        $('#inp_topik').val(data.topik || '');
        $('#inp_jumlah_soal').val(data.jumlah_butir || 1);
        $('#inp_tanggal_upload').val(data.created_at ? data.created_at.substring(0, 10) : new Date().toISOString().substring(0, 10));
        $('#modalSoal').modal('show');
    });

    var currentPreviewData = null;

    // Preview Paket
    $(document).on('click', '.btn-preview-paket', function() {
        var kode = $(this).data('kode');
        var $body = $('#paketPreviewBody');
        $body.html('<tr><td colspan="7" class="text-center"><i class="fas fa-spinner fa-spin"></i> Memuat...</td></tr>');
        currentPreviewData = null;
        $('#modalPaketPreview').modal('show');
        $.getJSON('ajax_generate_soal.php?aksi=detail_paket&kode_paket=' + encodeURIComponent(kode), function(res) {
            if (!res.ok) {
                $body.html('<tr><td colspan="7" class="text-center text-danger">' + (res.msg || 'Gagal memuat.') + '</td></tr>');
                return;
            }
            currentPreviewData = res;
            $('#paketPreviewTitle').text((res.jenis_asesmen || 'Paket') + ' — ' + (res.topik || ''));

            // Tampilkan Kisi-Kisi
            if (res.kisi_kisi && res.kisi_kisi.length) {
                var kHtml = '';
                $.each(res.kisi_kisi, function(i, k) {
                    kHtml += '<tr>'
                        + '<td class="text-center">' + (k.no || (i+1)) + '</td>'
                        + '<td><span class="badge badge-light border">' + $('<span>').text(k.bentuk || '').html() + '</span></td>'
                        + '<td>' + $('<span>').text(k.materi || '-').html() + '</td>'
                        + '<td>' + $('<span>').text(k.cp || '-').html() + '</td>'
                        + '<td>' + $('<span>').text(k.tp || '-').html() + '</td>'
                        + '<td>' + $('<span>').text(k.indikator || '-').html() + '</td>'
                        + '<td class="text-center"><span class="badge badge-info">' + $('<span>').text(k.level_kognitif || 'L2').html() + '</span></td>'
                        + '<td class="text-center">' + $('<span>').text(k.kesulitan || '-').html() + '</td>'
                        + '<td class="text-center">' + (k.bobot || 1) + '</td>'
                        + '</tr>';
                });
                $('#paketPreviewKisiBody').html(kHtml);
                $('#wrapModalKisi').show();
            } else {
                $('#wrapModalKisi').hide();
            }

            // Tampilkan Soal
            var html = '';
            $.each(res.items, function(i, it) {
                var opsiTxt = '';
                if (it.bentuk === 'Menjodohkan' && it.tabel && it.tabel.length) {
                    var tblHtml = '<div class="table-responsive"><table class="table table-bordered table-sm mb-0 small" style="font-size:11px;"><thead><tr class="bg-light"><th width="8%" class="text-center">No</th><th>Soal</th><th width="10%" class="text-center">Huruf</th><th>Pilihan Jawaban</th></tr></thead><tbody>';
                    $.each(it.tabel, function(_, tr) {
                        tblHtml += '<tr><td class="text-center">' + (tr.no || '') + '</td><td>' + $('<span>').text(tr.kiri || '').html() + '</td><td class="text-center font-weight-bold">' + $('<span>').text(tr.huruf || '').html() + '</td><td>' + $('<span>').text(tr.kanan || '').html() + '</td></tr>';
                    });
                    tblHtml += '</tbody></table></div>';
                    opsiTxt = tblHtml;
                } else if (it.opsi && typeof it.opsi === 'object') {
                    var parts = [];
                    $.each(['A','B','C','D'], function(_, k) {
                        if (it.opsi[k]) parts.push(k + '. ' + it.opsi[k]);
                    });
                    opsiTxt = parts.join(' | ');
                }
                html += '<tr>'
                    + '<td class="text-center">' + (i+1) + '</td>'
                    + '<td><span class="badge badge-light border">' + $('<span>').text(it.bentuk).html() + '</span></td>'
                    + '<td>' + $('<span>').text(it.level_kognitif).html() + '</td>'
                    + '<td style="white-space:pre-wrap;max-width:300px;">' + $('<span>').text(it.pertanyaan).html() + '</td>'
                    + '<td>' + (it.bentuk === 'Menjodohkan' ? opsiTxt : $('<span>').text(opsiTxt).html()) + '</td>'
                    + '<td><b>' + $('<span>').text(it.kunci || '-').html() + '</b></td>'
                    + '<td>' + $('<span>').text(it.pembahasan || '-').html() + '</td>'
                    + '</tr>';
            });
            $body.html(html);
        }).fail(function() {
            $body.html('<tr><td colspan="7" class="text-center text-danger">Error koneksi.</td></tr>');
        });
    });

    function unduhBlobDariModal(format) {
        if (!currentPreviewData || !currentPreviewData.items || !currentPreviewData.items.length) {
            Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Data soal belum selesai dimuat.' });
            return;
        }
        var btn = format === 'pdf' ? $('#btnUnduhModalPDF') : (format === 'docx' ? $('#btnUnduhModalDOCX') : $('#btnUnduhModalXLSX'));
        var origHtml = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Mengunduh...');

        var payload = {
            aksi: 'unduh',
            format: format,
            jenis_asesmen: currentPreviewData.jenis_asesmen || '',
            kurikulum: currentPreviewData.kurikulum || 'PERMENDIKDASMEN_046',
            mapel: currentPreviewData.mapel || '',
            kelas: currentPreviewData.kelas || '',
            semester: currentPreviewData.semester || '',
            topik: currentPreviewData.topik || '',
            kisi_kisi: currentPreviewData.kisi_kisi || [],
            items: currentPreviewData.items
        };

        fetch('ajax_generate_soal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(res) {
            var cType = res.headers.get('content-type') || '';
            if (cType.indexOf('application/json') !== -1) {
                return res.json().then(function(j) { throw new Error(j.msg || 'Gagal mengunduh file.'); });
            }
            if (!res.ok) throw new Error('HTTP ' + res.status);
            var disp = res.headers.get('content-disposition') || '';
            var m = /filename="?([^";]+)"?/i.exec(disp);
            var fname = m ? m[1] : ('soal_' + (currentPreviewData.topik || 'paket') + '.' + (format === 'xlsx' ? 'xlsx' : (format === 'docx' ? 'docx' : 'pdf')));
            return res.blob().then(function(b) { return { blob: b, fname: fname }; });
        })
        .then(function(o) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(o.blob);
            a.download = o.fname;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function() { URL.revokeObjectURL(a.href); }, 1000);
        })
        .catch(function(err) {
            Swal.fire({ icon: 'error', title: 'Gagal Unduh', text: err.message || 'Terjadi kesalahan saat mengunduh.' });
        })
        .finally(function() {
            btn.prop('disabled', false).html(origHtml);
        });
    }

    $(document).on('click', '#btnUnduhModalPDF', function() { unduhBlobDariModal('pdf'); });
    $(document).on('click', '#btnUnduhModalDOCX', function() { unduhBlobDariModal('docx'); });
    $(document).on('click', '#btnUnduhModalXLSX', function() { unduhBlobDariModal('xlsx'); });

    // Hapus Paket
    $(document).on('click', '.btn-hapus-paket', function() {
        var kode = $(this).data('kode');
        Swal.fire({
            title: 'Hapus Paket?',
            text: 'Semua butir soal dalam paket ' + kode + ' akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusPaketKode').val(kode);
                $('#formHapusPaket').submit();
            }
        });
    });
});
JS
];



if (!empty($message)) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Bank Soal</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Soal</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row" id="filter-soal-form">
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Mata Pelajaran</label>
                            <select name="f_mapel" class="form-control form-control-sm" data-auto-submit>
                                <option value="">-- Semua Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>" <?= $f_mapel === (int)$m['id_mapel'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm" data-auto-submit>
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm" data-auto-submit>
                                <option value="">-- Semua Status --</option>
                                <?php foreach (['Aktif', 'Draft', 'Arsip'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Jenis Asesmen</label>
                            <select name="f_asesmen" class="form-control form-control-sm" data-auto-submit>
                                <option value="">-- Semua Asesmen --</option>
                                <?php foreach ($asesmen_options as $a): ?>
                                    <option value="<?= htmlspecialchars($a) ?>" <?= $f_asesmen === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-2 d-flex">
                            <button type="submit" class="btn btn-primary btn-sm mr-2"><i class="fas fa-search"></i> Terapkan Filter</button>
                            <a href="bank_soal.php<?= $session_q ?>" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Bank Soal Card -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4><i class="fas fa-box mr-2"></i>Bank Soal</h4>
                    <div>
                        <button type="button" class="btn btn-primary" id="btnTambahSoal">
                            <i class="fas fa-plus mr-1"></i> Tambah Soal
                        </button>
                        <a href="generate_soal.php<?= $session_q ?>" class="btn btn-success">
                            <i class="fas fa-robot mr-1"></i> Generate Soal
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-paket">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Kode Paket</th>
                                    <th>Asesmen</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Kelas</th>
                                    <th>Topik</th>
                                    <th>Butir</th>
                                    <th>Tanggal</th>
                                    <th width="1%" class="text-center text-nowrap">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paket_rows as $i => $p): ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($p['kode_paket']) ?></strong></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($p['jenis_asesmen'] ?? '-') ?></span></td>
                                        <td><?= htmlspecialchars($p['nama_mapel'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['nama_kelas'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['topik'] ?? '-') ?></td>
                                        <td class="text-center"><span class="badge badge-primary"><?= (int)$p['jumlah_butir'] ?></span></td>
                                        <td><?= htmlspecialchars(substr($p['created_at'] ?? '', 0, 10)) ?></td>
                                        <td class="text-center text-nowrap" style="white-space: nowrap;">
                                            <div class="d-inline-flex align-items-center" style="gap: 4px;">
                                                <button type="button" class="btn btn-info btn-sm btn-preview-paket" data-kode="<?= htmlspecialchars($p['kode_paket'], ENT_QUOTES) ?>" title="Preview & Unduh">
                                                    <i class="fas fa-eye mr-1"></i> Preview & Unduh
                                                </button>
                                                <button type="button" class="btn btn-warning btn-sm btn-edit-paket" data-json='<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm btn-hapus-paket" data-kode="<?= htmlspecialchars($p['kode_paket'], ENT_QUOTES) ?>" title="Hapus Paket">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit Soal -->
<div class="modal fade" id="modalSoal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formSoal" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formSoalAction" value="tambah">
                <input type="hidden" name="kode_paket" id="inp_kode_paket" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalSoalTitle">Tambah Soal</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Jenis Asesmen <span class="text-danger">*</span></label>
                            <select name="jenis_asesmen" id="inp_asesmen" class="form-control" required>
                                <option value="">-- Pilih Asesmen --</option>
                                <?php foreach ($asesmen_options as $a): ?>
                                    <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Mata Pelajaran <span class="text-danger">*</span></label>
                            <select name="id_mapel" id="inp_mapel" class="form-control" required>
                                <option value="">-- Pilih Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Kelas <span class="text-danger">*</span></label>
                            <select name="id_kelas" id="inp_kelas" class="form-control" required>
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Materi / Topik <span class="text-danger">*</span></label>
                            <input type="text" name="topik" id="inp_topik" class="form-control" required placeholder="Contoh: Metamorfosis">
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Jumlah Soal <span class="text-danger">*</span></label>
                            <input type="number" name="jumlah_soal" id="inp_jumlah_soal" class="form-control" value="5" min="1" max="100" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Tanggal Upload <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_upload" id="inp_tanggal_upload" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-12 form-group">
                            <label class="font-weight-bold">Upload File Soal <small class="text-muted">(format .doc, .docx, .pdf, .xls, .xlsx, .txt)</small></label>
                            <input type="file" name="file_soal" id="inp_file_soal" class="form-control-file" accept=".doc,.docx,.pdf,.xls,.xlsx,.txt">
                            <small class="form-text text-muted">Opsional: Jika file diupload, butir soal akan otomatis dibaca dan dimasukkan ke paket ini.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="formHapusPaket" class="d-none">
    <input type="hidden" name="action" value="hapus_paket">
    <input type="hidden" name="kode_paket" id="formHapusPaketKode">
</form>

<!-- Modal Preview Paket -->
<div class="modal fade" id="modalPaketPreview" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-box mr-2"></i><span id="paketPreviewTitle">Preview Paket</span></h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 p-3 bg-light rounded border">
                    <div>
                        <span class="font-weight-bold mr-2"><i class="fas fa-download mr-1 text-primary"></i> Unduh Soal:</span>
                        <small class="text-muted">Pilih format file yang ingin diunduh</small>
                    </div>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-danger btn-sm" id="btnUnduhModalPDF">
                            <i class="fas fa-file-pdf mr-1"></i> Unduh PDF
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" id="btnUnduhModalDOCX">
                            <i class="fas fa-file-word mr-1"></i> Unduh Word (.docx)
                        </button>
                        <button type="button" class="btn btn-success btn-sm" id="btnUnduhModalXLSX">
                            <i class="fas fa-file-excel mr-1"></i> Unduh Excel (.xlsx)
                        </button>
                    </div>
                </div>
                <div class="card mb-3" id="wrapModalKisi" style="display:none;">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0 font-weight-bold text-primary"><i class="fas fa-th-list mr-1"></i> Kisi-Kisi Paket Soal</h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr class="bg-light">
                                        <th width="4%" class="text-center">No</th>
                                        <th>Bentuk</th>
                                        <th>Materi</th>
                                        <th>CP</th>
                                        <th>TP</th>
                                        <th>Indikator</th>
                                        <th width="7%" class="text-center">Level</th>
                                        <th width="8%" class="text-center">Kesulitan</th>
                                        <th width="6%" class="text-center">Bobot</th>
                                    </tr>
                                </thead>
                                <tbody id="paketPreviewKisiBody">
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0 font-weight-bold text-dark"><i class="fas fa-list-ol mr-1"></i> Daftar Butir Soal</h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0">
                                <thead>
                                    <tr class="bg-light">
                                        <th width="4%" class="text-center">No</th>
                                        <th width="12%">Bentuk</th>
                                        <th width="7%" class="text-center">Level</th>
                                        <th>Pertanyaan</th>
                                        <th>Opsi / Tabel</th>
                                        <th width="10%">Kunci</th>
                                        <th>Pembahasan</th>
                                    </tr>
                                </thead>
                                <tbody id="paketPreviewBody">
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="$('#btnUnduhModalPDF').click();">
                        <i class="fas fa-file-pdf mr-1"></i> PDF
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="$('#btnUnduhModalDOCX').click();">
                        <i class="fas fa-file-word mr-1"></i> Word (.docx)
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="$('#btnUnduhModalXLSX').click();">
                        <i class="fas fa-file-excel mr-1"></i> Excel (.xlsx)
                    </button>
                </div>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
