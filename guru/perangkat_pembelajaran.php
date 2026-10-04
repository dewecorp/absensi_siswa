<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran_aktif = $school_profile['tahun_ajaran'] ?? date('Y') . '/' . (date('Y') + 1);
$semester_aktif = $school_profile['semester'] ?? 'Semester 1';

$upload_dir = guru_upload_dir($pdo, $guru_id, 'perangkat');

// Handler Download Berkas Perangkat
if (isset($_GET['download']) && (int)$_GET['download'] > 0) {
    $dl_id = (int)$_GET['download'];
    $stmtDl = $pdo->prepare("SELECT judul, file_path FROM tb_perangkat_pembelajaran WHERE id = ?");
    $stmtDl->execute([$dl_id]);
    $itemDl = $stmtDl->fetch(PDO::FETCH_ASSOC);

    if ($itemDl && !empty($itemDl['file_path'])) {
        $filePath = resolve_guru_file_path('perangkat', $itemDl['file_path']);
        if ($filePath && is_file($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $safe_title = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($itemDl['judul']));
            $download_filename = ($safe_title !== '' ? $safe_title : 'perangkat_pembelajaran') . '.' . $ext;

            while (ob_get_level()) { ob_end_clean(); }
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $download_filename . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
    http_response_code(404);
    echo "<script>alert('Berkas tidak ditemukan.'); window.history.back();</script>";
    exit;
}

$message = null;
$can_crud = !in_array($user_level, ['admin', 'kepala_madrasah', 'tata_usaha'], true);

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki hak akses untuk mengubah data ini.'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $jenis_perangkat = trim((string)($_POST['jenis_perangkat'] ?? ''));
        $judul = trim((string)($_POST['judul'] ?? ''));
        $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
        $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $materi_tp = trim((string)($_POST['materi_tp'] ?? ''));
        $semester = trim((string)($_POST['semester'] ?? $semester_aktif));
        $tahun_ajaran = trim((string)($_POST['tahun_ajaran'] ?? $tahun_ajaran_aktif));
        $status = in_array($_POST['status'] ?? '', ['Draft', 'Aktif', 'Arsip'], true) ? $_POST['status'] : 'Aktif';
        $cp = trim((string)($_POST['cp'] ?? ''));
        $tp = trim((string)($_POST['tp'] ?? ''));
        $materi = trim((string)($_POST['materi'] ?? ''));
        $tujuan_pembelajaran = trim((string)($_POST['tujuan_pembelajaran'] ?? ''));
        $indikator = trim((string)($_POST['indikator'] ?? ''));
        $deskripsi = trim((string)($_POST['deskripsi'] ?? ''));

        $file_path = null;
        if (isset($_FILES['file_perangkat']) && $_FILES['file_perangkat']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['file_perangkat']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];
            if (in_array($ext, $allowed, true)) {
                $filename = 'perangkat_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['file_perangkat']['tmp_name'], $upload_dir . $filename)) {
                    $file_path = guru_folder_name($pdo, $guru_id) . '/' . $filename;
                }
            }
        }

        if ($judul === '' || $jenis_perangkat === '') {
            $message = ['type' => 'warning', 'text' => 'Judul dan Jenis Perangkat wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_perangkat_pembelajaran (
                            id_guru, jenis_perangkat, judul, id_mapel, id_kelas, materi_tp,
                            semester, tahun_ajaran, file_path, status, cp, tp, materi,
                            tujuan_pembelajaran, indikator, deskripsi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $jenis_perangkat, $judul, $id_mapel, $id_kelas, $materi_tp,
                        $semester, $tahun_ajaran, $file_path, $status, $cp, $tp, $materi,
                        $tujuan_pembelajaran, $indikator, $deskripsi
                    ]);
                    $message = ['type' => 'success', 'text' => 'Perangkat pembelajaran berhasil ditambahkan.'];
                } else {
                    $sql = "
                        UPDATE tb_perangkat_pembelajaran SET
                            jenis_perangkat = ?, judul = ?, id_mapel = ?, id_kelas = ?,
                            materi_tp = ?, semester = ?, tahun_ajaran = ?, status = ?,
                            cp = ?, tp = ?, materi = ?, tujuan_pembelajaran = ?,
                            indikator = ?, deskripsi = ?
                    ";
                    $params = [
                        $jenis_perangkat, $judul, $id_mapel, $id_kelas, $materi_tp,
                        $semester, $tahun_ajaran, $status, $cp, $tp, $materi,
                        $tujuan_pembelajaran, $indikator, $deskripsi
                    ];
                    if ($file_path !== null) {
                        $sql .= ", file_path = ?";
                        $params[] = $file_path;
                    }
                    $sql .= " WHERE id = ? AND id_guru = ?";
                    $params[] = $id;
                    $params[] = $guru_id;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = ['type' => 'success', 'text' => 'Perangkat pembelajaran berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'arsip') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE tb_perangkat_pembelajaran SET status = 'Arsip' WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Perangkat berhasil diarsipkan.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal mengarsipkan: ' . $e->getMessage()];
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT file_path FROM tb_perangkat_pembelajaran WHERE id = ? AND id_guru = ?");
            $stmt->execute([$id, $guru_id]);
            $old_file = $stmt->fetchColumn();
            $old_path = $old_file ? resolve_guru_file_path('perangkat', $old_file) : null;
            if ($old_path && is_file($old_path)) {
                @unlink($old_path);
            } elseif ($old_file && is_file($upload_dir . $old_file)) {
                @unlink($upload_dir . $old_file);
            } elseif ($old_file && is_file($upload_dir . basename($old_file))) {
                @unlink($upload_dir . basename($old_file));
            }
            $del = $pdo->prepare("DELETE FROM tb_perangkat_pembelajaran WHERE id = ? AND id_guru = ?");
            $del->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Perangkat berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
        }
    }
}

