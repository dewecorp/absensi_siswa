<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$is_kepala = ($user_level === 'kepala_madrasah') || (($_GET['session_type'] ?? '') === 'kepala_madrasah');
$can_crud = !$is_kepala;

$id_tugas = (int)($_GET['id_tugas'] ?? $_GET['id'] ?? 0);
if ($id_tugas <= 0) {
    redirect('tugas.php');
}

$stmt = $pdo->prepare("
    SELECT t.*, m.nama_mapel, k.nama_kelas
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    WHERE t.id = ? AND (t.id_guru = ? OR ? = 'admin')
    LIMIT 1
");
$stmt->execute([$id_tugas, $guru_id, $user_level]);
$tugas = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tugas || !in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)) {
    redirect('tugas.php');
}

$message = null;

// Tabel pengaturan bobot per jenis soal (per tugas, bukan per butir)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS tb_tugas_bobot_jenis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_tugas INT NOT NULL,
        tipe_soal VARCHAR(50) NOT NULL,
        bobot INT NOT NULL DEFAULT 10,
        UNIQUE KEY uniq_tugas_tipe (id_tugas, tipe_soal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$bobot_jenis_defaults = ['Pilihan Ganda' => 10, 'Pilihan Ganda Kompleks' => 10, 'Essay' => 10];
$bobot_jenis_map = $bobot_jenis_defaults;
try {
    $stB = $pdo->prepare("SELECT tipe_soal, bobot FROM tb_tugas_bobot_jenis WHERE id_tugas = ?");
    $stB->execute([$id_tugas]);
    foreach ($stB->fetchAll(PDO::FETCH_ASSOC) as $rb) {
        $bobot_jenis_map[$rb['tipe_soal']] = (int)$rb['bobot'];
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_crud) {
    $action = $_POST['action'] ?? '';
    if ($action === 'simpan_soal') {
        $soal_id = (int)($_POST['soal_id'] ?? 0);
        $tipe_soal = in_array($_POST['tipe_soal'] ?? '', ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay'], true) ? $_POST['tipe_soal'] : 'Pilihan Ganda';
        if ($tugas['jenis_tugas'] === 'Kuis' && $tipe_soal === 'Essay') {
            $tipe_soal = 'Pilihan Ganda';
        }
        $pertanyaan = trim((string)($_POST['pertanyaan'] ?? ''));
        $pilihan_a = trim((string)($_POST['pilihan_a'] ?? ''));
        $pilihan_b = trim((string)($_POST['pilihan_b'] ?? ''));
        $pilihan_c = trim((string)($_POST['pilihan_c'] ?? ''));
        $pilihan_d = trim((string)($_POST['pilihan_d'] ?? ''));
        $pilihan_e = trim((string)($_POST['pilihan_e'] ?? ''));
        $kunci_jawaban = trim((string)($_POST['kunci_jawaban'] ?? 'A'));
        if ($tipe_soal === 'Pilihan Ganda Kompleks') {
            $kunci_jawaban = trim((string)($_POST['kunci_jawaban_kompleks'] ?? 'A'));
        } elseif ($tipe_soal === 'Essay') {
            $kunci_jawaban = trim((string)($_POST['kunci_jawaban_essay'] ?? ''));
        }
        $bobot = $bobot_jenis_map[$tipe_soal] ?? ((int)($_POST['bobot'] ?? 10) ?: 10);

        if ($pertanyaan === '') {
            $message = ['type' => 'warning', 'text' => 'Pertanyaan soal wajib diisi.'];
        } else {
            try {
                if ($soal_id > 0) {
                    $st = $pdo->prepare("UPDATE tb_tugas_soal SET tipe_soal=?, pertanyaan=?, pilihan_a=?, pilihan_b=?, pilihan_c=?, pilihan_d=?, pilihan_e=?, kunci_jawaban=?, bobot=? WHERE id=? AND id_tugas=?");
                    $st->execute([$tipe_soal, $pertanyaan, $pilihan_a, $pilihan_b, $pilihan_c, $pilihan_d, $pilihan_e, $kunci_jawaban, $bobot, $soal_id, $id_tugas]);
                    $message = ['type' => 'success', 'text' => 'Soal berhasil diperbarui.'];
                } else {
                    $st = $pdo->prepare("INSERT INTO tb_tugas_soal (id_tugas, tipe_soal, pertanyaan, pilihan_a, pilihan_b, pilihan_c, pilihan_d, pilihan_e, kunci_jawaban, bobot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $st->execute([$id_tugas, $tipe_soal, $pertanyaan, $pilihan_a, $pilihan_b, $pilihan_c, $pilihan_d, $pilihan_e, $kunci_jawaban, $bobot]);
                    $message = ['type' => 'success', 'text' => 'Soal baru berhasil ditambahkan.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan soal: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'simpan_bobot_jenis') {
        try {
            $input_bobot = $_POST['bobot_jenis'] ?? [];
            $stUp = $pdo->prepare("
                INSERT INTO tb_tugas_bobot_jenis (id_tugas, tipe_soal, bobot)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE bobot = VALUES(bobot)
            ");
            foreach ($bobot_jenis_defaults as $tj => $def) {
                $b = isset($input_bobot[$tj]) ? (int)$input_bobot[$tj] : $def;
                if ($b < 1) $b = 1;
                if ($b > 100) $b = 100;
                $stUp->execute([$id_tugas, $tj, $b]);
                $bobot_jenis_map[$tj] = $b;
            }
            // Sinkronkan bobot soal lama mengikuti bobot jenisnya
            $stSync = $pdo->prepare("UPDATE tb_tugas_soal SET bobot = ? WHERE id_tugas = ? AND tipe_soal = ?");
            foreach ($bobot_jenis_map as $tj => $b) {
                $stSync->execute([$b, $id_tugas, $tj]);
            }
            $message = ['type' => 'success', 'text' => 'Pengaturan bobot per jenis soal berhasil disimpan dan diterapkan ke semua soal.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menyimpan bobot: ' . $e->getMessage()];
        }
    } elseif ($action === 'hapus_soal') {
        $soal_id = (int)($_POST['soal_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_tugas_soal WHERE id = ? AND id_tugas = ?")->execute([$soal_id, $id_tugas]);
            $message = ['type' => 'success', 'text' => 'Soal berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus soal: ' . $e->getMessage()];
        }
    } elseif ($action === 'impor_soal' && isset($_FILES['file_impor'])) {
        $file = $_FILES['file_impor'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $tmp = $file['tmp_name'];
            $imported_count = 0;

            try {
                if ($ext === 'docx') {
                    // Parse docx
                    $zip = new ZipArchive();
                    if ($zip->open($tmp) === TRUE) {
                        $xml = $zip->getFromName('word/document.xml');
                        $zip->close();
                        if ($xml) {
                            $dom = new DOMDocument();
                            @$dom->loadXML($xml);
                            $paragraphs = $dom->getElementsByTagName('p');
                            $current_q = '';
                            $opts = [];
                            $kunci = 'A';
                            $bobot = 10;
                            $tipe = 'Pilihan Ganda';

                            $mode_uraian = false;
                            $is_kuis_docx = ($tugas['jenis_tugas'] === 'Kuis');
                            $skipped_uraian_docx = 0;
                            $ins_docx = $pdo->prepare("INSERT INTO tb_tugas_soal (id_tugas, tipe_soal, pertanyaan, pilihan_a, pilihan_b, pilihan_c, pilihan_d, pilihan_e, kunci_jawaban, bobot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $save_docx = function () use ($ins_docx, $id_tugas, &$current_q, &$opts, &$kunci, &$tipe, &$imported_count, $bobot_jenis_map, $is_kuis_docx, &$skipped_uraian_docx) {
                                if (trim($current_q) === '') return;
                                $t = $tipe;
                                if ($t === 'Essay' && $is_kuis_docx) {
                                    $skipped_uraian_docx++;
                                    $current_q = '';
                                    $opts = [];
                                    $kunci = 'A';
                                    $tipe = 'Pilihan Ganda';
                                    return;
                                }
                                if ($t === 'Pilihan Ganda' && strpos($kunci, ',') !== false) {
                                    $t = 'Pilihan Ganda Kompleks';
                                }
                                $b = $bobot_jenis_map[$t] ?? 10;
                                $ins_docx->execute([$id_tugas, $t, $current_q, $opts['A'] ?? '', $opts['B'] ?? '', $opts['C'] ?? '', $opts['D'] ?? '', $opts['E'] ?? '', $kunci, $b]);
                                $imported_count++;
                            };

                            foreach ($paragraphs as $p) {
                                $text = trim($p->textContent);
                                if ($text === '') continue;
                                if (preg_match('/^bagian\s+b\b/i', $text)) {
                                    $save_docx();
                                    $mode_uraian = true;
                                    $current_q = '';
                                    $opts = [];
                                    $kunci = '';
                                    $tipe = 'Essay';
                                    continue;
                                }
                                if (preg_match('/^bagian\s+a\b/i', $text)) {
                                    $save_docx();
                                    $mode_uraian = false;
                                    $current_q = '';
                                    $opts = [];
                                    $kunci = 'A';
                                    $tipe = 'Pilihan Ganda';
                                    continue;
                                }
                                if (stripos($text, 'TEMPLATE SOAL') === 0 || stripos($text, 'Catatan:') === 0) continue;
                                if (preg_match('/^(soal|no\.?)\s*\d+/i', $text) || strpos($text, '?') !== false || strlen($text) > 40) {
                                    $save_docx();
                                    $current_q = preg_replace('/^(soal|no\.?)\s*\d+\s*[:.\-]?\s*/i', '', $text);
                                    $opts = [];
                                    $kunci = $mode_uraian ? '' : 'A';
                                    $tipe = $mode_uraian ? 'Essay' : 'Pilihan Ganda';
                                } elseif (!$mode_uraian && preg_match('/^([A-E])[\.\)]\s*(.*)/i', $text, $m)) {
                                    $opts[strtoupper($m[1])] = trim($m[2]);
                                } elseif (preg_match('/^kunci\s+uraian\s*:\s*(.+)$/i', $text, $m)) {
                                    $kunci = trim($m[1]);
                                    $tipe = 'Essay';
                                } elseif (preg_match('/^kunci\s*:\s*(.+)$/i', $text, $m)) {
                                    if ($mode_uraian) {
                                        $kunci = trim($m[1]);
                                        $tipe = 'Essay';
                                    } else {
                                        preg_match_all('/[A-E]/i', $m[1], $km);
                                        $letters = array_unique(array_map('strtoupper', $km[0]));
                                        if (!empty($letters)) $kunci = implode(',', $letters);
                                    }
                                }
                            }
                            $save_docx();
                            if ($skipped_uraian_docx > 0 && $imported_count === 0) {
                                $message = ['type' => 'warning', 'text' => 'Soal uraian dilewati: tugas Kuis hanya menerima Pilihan Ganda dan Pilihan Ganda Kompleks.'];
                            }
                        }
                    }
                } elseif (in_array($ext, ['xls', 'xml', 'html', 'htm'], true) || strpos(mime_content_type($tmp), 'excel') !== false || strpos(mime_content_type($tmp), 'xml') !== false) {
                    $html_content = file_get_contents($tmp);
                    $dom = new DOMDocument();
                    @$dom->loadHTML($html_content);
                    $ins_xls = $pdo->prepare("INSERT INTO tb_tugas_soal (id_tugas, tipe_soal, pertanyaan, pilihan_a, pilihan_b, pilihan_c, pilihan_d, pilihan_e, kunci_jawaban, bobot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $is_kuis_mode = ($tugas['jenis_tugas'] === 'Kuis');
                    $sheets = $dom->getElementsByTagName('Worksheet');
                    if ($sheets->length === 0) {
                        $sheets = [$dom];
                    }
                    $skipped_uraian = 0;
                    foreach ($sheets as $sheet) {
                        $sheet_name = '';
                        if ($sheet instanceof DOMElement && $sheet->hasAttribute('ss:Name')) {
                            $sheet_name = strtolower($sheet->getAttribute('ss:Name'));
                        }
                        $is_uraian_sheet = (strpos($sheet_name, 'uraian') !== false);
                        $rows = ($sheet instanceof DOMElement ? $sheet : $dom)->getElementsByTagName('Row');
                        $first_row = true;
                        foreach ($rows as $row) {
                            $cells = $row->getElementsByTagName('Cell');
                            $rowData = [];
                            foreach ($cells as $cell) {
                                $rowData[] = trim($cell->textContent);
                            }
                            if ($first_row) { $first_row = false; continue; }
                            if (empty($rowData[1])) continue;
                            if (stripos($rowData[1], 'Hapus ') === 0) continue;
                            if ($is_uraian_sheet || (count($rowData) === 3 && stripos($rowData[0] . ' ' . implode(' ', $rowData), 'uraian') === false && count($rowData) < 8)) {
                                // Format lembar Uraian: No | Pertanyaan | Kunci
                                if ($is_kuis_mode) { $skipped_uraian++; continue; }
                                $b = $bobot_jenis_map['Essay'] ?? 10;
                                $ins_xls->execute([$id_tugas, 'Essay', $rowData[1], '', '', '', '', '', $rowData[2] ?? '', $b]);
                                $imported_count++;
                            } elseif (count($rowData) >= 8) {
                                // Format lembar Pilihan Ganda: No | Pertanyaan | A | B | C | D | E | Kunci
                                preg_match_all('/[A-E]/i', $rowData[7], $km);
                                $letters = array_unique(array_map('strtoupper', $km[0]));
                                if (empty($letters)) continue;
                                $kunci = implode(',', $letters);
                                $tipe = count($letters) > 1 ? 'Pilihan Ganda Kompleks' : 'Pilihan Ganda';
                                $b = $bobot_jenis_map[$tipe] ?? 10;
                                $ins_xls->execute([$id_tugas, $tipe, $rowData[1], $rowData[2], $rowData[3], $rowData[4], $rowData[5], $rowData[6], $kunci, $b]);
                                $imported_count++;
                            }
                        }
                    }
                    if ($imported_count === 0 && $skipped_uraian === 0) {
                        // Fallback CSV parse if XML cells not found
                        $handle = fopen($tmp, 'r');
                        $rIdx = 0;
                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            if ($rIdx === 0) { $rIdx++; continue; }
                            if (count($data) >= 8 && !empty($data[1])) {
                                preg_match_all('/[A-E]/i', $data[7], $km2);
                                $letters2 = array_unique(array_map('strtoupper', $km2[0]));
                                if (empty($letters2)) continue;
                                $kunci2 = implode(',', $letters2);
                                $tipe2 = count($letters2) > 1 ? 'Pilihan Ganda Kompleks' : 'Pilihan Ganda';
                                $b2 = $bobot_jenis_map[$tipe2] ?? 10;
                                $pdo->prepare("INSERT INTO tb_tugas_soal (id_tugas, tipe_soal, pertanyaan, pilihan_a, pilihan_b, pilihan_c, pilihan_d, pilihan_e, kunci_jawaban, bobot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                    ->execute([$id_tugas, $tipe2, $data[1], $data[2], $data[3], $data[4], $data[5], $data[6], $kunci2, $b2]);
                                $imported_count++;
                            } elseif (count($data) === 3 && !empty($data[1])) {
                                if ($is_kuis_mode) { $skipped_uraian++; continue; }
                                $b3 = $bobot_jenis_map['Essay'] ?? 10;
                                $pdo->prepare("INSERT INTO tb_tugas_soal (id_tugas, tipe_soal, pertanyaan, pilihan_a, pilihan_b, pilihan_c, pilihan_d, pilihan_e, kunci_jawaban, bobot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                    ->execute([$id_tugas, 'Essay', $data[1], '', '', '', '', '', $data[2] ?? '', $b3]);
                                $imported_count++;
                            }
                        }
                        fclose($handle);
                    }
                    if ($skipped_uraian > 0 && $imported_count === 0) {
                        $message = ['type' => 'warning', 'text' => 'Soal uraian dilewati: tugas Kuis hanya menerima Pilihan Ganda dan Pilihan Ganda Kompleks.'];
                    }
                } elseif (in_array($ext, ['csv', 'txt'], true)) {
                    $handle = fopen($tmp, 'r');
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if (count($data) >= 8 && !empty($data[1])) {
                            preg_match_all('/[A-E]/i', $data[7], $km3);
                            $letters3 = array_unique(array_map('strtoupper', $km3[0]));
                            if (empty($letters3)) continue;
                            $kunci3 = implode(',', $letters3);
                            $tipe3 = count($letters3) > 1 ? 'Pilihan Ganda Kompleks' : 'Pilihan Ganda';
                            $b4 = $bobot_jenis_map[$tipe3] ?? 10;
                            $pdo->prepare("INSERT INTO tb_tugas_soal (id_tugas, tipe_soal, pertanyaan, pilihan_a, pilihan_b, pilihan_c, pilihan_d, pilihan_e, kunci_jawaban, bobot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([$id_tugas, $tipe3, $data[1], $data[2], $data[3], $data[4], $data[5], $data[6], $kunci3, $b4]);
                            $imported_count++;
                        } elseif (count($data) === 3 && !empty($data[1])) {
                            if ($tugas['jenis_tugas'] === 'Kuis') continue;
                            $b5 = $bobot_jenis_map['Essay'] ?? 10;
                            $pdo->prepare("INSERT INTO tb_tugas_soal (id_tugas, tipe_soal, pertanyaan, pilihan_a, pilihan_b, pilihan_c, pilihan_d, pilihan_e, kunci_jawaban, bobot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([$id_tugas, 'Essay', $data[1], '', '', '', '', '', $data[2] ?? '', $b5]);
                            $imported_count++;
                        }
                    }
                    fclose($handle);
                }
                if ($imported_count > 0) {
                    $message = ['type' => 'success', 'text' => "Berhasil mengimpor $imported_count butir soal!"];
                } elseif ($message === null) {
                    $message = ['type' => 'warning', 'text' => 'Tidak ada soal yang berhasil diimpor. Periksa format file.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal mengimpor file: ' . $e->getMessage()];
            }
        }
    }
}

$stSoal = $pdo->prepare("SELECT * FROM tb_tugas_soal WHERE id_tugas = ? ORDER BY id ASC");
$stSoal->execute([$id_tugas]);
$soal_list = $stSoal->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Kelola Soal ' . $tugas['jenis_tugas'];
include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <?php $judul_tampil = preg_replace('/^(individu|kelompok|proyek|praktik|portofolio|kuis|cbt)\s+/i', '', (string)$tugas['judul']); ?>
            <h1>Kelola Soal <?= htmlspecialchars($tugas['jenis_tugas']) ?>: <?= htmlspecialchars($judul_tampil) ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="tugas.php">Daftar Tugas</a></div>
                <div class="breadcrumb-item"><a href="detail_tugas.php?id=<?= $id_tugas ?>">Detail Tugas</a></div>
                <div class="breadcrumb-item active">Buat / Kelola Soal</div>
            </div>
        </div>

        <div class="section-body">
            <div class="mb-3 d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                <a href="detail_tugas.php?id=<?= $id_tugas ?>" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left mr-1"></i> Kembali ke Detail Tugas
                </a>
                <?php if ($can_crud): ?>
                <div class="d-flex" style="gap:6px;">
                    <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#modalImpor">
                        <i class="fas fa-file-excel mr-1"></i> Impor Soal (Word/Excel)
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modalSoal" onclick="resetFormSoal()">
                        <i class="fas fa-plus mr-1"></i> Tambah Soal Baru
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Card Info Ringkas Tugas -->
            <div class="card mb-4 shadow-sm border-left-primary">
                <div class="card-body py-3">
                    <div class="row">
                        <div class="col-md-4"><strong>Mata Pelajaran:</strong> <?= htmlspecialchars($tugas['nama_mapel'] ?? '-') ?></div>
                        <div class="col-md-4"><strong>Kelas:</strong> <?= htmlspecialchars($tugas['nama_kelas'] ?? '-') ?></div>
                        <div class="col-md-4"><strong>Total Soal:</strong> <span class="badge badge-primary"><?= count($soal_list) ?> Butir</span></div>
                    </div>
                </div>
            </div>

            <!-- Pengaturan Bobot per Jenis Soal -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h4><i class="fas fa-balance-scale mr-2"></i>Pengaturan Bobot / Skor per Jenis Soal</h4>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Tentukan bobot sekali per jenis soal. Semua soal dengan jenis yang sama otomatis memakai bobot ini.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="simpan_bobot_jenis">
                        <div class="form-row">
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold small">Pilihan Ganda</label>
                                <input type="number" name="bobot_jenis[Pilihan Ganda]" class="form-control" value="<?= (int)($bobot_jenis_map['Pilihan Ganda'] ?? 10) ?>" min="1" max="100" required>
                            </div>
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold small">Pilihan Ganda Kompleks</label>
                                <input type="number" name="bobot_jenis[Pilihan Ganda Kompleks]" class="form-control" value="<?= (int)($bobot_jenis_map['Pilihan Ganda Kompleks'] ?? 10) ?>" min="1" max="100" required>
                            </div>
                            <?php if ($tugas['jenis_tugas'] !== 'Kuis'): ?>
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold small">Essay / Uraian</label>
                                <input type="number" name="bobot_jenis[Essay]" class="form-control" value="<?= (int)($bobot_jenis_map['Essay'] ?? 10) ?>" min="1" max="100" required>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($can_crud): ?>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save mr-1"></i> Simpan Bobot Jenis Soal</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Daftar Soal -->
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h4><i class="fas fa-list-alt mr-2"></i>Daftar Butir Soal</h4>
                </div>
                <div class="card-body">
                    <?php if (empty($soal_list)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-file-alt fa-3x mb-3 text-muted"></i>
                            <h5>Belum ada soal dibuat.</h5>
                            <p class="text-muted">Klik tombol "Tambah Soal Baru" di atas untuk mulai memasukkan pertanyaan soal pilihan ganda atau essay.</p>
                            <?php if ($can_crud): ?>
                            <button type="button" class="btn btn-primary btn-sm mt-2" data-toggle="modal" data-target="#modalSoal" onclick="resetFormSoal()">
                                <i class="fas fa-plus mr-1"></i> Tambah Soal Sekarang
                            </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($soal_list as $idx => $soal): ?>
                                <div class="list-group-item list-group-item-action flex-column align-items-start mb-3 border rounded shadow-sm">
                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                        <h6 class="mb-1 font-weight-bold text-dark">
                                            <span class="badge badge-primary mr-2">Soal No. <?= $idx + 1 ?></span>
                                            <span class="badge badge-info mr-2"><?= htmlspecialchars($soal['tipe_soal']) ?></span>
                                            <span class="badge badge-secondary">Bobot: <?= (int)$soal['bobot'] ?></span>
                                        </h6>
                                        <?php if ($can_crud): ?>
                                        <div>
                                            <button type="button" class="btn btn-warning btn-sm py-0 px-2 btn-edit-soal" data-json='<?= htmlspecialchars(json_encode($soal), ENT_QUOTES, 'UTF-8') ?>' title="Edit Soal"><i class="fas fa-pencil-alt"></i></button>
                                            <button type="button" class="btn btn-danger btn-sm py-0 px-2 btn-hapus-soal" data-id="<?= (int)$soal['id'] ?>" title="Hapus Soal"><i class="fas fa-times"></i></button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mb-2 font-weight-normal text-dark" style="font-size: 14.5px; line-height: 1.6;"><?= nl2br(htmlspecialchars($soal['pertanyaan'])) ?></p>
                                    <?php if ($soal['tipe_soal'] === 'Pilihan Ganda'): ?>
                                        <div class="row small text-muted pl-3 mb-2" style="font-size: 13.5px;">
                                            <?php foreach (['A' => $soal['pilihan_a'], 'B' => $soal['pilihan_b'], 'C' => $soal['pilihan_c'], 'D' => $soal['pilihan_d'], 'E' => $soal['pilihan_e']] as $k => $opt): ?>
                                                <?php if (!empty($opt)): ?>
                                                    <div class="col-12 py-1 <?= $soal['kunci_jawaban'] === $k ? 'text-success font-weight-bold bg-light rounded px-2' : '' ?>">
                                                        <strong><?= $k ?>.</strong> <?= htmlspecialchars($opt) ?> <?= $soal['kunci_jawaban'] === $k ? '<i class="fas fa-check-circle ml-2"></i> (Kunci Jawaban)' : '' ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="small text-muted font-italic mb-2">Soal Essay / Uraian (Koreksi manual oleh guru).</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Tambah/Edit Soal -->
<div class="modal fade" id="modalSoal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formSoal">
                <input type="hidden" name="action" value="simpan_soal">
                <input type="hidden" name="soal_id" id="soalId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalSoalTitle"><i class="fas fa-plus-circle mr-2"></i>Tambah Soal Baru</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="col-md-6 form-group">
                            <label>Tipe Soal</label>
                            <select name="tipe_soal" id="inp_tipe_soal" class="form-control" required>
                                <option value="Pilihan Ganda">Pilihan Ganda</option>
                                <option value="Pilihan Ganda Kompleks">Pilihan Ganda Kompleks (Multi-Jawaban)</option>
                                <?php if ($tugas['jenis_tugas'] !== 'Kuis'): ?>
                                <option value="Essay">Essay / Uraian</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Bobot Nilai (otomatis per jenis soal)</label>
                            <input type="number" id="inp_bobot" class="form-control" value="10" min="1" max="100" readonly>
                            <small class="text-muted">Diambil dari Pengaturan Bobot per Jenis Soal di atas.</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Pertanyaan / Soal <span class="text-danger">*</span></label>
                        <textarea name="pertanyaan" id="inp_pertanyaan" class="form-control" rows="3" required placeholder="Tuliskan pertanyaan soal di sini..."></textarea>
                    </div>
                    <div class="form-group" id="group_kunci_essay" style="display:none;">
                        <label class="font-weight-bold text-success">Kunci Jawaban / Kata Kunci Essay</label>
                        <input type="text" name="kunci_jawaban_essay" id="inp_kunci_jawaban_essay" class="form-control" placeholder="Tuliskan kata kunci atau contoh jawaban yang benar...">
                        <small class="text-muted">Digunakan sebagai acuan guru saat memeriksa jawaban uraian siswa.</small>
                    </div>
                    
                    <div id="wrapper_pilihan_ganda">
                        <hr>
                        <h6 class="font-weight-bold text-primary mb-3">Pilihan Jawaban Pilihan Ganda</h6>
                        <?php foreach (['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E'] as $key => $lbl): ?>
                            <div class="form-group row">
                                <label class="col-sm-2 col-form-label font-weight-bold">Pilihan <?= $lbl ?></label>
                                <div class="col-sm-10">
                                    <input type="text" name="pilihan_<?= $key ?>" id="inp_pilihan_<?= $key ?>" class="form-control" placeholder="Teks pilihan <?= $lbl ?>...">
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="form-group row" id="group_kunci_pg">
                            <label class="col-sm-2 col-form-label font-weight-bold text-success">Kunci Jawaban</label>
                            <div class="col-sm-4">
                                <select name="kunci_jawaban" id="inp_kunci_jawaban" class="form-control">
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                    <option value="D">D</option>
                                    <option value="E">E</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group row" id="group_kunci_kompleks" style="display:none;">
                            <label class="col-sm-2 col-form-label font-weight-bold text-success">Kunci Jawaban (Multi)</label>
                            <div class="col-sm-10 d-flex align-items-center flex-wrap" style="gap:15px; padding-top:6px;">
                                <?php foreach (['A', 'B', 'C', 'D', 'E'] as $opt): ?>
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input chk-kunci-kompleks" id="chk_kunci_<?= $opt ?>" value="<?= $opt ?>">
                                        <label class="custom-control-label font-weight-bold" for="chk_kunci_<?= $opt ?>"><?= $opt ?></label>
                                    </div>
                                <?php endforeach; ?>
                                <input type="hidden" name="kunci_jawaban_kompleks" id="inp_kunci_jawaban_kompleks" value="A">
                            </div>
                            <small class="text-muted col-sm-12 mt-1">Centang semua opsi yang menjadi jawaban benar untuk Pilihan Ganda Kompleks.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Soal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="formHapusSoal" class="d-none">
    <input type="hidden" name="action" value="hapus_soal">
    <input type="hidden" name="soal_id" id="formHapusSoalId">
</form>

<?php
$js_page = [];
if ($message) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}
$js_page[] = <<<'JS'
$(document).ready(function() {
    var bobotJenisMap = <?= json_encode($bobot_jenis_map) ?>;
    function syncBobotModal() {
        var t = $('#inp_tipe_soal').val();
        $('#inp_bobot').val(bobotJenisMap[t] || 10);
    }
    function toggleTipeSoal() {
        var t = $('#inp_tipe_soal').val();
        syncBobotModal();
        if (t === 'Essay') {
            $('#wrapper_pilihan_ganda').slideUp(150);
            $('#group_kunci_pg').hide();
            $('#group_kunci_kompleks').hide();
            $('#group_kunci_essay').show();
        } else if (t === 'Pilihan Ganda Kompleks') {
            $('#wrapper_pilihan_ganda').slideDown(150);
            $('#group_kunci_pg').hide();
            $('#group_kunci_kompleks').show();
            $('#group_kunci_essay').hide();
        } else {
            $('#wrapper_pilihan_ganda').slideDown(150);
            $('#group_kunci_pg').show();
            $('#group_kunci_kompleks').hide();
            $('#group_kunci_essay').hide();
        }
    }
    $('#inp_tipe_soal').on('change', toggleTipeSoal);

    function updateKompleksString() {
        var arr = [];
        $('.chk-kunci-kompleks:checked').each(function() {
            arr.push($(this).val());
        });
        $('#inp_kunci_jawaban_kompleks').val(arr.join(',') || 'A');
    }
    $(document).on('change', '.chk-kunci-kompleks', updateKompleksString);

    window.resetFormSoal = function() {
        $('#formSoal')[0].reset();
        $('#soalId').val('');
        $('.chk-kunci-kompleks').prop('checked', false);
        $('#modalSoalTitle').text('Tambah Soal Baru');
        toggleTipeSoal();
        updateKompleksString();
    };

    $(document).on('click', '.btn-edit-soal', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') { try { data = JSON.parse(data); } catch(e) {} }
        $('#formSoal')[0].reset();
        $('.chk-kunci-kompleks').prop('checked', false);
        $('#soalId').val(data.id);
        $('#modalSoalTitle').text('Edit Soal Kuis / CBT');
        $('#inp_tipe_soal').val(data.tipe_soal || 'Pilihan Ganda').trigger('change');
        $('#inp_bobot').val(data.bobot || 10);
        $('#inp_pertanyaan').val(data.pertanyaan || '');
        $('#inp_pilihan_a').val(data.pilihan_a || '');
        $('#inp_pilihan_b').val(data.pilihan_b || '');
        $('#inp_pilihan_c').val(data.pilihan_c || '');
        $('#inp_pilihan_d').val(data.pilihan_d || '');
        $('#inp_pilihan_e').val(data.pilihan_e || '');
        
        if (data.tipe_soal === 'Pilihan Ganda Kompleks') {
            var keys = (data.kunci_jawaban || '').split(',');
            keys.forEach(function(k) {
                $('#chk_kunci_' + k.trim()).prop('checked', true);
            });
            updateKompleksString();
        } else if (data.tipe_soal === 'Essay') {
            $('#inp_kunci_jawaban_essay').val(data.kunci_jawaban || '');
        } else {
            $('#inp_kunci_jawaban').val(data.kunci_jawaban || 'A');
        }
        toggleTipeSoal();
        $('#modalSoal').modal('show');
    });

    $(document).on('click', '.btn-hapus-soal', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Hapus Soal?',
            text: 'Soal ini akan dihapus permanen.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusSoalId').val(id);
                $('#formHapusSoal').submit();
            }
        });
    });
});
JS;
?>

<!-- Modal Impor Soal -->
<div class="modal fade" id="modalImpor" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="impor_soal">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-excel mr-2"></i>Impor Soal dari Word (.docx) / Excel (.xls/.xlsx)</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <?php $tpl_mode = ($tugas['jenis_tugas'] === 'Kuis') ? 'kuis' : 'cbt'; ?>
                    <div class="alert alert-info small mb-3">
                        <i class="fas fa-info-circle mr-1"></i> <strong>Unduh template asli sesuai jenis tugas ini (<?= htmlspecialchars($tugas['jenis_tugas']) ?>):</strong><br>
                        1. <strong>Word (.docx asli):</strong> Bagian A Pilihan Ganda — kunci biasa <code>Kunci: B</code>, kompleks <code>Kunci: A,C</code>.<?php if ($tugas['jenis_tugas'] !== 'Kuis'): ?><br>Bagian B Uraian — <code>Kunci Uraian: kata kunci jawaban</code>.<?php else: ?><br>Tugas Kuis tidak menerima uraian: lembar/baris uraian otomatis dilewati saat impor.<?php endif; ?><br>
                        2. <strong>Excel (.xls asli):</strong> lembar <code>Pilihan_Ganda</code> (No | Pertanyaan | A | B | C | D | E | Kunci)<?php if ($tugas['jenis_tugas'] !== 'Kuis'): ?> + lembar <code>Uraian</code> (No | Pertanyaan | Kunci)<?php endif; ?>. Bobot per jenis soal diatur di halaman ini, bukan di file.
                    </div>

                    <div class="mb-3 d-flex flex-wrap" style="gap:8px;">
                        <a href="download_template_soal.php?format=excel&mode=<?= $tpl_mode ?>" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-file-excel mr-1"></i> Unduh Template Excel <?= htmlspecialchars($tugas['jenis_tugas']) ?> (.xls)
                        </a>
                        <a href="download_template_soal.php?format=docx&mode=<?= $tpl_mode ?>" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-file-word mr-1"></i> Unduh Template Word <?= htmlspecialchars($tugas['jenis_tugas']) ?> (.docx)
                        </a>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Pilih Berkas Soal (.docx / .xls / .xlsx)</label>
                        <input type="file" name="file_impor" class="form-control-file" accept=".docx,.xls,.xlsx,.csv,.txt" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-upload mr-1"></i> Mulai Impor Soal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
