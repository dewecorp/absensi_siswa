<?php
// Helper tab mobile lintas role (mobile only UI, desktop fallback tabel).
// Dipakai oleh templates/mobile_tab_page.php
if (!function_exists('mapp_role_dir')) {
    function mapp_role_dir(?string $level = null): string
    {
        $level = $level ?? (function_exists('getUserLevel') ? getUserLevel() : '');
        $map = [
            'admin' => 'admin',
            'kepala_madrasah' => 'kepala',
            'tata_usaha' => 'tata_usaha',
            'bendahara' => 'bendahara',
            'guru' => 'guru',
            'wali' => 'wali',
            'siswa' => 'siswa',
        ];
        return $map[$level] ?? 'admin';
    }
}

if (!function_exists('mapp_tab_meta')) {
    function mapp_tab_meta(string $tab): array
    {
        $meta = [
            'akademik' => ['title' => 'Akademik', 'sub' => 'Kelola seluruh data akademik madrasah', 'icon' => 'ri-book-open-line'],
            'supervisi' => ['title' => 'Supervisi', 'sub' => 'Pantau dan kelola kegiatan supervisi', 'icon' => 'ri-survey-line'],
            'laporan' => ['title' => 'Laporan', 'sub' => 'Akses berbagai laporan madrasah', 'icon' => 'ri-bar-chart-2-line'],
            'lainnya' => ['title' => 'Lainnya', 'sub' => 'Menu tambahan dan pengaturan akun', 'icon' => 'ri-more-fill'],
            'informasi' => ['title' => 'Informasi', 'sub' => 'Pengumuman dan agenda terbaru', 'icon' => 'ri-megaphone-line'],
        ];
        return $meta[$tab] ?? ['title' => ucfirst($tab), 'sub' => '', 'icon' => 'ri-apps-2-line'];
    }
}

if (!function_exists('mapp_tab_for_group')) {
    // Klasifikasi judul grup menu -> tab. Urutan penting: supervisi > laporan > akademik.
    function mapp_tab_for_group(string $title): string
    {
        $t = strtolower(trim($title));
        if ($t === '' || $t === 'dashboard' || $t === 'logout') return '';
        $has = static function (array $keys) use ($t): bool {
            foreach ($keys as $k) {
                if (strpos($t, $k) !== false) return true;
            }
            return false;
        };
        if ($has(['supervisi', 'monitoring', 'tindak lanjut', 'instrumen', 'indikator', 'sasaran', 'program kerja', 'agenda', 'rapat'])) {
            return 'supervisi';
        }
        if ($has(['laporan', 'rekap', 'rekapitulasi', 'arsip', 'keuangan', 'rab', 'anggaran', 'surat', 'inventaris', 'backup'])) {
            return 'laporan';
        }
        if ($has(['akademik', 'pembelajaran', 'nilai', 'master', 'data', 'kelas', 'jadwal', 'kehadiran', 'remidi', 'remidial', 'pengayaan', 'tugas', 'materi', 'soal', 'ajar', 'jurnal', 'ekstra', 'kokurikuler', 'konseling', 'pelanggaran', 'pembinaan', 'komunikasi', 'forum', 'diskusi', 'kalender', 'mapel', 'pelajaran'])) {
            return 'akademik';
        }
        return 'lainnya';
    }
}

if (!function_exists('mapp_tab_groups')) {
    // Ambil grup menu untuk satu tab: [['title','icon','items'=>[...]], ...] + single items.
    function mapp_tab_groups(array $menu_items, string $tab): array
    {
        $groups = [];
        $singles = [];
        foreach ($menu_items as $item) {
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '' || $title === 'Dashboard' || $title === 'Logout') continue;
            $has_sub = isset($item['submenu']) && is_array($item['submenu']) && count($item['submenu']) > 0;
            if ($has_sub) {
                $gtab = mapp_tab_for_group($title);
                if ($gtab === $tab) {
                    $groups[] = [
                        'title' => $title,
                        'icon' => $item['icon'] ?? 'fas fa-folder',
                        'items' => array_values($item['submenu']),
                    ];
                }
            } else {
                // Single tanpa submenu masuk 'lainnya' (kecuali tab-nya memang lainnya)
                if ($tab === 'lainnya' && isset($item['url'])) {
                    $singles[] = $item;
                }
            }
        }
        return ['groups' => $groups, 'singles' => $singles];
    }
}

