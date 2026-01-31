<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['guru', 'wali'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_grade') {
        $id_siswa = $_POST['id_siswa'];
        $id_kelas = $_POST['id_kelas'];
        $id_mapel = $_POST['id_mapel'];
        $jenis_semester = $_POST['jenis_semester'];
        $nilai_asli = isset($_POST['nilai_asli']) && $_POST['nilai_asli'] !== '' ? floatval($_POST['nilai_asli']) : 0;
        $nilai_remidi = isset($_POST['nilai_remidi']) && $_POST['nilai_remidi'] !== '' ? floatval($_POST['nilai_remidi']) : 0;

        // Get teacher info
        $id_guru = $_SESSION['user_id'];
        if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
            $stmt = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $id_guru = $stmt->fetchColumn();
        }

        // Get active semester info
        $school_profile = getSchoolProfile($pdo);
        $tahun_ajaran = $school_profile['tahun_ajaran'];
        $semester_aktif = $school_profile['semester'];

        // Logic: Nilai Jadi = Max(Nilai Asli, Nilai Remidi)
        // If Remidi is 0, Nilai Jadi is Nilai Asli
        $nilai_jadi = ($nilai_remidi > $nilai_asli) ? $nilai_remidi : $nilai_asli;

        try {
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

            echo json_encode(['status' => 'success']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
?>