<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/rkt_helper.php';

if (session_status() == PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function rktJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isAuthorized(['admin', 'kepala_madrasah'])) {
    rktJson(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk aksi ini.'], 403);
}

$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!appVerifyCsrfToken($token)) {
    rktJson(['success' => false, 'message' => 'Sesi keamanan tidak valid. Muat ulang halaman.'], 419);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rktJson(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

rktEnsureSchema($pdo);

$action = (string)($_POST['action'] ?? '');
$docId = (int)($_POST['document_id'] ?? 0);
$userId = (string)($_SESSION['username'] ?? 'system');

function rktDocOrFail(PDO $pdo, int $docId): array
{
    $doc = rktGetDocument($pdo, $docId);
    if (!$doc) {
        rktJson(['success' => false, 'message' => 'Dokumen RKT tidak ditemukan.'], 404);
    }
    return $doc;
}

function rktIntList($value): array
{
    $ids = is_array($value) ? $value : explode(',', (string)$value);
    $out = [];
    foreach ($ids as $v) {
        $n = (int)$v;
        if ($n > 0) $out[] = $n;
    }
    return array_values(array_unique($out));
}

function rktValidDate(string $value): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d instanceof DateTime && $d->format('Y-m-d') === $value;
}

function rktDateField(array $post, string $key): ?string
{
    $raw = trim((string)($post[$key] ?? ''));
    if ($raw === '') return null;
    if (!rktValidDate($raw)) {
        rktJson(['success' => false, 'message' => 'Format tanggal ' . $key . ' tidak valid (YYYY-MM-DD).']);
    }
    return $raw;
}

function rktMoney($value): float
{
    $raw = trim((string)$value);
    if ($raw === '') return 0.0;
    $raw = str_replace(',', '.', $raw);
    $raw = preg_replace('/[^0-9.]/', '', $raw);
    if (substr_count($raw, '.') > 1) {
        $raw = str_replace('.', '', $raw);
    }
    return round(max(0, (float)$raw), 2);
}

function rktFieldData(PDO $pdo, int $fieldId): array
{
    if ($fieldId <= 0) return [null, null];
    $stmt = $pdo->prepare("SELECT id, nama FROM rkjm_fields WHERE id = ? LIMIT 1");
    $stmt->execute([$fieldId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? [(int)$row['id'], (string)$row['nama']] : [null, null];
}

try {
    switch ($action) {

        case 'save_document': {
            $id = (int)($_POST['id'] ?? 0);
            $tahun = (int)($_POST['tahun'] ?? 0);
            if ($tahun < 1901 || $tahun > 2155) {
                rktJson(['success' => false, 'message' => 'Tahun pelaksanaan tidak valid.']);
            }

            $school = getSchoolProfile($pdo);
            $namaMadrasah = trim((string)($school['nama_madrasah'] ?? ''));
            if ($namaMadrasah === '') {
                $namaMadrasah = trim((string)($_POST['nama_madrasah'] ?? ''));
            }
            if ($namaMadrasah === '') {
                rktJson(['success' => false, 'message' => 'Nama madrasah wajib diisi.']);
            }

            $rkjmDocId = (int)($_POST['rkjm_document_id'] ?? 0) ?: null;
            $rkjmDoc = null;
            if ($rkjmDocId) {
                $rkjmDoc = rkjmGetDocument($pdo, $rkjmDocId);
                if (!$rkjmDoc) {
                    $rkjmDocId = null;
                }
            }

            $statusOpts = rktDocStatusOptions();
            $status = (string)($_POST['status'] ?? 'draf');
            if (!isset($statusOpts[$status])) $status = 'draf';

            // Visi, misi, tujuan, dan dasar penyusunan selalu mengikuti Pengaturan RKJM
            // bila dokumen RKT terhubung ke RKJM, supaya tidak ada duplikasi data.
            $visi = trim((string)($_POST['visi'] ?? ''));
            $misi = trim((string)($_POST['misi'] ?? ''));
            $tujuan = trim((string)($_POST['tujuan_pengembangan'] ?? ''));
            $dasarPenyusunan = trim((string)($_POST['dasar_penyusunan'] ?? ''));
            if ($rkjmDoc) {
                $visi = richTextToPlain($rkjmDoc['visi'] ?? '');
                $misi = richTextToPlain($rkjmDoc['misi'] ?? '');
                $tujuan = richTextToPlain($rkjmDoc['tujuan_pengembangan'] ?? '');
                $dasarPenyusunan = richTextToPlain($rkjmDoc['dasar_penyusunan'] ?? '');
            }

            $namaKepala = trim((string)($school['nama_kepala'] ?? ''));
            if ($namaKepala === '') $namaKepala = trim((string)($school['kepala_madrasah'] ?? ''));
            if ($namaKepala === '') $namaKepala = trim((string)($_POST['nama_kepala'] ?? ''));

            $tanggal = rktDateField($_POST, 'tanggal_penyusunan');

            $data = [
                'nama_madrasah' => $namaMadrasah,
                'nomor_dokumen' => trim((string)($_POST['nomor_dokumen'] ?? '')),
                'tahun' => $tahun,
                'rkjm_document_id' => $rkjmDocId,
                'visi' => $visi,
                'misi' => $misi,
                'tujuan_pengembangan' => $tujuan,
                'prioritas' => trim((string)($_POST['prioritas'] ?? '')),
                'dasar_penyusunan' => $dasarPenyusunan,
                'tanggal_penyusunan' => $tanggal,
                'nama_kepala' => $namaKepala,
                'status' => $status,
                'catatan' => trim((string)($_POST['catatan'] ?? '')),
            ];

            $dup = $pdo->prepare("SELECT id FROM rkt_documents WHERE tahun = ? AND nama_madrasah = ? AND id <> ? LIMIT 1");
            $dup->execute([$tahun, $namaMadrasah, $id]);
            if ($dup->fetchColumn()) {
                rktJson(['success' => false, 'message' => 'RKT untuk tahun ' . $tahun . ' dan madrasah ini sudah ada. Buka dokumen tersebut atau gunakan tahun lain.']);
            }

            if ($id > 0) {
                $doc = rktDocOrFail($pdo, $id);
                $sets = [];
                $params = [];
                foreach ($data as $k => $v) {
                    $sets[] = "`$k` = ?";
                    $params[] = $v;
                }
                $params[] = $id;
                $pdo->prepare("UPDATE rkt_documents SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                logActivity($pdo, $userId, 'Update RKT', 'Dokumen RKT #' . $id . ' tahun ' . $tahun);
                rktJson(['success' => true, 'message' => 'Dokumen RKT diperbarui.', 'document_id' => $id]);
            }

            $cols = array_keys($data);
            $cols[] = 'created_by';
            $params = array_values($data);
            $params[] = $userId;
            $place = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO rkt_documents (`" . implode('`,`', $cols) . "`) VALUES ($place)")->execute($params);
            $newId = (int)$pdo->lastInsertId();
            logActivity($pdo, $userId, 'Tambah RKT', 'Dokumen RKT #' . $newId . ' tahun ' . $tahun);
            rktJson(['success' => true, 'message' => 'Dokumen RKT dibuat.', 'document_id' => $newId]);
        }

        case 'save_program': {
            $doc = rktDocOrFail($pdo, $docId);
            $id = (int)($_POST['id'] ?? 0);
            $nama = trim((string)($_POST['nama_program'] ?? ''));
            if ($nama === '') {
                rktJson(['success' => false, 'message' => 'Nama program wajib diisi.']);
            }

            [$fieldId, $bidangNama] = rktFieldData($pdo, (int)($_POST['field_id'] ?? 0));
            $statusOpts = rktProgramStatusOptions();
            $status = (string)($_POST['status'] ?? 'belum_dimulai');
            if (!isset($statusOpts[$status])) $status = 'belum_dimulai';

            $mulai = rktDateField($_POST, 'jadwal_mulai');
            $selesai = rktDateField($_POST, 'jadwal_selesai');
            if ($mulai && $selesai && $selesai < $mulai) {
                rktJson(['success' => false, 'message' => 'Jadwal selesai tidak boleh lebih awal dari jadwal mulai.']);
            }

            $dup = $pdo->prepare("SELECT id FROM rkt_programs WHERE document_id = ? AND LOWER(nama_program) = LOWER(?) AND id <> ? LIMIT 1");
            $dup->execute([$docId, $nama, $id]);
            if ($dup->fetchColumn()) {
                rktJson(['success' => false, 'message' => 'Program dengan nama tersebut sudah ada pada RKT ini.']);
            }

            $data = [
                'field_id' => $fieldId,
                'bidang_nama' => $bidangNama,
                'kode_program' => trim((string)($_POST['kode_program'] ?? '')),
                'nama_program' => $nama,
                'kondisi_awal' => trim((string)($_POST['kondisi_awal'] ?? '')),
                'tujuan' => trim((string)($_POST['tujuan'] ?? '')),
                'sasaran' => trim((string)($_POST['sasaran'] ?? '')),
                'indikator' => trim((string)($_POST['indikator'] ?? '')),
                'target_tahunan' => trim((string)($_POST['target_tahunan'] ?? '')),
                'jadwal_mulai' => $mulai,
                'jadwal_selesai' => $selesai,
                'penanggung_jawab' => trim((string)($_POST['penanggung_jawab'] ?? '')),
                'estimasi_anggaran' => rktMoney($_POST['estimasi_anggaran'] ?? 0),
                'sumber_dana' => trim((string)($_POST['sumber_dana'] ?? '')),
                'output' => trim((string)($_POST['output'] ?? '')),
                'monitoring_evaluasi' => trim((string)($_POST['monitoring_evaluasi'] ?? '')),
                'catatan' => trim((string)($_POST['catatan'] ?? '')),
                'status' => $status,
                'urutan' => (int)($_POST['urutan'] ?? 0),
            ];

            if ($id > 0) {
                $own = $pdo->prepare("SELECT id, rkjm_program_id FROM rkt_programs WHERE id = ? AND document_id = ?");
                $own->execute([$id, $docId]);
                $existing = $own->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    rktJson(['success' => false, 'message' => 'Program tidak ditemukan pada dokumen ini.'], 404);
                }
                $sets = [];
                $params = [];
                foreach ($data as $k => $v) {
                    $sets[] = "`$k` = ?";
                    $params[] = $v;
                }
                $params[] = $id;
                $pdo->prepare("UPDATE rkt_programs SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                $msg = 'Program RKT diperbarui.';
            } else {
                $cols = array_keys($data);
                $cols[] = 'document_id';
                $params = array_values($data);
                $params[] = $docId;
                $place = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO rkt_programs (`" . implode('`,`', $cols) . "`) VALUES ($place)")->execute($params);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Program RKT ditambahkan.';
            }

            $warning = '';
            $rkjmPid = (int)($existing['rkjm_program_id'] ?? 0);
            if ($rkjmPid > 0) {
                $rkjmTarget = rktRkjmTarget($pdo, $rkjmPid, (int)$doc['tahun']);
                if (rktTargetMismatch($data['target_tahunan'], $rkjmTarget)) {
                    $warning = 'Target RKT berbeda dari target tahunan RKJM (' . $rkjmTarget . '). Tinjau kembali jika perbedaan tidak disengaja.';
                }
            }

            logActivity($pdo, $userId, 'Simpan Program RKT', $nama);
            rktJson(['success' => true, 'message' => $msg, 'id' => $id, 'warning' => $warning]);
        }

        case 'get_program': {
            $doc = rktDocOrFail($pdo, $docId);
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM rkt_programs WHERE id = ? AND document_id = ?");
            $stmt->execute([$id, $docId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) rktJson(['success' => false, 'message' => 'Program tidak ditemukan.'], 404);

            $labels = rktProgramStatusOptions();
            $row['status_label'] = $labels[$row['status']] ?? $row['status'];

            $acts = $pdo->prepare("SELECT * FROM rkt_activities WHERE program_id = ? ORDER BY urutan ASC, id ASC");
            $acts->execute([$id]);
            $activities = $acts->fetchAll(PDO::FETCH_ASSOC);
            foreach ($activities as &$a) {
                $a['status_label'] = $labels[$a['status']] ?? $a['status'];
            }
            unset($a);
            $row['activities'] = $activities;

            $mon = $pdo->prepare("SELECT m.*, a.nama_kegiatan FROM rkt_monitoring m
                LEFT JOIN rkt_activities a ON a.id = m.activity_id
                WHERE m.program_id = ? ORDER BY m.tanggal_pelaksanaan DESC, m.id DESC");
            $mon->execute([$id]);
            $monitoring = $mon->fetchAll(PDO::FETCH_ASSOC);
            foreach ($monitoring as &$m) {
                $m['status_label'] = $labels[$m['status_pelaksanaan']] ?? $m['status_pelaksanaan'];
            }
            unset($m);
            $row['monitoring'] = $monitoring;

            $row['rkjm_target'] = $row['rkjm_program_id']
                ? rktRkjmTarget($pdo, (int)$row['rkjm_program_id'], (int)$doc['tahun'])
                : null;
            $row['target_mismatch'] = rktTargetMismatch($row['target_tahunan'] ?? '', $row['rkjm_target']);
            rktJson(['success' => true, 'data' => $row]);
        }

        case 'delete_program': {
            $id = (int)($_POST['id'] ?? 0);
            $check = $pdo->prepare("SELECT nama_program FROM rkt_programs WHERE id = ? AND document_id = ?");
            $check->execute([$id, $docId]);
            $nama = $check->fetchColumn();
            if (!$nama) rktJson(['success' => false, 'message' => 'Program tidak ditemukan.'], 404);

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM rkt_monitoring WHERE program_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM rkt_activities WHERE program_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM rkt_programs WHERE id = ? AND document_id = ?")->execute([$id, $docId]);
            $pdo->commit();
            logActivity($pdo, $userId, 'Hapus Program RKT', (string)$nama);
            rktJson(['success' => true, 'message' => 'Program beserta kegiatan dan monitoringnya dihapus.']);
        }

        case 'duplicate_program': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM rkt_programs WHERE id = ? AND document_id = ?");
            $stmt->execute([$id, $docId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) rktJson(['success' => false, 'message' => 'Program tidak ditemukan.'], 404);

            $urutan = (int)$pdo->query("SELECT COALESCE(MAX(urutan),0) FROM rkt_programs WHERE document_id = " . (int)$docId)->fetchColumn() + 1;

            $pdo->beginTransaction();
            $newRow = $row;
            unset($newRow['id'], $newRow['created_at'], $newRow['updated_at']);
            $newRow['nama_program'] = $row['nama_program'] . ' (Salinan)';
            $newRow['status'] = 'belum_dimulai';
            $newRow['urutan'] = $urutan;
            $cols = array_keys($newRow);
            $place = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO rkt_programs (`" . implode('`,`', $cols) . "`) VALUES ($place)")->execute(array_values($newRow));
            $newId = (int)$pdo->lastInsertId();

            $acts = $pdo->prepare("SELECT * FROM rkt_activities WHERE program_id = ? ORDER BY urutan ASC, id ASC");
            $acts->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO rkt_activities
                (program_id, template_activity_id, nama_kegiatan, tujuan, indikator, target_volume, satuan,
                 jadwal_mulai, jadwal_selesai, penanggung_jawab, estimasi_biaya, sumber_dana, output, status, urutan)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($acts->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $ins->execute([
                    $newId, $a['template_activity_id'], $a['nama_kegiatan'], $a['tujuan'], $a['indikator'],
                    $a['target_volume'], $a['satuan'], $a['jadwal_mulai'], $a['jadwal_selesai'],
                    $a['penanggung_jawab'], $a['estimasi_biaya'], $a['sumber_dana'], $a['output'],
                    'belum_dimulai', $a['urutan'],
                ]);
            }
            $pdo->commit();
            logActivity($pdo, $userId, 'Duplikasi Program RKT', (string)$row['nama_program']);
            rktJson(['success' => true, 'message' => 'Program diduplikasi beserta kegiatannya.', 'id' => $newId]);
        }

        case 'update_status': {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            $opts = rktProgramStatusOptions();
            if (!isset($opts[$status])) rktJson(['success' => false, 'message' => 'Status tidak valid.']);
            $stmt = $pdo->prepare("UPDATE rkt_programs SET status = ? WHERE id = ? AND document_id = ?");
            $stmt->execute([$status, $id, $docId]);
            if ($stmt->rowCount() === 0) rktJson(['success' => false, 'message' => 'Program tidak ditemukan.']);
            logActivity($pdo, $userId, 'Update Status Program RKT', 'Program #' . $id . ' => ' . $status);
            rktJson(['success' => true, 'message' => 'Status program diperbarui.']);
        }

        case 'save_activity': {
            $doc = rktDocOrFail($pdo, $docId);
            $programId = (int)($_POST['program_id'] ?? 0);
            $check = $pdo->prepare("SELECT id, nama_program FROM rkt_programs WHERE id = ? AND document_id = ?");
            $check->execute([$programId, $docId]);
            $program = $check->fetch(PDO::FETCH_ASSOC);
            if (!$program) rktJson(['success' => false, 'message' => 'Program induk tidak ditemukan.'], 404);

            $id = (int)($_POST['id'] ?? 0);
            $nama = trim((string)($_POST['nama_kegiatan'] ?? ''));
            if ($nama === '') rktJson(['success' => false, 'message' => 'Nama kegiatan wajib diisi.']);

            $mulai = rktDateField($_POST, 'jadwal_mulai');
            $selesai = rktDateField($_POST, 'jadwal_selesai');
            if ($mulai && $selesai && $selesai < $mulai) {
                rktJson(['success' => false, 'message' => 'Jadwal selesai kegiatan tidak boleh lebih awal dari jadwal mulai.']);
            }

            $statusOpts = rktProgramStatusOptions();
            $status = (string)($_POST['status'] ?? 'belum_dimulai');
            if (!isset($statusOpts[$status])) $status = 'belum_dimulai';

            $data = [
                'nama_kegiatan' => $nama,
                'tujuan' => trim((string)($_POST['tujuan'] ?? '')),
                'indikator' => trim((string)($_POST['indikator'] ?? '')),
                'target_volume' => trim((string)($_POST['target_volume'] ?? '')),
                'satuan' => trim((string)($_POST['satuan'] ?? '')),
                'jadwal_mulai' => $mulai,
                'jadwal_selesai' => $selesai,
                'penanggung_jawab' => trim((string)($_POST['penanggung_jawab'] ?? '')),
                'estimasi_biaya' => rktMoney($_POST['estimasi_biaya'] ?? 0),
                'sumber_dana' => trim((string)($_POST['sumber_dana'] ?? '')),
                'output' => trim((string)($_POST['output'] ?? '')),
                'status' => $status,
                'urutan' => (int)($_POST['urutan'] ?? 0),
            ];

            $dup = $pdo->prepare("SELECT id FROM rkt_activities WHERE program_id = ? AND LOWER(nama_kegiatan) = LOWER(?) AND id <> ? LIMIT 1");
            $dup->execute([$programId, $nama, $id]);
            if ($dup->fetchColumn()) {
                rktJson(['success' => false, 'message' => 'Kegiatan dengan nama tersebut sudah ada pada program ini.']);
            }

            if ($id > 0) {
                $own = $pdo->prepare("SELECT id FROM rkt_activities WHERE id = ? AND program_id = ?");
                $own->execute([$id, $programId]);
                if (!$own->fetchColumn()) {
                    rktJson(['success' => false, 'message' => 'Kegiatan tidak ditemukan pada program ini.'], 404);
                }
                $sets = [];
                $params = [];
                foreach ($data as $k => $v) { $sets[] = "`$k` = ?"; $params[] = $v; }
                $params[] = $id;
                $pdo->prepare("UPDATE rkt_activities SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                $msg = 'Kegiatan diperbarui.';
            } else {
                $cols = array_keys($data);
                $cols[] = 'program_id';
                $params = array_values($data);
                $params[] = $programId;
                $place = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO rkt_activities (`" . implode('`,`', $cols) . "`) VALUES ($place)")->execute($params);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Kegiatan ditambahkan.';
            }

            logActivity($pdo, $userId, 'Simpan Kegiatan RKT', $program['nama_program'] . ' - ' . $nama);
            rktJson(['success' => true, 'message' => $msg, 'id' => $id]);
        }

        case 'get_activity': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT a.* FROM rkt_activities a INNER JOIN rkt_programs p ON p.id = a.program_id
                WHERE a.id = ? AND p.document_id = ?");
            $stmt->execute([$id, $docId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) rktJson(['success' => false, 'message' => 'Kegiatan tidak ditemukan.'], 404);
            $labels = rktProgramStatusOptions();
            $row['status_label'] = $labels[$row['status']] ?? $row['status'];
            rktJson(['success' => true, 'data' => $row]);
        }

        case 'delete_activity': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT a.nama_kegiatan FROM rkt_activities a INNER JOIN rkt_programs p ON p.id = a.program_id
                WHERE a.id = ? AND p.document_id = ?");
            $stmt->execute([$id, $docId]);
            $nama = $stmt->fetchColumn();
            if (!$nama) rktJson(['success' => false, 'message' => 'Kegiatan tidak ditemukan.'], 404);
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM rkt_monitoring WHERE activity_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM rkt_activities WHERE id = ?")->execute([$id]);
            $pdo->commit();
            logActivity($pdo, $userId, 'Hapus Kegiatan RKT', (string)$nama);
            rktJson(['success' => true, 'message' => 'Kegiatan beserta monitoringnya dihapus.']);
        }

        case 'save_monitoring': {
            $doc = rktDocOrFail($pdo, $docId);
            $programId = (int)($_POST['program_id'] ?? 0);
            $activityId = (int)($_POST['activity_id'] ?? 0) ?: null;

            $check = $pdo->prepare("SELECT id, nama_program FROM rkt_programs WHERE id = ? AND document_id = ?");
            $check->execute([$programId, $docId]);
            $program = $check->fetch(PDO::FETCH_ASSOC);
            if (!$program) rktJson(['success' => false, 'message' => 'Program tidak ditemukan.'], 404);

            if ($activityId) {
                $own = $pdo->prepare("SELECT id FROM rkt_activities WHERE id = ? AND program_id = ?");
                $own->execute([$activityId, $programId]);
                if (!$own->fetchColumn()) {
                    rktJson(['success' => false, 'message' => 'Kegiatan tidak sesuai dengan program.'], 404);
                }
            }

            $statusOpts = rktProgramStatusOptions();
            $status = (string)($_POST['status_pelaksanaan'] ?? 'belum_dimulai');
            if (!isset($statusOpts[$status])) $status = 'belum_dimulai';

            $pctRaw = trim((string)($_POST['persentase_capaian'] ?? ''));
            $pct = null;
            if ($pctRaw !== '') {
                if (!is_numeric($pctRaw)) {
                    rktJson(['success' => false, 'message' => 'Persentase capaian harus berupa angka 0-100.']);
                }
                $pct = max(0, min(100, (float)$pctRaw));
            }

            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'program_id' => $programId,
                'activity_id' => $activityId,
                'tanggal_pelaksanaan' => rktDateField($_POST, 'tanggal_pelaksanaan'),
                'target' => trim((string)($_POST['target'] ?? '')),
                'realisasi' => trim((string)($_POST['realisasi'] ?? '')),
                'persentase_capaian' => $pct,
                'penilaian_kualitatif' => trim((string)($_POST['penilaian_kualitatif'] ?? '')) ?: null,
                'status_pelaksanaan' => $status,
                'kendala' => trim((string)($_POST['kendala'] ?? '')),
                'tindak_lanjut' => trim((string)($_POST['tindak_lanjut'] ?? '')),
                'output_aktual' => trim((string)($_POST['output_aktual'] ?? '')),
                'bukti' => trim((string)($_POST['bukti'] ?? '')),
                'catatan_evaluasi' => trim((string)($_POST['catatan_evaluasi'] ?? '')),
                'petugas_evaluasi' => trim((string)($_POST['petugas_evaluasi'] ?? '')),
                'realisasi_biaya' => trim((string)($_POST['realisasi_biaya'] ?? '')) === '' ? null : rktMoney($_POST['realisasi_biaya']),
            ];

            $pdo->beginTransaction();
            if ($id > 0) {
                $own = $pdo->prepare("SELECT id FROM rkt_monitoring WHERE id = ? AND program_id = ?");
                $own->execute([$id, $programId]);
                if (!$own->fetchColumn()) {
                    $pdo->rollBack();
                    rktJson(['success' => false, 'message' => 'Catatan monitoring tidak ditemukan.'], 404);
                }
                $sets = [];
                $params = [];
                foreach ($data as $k => $v) { $sets[] = "`$k` = ?"; $params[] = $v; }
                $params[] = $id;
                $pdo->prepare("UPDATE rkt_monitoring SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            } else {
                $cols = array_keys($data);
                $place = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO rkt_monitoring (`" . implode('`,`', $cols) . "`) VALUES ($place)")->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
            }

            if ($activityId) {
                $pdo->prepare("UPDATE rkt_activities SET status = ? WHERE id = ?")->execute([$status, $activityId]);
            } else {
                $pdo->prepare("UPDATE rkt_programs SET status = ? WHERE id = ?")->execute([$status, $programId]);
            }
            $pdo->commit();

            logActivity($pdo, $userId, 'Simpan Monitoring RKT', $program['nama_program']);
            rktJson(['success' => true, 'message' => 'Monitoring dan evaluasi disimpan.', 'id' => $id]);
        }

        case 'delete_monitoring': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE m FROM rkt_monitoring m INNER JOIN rkt_programs p ON p.id = m.program_id
                WHERE m.id = ? AND p.document_id = ?");
            $stmt->execute([$id, $docId]);
            if ($stmt->rowCount() === 0) rktJson(['success' => false, 'message' => 'Catatan monitoring tidak ditemukan.'], 404);
            logActivity($pdo, $userId, 'Hapus Monitoring RKT', 'Monitoring #' . $id);
            rktJson(['success' => true, 'message' => 'Catatan monitoring dihapus.']);
        }

        case 'rkjm_programs': {
            $doc = rktDocOrFail($pdo, $docId);
            $tahun = (int)$doc['tahun'];
            $rkjmDocId = (int)($_POST['rkjm_document_id'] ?? 0);
            if ($rkjmDocId <= 0) {
                $found = rktFindRkjmDocForYear($pdo, $tahun);
                $rkjmDocId = $found ? (int)$found['id'] : 0;
            }
            if ($rkjmDocId <= 0) {
                rktJson(['success' => true, 'data' => [], 'source' => null, 'message' => 'Belum ada dokumen RKJM yang mencakup tahun ' . $tahun . '.']);
            }
            $src = rkjmGetDocument($pdo, $rkjmDocId);
            if (!$src) {
                rktJson(['success' => false, 'message' => 'Dokumen RKJM tidak ditemukan.'], 404);
            }

            $stmt = $pdo->prepare("SELECT p.*, t.target AS target_tahunan, t.tahun_kalender
                FROM rkjm_programs p
                INNER JOIN rkjm_program_targets t ON t.program_id = p.id AND t.tahun_kalender = ?
                WHERE p.document_id = ? AND COALESCE(t.target,'') <> ''
                ORDER BY p.bidang_nama ASC, p.urutan ASC, p.id ASC");
            $stmt->execute([$tahun, $rkjmDocId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $importedIds = [];
            $importedNames = [];
            $imp = $pdo->prepare("SELECT rkjm_program_id, LOWER(nama_program) AS nama FROM rkt_programs WHERE document_id = ?");
            $imp->execute([$docId]);
            foreach ($imp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['rkjm_program_id'])) $importedIds[(int)$r['rkjm_program_id']] = true;
                $importedNames[$r['nama']] = true;
            }
            foreach ($rows as &$r) {
                $r['imported'] = isset($importedIds[(int)$r['id']]) || isset($importedNames[mb_strtolower(trim((string)$r['nama_program']))]);
            }
            unset($r);

            rktJson([
                'success' => true,
                'data' => $rows,
                'source' => [
                    'id' => (int)$src['id'],
                    'tahun_awal' => (int)$src['tahun_awal'],
                    'tahun_akhir' => (int)$src['tahun_akhir'],
                    'nomor_dokumen' => (string)($src['nomor_dokumen'] ?? ''),
                ],
                'tahun' => $tahun,
            ]);
        }

        case 'apply_rkjm': {
            $doc = rktDocOrFail($pdo, $docId);
            $tahun = (int)$doc['tahun'];
            $rkjmDocId = (int)($_POST['rkjm_document_id'] ?? 0);
            $ids = rktIntList($_POST['rkjm_program_ids'] ?? []);
            if (!$ids) rktJson(['success' => false, 'message' => 'Pilih minimal satu program RKJM.']);
            $src = rkjmGetDocument($pdo, $rkjmDocId);
            if (!$src) rktJson(['success' => false, 'message' => 'Dokumen RKJM sumber tidak ditemukan.'], 404);
            if ($tahun < (int)$src['tahun_awal'] || $tahun > (int)$src['tahun_akhir']) {
                rktJson(['success' => false, 'message' => 'Tahun RKT berada di luar periode RKJM yang dipilih.']);
            }

            $in = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$tahun, $rkjmDocId], $ids);
            $stmt = $pdo->prepare("SELECT p.*, t.target AS target_tahunan
                FROM rkjm_programs p
                INNER JOIN rkjm_program_targets t ON t.program_id = p.id AND t.tahun_kalender = ?
                WHERE p.document_id = ? AND p.id IN ($in) AND COALESCE(t.target,'') <> ''
                ORDER BY p.bidang_nama ASC, p.urutan ASC, p.id ASC");
            $stmt->execute($params);
            $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$sources) rktJson(['success' => false, 'message' => 'Program RKJM tidak ditemukan atau tidak memiliki target pada tahun tersebut.']);

            $existing = $pdo->prepare("SELECT rkjm_program_id, LOWER(nama_program) AS nama FROM rkt_programs WHERE document_id = ?");
            $existing->execute([$docId]);
            $haveIds = [];
            $haveNames = [];
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $e) {
                if (!empty($e['rkjm_program_id'])) $haveIds[(int)$e['rkjm_program_id']] = true;
                $haveNames[$e['nama']] = true;
            }

            $urutan = (int)$pdo->query("SELECT COALESCE(MAX(urutan),0) FROM rkt_programs WHERE document_id = " . (int)$docId)->fetchColumn();
            $periode = (int)$src['tahun_awal'] . '-' . (int)$src['tahun_akhir'];

            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO rkt_programs
                (document_id, rkjm_program_id, rkjm_document_id, rkjm_referensi, field_id, bidang_nama, kode_program,
                 nama_program, kondisi_awal, tujuan, sasaran, indikator, target_tahunan, penanggung_jawab,
                 estimasi_anggaran, sumber_dana, output, monitoring_evaluasi, catatan, status, urutan)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'belum_dimulai',?)");
            $added = 0;
            $skipped = 0;
            foreach ($sources as $s) {
                $namaLower = mb_strtolower(trim((string)$s['nama_program']));
                if (isset($haveIds[(int)$s['id']]) || isset($haveNames[$namaLower])) {
                    $skipped++;
                    continue;
                }
                $urutan++;
                $ref = trim((string)$s['kode_program']);
                $ref = ($ref !== '' ? $ref . ' - ' : '') . $s['nama_program'] . ' (RKJM ' . $periode . ')';
                $ins->execute([
                    $docId,
                    (int)$s['id'],
                    (int)$src['id'],
                    $ref,
                    $s['field_id'] ?: null,
                    $s['bidang_nama'],
                    $s['kode_program'],
                    $s['nama_program'],
                    $s['kondisi_awal'] ?? '',
                    $s['tujuan'] ?? '',
                    $s['sasaran'] ?? '',
                    $s['indikator'] ?? '',
                    $s['target_tahunan'],
                    $s['penanggung_jawab'] ?? '',
                    0, // Jangan salin anggaran empat tahunan RKJM sebagai anggaran tahunan.
                    '',
                    $s['kegiatan_utama'] ?? '',
                    $s['monitoring_evaluasi'] ?? '',
                    'Disalin dari RKJM ' . $periode . '. Anggaran tahunan perlu disusun terpisah.',
                    $urutan,
                ]);
                $haveIds[(int)$s['id']] = true;
                $haveNames[$namaLower] = true;
                $added++;
            }
            $pdo->commit();

            if ($added === 0) {
                rktJson(['success' => false, 'message' => 'Semua program yang dipilih sudah ada di RKT ini (' . $skipped . ' duplikat).']);
            }
            logActivity($pdo, $userId, 'Impor Program RKJM ke RKT', $added . ' program ditambahkan');
            rktJson(['success' => true, 'message' => $added . ' program RKJM ditambahkan ke RKT. ' . ($skipped ? $skipped . ' duplikat dilewati.' : ''), 'added' => $added, 'skipped' => $skipped]);
        }

        case 'apply_templates': {
            $doc = rktDocOrFail($pdo, $docId);
            $tahun = (int)$doc['tahun'];
            $ids = rktIntList($_POST['template_ids'] ?? []);
            if (!$ids) rktJson(['success' => false, 'message' => 'Pilih minimal satu template.']);

            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT * FROM rkt_template_programs WHERE id IN ($in) AND is_active = 1 ORDER BY bidang_nama ASC, kode ASC");
            $stmt->execute($ids);
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$templates) rktJson(['success' => false, 'message' => 'Template aktif tidak ditemukan.']);

            $existing = $pdo->prepare("SELECT template_id, kode_program, LOWER(nama_program) AS nama FROM rkt_programs WHERE document_id = ?");
            $existing->execute([$docId]);
            $haveTpl = [];
            $haveCodes = [];
            $haveNames = [];
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $e) {
                if (!empty($e['template_id'])) $haveTpl[(int)$e['template_id']] = true;
                if (trim((string)$e['kode_program']) !== '') $haveCodes[mb_strtolower(trim((string)$e['kode_program']))] = true;
                $haveNames[$e['nama']] = true;
            }

            $actStmt = $pdo->prepare("SELECT * FROM rkt_template_activities WHERE template_program_id = ? ORDER BY urutan ASC, id ASC");
            $insProg = $pdo->prepare("INSERT INTO rkt_programs
                (document_id, template_id, field_id, bidang_nama, kode_program, nama_program, kondisi_awal, tujuan,
                 sasaran, indikator, target_tahunan, jadwal_mulai, jadwal_selesai, penanggung_jawab,
                 estimasi_anggaran, sumber_dana, output, monitoring_evaluasi, catatan, status, urutan)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'belum_dimulai',?)");
            $insAct = $pdo->prepare("INSERT INTO rkt_activities
                (program_id, template_activity_id, nama_kegiatan, tujuan, indikator, target_volume, satuan,
                 jadwal_mulai, jadwal_selesai, penanggung_jawab, estimasi_biaya, sumber_dana, output, status, urutan)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'belum_dimulai',?)");

            $urutan = (int)$pdo->query("SELECT COALESCE(MAX(urutan),0) FROM rkt_programs WHERE document_id = " . (int)$docId)->fetchColumn();
            $added = 0;
            $skipped = 0;
            $pdo->beginTransaction();
            foreach ($templates as $t) {
                $kode = mb_strtolower(trim((string)$t['kode']));
                $nama = mb_strtolower(trim((string)$t['nama_program']));
                if (isset($haveTpl[(int)$t['id']]) || ($kode !== '' && isset($haveCodes[$kode])) || isset($haveNames[$nama])) {
                    $skipped++;
                    continue;
                }
                $urutan++;
                $bulanMulai = (int)($t['bulan_mulai'] ?? 1) ?: 1;
                $bulanSelesai = (int)($t['bulan_selesai'] ?? 12) ?: 12;
                if ($bulanSelesai < $bulanMulai) $bulanSelesai = $bulanMulai;
                $insProg->execute([
                    $docId,
                    (int)$t['id'],
                    $t['field_id'] ?: null,
                    $t['bidang_nama'],
                    $t['kode'],
                    $t['nama_program'],
                    $t['latar_belakang'] ?? '',
                    $t['tujuan'] ?? '',
                    $t['sasaran'] ?? '',
                    $t['indikator'] ?? '',
                    $t['target'] ?? '',
                    rktMonthStart($tahun, $bulanMulai),
                    rktMonthEnd($tahun, $bulanSelesai),
                    $t['penanggung_jawab'] ?? '',
                    (float)$t['estimasi_anggaran'],
                    $t['sumber_dana'] ?? '',
                    $t['output'] ?? '',
                    $t['monitoring_evaluasi'] ?? '',
                    $t['catatan'] ?? '',
                    $urutan,
                ]);
                $pid = (int)$pdo->lastInsertId();

                $actStmt->execute([(int)$t['id']]);
                $i = 0;
                foreach ($actStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $i++;
                    $am = (int)($a['bulan_mulai'] ?? $bulanMulai) ?: $bulanMulai;
                    $as = (int)($a['bulan_selesai'] ?? $bulanSelesai) ?: $bulanSelesai;
                    if ($as < $am) $as = $am;
                    $insAct->execute([
                        $pid,
                        (int)$a['id'],
                        $a['nama_kegiatan'],
                        $a['tujuan'] ?? '',
                        $a['indikator'] ?? '',
                        $a['target_volume'] ?? '',
                        $a['satuan'] ?? '',
                        rktMonthStart($tahun, $am),
                        rktMonthEnd($tahun, $as),
                        $a['penanggung_jawab'] ?? '',
                        (float)$a['estimasi_biaya'],
                        $a['sumber_dana'] ?? '',
                        $a['output'] ?? '',
                        $i,
                    ]);
                }

                $haveTpl[(int)$t['id']] = true;
                if ($kode !== '') $haveCodes[$kode] = true;
                $haveNames[$nama] = true;
                $added++;
            }
            $pdo->commit();

            if ($added === 0) {
                rktJson(['success' => false, 'message' => 'Semua template yang dipilih sudah ada di RKT ini (' . $skipped . ' duplikat).']);
            }
            logActivity($pdo, $userId, 'Terapkan Template RKT', $added . ' program ditambahkan');
            rktJson(['success' => true, 'message' => $added . ' program template ditambahkan.' . ($skipped ? ' ' . $skipped . ' duplikat dilewati.' : ''), 'added' => $added, 'skipped' => $skipped]);
        }

        case 'template_get': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM rkt_template_programs WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) rktJson(['success' => false, 'message' => 'Template tidak ditemukan.'], 404);
            $acts = $pdo->prepare("SELECT * FROM rkt_template_activities WHERE template_program_id = ? ORDER BY urutan ASC, id ASC");
            $acts->execute([$id]);
            $row['activities'] = $acts->fetchAll(PDO::FETCH_ASSOC);
            $used = $pdo->prepare("SELECT COUNT(*) FROM rkt_programs WHERE template_id = ?");
            $used->execute([$id]);
            $row['used_count'] = (int)$used->fetchColumn();
            rktJson(['success' => true, 'data' => $row]);
        }

        case 'template_save': {
            $id = (int)($_POST['id'] ?? 0);
            $nama = trim((string)($_POST['nama_program'] ?? ''));
            if ($nama === '') rktJson(['success' => false, 'message' => 'Nama program template wajib diisi.']);

            [$fieldId, $bidangNama] = rktFieldData($pdo, (int)($_POST['field_id'] ?? 0));
            $bidangBaru = trim((string)($_POST['bidang_baru'] ?? ''));
            if ($bidangBaru !== '') {
                $cek = $pdo->prepare("SELECT id, nama FROM rkjm_fields WHERE LOWER(nama) = LOWER(?) LIMIT 1");
                $cek->execute([$bidangBaru]);
                $found = $cek->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $fieldId = (int)$found['id'];
                    $bidangNama = (string)$found['nama'];
                } else {
                    $kodeBaru = 'BID-' . str_pad((string)((int)$pdo->query("SELECT COUNT(*) FROM rkjm_fields")->fetchColumn() + 1), 2, '0', STR_PAD_LEFT);
                    $maxUrut = (int)$pdo->query("SELECT COALESCE(MAX(urutan),0) FROM rkjm_fields")->fetchColumn();
                    $pdo->prepare("INSERT INTO rkjm_fields (kode, nama, urutan) VALUES (?,?,?)")->execute([$kodeBaru, $bidangBaru, $maxUrut + 1]);
                    $fieldId = (int)$pdo->lastInsertId();
                    $bidangNama = $bidangBaru;
                }
            }

            $kode = trim((string)($_POST['kode'] ?? ''));
            if ($kode === '') {
                $kode = 'RKT-TPL-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            }
            $dupKode = $pdo->prepare("SELECT id FROM rkt_template_programs WHERE kode = ? AND id <> ? LIMIT 1");
            $dupKode->execute([$kode, $id]);
            if ($dupKode->fetchColumn()) {
                rktJson(['success' => false, 'message' => 'Kode template sudah digunakan.']);
            }

            $bulanMulai = max(1, min(12, (int)($_POST['bulan_mulai'] ?? 1) ?: 1));
            $bulanSelesai = max(1, min(12, (int)($_POST['bulan_selesai'] ?? 12) ?: 12));
            if ($bulanSelesai < $bulanMulai) $bulanSelesai = $bulanMulai;
            $isActive = ((string)($_POST['is_active'] ?? '1')) === '1' ? 1 : 0;

            $data = [
                'kode' => $kode,
                'field_id' => $fieldId,
                'bidang_nama' => $bidangNama ?: 'Lainnya',
                'nama_program' => $nama,
                'latar_belakang' => trim((string)($_POST['latar_belakang'] ?? '')),
                'tujuan' => trim((string)($_POST['tujuan'] ?? '')),
                'sasaran' => trim((string)($_POST['sasaran'] ?? '')),
                'indikator' => trim((string)($_POST['indikator'] ?? '')),
                'target' => trim((string)($_POST['target'] ?? '')),
                'penanggung_jawab' => trim((string)($_POST['penanggung_jawab'] ?? '')),
                'estimasi_anggaran' => rktMoney($_POST['estimasi_anggaran'] ?? 0),
                'sumber_dana' => trim((string)($_POST['sumber_dana'] ?? '')),
                'output' => trim((string)($_POST['output'] ?? '')),
                'monitoring_evaluasi' => trim((string)($_POST['monitoring_evaluasi'] ?? '')),
                'catatan' => trim((string)($_POST['catatan'] ?? '')),
                'bulan_mulai' => $bulanMulai,
                'bulan_selesai' => $bulanSelesai,
                'is_active' => $isActive,
            ];

            $activities = [];
            $raw = (string)($_POST['activities'] ?? '[]');
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $a) {
                    if (!is_array($a)) continue;
                    $an = trim((string)($a['nama_kegiatan'] ?? ''));
                    if ($an === '') continue;
                    $activities[] = [
                        'id' => (int)($a['id'] ?? 0),
                        'nama_kegiatan' => $an,
                        'tujuan' => trim((string)($a['tujuan'] ?? '')),
                        'indikator' => trim((string)($a['indikator'] ?? '')),
                        'target_volume' => trim((string)($a['target_volume'] ?? '')),
                        'satuan' => trim((string)($a['satuan'] ?? '')),
                        'bulan_mulai' => max(1, min(12, (int)($a['bulan_mulai'] ?? $bulanMulai) ?: $bulanMulai)),
                        'bulan_selesai' => max(1, min(12, (int)($a['bulan_selesai'] ?? $bulanSelesai) ?: $bulanSelesai)),
                        'penanggung_jawab' => trim((string)($a['penanggung_jawab'] ?? '')),
                        'estimasi_biaya' => rktMoney($a['estimasi_biaya'] ?? 0),
                        'sumber_dana' => trim((string)($a['sumber_dana'] ?? '')),
                        'output' => trim((string)($a['output'] ?? '')),
                    ];
                }
            }
            if (count($activities) > 100) {
                rktJson(['success' => false, 'message' => 'Jumlah kegiatan template terlalu banyak (maksimal 100).']);
            }

            $pdo->beginTransaction();
            if ($id > 0) {
                $own = $pdo->prepare("SELECT id FROM rkt_template_programs WHERE id = ?");
                $own->execute([$id]);
                if (!$own->fetchColumn()) {
                    $pdo->rollBack();
                    rktJson(['success' => false, 'message' => 'Template tidak ditemukan.'], 404);
                }
                $sets = [];
                $params = [];
                foreach ($data as $k => $v) { $sets[] = "`$k` = ?"; $params[] = $v; }
                $params[] = $id;
                $pdo->prepare("UPDATE rkt_template_programs SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                $msg = 'Template diperbarui. RKT yang sudah disalin tidak berubah.';
            } else {
                $cols = array_keys($data);
                $place = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO rkt_template_programs (`" . implode('`,`', $cols) . "`) VALUES ($place)")->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
                $msg = 'Template ditambahkan.';
            }

            $existingActs = [];
            $ea = $pdo->prepare("SELECT id FROM rkt_template_activities WHERE template_program_id = ?");
            $ea->execute([$id]);
            foreach ($ea->fetchAll(PDO::FETCH_COLUMN, 0) as $aid) $existingActs[(int)$aid] = true;

            $upd = $pdo->prepare("UPDATE rkt_template_activities SET nama_kegiatan=?, tujuan=?, indikator=?, target_volume=?, satuan=?,
                bulan_mulai=?, bulan_selesai=?, penanggung_jawab=?, estimasi_biaya=?, sumber_dana=?, output=?, urutan=?
                WHERE id=? AND template_program_id=?");
            $ins = $pdo->prepare("INSERT INTO rkt_template_activities
                (template_program_id, nama_kegiatan, tujuan, indikator, target_volume, satuan, bulan_mulai, bulan_selesai,
                 penanggung_jawab, estimasi_biaya, sumber_dana, output, urutan)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

            $keep = [];
            $i = 0;
            foreach ($activities as $a) {
                $i++;
                if ($a['bulan_selesai'] < $a['bulan_mulai']) $a['bulan_selesai'] = $a['bulan_mulai'];
                if ($a['id'] > 0 && isset($existingActs[$a['id']])) {
                    $upd->execute([
                        $a['nama_kegiatan'], $a['tujuan'], $a['indikator'], $a['target_volume'], $a['satuan'],
                        $a['bulan_mulai'], $a['bulan_selesai'], $a['penanggung_jawab'], $a['estimasi_biaya'],
                        $a['sumber_dana'], $a['output'], $i, $a['id'], $id,
                    ]);
                    $keep[$a['id']] = true;
                } else {
                    $ins->execute([
                        $id, $a['nama_kegiatan'], $a['tujuan'], $a['indikator'], $a['target_volume'], $a['satuan'],
                        $a['bulan_mulai'], $a['bulan_selesai'], $a['penanggung_jawab'], $a['estimasi_biaya'],
                        $a['sumber_dana'], $a['output'], $i,
                    ]);
                }
            }
            foreach ($existingActs as $aid => $_) {
                if (!isset($keep[$aid])) {
                    $pdo->prepare("DELETE FROM rkt_template_activities WHERE id = ? AND template_program_id = ?")->execute([$aid, $id]);
                }
            }
            $pdo->commit();

            logActivity($pdo, $userId, 'Simpan Template RKT', $nama);
            rktJson(['success' => true, 'message' => $msg, 'id' => $id]);
        }

        case 'template_toggle': {
            $id = (int)($_POST['id'] ?? 0);
            $active = (int)($_POST['is_active'] ?? 0) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE rkt_template_programs SET is_active = ? WHERE id = ?");
            $stmt->execute([$active, $id]);
            if ($stmt->rowCount() === 0) rktJson(['success' => false, 'message' => 'Template tidak ditemukan.']);
            logActivity($pdo, $userId, 'Ubah Status Template RKT', 'Template #' . $id . ' => ' . ($active ? 'aktif' : 'nonaktif'));
            rktJson(['success' => true, 'message' => $active ? 'Template diaktifkan.' : 'Template dinonaktifkan.']);
        }

        case 'template_delete': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, nama_program FROM rkt_template_programs WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) rktJson(['success' => false, 'message' => 'Template tidak ditemukan.'], 404);

            $used = $pdo->prepare("SELECT COUNT(*) FROM rkt_programs WHERE template_id = ?");
            $used->execute([$id]);
            $usedCount = (int)$used->fetchColumn();

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE rkt_programs SET template_id = NULL WHERE template_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM rkt_template_programs WHERE id = ?")->execute([$id]);
            $pdo->commit();

            logActivity($pdo, $userId, 'Hapus Template RKT', (string)$row['nama_program']);
            rktJson([
                'success' => true,
                'message' => 'Template dihapus.' . ($usedCount > 0 ? ' ' . $usedCount . ' program RKT hasil salinan tetap tersimpan tanpa perubahan.' : ''),
                'used_count' => $usedCount,
            ]);
        }

        default:
            rktJson(['success' => false, 'message' => 'Aksi tidak dikenal.'], 400);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('RKT ajax error: ' . $e->getMessage());
    rktJson(['success' => false, 'message' => 'Terjadi kesalahan saat memproses data: ' . $e->getMessage()], 500);
}
