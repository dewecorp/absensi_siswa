<?php
/**
 * Cetak / Ekspor PDF modul Supervisi (server-side, bisa reload).
 * Parameter: ?jenis=<kunci>&...filter
 */
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$jenis = trim((string)($_GET['page'] ?? 'pelaksanaan'));

$school_name = strtoupper((string)($school_profile['nama_madrasah'] ?? 'MADRASAH'));
$foundation_name = strtoupper((string)($school_profile['nama_yayasan'] ?? ''));
$school_address = (string)($school_profile['alamat'] ?? '');
$email = (string)($school_profile['email_madrasah'] ?? '');
$website = (string)($school_profile['website_madrasah'] ?? '');
$kepala_madrasah = (string)($school_profile['nama_kepala'] ?? $school_profile['kepala_madrasah'] ?? '-');
$nip_kepala = (string)($school_profile['nip_kepala'] ?? '-');
$logo_path = '../assets/img/' . basename((string)($school_profile['logo'] ?? 'logo.png'));
if (!is_readable(__DIR__ . '/' . $logo_path)) {
    $logo_path = '../assets/img/logo.png';
    if (!is_readable(__DIR__ . '/' . $logo_path)) {
        $cand = glob(__DIR__ . '/../assets/img/logo_*.png') ?: [];
        $logo_path = $cand ? '../assets/img/' . basename($cand[0]) : '';
    }
}
$tempat = (string)($school_profile['tempat_jadwal'] ?? 'Tempat');
$months = ['January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'];
$tanggal = $tempat . ', ' . date('d') . ' ' . $months[date('F')] . ' ' . date('Y');
$qr_content = "Ditandatangani secara elektronik oleh:\n" . $kepala_madrasah . "\nKepala Madrasah\nTanggal: " . date('d F Y');
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($qr_content);

$filter_ta = trim((string)($_GET['tahun_ajaran'] ?? $periode['tahun_ajaran']));
$filter_jenis = trim((string)($_GET['jenis_supervisi'] ?? $_GET['jenis'] ?? ''));
$filter_status = trim((string)($_GET['status'] ?? ''));
$filter_guru = trim((string)($_GET['guru'] ?? ''));
$filter_komponen = (int)($_GET['id_komponen'] ?? 0);
$filter_instrumen = (int)($_GET['id_instrumen'] ?? 0);