// Master lists for filter & forms
$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah', 'tata_usaha'], true);
if ($is_admin_or_kepala) {
    $mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);
    $kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $mapel_list = getGuruTaughtMapels($pdo, $guru_id);
    $kelas_list = getGuruTaughtClasses($pdo, $guru_id);
}

$jenis_options = [
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
$semester_options = ['Semester 1', 'Semester 2'];

// Filters
$f_jenis = trim((string)($_GET['f_jenis'] ?? ''));
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_semester = trim((string)($_GET['f_semester'] ?? ''));
$f_tahun = trim((string)($_GET['f_tahun'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

if ($is_admin_or_kepala) {
    $where = ["1=1"];
    $params = [];
} else {
    $where = ["p.id_guru = ?"];
    $params = [$guru_id];
}

if ($f_jenis !== '') {
    $where[] = "p.jenis_perangkat = ?";
    $params[] = $f_jenis;
}
if ($f_mapel > 0) {
    $where[] = "p.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_kelas > 0) {
    $where[] = "p.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_semester !== '') {
    $where[] = "p.semester = ?";
    $params[] = $f_semester;
}
if ($f_tahun !== '') {
    $where[] = "p.tahun_ajaran = ?";
    $params[] = $f_tahun;
}
if ($f_status !== '') {
    $where[] = "p.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT p.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_perangkat_pembelajaran p
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = p.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = p.id_guru
    WHERE $where_sql
    ORDER BY p.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unique years for filter
$tahun_list = $pdo->query("SELECT DISTINCT tahun_ajaran FROM tb_perangkat_pembelajaran WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran != '' ORDER BY tahun_ajaran DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($tahun_ajaran_aktif, $tahun_list, true)) {
    array_unshift($tahun_list, $tahun_ajaran_aktif);
}

$page_title = 'Daftar Perangkat Pembelajaran';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
// JavaScript Download Function using Blob (Safe from Chrome/Edge insecure connection blocking)
function downloadPerangkat(id, format) {
    if (!id) return;
    if (typeof toastr !== 'undefined') {
        toastr.info('Memulai pengunduhan berkas...', '', { timeOut: 1500 });
    }
    var url = 'download_perangkat.php?id=' + encodeURIComponent(id);
    if (format) {
        url += '&format=' + encodeURIComponent(format);
    }
    fetch(url)
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            var disposition = res.headers.get('Content-Disposition') || '';
            var filename = 'perangkat_pembelajaran' + (format ? '.' + format : '');
            var matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
            if (matches != null && matches[1]) {
                filename = matches[1].replace(/['"]/g, '').trim();
            }
            return res.blob().then(function(blob) {
                return { blob: blob, filename: filename };
            });
        })
        .then(function(data) {
            var blobUrl = window.URL.createObjectURL(data.blob);
            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = blobUrl;
            a.download = data.filename;
            document.body.appendChild(a);
            a.click();
            setTimeout(function() {
                window.URL.revokeObjectURL(blobUrl);
                document.body.removeChild(a);
            }, 500);
            if (typeof toastr !== 'undefined') {
                toastr.success('Berkas ' + data.filename + ' berhasil diunduh.', 'Selesai');
            } else if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Berhasil', text: 'Berkas ' + data.filename + ' berhasil diunduh.', timer: 2000, showConfirmButton: false });
            }
        })
        .catch(function(err) {
            window.location.href = url;
        });
}

$(document).ready(function() {
    if ($('#table-perangkat').length) {
        $('#table-perangkat').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [8, 11] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    // Auto-submit filter on change
    $('#formFilterPerangkat select').on('change', function() {
        $('#formFilterPerangkat').submit();
    });

    // Detail Modal (Desain Modern, Kontras Tinggi, & Hanya Menampilkan Kolom yang Terisi)
    $(document).on('click', '.btn-detail', function() {
        var data = $(this).data('json');
        
        var statusColor = '#15803d';
        var statusBg = '#dcfce7';
        var statusBorder = '#bbf7d0';
        if (data.status === 'Draft') {
            statusColor = '#b45309';
            statusBg = '#fef3c7';
            statusBorder = '#fde68a';
        } else if (data.status === 'Arsip') {
            statusColor = '#475569';
            statusBg = '#f1f5f9';
            statusBorder = '#cbd5e1';
        }
        
        var html = '';

        // 1. Header Card (Judul & Metadata Tag)
        html += '<div style="background: #ffffff; border: 1px solid #cbd5e1; border-left: 5px solid #2563eb; border-radius: 8px; padding: 14px 16px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">';
        html += '  <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 8px;">';
        html += '    <h4 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a; line-height: 1.3;">' + $('<div>').text(data.judul || '').html() + '</h4>';
        html += '    <div style="display: flex; gap: 6px; flex-shrink: 0;">';
        html += '      <span style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 700; font-size: 12px; padding: 3px 8px; border-radius: 4px;">' + $('<div>').text(data.jenis_perangkat || '').html() + '</span>';
        html += '      <span style="background: ' + statusBg + '; color: ' + statusColor + '; border: 1px solid ' + statusBorder + '; font-weight: 700; font-size: 12px; padding: 3px 8px; border-radius: 4px;">' + $('<div>').text(data.status || '').html() + '</span>';
        html += '    </div>';
        html += '  </div>';

        var metaPills = [];
        if (data.nama_mapel) {
            metaPills.push('<span style="background: #f1f5f9; color: #0f172a; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; border: 1px solid #cbd5e1;"><i class="fas fa-book mr-1 text-primary"></i> ' + $('<div>').text(data.nama_mapel).html() + '</span>');
        }
        if (data.nama_kelas) {
            metaPills.push('<span style="background: #f1f5f9; color: #0f172a; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; border: 1px solid #cbd5e1;"><i class="fas fa-graduation-cap mr-1 text-success"></i> Kelas ' + $('<div>').text(data.nama_kelas).html() + '</span>');
        }
        if (data.semester) {
            metaPills.push('<span style="background: #f1f5f9; color: #0f172a; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; border: 1px solid #cbd5e1;"><i class="fas fa-calendar mr-1 text-warning"></i> ' + $('<div>').text(data.semester).html() + '</span>');
        }
        if (data.tahun_ajaran) {
            metaPills.push('<span style="background: #f1f5f9; color: #0f172a; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; border: 1px solid #cbd5e1;"><i class="fas fa-calendar-alt mr-1 text-info"></i> ' + $('<div>').text(data.tahun_ajaran).html() + '</span>');
        }

        if (metaPills.length > 0) {
            html += '  <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px;">' + metaPills.join('') + '</div>';
        }
        html += '</div>';

        // 2. Hasil Generate AI tanpa file: tampilkan tombol Baca + Unduh seperti versi file
        if ((!data.file_path || data.file_path.trim() === '') && (data.isi_dokumen || data.deskripsi)) {
            html += '<div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">';
            html += '  <div style="display: flex; align-items: center; gap: 12px;">';
            html += '    <div style="width: 40px; height: 40px; background: #dcfce7; color: #15803d; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px;">';
            html += '      <i class="fas fa-robot"></i>';
            html += '    </div>';
            html += '    <div>';
            html += '      <div style="font-size: 14px; font-weight: 700; color: #0f172a;">Dokumen Hasil Generate AI</div>';
            html += '      <div style="font-size: 12px; color: #475569; font-weight: 600;">Pratinjau teks + unduh PDF / Word / Excel</div>';
            html += '    </div>';
            html += '  </div>';
            html += '  <div style="display: flex; gap: 6px;">';
            html += '    <a href="preview_perangkat.php?id=' + data.id + '" target="_blank" class="btn btn-sm" style="background: #2563eb; color: #ffffff; font-weight: 700; padding: 7px 14px; border-radius: 6px; text-decoration: none;">';
            html += '      <i class="fas fa-book-reader mr-1"></i> Baca Dokumen';
            html += '    </a>';
            html += '    <button type="button" onclick="downloadPerangkat(' + data.id + ', \'pdf\')" class="btn btn-sm" style="background: #dc2626; color: #ffffff; font-weight: 700; padding: 7px 12px; border-radius: 6px; border:none;" title="Unduh PDF"><i class="fas fa-file-pdf"></i></button>';
            html += '    <button type="button" onclick="downloadPerangkat(' + data.id + ', \'docx\')" class="btn btn-sm" style="background: #1d4ed8; color: #ffffff; font-weight: 700; padding: 7px 12px; border-radius: 6px; border:none;" title="Unduh Word"><i class="fas fa-file-word"></i></button>';
            html += '    <button type="button" onclick="downloadPerangkat(' + data.id + ', \'xlsx\')" class="btn btn-sm" style="background: #16a34a; color: #ffffff; font-weight: 700; padding: 7px 12px; border-radius: 6px; border:none;" title="Unduh Excel"><i class="fas fa-file-excel"></i></button>';
            html += '  </div>';
            html += '</div>';
        }

        // 2b. Berkas Terlampir (Hanya jika ada)
        if (data.file_path && data.file_path.trim() !== '') {
            var ext = data.file_path.split('.').pop().toUpperCase();
            html += '<div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">';
            html += '  <div style="display: flex; align-items: center; gap: 12px;">';
            html += '    <div style="width: 40px; height: 40px; background: #e0f2fe; color: #0284c7; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px;">';
            html += '      <i class="fas fa-file-alt"></i>';
            html += '    </div>';
            html += '    <div>';
            html += '      <div style="font-size: 14px; font-weight: 700; color: #0f172a;">Berkas Dokumen Terlampir</div>';
            html += '      <div style="font-size: 12px; color: #475569; font-weight: 600;">Format berkas: .' + ext + '</div>';
            html += '    </div>';
            html += '  </div>';
            html += '  <div style="display: flex; gap: 8px;">';
            html += '    <a href="preview_perangkat.php?id=' + data.id + '" target="_blank" class="btn btn-sm" style="background: #2563eb; color: #ffffff; font-weight: 700; padding: 7px 14px; border-radius: 6px; text-decoration: none;">';
            html += '      <i class="fas fa-book-reader mr-1"></i> Baca Dokumen';
            html += '    </a>';
            html += '    <button type="button" onclick="downloadPerangkat(' + data.id + ')" class="btn btn-sm" style="background: #16a34a; color: #ffffff; font-weight: 700; padding: 7px 16px; border-radius: 6px; border: none; box-shadow: 0 2px 4px rgba(22,163,74,0.3);">';
            html += '      <i class="fas fa-download mr-1"></i> Unduh (' + ext + ')';
            html += '    </button>';
            html += '  </div>';
            html += '</div>';
        }

        // 3. Rincian Konten (HANYA tampilkan yang terisi/ada isinya!)
        // Hasil Generate AI memakai isi_dokumen sebagai Isi Dokumen;
        // bila kosong (data lama), susun dari field terpisah agar tetap tampil.
        var isiAi = (data.isi_dokumen && String(data.isi_dokumen).trim() !== '') ? data.isi_dokumen : '';
        if (isiAi === '') {
            var susun = [];
            var tambah = function(j, v) {
                v = v ? String(v).trim() : '';
                if (v !== '' && v !== '-') susun.push(j + '\n' + v);
            };
            tambah('A. CAPAIAN PEMBELAJARAN (CP)', data.cp);
            tambah('B. TUJUAN PEMBELAJARAN (TP)', data.tp);
            tambah('C. MATERI POKOK', ((data.materi_tp ? String(data.materi_tp).trim() + '\n' : '') + (data.materi ? String(data.materi).trim() : '')).trim());
            tambah('D. TUJUAN PEMBELAJARAN KHUSUS', data.tujuan_pembelajaran);
            tambah('E. INDIKATOR KETERCAPAIAN', data.indikator);
            tambah('F. DESKRIPSI / CATATAN', data.deskripsi);
            isiAi = susun.join('\n\n');
        }
        var items = [
            { label: 'Materi / TP Ringkas', val: data.materi_tp, icon: 'fas fa-bookmark' },
            { label: 'Capaian Pembelajaran (CP)', val: data.cp, icon: 'fas fa-bullseye' },
            { label: 'Tujuan Pembelajaran (TP)', val: data.tp, icon: 'fas fa-flag-checkered' },
            { label: 'Materi Pembelajaran', val: data.materi, icon: 'fas fa-book-reader' },
            { label: 'Tujuan Pembelajaran Khusus', val: data.tujuan_pembelajaran, icon: 'fas fa-check-circle' },
            { label: 'Indikator Ketercapaian', val: data.indikator, icon: 'fas fa-tasks' },
            { label: 'Isi Dokumen', val: isiAi, icon: 'fas fa-file-alt' },
            { label: 'Deskripsi / Catatan Tambahan', val: data.deskripsi, icon: 'fas fa-comment-alt' }
        ];

        // Formatter untuk teks yang mengandung baris tabel matriks (Promes, Prota, Silabus, dll)
        function formatModalTextWithTables(raw) {
            if (!raw) return '';
            var lines = (raw + '').split(/\r\n|\r|\n/);
            var out = [];
            var tbl = [];

            function flushTbl() {
                if (!tbl.length) return;
                var max_c = 0;
                var clean_rows = [];
                tbl.forEach(function(r) {
                    var cols = r.split('|').map(function(c) { return c.trim(); });
                    if (cols.length && cols[0] === '') cols.shift();
                    if (cols.length && cols[cols.length - 1] === '') cols.pop();
                    if (!cols.length) return;
                    var isSep = cols.every(function(c) { return /^:?-+:?$/.test(c); });
                    if (isSep) return;
                    if (cols.length > max_c) max_c = cols.length;
                    clean_rows.push(cols);
                });

                if (clean_rows.length > 0) {
                    var t = '<div class="table-responsive my-3"><table class="table table-bordered table-sm table-striped text-dark" style="font-size:12.5px;width:100%;min-width:' + (max_c > 7 ? '850px' : '600px') + ';">';
                    var first = true;
                    clean_rows.forEach(function(cols) {
                        var tag = first ? 'th' : 'td';
                        var bg = first ? ' class="thead-light text-center"' : '';
                        t += '<tr' + bg + '>';
                        cols.forEach(function(c, ci) {
                            var align = (first || ci === 0 || /^\d+(\s*JP)?$/i.test(c) || c === '-') ? ' text-center' : ' text-left';
                            t += '<' + tag + ' class="align-middle' + align + '" style="padding:6px 8px;border:1px solid #cbd5e1;">' + $('<div>').text(c).html() + '</' + tag + '>';
                        });
                        t += '</tr>';
                        first = false;
                    });
                    t += '</table></div>';
                    out.push(t);
                }
                tbl = [];
            }

            lines.forEach(function(ln) {
                var tr = ln.trim();
                if (tr.indexOf('|') !== -1 && !/^[A-Z]\./.test(tr)) {
                    tbl.push(tr);
                } else {
                    flushTbl();
                    if (tr === '') {
                        out.push('<div style="height:6px;"></div>');
                    } else if (/^\[GAMBAR:\s*(.*?)\]$/i.test(tr)) {
                        var gm = /^\[GAMBAR:\s*(.*?)\]$/i.exec(tr);
                        var gDesc = gm ? gm[1] : '';
                        var imgId = 'modalImg_' + Math.random().toString(36).substr(2, 9);
                        out.push('<div class="my-3 text-center p-2 bg-white rounded border" style="max-width:650px;margin-left:auto;margin-right:auto;box-shadow:0 2px 6px rgba(0,0,0,0.08);">'
                            + '<img id="' + imgId + '" src="" alt="' + $('<div>').text(gDesc).html() + '" style="max-width:100%;height:auto;max-height:360px;border-radius:4px;display:none;">'
                            + '<div id="' + imgId + '_loading" class="text-muted small py-3"><i class="fas fa-spinner fa-spin mr-1"></i> Memuat gambar edukasi otentik...</div>'
                            + '<div class="mt-2 text-muted small font-italic"><i class="fas fa-image mr-1"></i>Gambar: ' + $('<div>').text(gDesc).html() + '</div>'
                            + '</div>');
                        setTimeout((function(id, desc) {
                            return function() {
                                $.getJSON('ajax_generate_perangkat.php', { aksi: 'resolve_image', desc: desc }, function(res) {
                                    $('#' + id + '_loading').hide();
                                    if (res && res.ok && res.url) {
                                        $('#' + id).attr('src', res.url).show();
                                    }
                                });
                            };
                        })(imgId, gDesc), 100);
                    } else if (/^[A-Z]\.\s+/.test(tr)) {
                        out.push('<h6 class="font-weight-bold mt-3 mb-1 text-primary border-bottom pb-1">' + $('<div>').text(tr).html() + '</h6>');
                    } else if (/^\d+\.\s+/.test(tr)) {
                        out.push('<div class="font-weight-bold mt-2 mb-1 text-dark">' + $('<div>').text(tr).html() + '</div>');
                    } else {
                        var escP = $('<div>').text(tr).html();
                        out.push('<p class="mb-1 text-dark" style="line-height:1.6;font-size:13.5px;">' + escP + '</p>');
                    }
                }
            });
            flushTbl();
            return out.join('');
        }

        var contentCount = 0;
        items.forEach(function(item) {
            var val = item.val ? String(item.val).trim() : '';
            if (val !== '' && val !== '-') {
                contentCount++;
                var isTableContent = (item.label === 'Isi Dokumen' || val.indexOf('|') !== -1);
                var bodyHtml = '';
                if (isTableContent) {
                    bodyHtml = formatModalTextWithTables(val);
                } else {
                    bodyHtml = $('<div>').text(val).html();
                    bodyHtml = bodyHtml.replace(/\[GAMBAR:\s*([\s\S]*?)\]/g, function(m, g) {
                        return '<div style="border:1px solid #f59e0b;background:#fffbeb;padding:6px 8px;margin:6px 0;font-weight:700;">[GAMBAR: ' + g + ']</div>';
                    });
                }
                var preWrapStyle = isTableContent ? '' : 'white-space: pre-wrap; ';
                html += '<div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">';
                html += '  <div style="background: #f1f5f9; padding: 9px 14px; border-bottom: 1px solid #cbd5e1; display: flex; align-items: center; gap: 8px;">';
                html += '    <i class="' + item.icon + '" style="color: #2563eb; font-size: 13px;"></i>';
                html += '    <span style="font-size: 12.5px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.3px;">' + item.label + '</span>';
                html += '  </div>';
                html += '  <div style="padding: 12px 14px; font-size: 14px; line-height: 1.6; color: #0f172a; font-weight: 500; ' + preWrapStyle + 'background: #ffffff;">' + bodyHtml + '</div>';
                html += '</div>';
            }
        });

        if (contentCount === 0 && (!data.file_path || data.file_path.trim() === '')) {
            html += '<div style="text-align: center; color: #64748b; padding: 24px; font-weight: 600;">Tidak ada rincian konten tambahan yang diisi.</div>';
        }

        // 4. Metadata Pembuat & Tanggal (High Contrast Footer)
        html += '<div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; margin-top: 14px; display: flex; flex-wrap: wrap; justify-content: space-between; font-size: 12px; color: #334155; gap: 10px;">';
        html += '  <span><i class="fas fa-user-edit mr-1 text-primary"></i> Pembuat: <strong style="color: #0f172a;">' + $('<div>').text(data.nama_guru || '-').html() + '</strong></span>';
        html += '  <span><i class="fas fa-clock mr-1 text-info"></i> Dibuat: <strong style="color: #0f172a;">' + $('<div>').text(data.created_at || '-').html() + '</strong></span>';
        if (data.updated_at && data.updated_at !== data.created_at) {
            html += '  <span><i class="fas fa-history mr-1 text-warning"></i> Diperbarui: <strong style="color: #0f172a;">' + $('<div>').text(data.updated_at).html() + '</strong></span>';
        }
        html += '</div>';

        $('#modalDetailBody').html(html);
        $('#modalDetail').modal('show');
    });

    // Edit Modal
    $(document).on('click', '.btn-edit', function() {
        var data = $(this).data('json');
        $('#formPerangkatAction').val('edit');
        $('#perangkatId').val(data.id);
        $('#modalPerangkatTitle').text('Edit Perangkat Pembelajaran');
        $('#inp_jenis').val(data.jenis_perangkat);
        $('#inp_judul').val(data.judul);
        $('#inp_mapel').val(data.id_mapel || '');
        $('#inp_kelas').val(data.id_kelas || '');
        $('#inp_materi_tp').val(data.materi_tp || '');
        $('#inp_semester').val(data.semester);
        $('#inp_tahun').val(data.tahun_ajaran);
        $('#inp_status').val(data.status);
        $('#inp_cp').val(data.cp || '');
        $('#inp_tp').val(data.tp || '');
        $('#inp_materi').val(data.materi || '');
        $('#inp_tujuan').val(data.tujuan_pembelajaran || '');
        $('#inp_indikator').val(data.indikator || '');
        $('#inp_deskripsi').val(data.deskripsi || '');
        $('#modalPerangkat').modal('show');
    });

    $('#btnTambahPerangkat').on('click', function() {
        $('#formPerangkatAction').val('tambah');
        $('#perangkatId').val('');
        $('#modalPerangkatTitle').text('Tambah Perangkat Pembelajaran');
        $('#formPerangkat')[0].reset();
        var validKelasOpts = $('#inp_kelas option').filter(function() { return this.value !== ''; });
        if (validKelasOpts.length === 1) {
            $('#inp_kelas').val(validKelasOpts.val());
        }
        $('#modalPerangkat').modal('show');
    });

    $(document).on('click', '.btn-arsip', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Arsipkan perangkat?',
            text: 'Status perangkat akan diubah menjadi Arsip.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya, Arsipkan',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formArsipId').val(id);
                $('#formArsip').submit();
            }
        });
    });

    $(document).on('click', '.btn-hapus', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Hapus perangkat?',
            text: 'Data dan file perangkat akan dihapus permanen.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusId').val(id);
                $('#formHapus').submit();
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
            <h1>Daftar Perangkat Pembelajaran</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header">
                    <h4><i class="fas fa-filter mr-2"></i>Filter Perangkat</h4>
                </div>
                <div class="card-body">
                    <form method="GET" class="row" id="formFilterPerangkat">
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Jenis Perangkat</label>
                            <select name="f_jenis" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Jenis --</option>
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= htmlspecialchars($j) ?>" <?= $f_jenis === $j ? 'selected' : '' ?>><?= htmlspecialchars($j) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2">
                            <label class="small font-weight-bold">Mata Pelajaran</label>
                            <select name="f_mapel" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>" <?= $f_mapel === (int)$m['id_mapel'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="f_kelas" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Semester</label>
                            <select name="f_semester" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua --</option>
                                <?php foreach ($semester_options as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $f_semester === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Status --</option>
                                <?php foreach (['Aktif', 'Draft', 'Arsip'] as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($f_jenis !== '' || $f_mapel > 0 || $f_kelas > 0 || $f_semester !== '' || $f_status !== '' || $f_tahun !== ''): ?>
                        <div class="col-12 mt-1">
                            <a href="perangkat_pembelajaran.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset Filter</a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4>Daftar Dokumen</h4>
                    <?php if ($can_crud): ?>
                    <div>
                        <button type="button" class="btn btn-primary" id="btnTambahPerangkat">
                            <i class="fas fa-plus mr-1"></i> Tambah Perangkat
                        </button>
                        <a href="generate_perangkat.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode((string)$_GET['session_type']) : '' ?>" class="btn btn-success ml-2">
                            <i class="fas fa-robot mr-1"></i> Generate AI
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-perangkat">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Jenis Perangkat</th>
                                    <th>Judul</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Kelas</th>
                                    <th>Materi/TP</th>
                                    <th>Semester</th>
                                    <th>Tahun Ajaran</th>
                                    <th width="5%" class="text-center">File</th>
                                    <th width="7%" class="text-center">Status</th>
                                    <th>Tanggal</th>
                                    <th class="text-center" style="width: 170px; min-width: 170px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_perangkat']) ?></span></td>
                                        <td><strong><?= htmlspecialchars($r['judul']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['nama_mapel'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['materi_tp'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['semester'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['tahun_ajaran'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($r['file_path'])): ?>
                                                <a href="preview_perangkat.php?id=<?= (int)$r['id'] ?>" target="_blank" class="text-primary font-weight-bold" title="Baca Dokumen (<?= strtoupper(pathinfo($r['file_path'], PATHINFO_EXTENSION)) ?>)">
                                                    <i class="fas fa-file-alt fa-lg"></i>
                                                    <small class="d-block" style="font-size: 10px;"><?= strtoupper(pathinfo($r['file_path'], PATHINFO_EXTENSION)) ?></small>
                                                </a>
                                            <?php else: ?>
                                                <a href="preview_perangkat.php?id=<?= (int)$r['id'] ?>" target="_blank" class="text-success font-weight-bold" title="Pratinjau hasil Generate AI">
                                                    <i class="fas fa-robot fa-lg"></i>
                                                    <small class="d-block" style="font-size: 10px;">AI</small>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php
                                            $badge_cls = $r['status'] === 'Aktif' ? 'success' : ($r['status'] === 'Draft' ? 'warning' : 'secondary');
                                            ?>
                                            <span class="badge badge-<?= $badge_cls ?>"><?= htmlspecialchars($r['status']) ?></span>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
                                        <td class="text-center" style="white-space: nowrap;">
                                            <div class="d-inline-flex align-items-center justify-content-center" style="gap: 4px;">
                                                <button type="button" class="btn btn-info btn-sm btn-detail" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($can_crud): ?>
                                                <button type="button" class="btn btn-warning btn-sm btn-edit" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if (!empty($r['file_path'])): ?>
                                                    <a href="preview_perangkat.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-primary btn-sm" title="Baca Dokumen (Laman Penuh)">
                                                        <i class="fas fa-book-reader"></i>
                                                    </a>
                                                    <button type="button" onclick="downloadPerangkat(<?= (int)$r['id'] ?>)" class="btn btn-success btn-sm" title="Unduh Berkas">
                                                        <i class="fas fa-download"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <a href="preview_perangkat.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-primary btn-sm" title="Pratinjau Hasil AI (Laman Penuh)">
                                                        <i class="fas fa-book-reader"></i>
                                                    </a>
                                                    <div class="btn-group btn-group-sm" role="group" title="Unduh Dokumen">
                                                        <button type="button" onclick="downloadPerangkat(<?= (int)$r['id'] ?>, 'pdf')" class="btn btn-outline-danger" title="Unduh PDF"><i class="fas fa-file-pdf"></i></button>
                                                        <button type="button" onclick="downloadPerangkat(<?= (int)$r['id'] ?>, 'docx')" class="btn btn-outline-primary" title="Unduh Word"><i class="fas fa-file-word"></i></button>
                                                        <button type="button" onclick="downloadPerangkat(<?= (int)$r['id'] ?>, 'xlsx')" class="btn btn-outline-success" title="Unduh Excel"><i class="fas fa-file-excel"></i></button>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($can_crud): ?>
                                                    <?php if ($r['status'] !== 'Arsip'): ?>
                                                        <button type="button" class="btn btn-secondary btn-sm btn-arsip" data-id="<?= (int)$r['id'] ?>" title="Arsipkan">
                                                            <i class="fas fa-archive"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-danger btn-sm btn-hapus" data-id="<?= (int)$r['id'] ?>" title="Hapus">
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

<!-- Modal Form Tambah / Edit -->
<div class="modal fade" id="modalPerangkat" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formPerangkat" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formPerangkatAction" value="tambah">
                <input type="hidden" name="id" id="perangkatId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPerangkatTitle">Tambah Perangkat Pembelajaran</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Jenis Perangkat <span class="text-danger">*</span></label>
                            <select name="jenis_perangkat" id="inp_jenis" class="form-control" required>
                                <option value="">-- Pilih Jenis --</option>
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= htmlspecialchars($j) ?>"><?= htmlspecialchars($j) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Judul Perangkat <span class="text-danger">*</span></label>
                            <input type="text" name="judul" id="inp_judul" class="form-control" required placeholder="Contoh: Modul Ajar IPAS Bab 1">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Mata Pelajaran</label>
                            <select name="id_mapel" id="inp_mapel" class="form-control">
                                <option value="">-- Pilih Mapel --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Kelas <?= count($kelas_list) > 1 ? '<span class="text-danger">*</span>' : '' ?></label>
                            <select name="id_kelas" id="inp_kelas" class="form-control" required>
                                <?php if (count($kelas_list) > 1): ?>
                                    <option value="">-- Pilih Kelas --</option>
                                    <?php foreach ($kelas_list as $k): ?>
                                        <option value="<?= (int)$k['id_kelas'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                    <?php endforeach; ?>
                                <?php elseif (count($kelas_list) === 1): ?>
                                    <option value="<?= (int)$kelas_list[0]['id_kelas'] ?>" selected><?= htmlspecialchars($kelas_list[0]['nama_kelas']) ?></option>
                                <?php else: ?>
                                    <option value="">-- Tidak ada kelas --</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Materi / TP</label>
                            <input type="text" name="materi_tp" id="inp_materi_tp" class="form-control" placeholder="Contoh: Metamorfosis Hewan">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Semester</label>
                            <select name="semester" id="inp_semester" class="form-control">
                                <?php foreach ($semester_options as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $semester_aktif ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tahun Ajaran</label>
                            <input type="text" name="tahun_ajaran" id="inp_tahun" class="form-control" value="<?= htmlspecialchars($tahun_ajaran_aktif) ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Upload File Dokumen</label>
                            <input type="file" name="file_perangkat" class="form-control-file">
                            <small class="text-muted">Format didukung: PDF, Word, Excel, PPT, ZIP</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <option value="Aktif">Aktif</option>
                                <option value="Draft">Draft</option>
                                <option value="Arsip">Arsip</option>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Capaian Pembelajaran (CP)</label>
                            <textarea name="cp" id="inp_cp" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 form-group">
                            <label>Tujuan Pembelajaran (TP)</label>
                            <textarea name="tp" id="inp_tp" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Materi Pokok</label>
                            <textarea name="materi" id="inp_materi" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Indikator Ketercapaian</label>
                            <textarea name="indikator" id="inp_indikator" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 form-group">
                            <label>Deskripsi Tambahan / Catatan</label>
                            <textarea name="deskripsi" id="inp_deskripsi" class="form-control" rows="2"></textarea>
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

<!-- Modal Detail Perangkat (Modern & Informatif) -->
<div class="modal fade" id="modalDetail" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title text-primary"><i class="fas fa-file-invoice mr-2"></i>Rincian Dokumen Perangkat</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-3" id="modalDetailBody">
            </div>
            <div class="modal-footer border-top py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formArsip" class="d-none">
    <input type="hidden" name="action" value="arsip">
    <input type="hidden" name="id" id="formArsipId">
</form>
<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
