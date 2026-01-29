<?php
require_once 'config/database.php';
require_once 'config/functions.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Check if email exists in any table
        $user_found = false;
        
        // Check tb_pengguna
        $stmt = $pdo->prepare("SELECT id_pengguna FROM tb_pengguna WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) $user_found = true;
        
        // Check tb_guru
        if (!$user_found) {
            $stmt = $pdo->prepare("SELECT id_guru FROM tb_guru WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) $user_found = true;
        }
        
        // Check tb_siswa
        if (!$user_found) {
            $stmt = $pdo->prepare("SELECT id_siswa FROM tb_siswa WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) $user_found = true;
        }
        
        if ($user_found) {
            // Generate token
            $token = bin2hex(random_bytes(32));
            
            // Store token
            $stmt = $pdo->prepare("INSERT INTO tb_password_resets (email, token) VALUES (?, ?)");
            if ($stmt->execute([$email, $token])) {
                // In a real application, you would send an email here.
                // For this environment, we will simulate it by showing the link.
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
                
                // For demo purposes, we show the link directly. 
                // In production, remove this and use mail() or PHPMailer.
                $message = "Link reset password telah dikirim ke email Anda (Simulasi).<br>Silakan klik link berikut: <a href='$reset_link'>Reset Password</a>";
                
                // Example mail code (commented out):
                // $subject = "Reset Password";
                // $msg = "Klik link ini untuk reset password: $reset_link";
                // mail($email, $subject, $msg);
            } else {
                $error = "Terjadi kesalahan sistem.";
            }
        } else {
            $error = "Email tidak ditemukan.";
        }
    } else {
        $error = "Format email tidak valid.";
    }
}

// Get school profile for logo/name
$school_profile = getSchoolProfile($pdo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title>Lupa Password | Sistem Absensi Siswa</title>
    
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
                            <h6 class="mb-0">LUPA PASSWORD</h6>
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

                        <p class="text-muted">Masukkan alamat email Anda yang terdaftar untuk mereset password.</p>

                        <form method="POST" action="">
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input id="email" type="email" class="form-control" name="email" tabindex="1" required autofocus>
                            </div>

                            <div class="form-group">
                                <button type="submit" class="btn btn-primary btn-lg btn-block" tabindex="4">
                                    Kirim Link Reset
                                </button>
                            </div>
                        </form>
                        
                        <div class="text-center mt-4 mb-3">
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