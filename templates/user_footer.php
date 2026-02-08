<?php
// Footer template for all user dashboards (admin, guru, wali)
if (!isset($school_profile)) {
    require_once '../config/database.php';
    require_once '../config/functions.php';
    $school_profile = getSchoolProfile($pdo);
}
?>

            <!-- Main Content will be inserted here by individual pages -->

            <style>
            @media (max-width: 768px) {
                .main-footer .footer-left, 
                .main-footer .footer-right {
                    float: none !important;
                    text-align: center !important;
                    display: block !important;
                    width: 100% !important;
                    margin-bottom: 10px;
                    white-space: normal !important;
                    line-height: 1.5;
                }
                .main-footer .footer-left .bullet {
                    display: none !important;
                }
            }
            </style>

            <footer class="main-footer">
                <div class="footer-left">
                    Copyright &copy; <?php echo date('Y'); ?> <div class="bullet"></div> Sistem Informasi Madrasah
                </div>
                <div class="footer-right">
                    <?php echo $school_profile['nama_madrasah']; ?>
                </div>
            </footer>
        </div>
    </div>

    <?php
    // Determine profile URL for bottom nav
    $bottom_profile_url = 'profil.php';
    if (function_exists('getUserLevel')) {
        $bottom_user_level = getUserLevel();
        if ($bottom_user_level === 'admin' || $bottom_user_level === 'kepala_madrasah') {
            $bottom_profile_url = 'profil_madrasah.php';
        }
    }
    ?>

    <!-- Spacer for Bottom Navbar (Mobile Only) -->
    <div class="d-block d-lg-none" style="height: 70px;"></div>

    <!-- Bottom Navbar (Mobile Only) -->
    <nav class="navbar navbar-expand navbar-light bg-white d-block d-lg-none border-top shadow-lg" style="position: fixed; bottom: 0; left: 0; right: 0; height: 60px; padding: 0; z-index: 1030;">
        <div class="container-fluid h-100">
            <div class="row w-100 mx-0 h-100">
                <!-- Hamburger / Menu -->
                <div class="col-4 px-0 h-100">
                    <a href="#" data-toggle="sidebar" class="nav-link h-100 d-flex flex-column align-items-center justify-content-center text-dark">
                        <i class="fas fa-bars fa-lg mb-1"></i>
                        <span style="font-size: 10px;">Menu</span>
                    </a>
                </div>
                
                <!-- Home / Dashboard -->
                <div class="col-4 px-0 h-100">
                    <a href="dashboard.php" class="nav-link h-100 d-flex flex-column align-items-center justify-content-center text-primary">
                        <i class="fas fa-home fa-lg mb-1"></i>
                        <span style="font-size: 10px;">Home</span>
                    </a>
                </div>
                
                <!-- User / Profile -->
                <div class="col-4 px-0 h-100">
                    <a href="#" data-toggle="modal" data-target="#mobileUserMenu" class="nav-link h-100 d-flex flex-column align-items-center justify-content-center text-dark">
                        <i class="fas fa-user fa-lg mb-1"></i>
                        <span style="font-size: 10px;">Akun</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Mobile User Menu Modal -->
    <div class="modal fade" id="mobileUserMenu" tabindex="-1" role="dialog" aria-labelledby="mobileUserMenuLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="mobileUserMenuLabel">Menu Pengguna</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-0">
                    <div class="list-group list-group-flush">
                        <a href="<?php echo $bottom_profile_url; ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                            <i class="fas fa-user-circle fa-lg mr-3 text-primary"></i> Profil Saya
                        </a>
                        <a href="#" onclick="confirmLogoutInline(); return false;" class="list-group-item list-group-item-action d-flex align-items-center text-danger">
                            <i class="fas fa-sign-out-alt fa-lg mr-3"></i> Logout
                        </a>
                    </div>
                </div>
                <div class="modal-footer p-2">
                    <button type="button" class="btn btn-secondary btn-block" data-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- General JS Scripts -->
    <script src="https://code.jquery.com/jquery-3.3.1.min.js" integrity="sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.nicescroll/3.7.6/jquery.nicescroll.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.24.0/moment.min.js"></script>
    <script src="../assets/js/stisla.js"></script>
    
    <!-- Load Chart.js after other scripts to avoid conflicts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

    <!-- SweetAlert Library -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- JS Libraries -->
    <?php if (isset($js_libs) && is_array($js_libs)): ?>
        <?php foreach ($js_libs as $js): ?>
            <?php if (strpos($js, 'http://') === 0 || strpos($js, 'https://') === 0): ?>
                <script src="<?php echo $js; ?>"></script>
            <?php else: ?>
                <script src="../<?php echo $js; ?>"></script>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Template JS File -->
    <script src="../assets/js/scripts.js"></script>
    <script src="../assets/js/custom.js"></script>

    <!-- Page Specific JS File -->
    <?php if (isset($js_page) && is_array($js_page)): ?>
        <?php foreach ($js_page as $js): ?>
            <script>
                <?php echo $js; ?>
            </script>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Logout Confirmation Function -->
    <script>
    function confirmLogoutInline(logoutUrl) {
        logoutUrl = logoutUrl || '../logout.php';
        Swal.fire({
            title: 'Konfirmasi Logout',
            text: 'Apakah Anda yakin ingin keluar dari sistem?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Keluar!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = logoutUrl;
            }
        });
    }
    </script>

</body>
</html>
</file_content>