$title = 'Supervisi';
$headers = [];
$body = [];
$subtitle = '';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
    switch ($jenis) {
        case 'program':
            $title = 'Program Supervisi';
            $subtitle = 'Tahun Ajaran ' . $filter_ta;
            $headers = ['Kode', 'Tahun Ajaran', 'Semester', 'Jenis', 'Nama Program', 'Tujuan', 'Sasaran', 'Fokus', 'Target', 'Indikator', 'Waktu', 'Penanggung Jawab', 'Keterangan'];
            $stmt = $pdo->prepare("SELECT * FROM tb_sv_program WHERE tahun_ajaran = ? ORDER BY jenis_supervisi ASC, kode_program ASC, nama_program ASC");
            $stmt->execute([$filter_ta]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $body[] = [
                    h($r['kode_program']), h($r['tahun_ajaran']), h($r['semester']), h($r['jenis_supervisi']),
                    h($r['nama_program']), nl2br(h($r['tujuan'])), nl2br(h($r['sasaran'])), nl2br(h($r['fokus_supervisi'])),
                    h($r['target']), nl2br(h($r['indikator_keberhasilan'])),
                    h(sv_format_rentang($r['tanggal_mulai'] ?? null, $r['tanggal_selesai'] ?? null) ?: (string)($r['waktu_pelaksanaan'] ?? '')),
                    h($r['penanggung_jawab']), h($r['keterangan']),
                ];
            }
            break;

        case 'sasaran':
            $title = 'Sasaran Supervisi';
            $subtitle = 'Tahun Ajaran ' . $periode['tahun_ajaran'] . ' - ' . $periode['semester'];
            $headers = ['Guru/PTK', 'NUPTK', 'Jabatan', 'Mata Pelajaran', 'Kelas', 'Program', 'Jenis', 'Semester', 'Status', 'Supervisi Terakhir', 'Nilai Terakhir'];
            $rows = $pdo->query("SELECT s.*, p.nama_program,
                    (SELECT MAX(pk.tanggal) FROM tb_sv_pelaksanaan pk WHERE pk.id_guru = s.id_guru AND pk.status = 'Selesai') AS supervisi_terakhir,
                    (SELECT pk.nilai FROM tb_sv_pelaksanaan pk WHERE pk.id_guru = s.id_guru AND pk.status = 'Selesai' ORDER BY pk.tanggal DESC, pk.id_pelaksanaan DESC LIMIT 1) AS nilai_terakhir
                FROM tb_sv_sasaran s
                LEFT JOIN tb_sv_program p ON p.id_program = s.id_program
                ORDER BY s.tahun_ajaran DESC, s.nama_guru ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $body[] = [
                    h($r['nama_guru']), h($r['nip_npk']), h($r['jabatan']), h($r['mata_pelajaran']), h($r['kelas']),
                    h($r['nama_program']), h($r['jenis_supervisi']), h($r['semester']),
                    h($r['status_supervisi']),
                    $r['supervisi_terakhir'] ? date('d/m/Y', strtotime($r['supervisi_terakhir'])) : '-',
                    $r['nilai_terakhir'] !== null ? number_format((float)$r['nilai_terakhir'], 2) : '-',
                ];
            }
            break;

        case 'jadwal':
            $title = 'Jadwal Supervisi';
            $filter_sem = trim((string)($_GET['semester'] ?? $periode['semester']));
            $subtitle = 'Tahun Ajaran ' . $filter_ta . ($filter_sem !== '' ? ' - ' . $filter_sem : '');
            $where = [];
            $params = [];
            if ($filter_ta !== '' && isTahunAjaranFormatValid($filter_ta)) {
                $rta = getRentangTanggalTahunAjaran($filter_ta);
                if ($rta) { $where[] = 'j.tanggal BETWEEN ? AND ?'; $params[] = $rta['mulai']; $params[] = $rta['sampai']; }
            }
            if ($filter_jenis !== '') { $where[] = 'j.jenis_supervisi = ?'; $params[] = $filter_jenis; }
            if ($filter_status !== '') { $where[] = 'j.status = ?'; $params[] = $filter_status; }
            if ($filter_guru !== '') { $where[] = 'j.id_guru = ?'; $params[] = (int)$filter_guru; }
            $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
            $headers = ['Program', 'Guru/PTK', 'Jenis', 'Instrumen', 'Supervisor', 'Tanggal', 'Jam Mulai', 'Jam Selesai', 'Tempat', 'Status', 'Keterangan'];
            $stmt = $pdo->prepare("SELECT j.*, p.nama_program, i.nama_instrumen FROM tb_sv_jadwal j
                LEFT JOIN tb_sv_program p ON p.id_program = j.id_program
                LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = j.id_instrumen
                {$whereSql} ORDER BY j.tanggal DESC, j.jam_mulai ASC");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $body[] = [
                    h($r['nama_program']), h($r['nama_guru']), h($r['jenis_supervisi']), h($r['nama_instrumen']), h($r['supervisor']),
                    $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-',
                    $r['jam_mulai'] ? substr($r['jam_mulai'], 0, 5) : '-',
                    $r['jam_selesai'] ? substr($r['jam_selesai'], 0, 5) : '-',
                    h($r['tempat']), h($r['status']), h($r['keterangan']),
                ];
            }
            break;

        case 'instrumen':
            $title = 'Daftar Instrumen Supervisi';
            $headers = ['Kode', 'Nama Instrumen', 'Jenis', 'Tujuan', 'Sasaran', 'Skala', 'Komponen', 'Indikator', 'Status', 'Keterangan'];
            $rows = $pdo->query("SELECT i.*,
                (SELECT COUNT(*) FROM tb_sv_komponen k WHERE k.id_instrumen = i.id_instrumen) AS jml_komponen,
                (SELECT COUNT(*) FROM tb_sv_indikator n JOIN tb_sv_komponen k2 ON k2.id_komponen = n.id_komponen WHERE k2.id_instrumen = i.id_instrumen) AS jml_indikator
                FROM tb_sv_instrumen i ORDER BY i.jenis_supervisi ASC, i.nama_instrumen ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $body[] = [
                    h($r['kode_instrumen']), h($r['nama_instrumen']), h($r['jenis_supervisi']), h($r['tujuan']), h($r['sasaran']),
                    h($r['skala_penilaian']), (int)$r['jml_komponen'], (int)$r['jml_indikator'], h($r['status']), h($r['keterangan']),
                ];
            }
            break;

        case 'komponen':
            $title = 'Komponen Instrumen';
            $headers = ['Instrumen', 'Kode Komponen', 'Nama Komponen', 'Bobot', 'Urutan', 'Keterangan'];
            if ($filter_instrumen > 0) {
                $stmt = $pdo->prepare("SELECT k.*, i.kode_instrumen, i.nama_instrumen FROM tb_sv_komponen k JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen WHERE k.id_instrumen = ? ORDER BY k.urutan ASC, k.id_komponen ASC");
                $stmt->execute([$filter_instrumen]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = $pdo->query("SELECT k.*, i.kode_instrumen, i.nama_instrumen FROM tb_sv_komponen k JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen ORDER BY i.kode_instrumen ASC, k.urutan ASC, k.id_komponen ASC")->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($rows as $r) {
                $body[] = [
                    h($r['kode_instrumen']) . ' - ' . h($r['nama_instrumen']), h($r['kode_komponen']), h($r['nama_komponen']),
                    number_format((float)$r['bobot'], 2), (int)$r['urutan'], h($r['keterangan']),
                ];
            }
            break;

        case 'indikator':
            $title = 'Indikator Penilaian';
            $headers = ['Instrumen', 'Komponen', 'Kode', 'Indikator', 'Bobot', 'Skor Min', 'Skor Maks', 'Urutan'];
            if ($filter_komponen > 0) {
                $stmt = $pdo->prepare("SELECT n.*, k.nama_komponen, k.kode_komponen, i.kode_instrumen, i.nama_instrumen FROM tb_sv_indikator n JOIN tb_sv_komponen k ON k.id_komponen = n.id_komponen JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen WHERE n.id_komponen = ? ORDER BY n.urutan ASC, n.id_indikator ASC");
                $stmt->execute([$filter_komponen]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($filter_instrumen > 0) {
                $stmt = $pdo->prepare("SELECT n.*, k.nama_komponen, k.kode_komponen, i.kode_instrumen, i.nama_instrumen FROM tb_sv_indikator n JOIN tb_sv_komponen k ON k.id_komponen = n.id_komponen JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen WHERE k.id_instrumen = ? ORDER BY k.urutan ASC, n.urutan ASC, n.id_indikator ASC");
                $stmt->execute([$filter_instrumen]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = $pdo->query("SELECT n.*, k.nama_komponen, k.kode_komponen, i.kode_instrumen, i.nama_instrumen FROM tb_sv_indikator n JOIN tb_sv_komponen k ON k.id_komponen = n.id_komponen JOIN tb_sv_instrumen i ON i.id_instrumen = k.id_instrumen ORDER BY i.kode_instrumen ASC, k.urutan ASC, n.urutan ASC")->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($rows as $r) {
                $body[] = [
                    h($r['kode_instrumen']) . ' - ' . h($r['nama_instrumen']),
                    h($r['nama_komponen']) . ' (' . h($r['kode_komponen']) . ')',
                    h($r['kode_indikator']), h($r['indikator']), number_format((float)$r['bobot'], 2),
                    (int)$r['skor_minimal'], (int)$r['skor_maksimal'], (int)$r['urutan'],
                ];
            }
            break;

        case 'arsip':
            $title = 'Arsip / Bukti Supervisi';
            $headers = ['Guru yang Disupervisi', 'Jenis Dokumen', 'Nama Dokumen', 'File', 'Tautan Dokumen', 'Tanggal Upload', 'Pengunggah'];
            $rows = $pdo->query("SELECT a.*, p.nama_guru, p.unit_bagian, p.jenis_supervisi
                FROM tb_sv_arsip a LEFT JOIN tb_sv_pelaksanaan p ON p.id_pelaksanaan = a.id_pelaksanaan
                ORDER BY a.tanggal_upload DESC, a.id_arsip DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $namaSup = ($r['jenis_supervisi'] === 'Manajerial') ? ($r['unit_bagian'] ?: '-') : ($r['nama_guru'] ?: '-');
                $body[] = [
                    h($namaSup), h($r['jenis_dokumen']), h($r['nama_dokumen']),
                    h($r['file']), h($r['tautan_dokumen']),
                    $r['tanggal_upload'] ? date('d/m/Y H:i', strtotime($r['tanggal_upload'])) : '-',
                    h($r['pengunggah']),
                ];
            }
            break;

        case 'hasil':
        case 'laporan':
            $title = ($jenis === 'laporan') ? 'Laporan Supervisi' : 'Hasil Supervisi';
            if ($jenis === 'laporan') { $subtitle = 'Tahun Ajaran ' . $filter_ta; }
            $where = [];
            $params = [];
            if ($filter_ta !== '' && isTahunAjaranFormatValid($filter_ta)) {
                $rta = getRentangTanggalTahunAjaran($filter_ta);
                if ($rta) { $where[] = 'p.tanggal BETWEEN ? AND ?'; $params[] = $rta['mulai']; $params[] = $rta['sampai']; }
            }
            if ($filter_jenis !== '') { $where[] = 'p.jenis_supervisi = ?'; $params[] = $filter_jenis; }
            if ($filter_status !== '') { $where[] = 'p.status = ?'; $params[] = $filter_status; }
            if ($filter_guru !== '') { $where[] = 'p.id_guru = ?'; $params[] = (int)$filter_guru; }
            $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
            $headers = ['Guru', 'Mapel yang Disupervisi', 'Jenis', 'Tanggal', 'Supervisor', 'Nilai', 'Predikat', 'Kekuatan', 'Kelemahan', 'Rekomendasi', 'Prioritas', 'Keterangan'];
            $stmt = $pdo->prepare("SELECT p.*, i.nama_instrumen FROM tb_sv_pelaksanaan p
                LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen {$whereSql}
                ORDER BY p.tanggal DESC, p.id_pelaksanaan DESC");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nama = $r['nama_guru'] ?: '-';
                $body[] = [
                    h($nama), h($r['mapel_di_supervisi']), h($r['jenis_supervisi']),
                    $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-',
                    h($r['supervisor']),
                    $r['nilai'] !== null ? number_format((float)$r['nilai'], 2) : '-',
                    h($r['predikat']), h($r['kekuatan']), h($r['kelemahan']), h($r['rekomendasi']),
                    h($r['prioritas_perbaikan']), h($r['keterangan']),
                ];
            }
            break;

        case 'pelaksanaan':
        default:
            $title = 'Pelaksanaan Supervisi';
            $sv_jenis = trim((string)($_GET['sv_jenis'] ?? ''));
            $where = [];
            $params = [];
            if ($sv_jenis !== '' && in_array($sv_jenis, sv_jenis_list(), true)) { $where[] = 'p.jenis_supervisi = ?'; $params[] = $sv_jenis; }
            if ($filter_ta !== '' && isTahunAjaranFormatValid($filter_ta)) {
                $rta = getRentangTanggalTahunAjaran($filter_ta);
                if ($rta) { $where[] = 'p.tanggal BETWEEN ? AND ?'; $params[] = $rta['mulai']; $params[] = $rta['sampai']; }
            }
            if ($filter_status !== '') { $where[] = 'p.status = ?'; $params[] = $filter_status; }
            if ($filter_guru !== '') { $where[] = 'p.id_guru = ?'; $params[] = (int)$filter_guru; }
            $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
            $headers = ['Guru/Unit', 'Mapel yang Disupervisi', 'Program', 'Tanggal', 'Supervisor', 'Instrumen', 'Nilai', 'Predikat', 'Rekomendasi', 'Status'];
            $stmt = $pdo->prepare("SELECT p.*, pr.nama_program, i.nama_instrumen FROM tb_sv_pelaksanaan p
                LEFT JOIN tb_sv_program pr ON pr.id_program = p.id_program
                LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
                {$whereSql} ORDER BY p.tanggal DESC, p.id_pelaksanaan DESC");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nama = $r['jenis_supervisi'] === 'Manajerial' ? ($r['unit_bagian'] ?: '-') : ($r['nama_guru'] ?: '-');
                $body[] = [
                    h($nama), h($r['mapel_di_supervisi']), h($r['nama_program']),
                    $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-',
                    h($r['supervisor']), h($r['nama_instrumen']),
                    $r['nilai'] !== null ? number_format((float)$r['nilai'], 2) : '-',
                    h($r['predikat']), h($r['rekomendasi']), h($r['status']),
                ];
            }
            break;

        case 'tindak_lanjut':
            $title = 'Tindak Lanjut Supervisi';
            $headers = ['ID Supervisi', 'Guru/Unit', 'Rekomendasi', 'Bentuk', 'Rencana Tindakan', 'Penanggung Jawab', 'Target Selesai', 'Realisasi', 'Status', 'Tanggal Selesai', 'Catatan'];
            $where = [];
            $params = [];
            if ($filter_status !== '') { $where[] = 'status = ?'; $params[] = $filter_status; }
            $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
            $stmt = $pdo->prepare("SELECT * FROM tb_sv_tindak_lanjut {$whereSql} ORDER BY target_selesai ASC, id_tindak_lanjut DESC");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $body[] = [
                    '#' . (int)$r['id_pelaksanaan'], h($r['nama_guru'] ?: ($r['unit_bagian'] ?: '-')),
                    h($r['rekomendasi']), h($r['bentuk_tindak_lanjut']), h($r['rencana_tindakan']), h($r['penanggung_jawab']),
                    $r['target_selesai'] ? date('d/m/Y', strtotime($r['target_selesai'])) : '-',
                    $r['realisasi'] ? date('d/m/Y', strtotime($r['realisasi'])) : '-',
                    h($r['status']),
                    $r['tanggal_selesai'] ? date('d/m/Y', strtotime($r['tanggal_selesai'])) : '-',
                    h($r['catatan']),
                ];
            }
            break;

        case 'monitoring':
            $title = 'Monitoring Tindak Lanjut';
            $headers = ['Tindak Lanjut', 'Guru/Unit', 'Tindakan', 'Target Perbaikan', 'Tanggal', 'Ke', 'Hasil Monitoring', 'Perubahan', 'Nilai Sebelum', 'Nilai Sesudah', 'Status', 'Catatan', 'Bukti'];
            $rows = $pdo->query("SELECT m.*, t.bentuk_tindak_lanjut, t.status AS status_tl FROM tb_sv_monitoring m
                LEFT JOIN tb_sv_tindak_lanjut t ON t.id_tindak_lanjut = m.id_tindak_lanjut
                ORDER BY m.tanggal_monitoring DESC, m.id_monitoring DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $body[] = [
                    '#' . (int)$r['id_tindak_lanjut'] . ' - ' . h($r['bentuk_tindak_lanjut']),
                    h($r['nama_guru'] ?: ($r['unit_bagian'] ?: '-')), h($r['tindakan']), h($r['target_perbaikan']),
                    $r['tanggal_monitoring'] ? date('d/m/Y', strtotime($r['tanggal_monitoring'])) : '-',
                    (int)$r['monitoring_ke'], h($r['hasil_monitoring']), h($r['perubahan']),
                    $r['nilai_sebelum'] !== null ? number_format((float)$r['nilai_sebelum'], 2) : '-',
                    $r['nilai_sesudah'] !== null ? number_format((float)$r['nilai_sesudah'], 2) : '-',
                    h($r['status']), h($r['catatan']), h($r['bukti']),
                ];
            }
            break;

        case 'rekapitulasi':
            $title = 'Rekapitulasi Supervisi';
            $subtitle = 'Tahun Ajaran ' . $filter_ta;
            $headers = ['Kelompok', 'Jumlah Supervisi', 'Nilai Rata-rata'];
            $parts = explode('/', $filter_ta);
            $ta_start = ($parts[0] ?? date('Y')) . '-07-01';
            $ta_end = ($parts[1] ?? ((int)date('Y') + 1)) . '-06-30';
            $stmt = $pdo->prepare("SELECT p.*, i.nama_instrumen FROM tb_sv_pelaksanaan p
                LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
                WHERE p.tanggal BETWEEN ? AND ? ORDER BY p.nama_guru ASC, p.tanggal DESC");
            $stmt->execute([$ta_start, $ta_end]);
            $kelompok = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $key = $r['nama_guru'] ?: ($r['unit_bagian'] ?: '-');
                if (!isset($kelompok[$key])) { $kelompok[$key] = ['jumlah' => 0, 'sum' => 0.0, 'n' => 0]; }
                $kelompok[$key]['jumlah']++;
                if ($r['nilai'] !== null) { $kelompok[$key]['sum'] += (float)$r['nilai']; $kelompok[$key]['n']++; }
            }
            uasort($kelompok, function ($a, $b) { return $b['jumlah'] <=> $a['jumlah']; });
            foreach ($kelompok as $label => $k) {
                $body[] = [h($label), (int)$k['jumlah'], $k['n'] > 0 ? number_format($k['sum'] / $k['n'], 2) : '-'];
            }
            break;
    }
} catch (Throwable $e) {
    $body = [];
}

$total_cols = count($headers) + 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Cetak <?= h($title) ?></title>
<style>
@page { size: 330mm 215mm; margin: 8mm 10mm; } /* F4 Landscape */
@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }
body { font-family: Arial, sans-serif; font-size: 10pt; margin: 15px; line-height: 1.45; }
.header { display: flex; align-items: center; justify-content: center; position: relative; padding-bottom: 6px; border-bottom: 2px solid #000; margin-bottom: 12px; }
.header img { position: absolute; left: 0; top: 0; height: 60px; }
.header-text { text-align: center; width: 100%; }
.header-text h2 { margin: 0; font-size: 14px; }
.header-text h1 { margin: 0; font-size: 16px; }
.header-text p { margin: 1px 0; font-size: 10px; }
.title { text-align: center; font-weight: bold; font-size: 13px; text-decoration: underline; margin: 8px 0 4px; }
.subtitle { text-align: center; margin: 0 0 8px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
th, td { border: 1px solid #000; padding: 3px 4px; vertical-align: top; font-size: 9pt; }
th { background: #f0f0f0; text-align: center; font-weight: bold; }
.text-center { text-align: center; }
.bold { font-weight: bold; }
.ttd-box { margin-top: 18px; display: flex; justify-content: flex-end; page-break-inside: avoid; }
.ttd-item { text-align: center; width: 280px; }
.ttd-space { height: 65px; }
.small { font-size: 7px; color: #555; }
.print-btn { position: fixed; top: 16px; right: 16px; padding: 8px 16px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; z-index: 9999; }
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">Cetak / Simpan PDF</button>

<div class="header">
    <?php if ($logo_path): ?><img src="<?= h($logo_path) ?>" alt="Logo"><?php endif; ?>
    <div class="header-text">
        <?php if ($foundation_name !== ''): ?><h2><?= h($foundation_name) ?></h2><?php endif; ?>
        <h1><?= h($school_name) ?></h1>
        <?php if ($school_address !== ''): ?><p><?= h($school_address) ?></p><?php endif; ?>
        <?php if ($email !== '' || $website !== ''): ?><p><?= $email !== '' ? 'Email: ' . h($email) : '' ?><?= ($email !== '' && $website !== '') ? ' | ' : '' ?><?= $website !== '' ? 'Website: ' . h($website) : '' ?></p><?php endif; ?>
    </div>
</div>

<div class="title"><?= h(strtoupper($title)) ?></div>
<?php if ($subtitle !== ''): ?><p class="subtitle"><?= h($subtitle) ?></p><?php endif; ?>

<table>
    <thead>
        <tr>
            <th width="3%">No</th>
            <?php foreach ($headers as $hd): ?><th><?= h($hd) ?></th><?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php if ($body): $no = 1; foreach ($body as $cells): ?>
        <tr>
            <td class="text-center"><?= $no++ ?></td>
            <?php foreach ($cells as $cell): ?><td><?= $cell ?></td><?php endforeach; ?>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="<?= $total_cols ?>" class="text-center">Tidak ada data</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<p class="small">Total data: <?= count($body) ?> | Dicetak: <?= date('d-m-Y H:i') ?></p>

<div class="ttd-box">
    <div class="ttd-item">
        <p style="margin-bottom:4px"><?= h($tanggal) ?></p>
        <p>Kepala Madrasah,</p>
        <div class="ttd-space"><img src="<?= h($qr_url) ?>" alt="QR" style="height:65px"></div>
        <p class="bold" style="margin-bottom:0"><?= h($kepala_madrasah) ?></p>
        <?php if ($nip_kepala !== '' && $nip_kepala !== '-'): ?><p style="margin-top:2px">NIP. <?= h($nip_kepala) ?></p><?php endif; ?>
    </div>
</div>
</body>
</html>
