<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/rkjm_helper.php';

if (session_status() == PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function rkjmJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isAuthorized(['admin', 'kepala_madrasah'])) {
    rkjmJson(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk aksi ini.'], 403);
}

$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!appVerifyCsrfToken($token)) {
    rkjmJson(['success' => false, 'message' => 'Sesi keamanan tidak valid. Muat ulang halaman.'], 419);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rkjmJson(['success' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

rkjmEnsureSchema($pdo);

$action = (string)($_POST['action'] ?? '');
$docId = (int)($_POST['document_id'] ?? 0);
$userId = (string)($_SESSION['username'] ?? 'system');

function rkjmDocOrFail(PDO $pdo, int $docId): array
{
    $doc = rkjmGetDocument($pdo, $docId);
    if (!$doc) {
        rkjmJson(['success' => false, 'message' => 'Dokumen RKJM tidak ditemukan.'], 404);
    }
    return $doc;
}

function rkjmIntList($value): array
{
    if (is_array($value)) {
        $ids = $value;
    } else {
        $ids = explode(',', (string)$value);
    }
    $out = [];
    foreach ($ids as $v) {
        $n = (int)$v;
        if ($n > 0) $out[] = $n;
    }
    return array_values(array_unique($out));
}

try {
    switch ($action) {

        case 'save_document': {
            $id = (int)($_POST['id'] ?? 0);
            $tahunAwal = (int)($_POST['tahun_awal'] ?? 0);
            $tahunAkhir = (int)($_POST['tahun_akhir'] ?? 0);
            if ($tahunAwal < 1901 || $tahunAwal > 2152 || $tahunAkhir < 1901 || $tahunAkhir > 2155) {
                rkjmJson(['success' => false, 'message' => 'Tahun tidak valid.']);
            }
            if ($tahunAkhir - $tahunAwal !== 3) {
                rkjmJson(['success' => false, 'message' => 'Periode RKJM harus tepat 4 tahun (tahun akhir = tahun awal + 3).']);
            }

            $allowed = ['nama_madrasah','nomor_dokumen','tahun_awal','tahun_akhir','visi','misi','tujuan_pengembangan','kondisi_awal','dasar_penyusunan','tanggal_penyusunan','nama_kepala','status'];
            $data = [];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $_POST)) {
                    $data[$k] = trim((string)$_POST[$k]);
                }
            }
            $statusOpts = rkjmDocStatusOptions();
            if (isset($data['status']) && !isset($statusOpts[$data['status']])) {
                $data['status'] = 'draf';
            }
            if (isset($data['tanggal_penyusunan']) && $data['tanggal_penyusunan'] === '') {
                $data['tanggal_penyusunan'] = null;
            }
            if (isset($data['tahun_awal'])) $data['tahun_awal'] = $tahunAwal;
            if (isset($data['tahun_akhir'])) $data['tahun_akhir'] = $tahunAkhir;

            // Identitas madrasah dan kepala madrasah selalu mengikuti Pengaturan Admin.
            $schoolProfile = getSchoolProfile($pdo);
            $namaMadrasah = trim((string)($schoolProfile['nama_madrasah'] ?? ''));
            if ($namaMadrasah !== '') {
                $data['nama_madrasah'] = $namaMadrasah;
            }
            $namaKepala = trim((string)($schoolProfile['nama_kepala'] ?? ''));
            if ($namaKepala === '') {
                $namaKepala = trim((string)($schoolProfile['kepala_madrasah'] ?? ''));
            }
            if ($namaKepala !== '') {
                $data['nama_kepala'] = $namaKepala;
            }

            // Visi, misi, tujuan, profil singkat, dan dasar penyusunan berupa HTML dari editor.
            foreach (['visi', 'misi', 'tujuan_pengembangan', 'kondisi_awal', 'dasar_penyusunan'] as $richKey) {
                if (array_key_exists($richKey, $data)) {
                    $data[$richKey] = sanitizeRichText((string)$data[$richKey]);
                }
            }

            if ($id > 0) {
                $doc = rkjmDocOrFail($pdo, $id);
                if (!$data) {
                    rkjmJson(['success' => false, 'message' => 'Tidak ada data untuk disimpan.']);
                }
                $sets = [];
                $params = [];
                foreach ($data as $k => $v) {
                    $sets[] = "$k = ?";
                    $params[] = $v;
                }
                $params[] = $id;
                $stmt = $pdo->prepare("UPDATE rkjm_documents SET " . implode(', ', $sets) . " WHERE id = ?");
                $stmt->execute($params);

                // Sinkronkan tahun_kalender target bila periode berubah.
                if ((int)$doc['tahun_awal'] !== $tahunAwal) {
                    $progs = $pdo->prepare("SELECT id FROM rkjm_programs WHERE document_id = ?");
                    $progs->execute([$id]);
                    $upd = $pdo->prepare("UPDATE rkjm_program_targets SET tahun_kalender = ? WHERE program_id = ? AND tahun_ke = ?");
                    foreach ($progs->fetchAll(PDO::FETCH_COLUMN, 0) as $pid) {
                        for ($i = 1; $i <= 4; $i++) {
                            $upd->execute([$tahunAwal + $i - 1, (int)$pid, $i]);
                        }
                    }
                }
                logActivity($pdo, $userId, 'Update RKJM', 'Dokumen RKJM #' . $id);
                rkjmJson(['success' => true, 'message' => 'Dokumen RKJM diperbarui.', 'document_id' => $id]);
            }

            if ($tahunAwal <= 0) {
                rkjmJson(['success' => false, 'message' => 'Tahun awal wajib diisi.']);
            }
            $stmt = $pdo->prepare("INSERT INTO rkjm_documents
                (nama_madrasah, nomor_dokumen, tahun_awal, tahun_akhir, visi, misi, tujuan_pengembangan,
                 kondisi_awal, dasar_penyusunan, tanggal_penyusunan, nama_kepala, status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $data['nama_madrasah'] ?? '',
                $data['nomor_dokumen'] ?? '',
                $tahunAwal,
                $tahunAkhir,
                $data['visi'] ?? '',
                $data['misi'] ?? '',
                $data['tujuan_pengembangan'] ?? '',
                $data['kondisi_awal'] ?? '',
                $data['dasar_penyusunan'] ?? '',
                ($data['tanggal_penyusunan'] ?? '') ?: null,
                $data['nama_kepala'] ?? '',
                $data['status'] ?? 'draf',
                $userId,
            ]);
            $newId = (int)$pdo->lastInsertId();
            logActivity($pdo, $userId, 'Tambah RKJM', 'Dokumen RKJM #' . $newId);
            rkjmJson(['success' => true, 'message' => 'Dokumen RKJM dibuat.', 'document_id' => $newId]);
        }

        case 'save_program': {
            $doc = rkjmDocOrFail($pdo, $docId);
            $id = (int)($_POST['id'] ?? 0);
            $nama = trim((string)($_POST['nama_program'] ?? ''));
            if ($nama === '') {
                rkjmJson(['success' => false, 'message' => 'Nama program wajib diisi.']);
            }
            $fieldId = (int)($_POST['field_id'] ?? 0) ?: null;
            $bidangNama = null;
            if ($fieldId) {
                $fs = $pdo->prepare("SELECT nama FROM rkjm_fields WHERE id = ?");
                $fs->execute([$fieldId]);
                $bidangNama = $fs->fetchColumn() ?: null;
                if ($bidangNama === null) $fieldId = null;
            }
            $statusOpts = rkjmProgramStatusOptions();
            $status = (string)($_POST['status'] ?? 'belum_dimulai');
            if (!isset($statusOpts[$status])) $status = 'belum_dimulai';

            $anggaran = (float)preg_replace('/[^0-9]/', '', (string)($_POST['estimasi_anggaran'] ?? '0'));
            $fieldsData = [
                'field_id' => $fieldId,
                'bidang_nama' => $bidangNama,
                'kode_program' => trim((string)($_POST['kode_program'] ?? '')),
                'nama_program' => $nama,
                'kondisi_awal' => trim((string)($_POST['kondisi_awal'] ?? '')),
                'permasalahan' => trim((string)($_POST['permasalahan'] ?? '')),
                'tujuan' => trim((string)($_POST['tujuan'] ?? '')),
                'sasaran' => trim((string)($_POST['sasaran'] ?? '')),
                'indikator' => trim((string)($_POST['indikator'] ?? '')),
                'target_akhir' => trim((string)($_POST['target_akhir'] ?? '')),
                'kegiatan_utama' => trim((string)($_POST['kegiatan_utama'] ?? '')),
                'penanggung_jawab' => trim((string)($_POST['penanggung_jawab'] ?? '')),
                'estimasi_anggaran' => $anggaran,
                'sumber_dana' => trim((string)($_POST['sumber_dana'] ?? '')),
                'dokumen_bukti' => trim((string)($_POST['dokumen_bukti'] ?? '')),
                'monitoring_evaluasi' => trim((string)($_POST['monitoring_evaluasi'] ?? '')),
                'catatan' => trim((string)($_POST['catatan'] ?? '')),
                'status' => $status,
                'urutan' => (int)($_POST['urutan'] ?? 0),
            ];

            $targets = [];
            $tahunAwal = (int)$doc['tahun_awal'];
            for ($i = 1; $i <= 4; $i++) {
                $targets[$i] = trim((string)($_POST['target_tahun' . $i] ?? ''));
            }

            $pdo->beginTransaction();
            if ($id > 0) {
                $check = $pdo->prepare("SELECT id FROM rkjm_programs WHERE id = ? AND document_id = ?");
                $check->execute([$id, $docId]);
                if (!$check->fetchColumn()) {
                    $pdo->rollBack();
                    rkjmJson(['success' => false, 'message' => 'Program tidak ditemukan pada dokumen ini.'], 404);
                }
                $sets = [];
                $params = [];
                foreach ($fieldsData as $k => $v) {
                    $sets[] = "$k = ?";
                    $params[] = $v;
                }
                $params[] = $id;
                $pdo->prepare("UPDATE rkjm_programs SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                $msg = 'Program RKJM diperbarui.';
            } else {
                $cols = array_keys($fieldsData);
                $cols[] = 'document_id';
                $params = array_values($fieldsData);
                $params[] = $docId;
                $place = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO rkjm_programs (" . implode(',', $cols) . ") VALUES ($place)")->execute($params);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Program RKJM ditambahkan.';
            }

            $upsert = $pdo->prepare("INSERT INTO rkjm_program_targets (program_id, tahun_ke, tahun_kalender, target)
                VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE tahun_kalender = VALUES(tahun_kalender), target = VALUES(target)");
            for ($i = 1; $i <= 4; $i++) {
                $upsert->execute([$id, $i, $tahunAwal + $i - 1, $targets[$i]]);
            }
            $pdo->commit();

            logActivity($pdo, $userId, $id ? 'Simpan Program RKJM' : 'Tambah Program RKJM', $nama);
            rkjmJson(['success' => true, 'message' => $msg, 'id' => $id]);
        }

        case 'get_program': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM rkjm_programs WHERE id = ? AND document_id = ?");
            $stmt->execute([$id, $docId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) rkjmJson(['success' => false, 'message' => 'Program tidak ditemukan.'], 404);

            $doc = rkjmDocOrFail($pdo, $docId);
            $years = rkjmYears($doc);
            $targets = [];
            $stmt = $pdo->prepare("SELECT tahun_ke, tahun_kalender, target FROM rkjm_program_targets WHERE program_id = ? ORDER BY tahun_ke ASC");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $targets[(int)$t['tahun_ke']] = $t;
            }
            $stmt = $pdo->prepare("SELECT * FROM rkjm_monitoring WHERE program_id = ? ORDER BY tahun_kalender DESC, id DESC");
            $stmt->execute([$id]);
            $monitoring = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $labels = rkjmProgramStatusOptions();
            foreach ($monitoring as &$m) {
                $m['status_label'] = $labels[$m['status_pelaksanaan']] ?? $m['status_pelaksanaan'];
            }
            unset($m);

            $row['status_label'] = $labels[$row['status']] ?? $row['status'];
            $row['targets'] = $targets;
            $row['monitoring'] = $monitoring;
            $row['years'] = $years;
            rkjmJson(['success' => true, 'data' => $row]);
        }

        case 'delete_program': {
            $id = (int)($_POST['id'] ?? 0);
            $check = $pdo->prepare("SELECT nama_program FROM rkjm_programs WHERE id = ? AND document_id = ?");
            $check->execute([$id, $docId]);
            $nama = $check->fetchColumn();
            if (!$nama) rkjmJson(['success' => false, 'message' => 'Program tidak ditemukan.'], 404);

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM rkjm_monitoring WHERE program_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM rkjm_program_targets WHERE program_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM rkjm_programs WHERE id = ? AND document_id = ?")->execute([$id, $docId]);
            $pdo->commit();
            logActivity($pdo, $userId, 'Hapus Program RKJM', (string)$nama);
            rkjmJson(['success' => true, 'message' => 'Program dihapus.']);
        }

        case 'duplicate_program': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM rkjm_programs WHERE id = ? AND document_id = ?");
            $stmt->execute([$id, $docId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) rkjmJson(['success' => false, 'message' => 'Program tidak ditemukan.'], 404);

            $pdo->beginTransaction();
            unset($row['id'], $row['created_at'], $row['updated_at']);
            $row['nama_program'] = $row['nama_program'] . ' (Salinan)';
            $row['status'] = 'belum_dimulai';
            $cols = array_keys($row);
            $place = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO rkjm_programs (" . implode(',', $cols) . ") VALUES ($place)")->execute(array_values($row));
            $newId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO rkjm_program_targets (program_id, tahun_ke, tahun_kalender, target)
                SELECT ?, tahun_ke, tahun_kalender, target FROM rkjm_program_targets WHERE program_id = ?")
                ->execute([$newId, $id]);
            $pdo->commit();
            logActivity($pdo, $userId, 'Duplikasi Program RKJM', (string)$row['nama_program']);
            rkjmJson(['success' => true, 'message' => 'Program diduplikasi.', 'id' => $newId]);
        }

        case 'update_status': {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            $opts = rkjmProgramStatusOptions();
            if (!isset($opts[$status])) rkjmJson(['success' => false, 'message' => 'Status tidak valid.']);
            $stmt = $pdo->prepare("UPDATE rkjm_programs SET status = ? WHERE id = ? AND document_id = ?");
            $stmt->execute([$status, $id, $docId]);
            if ($stmt->rowCount() === 0) rkjmJson(['success' => false, 'message' => 'Program tidak ditemukan.']);
            logActivity($pdo, $userId, 'Update Status Program RKJM', 'Program #' . $id . ' => ' . $status);
            rkjmJson(['success' => true, 'message' => 'Status diperbarui.']);
        }

        case 'apply_templates': {
            $doc = rkjmDocOrFail($pdo, $docId);
            $ids = rkjmIntList($_POST['template_ids'] ?? []);
            if (!$ids) rkjmJson(['success' => false, 'message' => 'Pilih minimal satu template.']);

            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT * FROM rkjm_template_programs WHERE id IN ($in) AND is_active = 1 ORDER BY bidang_nama ASC, kode ASC");
            $stmt->execute($ids);
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$templates) rkjmJson(['success' => false, 'message' => 'Template aktif tidak ditemukan.']);

            // Deteksi duplikat: kode/nama program yang sudah ada pada dokumen ini.
            $existing = $pdo->prepare("SELECT kode_program, nama_program FROM rkjm_programs WHERE document_id = ?");
            $existing->execute([$docId]);
            $haveCodes = [];
            $haveNames = [];
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $e) {
                if (trim((string)$e['kode_program']) !== '') $haveCodes[mb_strtolower(trim($e['kode_program']))] = true;
                $haveNames[mb_strtolower(trim((string)$e['nama_program']))] = true;
            }

            $tahunAwal = (int)$doc['tahun_awal'];
            $added = 0;
            $skipped = 0;
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO rkjm_programs
                (document_id, template_id, field_id, bidang_nama, kode_program, nama_program, kondisi_awal, permasalahan,
                 tujuan, sasaran, indikator, target_akhir, kegiatan_utama, penanggung_jawab, estimasi_anggaran,
                 sumber_dana, dokumen_bukti, monitoring_evaluasi, catatan, status, urutan)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'belum_dimulai',?)");
            $insTarget = $pdo->prepare("INSERT INTO rkjm_program_targets (program_id, tahun_ke, tahun_kalender, target)
                VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE target = VALUES(target)");
            $maxUrut = (int)$pdo->query("SELECT COALESCE(MAX(urutan),0) FROM rkjm_programs WHERE document_id = " . (int)$docId)->fetchColumn();

            foreach ($templates as $t) {
                $kode = strtolower(trim((string)$t['kode']));
                $nama = mb_strtolower(trim((string)$t['nama_program']));
                if (($kode !== '' && isset($haveCodes[$kode])) || isset($haveNames[$nama])) {
                    $skipped++;
                    continue;
                }
                $maxUrut++;
                $ins->execute([
                    $docId, (int)$t['id'], $t['field_id'] ?: null, $t['bidang_nama'], $t['kode'], $t['nama_program'],
                    $t['latar_belakang'], $t['permasalahan'], $t['tujuan'], $t['sasaran'], $t['indikator'], $t['target_akhir'],
                    $t['kegiatan_utama'], $t['penanggung_jawab'], $t['estimasi_anggaran'], $t['sumber_dana'],
                    $t['dokumen_bukti'], $t['monitoring_evaluasi'], $t['catatan'], $maxUrut,
                ]);
                $pid = (int)$pdo->lastInsertId();
                $t1 = $t['target_tahun1'] ?? '';
                $t2 = $t['target_tahun2'] ?? '';
                $t3 = $t['target_tahun3'] ?? '';
                $t4 = $t['target_tahun4'] ?? '';
                foreach ([1 => $t1, 2 => $t2, 3 => $t3, 4 => $t4] as $tk => $tt) {
                    $insTarget->execute([$pid, $tk, $tahunAwal + $tk - 1, $tt]);
                }
                $haveCodes[$kode] = true;
                $haveNames[$nama] = true;
                $added++;
            }
            $pdo->commit();

            if ($added === 0) {
                rkjmJson(['success' => false, 'message' => 'Semua template yang dipilih sudah ada pada RKJM ini (' . $skipped . ' duplikat).']);
            }
            logActivity($pdo, $userId, 'Terapkan Template RKJM', $added . ' program ditambahkan');
            rkjmJson(['success' => true, 'message' => $added . ' program ditambahkan.', 'added' => $added, 'skipped' => $skipped]);
        }

        case 'save_monitoring': {
            $id = (int)($_POST['id'] ?? 0);
            $programId = (int)($_POST['program_id'] ?? 0);
            $check = $pdo->prepare("SELECT id, nama_program FROM rkjm_programs WHERE id = ? AND document_id = ?");
            $check->execute([$programId, $docId]);
            $prog = $check->fetch(PDO::FETCH_ASSOC);
            if (!$prog) rkjmJson(['success' => false, 'message' => 'Program tidak ditemukan.'], 404);

            $statusOpts = rkjmProgramStatusOptions();
            $status = (string)($_POST['status_pelaksanaan'] ?? 'belum_dimulai');
            if (!isset($statusOpts[$status])) $status = 'belum_dimulai';
            $pctRaw = trim((string)($_POST['persentase_capaian'] ?? ''));
            $pct = null;
            if ($pctRaw !== '') {
                $pct = max(0, min(100, (float)$pctRaw));
            }
            $tahun = (int)($_POST['tahun_kalender'] ?? 0) ?: null;
            $tgl = trim((string)($_POST['tanggal_evaluasi'] ?? '')) ?: null;
            $data = [
                'tahun_kalender' => $tahun,
                'target_tahun_berjalan' => trim((string)($_POST['target_tahun_berjalan'] ?? '')),
                'realisasi' => trim((string)($_POST['realisasi'] ?? '')),
                'persentase_capaian' => $pct,
                'penilaian_kualitatif' => trim((string)($_POST['penilaian_kualitatif'] ?? '')) ?: null,
                'status_pelaksanaan' => $status,
                'kendala' => trim((string)($_POST['kendala'] ?? '')),
                'tindak_lanjut' => trim((string)($_POST['tindak_lanjut'] ?? '')),
                'tanggal_evaluasi' => $tgl,
                'petugas_evaluasi' => trim((string)($_POST['petugas_evaluasi'] ?? '')),
                'catatan_evaluasi' => trim((string)($_POST['catatan_evaluasi'] ?? '')),
            ];

            $pdo->beginTransaction();
            if ($id > 0) {
                $own = $pdo->prepare("SELECT id FROM rkjm_monitoring WHERE id = ? AND program_id = ?");
                $own->execute([$id, $programId]);
                if (!$own->fetchColumn()) {
                    $pdo->rollBack();
                    rkjmJson(['success' => false, 'message' => 'Catatan monitoring tidak ditemukan.'], 404);
                }
                $sets = [];
                $params = [];
                foreach ($data as $k => $v) { $sets[] = "$k = ?"; $params[] = $v; }
                $params[] = $id;
                $pdo->prepare("UPDATE rkjm_monitoring SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            } else {
                $data['program_id'] = $programId;
                $cols = array_keys($data);
                $place = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO rkjm_monitoring (" . implode(',', $cols) . ") VALUES ($place)")->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
            }
            // Sinkronkan status program dengan status monitoring terbaru.
            $pdo->prepare("UPDATE rkjm_programs SET status = ? WHERE id = ?")->execute([$status, $programId]);
            $pdo->commit();

            logActivity($pdo, $userId, 'Simpan Monitoring RKJM', $prog['nama_program']);
            rkjmJson(['success' => true, 'message' => 'Monitoring disimpan.', 'id' => $id]);
        }

        case 'delete_monitoring': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE m FROM rkjm_monitoring m INNER JOIN rkjm_programs p ON p.id = m.program_id WHERE m.id = ? AND p.document_id = ?");
            $stmt->execute([$id, $docId]);
            if ($stmt->rowCount() === 0) rkjmJson(['success' => false, 'message' => 'Catatan monitoring tidak ditemukan.'], 404);
            logActivity($pdo, $userId, 'Hapus Monitoring RKJM', 'Monitoring #' . $id);
            rkjmJson(['success' => true, 'message' => 'Catatan monitoring dihapus.']);
        }

        case 'template_get': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM rkjm_template_programs WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) rkjmJson(['success' => false, 'message' => 'Template tidak ditemukan.'], 404);
            rkjmJson(['success' => true, 'data' => $row]);
        }

        case 'template_save': {
            $id = (int)($_POST['id'] ?? 0);
            $nama = trim((string)($_POST['nama_program'] ?? ''));
            if ($nama === '') rkjmJson(['success' => false, 'message' => 'Nama program template wajib diisi.']);

            $fieldId = (int)($_POST['field_id'] ?? 0) ?: null;
            $bidangBaru = trim((string)($_POST['bidang_baru'] ?? ''));
            $bidangNama = '';
            if ($fieldId) {
                $fs = $pdo->prepare("SELECT nama FROM rkjm_fields WHERE id = ?");
                $fs->execute([$fieldId]);
                $bidangNama = (string)($fs->fetchColumn() ?: '');
                if ($bidangNama === '') $fieldId = null;
            }
            if ($bidangBaru !== '') {
                $cek = $pdo->prepare("SELECT id, nama FROM rkjm_fields WHERE LOWER(nama) = LOWER(?) LIMIT 1");
                $cek->execute([$bidangBaru]);
                $found = $cek->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $fieldId = (int)$found['id'];
                    $bidangNama = $found['nama'];
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
                $kode = 'TPL-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            }
            $anggaran = (float)preg_replace('/[^0-9]/', '', (string)($_POST['estimasi_anggaran'] ?? '0'));
            $isActive = isset($_POST['is_active']) ? (int)($_POST['is_active'] === '1' || $_POST['is_active'] === 'on') : 1;

            $data = [
                'kode' => $kode,
                'field_id' => $fieldId,
                'bidang_nama' => $bidangNama ?: 'Lainnya',
                'nama_program' => $nama,
                'latar_belakang' => trim((string)($_POST['latar_belakang'] ?? '')),
                'permasalahan' => trim((string)($_POST['permasalahan'] ?? '')),
                'tujuan' => trim((string)($_POST['tujuan'] ?? '')),
                'sasaran' => trim((string)($_POST['sasaran'] ?? '')),
                'indikator' => trim((string)($_POST['indikator'] ?? '')),
                'target_akhir' => trim((string)($_POST['target_akhir'] ?? '')),
                'target_tahun1' => trim((string)($_POST['target_tahun1'] ?? '')),
                'target_tahun2' => trim((string)($_POST['target_tahun2'] ?? '')),
                'target_tahun3' => trim((string)($_POST['target_tahun3'] ?? '')),
                'target_tahun4' => trim((string)($_POST['target_tahun4'] ?? '')),
                'kegiatan_utama' => trim((string)($_POST['kegiatan_utama'] ?? '')),
                'penanggung_jawab' => trim((string)($_POST['penanggung_jawab'] ?? '')),
                'estimasi_anggaran' => $anggaran,
                'sumber_dana' => trim((string)($_POST['sumber_dana'] ?? '')),
                'dokumen_bukti' => trim((string)($_POST['dokumen_bukti'] ?? '')),
                'monitoring_evaluasi' => trim((string)($_POST['monitoring_evaluasi'] ?? '')),
                'catatan' => trim((string)($_POST['catatan'] ?? '')),
                'is_active' => $isActive,
            ];

            if ($id > 0) {
                $sets = [];
                $params = [];
                foreach ($data as $k => $v) { $sets[] = "$k = ?"; $params[] = $v; }
                $params[] = $id;
                $pdo->prepare("UPDATE rkjm_template_programs SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                $msg = 'Template diperbarui. Perubahan tidak mengubah program RKJM yang sudah disalin.';
            } else {
                $cols = array_keys($data);
                $place = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO rkjm_template_programs (" . implode(',', $cols) . ") VALUES ($place)")->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
                $msg = 'Template ditambahkan.';
            }
            logActivity($pdo, $userId, 'Simpan Template RKJM', $nama);
            rkjmJson(['success' => true, 'message' => $msg, 'id' => $id]);
        }

        case 'template_toggle': {
            $id = (int)($_POST['id'] ?? 0);
            $active = (int)($_POST['is_active'] ?? 0) ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE rkjm_template_programs SET is_active = ? WHERE id = ?");
            $stmt->execute([$active, $id]);
            if ($stmt->rowCount() === 0) rkjmJson(['success' => false, 'message' => 'Template tidak ditemukan.']);
            logActivity($pdo, $userId, 'Ubah Status Template RKJM', 'Template #' . $id . ' => ' . ($active ? 'aktif' : 'nonaktif'));
            rkjmJson(['success' => true, 'message' => $active ? 'Template diaktifkan.' : 'Template dinonaktifkan.']);
        }

        case 'template_delete': {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, nama_program FROM rkjm_template_programs WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) rkjmJson(['success' => false, 'message' => 'Template tidak ditemukan.'], 404);

            $used = $pdo->prepare("SELECT COUNT(*) FROM rkjm_programs WHERE template_id = ?");
            $used->execute([$id]);
            $usedCount = (int)$used->fetchColumn();

            // Program RKJM hasil salinan tetap dipertahankan.
            $pdo->prepare("UPDATE rkjm_programs SET template_id = NULL WHERE template_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM rkjm_template_programs WHERE id = ?")->execute([$id]);
            logActivity($pdo, $userId, 'Hapus Template RKJM', (string)$row['nama_program']);
            rkjmJson([
                'success' => true,
                'message' => 'Template dihapus.' . ($usedCount > 0 ? ' ' . $usedCount . ' program RKJM hasil salinan tetap tersimpan.' : ''),
                'used_count' => $usedCount,
            ]);
        }

        default:
            rkjmJson(['success' => false, 'message' => 'Aksi tidak dikenal.'], 400);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('RKJM ajax error: ' . $e->getMessage());
    rkjmJson(['success' => false, 'message' => 'Terjadi kesalahan saat memproses data. Silakan coba lagi.'], 500);
}
