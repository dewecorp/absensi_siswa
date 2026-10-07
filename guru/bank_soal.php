<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/ai_helper.php';

ensure_learning_schema($pdo);
ai_helper_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$can_crud = !$is_admin_or_kepala;

$upload_dir = guru_upload_dir($pdo, $guru_id, 'bank_soal');

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
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki hak akses untuk mengubah data ini.'];
    } else {
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

        // Cek file upload: simpan file asli agar preview bisa tampil format asli,
        // sekaligus parse isinya bila memungkinkan.
        $has_file = !empty($_FILES['file_soal']) && (int)$_FILES['file_soal']['error'] === UPLOAD_ERR_OK;
        $file_items = [];
        $file_link = null;
        $file_err = '';
        if ($has_file) {
            $orig_name = (string)$_FILES['file_soal']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $allow_ext = ['doc', 'docx', 'pdf', 'xls', 'xlsx', 'txt'];
            $max_size = 5 * 1024 * 1024;
            if (!in_array($ext, $allow_ext, true)) {
                $file_err = 'Format file harus .doc, .docx, .pdf, .xls, .xlsx, atau .txt.';
                $has_file = false;
            } elseif ((int)$_FILES['file_soal']['size'] > $max_size) {
                $file_err = 'Ukuran file maksimal 5 MB.';
                $has_file = false;
            } else {
                $filename = 'soal_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '.' . $ext;
                if (move_uploaded_file((string)$_FILES['file_soal']['tmp_name'], $upload_dir . $filename)) {
                    $file_link = guru_folder_name($pdo, $guru_id) . '/' . $filename;
                    $file_items = parse_uploaded_soal_file($upload_dir . $filename, $orig_name);
                } else {
                    $file_err = 'Gagal menyimpan file upload.';
                    $has_file = false;
                }
            }
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
                            level_kognitif, tingkat_kesulitan, bobot, status, created_at, file_soal
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sedang', 1.0, 'Aktif', ?, ?)
                    ");

                    if ($has_file) {
                        // Paket dari file upload: simpan hasil parse bila ada,
                        // minimal 1 baris penanda agar paket tercatat + file asli tersimpan.
                        $rows_to_save = $file_items;
                        if (empty($rows_to_save)) {
                            $rows_to_save = [[
                                'bentuk' => 'Pilihan Ganda', 'level' => 'L2',
                                'pertanyaan' => 'Soal tersimpan dalam format file asli. Buka preview untuk melihat file.',
                                'opsi' => [], 'kunci' => '', 'pembahasan' => '',
                            ]];
                        }
                        foreach ($rows_to_save as $item) {
                            $kode_soal = 'SOAL-' . strtoupper(substr(uniqid(), -6));
                            $opsi_json = (!empty($item['opsi']['A']) || !empty($item['opsi']['B'])) ? json_encode($item['opsi'], JSON_UNESCAPED_UNICODE) : null;
                            $stmtIns->execute([
                                $guru_id, $kode_soal, $kode_paket_new,
                                $item['bentuk'] ?? 'Pilihan Ganda', $id_mapel, $id_kelas,
                                $jenis_asesmen, $topik, $item['pertanyaan'],
                                $opsi_json, (($item['kunci'] ?? '') !== '' ? $item['kunci'] : null),
                                (($item['pembahasan'] ?? '') !== '' ? $item['pembahasan'] : null),
                                $item['level'] ?? 'L2', $created_at, $file_link
                            ]);
                        }
                        $total_saved = count($file_items);
                        $message = ['type' => 'success', 'text' => $total_saved > 0
                            ? "Paket soal berhasil ditambahkan dari file ($total_saved butir soal)."
                            : 'File soal berhasil diupload. Preview menampilkan format file asli.'];
                    } else {
                        for ($i = 1; $i <= $jumlah_soal; $i++) {
                            $kode_soal = 'SOAL-' . strtoupper(substr(uniqid(), -6));
                            $pertanyaan = "Butir Soal {$i}: Tuliskan butir pertanyaan di sini...";
                            $stmtIns->execute([
                                $guru_id, $kode_soal, $kode_paket_new,
                                'Pilihan Ganda', $id_mapel, $id_kelas,
                                $jenis_asesmen, $topik, $pertanyaan,
                                null, null, null,
                                'L2', $created_at, null
                            ]);
                        }
                        $message = $file_err !== ''
                            ? ['type' => 'warning', 'text' => $file_err . ' Paket kosong tetap dibuat, silakan edit dan upload ulang file yang valid.']
                            : ['type' => 'success', 'text' => 'Paket soal berhasil ditambahkan (' . $jumlah_soal . ' butir).'];
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

                    if ($has_file) {
                        // Jika ada file baru diupload saat edit, ganti butir soal + file lama dihapus
                        $old_files = $pdo->prepare("SELECT DISTINCT file_soal FROM tb_bank_soal WHERE kode_paket = ? AND id_guru = ? AND file_soal IS NOT NULL AND file_soal != ''");
                        $old_files->execute([$kode_paket, $guru_id]);
                        foreach ($old_files->fetchAll(PDO::FETCH_COLUMN) as $of) {
                            @unlink(dirname(__DIR__) . '/uploads/bank_soal/' . ltrim(str_replace('\\', '/', (string)$of), '/'));
                        }
                        $pdo->prepare("DELETE FROM tb_bank_soal WHERE kode_paket = ? AND id_guru = ?")->execute([$kode_paket, $guru_id]);
                        $stmtIns = $pdo->prepare("
                            INSERT INTO tb_bank_soal (
                                id_guru, kode_soal, kode_paket, jenis_soal, id_mapel, id_kelas,
                                jenis_asesmen, topik, pertanyaan, pilihan_jawaban, jawaban_benar, pembahasan,
                                level_kognitif, tingkat_kesulitan, bobot, status, created_at, file_soal
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sedang', 1.0, 'Aktif', ?, ?)
                        ");
                        $rows_to_save = $file_items;
                        if (empty($rows_to_save)) {
                            $rows_to_save = [[
                                'bentuk' => 'Pilihan Ganda', 'level' => 'L2',
                                'pertanyaan' => 'Soal tersimpan dalam format file asli. Buka preview untuk melihat file.',
                                'opsi' => [], 'kunci' => '', 'pembahasan' => '',
                            ]];
                        }
                        foreach ($rows_to_save as $item) {
                            $kode_soal = 'SOAL-' . strtoupper(substr(uniqid(), -6));
                            $opsi_json = (!empty($item['opsi']['A']) || !empty($item['opsi']['B'])) ? json_encode($item['opsi'], JSON_UNESCAPED_UNICODE) : null;
                            $stmtIns->execute([
                                $guru_id, $kode_soal, $kode_paket,
                                $item['bentuk'] ?? 'Pilihan Ganda', $id_mapel, $id_kelas,
                                $jenis_asesmen, $topik, $item['pertanyaan'],
                                $opsi_json, (($item['kunci'] ?? '') !== '' ? $item['kunci'] : null),
                                (($item['pembahasan'] ?? '') !== '' ? $item['pembahasan'] : null),
                                $item['level'] ?? 'L2', $created_at, $file_link
                            ]);
                        }
                        $total_saved = count($file_items);
                        $message = ['type' => 'success', 'text' => $total_saved > 0
                            ? "Paket soal berhasil diperbarui dengan file baru ($total_saved butir soal)."
                            : 'File soal berhasil diperbarui. Preview menampilkan format file asli.'];
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
                $del_files = $pdo->prepare("SELECT DISTINCT file_soal FROM tb_bank_soal WHERE kode_paket = ? AND id_guru = ? AND file_soal IS NOT NULL AND file_soal != ''");
                $del_files->execute([$kode_paket_del, $guru_id]);
                foreach ($del_files->fetchAll(PDO::FETCH_COLUMN) as $df) {
                    @unlink(dirname(__DIR__) . '/uploads/bank_soal/' . ltrim(str_replace('\\', '/', (string)$df), '/'));
                }
                $stmt = $pdo->prepare("DELETE FROM tb_bank_soal WHERE kode_paket = ? AND id_guru = ?");
                $stmt->execute([$kode_paket_del, $guru_id]);
                $message = ['type' => 'success', 'text' => 'Paket soal berhasil dihapus.'];
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menghapus paket: ' . $e->getMessage()];
            }
        }
    }
}
}

// Master lists
if ($is_admin_or_kepala) {
    $mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran WHERE (jenis_mapel IS NULL OR jenis_mapel = 'Akademik') AND nama_mapel NOT LIKE '%Asmaul Husna%' AND nama_mapel NOT LIKE '%Upacara%' AND nama_mapel NOT LIKE '%Istirahat%' AND nama_mapel NOT LIKE '%Kepramukaan%' AND nama_mapel NOT LIKE '%Ekstrakurikuler%' ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);
    $kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $mapel_list = function_exists('getGuruTaughtMapels') ? getGuruTaughtMapels($pdo, $guru_id) : getFilteredSubjects($pdo);
    $kelas_list = function_exists('getGuruTaughtClasses') ? getGuruTaughtClasses($pdo, $guru_id) : $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
}
$jenis_soal_options = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
$kurikulum_options = ['PERMENDIKDASMEN_046' => 'Permendikdasmen CP 046', 'KMA_1503_KBC' => 'KMA 1503 + KBC'];
$asesmen_options = ai_asesmen_list();
$session_q = isset($_GET['session_type']) ? '?session_type=' . urlencode((string)$_GET['session_type']) : '';

