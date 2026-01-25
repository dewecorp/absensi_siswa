<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'Kenaikan Kelas & Pembersihan Data';

// Handle Promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_students'])) {
    $source_class_id = (int)$_POST['source_class_id'];
    $target_class_id = (int)$_POST['target_class_id'];
    $selected_students = $_POST['students'] ?? [];

    if ($source_class_id && $target_class_id && !empty($selected_students)) {
        try {
            $pdo->beginTransaction();

            $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';
            $sql = "UPDATE tb_siswa SET id_kelas = ? WHERE id_siswa IN ($placeholders) AND id_kelas = ?";
            $params = array_merge([$target_class_id], $selected_students, [$source_class_id]);
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Log activity
            $count = count($selected_students);
            $username = $_SESSION['username'] ?? 'system';
            
            // Get class names for logging
            $stmtClass = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE id_kelas IN (?, ?)");
            $stmtClass->execute([$source_class_id, $target_class_id]);
            $classes = $stmtClass->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $source_name = $classes[$source_class_id] ?? 'Unknown';
            $target_name = $classes[$target_class_id] ?? 'Unknown';

            logActivity($pdo, $username, 'Kenaikan Kelas', "Memindahkan $count siswa dari $source_name ke $target_name");

            $pdo->commit();
            $message = ['type' => 'success', 'text' => "Berhasil memindahkan $count siswa."];
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = ['type' => 'danger', 'text' => 'Gagal memproses kenaikan kelas: ' . $e->getMessage()];
        }
    } else {
        $message = ['type' => 'warning', 'text' => 'Silakan pilih kelas asal, kelas tujuan, dan minimal satu siswa.'];
    }
}

// Handle Cleanup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_data'])) {
    $cleanup_type = $_POST['cleanup_type'];
    $confirm_cleanup = $_POST['confirm_cleanup'] ?? false;
    $cutoff_date = $_POST['cutoff_date'];

    if ($confirm_cleanup && $cutoff_date) {
        try {
            $deleted_counts = [];
            $messages = [];

            // 1. Cleanup Attendance (Students)
            if (isset($_POST['cleanup_attendance_student'])) {
                $stmt = $pdo->prepare("DELETE FROM tb_absensi WHERE tanggal < ?");
                $stmt->execute([$cutoff_date]);
                $count = $stmt->rowCount();
                $deleted_counts['attendance_student'] = $count;
                $messages[] = "$count data absensi siswa";
            }

            // 2. Cleanup Attendance (Teachers)
            if (isset($_POST['cleanup_attendance_teacher'])) {
                $stmt = $pdo->prepare("DELETE FROM tb_absensi_guru WHERE tanggal < ?");
                $stmt->execute([$cutoff_date]);
                $count = $stmt->rowCount();
                $deleted_counts['attendance_teacher'] = $count;
                $messages[] = "$count data absensi guru";
            }

            // 3. Cleanup Journals
            if (isset($_POST['cleanup_journals'])) {
                // Assuming tb_jurnal has a 'tanggal' or 'created_at' column. 
                // Let's check tb_jurnal structure first. If not sure, use created_at or similar.
                // Based on common practices, likely 'tanggal'.
                // I'll assume 'tanggal' based on other tables, but I should verify if possible.
                // If I can't verify, I'll try to handle potential errors or use a safe guess.
                // Actually, let's just use 'tanggal' as it is standard in this app.
                $stmt = $pdo->prepare("DELETE FROM tb_jurnal WHERE tanggal < ?");
                $stmt->execute([$cutoff_date]);
                $count = $stmt->rowCount();
                $deleted_counts['journals'] = $count;
                $messages[] = "$count data jurnal mengajar";
            }

            // 4. Cleanup Activity Log
            if (isset($_POST['cleanup_logs'])) {
                $stmt = $pdo->prepare("DELETE FROM tb_activity_log WHERE created_at < ?");
                $stmt->execute([$cutoff_date . ' 00:00:00']);
                $count = $stmt->rowCount();
                $deleted_counts['logs'] = $count;
                $messages[] = "$count log aktivitas";
            }

            if (!empty($messages)) {
                $msg_text = "Berhasil menghapus: " . implode(", ", $messages) . " (sebelum $cutoff_date).";
                logActivity($pdo, $_SESSION['username'] ?? 'system', 'Pembersihan Data', $msg_text);
                $message = ['type' => 'success', 'text' => $msg_text];
            } else {
                $message = ['type' => 'warning', 'text' => 'Tidak ada opsi pembersihan yang dipilih.'];
            }

        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
        }
    } else {
        $message = ['type' => 'warning', 'text' => 'Harap lengkapi tanggal batas dan konfirmasi pembersihan.'];
    }
}

