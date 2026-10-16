<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'tata_usaha', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah', 'tata_usaha'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$can_crud = !$is_admin_or_kepala;

$message = null;

// Handle CRUD Catatan Perkembangan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki hak akses untuk mengubah data ini.'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $id_kelas = (int)($_POST['id_kelas'] ?? 0) ?: null;
        $id_mapel = (int)($_POST['id_mapel'] ?? 0) ?: null;
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $kategori = trim((string)($_POST['kategori'] ?? 'Akademik'));
        if ($kategori === '') $kategori = 'Akademik';
        $ringkasan = trim((string)($_POST['ringkasan'] ?? ''));
        $kendala = trim((string)($_POST['kendala'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Aktif', 'Selesai', 'Dalam Pemantauan'], true) ? $_POST['status'] : 'Aktif';

        $perkembangan_akademik = trim((string)($_POST['perkembangan_akademik'] ?? ''));
        $perkembangan_sikap = trim((string)($_POST['perkembangan_sikap'] ?? ''));
        $perkembangan_keterampilan = trim((string)($_POST['perkembangan_keterampilan'] ?? ''));
        $keaktifan = trim((string)($_POST['keaktifan'] ?? ''));
        $potensi = trim((string)($_POST['potensi'] ?? ''));
        $catatan_guru = trim((string)($_POST['catatan_guru'] ?? ''));
        $rekomendasi = trim((string)($_POST['rekomendasi'] ?? ''));

        // Auto dapatkan id_kelas dari siswa bila tidak dipilih
        if (!$id_kelas && $id_siswa > 0) {
            $stk = $pdo->prepare("SELECT id_kelas FROM tb_siswa WHERE id_siswa = ?");
            $stk->execute([$id_siswa]);
            $id_kelas = (int)$stk->fetchColumn() ?: null;
        }

        if ($id_siswa <= 0 || $ringkasan === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa dan tuliskan Ringkasan Perkembangan.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_catatan_perkembangan (
                            id_guru, id_siswa, id_kelas, id_mapel, tanggal, kategori,
                            ringkasan, kendala, tindak_lanjut, status,
                            perkembangan_akademik, perkembangan_sikap, perkembangan_keterampilan,
                            keaktifan, potensi, catatan_guru, rekomendasi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $id_mapel, $tanggal, $kategori,
                        $ringkasan, $kendala, $tindak_lanjut, $status,
                        $perkembangan_akademik, $perkembangan_sikap, $perkembangan_keterampilan,
                        $keaktifan, $potensi, $catatan_guru, $rekomendasi
                    ]);
                    $message = ['type' => 'success', 'text' => 'Catatan perkembangan berhasil ditambahkan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_catatan_perkembangan SET
                            id_siswa = ?, id_kelas = ?, id_mapel = ?, tanggal = ?, kategori = ?,
                            ringkasan = ?, kendala = ?, tindak_lanjut = ?, status = ?,
                            perkembangan_akademik = ?, perkembangan_sikap = ?, perkembangan_keterampilan = ?,
                            keaktifan = ?, potensi = ?, catatan_guru = ?, rekomendasi = ?
                        WHERE id = ? AND id_guru = ?
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $id_mapel, $tanggal, $kategori,
                        $ringkasan, $kendala, $tindak_lanjut, $status,
                        $perkembangan_akademik, $perkembangan_sikap, $perkembangan_keterampilan,
                        $keaktifan, $potensi, $catatan_guru, $rekomendasi,
                        $id, $guru_id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Catatan perkembangan berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_catatan_perkembangan WHERE id = ? AND id_guru = ?")->execute([$id, $guru_id]);
            $message = ['type' => 'success', 'text' => 'Catatan perkembangan berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
        }
    }
}

// 1. List kelas
$all_kelas_list = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($is_admin_or_kepala) {
    $kelas_list = $all_kelas_list;
    $mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran WHERE (jenis_mapel IS NULL OR jenis_mapel = 'Akademik') AND nama_mapel NOT LIKE '%Asmaul Husna%' AND nama_mapel NOT LIKE '%Upacara%' AND nama_mapel NOT LIKE '%Istirahat%' AND nama_mapel NOT LIKE '%Kepramukaan%' AND nama_mapel NOT LIKE '%Ekstrakurikuler%' ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $kelas_list = getGuruTaughtClasses($pdo, $guru_id);
    if (empty($kelas_list)) {
        $kelas_list = $all_kelas_list;
    }
    $mapel_list = getGuruTaughtMapels($pdo, $guru_id);
    if (empty($mapel_list)) {
        $mapel_list = $pdo->query("SELECT id_mapel, nama_mapel FROM tb_mata_pelajaran WHERE (jenis_mapel IS NULL OR jenis_mapel = 'Akademik') AND nama_mapel NOT LIKE '%Asmaul Husna%' AND nama_mapel NOT LIKE '%Upacara%' AND nama_mapel NOT LIKE '%Istirahat%' AND nama_mapel NOT LIKE '%Kepramukaan%' AND nama_mapel NOT LIKE '%Ekstrakurikuler%' ORDER BY nama_mapel ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 3. Siswa
$all_siswa_list = $pdo->query("
    SELECT s.id_siswa, s.nama_siswa, s.nisn, s.id_kelas, k.nama_kelas
    FROM tb_siswa s
    LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
    ORDER BY k.nama_kelas ASC, s.nama_siswa ASC
")->fetchAll(PDO::FETCH_ASSOC);

$taught_kelas_ids = array_filter(array_map(function($k) { return (int)($k['id_kelas'] ?? 0); }, $kelas_list));
if (!empty($taught_kelas_ids) && !$is_admin_or_kepala) {
    $in_clause = implode(',', $taught_kelas_ids);
    $siswa_list = $pdo->query("
        SELECT s.id_siswa, s.nama_siswa, s.nisn, s.id_kelas, k.nama_kelas
        FROM tb_siswa s
        LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
        WHERE s.id_kelas IN ($in_clause)
        ORDER BY k.nama_kelas ASC, s.nama_siswa ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $siswa_list = $all_siswa_list;
}

// 4. Data Master Pemetaan Perkembangan
$stMaster = $pdo->query("
    SELECT id, aspek, kendala, tindak_lanjut, ringkasan,
           perkembangan_akademik, perkembangan_sikap, perkembangan_keterampilan,
           keaktifan, potensi, rekomendasi
    FROM tb_master_perkembangan
    ORDER BY aspek ASC, id ASC
");
$master_perkembangan = $stMaster->fetchAll(PDO::FETCH_ASSOC);

// Daftar Aspek unik lengkap
$default_aspek = ['Akademik', 'Sikap & Karakter', 'Keterampilan', 'Kedisiplinan', 'Ibadah & Spiritual', 'Sosial Emosional', 'Keaktifan & Partisipasi', 'Potensi & Minat'];
$master_aspek = array_values(array_unique(array_filter(array_column($master_perkembangan, 'aspek'))));
$cat_aspek = $pdo->query("SELECT DISTINCT kategori FROM tb_catatan_perkembangan WHERE kategori IS NOT NULL AND kategori != ''")->fetchAll(PDO::FETCH_COLUMN);
$aspek_options = array_values(array_unique(array_filter(array_merge($default_aspek, $master_aspek, $cat_aspek))));
sort($aspek_options);

// Filters
$f_kategori = trim((string)($_GET['f_kategori'] ?? ''));
$f_kelas = (int)($_GET['f_kelas'] ?? 0);
$f_mapel = (int)($_GET['f_mapel'] ?? 0);
$f_siswa = (int)($_GET['f_siswa'] ?? 0);
$f_status = trim((string)($_GET['f_status'] ?? ''));

if ($is_admin_or_kepala) {
    $where = ["1=1"];
    $params = [];
} else {
    $where = ["c.id_guru = ?"];
    $params = [$guru_id];
}

if ($f_kategori !== '') {
    $where[] = "c.kategori = ?";
    $params[] = $f_kategori;
}
if ($f_kelas > 0) {
    $where[] = "c.id_kelas = ?";
    $params[] = $f_kelas;
}
if ($f_mapel > 0) {
    $where[] = "c.id_mapel = ?";
    $params[] = $f_mapel;
}
if ($f_siswa > 0) {
    $where[] = "c.id_siswa = ?";
    $params[] = $f_siswa;
}
if ($f_status !== '') {
    $where[] = "c.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT c.*,
           COALESCE(NULLIF(c.id_kelas, 0), s.id_kelas) AS id_kelas,
           s.nama_siswa, s.nisn, s.id_kelas AS siswa_id_kelas,
           k.nama_kelas, m.nama_mapel, g.nama_guru
    FROM tb_catatan_perkembangan c
    JOIN tb_siswa s ON s.id_siswa = c.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = COALESCE(NULLIF(c.id_kelas, 0), s.id_kelas)
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = c.id_mapel
    LEFT JOIN tb_guru g ON g.id_guru = c.id_guru
    WHERE $where_sql
    ORDER BY c.tanggal DESC, c.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('get_initials_catatan')) {
    function get_initials_catatan(string $name): string {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $name);
        $words = preg_split('/\s+/', trim((string)$clean));
        $init = '';
        if (!empty($words[0])) $init .= mb_substr($words[0], 0, 1);
        if (!empty($words[1])) $init .= mb_substr($words[1], 0, 1);
        return strtoupper($init ?: 'S');
    }
}

$page_title = 'Catatan Perkembangan Siswa';
$css_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
];
$js_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
];

$js_page = [<<<'JS'
var masterPerkembangan = 
JS
. json_encode($master_perkembangan) . ";\n" . <<<'JS'
$(document).ready(function() {
    $('#cari-kartu-catatan').on('input', function() {
        var query = $(this).val().toLowerCase().trim();
        var visibleCount = 0;
        $('.catatan-card-col').each(function() {
            var cardText = $(this).text().toLowerCase();
            if (cardText.indexOf(query) !== -1) {
                $(this).show();
                visibleCount++;
            } else {
                $(this).hide();
            }
        });
        if (visibleCount === 0 && query !== '') {
            $('#no-catatan-search').show();
        } else {
            $('#no-catatan-search').hide();
        }
        $('#count-kartu-catatan').text(visibleCount);
    });

    if ($.fn.select2) {
        $('.select2-siswa').select2({
            dropdownParent: $('#modalCatatan'),
            placeholder: '-- Cari & Pilih Siswa --',
            allowClear: true,
            width: '100%'
        });
        $('.select2-filter-siswa').select2({
            placeholder: '-- Siswa --',
            allowClear: true,
            width: '100%'
        }).on('change', function() {
            $('#formFilterCatatan').submit();
        });
    }

    // Salin opsi siswa asli untuk filter dinamis per kelas
    var allSiswaOptions = $('#inp_siswa option').clone();

    function filterSiswaByKelas(selectedKelas) {
        var currentSiswa = $('#inp_siswa').val();
        $('#inp_siswa').empty().append(allSiswaOptions.clone());
        if (selectedKelas && selectedKelas !== '') {
            $('#inp_siswa option').each(function() {
                var k = $(this).data('kelas');
                if ($(this).val() !== '' && String(k) !== String(selectedKelas)) {
                    $(this).remove();
                }
            });
        }
        if (currentSiswa && $('#inp_siswa option[value="' + currentSiswa + '"]').length) {
            $('#inp_siswa').val(currentSiswa).trigger('change.select2');
        } else {
            $('#inp_siswa').val('').trigger('change.select2');
        }
    }

    $('#inp_kelas').on('change', function() {
        filterSiswaByKelas($(this).val());
    });

    $('#inp_siswa').on('change', function() {
        var opt = $('#inp_siswa option:selected');
        var k = opt.data('kelas');
        if (k && !$('#inp_kelas').val()) {
            $('#inp_kelas').val(k);
        }
    });

    // ==========================================
    // ALUR PERKEMBANGAN CERDAS:
    // 1. Aspek -> 2. Kendala -> 3. Tindak Lanjut -> 4. Ringkasan
    // ==========================================

    function shortTpl(s, n) {
        s = (s || '').toString().replace(/\s+/g, ' ').trim();
        n = n || 100;
        return s.length > n ? s.substr(0, n) + '...' : s;
    }

    function populateDetailDropdowns(selAspek) {
        $('.sel-detail-tpl').each(function() {
            var field = $(this).data('field');
            var label = $(this).find('option:first').text() || '-- Pilih --';
            $(this).empty().append($('<option>').val('').text(label));
            if (!selAspek || !field) return;
            var filtered = masterPerkembangan.filter(function(m) {
                return (m.aspek || '').toLowerCase() === selAspek.toLowerCase() && m[field];
            });
            filtered.forEach(function(m) {
                var $opt = $('<option>').val(m[field]).text(shortTpl(m[field], 100));
                $(this).append($opt);
            }.bind(this));
        });
    }

    // 1. Ketika Aspek Perkembangan berubah
    $('#inp_kategori').on('change', function() {
        var selAspek = $(this).val();
        $('#sel_template_kendala').empty().append('<option value="">-- Pilih Template Kendala --</option>');
        $('#sel_template_tindak_lanjut').empty().append('<option value="">-- Pilih Kendala/Aspek terlebih dahulu --</option>');
        populateDetailDropdowns(selAspek);

        if (!selAspek) return;

        var filtered = masterPerkembangan.filter(function(m) {
            return m.aspek.toLowerCase() === selAspek.toLowerCase();
        });

        if (filtered.length > 0) {
            filtered.forEach(function(item) {
                var shortText = item.kendala.length > 80 ? item.kendala.substr(0, 80) + '...' : item.kendala;
                var $opt = $('<option>').val(item.id).text(shortText);
                $opt.data('item', item);
                $('#sel_template_kendala').append($opt);
            });
            $('#wrapTemplateKendala').show();
        }
    });

    // Terapkan satu template master ke seluruh kolom catatan (kendala, tindak lanjut,
    // ringkasan, + 6 rincian aspek). Dipakai saat guru pilih dropdown template.
    function applyMasterItem(item, overwriteRingkasan) {
        if (!item) return;
        $('#inp_kendala').val(item.kendala || '');
        autogrow($('#inp_kendala'));
        $('#inp_tindak_lanjut').val(item.tindak_lanjut || '');
        autogrow($('#inp_tindak_lanjut'));
        if (overwriteRingkasan || !$('#inp_ringkasan').val().trim()) {
            $('#inp_ringkasan').val(item.ringkasan || '');
        }
        autogrow($('#inp_ringkasan'));
        $('#inp_akademik').val(item.perkembangan_akademik || '');
        $('#inp_sikap').val(item.perkembangan_sikap || '');
        $('#inp_keterampilan').val(item.perkembangan_keterampilan || '');
        $('#inp_keaktifan').val(item.keaktifan || '');
        $('#inp_potensi').val(item.potensi || '');
        $('#inp_rekomendasi').val(item.rekomendasi || '');
        $('#modalCatatan textarea').each(function() { autogrow($(this)); });
    }

    // 2. Ketika Template Kendala dipilih -> isi kendala + pasangannya (tindak lanjut,
    // ringkasan, rincian aspek). Guru tetap bisa ganti tiap dropdown/textarea manual.
    $('#sel_template_kendala').on('change', function() {
        var item = $('#sel_template_kendala option:selected').data('item');
        if (!item) return;

        // Siapkan pilihan tindak lanjut yang cocok + tandai pasangannya
        var selAspek = $('#inp_kategori').val();
        $('#sel_template_tindak_lanjut').empty().append('<option value="">-- Pilih Template Tindak Lanjut --</option>');

        var filtered = masterPerkembangan.filter(function(m) {
            return m.aspek.toLowerCase() === selAspek.toLowerCase();
        });

        filtered.forEach(function(m) {
            var shortTl = m.tindak_lanjut.length > 80 ? m.tindak_lanjut.substr(0, 80) + '...' : m.tindak_lanjut;
            var $opt = $('<option>').val(m.id).text(shortTl);
            $opt.data('item', m);
            if (String(m.id) === String(item.id)) $opt.prop('selected', true);
            $('#sel_template_tindak_lanjut').append($opt);
        });
        $('#wrapTemplateTindakLanjut').show();

        // Auto-isi seperti semula: kendala, tindak lanjut, ringkasan, + 6 rincian aspek
        applyMasterItem(item, true);
    });

    // 3. Ketika Template Tindak Lanjut dipilih manual -> isi tindak lanjut + ringkasan + rincian aspek.
    $('#sel_template_tindak_lanjut').on('change', function() {
        var item = $('#sel_template_tindak_lanjut option:selected').data('item');
        if (!item) return;

        $('#inp_tindak_lanjut').val(item.tindak_lanjut);
        autogrow($('#inp_tindak_lanjut'));
        if (!$('#inp_ringkasan').val().trim()) {
            $('#inp_ringkasan').val(item.ringkasan || '');
            autogrow($('#inp_ringkasan'));
        }
        $('#inp_akademik').val(item.perkembangan_akademik || '');
        $('#inp_sikap').val(item.perkembangan_sikap || '');
        $('#inp_keterampilan').val(item.perkembangan_keterampilan || '');
        $('#inp_keaktifan').val(item.keaktifan || '');
        $('#inp_potensi').val(item.potensi || '');
        $('#inp_rekomendasi').val(item.rekomendasi || '');
        $('#modalCatatan textarea').each(function() { autogrow($(this)); });
    });

    // 3b. Dropdown tiap rincian aspek detail -> isi HANYA kolomnya masing-masing saat guru memilih.
    $(document).on('change', '.sel-detail-tpl', function() {
        var target = $(this).data('target');
        var val = $(this).val() || '';
        if (target && val) {
            $(target).val(val);
            autogrow($(target));
        }
    });

    // Textarea auto-tinggi agar teks panjang tidak terpotong
    function autogrow($ta) {
        if (!$ta || !$ta.length) return;
        $ta.css('height', 'auto');
        $ta.css('height', ($ta[0].scrollHeight + 4) + 'px');
    }
    $(document).on('input', '#modalCatatan textarea', function() { autogrow($(this)); });
    $('#modalCatatan').on('shown.bs.modal', function() {
        $('#modalCatatan textarea').each(function() { autogrow($(this)); });
    });

    // 4. Tombol Susun Ringkasan Otomatis dari Kendala & Tindak Lanjut
    $('#btnAutoRangkum').on('click', function() {
        var asp = $('#inp_kategori').val() || 'perkembangan';
        var ken = $('#inp_kendala').val().trim();
        var tl = $('#inp_tindak_lanjut').val().trim();
        if (!ken && !tl) {
            Swal.fire({ icon: 'info', title: 'Perhatian', text: 'Pilih atau tuliskan kendala serta tindak lanjut terlebih dahulu.' });
            return;
        }
        var summary = 'Pada aspek ' + asp + ', peserta didik menghadapi kendala: ' + (ken || '-') + '; dilakukan tindak lanjut: ' + (tl || '-') + '.';
        $('#inp_ringkasan').val(summary);
    });

    // Modal Tambah -> kosongkan semua, dropdown tetap manual (tidak auto-fill)
    $('#btnTambahCatatan').on('click', function() {
        $('#formCatatanAction').val('tambah');
        $('#catatanId').val('');
        $('#modalCatatanTitle').text('Tambah Catatan Perkembangan');
        $('#formCatatan')[0].reset();
        $('#inp_kelas').val('');
        filterSiswaByKelas('');
        $('#inp_siswa').val('').trigger('change.select2');
        $('#sel_template_kendala').empty().append('<option value="">-- Pilih Aspek terlebih dahulu --</option>');
        $('#sel_template_tindak_lanjut').empty().append('<option value="">-- Pilih Kendala/Aspek terlebih dahulu --</option>');
        populateDetailDropdowns('');
        $('.sel-detail-tpl').val('');
        $('#modalCatatan textarea').css('height', 'auto');
        $('#inp_kategori').val('').trigger('change');
        $('#modalCatatan select').each(function() {
            if (typeof this.gdsRefresh === 'function') this.gdsRefresh();
        });
        $('#modalCatatan').modal('show');
    });

    // Modal Edit
    $(document).on('click', '.btn-edit-catatan', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(e) {}
        }
        if (!data || typeof data !== 'object') return;

        $('#formCatatanAction').val('edit');
        $('#catatanId').val(data.id);
        $('#modalCatatanTitle').text('Edit Catatan Perkembangan');

        // 1. Set Kelas
        var targetKelas = data.id_kelas || data.siswa_id_kelas || '';
        if (targetKelas) {
            var $optKelas = $('#inp_kelas option[value="' + targetKelas + '"]');
            if ($optKelas.length === 0 && data.nama_kelas) {
                $('#inp_kelas').append($('<option>').val(targetKelas).text('Kelas ' + data.nama_kelas));
            }
            $('#inp_kelas').val(String(targetKelas));
        } else {
            $('#inp_kelas').val('');
        }

        // Filter siswa berdasar kelas target, lalu set siswa terpilih
        filterSiswaByKelas(targetKelas);
        if (data.id_siswa) {
            $('#inp_siswa').val(String(data.id_siswa)).trigger('change.select2');
        }

        // 2. Set Mapel, Tanggal, Status
        $('#inp_mapel').val(data.id_mapel || '');
        $('#inp_tanggal').val(data.tanggal);
        $('#inp_status').val(data.status || 'Aktif');

        // 3. Set Kategori / Aspek Perkembangan
        var targetKategori = (data.kategori || '').trim();
        if (targetKategori) {
            var matchedKategori = false;
            $('#inp_kategori option').each(function() {
                if ($(this).val().toLowerCase() === targetKategori.toLowerCase()) {
                    $(this).prop('selected', true);
                    targetKategori = $(this).val(); // gunakan value presisi yang ada
                    matchedKategori = true;
                    return false;
                }
            });
            if (!matchedKategori) {
                $('#inp_kategori').append($('<option>').val(targetKategori).text(targetKategori).prop('selected', true));
            }
            $('#inp_kategori').val(targetKategori);
        } else {
            $('#inp_kategori').val('');
        }

        // 4. Populate template kendala & tindak lanjut sesuai aspek
        populateDetailDropdowns(targetKategori);
        var curKendala = (data.kendala || '').toString().trim();
        var curTl = (data.tindak_lanjut || '').toString().trim();
        $('#sel_template_kendala').empty().append('<option value="">-- Pilih Template Kendala --</option>');
        $('#sel_template_tindak_lanjut').empty().append('<option value="">-- Pilih Template Tindak Lanjut --</option>');
        var filteredEdit = masterPerkembangan.filter(function(m) {
            return (m.aspek || '').toLowerCase() === targetKategori.toLowerCase();
        });
        filteredEdit.forEach(function(m) {
            var $o1 = $('<option>').val(m.id).text(shortTpl(m.kendala, 100));
            $o1.data('item', m);
            if (m.kendala && m.kendala.trim() === curKendala) $o1.prop('selected', true);
            $('#sel_template_kendala').append($o1);
            var $o2 = $('<option>').val(m.id).text(shortTpl(m.tindak_lanjut, 100));
            $o2.data('item', m);
            if (m.tindak_lanjut && m.tindak_lanjut.trim() === curTl) $o2.prop('selected', true);
            $('#sel_template_tindak_lanjut').append($o2);
        });

        // Samakan dropdown rincian aspek dengan nilai tersimpan (bila cocok persis)
        $('.sel-detail-tpl').each(function() {
            var field = $(this).data('field');
            var cur = '';
            if (field === 'perkembangan_akademik') cur = data.perkembangan_akademik || '';
            else if (field === 'perkembangan_sikap') cur = data.perkembangan_sikap || '';
            else if (field === 'perkembangan_keterampilan') cur = data.perkembangan_keterampilan || '';
            else if (field === 'keaktifan') cur = data.keaktifan || '';
            else if (field === 'potensi') cur = data.potensi || '';
            else if (field === 'rekomendasi') cur = data.rekomendasi || '';
            if (cur) {
                var matched = false;
                $(this).find('option').each(function() {
                    if ($(this).val() === cur) { $(this).prop('selected', true); matched = true; }
                });
                if (!matched) $(this).val('');
            }
        });

        // 5. Isi textarea
        $('#inp_ringkasan').val(data.ringkasan || '');
        $('#inp_kendala').val(data.kendala || '');
        $('#inp_tindak_lanjut').val(data.tindak_lanjut || '');

        $('#inp_akademik').val(data.perkembangan_akademik || '');
        $('#inp_sikap').val(data.perkembangan_sikap || '');
        $('#inp_keterampilan').val(data.perkembangan_keterampilan || '');
        $('#inp_keaktifan').val(data.keaktifan || '');
        $('#inp_potensi').val(data.potensi || '');
        $('#inp_catatan_guru').val(data.catatan_guru || '');
        $('#inp_rekomendasi').val(data.rekomendasi || '');

        // 6. Refresh gds custom button jika ada
        $('#modalCatatan select').each(function() {
            if (typeof this.gdsRefresh === 'function') this.gdsRefresh();
        });

        $('#modalCatatan').modal('show');
        setTimeout(function() {
            $('#modalCatatan select').each(function() {
                if (typeof this.gdsRefresh === 'function') this.gdsRefresh();
            });
            $('#modalCatatan textarea').each(function() { autogrow($(this)); });
        }, 120);
    });

    // Detail & Timeline Modal
    $(document).on('click', '.btn-detail-catatan', function() {
        var data = $(this).data('json');
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_mapel').text(data.nama_mapel || '-');
        $('#det_tanggal').text(data.tanggal);
        $('#det_kategori').text(data.kategori);
        $('#det_status').text(data.status);

        $('#det_ringkasan').text(data.ringkasan || '-');
        $('#det_kendala').text(data.kendala || '-');
        $('#det_tindak_lanjut').text(data.tindak_lanjut || '-');
        $('#det_akademik').text(data.perkembangan_akademik || '-');
        $('#det_sikap').text(data.perkembangan_sikap || '-');
        $('#det_keterampilan').text(data.perkembangan_keterampilan || '-');
        $('#det_keaktifan').text(data.keaktifan || '-');
        $('#det_potensi').text(data.potensi || '-');
        $('#det_catatan_guru').text(data.catatan_guru || '-');
        $('#det_rekomendasi').text(data.rekomendasi || '-');

        $('#timelineContainer').html('<div class="text-center p-3"><i class="fas fa-spinner fa-spin"></i> Memuat timeline...</div>');
        $.ajax({
            url: 'catatan_perkembangan.php?ajax_timeline=1&id_siswa=' + data.id_siswa,
            dataType: 'json',
            success: function(timeline) {
                if (!timeline || !timeline.length) {
                    $('#timelineContainer').html('<div class="text-muted p-3 text-center">Belum ada riwayat perkembangan lainnya.</div>');
                    return;
                }
                var html = '<div class="activities">';
                timeline.forEach(function(item) {
                    html += '<div class="activity">';
                    html += '  <div class="activity-icon bg-primary text-white shadow-primary"><i class="fas fa-chart-line"></i></div>';
                    html += '  <div class="activity-detail">';
                    html += '    <div class="mb-2">';
                    html += '      <span class="text-job text-primary font-weight-bold">' + item.tanggal_formatted + '</span>';
                    html += '      <span class="bullet"></span>';
                    html += '      <span class="badge badge-info">' + item.kategori + '</span>';
                    html += '    </div>';
                    html += '    <p class="font-weight-bold mb-1">' + item.ringkasan + '</p>';
                    if (item.kendala) html += '    <p class="mb-1 text-danger small"><strong>Kendala:</strong> ' + item.kendala + '</p>';
                    if (item.tindak_lanjut) html += '    <p class="mb-0 text-success small"><strong>Tindak Lanjut:</strong> ' + item.tindak_lanjut + '</p>';
                    html += '  </div>';
                    html += '</div>';
                });
                html += '</div>';
                $('#timelineContainer').html(html);
            }
        });

        $('#modalDetailPerkembangan').modal('show');
    });

    $(document).on('click', '.btn-hapus-catatan', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Catatan?',
            text: 'Catatan perkembangan untuk ' + nama + ' akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusId').val(id);
                $('#formHapus').submit();
            }
        });
    });
});
JS
];

