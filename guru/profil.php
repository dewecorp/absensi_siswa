<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/ai_helper.php';

if (!isAuthorized(['guru', 'wali'])) {
    redirect('../login.php');
}

ai_helper_schema($pdo);

$school_profile = getSchoolProfile($pdo);

if ($_SESSION['level'] == 'guru' || $_SESSION['level'] == 'wali') {
    $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT g.* FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$teacher) {
    redirect('../login.php');
}

if (!isset($_SESSION['nama_guru']) || empty($_SESSION['nama_guru'])) {
    $_SESSION['nama_guru'] = $teacher['nama_guru'];
}

$message = null;

// Handle profile update via modal (AJAX)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile_modal'])) {
    header('Content-Type: application/json');
    $nama_guru = trim((string)($_POST['nama_guru'] ?? ''));
    $jenis_kelamin = trim((string)($_POST['jenis_kelamin'] ?? ''));
    $tempat_lahir = trim((string)($_POST['tempat_lahir'] ?? ''));
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
    $tmt = !empty($_POST['tmt']) ? $_POST['tmt'] : null;

    if ($nama_guru === '') {
        echo json_encode(['success' => false, 'message' => 'Nama guru wajib diisi.']);
        exit;
    }
    if (!in_array($jenis_kelamin, ['Laki-laki', 'Perempuan'], true)) {
        echo json_encode(['success' => false, 'message' => 'Jenis kelamin tidak valid.']);
        exit;
    }
    if ($tanggal_lahir !== null) {
        $parts = explode('-', $tanggal_lahir);
        if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            echo json_encode(['success' => false, 'message' => 'Tanggal lahir tidak valid.']);
            exit;
        }
    }

    try {
        $stmt = $pdo->prepare("UPDATE tb_guru SET nama_guru=?, jenis_kelamin=?, tempat_lahir=?, tanggal_lahir=?, tmt=? WHERE id_guru=?");
        $stmt->execute([$nama_guru, $jenis_kelamin, ($tempat_lahir ?: null), $tanggal_lahir, $tmt, $teacher['id_guru']]);
        $_SESSION['nama_guru'] = $nama_guru;
        $_SESSION['nama'] = $nama_guru;
        logActivity($pdo, $teacher['nuptk'] ?? 'system', 'Ubah Profil', 'Guru memperbarui profil sendiri');
        echo json_encode(['success' => true, 'message' => 'Profil berhasil diperbarui!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan profil.']);
    }
    exit;
}

// Handle non-AJAX fallback
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $nama_guru = trim((string)($_POST['nama_guru'] ?? ''));
    $jenis_kelamin = trim((string)($_POST['jenis_kelamin'] ?? ''));
    $tempat_lahir = trim((string)($_POST['tempat_lahir'] ?? ''));
    $tanggal_lahir = !empty($_POST['tanggal_lahir']) ? $_POST['tanggal_lahir'] : null;
    $tmt = !empty($_POST['tmt']) ? $_POST['tmt'] : null;

    if ($nama_guru === '') {
        $message = ['type' => 'warning', 'text' => 'Nama guru wajib diisi.'];
    } elseif (!in_array($jenis_kelamin, ['Laki-laki', 'Perempuan'], true)) {
        $message = ['type' => 'warning', 'text' => 'Jenis kelamin tidak valid.'];
    } elseif ($tanggal_lahir !== null) {
        $parts = explode('-', $tanggal_lahir);
        if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            $message = ['type' => 'warning', 'text' => 'Tanggal lahir tidak valid.'];
        }
    }

    if ($message === null) {
        try {
            $stmt = $pdo->prepare("UPDATE tb_guru SET nama_guru=?, jenis_kelamin=?, tempat_lahir=?, tanggal_lahir=?, tmt=? WHERE id_guru=?");
            $stmt->execute([$nama_guru, $jenis_kelamin, ($tempat_lahir ?: null), $tanggal_lahir, $tmt, $teacher['id_guru']]);
            $_SESSION['nama_guru'] = $nama_guru;
            $_SESSION['nama'] = $nama_guru;
            $message = ['type' => 'success', 'text' => 'Profil berhasil diperbarui.'];
            logActivity($pdo, $teacher['nuptk'] ?? 'system', 'Ubah Profil', 'Guru memperbarui profil sendiri');
            $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
            $stmt->execute([$teacher['id_guru']]);
            $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menyimpan profil.'];
        }
    }
}

