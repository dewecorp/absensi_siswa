<?php
// Footer template for the attendance system
// This file should be included at the end of the main content
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
            
            <?php
            // Determine profile URL for bottom nav
            $bottom_profile_url = 'profil.php';
            $bottom_user_level = getUserLevel();
            if ($bottom_user_level === 'admin' || $bottom_user_level === 'kepala_madrasah') {
                $bottom_profile_url = 'profil_madrasah.php';
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
                                <a href="#" onclick="confirmLogout(); return false;" class="list-group-item list-group-item-action d-flex align-items-center text-danger">
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

    <!-- Notification JS -->
    <script>
    function readNotification(id, link, element) {
        // Optimistic UI updates
        if (element) {
            var $el = $(element);
            
            // Unbold text and remove background
            $el.css('font-weight', 'normal').css('background-color', 'white');
            $el.find('span, p').css('font-weight', 'normal');
            $el.removeClass('bg-light'); // For mobile list item
            
            // Update badge count
            var $badges = $('.dropdown-list-toggle .badge, .btn-lg .badge');
            $badges.each(function() {
                var count = parseInt($(this).text());
                if (!isNaN(count)) {
                    if (count > 1) {
                        $(this).text(count - 1);
                    } else {
                        $(this).remove();
                        $('.dropdown-list-toggle').removeClass('beep');
                    }
                }
            });
        }

        // Mark as read via AJAX
        $.ajax({
            url: '../admin/mark_notification_read.php',
            type: 'POST',
            data: { id: id },
            success: function(response) {
                // Redirect to link
                if (link && link !== '#') {
                    window.location.href = link;
                } else if (!element) {
                    // Only reload if no element passed (manual call?)
                    window.location.reload();
                }
            },
            error: function() {
                // Fallback redirect even if mark read fails
                console.error("Failed to mark notification as read");
                if (link && link !== '#') {
                    window.location.href = link;
                }
            }
        });
    }
    
    $(document).ready(function() {
        $('#mark-all-read').click(function(e) {
            e.preventDefault();
            $.ajax({
                url: '../admin/mark_notification_read.php',
                type: 'POST',
                data: { action: 'mark_all' },
                success: function(response) {
                    window.location.reload();
                }
            });
        });
    });
    </script>
    
    <!-- Import Modal JavaScript -->
    <script>
    // Fungsi untuk mengatur tipe impor untuk modal
    function setImportType(type) {
        // Perbarui bidang input tersembunyi modal impor dengan tipe impor
        $('#importModal #importType').val(type);
        
        // Perbarui tautan unduhan template berdasarkan tipe
        let templateUrl = '../download_template.php?type=' + type;
        
        // Jika tipe adalah siswa dan ada kelas yang dipilih, tambahkan ID kelas ke URL
        if (type === 'siswa' && $('#filter_kelas').length > 0) {
            let selectedClassId = $('#filter_kelas').val();
            if (selectedClassId) {
                templateUrl += '&class_id=' + selectedClassId;
            }
        }
        
        $('#importModal .btn-info').attr('href', templateUrl);
        
        // Reset form dan progress saat modal dibuka
        $('#importForm')[0].reset();
        $('#importProgress').hide();
        $('#importResult').hide();
        $('#excel_file').val('');
    }
    
    // Tangani perubahan file untuk auto-submit
    $(document).on('change', '#excel_file', function() {
        if (this.files.length > 0) {
            // Submit form secara otomatis setelah memilih file
            $('#importForm').submit();
        }
    });
    
    // Tangani pengiriman formulir impor dengan AJAX
    $(document).on('submit', '#importForm', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        var submitBtn = $('#importSubmitBtn');
        var progressBar = $('#importProgress');
        var progressText = $('#importProgress .progress-bar');
        var resultDiv = $('#importResult');
        
        // Tampilkan kemajuan dan sembunyikan tombol
        progressBar.show();
        progressText.removeClass('progress-bar-success progress-bar-danger').addClass('progress-bar-striped progress-bar-animated');
        progressText.css('width', '20%').text('Mengunggah...');
        submitBtn.hide();
        
        $.ajax({
            url: 'import_handler.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                // Upload progress
                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = evt.loaded / evt.total * 100;
                        progressText.css('width', percentComplete + '%');
                        progressText.text(Math.round(percentComplete) + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                progressText.css('width', '100%').text('Selesai!');
                progressText.removeClass('progress-bar-striped progress-bar-animated').addClass('progress-bar-success');
                
                // Tampilkan hasil
                if (response.success) {
                    // Buat pesan berdasarkan data yang dikembalikan
                    let message = 'Proses impor selesai';
                    if (response.imported_rows !== undefined) {
                        message += '<br>- Data disimpan: ' + response.imported_rows + ' baris';
                    }
                    if (response.duplicate_rows !== undefined) {
                        message += '<br>- Data duplikat ditimpa: ' + response.duplicate_rows + ' baris';
                    }
                    if (response.failed_rows !== undefined) {
                        message += '<br>- Data gagal disimpan: ' + response.failed_rows + ' baris';
                    }
                    if (response.errors && response.errors.length > 0) {
                        message += '<br><strong>Kesalahan:</strong><br>' + response.errors.join('<br>');
                        // Gunakan alert-warning jika ada error, meskipun sebagian data berhasil
                        resultDiv.html('<div class="alert alert-warning">' + message + '</div>').show();
                    } else {
                        // Jika tidak ada error, gunakan alert-success
                        resultDiv.html('<div class="alert alert-success">' + message + '</div>').show();
                    }
                    
                    // Muat ulang halaman setelah jeda singkat untuk menampilkan data terbaru
                    // Hanya muat ulang jika tidak ada error
                    if (!(response.errors && response.errors.length > 0)) {
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                } else {
                    progressText.removeClass('progress-bar-success').addClass('progress-bar-danger');
                    resultDiv.html('<div class="alert alert-danger">' + (response.message || response.error || 'Terjadi kesalahan') + '</div>').show();
                }
            },
            error: function(xhr, status, error) {
                progressText.css('width', '100%').text('Gagal!');
                progressText.removeClass('progress-bar-striped progress-bar-animated').addClass('progress-bar-danger');
                resultDiv.html('<div class="alert alert-danger">Terjadi kesalahan: ' + error + '</div>').show();
                
                // Tampilkan tombol impor kembali jika terjadi kesalahan
                setTimeout(function() {
                    $('#importSubmitBtn').show();
                }, 3000);
            }
        });
    });
    
    function togglePassword(teacherId, teacherName) {
        var passwordSpan = document.getElementById('password-' + teacherId);
        var passwordTextSpan = document.getElementById('password-text-' + teacherId);
        
        if (passwordSpan.style.display === 'none') {
            // Hide the text version, show the masked version
            passwordSpan.style.display = 'inline';
            passwordTextSpan.style.display = 'none';
        } else {
            // Show the text version, hide the masked version
            passwordSpan.style.display = 'none';
            passwordTextSpan.style.display = 'inline';
        }
    }
    
    function toggleEditPassword(teacherId) {
        var passwordInput = document.getElementById('edit-password-' + teacherId);
        var button = passwordInput.closest('.input-group').querySelector('.input-group-append button');
        var eyeIcon = button.querySelector('i');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            eyeIcon.classList.remove('fa-eye-slash');
            eyeIcon.classList.add('fa-eye');
        }
    }
    
    function toggleAddPassword() {
        var passwordInput = document.getElementById('add-password');
        var button = passwordInput.closest('.input-group').querySelector('.input-group-append button');
        var eyeIcon = button.querySelector('i');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            eyeIcon.classList.remove('fa-eye-slash');
            eyeIcon.classList.add('fa-eye');
        }
    }
    
    // Bulk functions are now handled in individual page files to avoid conflicts
    

    
    function confirmLogout() {
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
                window.location.href = '../logout.php';
            }
        });
    }
    
    // Also provide confirmLogoutInline function for consistency
    function confirmLogoutInline() {
        confirmLogout();
    }
    </script>
    
</body>
</html>