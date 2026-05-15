<?php
require_once '../config/database.php';
require_once '../config/functions.php';

ensure_nilai_semester_enum_ujian_praktik($pdo);
ensure_nilai_semester_setting_minmax($pdo);

if (!isAuthorized(['guru', 'wali'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_grade') {
        $id_siswa = $_POST['id_siswa'];
        $id_kelas = $_POST['id_kelas'];
        $id_mapel = $_POST['id_mapel'];
        $jenis_semester = isset($_POST['jenis_semester']) ? (string)$_POST['jenis_semester'] : '';
        $jenis_semester = normalize_jenis_semester_param($jenis_semester);
        if ($jenis_semester === null) {
            echo json_encode(['status' => 'error', 'message' => 'Jenis nilai tidak valid']);
            exit;
        }
        $nilai_asli = isset($_POST['nilai_asli']) && $_POST['nilai_asli'] !== '' ? floatval($_POST['nilai_asli']) : 0;
        $nilai_remidi = isset($_POST['nilai_remidi']) && $_POST['nilai_remidi'] !== '' ? floatval($_POST['nilai_remidi']) : 0;
        if ($jenis_semester === 'Ujian Praktik') {
            $nilai_remidi = 0;
        }
        if ($nilai_asli < 0 || $nilai_asli > 99) {
            echo json_encode(['status' => 'error', 'message' => 'Nilai asli harus 0 s.d 99']);
            exit;
        }
        if ($nilai_remidi < 0 || $nilai_remidi > 99) {
            echo json_encode(['status' => 'error', 'message' => 'Nilai remidi harus 0 s.d 99']);
            exit;
        }
        $min_target = null;
        $max_target = null;
        if (isset($_POST['nilai_min_target']) || isset($_POST['nilai_max_target'])) {
            $min_raw = $_POST['nilai_min_target'] ?? null;
            $max_raw = $_POST['nilai_max_target'] ?? null;
            if ($min_raw !== null && $min_raw !== '') {
                if (!is_numeric($min_raw)) {
                    echo json_encode(['status' => 'error', 'message' => 'Nilai terendah tidak valid']);
                    exit;
                }
                $min_target = (float)$min_raw;
            }
            if ($max_raw !== null && $max_raw !== '') {
                if (!is_numeric($max_raw)) {
                    echo json_encode(['status' => 'error', 'message' => 'Nilai tertinggi tidak valid']);
                    exit;
                }
                $max_target = (float)$max_raw;
            }
        }

        // Get teacher info
        $id_guru = $_SESSION['user_id'];
        if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
            $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $id_guru = $stmt->fetchColumn();
        }
        if ((!$id_guru || $id_guru == 0) && isset($_SESSION['nama_guru'])) {
            $stmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE nama_guru = ? LIMIT 1");
            $stmt->execute([$_SESSION['nama_guru']]);
            $id_guru = $stmt->fetchColumn();
        }

        // Get active semester info
        $school_profile = getSchoolProfile($pdo);
        $tahun_ajaran = $school_profile['tahun_ajaran'];
        $semester_aktif = $school_profile['semester'];

        // Get KKTP
        $stmt = $pdo->prepare("SELECT kktp FROM tb_mata_pelajaran WHERE id_mapel = ?");
        $stmt->execute([$id_mapel]);
        $kktp = $stmt->fetchColumn();
        $kktp = $kktp ? floatval($kktp) : 0;

        if ($min_target === null && $max_target === null) {
            $setting = get_nilai_semester_setting_minmax($pdo, (int)$id_kelas, (int)$id_mapel, $jenis_semester, (string)$tahun_ajaran, (string)$semester_aktif);
            $min_target = $setting['nilai_min_target'];
            $max_target = $setting['nilai_max_target'];
        } else {
            if ($min_target !== null) {
                if ($kktp > 0 && $min_target < $kktp) {
                    echo json_encode(['status' => 'error', 'message' => 'Nilai terendah tidak boleh di bawah KKTP (' . $kktp . ')']);
                    exit;
                }
                if ($min_target > 99) {
                    echo json_encode(['status' => 'error', 'message' => 'Nilai terendah tidak boleh lebih dari 99']);
                    exit;
                }
            }
            if ($max_target !== null) {
                if ($max_target > 99) {
                    echo json_encode(['status' => 'error', 'message' => 'Nilai tertinggi tidak boleh lebih dari 99']);
                    exit;
                }
                if ($kktp > 0 && $max_target < $kktp) {
                    echo json_encode(['status' => 'error', 'message' => 'Nilai tertinggi tidak boleh di bawah KKTP (' . $kktp . ')']);
                    exit;
                }
            }
            if ($min_target !== null && $max_target !== null && $min_target > $max_target) {
                echo json_encode(['status' => 'error', 'message' => 'Nilai terendah tidak boleh lebih besar dari nilai tertinggi']);
                exit;
            }

            upsert_nilai_semester_setting_minmax(
                $pdo,
                (int)$id_kelas,
                (int)$id_mapel,
                $jenis_semester,
                (string)$tahun_ajaran,
                (string)$semester_aktif,
                $min_target,
                $max_target,
                $id_guru ? (int)$id_guru : null
            );
        }

        $floor = $kktp > 0 ? $kktp : 0;
        if ($min_target !== null) {
            $floor = $min_target;
        }
        $maxVal = 99.0;
        if ($max_target !== null) {
            $maxVal = $max_target;
        }
        if ($maxVal > 99) {
            $maxVal = 99;
        }

        $compute_nilai_jadi = function(float $tempJadi, float $floorVal, float $maxValLocal, float $inputMax) {
            if ($tempJadi <= 0) {
                return 0.0;
            }
            $nilaiJadi = $tempJadi;
            if ($floorVal > 0 && $tempJadi < $floorVal) {
                $nilaiJadi = $floorVal;
            } else {
                $range = $maxValLocal - $floorVal;
                $inputRange = $inputMax - $floorVal;
                if ($range > 0 && $inputRange > 0) {
                    $ratio = ($tempJadi - $floorVal) / $inputRange;
                    if ($ratio < 0) $ratio = 0;
                    if ($ratio > 1) $ratio = 1;
                    $ratioBoosted = 1 - pow(1 - $ratio, 2);
                    $curve = $floorVal + ($range * $ratioBoosted);
                    $curve = round($curve);
                    $nilaiJadi = $curve < $tempJadi ? $tempJadi : $curve;
                }
            }
            if ($nilaiJadi > $maxValLocal) {
                $nilaiJadi = $maxValLocal;
            }
            $nilaiJadi = round($nilaiJadi);
            if ($nilaiJadi > 99) $nilaiJadi = 99;
            return (float)$nilaiJadi;
        };

        $temp_jadi = ($nilai_remidi > $nilai_asli) ? $nilai_remidi : $nilai_asli;
        $inputMaxDefault = 100.0;
        $nilai_jadi = $compute_nilai_jadi((float)$temp_jadi, (float)$floor, (float)$maxVal, $inputMaxDefault);

        try {
            $pdo->beginTransaction();

            // Check if record exists
            $stmt = $pdo->prepare("
                SELECT id_nilai FROM tb_nilai_semester 
                WHERE id_siswa = ? 
                AND id_mapel = ? 
                AND jenis_semester = ? 
                AND tahun_ajaran = ? 
                AND semester = ?
            ");
            $stmt->execute([$id_siswa, $id_mapel, $jenis_semester, $tahun_ajaran, $semester_aktif]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Update
                $stmt = $pdo->prepare("
                    UPDATE tb_nilai_semester 
                    SET nilai_asli = ?, 
                        nilai_remidi = ?, 
                        nilai_jadi = ?, 
                        id_guru = ?,
                        id_kelas = ?
                    WHERE id_nilai = ?
                ");
                $stmt->execute([$nilai_asli, $nilai_remidi, $nilai_jadi, $id_guru, $id_kelas, $existing['id_nilai']]);
            } else {
                // Insert
                $stmt = $pdo->prepare("
                    INSERT INTO tb_nilai_semester 
                    (id_siswa, id_mapel, id_kelas, id_guru, jenis_semester, tahun_ajaran, semester, nilai_asli, nilai_remidi, nilai_jadi) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$id_siswa, $id_mapel, $id_kelas, $id_guru, $jenis_semester, $tahun_ajaran, $semester_aktif, $nilai_asli, $nilai_remidi, $nilai_jadi]);
            }

            $recalc_all = ($min_target !== null || $max_target !== null);
            if ($recalc_all) {
                $stmt = $pdo->prepare("
                    SELECT id_nilai, id_siswa, nilai_asli, nilai_remidi
                    FROM tb_nilai_semester
                    WHERE id_mapel = ?
                      AND id_kelas = ?
                      AND jenis_semester = ?
                      AND tahun_ajaran = ?
                      AND semester = ?
                ");
                $stmt->execute([$id_mapel, $id_kelas, $jenis_semester, $tahun_ajaran, $semester_aktif]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $observedMax = 0.0;
                foreach ($rows as $r) {
                    $a = (float)($r['nilai_asli'] ?? 0);
                    $rm = (float)($r['nilai_remidi'] ?? 0);
                    if ($jenis_semester === 'Ujian Praktik') {
                        $rm = 0.0;
                    }
                    $t = ($rm > $a) ? $rm : $a;
                    if ($t > $observedMax) {
                        $observedMax = $t;
                    }
                }
                $inputMax = ($max_target !== null) ? ($observedMax > 0 ? $observedMax : 100.0) : 100.0;

                $stmtUpd = $pdo->prepare("UPDATE tb_nilai_semester SET nilai_jadi = ? WHERE id_nilai = ?");
                foreach ($rows as $r) {
                    $a = (float)($r['nilai_asli'] ?? 0);
                    $rm = (float)($r['nilai_remidi'] ?? 0);
                    if ($jenis_semester === 'Ujian Praktik') {
                        $rm = 0.0;
                    }
                    $t = ($rm > $a) ? $rm : $a;
                    $nj = $compute_nilai_jadi((float)$t, (float)$floor, (float)$maxVal, (float)$inputMax);
                    $stmtUpd->execute([$nj > 0 ? $nj : 0, $r['id_nilai']]);
                    if ((string)$r['id_siswa'] === (string)$id_siswa) {
                        $nilai_jadi = $nj;
                    }
                }
            }

            $pdo->commit();

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'nilai_asli' => $nilai_asli,
                    'nilai_remidi' => $nilai_remidi,
                    'nilai_jadi' => $nilai_jadi,
                    'kktp' => $kktp,
                    'nilai_min_target' => $min_target,
                    'nilai_max_target' => $max_target,
                    'recalc_all' => ($min_target !== null || $max_target !== null)
                ]
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
?>