// Handle konektor AI guru (simpan kunci Gemini/OpenAI milik sendiri)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_konektor_ai'])) {
    $provider = in_array($_POST['ai_provider'] ?? '', ['gemini', 'openai'], true) ? $_POST['ai_provider'] : 'gemini';
    $gkey = trim((string)($_POST['ai_gemini_key'] ?? ''));
    $oemail = trim((string)($_POST['ai_openai_email'] ?? ''));
    $okey = trim((string)($_POST['ai_openai_key'] ?? ''));
    try {
        $stmt = $pdo->prepare("UPDATE tb_guru SET ai_provider=?, ai_gemini_key=?, ai_gemini_model=NULL, ai_openai_email=?, ai_openai_key=?, ai_openai_model=NULL WHERE id_guru=?");
        $stmt->execute([$provider, ($gkey !== '' ? $gkey : null), ($oemail !== '' ? $oemail : null), ($okey !== '' ? $okey : null), $teacher['id_guru']]);
        $message = ['type' => 'success', 'text' => 'Konektor AI berhasil disimpan.'];
        $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
        $stmt->execute([$teacher['id_guru']]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Gagal menyimpan konektor AI.'];
    }
}

// Handle tes koneksi AI guru (pakai nilai dari form saat itu, fallback ke tersimpan)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tes_konektor_ai'])) {
    $provider = in_array($_POST['ai_provider'] ?? '', ['gemini', 'openai'], true) ? $_POST['ai_provider'] : 'gemini';
    $cfg = ai_guru_config($pdo, (int)$teacher['id_guru']);
    if ($provider === 'openai') {
        $tkey = trim((string)($_POST['ai_openai_key'] ?? ''));
        $tmodel = trim((string)($_POST['ai_openai_model'] ?? ''));
        [$ok, $note] = ai_test_connection('openai', ($tkey !== '' ? $tkey : $cfg['openai_key']), ($tmodel !== '' ? $tmodel : $cfg['openai_model']));
    } else {
        $tkey = trim((string)($_POST['ai_gemini_key'] ?? ''));
        $tmodel = trim((string)($_POST['ai_gemini_model'] ?? ''));
        [$ok, $note] = ai_test_connection('gemini', ($tkey !== '' ? $tkey : $cfg['gemini_key']), ($tmodel !== '' ? $tmodel : $cfg['gemini_model']));
    }
    $message = ['type' => $ok ? 'success' : 'danger', 'text' => $note];
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ubah_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $message = ['type' => 'warning', 'text' => 'Harap lengkapi semua field!'];
    } elseif ($new_password !== $confirm_password) {
        $message = ['type' => 'warning', 'text' => 'Password baru dan konfirmasi password tidak sama!'];
    } elseif (strlen($new_password) < 6) {
        $message = ['type' => 'warning', 'text' => 'Password baru minimal 6 karakter!'];
    } else {
        if ($teacher['password'] && password_verify($current_password, $teacher['password'])) {
            $hashed_password = hashPassword($new_password);
            $stmt = $pdo->prepare("UPDATE tb_guru SET password=?, password_plain=? WHERE id_guru=?");
            if ($stmt->execute([$hashed_password, $new_password, $teacher['id_guru']])) {
                $message = ['type' => 'success', 'text' => 'Password berhasil diubah!'];
                logActivity($pdo, $teacher['nuptk'] ?? 'system', 'Ubah Password', 'Guru mengubah password sendiri');
                $stmt = $pdo->prepare("SELECT * FROM tb_guru WHERE id_guru = ?");
                $stmt->execute([$teacher['id_guru']]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengubah password!'];
            }
        } else {
            $message = ['type' => 'danger', 'text' => 'Password lama tidak benar!'];
        }
    }
}

