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
            
            <?php
            // Determine profile URL for bottom nav
            $bottom_profile_url = 'profil.php';
            $bottom_user_level = getUserLevel();
            if ($bottom_user_level === 'admin' || $bottom_user_level === 'kepala_madrasah') {
                $bottom_profile_url = 'profil_madrasah.php';
            }

            // Determine home URL for bottom nav based on menu items
            $bottom_home_url = 'dashboard.php';
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
                .bottom-nav-row a.nav-link { color: #8e9aad; padding-top: 5px; transition: color .2s ease; position: relative; }
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
                .bottom-nav-row a.nav-link.bottom-nav-active,
                .bottom-nav-row a.nav-link.bottom-nav-active .bottom-nav-label { color: #2563eb !important; }
                .bottom-nav-row a.nav-link.bottom-nav-active .bottom-nav-label { font-weight: 700; }
                .bottom-nav-row a.nav-link.bottom-nav-active i {
                    background: #e8f1ff;
                    color: #2563eb !important;
                    box-shadow: 0 4px 12px rgba(37, 99, 235, .35);
                }
            }
            </style>

            <!-- Bottom Navbar (Mobile Only) -->
            <?php
            $bottom_quick_links = function_exists('get_bottom_nav_quick_links') && isset($menu_items)
                ? get_bottom_nav_quick_links($menu_items, 3)
                : [];
            ?>
            <nav class="navbar navbar-expand navbar-light bg-white d-block d-lg-none border-top shadow-lg" style="position: fixed; bottom: 0; left: 0; right: 0; height: 60px; padding: 0; z-index: 1030;">
                <div class="container-fluid h-100 px-0">
                    <div class="row w-100 mx-0 h-100 no-gutters bottom-nav-row">
                        <div class="col px-0 h-100">
                            <a href="<?php echo htmlspecialchars(app_url($bottom_home_url), ENT_QUOTES, 'UTF-8'); ?>" class="nav-link h-100 d-flex flex-column align-items-center justify-content-center bottom-nav-home">
                                <i class="fas fa-home"></i>
                                <span class="bottom-nav-label">Home</span>
                            </a>
                        </div>
                        <?php foreach ($bottom_quick_links as $link): ?>
                            <div class="col px-0 h-100">
                                <a href="<?php echo htmlspecialchars(app_url($link['url']), ENT_QUOTES, 'UTF-8'); ?>" class="nav-link h-100 d-flex flex-column align-items-center justify-content-center bottom-nav-item">
                                    <i class="<?php echo $link['icon']; ?>"></i>
                                    <span class="bottom-nav-label"><?php echo $link['title']; ?></span>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        <div class="col px-0 h-100">
                            <a href="#" data-toggle="modal" data-target="#mobileUserMenu" class="nav-link h-100 d-flex flex-column align-items-center justify-content-center bottom-nav-account">
                                <i class="fas fa-user"></i>
                                <span class="bottom-nav-label">Akun</span>
                            </a>
                        </div>
                    </div>
                </div>
            </nav>

            <script>
            (function() {
                function lastSeg(path) {
                    return decodeURIComponent((String(path).split('/').pop() || '').toLowerCase());
                }
                function titleOf(a) {
                    var l = a.querySelector('.bottom-nav-label');
                    return l ? l.textContent.trim() : '';
                }
                function setActive(el) {
                    var home = document.querySelector('.bottom-nav-row .bottom-nav-home');
                    var items = document.querySelectorAll('.bottom-nav-row .bottom-nav-item');
                    if (home) home.classList.remove('bottom-nav-active');
                    Array.prototype.forEach.call(items, function(a) { a.classList.remove('bottom-nav-active'); });
                    if (el) el.classList.add('bottom-nav-active');
                }
                function updateBottomNavActive() {
                    var home = document.querySelector('.bottom-nav-row .bottom-nav-home');
                    var items = document.querySelectorAll('.bottom-nav-row .bottom-nav-item');

                    // 0) Pulihkan pilihan dari klik sebelum navigasi
                    try {
                        var saved = JSON.parse(sessionStorage.getItem('bnav_sel') || 'null');
                        if (saved && Date.now() - saved.ts < 5000) {
                            var cand = null;
                            Array.prototype.forEach.call(items, function(it) {
                                if (!cand && titleOf(it) === saved.t) cand = it;
                            });
                            if (!cand && home && saved.t === 'Home') cand = home;
                            if (cand) { setActive(cand); sessionStorage.removeItem('bnav_sel'); return; }
                        } else if (saved) {
                            sessionStorage.removeItem('bnav_sel');
                        }
                    } catch (err) {}

                    var page = lastSeg(location.pathname);
                    var hash = location.hash || '';

                    // Tanpa anchor: Home adalah default aktif
                    if (!hash) {
                        setActive(home);
                        return;
                    }

                    // 1) Cocokkan anchor persis
                    var matched = null;
                    Array.prototype.forEach.call(items, function(a) {
                        try {
                            var u = new URL(a.getAttribute('href'), location.href);
                            if (u.hash === hash && lastSeg(u.pathname) === page) matched = a;
                        } catch (e) {}
                    });

                    // 2) Jaring pengaman: anchor tak cocok -> item dengan halaman sama
                    if (!matched) {
                        Array.prototype.forEach.call(items, function(a) {
                            try {
                                var u = new URL(a.getAttribute('href'), location.href);
                                if (!matched && lastSeg(u.pathname) === page) matched = a;
                            } catch (e) {}
                        });
                    }

                    // 3) Jaring pengaman terakhir: tetap Home
                    setActive(matched || home);
                }
                // Aktivasi instan saat menu diklik + simpan pilihan untuk dimuat ulang
                document.addEventListener('click', function(e) {
                    var a = e.target.closest ? e.target.closest('.bottom-nav-row .bottom-nav-home, .bottom-nav-row .bottom-nav-item') : null;
                    if (!a) return;
                    try { sessionStorage.setItem('bnav_sel', JSON.stringify({ t: titleOf(a), ts: Date.now() })); } catch (err) {}
                    setActive(a);
                });
                document.addEventListener('DOMContentLoaded', updateBottomNavActive);
                window.addEventListener('hashchange', updateBottomNavActive);
            })();
            </script>

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
                                <a href="#" onclick="confirmLogout('../logout.php?level=<?php echo htmlspecialchars(getUserLevel(), ENT_QUOTES, 'UTF-8'); ?>'); return false;" class="list-group-item list-group-item-action d-flex align-items-center text-danger">
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

            <?php if (getUserLevel() === 'siswa'): ?>
            <!-- Modal Detail Pesan Siswa (Nav Global) -->
            <div class="modal fade" id="modalBacaPesanSiswaNav" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-md" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-envelope-open mr-2 text-info"></i>Detail Pesan &amp; Informasi</h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <table class="table table-bordered table-sm mb-3">
                                <tr><th width="35%">Tanggal Mulai - Selesai</th><td id="nav_baca_tanggal"></td></tr>
                                <tr><th>Waktu</th><td id="nav_baca_waktu"></td></tr>
                                <tr><th>Pengirim</th><td id="nav_baca_pengirim" class="font-weight-bold"></td></tr>
                                <tr><th>Kepada Ortu/Wali</th><td id="nav_baca_ortu"></td></tr>
                                <tr><th>Jenis Informasi</th><td id="nav_baca_jenis"></td></tr>
                                <tr><th>Judul Pesan</th><td id="nav_baca_judul" class="font-weight-bold text-primary"></td></tr>
                            </table>
                            <div class="font-weight-bold mb-1">Isi Pesan / Laporan:</div>
                            <div id="nav_baca_isi" class="p-3 border rounded bg-light" style="white-space: pre-wrap; font-size: 13.5px; line-height: 1.6;"></div>
                            <div id="nav_baca_lampiran_wrap" class="mt-2" style="display:none;"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            function openStudentMsgFromNav(data, element) {
                if (!data) return;
                var tglStr = '-';
                var tm = data.tanggal_mulai || data.tgl_mulai_ef || data.tanggal;
                var ts = data.tanggal_selesai || data.tgl_selesai_ef || tm;
                if (tm) {
                    var formatDate = function(dStr) {
                        if (!dStr) return '';
                        var parts = String(dStr).split(' ')[0].split('-');
                        if (parts.length === 3) return parts[2] + '/' + parts[1] + '/' + parts[0];
                        return dStr;
                    };
                    var fM = formatDate(tm);
                    var fS = formatDate(ts);
                    tglStr = (!fS || fM === fS) ? fM : (fM + ' s/d ' + fS);
                }
                var shortT = function(tStr) {
                    if (!tStr) return '';
                    return String(tStr).substring(0, 5).replace(':', '.');
                };
                var wm = shortT(data.waktu_mulai || '');
                var ws = shortT(data.waktu_selesai || '');
                var waktuStr = '-';
                if (wm !== '' && ws !== '' && wm !== ws) {
                    waktuStr = wm + ' - ' + ws + ' WIB';
                } else if (wm !== '') {
                    waktuStr = wm + ' WIB';
                } else if (ws !== '') {
                    waktuStr = ws + ' WIB';
                }
                $('#nav_baca_tanggal').text(tglStr);
                $('#nav_baca_waktu').text(waktuStr);
                $('#nav_baca_pengirim').text(data.nama_guru || data.pengirim || 'Wali / Guru');
                $('#nav_baca_ortu').text(data.nama_ortu || '-');
                $('#nav_baca_jenis').html('<span class="badge badge-info">' + $('<div>').text(data.jenis_informasi || '').html() + '</span>');
                $('#nav_baca_judul').text(data.judul || '-');
                $('#nav_baca_isi').text(data.isi || '-');
                
                if (data.lampiran) {
                    var lampDir = data.msg_type === 'tugas' ? 'tugas' : 'komunikasi';
                    $('#nav_baca_lampiran_wrap').html('<a href="../uploads/' + lampDir + '/' + String(data.lampiran).split('/').map(encodeURIComponent).join('/') + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-paperclip mr-1"></i>Unduh Berkas Lampiran (' + String(data.lampiran).split('.').pop().toUpperCase() + ')</a>').show();
                } else {
                    $('#nav_baca_lampiran_wrap').hide().empty();
                }

                $('#modalBacaPesanSiswaNav').modal('show');
                
                if (data.status_dibaca !== 'Sudah Dibaca') {
                    $.post('<?php echo htmlspecialchars(app_url('siswa/mark_message_read.php'), ENT_QUOTES, 'UTF-8'); ?>', { pesan_id: data.id, msg_type: data.msg_type || 'ortu' }, function(res) {
                        data.status_dibaca = 'Sudah Dibaca';
                        if (element) {
                            var $el = $(element);
                            $el.css('font-weight', 'normal').css('background-color', 'white');
                            $el.find('span').css('font-weight', 'normal');
                        }
                        var $badge = $('.nav-msg-count');
                        if ($badge.length) {
                            var countAttr = parseInt($badge.attr('data-count'), 10) || 0;
                            if (countAttr > 1) {
                                var next = countAttr - 1;
                                $badge.attr('data-count', next).text(next > 99 ? '99+' : String(next));
                            } else {
                                $badge.remove();
                                $('.nav-msg-toggle').removeClass('beep');
                            }
                        }
                        if ($('#row-pesan-' + data.id).length) {
                            $('#row-pesan-' + data.id).find('.status-baca-col').html('<span class="badge badge-info"><i class="fas fa-check-double mr-1"></i>Sudah Dibaca</span>');
                        }
                    });
                }
            }
            </script>
            <?php endif; ?>
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
    <script src="../assets/js/global_dropdown.js"></script>

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
        function formatNotifCount(n) {
            return n > 99 ? '99+' : String(n);
        }
        // Optimistic UI updates
        if (element) {
            var $el = $(element);
            
            // Unbold text and remove background
            $el.css('font-weight', 'normal').css('background-color', 'white');
            $el.find('span, p').css('font-weight', 'normal');
            $el.removeClass('bg-light'); // For mobile list item
            
            // Update badge count
            var $badges = $('.dropdown-list-toggle .badge, .btn-lg .badge, .badge-circle, .notif-count-badge');
            $badges.each(function() {
                var $badge = $(this);
                var countAttr = parseInt($badge.attr('data-count'), 10);
                var countText = parseInt($badge.text(), 10);
                var count = !isNaN(countAttr) ? countAttr : countText;
                if (isNaN(count)) return;

                if (count > 1) {
                    var next = count - 1;
                    $badge.attr('data-count', next).text(formatNotifCount(next));
                } else {
                    $badge.remove();
                    $('.dropdown-list-toggle').removeClass('beep');
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

    function showSidebarNotifDropdown(type, $targetElem) {
        $('.popover-notif-dropdown').remove();
        if (!type || type === 'other') return;
        var list = (type === 'forum') ? (window.forumUnreadList || []) : (window.diskusiUnreadList || []);
        var pageUrl = (type === 'forum') ? '../guru/forum.php' : '../guru/diskusi.php';
        var rect = $targetElem[0] ? $targetElem[0].getBoundingClientRect() : null;
        if (!rect) return;
        var top = Math.max(10, rect.top);
        var left = rect.right + 10;
        if (left + 330 > $(window).width()) {
            left = Math.max(10, rect.left - 335);
        }
        var html = '<div class="popover-notif-dropdown shadow-lg" style="top:' + top + 'px; left:' + left + 'px;">';
        html += '<div class="px-3 py-2 font-weight-bold d-flex justify-content-between align-items-center text-white popover-notif-head">';
        html += '<span>Notifikasi ' + (type === 'forum' ? 'Forum Guru' : 'Diskusi Kelas') + '</span>';
        html += '<button type="button" class="close text-white p-0 m-0 popover-notif-close" onclick="$(this).closest(\'.popover-notif-dropdown\').remove()">&times;</button>';
        html += '</div>';
        html += '<div class="list-group list-group-flush modern-notif-scroll">';
        if (list && list.length > 0) {
            $.each(list, function(i, item) {
                var isUnread = (!item.is_read || item.is_read == '0');
                var cls = isUnread ? 'is-unread' : 'is-read';
                html += '<a href="#" class="list-group-item list-group-item-action modern-notif-item ' + cls + '" onclick="readNotification(' + item.id + ', \'' + item.link + '\', this); return false;">';
                html += '<span class="unread-dot"></span><span class="popover-notif-body"><span class="d-block mb-1">' + $('<div>').text(item.message).html() + '</span>';
                html += '<small class="text-primary"><i class="far fa-clock mr-1"></i>' + (item.created_at || '') + '</small></span>';
                html += '</a>';
            });
        } else {
            html += '<div class="p-3 text-center text-muted small">Belum ada aktivitas 24 jam terakhir</div>';
        }
        html += '</div>';
        html += '<div class="p-2 text-center bg-light border-top">';
        html += '<a href="' + pageUrl + '" class="small font-weight-bold text-primary">Buka Halaman ' + (type === 'forum' ? 'Forum' : 'Diskusi') + ' &rarr;</a>';
        html += '</div></div>';
        $('body').append(html);
    }

    $(document).off('click.sbNotif').on('click.sbNotif', '.sidebar-notif-badge', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var type = $(this).attr('data-notif-type');
        showSidebarNotifDropdown(type, $(this));
    });

    $(document).off('click.sbLink').on('click.sbLink', '.has-notif-badge', function(e) {
        var $link = $(this);
        var $badge = $link.find('.sidebar-notif-badge');
        var type = $badge.length ? $badge.attr('data-notif-type') : $link.attr('data-notif-type');
        if (!type || type === 'other') return;
        e.preventDefault();
        e.stopPropagation();
        showSidebarNotifDropdown(type, $badge.length ? $badge : $link);
    });

    $(document).off('click.sbOut').on('click.sbOut', function(e) {
        if (!$(e.target).closest('.popover-notif-dropdown, .sidebar-notif-badge, .has-notif-badge').length) {
            $('.popover-notif-dropdown').remove();
        }
    });
    
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

        // Template statis tanpa sesi/PHP agar unduhan tidak bisa macet.
        var templateUrl = (type === 'guru')
            ? '../assets/templates/template_impor_guru.xlsx'
            : '../assets/templates/template_impor_siswa.xlsx';

        var $dl = $('#importModal #downloadTemplateLink');
        try { $dl.attr('href', templateUrl); } catch (e) {}
        try { $dl.removeAttr('target'); } catch (e) {}
        try { $dl.removeAttr('download'); } catch (e) {}
        try { $dl.prop('disabled', false).removeClass('disabled').css('pointer-events', ''); } catch (e) {}

        // Reset form dan progress saat modal dibuka
        try { $('#importForm')[0].reset(); } catch (e) {}
        // Kunci ulang URL unduh setelah reset
        try { $dl.attr('href', templateUrl); } catch (e) {}
        $('#importProgress').hide();
        $('#importResult').hide();
        try { $('#excel_file').val(''); } catch (e) {}
    }
    // Kunci ulang href unduh setiap modal impor dibuka (anti-timpa reset/JS lain).
    $(document).on('show.bs.modal', '#importModal', function() {
        var t = $('#importModal #importType').val() || 'siswa';
        var u = (t === 'guru')
            ? '../assets/templates/template_impor_guru.xlsx'
            : '../assets/templates/template_impor_siswa.xlsx';
        var dl = document.getElementById('downloadTemplateLink');
        if (dl) { dl.setAttribute('href', u); dl.removeAttribute('target'); dl.removeAttribute('download'); }
        try {
            var $dl = $('#importModal #downloadTemplateLink');
            $dl.attr('href', u);
            $dl.removeAttr('target');
            $dl.removeAttr('download');
        } catch (e) {}
    });
    // Unduh paksa via blob agar tidak diblokir tab/iframe browser.
    $(document).off('click.tplforce').on('click.tplforce', '#downloadTemplateLink, .tpl-direct', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var url = $(this).attr('href');
        if (!url) return false;
        var fname = url.split('/').pop().split('?')[0] || 'template.xlsx';
        fetch(url, { credentials: 'same-origin' }).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.blob();
        }).then(function(b) {
            var blob = (b.size > 0) ? b : null;
            if (!blob) throw new Error('empty');
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = fname;
            document.body.appendChild(a);
            a.click();
            setTimeout(function() { URL.revokeObjectURL(a.href); a.remove(); }, 800);
        }).catch(function() {
            window.location.href = url;
        });
        return false;
    });
    
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
    

    
    function confirmLogout(logoutUrl) {
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
    
    // Also provide confirmLogoutInline function for consistency
    function confirmLogoutInline(logoutUrl) {
        confirmLogout(logoutUrl);
    }
    </script>

    <!-- Page Navigation Preloader: hanya login & pindah laman, bukan reload -->
    <?php $preloader_logo = !empty($favicon_logo) ? $favicon_logo : 'logo.png'; ?>
    <div id="pagePreloader" hidden style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;background:rgba(255,255,255,.88);backdrop-filter:blur(2px);">
        <div class="page-loader">
            <img src="../assets/img/<?php echo htmlspecialchars($preloader_logo, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($favicon_version ?? '1', ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="page-loader-logo">
            <span class="page-loader-ring"></span>
        </div>
        <div class="page-loader-text">Memuat halaman...</div>
    </div>
    <style>
    .page-loader {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -58%);
    }
    .page-loader-logo {
        display: block;
        width: 86px;
        height: 86px;
        object-fit: contain;
    }
    .page-loader-ring {
        position: absolute;
        top: -10px;
        left: -10px;
        width: calc(100% + 20px);
        height: calc(100% + 20px);
        border-radius: 50%;
        border: 5px solid rgba(47, 110, 240, .15);
        border-top-color: #2f6ef0;
        animation: pageRingSpin .8s linear infinite;
    }
    .page-loader-text {
        position: absolute;
        top: calc(100% + 26px);
        left: 50%;
        transform: translateX(-50%);
        text-align: center;
        font-size: .95rem;
        font-weight: 600;
        color: #475569;
        white-space: nowrap;
    }
    @keyframes pageRingSpin { to { transform: rotate(360deg); } }
    #pagePreloader{transition:opacity .35s ease;}
    #pagePreloader.pre-hide{opacity:0;pointer-events:none;}
    @media (max-width: 575.98px) {
        .page-loader-logo { width: 68px; height: 68px; }
        .page-loader-ring { top: -8px; left: -8px; width: calc(100% + 16px); height: calc(100% + 16px); border-width: 4px; }
        .page-loader-text { font-size: .88rem; }
    }
    </style>
    <script>
    (function() {
        var overlay = document.getElementById('pagePreloader');
        if (!overlay) return;
        // Tandai pindah laman via klik link internal. Simpan form tak tandai.
        // Wajib pasang duluan agar tiap laman bisa tandai klik berikutnya.
        document.addEventListener('click', function(e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) return;
            var href = a.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
            if (a.target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            if (a.hasAttribute('data-no-loader')) return;
            try {
                var url = new URL(a.getAttribute('href'), window.location.href);
                if (url.origin !== window.location.origin) return;
                // Link ke laman sama persis: anggap bukan pindah, jangan tandai.
                if (url.href === window.location.href) return;
            } catch (err) { return; }
            try { sessionStorage.setItem('showPreloader', '1'); } catch (err) {}
        }, true);
        // Tampil hanya jika laman sebelumnya tandai pindah via klik link.
        // Reload / simpan form / buka langsung: flag tak ada, loader tetap sembunyi.
        var flag = null;
        try { flag = sessionStorage.getItem('showPreloader'); } catch (e) {}
        if (flag !== '1') {
            overlay.hidden = true;
            overlay.style.display = 'none';
            overlay.remove();
            return;
        }
        try { sessionStorage.removeItem('showPreloader'); } catch (e) {}
        overlay.hidden = false;
        overlay.style.display = 'block';
        overlay.classList.remove('pre-hide');
        var ring = overlay.querySelector('.page-loader-ring');
        var done = false;
        function doHide() {
            if (done) return;
            done = true;
            overlay.classList.add('pre-hide');
            setTimeout(function() {
                overlay.hidden = true;
                overlay.style.display = 'none';
            }, 250);
        }
        // Tutup tepat 1 putaran penuh: pakai batas animasi CSS, bukan timer.
        if (ring) {
            ring.addEventListener('animationiteration', doHide, { once: true });
        }
        // Pengaman kalau event tak jalan
        setTimeout(doHide, 2000);
    })();
    </script>
    
</body>
</html>