// AJAX Timeline endpoint handler
if (isset($_GET['ajax_timeline']) && (int)$_GET['ajax_timeline'] === 1) {
    header('Content-Type: application/json; charset=UTF-8');
    $sid = (int)($_GET['id_siswa'] ?? 0);
    $stTimeline = $pdo->prepare("
        SELECT id, tanggal, kategori, ringkasan, kendala, tindak_lanjut, status
        FROM tb_catatan_perkembangan
        WHERE id_siswa = ? AND id_guru = ?
        ORDER BY tanggal DESC, id DESC
    ");
    $stTimeline->execute([$sid, $guru_id]);
    $t_rows = $stTimeline->fetchAll(PDO::FETCH_ASSOC);
    foreach ($t_rows as &$item) {
        $item['tanggal_formatted'] = date('d F Y', strtotime($item['tanggal']));
    }
    unset($item);
    echo json_encode($t_rows);
    exit;
}

if (!empty($message)) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
.catatan-actions { display: flex; flex-wrap: wrap; gap: 6px; row-gap: 6px; }
.catatan-actions .btn { margin-bottom: 4px; white-space: nowrap; }
#modalCatatan textarea.catatan-ta { min-height: 110px; line-height: 1.55; resize: vertical; overflow-y: auto; }
#modalCatatan select.sel-detail-tpl { white-space: normal; }
.card-catatan {
    border-radius: 12px;
    border: 1px solid #e2e8f0 !important;
    background: #ffffff;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.card-catatan:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(103, 119, 239, 0.08) !important;
    border-color: #cbd5e1 !important;
}
.catatan-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6777ef, #3abaf4);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(103, 119, 239, 0.25);
}
.bullet {
    display: inline-block;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background-color: #cbd5e1;
    margin: 0 4px;
}
.select2-container--default .select2-selection--single {
    border: 1px solid #ced4da;
    height: 31px;
    border-radius: 0.2rem;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 29px;
    font-size: 13px;
    padding-left: 8px;
    padding-right: 20px;
    text-align: center;
    color: #34395e !important;
    font-weight: 600;
}
.select2-container--default .select2-selection--single .select2-selection__placeholder {
    color: #34395e !important;
    font-weight: 600;
}
.select2-dropdown .select2-results__option {
    text-align: center;
    color: #34395e;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 29px;
}
</style>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Catatan Perkembangan Siswa</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Section -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light py-2">
                    <h6 class="mb-0 text-dark"><i class="fas fa-filter mr-1 text-primary"></i> Filter Perkembangan Siswa</h6>
                </div>
                <div class="card-body py-3">
                    <form method="GET" id="formFilterCatatan" class="form-row align-items-center">
                        <?php if (isset($_GET['session_type'])): ?>
                            <input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>">
                        <?php endif; ?>
                        <div class="col-md-4 col-lg mb-2">
                            <select name="f_kelas" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Kelas --</option>
                                <?php foreach ($kelas_list as $k): ?>
                                    <option value="<?= (int)$k['id_kelas'] ?>" <?= $f_kelas === (int)$k['id_kelas'] ? 'selected' : '' ?>>
                                        Kelas <?= htmlspecialchars($k['nama_kelas']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg mb-2">
                            <select name="f_mapel" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Mata Pelajaran --</option>
                                <?php foreach ($mapel_list as $m): ?>
                                    <option value="<?= (int)$m['id_mapel'] ?>" <?= $f_mapel === (int)$m['id_mapel'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['nama_mapel']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg mb-2">
                            <select name="f_kategori" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Aspek Perkembangan --</option>
                                <?php foreach ($aspek_options as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>" <?= $f_kategori === $cat ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg mb-2">
                            <select name="f_siswa" id="f_siswa" class="form-control form-control-sm select2-filter-siswa">
                                <option value="">-- Siswa --</option>
                                <?php foreach ($siswa_list as $sw): ?>
                                    <option value="<?= (int)$sw['id_siswa'] ?>" <?= $f_siswa === (int)$sw['id_siswa'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sw['nama_siswa']) ?> (Kelas <?= htmlspecialchars($sw['nama_kelas'] ?? '-') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg mb-2">
                            <select name="f_status" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Status --</option>
                                <option value="Aktif" <?= $f_status === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="Dalam Pemantauan" <?= $f_status === 'Dalam Pemantauan' ? 'selected' : '' ?>>Dalam Pemantauan</option>
                                <option value="Selesai" <?= $f_status === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                            </select>
                        </div>
                        <div class="col-12 mt-1">
                            <a href="catatan_perkembangan.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode($_GET['session_type']) : '' ?>" class="btn btn-secondary btn-sm ml-1"><i class="fas fa-undo mr-1"></i> Reset</a>
                            <small class="text-muted ml-2">Filter otomatis tersubmit saat diubah.</small>
                        </div>
                        <?php
                        $qs_all = $_GET;
                        unset($qs_all['download'], $qs_all['mode']);
                        $url_cetak = 'export_catatan_perkembangan_pdf.php?' . http_build_query(array_merge($qs_all, ['mode' => 'print']));
                        $url_xls = 'export_catatan_perkembangan_excel.php?' . http_build_query($qs_all);
                        ?>
                        <div class="col-12 mt-2 pt-2 border-top">
                            <div class="catatan-actions">
                                <a href="data_perkembangan.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode($_GET['session_type']) : '' ?>" class="btn btn-info btn-sm">
                                    <i class="fas fa-sitemap mr-1"></i> Data Pemetaan
                                </a>
                                <a href="<?= htmlspecialchars($url_cetak) ?>" target="_blank" class="btn btn-danger btn-sm" title="Cetak / Simpan PDF sesuai filter">
                                    <i class="fas fa-print mr-1"></i> Cetak / PDF
                                </a>
                                <a href="<?= htmlspecialchars($url_xls) ?>" class="btn btn-success btn-sm" title="Ekspor Excel sesuai filter">
                                    <i class="fas fa-file-excel mr-1"></i> Excel
                                </a>
                                <?php if ($can_crud): ?>
                                <button type="button" class="btn btn-primary btn-sm" id="btnTambahCatatan">
                                    <i class="fas fa-plus mr-1"></i> Tambah Catatan
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Daftar Catatan Perkembangan Siswa (Bentuk Kartu Informatif) -->
            <div class="card mb-4 border-0 shadow-sm" style="border-radius: 14px;">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3" style="border-radius: 14px 14px 0 0; border-bottom: 1px solid #f1f5f9; gap: 10px;">
                    <div>
                        <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-id-card-alt text-primary mr-2"></i>Catatan Perkembangan Siswa</h4>
                        <small class="text-muted">Menampilkan <span id="count-kartu-catatan" class="font-weight-bold text-primary"><?= count($rows) ?></span> catatan perkembangan</small>
                    </div>
                    <div class="d-flex align-items-center" style="gap: 8px;">
                        <div class="input-group input-group-sm" style="width: 240px;">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-light border-right-0"><i class="fas fa-search text-muted"></i></span>
                            </div>
                            <input type="text" id="cari-kartu-catatan" class="form-control form-control-sm border-left-0" placeholder="Cari siswa / mapel...">
                        </div>
                    </div>
                </div>
                <div class="card-body bg-light p-3 p-md-4" style="border-radius: 0 0 14px 14px;">
                    <?php if (empty($rows)): ?>
                        <div class="text-center py-5 bg-white rounded shadow-sm border">
                            <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted font-weight-bold">Belum Ada Catatan Perkembangan</h5>
                            <p class="text-muted small mb-0">Silakan tambahkan catatan baru atau sesuaikan filter pencarian di atas.</p>
                        </div>
                    <?php else: ?>
                        <div class="row" id="container-kartu-catatan" style="row-gap: 16px;">
                            <div class="col-12" id="no-catatan-search" style="display: none;">
                                <div class="text-center py-5 bg-white rounded shadow-sm border">
                                    <i class="fas fa-search fa-2x text-muted mb-2"></i>
                                    <p class="text-muted small mb-0">Tidak ada catatan perkembangan yang cocok dengan kata kunci pencarian.</p>
                                </div>
                            </div>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $st_badge = 'badge-success';
                                $st_icon = 'fas fa-check-circle';
                                if ($r['status'] === 'Dalam Pemantauan') {
                                    $st_badge = 'badge-warning';
                                    $st_icon = 'fas fa-eye';
                                } elseif ($r['status'] === 'Selesai') {
                                    $st_badge = 'badge-secondary';
                                    $st_icon = 'fas fa-flag-checkered';
                                }

                                $has_kendala = (!empty($r['kendala']) && trim($r['kendala']) !== '-');
                                $has_tl = (!empty($r['tindak_lanjut']) && trim($r['tindak_lanjut']) !== '-');
                                ?>
                                <div class="col-12 col-md-6 col-xl-6 d-flex align-items-stretch catatan-card-col">
                                    <div class="card w-100 border-0 shadow-sm card-catatan d-flex flex-column justify-content-between mb-0" style="overflow: hidden;">
                                        <div class="card-body p-3 p-md-3.5">
                                            <!-- Top Bar: Avatar + Identitas Siswa + Status -->
                                            <div class="d-flex justify-content-between align-items-start mb-2.5 pb-2 border-bottom">
                                                <div class="d-flex align-items-center" style="gap: 12px;">
                                                    <div class="catatan-avatar">
                                                        <?= htmlspecialchars(get_initials_catatan($r['nama_siswa'])) ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-0 font-weight-bold" style="font-size: 15px; line-height: 1.3;">
                                                            <a href="javascript:void(0)" class="text-dark btn-detail-catatan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' style="text-decoration: none;">
                                                                <?= htmlspecialchars($r['nama_siswa']) ?>
                                                            </a>
                                                        </h6>
                                                        <div class="d-flex flex-wrap align-items-center small text-muted mt-1" style="gap: 6px;">
                                                            <?php if (!empty($r['nisn'])): ?>
                                                                <span>NISN: <?= htmlspecialchars($r['nisn']) ?></span>
                                                                <span class="bullet"></span>
                                                            <?php endif; ?>
                                                            <span class="badge badge-light border text-dark font-weight-bold px-2 py-0.5" style="border-radius: 4px; font-size: 10.5px;">
                                                                <i class="fas fa-chalkboard text-muted mr-1"></i> Kelas <?= htmlspecialchars($r['nama_kelas'] ?? '-') ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div>
                                                    <span class="badge <?= $st_badge ?> px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                        <i class="<?= $st_icon ?> mr-1"></i> <?= htmlspecialchars($r['status']) ?>
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Baris Meta: Tanggal, Aspek, Mapel, Guru -->
                                            <div class="d-flex flex-wrap align-items-center mb-2.5 small text-muted" style="gap: 6px;">
                                                <span class="badge badge-light border text-muted px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="far fa-calendar-alt text-primary mr-1"></i> <?= date('d M Y', strtotime($r['tanggal'])) ?>
                                                </span>
                                                <span class="badge badge-primary px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="fas fa-tag mr-1"></i> <?= htmlspecialchars($r['kategori']) ?>
                                                </span>
                                                <span class="badge badge-light border text-dark px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="fas fa-book-reader text-info mr-1"></i> <?= htmlspecialchars($r['nama_mapel'] ?? 'Umum') ?>
                                                </span>
                                                <?php if (!empty($r['nama_guru'])): ?>
                                                <span class="badge badge-light border text-muted px-2 py-1" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="fas fa-user-tie mr-1"></i> <?= htmlspecialchars($r['nama_guru']) ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Ringkasan Perkembangan (Highlight Box) -->
                                            <div class="p-2.5 mb-2.5 rounded" style="background: #f8fafc; border-left: 3.5px solid #6777ef; border-top: 1px solid #edf2f7; border-right: 1px solid #edf2f7; border-bottom: 1px solid #edf2f7;">
                                                <div class="small font-weight-bold text-dark mb-1 d-flex align-items-center">
                                                    <i class="fas fa-quote-left text-primary mr-1.5" style="font-size: 11px;"></i> Ringkasan Perkembangan:
                                                </div>
                                                <div class="text-dark small" style="line-height: 1.5; font-size: 12.5px;">
                                                    <?= nl2br(htmlspecialchars($r['ringkasan'])) ?>
                                                </div>
                                            </div>

                                            <!-- Kendala Belajar & Tindak Lanjut -->
                                            <?php if ($has_kendala || $has_tl): ?>
                                            <div class="row no-gutters mb-1" style="gap: 8px;">
                                                <?php if ($has_kendala): ?>
                                                <div class="col p-2 rounded" style="background: #fff5f5; border: 1px solid #fed7d7;">
                                                    <div class="small font-weight-bold text-danger mb-0.5" style="font-size: 11.5px;">
                                                        <i class="fas fa-exclamation-triangle mr-1"></i> Kendala:
                                                    </div>
                                                    <div class="small text-danger" style="line-height: 1.35; font-size: 11.5px;">
                                                        <?= htmlspecialchars($r['kendala']) ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>

                                                <?php if ($has_tl): ?>
                                                <div class="col p-2 rounded" style="background: #f0fff4; border: 1px solid #c6f6d5;">
                                                    <div class="small font-weight-bold text-success mb-0.5" style="font-size: 11.5px;">
                                                        <i class="fas fa-clipboard-check mr-1"></i> Tindak Lanjut:
                                                    </div>
                                                    <div class="small text-success" style="line-height: 1.35; font-size: 11.5px;">
                                                        <?= htmlspecialchars($r['tindak_lanjut']) ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Card Footer Aksi -->
                                        <div class="card-footer bg-white pt-2 pb-2.5 px-3 border-top d-flex flex-wrap justify-content-between align-items-center" style="gap: 6px;">
                                            <button type="button" class="btn btn-outline-primary btn-sm btn-detail-catatan font-weight-bold px-2.5" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' style="border-radius: 6px; font-size: 12px;">
                                                <i class="fas fa-eye mr-1"></i> Detail &amp; Timeline
                                            </button>
                                            <div class="d-flex align-items-center" style="gap: 5px;">
                                                <a href="export_catatan_perkembangan_pdf.php?id_siswa=<?= (int)$r['id_siswa'] ?>&mode=print" target="_blank" class="btn btn-danger btn-sm" title="Cetak / Simpan PDF <?= htmlspecialchars($r['nama_siswa']) ?>" style="border-radius: 6px; width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <a href="export_catatan_perkembangan_excel.php?id_siswa=<?= (int)$r['id_siswa'] ?>" class="btn btn-success btn-sm" title="Ekspor Excel <?= htmlspecialchars($r['nama_siswa']) ?>" style="border-radius: 6px; width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-file-excel"></i>
                                                </a>
                                                <?php if ($can_crud): ?>
                                                <button type="button" class="btn btn-warning btn-sm btn-edit-catatan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit Catatan" style="border-radius: 6px; width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm btn-hapus-catatan" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa']) ?>" title="Hapus Catatan" style="border-radius: 6px; width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit Catatan Perkembangan (Alur Cerdas) -->
<div class="modal fade" id="modalCatatan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <form method="POST" id="formCatatan">
                <input type="hidden" name="action" id="formCatatanAction" value="tambah">
                <input type="hidden" name="id" id="catatanId" value="">
                
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title text-primary" id="modalCatatanTitle">Catat Perkembangan Siswa</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                
                <div class="modal-body p-3">
                    <!-- 1. IDENTITAS PEMBELAJARAN (Hanya yang diajar guru login) -->
                    <div class="card border mb-3 bg-light">
                        <div class="card-header bg-white py-2 border-bottom">
                            <span class="font-weight-bold text-dark"><i class="fas fa-user-graduate mr-1 text-primary"></i> 1. Identitas Pembelajaran &amp; Siswa</span>
                            <small class="text-muted ml-2">(Siswa, kelas, dan mapel terfilter khusus yang Anda ajar)</small>
                        </div>
                        <div class="card-body py-3">
                            <div class="row">
                                <div class="col-md-3 form-group mb-2">
                                    <label class="font-weight-bold small">Pilih Kelas</label>
                                    <select name="id_kelas" id="inp_kelas" class="form-control form-control-sm">
                                        <option value="">-- Pilih Kelas --</option>
                                        <?php foreach ($all_kelas_list as $k): ?>
                                            <option value="<?= (int)$k['id_kelas'] ?>">Kelas <?= htmlspecialchars($k['nama_kelas']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Memfilter daftar siswa secara otomatis.</small>
                                </div>
                                <div class="col-md-5 form-group mb-2">
                                    <label class="font-weight-bold small">Pilih Siswa <span class="text-danger">*</span></label>
                                    <select name="id_siswa" id="inp_siswa" class="form-control select2-siswa" required style="width:100%;">
                                        <option value="">-- Cari &amp; Pilih Siswa --</option>
                                        <?php foreach ($all_siswa_list as $s): ?>
                                            <option value="<?= (int)$s['id_siswa'] ?>" data-kelas="<?= (int)$s['id_kelas'] ?>">
                                                <?= htmlspecialchars($s['nama_siswa']) ?> (Kelas <?= htmlspecialchars($s['nama_kelas'] ?? '-') ?> / NISN: <?= htmlspecialchars($s['nisn'] ?? '-') ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group mb-2">
                                    <label class="font-weight-bold small">Mata Pelajaran (Opsional)</label>
                                    <select name="id_mapel" id="inp_mapel" class="form-control form-control-sm">
                                        <option value="">-- Umum / Tanpa Mapel --</option>
                                        <?php foreach ($mapel_list as $m): ?>
                                            <option value="<?= (int)$m['id_mapel'] ?>"><?= htmlspecialchars($m['nama_mapel']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 form-group mb-0 mt-2">
                                    <label class="font-weight-bold small">Tanggal Pengamatan</label>
                                    <input type="date" name="tanggal" id="inp_tanggal" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-3 form-group mb-0 mt-2">
                                    <label class="font-weight-bold small">Status Pemantauan</label>
                                    <select name="status" id="inp_status" class="form-control form-control-sm">
                                        <option value="Aktif">Aktif</option>
                                        <option value="Dalam Pemantauan">Dalam Pemantauan</option>
                                        <option value="Selesai">Selesai</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. ALUR PERKEMBANGAN CERDAS (Aspek -> Kendala -> Tindak Lanjut -> Ringkasan) -->
                    <div class="card border mb-3">
                        <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
                            <span class="font-weight-bold"><i class="fas fa-magic mr-1 text-warning"></i> 2. Alur Catatan Perkembangan Cerdas</span>
                            <a href="data_perkembangan.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode($_GET['session_type']) : '' ?>" target="_blank" class="badge badge-light text-primary font-weight-bold px-2 py-1">
                                <i class="fas fa-cog mr-1"></i> Kelola Master Pemetaan
                            </a>
                        </div>
                        <div class="card-body py-3">
                            <div class="row">
                                <!-- Langkah 1: Aspek Perkembangan -->
                                <div class="col-md-12 form-group mb-3">
                                    <label class="font-weight-bold text-dark d-flex align-items-center">
                                        <span class="badge badge-primary mr-2" style="font-size:12px;">Langkah 1</span>
                                        Pilih Aspek Perkembangan yang Diamati <span class="text-danger">*</span>
                                    </label>
                                    <select name="kategori" id="inp_kategori" class="form-control font-weight-bold border-primary text-primary" required>
                                        <option value="">-- Pilih Aspek Perkembangan --</option>
                                        <?php foreach ($aspek_options as $asp): ?>
                                            <option value="<?= htmlspecialchars($asp) ?>"><?= htmlspecialchars($asp) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Memilih aspek akan otomatis memunculkan pilihan template kendala dan tindak lanjut di bawah.</small>
                                </div>

                                <!-- Langkah 2: Kendala Siswa -->
                                <div class="col-md-6 form-group mb-3">
                                    <label class="font-weight-bold text-dark d-flex align-items-center">
                                        <span class="badge badge-danger mr-2" style="font-size:12px;">Langkah 2</span>
                                        Kendala Pembelajaran
                                    </label>
                                    <div class="mb-1" id="wrapTemplateKendala">
                                        <select id="sel_template_kendala" class="form-control form-control-sm text-danger font-weight-bold mb-1">
                                            <option value="">-- Pilih Aspek terlebih dahulu --</option>
                                        </select>
                                    </div>
                                    <textarea name="kendala" id="inp_kendala" class="form-control catatan-ta" rows="5" style="min-height:120px;" placeholder="Tuliskan kendala atau pilih dari template di atas..."></textarea>
                                </div>

                                <!-- Langkah 3: Tindak Lanjut Guru -->
                                <div class="col-md-6 form-group mb-3">
                                    <label class="font-weight-bold text-dark d-flex align-items-center">
                                        <span class="badge badge-success mr-2" style="font-size:12px;">Langkah 3</span>
                                        Tindak Lanjut / Solusi Guru
                                    </label>
                                    <div class="mb-1" id="wrapTemplateTindakLanjut">
                                        <select id="sel_template_tindak_lanjut" class="form-control form-control-sm text-success font-weight-bold mb-1">
                                            <option value="">-- Pilih Kendala/Aspek terlebih dahulu --</option>
                                        </select>
                                    </div>
                                    <textarea name="tindak_lanjut" id="inp_tindak_lanjut" class="form-control catatan-ta" rows="5" style="min-height:120px;" placeholder="Tuliskan langkah pembimbingan atau solusi yang dilakukan..."></textarea>
                                </div>

                                <!-- Langkah 4: Ringkasan Terpadu -->
                                <div class="col-12 form-group mb-1">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="font-weight-bold text-dark mb-0 d-flex align-items-center">
                                            <span class="badge badge-dark mr-2" style="font-size:12px;">Langkah 4</span>
                                            Ringkasan Perkembangan Terpadu <span class="text-danger">*</span>
                                        </label>
                                        <button type="button" id="btnAutoRangkum" class="btn btn-outline-primary btn-sm py-1 px-2 font-weight-bold">
                                            <i class="fas fa-magic mr-1"></i> Susun dari Kendala &amp; Tindak Lanjut
                                        </button>
                                    </div>
                                    <textarea name="ringkasan" id="inp_ringkasan" class="form-control catatan-ta" rows="5" style="min-height:120px;" required placeholder="Intisari capaian dan perkembangan siswa... (Otomatis terisi saat template dipilih, atau dapat diketik manual)"></textarea>
                                    <small class="text-muted">Narasi ringkasan ini yang akan ditampilkan pada laporan perkembangan dan timeline siswa.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <style>
                        #modalCatatan textarea.catatan-ta { min-height: 110px; line-height: 1.55; resize: vertical; overflow-y: auto; }
                        #modalCatatan select.sel-detail-tpl { white-space: normal; }
                    </style>
                    <!-- 3. RINCIAN ASPEK DETAIL (pilih manual dari Data Perkembangan) -->
                    <div class="card border mb-0">
                        <div class="card-header bg-light py-2" style="cursor: pointer;" data-toggle="collapse" data-target="#collapseDetailAspek">
                            <span class="font-weight-bold text-secondary">
                                <i class="fas fa-chevron-down mr-1"></i> 3. Rincian Aspek Pengamatan (pilih manual sesuai kondisi siswa)
                            </span>
                            <small class="text-muted ml-2">Data dari menu Data Perkembangan, guru tinggal pilih per aspek</small>
                        </div>
                        <div id="collapseDetailAspek" class="collapse show">
                            <div class="card-body py-3">
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label class="small font-weight-bold">Perkembangan Akademik</label>
                                        <select class="form-control form-control-sm mb-1 sel-detail-tpl" data-target="#inp_akademik" data-field="perkembangan_akademik">
                                            <option value="">-- Pilih catatan akademik --</option>
                                        </select>
                                        <textarea name="perkembangan_akademik" id="inp_akademik" class="form-control catatan-ta" rows="4" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="small font-weight-bold">Perkembangan Sikap / Karakter</label>
                                        <select class="form-control form-control-sm mb-1 sel-detail-tpl" data-target="#inp_sikap" data-field="perkembangan_sikap">
                                            <option value="">-- Pilih catatan sikap --</option>
                                        </select>
                                        <textarea name="perkembangan_sikap" id="inp_sikap" class="form-control catatan-ta" rows="4" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="small font-weight-bold">Perkembangan Keterampilan</label>
                                        <select class="form-control form-control-sm mb-1 sel-detail-tpl" data-target="#inp_keterampilan" data-field="perkembangan_keterampilan">
                                            <option value="">-- Pilih catatan keterampilan --</option>
                                        </select>
                                        <textarea name="perkembangan_keterampilan" id="inp_keterampilan" class="form-control catatan-ta" rows="4" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="small font-weight-bold">Keaktifan Siswa</label>
                                        <select class="form-control form-control-sm mb-1 sel-detail-tpl" data-target="#inp_keaktifan" data-field="keaktifan">
                                            <option value="">-- Pilih catatan keaktifan --</option>
                                        </select>
                                        <textarea name="keaktifan" id="inp_keaktifan" class="form-control catatan-ta" rows="4" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="small font-weight-bold">Bakat / Potensi Khusus</label>
                                        <select class="form-control form-control-sm mb-1 sel-detail-tpl" data-target="#inp_potensi" data-field="potensi">
                                            <option value="">-- Pilih catatan potensi --</option>
                                        </select>
                                        <textarea name="potensi" id="inp_potensi" class="form-control catatan-ta" rows="4" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label class="small font-weight-bold">Rekomendasi / Catatan Guru</label>
                                        <select class="form-control form-control-sm mb-1 sel-detail-tpl" data-target="#inp_rekomendasi" data-field="rekomendasi">
                                            <option value="">-- Pilih catatan rekomendasi --</option>
                                        </select>
                                        <textarea name="rekomendasi" id="inp_rekomendasi" class="form-control catatan-ta" rows="4" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save mr-1"></i> Simpan Catatan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail & Timeline Perkembangan Siswa -->
<div class="modal fade" id="modalDetailPerkembangan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title text-primary"><i class="fas fa-id-card-alt mr-2"></i>Detail &amp; Timeline Perkembangan Siswa</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-3">
                <!-- Identitas Siswa -->
                <div class="card bg-light mb-3 border">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-md-4 mb-2"><strong>Nama Siswa:</strong> <div id="det_nama" class="text-primary font-weight-bold"></div></div>
                            <div class="col-md-4 mb-2"><strong>NISN:</strong> <div id="det_nisn"></div></div>
                            <div class="col-md-4 mb-2"><strong>Kelas:</strong> <div id="det_kelas"></div></div>
                            <div class="col-md-4 mb-2"><strong>Mata Pelajaran:</strong> <div id="det_mapel"></div></div>
                            <div class="col-md-4 mb-2"><strong>Tanggal:</strong> <div id="det_tanggal"></div></div>
                            <div class="col-md-4 mb-2"><strong>Status:</strong> <div id="det_status" class="badge badge-success"></div></div>
                        </div>
                    </div>
                </div>

                <!-- Rincian Catatan -->
                <div class="card border mb-3">
                    <div class="card-header bg-white py-2 border-bottom">
                        <h6 class="mb-0 text-dark">Rincian Perkembangan</h6>
                    </div>
                    <div class="card-body p-3">
                        <div class="mb-2"><strong>Aspek:</strong> <span id="det_kategori" class="badge badge-info"></span></div>
                        <div class="mb-2"><strong>Ringkasan Perkembangan:</strong> <div id="det_ringkasan" class="text-dark bg-light p-2 rounded"></div></div>
                        <div class="mb-2"><strong>Kendala Belajar:</strong> <div id="det_kendala" class="text-danger bg-light p-2 rounded"></div></div>
                        <div class="mb-2"><strong>Tindak Lanjut Guru:</strong> <div id="det_tindak_lanjut" class="text-success bg-light p-2 rounded"></div></div>

                        <div class="row mt-3">
                            <div class="col-md-6 mb-2"><strong>Akademik:</strong> <div id="det_akademik" class="text-muted small"></div></div>
                            <div class="col-md-6 mb-2"><strong>Sikap &amp; Karakter:</strong> <div id="det_sikap" class="text-muted small"></div></div>
                            <div class="col-md-6 mb-2"><strong>Keterampilan:</strong> <div id="det_keterampilan" class="text-muted small"></div></div>
                            <div class="col-md-6 mb-2"><strong>Keaktifan:</strong> <div id="det_keaktifan" class="text-muted small"></div></div>
                            <div class="col-md-6 mb-2"><strong>Potensi / Bakat:</strong> <div id="det_potensi" class="text-muted small"></div></div>
                            <div class="col-md-6 mb-2"><strong>Rekomendasi:</strong> <div id="det_rekomendasi" class="text-muted small"></div></div>
                        </div>
                    </div>
                </div>

                <!-- Timeline Riwayat -->
                <div class="card border mb-0">
                    <div class="card-header bg-white py-2 border-bottom">
                        <h6 class="mb-0 text-dark"><i class="fas fa-history mr-1 text-info"></i> Timeline Riwayat Perkembangan Siswa</h6>
                    </div>
                    <div class="card-body p-2" id="timelineContainer" style="max-height: 280px; overflow-y: auto;">
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>