$page_title = 'Profil & Pengaturan';
include '../templates/user_header.php';
?>
<style>
.profile-card .card-body {
    font-size: 12pt;
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Profil & Pengaturan</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Profil & Pengaturan</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12 col-lg-6 mb-4">
                    <div class="card profile-card shadow-sm h-100">
                        <div class="card-header">
                            <h4><i class="fas fa-id-card mr-2"></i>Data Diri</h4>
                            <div class="card-header-action">
                                <a href="#" class="text-primary" data-toggle="modal" data-target="#editProfileModal" title="Edit Profil">
                                    <i class="fas fa-pen fa-lg"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <?php echo getTeacherAvatarImage($teacher, 110); ?>
                                <h4 class="mt-2 mb-1 font-weight-bold"><?php echo htmlspecialchars($teacher['nama_guru']); ?></h4>
                                <span class="badge badge-primary"><?php echo htmlspecialchars($teacher['kode_guru'] ?? '-'); ?></span>
                                <span class="badge badge-info"><?php echo htmlspecialchars($teacher['pendidikan'] ?? '-'); ?></span>
                            </div>
                            <hr>
                            <div class="row mb-2">
                                <div class="col-5 font-weight-bold">Nama Lengkap</div>
                                <div class="col-7"><?php echo htmlspecialchars($teacher['nama_guru']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 font-weight-bold">Kode Guru</div>
                                <div class="col-7"><?php echo htmlspecialchars($teacher['kode_guru'] ?? '-'); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 font-weight-bold">NUPTK</div>
                                <div class="col-7"><?php echo htmlspecialchars($teacher['nuptk']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 font-weight-bold">Jenis Kelamin</div>
                                <div class="col-7"><?php echo htmlspecialchars($teacher['jenis_kelamin']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 font-weight-bold">Tempat Lahir</div>
                                <div class="col-7"><?php echo htmlspecialchars($teacher['tempat_lahir'] ?? '-'); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 font-weight-bold">Tanggal Lahir</div>
                                <div class="col-7"><?php echo !empty($teacher['tanggal_lahir']) ? date('d-m-Y', strtotime($teacher['tanggal_lahir'])) : '-'; ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 font-weight-bold">Pendidikan</div>
                                <div class="col-7"><?php echo htmlspecialchars(!empty($teacher['pendidikan']) ? $teacher['pendidikan'] : '-'); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 font-weight-bold">TMT</div>
                                <div class="col-7"><?php echo !empty($teacher['tmt']) ? date('d-m-Y', strtotime($teacher['tmt'])) : '-'; ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 font-weight-bold text-success">Masa Bakti</div>
                                <div class="col-7 font-weight-bold text-success"><?php echo calculateMasaBakti($teacher['tmt'] ?? null); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6 mb-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h4><i class="fas fa-key mr-2"></i>Ubah Password</h4>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="form-group">
                                    <label>Password Lama</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                <div class="form-group">
                                    <label>Password Baru</label>
                                    <input type="password" class="form-control" name="new_password" minlength="6" required>
                                    <small class="form-text text-muted">Minimal 6 karakter</small>
                                </div>
                                <div class="form-group">
                                    <label>Konfirmasi Password Baru</label>
                                    <input type="password" class="form-control" name="confirm_password" minlength="6" required>
                                </div>
                                <button type="submit" name="ubah_password" class="btn btn-primary"><i class="fas fa-key mr-2"></i>Ubah Password</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-12">
                    <?php $ai_cfg = ai_guru_config($pdo, (int)$teacher['id_guru']); ?>
                    <div class="card shadow-sm">
                        <div class="card-header">
                            <h4><i class="fas fa-robot mr-2"></i>Konektor AI (Akun Guru)</h4>
                            <div class="card-header-action">
                                <span class="badge badge-info"><?= htmlspecialchars($ai_cfg['provider'] === 'openai' ? 'ChatGPT' : 'Gemini') ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">Setiap guru memakai akun AI sendiri. Pilih penyedia favorit, isi API key, lalu Tes Koneksi.</p>
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label>Penyedia AI</label>
                                        <select name="ai_provider" class="form-control">
                                            <option value="gemini" <?= $ai_cfg['provider'] === 'gemini' ? 'selected' : '' ?>>Gemini (Google)</option>
                                            <option value="openai" <?= $ai_cfg['provider'] === 'openai' ? 'selected' : '' ?>>ChatGPT (OpenAI)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label>Gemini API Key</label>
                                        <input type="password" name="ai_gemini_key" class="form-control" value="<?= htmlspecialchars($ai_cfg['gemini_key']) ?>" placeholder="AIza...">
                                        <small class="text-muted d-block">Ambil gratis di <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio &raquo;</a></small>
                                        <small class="text-muted d-block">Model otomatis dipilih provider (tidak perlu diisi).</small>
                                    </div>
                                    <div class="col-md-4 form-group">
                                        <label>Email Akun ChatGPT</label>
                                        <input type="email" name="ai_openai_email" class="form-control" value="<?= htmlspecialchars($ai_cfg['openai_email']) ?>" placeholder="nama@email.com">
                                        <small class="text-muted d-block">API Key (wajib untuk generate):</small>
                                        <input type="password" name="ai_openai_key" class="form-control mt-1" value="<?= htmlspecialchars($ai_cfg['openai_key']) ?>" placeholder="sk-...">
                                        <small class="text-muted d-block">Lihat di <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com &raquo;</a></small>
                                        <small class="text-muted d-block">Model otomatis dipilih provider (tidak perlu diisi).</small>
                                    </div>
                                </div>
                                <div class="d-flex" style="gap:8px;">
                                    <button type="submit" name="simpan_konektor_ai" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Simpan Konektor</button>
                                    <button type="submit" name="tes_konektor_ai" class="btn btn-outline-info"><i class="fas fa-plug mr-1"></i>Tes Koneksi</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Profil</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <form id="editProfileForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="update_profile_modal" value="1">
                    <div class="form-group">
                        <label>Nama Guru</label>
                        <input type="text" class="form-control" name="nama_guru" value="<?php echo htmlspecialchars($teacher['nama_guru']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Jenis Kelamin</label>
                        <select class="form-control" name="jenis_kelamin" required>
                            <option value="Laki-laki" <?php echo $teacher['jenis_kelamin'] == 'Laki-laki' ? 'selected' : ''; ?>>Laki-laki</option>
                            <option value="Perempuan" <?php echo $teacher['jenis_kelamin'] == 'Perempuan' ? 'selected' : ''; ?>>Perempuan</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tempat Lahir</label>
                        <input type="text" class="form-control" name="tempat_lahir" value="<?php echo htmlspecialchars($teacher['tempat_lahir'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Lahir</label>
                        <input type="date" class="form-control" name="tanggal_lahir" value="<?php echo !empty($teacher['tanggal_lahir']) ? $teacher['tanggal_lahir'] : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Mulai Tugas (TMT)</label>
                        <input type="date" class="form-control" name="tmt" id="modalTmt" value="<?php echo !empty($teacher['tmt']) ? $teacher['tmt'] : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Masa Bakti</label>
                        <input type="text" class="form-control" id="modalMasaBakti" readonly value="<?php echo calculateMasaBakti($teacher['tmt'] ?? null); ?>">
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$js_page = [];

if ($message) {
    $js_page[] = "
    $(document).ready(function() {
        Swal.fire({
            title: " . json_encode($message['type'] === 'success' ? 'Berhasil!' : 'Perhatian!') . ",
            text: " . json_encode((string)$message['text']) . ",
            icon: '" . ($message['type'] === 'success' ? 'success' : ($message['type'] === 'danger' ? 'error' : 'warning')) . "',
            timer: " . ($message['type'] === 'success' ? '3000' : '5000') . ",
            timerProgressBar: true,
            showConfirmButton: false
        });
    });
    ";
}

$js_page[] = "
$(document).ready(function() {
    function hitungMasaBakti(tmt) {
        if (!tmt) return '';
        var p = tmt.split('-');
        var s = new Date(p[0], p[1]-1, p[2]);
        var e = new Date();
        var y = e.getFullYear() - s.getFullYear();
        var m = e.getMonth() - s.getMonth();
        if (m < 0) { y--; m += 12; }
        return y + ' tahun ' + m + ' bulan';
    }
    $('#modalTmt').on('change', function() {
        $('#modalMasaBakti').val(hitungMasaBakti($(this).val()));
    });
    $('#editProfileForm').on('submit', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type=submit]');
        btn.prop('disabled', true).html('<i class=\"fas fa-spinner fa-spin mr-1\"></i>Menyimpan...');
        $.ajax({
            url: 'profil.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(r) {
                if (r.success) {
                    $('#editProfileModal').modal('hide');
                    Swal.fire({ title: 'Berhasil!', text: r.message, icon: 'success', timer: 2000, showConfirmButton: false })
                    .then(function() { location.reload(); });
                } else {
                    Swal.fire('Gagal!', r.message, 'error');
                    btn.prop('disabled', false).html('<i class=\"fas fa-save mr-1\"></i>Simpan');
                }
            },
            error: function() {
                Swal.fire('Error!', 'Terjadi kesalahan.', 'error');
                btn.prop('disabled', false).html('<i class=\"fas fa-save mr-1\"></i>Simpan');
            }
        });
    });
});
";

include '../templates/user_footer.php';
?>