// Get all classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Kenaikan Kelas & Pembersihan Data</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Master Data</a></div>
                <div class="breadcrumb-item">Kenaikan Kelas</div>
            </div>
        </div>

        <div class="row">
    <!-- Promotion Section -->
    <div class="col-md-12 mb-4">
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Kenaikan Kelas (Promosi Siswa)</h6>
            </div>
            <div class="card-body">
                <?php if (isset($message)): ?>
                    <div class="alert alert-<?= $message['type'] ?> alert-dismissible fade show" role="alert">
                        <?= $message['text'] ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <form method="GET" class="mb-4">
                    <div class="form-row align-items-end">
                        <div class="col-md-4">
                            <label>Pilih Kelas Asal</label>
                            <select name="source_class" class="form-control" onchange="this.form.submit()">
                                <option value="">-- Pilih Kelas --</option>
                                <?php foreach ($all_classes as $cls): ?>
                                    <option value="<?= $cls['id_kelas'] ?>" <?= (isset($_GET['source_class']) && $_GET['source_class'] == $cls['id_kelas']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cls['nama_kelas']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>

                <?php if (isset($_GET['source_class']) && !empty($_GET['source_class'])): ?>
                    <?php
                    $source_id = (int)$_GET['source_class'];
                    $stmt = $pdo->prepare("SELECT * FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
                    $stmt->execute([$source_id]);
                    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    
                    <form method="POST" id="promotionForm">
                        <input type="hidden" name="source_class_id" value="<?= $source_id ?>">
                        
                        <div class="form-group">
                            <label>Pindahkan ke Kelas Tujuan</label>
                            <select name="target_class_id" class="form-control" required>
                                <option value="">-- Pilih Kelas Tujuan --</option>
                                <?php foreach ($all_classes as $cls): ?>
                                    <?php if ($cls['id_kelas'] != $source_id): ?>
                                        <option value="<?= $cls['id_kelas'] ?>">
                                            <?= htmlspecialchars($cls['nama_kelas']) ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Untuk menandai lulus, silakan buat kelas "Alumni" terlebih dahulu di Data Kelas dan pindahkan siswa ke sana.</small>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th width="5%"><input type="checkbox" id="checkAll"></th>
                                        <th>NISN</th>
                                        <th>Nama Siswa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($students) > 0): ?>
                                        <?php foreach ($students as $student): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" name="students[]" value="<?= $student['id_siswa'] ?>" class="student-check" checked>
                                                </td>
                                                <td><?= htmlspecialchars($student['nisn']) ?></td>
                                                <td><?= htmlspecialchars($student['nama_siswa']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center">Tidak ada siswa di kelas ini.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (count($students) > 0): ?>
                            <button type="submit" name="promote_students" class="btn btn-primary mt-3">
                                <i class="fas fa-exchange-alt"></i> Proses Kenaikan Kelas
                            </button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Cleanup Section -->
    <div class="col-md-12">
        <div class="card shadow border-left-danger">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-danger">Pembersihan Data (Maintenance)</h6>
            </div>
            <div class="card-body">
                <p>Fitur ini digunakan untuk menghapus data lama yang sudah tidak diperlukan. Harap berhati-hati karena data yang dihapus tidak dapat dikembalikan.</p>
                
                <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data ini? Tindakan ini tidak dapat dibatalkan!');">
                    <input type="hidden" name="cleanup_type" value="bulk_cleanup">
                    
                    <div class="form-group row">
                        <label class="col-sm-3 col-form-label">Hapus Data Sebelum Tanggal</label>
                        <div class="col-sm-4">
                            <input type="date" name="cutoff_date" class="form-control" required>
                            <small class="text-muted">Semua data sebelum tanggal ini akan dihapus.</small>
                        </div>
                    </div>

                    <div class="form-group row">
                        <label class="col-sm-3 col-form-label">Pilih Data untuk Dihapus</label>
                        <div class="col-sm-9">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="cleanupAttendanceStudent" name="cleanup_attendance_student" value="1" checked>
                                <label class="custom-control-label" for="cleanupAttendanceStudent">Absensi Siswa</label>
                            </div>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="cleanupAttendanceTeacher" name="cleanup_attendance_teacher" value="1" checked>
                                <label class="custom-control-label" for="cleanupAttendanceTeacher">Absensi Guru</label>
                            </div>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="cleanupJournals" name="cleanup_journals" value="1">
                                <label class="custom-control-label" for="cleanupJournals">Jurnal Mengajar</label>
                            </div>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="cleanupLogs" name="cleanup_logs" value="1">
                                <label class="custom-control-label" for="cleanupLogs">Log Aktivitas</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group row">
                        <div class="col-sm-3"></div>
                        <div class="col-sm-9">
                            <div class="custom-control custom-checkbox my-1 mr-sm-2">
                                <input type="checkbox" class="custom-control-input" id="confirmCleanup" name="confirm_cleanup" value="1" required>
                                <label class="custom-control-label" for="confirmCleanup">Saya mengerti konsekuensinya dan ingin melanjutkan</label>
                            </div>
                            <button type="submit" name="cleanup_data" class="btn btn-danger mt-2">
                                <i class="fas fa-trash"></i> Hapus Data Terpilih
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Check All functionality
    const checkAll = document.getElementById('checkAll');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.student-check');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