if (!function_exists('mapp_item_icon')) {
    function mapp_item_icon(string $title, string $group_title = ''): string
    {
        $t = strtolower(trim($title));
        $map = [
            'nilai harian' => 'fas fa-calendar-day',
            'nilai tengah semester' => 'fas fa-file-signature',
            'nilai uts' => 'fas fa-file-signature',
            'nilai akhir semester' => 'fas fa-file-alt',
            'nilai uas' => 'fas fa-file-alt',
            'nilai akhir tahun' => 'fas fa-award',
            'nilai pat' => 'fas fa-award',
            'nilai kokurikuler' => 'fas fa-palette',
            'nilai pra ujian' => 'fas fa-clipboard-list',
            'nilai ujian' => 'fas fa-graduation-cap',
            'nilai ujian praktik' => 'fas fa-flask',
            'data nilai ujian' => 'fas fa-table',
            'rekap nilai' => 'fas fa-chart-line',
            'rekap nilai siswa' => 'fas fa-chart-line',
            'program remidi' => 'fas fa-tools',
            'program pengayaan' => 'fas fa-layer-group',
            'tagihan siswa' => 'fas fa-file-invoice-dollar',
            'laporan pembayaran' => 'fas fa-receipt',
            'tabungan siswa' => 'fas fa-piggy-bank',
            'tabungan' => 'fas fa-piggy-bank',
            'data gaji guru' => 'fas fa-money-bill-wave',
            'gaji guru' => 'fas fa-money-bill-wave',
            'data honor & insentif' => 'fas fa-award',
            'honor & insentif' => 'fas fa-award',
            'honor insentif' => 'fas fa-award',
            'kategori anggaran' => 'fas fa-tags',
            'rab madrasah' => 'fas fa-file-invoice',
            'rab ekstrakurikuler' => 'fas fa-trophy',
            'rab ujian' => 'fas fa-clipboard-list',
        ];
        if (isset($map[$t])) {
            return $map[$t];
        }
        foreach ($map as $k => $icon) {
            if (strpos($t, $k) !== false) {
                return $icon;
            }
        }
        $group_map = [
            'keuangan' => 'fas fa-wallet',
            'tabungan' => 'fas fa-piggy-bank',
            'gaji guru' => 'fas fa-money-bill-wave',
            'honor & insentif' => 'fas fa-award',
            'anggaran' => 'fas fa-coins',
        ];
        $g = strtolower(trim($group_title));
        if (isset($group_map[$g])) {
            return $group_map[$g];
        }
        return 'fas fa-file-alt';
    }
}

if (!function_exists('mapp_item_desc')) {
    function mapp_item_desc(string $title): string
    {
        $t = strtolower(trim($title));
        $map = [
            'nilai harian' => 'Input & pantau nilai asesmen formatif harian per materi/KD',
            'nilai tengah semester' => 'Nilai asesmen sumatif tengah semester (PTS/UTS)',
            'nilai uts' => 'Nilai asesmen sumatif tengah semester (PTS/UTS)',
            'nilai akhir semester' => 'Nilai asesmen sumatif akhir semester (PAS/UAS)',
            'nilai uas' => 'Nilai asesmen sumatif akhir semester (PAS/UAS)',
            'nilai akhir tahun' => 'Nilai kenaikan kelas akhir tahun ajaran (PAT)',
            'nilai pat' => 'Nilai kenaikan kelas akhir tahun ajaran (PAT)',
            'nilai kokurikuler' => 'Penilaian projek penguatan profil & kokurikuler madrasah',
            'nilai pra ujian' => 'Nilai simulasi dan uji coba persiapan ujian akhir',
            'nilai ujian' => 'Nilai asesmen madrasah/ujian akhir resmi kelulusan',
            'nilai ujian praktik' => 'Nilai asesmen unjuk kerja & praktik ibadah/mapel',
            'data nilai ujian' => 'Pusat master dan rekapitulasi nilai ujian madrasah',
            'rekap nilai' => 'Rekapitulasi lengkap nilai seluruh mapel & peringkat kelas',
            'rekap nilai siswa' => 'Rekapitulasi lengkap nilai seluruh mapel & peringkat kelas',
            'program remidi' => 'Perencanaan & pelaksanaan tindak lanjut ketuntasan remidi',
            'program pengayaan' => 'Program pendalaman materi bagi siswa berprestasi',
            'tagihan siswa' => 'Cek tunggakan & status pembayaran seluruh siswa',
            'laporan pembayaran' => 'Riwayat pembayaran dan rincian tagihan per siswa',
            'tabungan siswa' => 'Saldo, setoran, penarikan & riwayat tabungan siswa',
            'data gaji guru' => 'Rekap gaji pokok, tunjangan, potongan & gaji bersih',
            'gaji guru' => 'Rekap gaji pokok, tunjangan, potongan & gaji bersih',
            'data honor & insentif' => 'Kelola honor & insentif guru per periode',
            'honor & insentif' => 'Kelola honor & insentif guru per periode',
            'honor insentif' => 'Kelola honor & insentif guru per periode',
            'kategori anggaran' => 'Kelola pos & kategori anggaran madrasah',
            'rab madrasah' => 'Rencana anggaran & realisasi belanja madrasah',
            'rab ekstrakurikuler' => 'Rencana anggaran kegiatan ekstrakurikuler',
            'rab ujian' => 'Rencana anggaran pelaksanaan ujian madrasah',
        ];
        if (isset($map[$t])) {
            return $map[$t];
        }
        foreach ($map as $k => $desc) {
            if (strpos($t, $k) !== false) {
                return $desc;
            }
        }
        return '';
    }
}

