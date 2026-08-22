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
                    <?php
                    $_version_file = dirname(__DIR__) . '/version.txt';
                    $_app_version = is_file($_version_file) ? trim((string)@file_get_contents($_version_file)) : '';
                    ?>
                    <?php if ($_app_version !== ''): ?>
                        <span class="text-muted" style="font-size: 11px; margin-left: 8px;">v<?php echo htmlspecialchars($_app_version); ?></span>
                    <?php endif; ?>
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
    $bottom_home_url = 'dashboard.php';
    if (function_exists('getUserLevel')) {
        $bottom_user_level = getUserLevel();
        if ($bottom_user_level === 'admin' || $bottom_user_level === 'kepala_madrasah') {
            $bottom_profile_url = 'profil_madrasah.php';
        }
    }

    // Determine home URL for bottom nav based on menu items
    if (isset($menu_items) && is_array($menu_items)) {
        foreach ($menu_items as $item) {
            if (isset($item['title'], $item['url']) && $item['title'] === 'Dashboard') {
                $bottom_home_url = $item['url'];
                break;
            }
        }
    }
    ?>

    <!-- Spacer for Bottom Navbar (Mobile Only) -->
    <div class="d-block d-lg-none" style="height: 70px;"></div>

    <style>
    /* Bottom nav: selalu satu baris, semua menu tampil (termasuk Akun) di layar sempit */
    @media (max-width: 991.98px) {
        .bottom-nav-row { flex-wrap: nowrap !important; }
        .bottom-nav-row .col { flex: 1 1 0 !important; min-width: 0 !important; overflow: hidden; }
        .bottom-nav-row .bottom-nav-label { white-space: normal !important; line-height: 1.15 !important; }
    }
    /* Bottom nav gaya mobile app modern */
    @media (max-width: 991.98px) {
        .bottom-nav-row a.nav-link { color: #8e9aad; padding-top: 5px; transition: color .2s ease; }
        .bottom-nav-row a.nav-link i {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; margin-bottom: 2px;
            border-radius: 12px; font-size: 16px;
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            color: #64748b;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .08);
            transition: transform .15s ease, background .25s ease, color .25s ease, box-shadow .25s ease;
        }
        .bottom-nav-row a.nav-link:active i { transform: scale(.88); }
        .bottom-nav-row .bottom-nav-label { font-weight: 600; font-size: 10.5px !important; margin-top: 1px; }
        .bottom-nav-row a.nav-link.bottom-nav-active { color: #4f46e5; }
        .bottom-nav-row a.nav-link.bottom-nav-active i {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            box-shadow: 0 4px 12px rgba(99, 102, 241, .45);
        }
    }
    </style>

    <!-- Bottom Navbar (Mobile Only) -->
    <?php
    $bottom_quick_links = function_exists('get_bottom_nav_quick_links') && isset($menu_items)
        ? get_bottom_nav_quick_links($menu_items, 3)
        : [];
    $bottom_current = basename($_SERVER['PHP_SELF']);
    $bottom_home_active = ($bottom_current === basename($bottom_home_url));
    ?>
    <nav class="navbar navbar-expand navbar-light bg-white d-block d-lg-none border-top shadow-lg" style="position: fixed; bottom: 0; left: 0; right: 0; height: 60px; padding: 0; z-index: 1030;">
        <div class="container-fluid h-100 px-0">
            <div class="row w-100 mx-0 h-100 no-gutters bottom-nav-row">
                <div class="col px-0 h-100">
                            <a href="<?php echo htmlspecialchars(app_url($bottom_home_url), ENT_QUOTES, 'UTF-8'); ?>" class="nav-link h-100 d-flex flex-column align-items-center justify-content-center<?php echo $bottom_home_active ? ' bottom-nav-active' : ''; ?>">
                        <i class="fas fa-home"></i>
                        <span class="bottom-nav-label">Home</span>
                    </a>
                </div>
                <?php foreach ($bottom_quick_links as $link): ?>
                    <div class="col px-0 h-100">
                                <a href="<?php echo htmlspecialchars(app_url($link['url']), ENT_QUOTES, 'UTF-8'); ?>" class="nav-link h-100 d-flex flex-column align-items-center justify-content-center<?php echo $bottom_current === basename($link['url']) ? ' bottom-nav-active' : ''; ?>">
                            <i class="<?php echo $link['icon']; ?>"></i>
                            <span class="bottom-nav-label"><?php echo $link['title']; ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
                <div class="col px-0 h-100">
                    <a href="#" data-toggle="modal" data-target="#mobileUserMenu" class="nav-link h-100 d-flex flex-column align-items-center justify-content-center">
                        <i class="fas fa-user"></i>
                        <span class="bottom-nav-label">Akun</span>
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
                                <a href="<?php echo htmlspecialchars(app_url($bottom_profile_url), ENT_QUOTES, 'UTF-8'); ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                            <i class="fas fa-user-circle fa-lg mr-3 text-primary"></i> Profil Saya
                        </a>
                        <a href="#" onclick="confirmLogoutInline('../logout.php?level=<?php echo htmlspecialchars(getUserLevel(), ENT_QUOTES, 'UTF-8'); ?>'); return false;" class="list-group-item list-group-item-action d-flex align-items-center text-danger">
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

    // Auto scroll to menu section when hash exists in URL
    document.addEventListener('DOMContentLoaded', function() {
        // Check if URL has hash
        if (window.location.hash) {
            // Wait a bit for all content to load
            setTimeout(function() {
                var targetElement = document.querySelector(window.location.hash);
                if (targetElement) {
                    // Scroll to the element
                    targetElement.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }, 300);
        }
    });
    </script>

</body>
</html>
</file_content>