// Filters
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_status = trim((string)($_GET['f_status'] ?? ''));
$f_asesmen = trim((string)($_GET['f_asesmen'] ?? ''));

if ($is_admin_or_kepala) {
    $where = ["1=1"];
    $params = [];
} else {
    $where = ["b.id_guru = ?"];
    $params = [$guru_id];
}

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
           MAX(b.file_soal) AS file_soal,
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

// Tambahan hitungan: tiap baris pasangan pada tabel Menjodohkan dihitung 1 butir.
// (1 baris soal Menjodohkan berisi N baris pasangan => tambah N-1 ke jumlah butir.)
$extra_butir = [];
try {
    $stM = $pdo->prepare("
        SELECT kode_paket, pertanyaan, pilihan_jawaban
        FROM tb_bank_soal
        WHERE id_guru = ? AND jenis_soal = 'Menjodohkan'
          AND kode_paket IS NOT NULL AND kode_paket != ''
    ");
    $stM->execute([$guru_id]);
    foreach ($stM->fetchAll(PDO::FETCH_ASSOC) as $mr) {
        $n_baris = 0;
        if (!empty($mr['pilihan_jawaban'])) {
            $pj = json_decode((string)$mr['pilihan_jawaban'], true);
            if (is_array($pj) && (isset($pj[0]['no']) || isset($pj[0]['kiri']))) {
                $n_baris = count($pj);
            }
        }
        if ($n_baris === 0) {
            // Fallback: hitung baris pola "1. ... | A. ..." pada teks pertanyaan
            $n_baris = preg_match_all('/^\s*\d+[\.\)]\s*[^|\n]+\|/mu', (string)($mr['pertanyaan'] ?? ''), $mm);
            if (!$n_baris) {
                $n_baris = preg_match_all('/(?:^|\s+)\d+[\.\)]\s*[^|]+?\|\s*[A-Za-z][\.\)]/u', (string)($mr['pertanyaan'] ?? ''), $mm);
            }
        }
        if ($n_baris > 1) {
            $kp = (string)$mr['kode_paket'];
            $extra_butir[$kp] = ($extra_butir[$kp] ?? 0) + ($n_baris - 1);
        }
    }
} catch (Throwable $e) { /* abaikan, tampilkan hitungan dasar */ }

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
            'order': [[0, 'asc']],
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

    // Validasi ukuran file SEBELUM submit (server menolak >5MB; file lebih besar
    // membuat $_FILES kosong sehingga upload gagal diam-diam)
    $('#formSoal').on('submit', function(e) {
        var fi = $('#inp_file_soal')[0];
        if (fi && fi.files && fi.files.length) {
            var f = fi.files[0];
            var allow = ['doc', 'docx', 'pdf', 'xls', 'xlsx', 'txt'];
            var ext = (f.name.split('.').pop() || '').toLowerCase();
            if (allow.indexOf(ext) === -1) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'Format ditolak', text: 'Format file harus .doc, .docx, .pdf, .xls, .xlsx, atau .txt.' });
                return false;
            }
            if (f.size > 5 * 1024 * 1024) {
                e.preventDefault();
                Swal.fire({ icon: 'error', title: 'File terlalu besar', text: 'Ukuran file maksimal 5 MB. Kecilkan/kompres dulu file PDF-nya, lalu upload ulang.' });
                return false;
            }
        }
    });

    $(document).on('click', '.btn-edit-paket', function() {
        var data = $(this).data('json');
        $('#formSoalAction').val('edit');
        $('#inp_kode_paket').val(data.kode_paket);
        $('#modalSoalTitle').text('Edit Soal â€” ' + data.kode_paket);
        $('#inp_asesmen').val(data.jenis_asesmen || '');
        $('#inp_mapel').val(data.id_mapel || '');
        $('#inp_kelas').val(data.id_kelas || '');
        $('#inp_topik').val(data.topik || '');
        $('#inp_jumlah_soal').val(data.jumlah_butir || 1);
        $('#inp_tanggal_upload').val(data.created_at ? data.created_at.substring(0, 10) : new Date().toISOString().substring(0, 10));
        $('#modalSoal').modal('show');
    });

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
                    <?php if ($can_crud): ?>
                    <div>
                        <button type="button" class="btn btn-primary" id="btnTambahSoal">
                            <i class="fas fa-plus mr-1"></i> Tambah Soal
                        </button>
                        <a href="generate_soal.php<?= $session_q ?>" class="btn btn-success">
                            <i class="fas fa-magic mr-1"></i> Generate Soal
                        </a>
                    </div>
                    <?php endif; ?>
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
                                        <td><strong><?= htmlspecialchars($p['kode_paket']) ?></strong><?php if (!empty($p['file_soal'])): ?><br><span class="badge badge-info mt-1" title="Paket memiliki file soal asli: <?= htmlspecialchars(basename((string)$p['file_soal'])) ?>"><i class="fas fa-paperclip mr-1"></i><?= strtoupper(htmlspecialchars(pathinfo((string)$p['file_soal'], PATHINFO_EXTENSION))) ?> ASLI</span><?php endif; ?></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($p['jenis_asesmen'] ?? '-') ?></span></td>
                                        <td><?= htmlspecialchars($p['nama_mapel'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['nama_kelas'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($p['topik'] ?? '-') ?></td>
                                        <td class="text-center"><span class="badge badge-primary"><?= (int)$p['jumlah_butir'] + (int)($extra_butir[$p['kode_paket']] ?? 0) ?></span></td>
                                        <td><?= htmlspecialchars(substr($p['created_at'] ?? '', 0, 10)) ?></td>
                                        <td class="text-center text-nowrap" style="white-space: nowrap;">
                                            <div class="d-inline-flex align-items-center" style="gap: 4px;">
                                                <a href="preview_bank_soal.php?kode_paket=<?= urlencode($p['kode_paket']) ?><?= $session_q ? '&' . ltrim($session_q, '?') : '' ?>" target="_blank" class="btn btn-info btn-sm" title="Pratinjau di laman penuh">
                                                    <i class="fas fa-eye mr-1"></i> Preview
                                                </a>
                                                <?php if ($can_crud): ?>
                                                <button type="button" class="btn btn-warning btn-sm btn-edit-paket" data-json='<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm btn-hapus-paket" data-kode="<?= htmlspecialchars($p['kode_paket'], ENT_QUOTES) ?>" title="Hapus Paket">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
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

<?php include '../templates/footer.php'; ?>