if (!function_exists('mapp_render_tab_list')) {
    // Render list-card mobile + search client-side. $search_id unik per halaman.
    function mapp_render_tab_list(array $tab_data, string $search_id = 'mappSearch'): string
    {
        $groups = $tab_data['groups'] ?? [];
        $singles = $tab_data['singles'] ?? [];
        ob_start();
        ?>
        <div class="mapp-tab-wrapper">
            <div class="mapp-search">
                <i class="fas fa-search"></i>
                <input type="text" id="<?php echo htmlspecialchars($search_id, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Cari menu..." autocomplete="off">
            </div>
            <div id="<?php echo htmlspecialchars($search_id, ENT_QUOTES, 'UTF-8'); ?>-wrap">
                <?php if (empty($groups) && empty($singles)): ?>
                    <div class="mapp-tab-list"><div class="mapp-empty">Belum ada menu pada tab ini.</div></div>
                <?php else: ?>
                    <?php foreach ($groups as $gi => $g): ?>
                        <div class="mapp-tab-group"><?php echo htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="mapp-tab-list" data-group="<?php echo htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php foreach ($g['items'] as $ii => $sub): ?>
                                <?php
                                $url = (string)($sub['url'] ?? '#');
                                $stitle = (string)($sub['title'] ?? '-');
                                $surl = (strpos($url, 'http') === 0 || strpos($url, 'javascript:') === 0 || strpos($url, '#') === 0)
                                    ? $url : (function_exists('app_url') ? app_url($url) : $url);
                                $item_icon = function_exists('mapp_item_icon') ? mapp_item_icon($stitle, (string)$g['title']) : ($g['icon'] ?? 'fas fa-folder');
                                $item_desc = function_exists('mapp_item_desc') ? mapp_item_desc($stitle) : '';
                                ?>
                                <a class="mapp-tab-item" data-key="<?php echo htmlspecialchars(strtolower($stitle . ' ' . $item_desc), ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars($surl, ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="mapp-tab-icon mg-c<?php echo ((int)$ii % 8) + 1; ?>"><i class="<?php echo htmlspecialchars($item_icon, ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                                    <span class="mapp-tab-text">
                                        <span class="mapp-tab-title d-block"><?php echo htmlspecialchars($stitle, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="mapp-tab-sub d-block"><?php echo htmlspecialchars($item_desc !== '' ? $item_desc : $g['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!empty($singles)): ?>
                        <div class="mapp-tab-group">Lainnya</div>
                        <div class="mapp-tab-list">
                            <?php foreach ($singles as $si => $s): ?>
                                <?php
                                $url = (string)($s['url'] ?? '#');
                                $stitle = (string)($s['title'] ?? '-');
                                $surl = (strpos($url, 'http') === 0 || strpos($url, 'javascript:') === 0 || strpos($url, '#') === 0)
                                    ? $url : (function_exists('app_url') ? app_url($url) : $url);
                                ?>
                                <a class="mapp-tab-item" data-key="<?php echo htmlspecialchars(strtolower($stitle), ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars($surl, ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="mapp-tab-icon mg-c<?php echo ((int)$si % 8) + 1; ?>"><i class="<?php echo htmlspecialchars($s['icon'] ?? 'fas fa-th', ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                                    <span class="mapp-tab-text">
                                        <span class="mapp-tab-title d-block"><?php echo htmlspecialchars($stitle, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function() {
            var input = document.getElementById('<?php echo addslashes($search_id); ?>');
            if (!input) return;
            input.addEventListener('input', function() {
                var q = (input.value || '').toLowerCase().trim();
                var items = document.querySelectorAll('#<?php echo addslashes($search_id); ?>-wrap .mapp-tab-item');
                items.forEach(function(a) {
                    var key = (a.getAttribute('data-key') || '');
                    a.style.display = (!q || key.indexOf(q) !== -1) ? '' : 'none';
                });
                var groups = document.querySelectorAll('#<?php echo addslashes($search_id); ?>-wrap .mapp-tab-group');
                groups.forEach(function(gh) {
                    var list = gh.nextElementSibling;
                    if (!list || !list.classList.contains('mapp-tab-list')) return;
                    var visible = list.querySelectorAll('.mapp-tab-item:not([style*="none"])').length;
                    var anyVisible = Array.prototype.some.call(list.querySelectorAll('.mapp-tab-item'), function(a) { return a.style.display !== 'none'; });
                    gh.style.display = anyVisible ? '' : 'none';
                    list.style.display = anyVisible ? '' : 'none';
                });
            });
        })();
        </script>
        <?php
        return (string)ob_get_clean();
    }
}
