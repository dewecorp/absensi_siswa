<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['guru', 'wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$is_kepala = ($user_level === 'kepala_madrasah') || (($_GET['session_type'] ?? '') === 'kepala_madrasah');
$can_crud = !$is_kepala;

$id_tugas = (int)($_GET['id'] ?? 0);
if ($id_tugas <= 0) {
    redirect('tugas.php');
}

// Deteksi kelas wali jika login sebagai wali
$wali_kelas_id = 0;
if ($user_level !== 'admin' && !$is_kepala) {
    try {
        $stWali = $pdo->prepare("SELECT id_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
        $stWali->execute([$guru_id, $guru_id]);
        $wali_kelas_id = (int)$stWali->fetchColumn() ?: 0;
    } catch (Throwable $e) {}
}

if ($user_level === 'admin' || $is_kepala) {
    $auth_sql = "t.id = ?";
    $auth_params = [$id_tugas];
} else {
    // Guru pengampu tugas atau Wali Kelas target dapat mengakses
    $auth_sql = "t.id = ? AND (t.id_guru = ? " . ($wali_kelas_id > 0 ? "OR t.id_kelas = $wali_kelas_id" : "") . ")";
    $auth_params = [$id_tugas, $guru_id];
}

// Fetch tugas info
$stmt = $pdo->prepare("
    SELECT t.*, m.nama_mapel, k.nama_kelas, g.nama_guru
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
    WHERE $auth_sql
    LIMIT 1
");
$stmt->execute($auth_params);
$tugas = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tugas) {
    redirect('tugas.php');
}

$message = null;

// Handle periksa / nilai / feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Anda tidak memiliki hak akses untuk mengubah nilai.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'nilai_tugas') {
        $id_pengumpulan = (int)($_POST['id_pengumpulan'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $nilai = $_POST['nilai'] !== '' ? (float)$_POST['nilai'] : null;
        $status_periksa = in_array($_POST['status_periksa'] ?? '', ['Belum Diperiksa', 'Sudah Diperiksa', 'Perlu Revisi'], true) ? $_POST['status_periksa'] : 'Sudah Diperiksa';
        $feedback = trim((string)($_POST['feedback'] ?? ''));

        try {
            if ($id_pengumpulan > 0) {
                $upd = $pdo->prepare("
                    UPDATE tb_tugas_pengumpulan SET
                        nilai = ?, status_periksa = ?, feedback = ?, diperiksa_at = NOW()
                    WHERE id = ? AND id_tugas = ?
                ");
                $upd->execute([$nilai, $status_periksa, $feedback, $id_pengumpulan, $id_tugas]);
            } elseif ($id_siswa > 0) {
                // Guru memasukkan nilai manual untuk siswa yang belum submit berkas
                $ins = $pdo->prepare("
                    INSERT INTO tb_tugas_pengumpulan (
                        id_tugas, id_siswa, nilai, status_periksa, feedback, diperiksa_at, tgl_kumpul
                    ) VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        nilai = VALUES(nilai), status_periksa = VALUES(status_periksa), feedback = VALUES(feedback), diperiksa_at = NOW()
                ");
                $ins->execute([$id_tugas, $id_siswa, $nilai, $status_periksa, $feedback]);
            }
            $message = ['type' => 'success', 'text' => 'Nilai dan feedback berhasil disimpan.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menyimpan nilai: ' . $e->getMessage()];
        }
    } elseif ($action === 'simpan_soal') {
            $soal_id = (int)($_POST['soal_id'] ?? 0);
            $tipe_soal = in_array($_POST['tipe_soal'] ?? '', ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Essay'], true) ? $_POST['tipe_soal'] : 'Pilihan Ganda';
            $pertanyaan = trim((string)($_POST['pertanyaan'] ?? ''));
            $pilihan_a = trim((string)($_POST['pilihan_a'] ?? ''));
            $pilihan_b = trim((string)($_POST['pilihan_b'] ?? ''));
            $pilihan_c = trim((string)($_POST['pilihan_c'] ?? ''));
            $pilihan_d = trim((string)($_POST['pilihan_d'] ?? ''));
            $pilihan_e = trim((string)($_POST['pilihan_e'] ?? ''));
            $kunci_jawaban = trim((string)($_POST['kunci_jawaban'] ?? 'A'));
            $bobot = (int)($_POST['bobot'] ?? 10) ?: 10;

            if ($pertanyaan === '') {
                $message = ['type' => 'warning', 'text' => 'Pertanyaan soal wajib diisi.'];
            } else {
                try {
                    if ($soal_id > 0) {
                        $st = $pdo->prepare("UPDATE tb_tugas_soal SET tipe_soal=?, pertanyaan=?, pilihan_a=?, pilihan_b=?, pilihan_c=?, pilihan_d=?, pilihan_e=?, kunci_jawaban=?, bobot=? WHERE id=? AND id_tugas=?");
                        $st->execute([$tipe_soal, $pertanyaan, $pilihan_a, $pilihan_b, $pilihan_c, $pilihan_d, $pilihan_e, $kunci_jawaban, $bobot, $soal_id, $id_tugas]);
                        $message = ['type' => 'success', 'text' => 'Soal kuis berhasil diperbarui.'];
                    } else {
                        $st = $pdo->prepare("INSERT INTO tb_tugas_soal (id_tugas, tipe_soal, pertanyaan, pilihan_a, pilihan_b, pilihan_c, pilihan_d, pilihan_e, kunci_jawaban, bobot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $st->execute([$id_tugas, $tipe_soal, $pertanyaan, $pilihan_a, $pilihan_b, $pilihan_c, $pilihan_d, $pilihan_e, $kunci_jawaban, $bobot]);
                        $message = ['type' => 'success', 'text' => 'Soal kuis baru berhasil ditambahkan.'];
                    }
                } catch (Exception $e) {
                    $message = ['type' => 'danger', 'text' => 'Gagal menyimpan soal: ' . $e->getMessage()];
                }
            }
        } elseif ($action === 'perpanjang_waktu') {
            $tambah_jam = (int)($_POST['tambah_jam'] ?? 24);
            try {
                $stDL = $pdo->prepare("SELECT deadline FROM tb_tugas WHERE id = ? AND id_guru = ?");
                $stDL->execute([$id_tugas, $guru_id]);
                $old_dl = $stDL->fetchColumn();
                $base_time = ($old_dl && strtotime($old_dl) > time()) ? strtotime($old_dl) : time();
                $new_dl = date('Y-m-d H:i:s', $base_time + ($tambah_jam * 3600));

                $pdo->prepare("UPDATE tb_tugas SET deadline = ? WHERE id = ? AND id_guru = ?")->execute([$new_dl, $id_tugas, $guru_id]);
                $message = ['type' => 'success', 'text' => "Batas waktu tugas berhasil diperpanjang hingga: " . date('d/m/Y H:i', strtotime($new_dl))];
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal memperpanjang waktu: ' . $e->getMessage()];
            }
        }
}
}

// Fetch all students in this class with their submission status
$students_stmt = $pdo->prepare("
    SELECT s.id_siswa, s.nama_siswa, s.nisn,
           tp.id AS id_pengumpulan, tp.file_path, tp.catatan_siswa, tp.tgl_kumpul,
           tp.keterlambatan, tp.nilai, tp.status_periksa, tp.feedback, tp.diperiksa_at
    FROM tb_siswa s
    LEFT JOIN tb_tugas_pengumpulan tp ON tp.id_siswa = s.id_siswa AND tp.id_tugas = ?
    WHERE s.id_kelas = ?
    ORDER BY s.nama_siswa ASC
");
$students_stmt->execute([$id_tugas, $tugas['id_kelas']]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch soal kuis/CBT jika jenis tugas Kuis atau CBT
$soal_list = [];
if (in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)) {
    $stSoal = $pdo->prepare("SELECT * FROM tb_tugas_soal WHERE id_tugas = ? ORDER BY id ASC");
    $stSoal->execute([$id_tugas]);
    $soal_list = $stSoal->fetchAll(PDO::FETCH_ASSOC);
}

// Analisis Butir Soal CBT / Kuis
$is_cbt_or_kuis = in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true);
$analisis_soal = [];
$total_responden_cbt = 0;
$total_skor_cbt = 0;
$skor_cbt_list = [];
$rekap_kesukaran = ['Mudah' => 0, 'Sedang' => 0, 'Sukar' => 0];

if ($is_cbt_or_kuis && !empty($soal_list)) {
    $responden_list = [];
    foreach ($students as $s) {
        if (!empty($s['tgl_kumpul'])) {
            $raw_ans = [];
            if (!empty($s['catatan_siswa'])) {
                $decoded = json_decode($s['catatan_siswa'], true);
                if (is_array($decoded)) {
                    $raw_ans = $decoded;
                }
            }
            $val_nilai = $s['nilai'] !== null ? (float)$s['nilai'] : 0;
            $responden_list[] = [
                'id_siswa' => $s['id_siswa'],
                'nama_siswa' => $s['nama_siswa'],
                'nilai' => $val_nilai,
                'jawaban' => $raw_ans,
            ];
            $total_skor_cbt += $val_nilai;
            $skor_cbt_list[] = $val_nilai;
        }
    }
    $total_responden_cbt = count($responden_list);

    // Kelompok Atas & Bawah untuk Daya Pembeda (bila responden >= 4)
    $upper_grp = [];
    $lower_grp = [];
    if ($total_responden_cbt >= 4) {
        $sorted_resp = $responden_list;
        usort($sorted_resp, fn($a, $b) => $b['nilai'] <=> $a['nilai']);
        $half = (int)ceil($total_responden_cbt / 2);
        $upper_grp = array_slice($sorted_resp, 0, $half);
        $lower_grp = array_slice($sorted_resp, -$half);
    }

    foreach ($soal_list as $idx => $soal) {
        $sId = $soal['id'];
        $tipe = $soal['tipe_soal'];
        $kunci = trim((string)$soal['kunci_jawaban']);
        $bobot = (int)($soal['bobot'] ?? 10);

        $distribusi = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'Lainnya' => 0];
        $pemilih = ['A' => [], 'B' => [], 'C' => [], 'D' => [], 'E' => [], 'Lainnya' => []];
        $jml_benar = 0;
        $jml_salah = 0;
        $siswa_benar = [];
        $siswa_salah = [];

        foreach ($responden_list as $resp) {
            $jwb = $resp['jawaban'][$sId] ?? $resp['jawaban'][(string)$sId] ?? null;
            $nama = $resp['nama_siswa'];
            $is_benar = false;

            if ($tipe === 'Pilihan Ganda') {
                $jwb_clean = trim((string)$jwb);
                $opt_u = strtoupper($jwb_clean);
                if (in_array($opt_u, ['A', 'B', 'C', 'D', 'E'], true)) {
                    $distribusi[$opt_u]++;
                    $pemilih[$opt_u][] = $nama;
                } else {
                    $distribusi['Lainnya']++;
                    $pemilih['Lainnya'][] = $nama . ($jwb_clean !== '' ? " ($jwb_clean)" : ' (Kosong)');
                }
                if ($jwb_clean !== '' && strcasecmp($jwb_clean, $kunci) === 0) {
                    $is_benar = true;
                }
            } elseif ($tipe === 'Pilihan Ganda Kompleks') {
                $k_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', $kunci))));
                $j_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', (string)$jwb))));
                if (!empty($j_arr)) {
                    foreach ($j_arr as $opt) {
                        if (in_array($opt, ['A', 'B', 'C', 'D', 'E'], true)) {
                            $distribusi[$opt]++;
                            $pemilih[$opt][] = $nama;
                        }
                    }
                } else {
                    $distribusi['Lainnya']++;
                    $pemilih['Lainnya'][] = $nama . ' (Kosong)';
                }
                if (count($k_arr) > 0 && count(array_intersect($k_arr, $j_arr)) === count($k_arr) && count(array_diff($j_arr, $k_arr)) === 0) {
                    $is_benar = true;
                }
            } else {
                $jwb_clean = trim((string)$jwb);
                if ($jwb_clean !== '') {
                    $distribusi['Lainnya']++;
                    $pemilih['Lainnya'][] = $nama;
                    if ($kunci !== '') {
                        similar_text(strtolower($jwb_clean), strtolower($kunci), $pct);
                        if ($pct >= 70.0) $is_benar = true;
                    }
                } else {
                    $distribusi['Lainnya']++;
                    $pemilih['Lainnya'][] = $nama . ' (Kosong)';
                }
            }

            if ($is_benar) {
                $jml_benar++;
                $siswa_benar[] = $nama;
            } else {
                $jml_salah++;
                $siswa_salah[] = [
                    'nama' => $nama,
                    'jawaban' => $jwb !== null && $jwb !== '' ? (is_array($jwb) ? implode(',', $jwb) : $jwb) : '(Kosong)'
                ];
            }
        }

        $p_val = $total_responden_cbt > 0 ? round($jml_benar / $total_responden_cbt, 2) : 0;
        $pct_benar = $total_responden_cbt > 0 ? round(($jml_benar / $total_responden_cbt) * 100, 1) : 0;
        $pct_salah = $total_responden_cbt > 0 ? round(($jml_salah / $total_responden_cbt) * 100, 1) : 0;

        if ($p_val > 0.70) {
            $kesukaran_label = 'Mudah';
            $kesukaran_badge = 'badge-success';
            $rekap_kesukaran['Mudah']++;
        } elseif ($p_val >= 0.30) {
            $kesukaran_label = 'Sedang';
            $kesukaran_badge = 'badge-warning';
            $rekap_kesukaran['Sedang']++;
        } else {
            $kesukaran_label = 'Sukar';
            $kesukaran_badge = 'badge-danger';
            $rekap_kesukaran['Sukar']++;
        }

        $d_val = null;
        $d_label = '-';
        $d_badge = 'badge-secondary';
        if ($total_responden_cbt >= 4) {
            $bu = 0;
            $bl = 0;
            foreach ($upper_grp as $u) {
                $uj = $u['jawaban'][$sId] ?? $u['jawaban'][(string)$sId] ?? null;
                if ($tipe === 'Pilihan Ganda' && $uj !== null && strcasecmp(trim((string)$uj), $kunci) === 0) $bu++;
                elseif ($tipe === 'Pilihan Ganda Kompleks') {
                    $k_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', $kunci))));
                    $j_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', (string)$uj))));
                    if (count($k_arr) > 0 && count(array_intersect($k_arr, $j_arr)) === count($k_arr) && count(array_diff($j_arr, $k_arr)) === 0) $bu++;
                }
            }
            foreach ($lower_grp as $l) {
                $lj = $l['jawaban'][$sId] ?? $l['jawaban'][(string)$sId] ?? null;
                if ($tipe === 'Pilihan Ganda' && $lj !== null && strcasecmp(trim((string)$lj), $kunci) === 0) $bl++;
                elseif ($tipe === 'Pilihan Ganda Kompleks') {
                    $k_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', $kunci))));
                    $j_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', (string)$lj))));
                    if (count($k_arr) > 0 && count(array_intersect($k_arr, $j_arr)) === count($k_arr) && count(array_diff($j_arr, $k_arr)) === 0) $bl++;
                }
            }
            $half_size = count($upper_grp);
            if ($half_size > 0) {
                $d_val = round(($bu - $bl) / $half_size, 2);
                if ($d_val >= 0.40) {
                    $d_label = 'Sangat Baik';
                    $d_badge = 'badge-success';
                } elseif ($d_val >= 0.30) {
                    $d_label = 'Baik';
                    $d_badge = 'badge-info';
                } elseif ($d_val >= 0.20) {
                    $d_label = 'Cukup';
                    $d_badge = 'badge-warning';
                } else {
                    $d_label = 'Kurang / Jelek';
                    $d_badge = 'badge-danger';
                }
            }
        }

        $rekomendasi = 'Diterima';
        if ($total_responden_cbt > 0) {
            if ($p_val > 0.85) $rekomendasi = 'Terlalu Mudah (Revisi Pengecoh)';
            elseif ($p_val < 0.20) $rekomendasi = 'Terlalu Sulit (Perlu Ditinjau)';
            elseif ($d_val !== null && $d_val < 0.20) $rekomendasi = 'Daya Pembeda Rendah';
            else $rekomendasi = 'Soal Baik (Diterima)';
        }

        $analisis_soal[] = [
            'no' => $idx + 1,
            'id' => $sId,
            'tipe_soal' => $tipe,
            'pertanyaan' => $soal['pertanyaan'],
            'kunci_jawaban' => $kunci,
            'bobot' => $bobot,
            'pilihan_a' => $soal['pilihan_a'],
            'pilihan_b' => $soal['pilihan_b'],
            'pilihan_c' => $soal['pilihan_c'],
            'pilihan_d' => $soal['pilihan_d'],
            'pilihan_e' => $soal['pilihan_e'],
            'jml_benar' => $jml_benar,
            'jml_salah' => $jml_salah,
            'pct_benar' => $pct_benar,
            'pct_salah' => $pct_salah,
            'distribusi' => $distribusi,
            'pemilih' => $pemilih,
            'siswa_benar' => $siswa_benar,
            'siswa_salah' => $siswa_salah,
            'p_val' => $p_val,
            'kesukaran_label' => $kesukaran_label,
            'kesukaran_badge' => $kesukaran_badge,
            'd_val' => $d_val,
            'd_label' => $d_label,
            'd_badge' => $d_badge,
            'rekomendasi' => $rekomendasi,
        ];
    }
}

$avg_nilai_cbt = $total_responden_cbt > 0 ? round($total_skor_cbt / $total_responden_cbt, 1) : 0;
$max_nilai_cbt = !empty($skor_cbt_list) ? max($skor_cbt_list) : 0;
$min_nilai_cbt = !empty($skor_cbt_list) ? min($skor_cbt_list) : 0;

// Statistics
$jml_siswa = count($students);
$sudah_kumpul = 0;
$belum_kumpul = 0;
$terlambat = 0;
$sudah_diperiksa = 0;
$belum_diperiksa = 0;

$deadline_ts = !empty($tugas['deadline']) ? strtotime($tugas['deadline']) : null;

foreach ($students as $st) {
    if (!empty($st['tgl_kumpul'])) {
        $sudah_kumpul++;
        if ($deadline_ts && strtotime($st['tgl_kumpul']) > $deadline_ts) {
            $terlambat++;
        }
        if (($st['status_periksa'] ?? '') === 'Sudah Diperiksa') {
            $sudah_diperiksa++;
        } else {
            $belum_diperiksa++;
        }
    } else {
        $belum_kumpul++;
        $belum_diperiksa++;
    }
}

$page_title = 'Detail Tugas: ' . $tugas['judul'];
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$can_crud_js = json_encode((bool)$can_crud);
$js_page = [<<<JS
$(document).ready(function() {
    if ($('#table-pengumpulan').length) {
        var colCount = $('#table-pengumpulan thead th').length;
        var nonSortable = [colCount - 1];
        if (colCount >= 10) nonSortable.push(colCount - 2);
        $('#table-pengumpulan').DataTable({
            'order': [[1, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': nonSortable }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data pengumpulan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari Siswa:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    if ($('#table-analisis-soal').length) {
        $('#table-analisis-soal').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [2, 6, 9] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada butir soal',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ butir soal',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 butir soal',
                'search': 'Cari Soal:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $(document).on('click', '.btn-periksa', function() {
        var data = $(this).data('json');
        $('#mdl_id_pengumpulan').val(data.id_pengumpulan || '');
        $('#mdl_id_siswa').val(data.id_siswa);
        $('#mdl_nama_siswa').text(data.nama_siswa);
        $('#mdl_nilai').val(data.nilai !== null ? data.nilai : '');
        $('#mdl_status_periksa').val(data.status_periksa || 'Sudah Diperiksa');
        $('#mdl_feedback').val(data.feedback || '');
        $('#mdl_nilai, #mdl_status_periksa, #mdl_feedback').prop('disabled', !$can_crud_js);

        if (data.file_path) {
            var ext = data.file_path.split('.').pop().toLowerCase();
            $('#mdl_file_wrap').html('<a href="preview_tugas.php?id_pengumpulan=' + data.id_pengumpulan + '" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-book-reader mr-1"></i>Baca (' + ext.toUpperCase() + ')</a>');
        } else {
            $('#mdl_file_wrap').html('<span class="text-muted">Tidak ada berkas yang diunggah siswa</span>');
        }
        $('#mdl_catatan_siswa').text(data.catatan_siswa || '-');
        $('#modalPeriksa').modal('show');
    });

    // Modal Detail Analisis Butir Soal
    $(document).on('click', '.btn-detail-analisis', function() {
        var item = $(this).data('json');
        if (typeof item === 'string') {
            try { item = JSON.parse(item); } catch(e) {}
        }
        if (!item) return;

        $('#an_no_soal').text(item.no);
        $('#an_tipe_soal').text(item.tipe_soal);
        $('#an_bobot').text('Bobot: ' + item.bobot);
        $('#an_badge_kesukaran').attr('class', 'badge ' + item.kesukaran_badge).text('Kesukaran: ' + item.kesukaran_label + ' (P=' + parseFloat(item.p_val).toFixed(2) + ')');
        if (item.d_val !== null) {
            $('#an_badge_pembeda').show().attr('class', 'badge ml-1 ' + item.d_badge).text('Pembeda: ' + item.d_label + ' (D=' + parseFloat(item.d_val).toFixed(2) + ')');
        } else {
            $('#an_badge_pembeda').hide();
        }
        $('#an_pertanyaan').html(item.pertanyaan ? item.pertanyaan.replace(/\\n/g, '<br>') : '-');
        $('#an_kunci').text(item.kunci_jawaban || '-');
        $('#an_rekomendasi_wrap').html('Status: <strong class="text-primary">' + (item.rekomendasi || 'Diterima') + '</strong>');

        // Distribusi Opsi
        if (item.tipe_soal !== 'Essay') {
            $('#an_box_distribusi').show();
            var htmlOpsi = '';
            var kunciArr = String(item.kunci_jawaban || '').toUpperCase().split(',').map(function(s){ return s.trim(); });
            var totalResp = (item.jml_benar + item.jml_salah) || 1;
            var opts = { 'A': item.pilihan_a, 'B': item.pilihan_b, 'C': item.pilihan_c, 'D': item.pilihan_d, 'E': item.pilihan_e };
            
            for (var k in opts) {
                if (opts[k]) {
                    var isKey = kunciArr.indexOf(k) !== -1;
                    var count = (item.distribusi && item.distribusi[k]) ? item.distribusi[k] : 0;
                    var pct = Math.round((count / totalResp) * 100);
                    var barClass = isKey ? 'bg-success' : 'bg-info';
                    htmlOpsi += '<div class="mb-2">';
                    htmlOpsi += '<div class="d-flex justify-content-between mb-1" style="font-size:13px;">';
                    htmlOpsi += '<span><strong>' + k + '.</strong> ' + opts[k] + (isKey ? ' <span class="badge badge-success py-0 px-2 ml-1">KUNCI</span>' : '') + '</span>';
                    htmlOpsi += '<span class="font-weight-bold ' + (isKey ? 'text-success' : 'text-dark') + '">' + count + ' Siswa (' + pct + '%)</span>';
                    htmlOpsi += '</div>';
                    htmlOpsi += '<div class="progress" style="height: 12px; border-radius: 6px;">';
                    htmlOpsi += '<div class="progress-bar ' + barClass + '" role="progressbar" style="width: ' + pct + '%;" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"></div>';
                    htmlOpsi += '</div>';
                    htmlOpsi += '</div>';
                }
            }
            $('#an_container_opsi').html(htmlOpsi);
        } else {
            $('#an_box_distribusi').hide();
        }

        // List Benar
        $('#an_cnt_benar').text(item.jml_benar || 0);
        var htmlBenar = '';
        if (item.siswa_benar && item.siswa_benar.length > 0) {
            htmlBenar = '<div class="d-flex flex-wrap" style="gap:4px;">';
            item.siswa_benar.forEach(function(nama) {
                htmlBenar += '<span class="badge badge-light border text-dark font-weight-normal py-1 px-2" style="font-size:11.5px;"><i class="fas fa-check text-success mr-1"></i>' + nama + '</span>';
            });
            htmlBenar += '</div>';
        } else {
            htmlBenar = '<span class="text-muted small">Tidak ada siswa yang menjawab benar.</span>';
        }
        $('#an_list_benar').html(htmlBenar);

        // List Salah
        $('#an_cnt_salah').text(item.jml_salah || 0);
        var htmlSalah = '';
        if (item.siswa_salah && item.siswa_salah.length > 0) {
            htmlSalah = '<div class="d-flex flex-wrap" style="gap:4px;">';
            item.siswa_salah.forEach(function(s) {
                var sNama = typeof s === 'object' ? s.nama : s;
                var sJwb = typeof s === 'object' ? s.jawaban : '';
                htmlSalah += '<span class="badge badge-light border text-dark font-weight-normal py-1 px-2" style="font-size:11.5px;"><i class="fas fa-times text-danger mr-1"></i>' + sNama + (sJwb ? ' <em class="text-muted">(' + sJwb + ')</em>' : '') + '</span>';
            });
            htmlSalah += '</div>';
        } else {
            htmlSalah = '<span class="text-muted small">Semua siswa menjawab benar.</span>';
        }
        $('#an_list_salah').html(htmlSalah);

        $('#modalDetailAnalisisSoal').modal('show');
    });

    // Live search mobile pengumpulan
    $('#mobileSearchPengumpulan').on('input', function() {
        var q = $(this).val().toLowerCase().trim();
        $('.mobile-student-card').each(function() {
            var nama = $(this).data('nama') || '';
            if (q === '' || nama.indexOf(q) !== -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
});
JS
];

if (!empty($message)) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Detail Tugas</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="mb-3 d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                <a href="tugas.php" class="btn btn-dark btn-sm"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a>
                <?php if (in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                <div class="d-flex flex-wrap" style="gap:6px;">
                    <a href="#section-analisis-soal" class="btn btn-info btn-sm font-weight-bold">
                        <i class="fas fa-chart-pie mr-1"></i> Analisis Butir Soal
                    </a>
                    <a href="buat_soal_tugas.php?id=<?= (int)$tugas['id'] ?>" class="btn btn-primary btn-sm font-weight-bold">
                        <i class="fas fa-question-circle mr-1"></i> Kelola Soal <?= htmlspecialchars($tugas['jenis_tugas']) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <!-- Informasi Tugas & Statistik -->
            <div class="row">
                <!-- Info Tugas -->
                <div class="col-lg-7 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h4><i class="fas fa-info-circle mr-2"></i>Informasi Tugas</h4>
                            <div class="card-header-action">
                                <span class="badge badge-<?= $tugas['status'] === 'Aktif' ? 'success' : 'secondary' ?>">
                                    <?= htmlspecialchars($tugas['status']) ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php
                            $judul_bersih = $tugas['judul'];
                            if ($tugas['jenis_tugas'] === 'CBT') {
                                $judul_bersih = preg_replace('/^kuis\s*/i', '', $judul_bersih);
                                if (stripos($judul_bersih, 'cbt') !== 0) $judul_bersih = 'CBT ' . trim($judul_bersih);
                            } elseif ($tugas['jenis_tugas'] === 'Kuis') {
                                $judul_bersih = preg_replace('/^cbt\s*/i', '', $judul_bersih);
                                if (stripos($judul_bersih, 'kuis') !== 0) $judul_bersih = 'Kuis ' . trim($judul_bersih);
                            }
                            ?>
                            <h5 class="text-primary font-weight-bold mb-3">
                                <?= htmlspecialchars($judul_bersih) ?>
                            </h5>
                            <table class="table table-sm table-bordered mt-3">
                                <tr><th width="30%">Mata Pelajaran</th><td><?= htmlspecialchars($tugas['nama_mapel'] ?? '-') ?></td></tr>
                                <tr><th>Kelas</th><td><?= htmlspecialchars($tugas['nama_kelas'] ?? '-') ?></td></tr>
                                <tr><th>Materi / TP</th><td><?= htmlspecialchars($tugas['materi_tp'] ?? '-') ?></td></tr>
                                 <tr><th>Jenis Tugas</th><td><span class="badge badge-light border"><?= htmlspecialchars($tugas['jenis_tugas']) ?></span></td></tr>
                                <tr><th>Jumlah Soal</th><td><strong class="text-primary"><?= count($soal_list) ?> Butir Soal</strong></td></tr>
                                <?php if (in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                <tr><th>Durasi Pengerjaan</th><td><strong class="text-danger"><i class="fas fa-clock mr-1"></i> <?= (int)($tugas['durasi_menit'] ?? 90) ?> Menit</strong></td></tr>
                                <?php endif; ?>
                                <tr><th>Tanggal Mulai</th><td><?= !empty($tugas['tgl_mulai']) ? date('d/m/Y H:i', strtotime($tugas['tgl_mulai'])) : '-' ?></td></tr>
                                <tr><th>Deadline</th><td class="font-weight-bold text-danger"><?= !empty($tugas['deadline']) ? date('d/m/Y H:i', strtotime($tugas['deadline'])) : '-' ?></td></tr>
                                <tr><th>Nilai Maksimal</th><td><?= (int)$tugas['nilai_maksimal'] ?></td></tr>
                                <tr>
                                    <th>Lampiran Berkas</th>
                                    <td>
                                        <?php if (!empty($tugas['lampiran'])): ?>
                                            <a href="preview_tugas.php?id=<?= (int)$tugas['id'] ?>" target="_blank" class="btn btn-sm btn-primary">
                                                <i class="fas fa-book-reader mr-1"></i> Baca Dokumen
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Tidak ada lampiran</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Instruksi</th>
                                    <td style="white-space: pre-wrap;"><?= htmlspecialchars($tugas['instruksi'] ?? '-') ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Statistik -->
                <div class="col-lg-5 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h4><i class="fas fa-chart-pie mr-2"></i>Statistik Pengumpulan</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Total Siswa</div>
                                        <h3 class="mb-0 text-dark"><?= $jml_siswa ?></h3>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Sudah</div>
                                        <h3 class="mb-0 text-success"><?= $sudah_kumpul ?></h3>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Belum</div>
                                        <h3 class="mb-0 text-danger"><?= $belum_kumpul ?></h3>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Terlambat</div>
                                        <h3 class="mb-0 text-warning"><?= $terlambat ?></h3>
                                    </div>
                                </div>
                                 <div class="col-6">
                                     <div class="border rounded p-3 text-center">
                                         <div class="text-muted small"><?= in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true) ? 'Sudah Mengerjakan' : 'Sudah Diperiksa' ?></div>
                                         <h3 class="mb-0 text-info"><?= $sudah_diperiksa ?></h3>
                                     </div>
                                 </div>
                                  <div class="col-6">
                                      <div class="border rounded p-3 text-center">
                                          <div class="text-muted small"><?= in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true) ? 'Belum Mengerjakan' : 'Belum Diperiksa' ?></div>
                                          <h3 class="mb-0 text-secondary"><?= $belum_diperiksa ?></h3>
                                      </div>
                                  </div>
                             </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
            <!-- Box Analisis Butir Soal CBT -->
            <div class="card shadow-sm mb-4" id="section-analisis-soal">
                <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4 class="mb-0"><i class="fas fa-chart-bar text-primary mr-2"></i>Analisis Butir Soal <?= htmlspecialchars($tugas['jenis_tugas']) ?></h4>
                    <div class="d-flex flex-wrap" style="gap:6px;">
                        <a href="cetak_analisis_soal.php?id=<?= (int)$tugas['id'] ?>" target="_blank" class="btn btn-danger btn-sm font-weight-bold">
                            <i class="fas fa-print mr-1"></i> Cetak Analisis
                        </a>
                        <a href="export_analisis_soal_excel.php?id=<?= (int)$tugas['id'] ?>" class="btn btn-success btn-sm font-weight-bold">
                            <i class="fas fa-file-excel mr-1"></i> Export Excel
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($total_responden_cbt === 0): ?>
                        <div class="alert alert-light border text-center py-4">
                            <div class="mb-2 text-info" style="font-size: 32px;"><i class="fas fa-info-circle"></i></div>
                            <h6 class="font-weight-bold text-dark">Belum Ada Siswa yang Mengerjakan</h6>
                            <p class="text-muted small mb-0">Analisis butir soal otomatis ditampilkan setelah ada siswa yang menyelesaikan dan mengumpulkan ujian ini.</p>
                        </div>
                    <?php else: ?>
                        <!-- Mini KPI Summary -->
                        <div class="row mb-3">
                            <div class="col-6 col-md-3 mb-2">
                                <div class="border rounded p-2 text-center bg-light">
                                    <small class="text-muted d-block">Responden</small>
                                    <h5 class="mb-0 text-primary font-weight-bold"><?= $total_responden_cbt ?> / <?= $jml_siswa ?> <small style="font-size:11px;" class="text-muted">(<?= round(($total_responden_cbt / max(1, $jml_siswa)) * 100) ?>%)</small></h5>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-2">
                                <div class="border rounded p-2 text-center bg-light">
                                    <small class="text-muted d-block">Rata-rata Nilai</small>
                                    <h5 class="mb-0 text-dark font-weight-bold"><?= $avg_nilai_cbt ?> <small style="font-size:11px;" class="text-muted">(Min: <?= $min_nilai_cbt ?>, Max: <?= $max_nilai_cbt ?>)</small></h5>
                                </div>
                            </div>
                            <div class="col-4 col-md-2 mb-2">
                                <div class="border rounded p-2 text-center bg-light">
                                    <small class="text-success font-weight-bold d-block">Mudah</small>
                                    <h5 class="mb-0 text-success font-weight-bold"><?= $rekap_kesukaran['Mudah'] ?> <small style="font-size:11px;">Soal</small></h5>
                                </div>
                            </div>
                            <div class="col-4 col-md-2 mb-2">
                                <div class="border rounded p-2 text-center bg-light">
                                    <small class="text-warning font-weight-bold d-block">Sedang</small>
                                    <h5 class="mb-0 text-warning font-weight-bold"><?= $rekap_kesukaran['Sedang'] ?> <small style="font-size:11px;">Soal</small></h5>
                                </div>
                            </div>
                            <div class="col-4 col-md-2 mb-2">
                                <div class="border rounded p-2 text-center bg-light">
                                    <small class="text-danger font-weight-bold d-block">Sukar</small>
                                    <h5 class="mb-0 text-danger font-weight-bold"><?= $rekap_kesukaran['Sukar'] ?> <small style="font-size:11px;">Soal</small></h5>
                                </div>
                            </div>
                        </div>

                        <!-- Desktop Table View: Analisis Soal -->
                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-striped table-bordered table-sm text-dark" id="table-analisis-soal">
                                <thead class="bg-light">
                                    <tr>
                                        <th width="4%" class="text-center">No</th>
                                        <th width="12%">Tipe</th>
                                        <th>Pertanyaan</th>
                                        <th width="6%" class="text-center">Kunci</th>
                                        <th width="8%" class="text-center">Benar</th>
                                        <th width="8%" class="text-center">Salah</th>
                                        <th width="16%">Distribusi Opsi</th>
                                        <th width="13%" class="text-center">Kesukaran (P)</th>
                                        <th width="12%" class="text-center">Pembeda (D)</th>
                                        <th width="6%" class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($analisis_soal as $item): ?>
                                    <tr>
                                        <td class="text-center font-weight-bold"><?= $item['no'] ?></td>
                                        <td><span class="badge badge-light border"><?= htmlspecialchars($item['tipe_soal']) ?></span></td>
                                        <td>
                                            <div class="font-weight-normal text-truncate" style="max-width: 280px;" title="<?= htmlspecialchars(strip_tags($item['pertanyaan'])) ?>">
                                                <?= htmlspecialchars(strip_tags($item['pertanyaan'])) ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-primary font-weight-bold"><?= htmlspecialchars($item['kunci_jawaban']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-success font-weight-bold"><?= $item['jml_benar'] ?> (<?= $item['pct_benar'] ?>%)</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-danger font-weight-bold"><?= $item['jml_salah'] ?> (<?= $item['pct_salah'] ?>%)</span>
                                        </td>
                                        <td>
                                            <?php if ($item['tipe_soal'] !== 'Essay'): ?>
                                                <div class="d-flex flex-wrap" style="gap:3px; font-size:11px;">
                                                    <?php 
                                                    $kunci_opts = array_map('trim', explode(',', strtoupper($item['kunci_jawaban'])));
                                                    foreach (['A', 'B', 'C', 'D', 'E'] as $opt_k): 
                                                        if (!empty($item['pilihan_' . strtolower($opt_k)])):
                                                            $is_k = in_array($opt_k, $kunci_opts, true);
                                                            $cnt = $item['distribusi'][$opt_k] ?? 0;
                                                    ?>
                                                        <span class="badge <?= $is_k ? 'badge-success' : 'badge-light border' ?>" title="<?= $is_k ? 'Kunci Jawaban' : 'Pengecoh' ?>: <?= $cnt ?> siswa">
                                                            <?= $opt_k ?>: <?= $cnt ?><?= $is_k ? '✓' : '' ?>
                                                        </span>
                                                    <?php 
                                                        endif;
                                                    endforeach; 
                                                    ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">Uraian</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $item['kesukaran_badge'] ?> font-weight-bold"><?= $item['kesukaran_label'] ?> (<?= number_format($item['p_val'], 2) ?>)</span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($item['d_val'] !== null): ?>
                                                <span class="badge <?= $item['d_badge'] ?>"><?= $item['d_label'] ?> (<?= number_format($item['d_val'], 2) ?>)</span>
                                            <?php else: ?>
                                                <span class="text-muted small" title="Minimal 4 responden">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-info btn-sm px-2 btn-detail-analisis" data-json='<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>' title="Detail Analisis Butir Soal">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile View: Cards Analisis Soal -->
                        <div class="d-md-none">
                            <div class="d-flex flex-column" style="gap: 10px;">
                                <?php foreach ($analisis_soal as $item): ?>
                                <div class="card mb-2 border shadow-sm" style="border-radius: 12px;">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="d-flex align-items-center" style="gap:6px;">
                                                <span class="badge badge-primary font-weight-bold">Soal #<?= $item['no'] ?></span>
                                                <span class="badge badge-light border"><?= htmlspecialchars($item['tipe_soal']) ?></span>
                                            </div>
                                            <span class="badge <?= $item['kesukaran_badge'] ?>"><?= $item['kesukaran_label'] ?> (P=<?= number_format($item['p_val'], 2) ?>)</span>
                                        </div>
                                        <p class="text-dark font-weight-normal mb-2" style="font-size: 13.5px; line-height: 1.4;">
                                            <?= htmlspecialchars(strip_tags($item['pertanyaan'])) ?>
                                        </p>
                                        <div class="bg-light p-2 rounded mb-2" style="font-size: 12px;">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Kunci: <strong class="text-success"><?= htmlspecialchars($item['kunci_jawaban']) ?></strong></span>
                                                <span>Bobot: <strong><?= (int)$item['bobot'] ?></strong></span>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span class="text-success font-weight-bold"><i class="fas fa-check mr-1"></i>Benar: <?= $item['jml_benar'] ?> (<?= $item['pct_benar'] ?>%)</span>
                                                <span class="text-danger font-weight-bold"><i class="fas fa-times mr-1"></i>Salah: <?= $item['jml_salah'] ?> (<?= $item['pct_salah'] ?>%)</span>
                                            </div>
                                        </div>
                                        <?php if ($item['tipe_soal'] !== 'Essay'): ?>
                                            <div class="d-flex flex-wrap align-items-center mb-2" style="gap:4px; font-size:11px;">
                                                <span class="text-muted mr-1">Distribusi:</span>
                                                <?php 
                                                $kunci_opts = array_map('trim', explode(',', strtoupper($item['kunci_jawaban'])));
                                                foreach (['A', 'B', 'C', 'D', 'E'] as $opt_k): 
                                                    if (!empty($item['pilihan_' . strtolower($opt_k)])):
                                                        $is_k = in_array($opt_k, $kunci_opts, true);
                                                        $cnt = $item['distribusi'][$opt_k] ?? 0;
                                                ?>
                                                    <span class="badge <?= $is_k ? 'badge-success' : 'badge-light border' ?>">
                                                        <?= $opt_k ?>: <?= $cnt ?><?= $is_k ? '✓' : '' ?>
                                                    </span>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-info btn-sm btn-block btn-detail-analisis" data-json='<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>'>
                                            <i class="fas fa-search-plus mr-1"></i> Detail Analisis Butir Soal
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tabel Pengumpulan Tugas -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4><i class="fas fa-users-cog mr-2"></i>Daftar Pengumpulan Siswa</h4>
                    <div class="d-flex flex-wrap" style="gap:6px;">
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-warning btn-sm font-weight-bold" data-toggle="modal" data-target="#modalPerpanjangWaktu" title="Perpanjang Batas Waktu Pengerjaan">
                            <i class="fas fa-clock mr-1"></i> Perpanjang Waktu
                        </button>
                        <?php endif; ?>
                        <a href="export_pengumpulan_tugas_pdf.php?id=<?= (int)$tugas['id'] ?>" target="_blank" class="btn btn-danger btn-sm" title="Cetak / Simpan PDF (tab baru)"><i class="fas fa-print mr-1"></i> PDF</a>
                        <a href="export_pengumpulan_tugas_excel.php?id=<?= (int)$tugas['id'] ?>" class="btn btn-success btn-sm" title="Unduh Excel"><i class="fas fa-file-excel mr-1"></i> Excel</a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Desktop Table View -->
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-striped table-bordered table-sm" id="table-pengumpulan">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Nama Siswa</th>
                                    <th>NISN</th>
                                    <?php if (!in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                        <th>Status Kumpul</th>
                                    <?php endif; ?>
                                    <th><?= in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true) ? 'Tanggal Mengerjakan' : 'Tanggal Kumpul' ?></th>
                                    <th>Keterlambatan</th>
                                    <th>Nilai</th>
                                    <?php if (in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                        <th>Status</th>
                                        <th width="15%" class="text-center">Aksi</th>
                                    <?php else: ?>
                                        <th>Status Pemeriksaan</th>
                                        <th width="10%" class="text-center">Berkas Siswa</th>
                                        <th width="12%" class="text-center">Aksi</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $i => $s): ?>
                                    <?php
                                    $is_kumpul = !empty($s['tgl_kumpul']);
                                    $keterlambatan_label = '-';
                                    if ($is_kumpul && $deadline_ts) {
                                        $diff = strtotime($s['tgl_kumpul']) - $deadline_ts;
                                        if ($diff > 0) {
                                            $jam = floor($diff / 3600);
                                            $keterlambatan_label = $jam > 24 ? floor($jam / 24) . ' hari' : $jam . ' jam';
                                        } else {
                                            $keterlambatan_label = 'Tepat Waktu';
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><strong><?= htmlspecialchars($s['nama_siswa']) ?></strong></td>
                                        <td><?= htmlspecialchars($s['nisn'] ?? '-') ?></td>
                                         <?php if (!in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                         <td class="text-center">
                                             <?php if ($is_kumpul): ?>
                                                 <span class="badge badge-success">Sudah</span>
                                             <?php else: ?>
                                                 <span class="badge badge-danger">Belum</span>
                                             <?php endif; ?>
                                         </td>
                                         <?php endif; ?>
                                        <td><?= $is_kumpul ? date('d/m/Y H:i', strtotime($s['tgl_kumpul'])) : '-' ?></td>
                                        <td>
                                            <?php if ($keterlambatan_label === 'Tepat Waktu'): ?>
                                                <span class="badge badge-light text-success"><?= $keterlambatan_label ?></span>
                                            <?php elseif ($keterlambatan_label !== '-'): ?>
                                                <span class="badge badge-danger">Telat <?= $keterlambatan_label ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center font-weight-bold text-primary" style="font-size: 15px;">
                                            <?= $s['nilai'] !== null ? (float)$s['nilai'] : '-' ?>
                                        </td>
                                         <td class="text-center">
                                             <?php if (in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                                 <?php if ($is_kumpul): ?>
                                                     <span class="badge badge-success">Sudah Mengerjakan</span>
                                                 <?php else: ?>
                                                     <span class="badge badge-secondary">Belum Mengerjakan</span>
                                                 <?php endif; ?>
                                             <?php else: ?>
                                                 <?php
                                                 $st_periksa = $s['status_periksa'] ?? 'Belum Diperiksa';
                                                 $p_badge = $st_periksa === 'Sudah Diperiksa' ? 'success' : ($st_periksa === 'Perlu Revisi' ? 'warning' : 'secondary');
                                                 ?>
                                                 <span class="badge badge-<?= $p_badge ?>"><?= htmlspecialchars($st_periksa) ?></span>
                                             <?php endif; ?>
                                         </td>
                                         <?php if (!in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                         <td class="text-center">
                                             <?php if (!empty($s['file_path'])): ?>
                                                 <?php $ext = strtoupper(pathinfo($s['file_path'], PATHINFO_EXTENSION)); ?>
                                                 <a href="preview_tugas.php?id_pengumpulan=<?= (int)$s['id_pengumpulan'] ?>" target="_blank" class="btn btn-primary btn-sm" title="Baca Berkas Siswa (Tab Baru)">
                                                     <i class="fas fa-book-reader mr-1"></i> <?= $ext ?>
                                                 </a>
                                             <?php else: ?>
                                                 <span class="text-muted small">-</span>
                                             <?php endif; ?>
                                         </td>
                                         <?php endif; ?>
                                         <td class="text-center">
                                             <?php
                                             $s_json = $s;
                                             $s_json['soal_list'] = $soal_list;
                                             ?>
                                             <?php if (in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                                 <button type="button" class="btn btn-info btn-sm btn-lihat-jawaban"
                                                     data-json='<?= htmlspecialchars(json_encode($s_json), ENT_QUOTES, 'UTF-8') ?>'
                                                     title="Lihat Lembar Jawaban Siswa">
                                                     <i class="fas fa-eye mr-1"></i> Lihat Jawaban
                                                 </button>
                                             <?php else: ?>
                                                 <?php if ($can_crud): ?>
                                                 <button type="button" class="btn btn-primary btn-sm btn-periksa"
                                                     data-json='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>'
                                                     title="Periksa, Nilai & Feedback">
                                                     <i class="fas fa-check-circle mr-1"></i> Periksa / Nilai
                                                 </button>
                                                 <?php else: ?>
                                                 <button type="button" class="btn btn-info btn-sm btn-periksa"
                                                     data-json='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>'
                                                     title="Lihat Detail Nilai & Feedback">
                                                     <i class="fas fa-eye mr-1"></i> Detail
                                                 </button>
                                                 <?php endif; ?>
                                             <?php endif; ?>
                                         </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile App View: Cards Siswa (Tanpa Tabel) -->
                    <div class="d-md-none">
                        <div class="form-group mb-3">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
                                </div>
                                <input type="text" id="mobileSearchPengumpulan" class="form-control border-left-0" placeholder="Cari nama siswa...">
                            </div>
                        </div>

                        <div class="d-flex flex-column" id="mobilePengumpulanList" style="gap: 10px;">
                            <?php foreach ($students as $i => $s): ?>
                                <?php
                                $is_kumpul = !empty($s['tgl_kumpul']);
                                $keterlambatan_label = '-';
                                if ($is_kumpul && $deadline_ts) {
                                    $diff = strtotime($s['tgl_kumpul']) - $deadline_ts;
                                    if ($diff > 0) {
                                        $jam = floor($diff / 3600);
                                        $keterlambatan_label = $jam > 24 ? floor($jam / 24) . ' hari' : $jam . ' jam';
                                    } else {
                                        $keterlambatan_label = 'Tepat Waktu';
                                    }
                                }
                                $s_json = $s;
                                $s_json['soal_list'] = $soal_list;
                                ?>
                                <div class="card mb-2 border shadow-sm mobile-student-card" data-nama="<?= htmlspecialchars(strtolower($s['nama_siswa'])) ?>" style="border-radius: 14px; overflow: hidden;">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div class="d-flex align-items-center" style="gap: 10px;">
                                                <div class="rounded-circle bg-light text-primary font-weight-bold d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; font-size: 13.5px; border: 1.5px solid #dbeafe; flex-shrink: 0;">
                                                    <?= $i + 1 ?>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0 font-weight-bold text-dark" style="font-size: 14px;"><?= htmlspecialchars($s['nama_siswa']) ?></h6>
                                                    <small class="text-muted">NISN: <?= htmlspecialchars($s['nisn'] ?? '-') ?></small>
                                                </div>
                                            </div>
                                            <?php if (in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                                <?php if ($is_kumpul): ?>
                                                    <span class="badge badge-success">Sudah</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Belum</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($is_kumpul): ?>
                                                    <span class="badge badge-success">Sudah Kumpul</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">Belum Kumpul</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                        <div class="bg-light p-2 rounded mb-3" style="font-size: 12px;">
                                            <div class="d-flex justify-content-between py-1 border-bottom">
                                                <span class="text-muted"><?= in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true) ? 'Tgl Mengerjakan' : 'Tgl Kumpul' ?>:</span>
                                                <strong><?= $is_kumpul ? date('d/m/Y H:i', strtotime($s['tgl_kumpul'])) : '-' ?></strong>
                                            </div>
                                            <div class="d-flex justify-content-between py-1 border-bottom">
                                                <span class="text-muted">Keterlambatan:</span>
                                                <span>
                                                    <?php if ($keterlambatan_label === 'Tepat Waktu'): ?>
                                                        <span class="badge badge-light text-success font-weight-bold py-0 px-2">Tepat Waktu</span>
                                                    <?php elseif ($keterlambatan_label !== '-'): ?>
                                                        <span class="badge badge-warning py-0 px-2"><?= $keterlambatan_label ?></span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center py-1 <?= !in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true) ? 'border-bottom' : '' ?>">
                                                <span class="text-muted">Nilai:</span>
                                                <strong>
                                                    <?php if ($s['nilai'] !== null): ?>
                                                        <span class="badge badge-primary font-weight-bold" style="font-size: 13px;"><?= (float)$s['nilai'] ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </strong>
                                            </div>
                                            <?php if (!in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                            <div class="d-flex justify-content-between py-1">
                                                <span class="text-muted">Status Periksa:</span>
                                                <span class="badge badge-<?= ($s['status_periksa'] ?? '') === 'Sudah Diperiksa' ? 'success' : (($s['status_periksa'] ?? '') === 'Perlu Revisi' ? 'warning' : 'secondary') ?>">
                                                    <?= htmlspecialchars($s['status_periksa'] ?? 'Belum Diperiksa') ?>
                                                </span>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)): ?>
                                            <button type="button" class="btn btn-info btn-sm btn-block btn-lihat-jawaban"
                                                data-json='<?= htmlspecialchars(json_encode($s_json), ENT_QUOTES, 'UTF-8') ?>'>
                                                <i class="fas fa-eye mr-1"></i> Lihat Jawaban
                                            </button>
                                        <?php else: ?>
                                            <?php if ($can_crud): ?>
                                                <button type="button" class="btn btn-primary btn-sm btn-block btn-periksa"
                                                    data-json='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>'>
                                                    <i class="fas fa-check-circle mr-1"></i> Periksa / Nilai
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-info btn-sm btn-block btn-periksa"
                                                    data-json='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>'>
                                                    <i class="fas fa-eye mr-1"></i> Detail
                                                </button>
                                            <?php endif; ?>
                                            <?php if (!empty($s['file_path'])): ?>
                                                <?php $ext = strtoupper(pathinfo($s['file_path'], PATHINFO_EXTENSION)); ?>
                                                <a href="preview_tugas.php?id_pengumpulan=<?= (int)$s['id_pengumpulan'] ?>" target="_blank" class="btn btn-outline-primary btn-sm btn-block mt-2">
                                                    <i class="fas fa-book-reader mr-1"></i> Baca Berkas (<?= $ext ?>)
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Periksa, Nilai & Feedback -->
<div class="modal fade" id="modalPeriksa" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="nilai_tugas">
                <input type="hidden" name="id_pengumpulan" id="mdl_id_pengumpulan">
                <input type="hidden" name="id_siswa" id="mdl_id_siswa">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-award mr-2"></i>Pemeriksaan Tugas</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border">
                        Siswa: <strong id="mdl_nama_siswa"></strong>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Berkas Pengumpulan Siswa</label>
                        <div id="mdl_file_wrap"></div>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Catatan Siswa</label>
                        <div id="mdl_catatan_siswa" class="p-3 bg-light rounded border font-weight-normal" style="font-size: 14px; line-height: 1.6; color: #1e293b !important; white-space: pre-wrap;"></div>
                    </div>
                    <hr>
                    <div class="form-group">
                        <label class="font-weight-bold">Nilai (Maks: <?= (int)$tugas['nilai_maksimal'] ?>) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="nilai" id="mdl_nilai" class="form-control" required min="0" max="<?= (int)$tugas['nilai_maksimal'] ?>">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Status Pemeriksaan</label>
                        <select name="status_periksa" id="mdl_status_periksa" class="form-control">
                            <option value="Sudah Diperiksa">Sudah Diperiksa</option>
                            <option value="Perlu Revisi">Perlu Revisi</option>
                            <option value="Belum Diperiksa">Belum Diperiksa</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Feedback / Komentar Guru</label>
                        <textarea name="feedback" id="mdl_feedback" class="form-control" rows="3" placeholder="Berikan catatan perbaikan atau apresiasi..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                    <?php if ($can_crud): ?>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Penilaian</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Lihat Lembar Jawaban Siswa (Kuis / CBT) -->
<div class="modal fade" id="modalLihatJawaban" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white"><i class="fas fa-file-alt mr-2"></i>Lembar Jawaban Siswa: <span id="jw_nama_siswa"></span></h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body bg-light" style="max-height: 75vh; overflow-y: auto;">
                <div class="card mb-3 shadow-sm">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-4"><strong>Status:</strong> <span id="jw_status_kumpul"></span></div>
                            <div class="col-md-4"><strong>Tanggal Kumpul:</strong> <span id="jw_tgl_kumpul"></span></div>
                            <div class="col-md-4"><strong>Nilai Diperoleh:</strong> <span id="jw_nilai" class="text-primary font-weight-bold" style="font-size: 16px;"></span></div>
                        </div>
                    </div>
                </div>
                <div id="jw_container_soal"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Detail Analisis Butir Soal -->
<div class="modal fade" id="modalDetailAnalisisSoal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title text-white"><i class="fas fa-chart-pie mr-2"></i>Detail Analisis Soal No. <span id="an_no_soal"></span></h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body bg-light" style="max-height: 75vh; overflow-y: auto;">
                <div class="card mb-3 shadow-sm border">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap mb-2" style="gap:6px;">
                            <div>
                                <span class="badge badge-info" id="an_tipe_soal"></span>
                                <span class="badge badge-secondary ml-1" id="an_bobot"></span>
                            </div>
                            <div>
                                <span class="badge" id="an_badge_kesukaran"></span>
                                <span class="badge ml-1" id="an_badge_pembeda"></span>
                            </div>
                        </div>
                        <label class="font-weight-bold text-muted small mb-1">Pertanyaan Soal:</label>
                        <div class="p-3 bg-white rounded border mb-2 font-weight-normal text-dark" id="an_pertanyaan" style="font-size: 14.5px; line-height: 1.6;"></div>
                        <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                            <span>Kunci Jawaban Guru: <strong class="text-success" id="an_kunci"></strong></span>
                            <span class="text-muted small" id="an_rekomendasi_wrap"></span>
                        </div>
                    </div>
                </div>

                <!-- Distribusi Opsi Jawaban Siswa -->
                <div class="card mb-3 shadow-sm border" id="an_box_distribusi">
                    <div class="card-header bg-white py-2">
                        <h6 class="mb-0 font-weight-bold text-dark"><i class="fas fa-poll mr-2 text-primary"></i>Distribusi Pemilihan Opsi Jawaban</h6>
                    </div>
                    <div class="card-body py-3" id="an_container_opsi">
                        <!-- Populated via JS -->
                    </div>
                </div>

                <!-- Daftar Siswa Benar vs Salah -->
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100 shadow-sm border">
                            <div class="card-header bg-white py-2 text-success font-weight-bold">
                                <i class="fas fa-check-circle mr-1"></i> Siswa Menjawab Benar (<span id="an_cnt_benar">0</span>)
                            </div>
                            <div class="card-body py-2" id="an_list_benar" style="max-height: 220px; overflow-y: auto;">
                                <!-- List populated via JS -->
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="card h-100 shadow-sm border">
                            <div class="card-header bg-white py-2 text-danger font-weight-bold">
                                <i class="fas fa-times-circle mr-1"></i> Siswa Menjawab Salah (<span id="an_cnt_salah">0</span>)
                            </div>
                            <div class="card-body py-2" id="an_list_salah" style="max-height: 220px; overflow-y: auto;">
                                <!-- List populated via JS -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?php
$js_page = $js_page ?? [];
if ($message) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}
$js_page[] = <<<'JS'
$(document).ready(function() {
    $(document).on('click', '.btn-lihat-jawaban', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') { try { data = JSON.parse(data); } catch(e) {} }
        data = data || {};
        
        var soalList = data.soal_list || [];
        var totalBobotMaks = 0;
        soalList.forEach(function(s) {
            totalBobotMaks += (parseInt(s.bobot) || 10);
        });
        if (totalBobotMaks === 0) totalBobotMaks = 100;

        $('#jw_nama_siswa').text(data.nama_siswa || '-');
        if (data.tgl_kumpul) {
            $('#jw_status_kumpul').html('<span class="badge badge-success">Sudah Mengerjakan</span>');
            $('#jw_tgl_kumpul').text(data.tgl_kumpul);
            $('#jw_nilai').text((data.nilai !== null ? parseFloat(data.nilai) : 0) + ' / ' + totalBobotMaks);
        } else {
            $('#jw_status_kumpul').html('<span class="badge badge-secondary">Belum Mengerjakan</span>');
            $('#jw_tgl_kumpul').text('-');
            $('#jw_nilai').text('-');
        }

        var hasSubmitted = !!data.tgl_kumpul;
        var jawabanSiswa = {};
        if (hasSubmitted) {
            try {
                if (data.catatan_siswa) {
                    jawabanSiswa = JSON.parse(data.catatan_siswa);
                }
            } catch(e) {}
        }

        var htmlSoal = '';
        if (soalList.length === 0) {
            htmlSoal = '<div class="text-center text-muted py-4">Belum ada butir soal.</div>';
        } else {
            var ringkasan = { total: soalList.length, benar: 0, sebagian: 0, salah: 0, kosong: 0, totalPoin: 0 };
            soalList.forEach(function(soal, idx) {
                var sId = soal.id;
                var bobot = parseInt(soal.bobot) || 10;
                // Ambil jawaban persis berdasarkan ID butir soal (soal.id)
                var rawJwb = null;
                if (hasSubmitted) {
                    if (jawabanSiswa[sId] !== undefined) {
                        rawJwb = jawabanSiswa[sId];
                    } else if (jawabanSiswa[String(sId)] !== undefined) {
                        rawJwb = jawabanSiswa[String(sId)];
                    }
                }

                var isDijawab = (rawJwb !== null && rawJwb !== undefined && String(rawJwb).trim() !== '');
                var jwbSiswa = isDijawab ? String(rawJwb).trim() : null;
                var kunci = String(soal.kunci_jawaban || '').trim();
                var isCorrect = false;
                var poinDiperoleh = 0;

                if (hasSubmitted && isDijawab) {
                    if (soal.tipe_soal === 'Pilihan Ganda') {
                        isCorrect = (jwbSiswa.toLowerCase() === kunci.toLowerCase());
                        if (isCorrect) poinDiperoleh = bobot;
                    } else if (soal.tipe_soal === 'Pilihan Ganda Kompleks') {
                        var kArr = kunci.toUpperCase().split(',').map(function(s){ return s.trim(); }).filter(Boolean).sort();
                        var jArr = jwbSiswa.toUpperCase().split(',').map(function(s){ return s.trim(); }).filter(Boolean).sort();
                        isCorrect = (kArr.length > 0 && kArr.join(',') === jArr.join(','));
                        if (isCorrect) {
                            poinDiperoleh = bobot;
                        } else {
                            var benarCount = 0;
                            jArr.forEach(function(x){ if (kArr.indexOf(x) !== -1) benarCount++; });
                            var salahCount = jArr.length - benarCount;
                            if (benarCount > 0 && salahCount === 0) {
                                poinDiperoleh = Math.round((benarCount / kArr.length) * bobot * 10) / 10;
                            } else if (benarCount > 0 && salahCount > 0) {
                                poinDiperoleh = Math.round(Math.max(0, (benarCount - salahCount) / kArr.length) * bobot * 10) / 10;
                            }
                        }
                    } else if (soal.tipe_soal === 'Essay') {
                        isCorrect = (jwbSiswa.toLowerCase() === kunci.toLowerCase());
                        if (isCorrect) poinDiperoleh = bobot;
                    }
                }

                if (!hasSubmitted || !isDijawab) {
                    ringkasan.kosong++;
                } else if (isCorrect) {
                    ringkasan.benar++;
                } else if (poinDiperoleh > 0) {
                    ringkasan.sebagian++;
                } else {
                    ringkasan.salah++;
                }
                ringkasan.totalPoin += poinDiperoleh;

                htmlSoal += '<div class="card mb-3 shadow-sm border">';
                htmlSoal += '<div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap" style="gap:6px;">';
                htmlSoal += '<div><span class="badge badge-primary mr-2">Soal No. ' + (idx + 1) + '</span> <span class="badge badge-info mr-2">' + soal.tipe_soal + '</span> <span class="badge badge-light border font-weight-bold">Poin: ' + poinDiperoleh + ' / ' + bobot + '</span></div>';
                if (hasSubmitted) {
                    if (!isDijawab) {
                        htmlSoal += '<span class="badge badge-secondary"><i class="fas fa-minus mr-1"></i> Tidak Dijawab (+0)</span>';
                    } else if (isCorrect) {
                        htmlSoal += '<span class="badge badge-success"><i class="fas fa-check mr-1"></i> Benar (+' + poinDiperoleh + ')</span>';
                    } else if (poinDiperoleh > 0) {
                        htmlSoal += '<span class="badge badge-warning text-dark"><i class="fas fa-check-circle mr-1"></i> Sebagian Benar (+' + poinDiperoleh + ')</span>';
                    } else {
                        htmlSoal += '<span class="badge badge-danger"><i class="fas fa-times mr-1"></i> Salah (+0)</span>';
                    }
                } else {
                    htmlSoal += '<span class="badge badge-secondary">Siswa Belum Mengerjakan</span>';
                }
                htmlSoal += '</div>';
                htmlSoal += '<div class="card-body">';
                htmlSoal += '<p class="font-weight-bold text-dark mb-3" style="font-size:14.5px;">' + (soal.pertanyaan || '') + '</p>';

                if (soal.tipe_soal !== 'Essay') {
                    var opts = { 'A': soal.pilihan_a, 'B': soal.pilihan_b, 'C': soal.pilihan_c, 'D': soal.pilihan_d, 'E': soal.pilihan_e };
                    var chosenArr = isDijawab ? jwbSiswa.toUpperCase().split(',').map(function(s){ return s.trim(); }) : [];
                    var keyArr = kunci.toUpperCase().split(',').map(function(s){ return s.trim(); });

                    htmlSoal += '<div class="pl-2 mb-3">';
                    for (var k in opts) {
                        if (opts[k]) {
                            var isChosen = chosenArr.indexOf(k) !== -1;
                            var isKey = keyArr.indexOf(k) !== -1;
                            var badgeCls = 'text-dark';
                            if (isKey) badgeCls = 'text-success font-weight-bold';
                            if (isChosen && !isKey) badgeCls = 'text-danger font-weight-bold';

                            htmlSoal += '<div class="py-1 ' + badgeCls + '">';
                            htmlSoal += '<strong>' + k + '.</strong> ' + opts[k];
                            if (isChosen) {
                                htmlSoal += ' <span class="badge badge-light border text-danger ml-1"><em>(Pilihan Siswa)</em></span>';
                            }
                            if (isKey) {
                                htmlSoal += ' <span class="badge badge-success ml-1"><strong>(Kunci)</strong></span>';
                            }
                            htmlSoal += '</div>';
                        }
                    }
                    htmlSoal += '</div>';
                } else {
                    htmlSoal += '<div class="p-3 bg-light rounded mb-2"><strong>Jawaban Siswa:</strong><br>' + (isDijawab ? jwbSiswa : '<em class="text-muted">Tidak dijawab / Kosong</em>') + '</div>';
                }

                htmlSoal += '<div class="small text-muted font-italic">Kunci Jawaban Guru: <strong>' + (kunci || '-') + '</strong> | Bobot: ' + bobot + '</div>';
                htmlSoal += '</div></div>';
            });

            // Analisis ringkasan performa siswa
            if (hasSubmitted) {
                var htmlSummary = '<div class="alert alert-light border mb-3 p-3">';
                htmlSummary += '<div class="row text-center">';
                htmlSummary += '<div class="col-3"><strong>Total Soal:</strong><br><span class="badge badge-primary px-3 py-1 font-weight-bold" style="font-size:13px;">' + ringkasan.total + ' Butir</span></div>';
                htmlSummary += '<div class="col-3"><strong>Jawaban Benar:</strong><br><span class="badge badge-success px-3 py-1 font-weight-bold" style="font-size:13px;">' + ringkasan.benar + ' Soal</span></div>';
                htmlSummary += '<div class="col-3"><strong>Jawaban Salah:</strong><br><span class="badge badge-danger px-3 py-1 font-weight-bold" style="font-size:13px;">' + (ringkasan.salah + ringkasan.sebagian) + ' Soal</span></div>';
                htmlSummary += '<div class="col-3"><strong>Tidak Dijawab:</strong><br><span class="badge badge-secondary px-3 py-1 font-weight-bold" style="font-size:13px;">' + ringkasan.kosong + ' Soal</span></div>';
                htmlSummary += '</div></div>';
                htmlSoal = htmlSummary + htmlSoal;
            }
        }

        $('#jw_container_soal').html(htmlSoal);
        $('#modalLihatJawaban').modal('show');
    });
});
JS;
?>

<!-- Modal Perpanjang Waktu -->
<div class="modal fade" id="modalPerpanjangWaktu" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="perpanjang_waktu">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-clock mr-2"></i>Perpanjang Batas Waktu Pengerjaan</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small mb-3">
                        <i class="fas fa-info-circle mr-1"></i> Deadline saat ini: <strong><?= !empty($tugas['deadline']) ? date('d/m/Y H:i', strtotime($tugas['deadline'])) : 'Tidak ada deadline' ?></strong>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Tambah Durasi Waktu</label>
                        <select name="tambah_jam" class="form-control" required>
                            <option value="6">Tambah 6 Jam</option>
                            <option value="12">Tambah 12 Jam</option>
                            <option value="24" selected>Tambah 1 Hari (24 Jam)</option>
                            <option value="48">Tambah 2 Hari (48 Jam)</option>
                            <option value="72">Tambah 3 Hari (72 Jam)</option>
                            <option value="168">Tambah 1 Minggu</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning font-weight-bold text-dark"><i class="fas fa-check mr-1"></i> Perpanjang Waktu Sekarang</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
