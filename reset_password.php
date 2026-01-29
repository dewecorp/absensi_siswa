<?php
require_once 'config/database.php';
require_once 'config/functions.php';

$message = '';
$error = '';
$token = $_GET['token'] ?? '';

// Validate token
$stmt = $pdo->prepare("SELECT * FROM tb_password_resets WHERE token = ? LIMIT 1");
$stmt->execute([$token]);
$reset_request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reset_request) {
    $error = "Token tidak valid atau sudah kadaluarsa.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$error) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($password !== $confirm_password) {
        $error = "Konfirmasi password tidak cocok.";
    } elseif (strlen($password) < 6) {
        $error = "Password minimal 6 karakter.";
    } else {
        $email = $reset_request['email'];
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $updated = false;
        
        try {
            $pdo->beginTransaction();
            
            // Update tb_pengguna
            $stmt = $pdo->prepare("UPDATE tb_pengguna SET password = ? WHERE email = ?");
            $stmt->execute([$hashed_password, $email]);
            if ($stmt->rowCount() > 0) $updated = true;
            
            // Update tb_guru
            $stmt = $pdo->prepare("UPDATE tb_guru SET password = ? WHERE email = ?");
            $stmt->execute([$hashed_password, $email]);
            if ($stmt->rowCount() > 0) $updated = true;
            
            // Update tb_siswa
            $stmt = $pdo->prepare("UPDATE tb_siswa SET password = ? WHERE email = ?");
            $stmt->execute([$hashed_password, $email]);
            if ($stmt->rowCount() > 0) $updated = true;
            
            if ($updated) {
                // Delete token
                $stmt = $pdo->prepare("DELETE FROM tb_password_resets WHERE email = ?");
                $stmt->execute([$email]);
                
                $pdo->commit();
                $message = "Password berhasil diubah. Silakan <a href='login.php'>Login</a> dengan password baru Anda.";
            } else {
                $pdo->rollBack();
                $error = "Email tidak ditemukan di database pengguna.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

$school_profile = getSchoolProfile($pdo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title>Reset Password | Sistem Absensi Siswa</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="apple-touch-icon" href="assets/img/favicon.svg">

    <!-- General CSS Files -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css" integrity="sha384-fnmOCqbTlWIlj8LyTjo7mOUStjsKC4pOpQbqyi7RrhN7udi9RwhKkMHpvLbHG9Sr" crossorigin="anonymous">

    <!-- Template CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/components.css">
</head>

<body style="height: 100vh; margin: 0; overflow: hidden;">
    <div id="app">
        <section class="section d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 0;">
            <div class="col-12 col-sm-8 col-md-6 col-lg-4">
                <div class="card shadow mt-3 mb-3">
                    <div class="card-header text-center bg-primary text-white pt-4 pb-4">
                        <div class="mx-auto text-center">
                            <img src="assets/img/<?php echo $school_profile['logo'] ?: 'logo.png'; ?>" alt="Logo Madrasah" width="80" height="80" class="mb-3 d-block mx-auto">
                            <h5 class="mb-2"><?php echo strtoupper($school_profile['nama_madrasah']); ?></h5>
                            <h6 class="mb-0">RESET PASSWORD</h6>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible show fade">
                                <div class="alert-body">
                                    <button class="close" data-dismiss="alert">
                                        <span>&times;</span>
                                    </button>
                                    <?php echo $error; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($message): ?>
                            <div class="alert alert-success alert-dismissible show fade">
                                <div class="alert-body">
                                    <button class="close" data-dismiss="alert">
                                        <span>&times;</span>
                                    </button>
                                    <?php echo $message; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!$message && !$error && $reset_request): ?>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label for="password">Password Baru</label>
                                <input id="password" type="password" class="form-control" name="password" tabindex="1" required autofocus>
                                <small class="text-muted">Minimal 6 karakter</small>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">Konfirmasi Password Baru</label>
                                <input id="confirm_password" type="password" class="form-control" name="confirm_password" tabindex="2" required>
                            </div>

                            <div class="form-group">
                                <button type="submit" class="btn btn-primary btn-lg btn-block" tabindex="4">
                                    Ubah Password
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>
                        
                        <?php if ($error && !$reset_request): ?>
                        <div class="text-center mt-4">
                            <a href="lupa_password.php">Kirim Ulang Link Reset</a>
                        </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-3">
                            <a href="login.php">Kembali ke Login</a>
                        </div>
                    </div>
                </div>
                <div class="simple-footer text-center mt-4 mb-2">
                    <small>Copyright &copy; <?php echo date('Y'); ?> <?php echo $school_profile['nama_madrasah']; ?></small>
                </div>
            </div>
        </section>
    </div>

    <!-- General JS Scripts -->
    <script src="https://code.jquery.com/jquery-3.3.1.min.js" integrity="sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
    <script src="assets/js/stisla.js"></script>
    <script src="assets/js/scripts.js"></script>
    <script src="assets/js/custom.js"></script>
</body>
</html>