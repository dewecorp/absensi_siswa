<?php
require_once '../config/database.php';
require_once '../config/functions.php';

ensure_nilai_kokurikuler_header_minmax($pdo);

// Check auth
if (!isAuthorized(['guru', 'wali'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Get logged in teacher ID
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

    $kktp_of_mapel = function($id_mapel) use ($pdo) {
        $stmt = $pdo->prepare("SELECT kktp FROM tb_mata_pelajaran WHERE id_mapel = ?");
        $stmt->execute([$id_mapel]);
        $kktp = $stmt->fetchColumn();
        $kktp = $kktp ? (float)$kktp : 0;
        return $kktp > 0 ? $kktp : 0;
    };

    $normalize_float_or_null = function($v) {
        if (!isset($v) || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        return (float)$v;
    };

    $compute_nilai_jadi = function($nilai, float $kktp, ?float $minTarget, ?float $maxTarget, float $inputMin, float $inputMax) {
        if ($nilai === null) {
            return null;
        }
        $n = (float)$nilai;
        if ($n <= 0) {
            return null;
        }

        $floor = $kktp > 0 ? $kktp : 0;
        if ($minTarget !== null) {
            $floor = $minTarget;
        }

        $maxVal = 99.0;
        if ($maxTarget !== null) {
            $maxVal = $maxTarget;
        }
        if ($maxVal > 99) {
            $maxVal = 99;
        }

        $maxDesired = $maxVal;
        $minDesired = $floor;
        $maxOriginal = $inputMax;
        $minOriginal = $inputMin;

        if ($maxOriginal > $minOriginal) {
            // Formula: Nilai = P * Original + Q
            // P = (maxDesired - minDesired) / (maxOriginal - minOriginal)
            // Q = maxDesired - P * maxOriginal
            $P = ($maxDesired - $minDesired) / ($maxOriginal - $minOriginal);
            $Q = $maxDesired - ($P * $maxOriginal);
            $nilaiJadi = ($P * $n) + $Q;
        } else {
            $nilaiJadi = ($n >= $minDesired) ? $maxDesired : $minDesired;
        }

        // Clamp
        if ($nilaiJadi > $maxVal) {
            $nilaiJadi = $maxVal;
        }
        if ($nilaiJadi < $floor && $n >= $floor) {
            $nilaiJadi = $floor;
        }

        $nilaiJadi = round($nilaiJadi);
        if ($nilaiJadi > 99) $nilaiJadi = 99;
        
        return $nilaiJadi;
    };

    try {
        if ($action == 'add_column') {
            $id_kelas = $_POST['id_kelas'];
            $id_mapel = $_POST['id_mapel'];
            $nama = $_POST['nama_penilaian'];
            $jenis_kegiatan = $_POST['jenis_kegiatan'] ?? null;
            $tgl_kegiatan = !empty($_POST['tgl_kegiatan']) ? $_POST['tgl_kegiatan'] : null;
            $min_target = $normalize_float_or_null($_POST['nilai_min_target'] ?? null);
            $max_target = $normalize_float_or_null($_POST['nilai_max_target'] ?? null);

            $kktp = $kktp_of_mapel($id_mapel);
            if ($min_target !== null) {
                if ($kktp > 0 && $min_target < $kktp) {
                    echo json_encode(['success' => false, 'message' => 'Nilai terendah tidak boleh di bawah KKTP (' . $kktp . ')']);
                    exit;
                }
            }
            if ($max_target !== null) {
                if ($kktp > 0 && $max_target < $kktp) {
                    echo json_encode(['success' => false, 'message' => 'Nilai tertinggi tidak boleh di bawah KKTP (' . $kktp . ')']);
                    exit;
                }
            }
            if ($min_target !== null && $max_target !== null && $min_target > $max_target) {
                echo json_encode(['success' => false, 'message' => 'Nilai terendah tidak boleh lebih besar dari nilai tertinggi']);
                exit;
            }
            
            // Get active semester info
            $school_profile = getSchoolProfile($pdo);
            $tahun_ajaran = $school_profile['tahun_ajaran'];
            $semester = $school_profile['semester'];
            
            $stmt = $pdo->prepare("INSERT INTO tb_nilai_kokurikuler_header (id_guru, id_kelas, id_mapel, nama_penilaian, jenis_kegiatan, tgl_kegiatan, nilai_min_target, nilai_max_target, tahun_ajaran, semester) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$id_guru, $id_kelas, $id_mapel, $nama, $jenis_kegiatan, $tgl_kegiatan, $min_target, $max_target, $tahun_ajaran, $semester])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
        } 
        elseif ($action == 'delete_column') {
            $id_header = $_POST['id_header'];
            
            // Verify ownership
            $check = $pdo->prepare("SELECT id_header FROM tb_nilai_kokurikuler_header WHERE id_header = ? AND id_guru = ?");
            $check->execute([$id_header, $id_guru]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM tb_nilai_kokurikuler_header WHERE id_header = ?");
            if ($stmt->execute([$id_header])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
        }
        elseif ($action == 'save_grades') {
            $id_header = $_POST['id_header'];
            $grades = isset($_POST['grades']) ? $_POST['grades'] : [];
            $jenis_kegiatan = isset($_POST['jenis_kegiatan']) ? $_POST['jenis_kegiatan'] : null;
            $tgl_kegiatan = !empty($_POST['tgl_kegiatan']) ? $_POST['tgl_kegiatan'] : null;
            $nama = isset($_POST['nama_penilaian']) ? trim((string)$_POST['nama_penilaian']) : null;
            $min_target = $normalize_float_or_null($_POST['nilai_min_target'] ?? null);
            $max_target = $normalize_float_or_null($_POST['nilai_max_target'] ?? null);
            
            // Verify ownership
            $check = $pdo->prepare("SELECT * FROM tb_nilai_kokurikuler_header WHERE id_header = ? AND id_guru = ? LIMIT 1");
            $check->execute([$id_header, $id_guru]);
            $headerRow = $check->fetch(PDO::FETCH_ASSOC);
            if (!$headerRow) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }

            $id_mapel = $headerRow['id_mapel'];
            $kktp = $kktp_of_mapel($id_mapel);
            if ($min_target !== null) {
                if ($kktp > 0 && $min_target < $kktp) {
                    echo json_encode(['success' => false, 'message' => 'Nilai terendah tidak boleh di bawah KKTP (' . $kktp . ')']);
                    exit;
                }
            }
            if ($max_target !== null) {
                if ($kktp > 0 && $max_target < $kktp) {
                    echo json_encode(['success' => false, 'message' => 'Nilai tertinggi tidak boleh di bawah KKTP (' . $kktp . ')']);
                    exit;
                }
            }
            if ($min_target !== null && $max_target !== null && $min_target > $max_target) {
                echo json_encode(['success' => false, 'message' => 'Nilai terendah tidak boleh lebih besar dari nilai tertinggi']);
                exit;
            }
            
            $pdo->beginTransaction();
            
            if ($jenis_kegiatan !== null || $tgl_kegiatan !== null || $nama !== null || array_key_exists('nilai_min_target', $_POST) || array_key_exists('nilai_max_target', $_POST)) {
                if ($nama !== null && $nama === '') {
                    echo json_encode(['success' => false, 'message' => 'Nama penilaian tidak boleh kosong']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE tb_nilai_kokurikuler_header SET nama_penilaian = COALESCE(?, nama_penilaian), jenis_kegiatan = COALESCE(?, jenis_kegiatan), tgl_kegiatan = COALESCE(?, tgl_kegiatan), nilai_min_target = ?, nilai_max_target = ? WHERE id_header = ?");
                $stmt->execute([$nama, $jenis_kegiatan, $tgl_kegiatan, $min_target, $max_target, $id_header]);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO tb_nilai_kokurikuler_detail (id_header, id_siswa, nilai, nilai_jadi) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE nilai = VALUES(nilai), nilai_jadi = VALUES(nilai_jadi)
            ");

            $updatedGrades = [];
            $observedMax = 0.0;
            $observedMin = 100.0;
            $hasScores = false;

            foreach ($grades as $g) {
                if (isset($g['nilai']) && $g['nilai'] !== '') {
                    $v = $normalize_float_or_null($g['nilai']);
                    if ($v !== null && $v > 0) {
                        if ($v > $observedMax) $observedMax = $v;
                        if ($v < $observedMin) $observedMin = $v;
                        $hasScores = true;
                    }
                }
            }

            $inputMin = $hasScores ? $observedMin : 0.0;
            $inputMax = $hasScores ? ($observedMax > 0 ? $observedMax : 100.0) : 100.0;

            if ($inputMax <= $inputMin) {
                $inputMin = 0.0;
                $inputMax = 100.0;
            }
            
            foreach ($grades as $g) {
                $nilai = isset($g['nilai']) && $g['nilai'] !== '' ? $normalize_float_or_null($g['nilai']) : null;
                if ($nilai !== null) {
                    if ($nilai < 0) {
                        throw new Exception('Nilai tidak boleh kurang dari 0');
                    }
                }

                $nilai_jadi = $compute_nilai_jadi($nilai, $kktp, $min_target, $max_target, $inputMin, $inputMax);
                $stmt->execute([$id_header, $g['id_siswa'], $nilai, $nilai_jadi]);
                $updatedGrades[] = [
                    'id_siswa' => $g['id_siswa'],
                    'nilai_jadi' => $nilai_jadi
                ];
            }
            
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'data' => [
                    'kktp' => $kktp,
                    'nama_penilaian' => $nama,
                    'jenis_kegiatan' => $jenis_kegiatan,
                    'tgl_kegiatan' => $tgl_kegiatan,
                    'nilai_min_target' => $min_target,
                    'nilai_max_target' => $max_target,
                    'grades' => $updatedGrades
                ]
            ]);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
