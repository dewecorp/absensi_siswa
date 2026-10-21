<?php
/**
 * Transaksi Setoran Tabungan Siswa.
 * Menu: Tabungan > Setoran.
 */

require_once __DIR__ . '/tabungan/config/config.php';

$page_title = 'Setoran Tabungan';
$csrf = appCsrfToken();
$pesan = '';

/* ---------------- PROSES POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!appVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $pesan = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $aksi = $_POST['aksi'] ?? '';

        if ($aksi === 'tambah' || $aksi === 'ubah') {
            $nisn = trim((string)($_POST['nisn'] ?? ''));
            $setor = (int)preg_replace('/[^0-9]/', '', (string)($_POST['setor'] ?? '0'));
            $tgl = trim((string)($_POST['tgl'] ?? date('Y-m-d')));
            $id = (int)($_POST['id_tabungan'] ?? 0);

            $siswa = $nisn !== '' ? tabungan_siswa($pdo, $nisn) : null;
            if (!$siswa) {
                $pesan = 'Siswa tidak ditemukan. Pilih siswa yang benar.';
            } elseif ($setor <= 0) {
                $pesan = 'Nominal setoran harus lebih dari 0.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
                $pesan = 'Tanggal setoran tidak valid.';
            } else {
                if ($aksi === 'tambah') {
                    $hasil = tabungan_tambah_transaksi($pdo, [
                        'nisn' => $nisn,
                        'setor' => $setor,
                        'tarik' => 0,
                        'tgl' => $tgl,
                        'jenis' => 'ST',
                        'petugas' => tabungan_aktor_nama(),
                    ]);
                    if ($hasil['ok']) {
                        logActivity($pdo, (string)($_SESSION['username'] ?? 'bendahara'), 'Setoran Tabungan', 'Setoran ' . tabungan_rupiah($setor) . ' untuk ' . $siswa['nama_siswa'] . ' (NISN ' . $nisn . ')');
                    }
                    $pesan = $hasil['message'];
                } else {
                    $hasil = tabungan_ubah_transaksi($pdo, $id, [
                        'nisn' => $nisn,
                        'setor' => $setor,
                        'tarik' => 0,
                        'tgl' => $tgl,
                        'tujuan_tarik' => 'lainnya',
                    ]);
                    if ($hasil['ok']) {
                        logActivity($pdo, (string)($_SESSION['username'] ?? 'bendahara'), 'Ubah Setoran Tabungan', 'Ubah setoran ' . tabungan_rupiah($setor) . ' untuk ' . $siswa['nama_siswa'] . ' (NISN ' . $nisn . ')');
                    }
                    $pesan = $hasil['message'];
                }
            }
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_tabungan'] ?? 0);
            $hasil = tabungan_hapus_transaksi($pdo, $id);
            if ($hasil['ok']) {
                logActivity($pdo, (string)($_SESSION['username'] ?? 'bendahara'), 'Hapus Setoran Tabungan', 'Hapus setoran #' . $id);
            }
            $pesan = $hasil['message'];
        }
    }
}

/* ---------------- DATA ---------------- */
$siswa_list = tabungan_daftar_siswa($pdo, ['hanya_aktif' => true]);
$transaksi = tabungan_transaksi($pdo, ['jenis' => 'ST', 'limit' => 1000]);
$total_setor = 0;
foreach ($transaksi as $t) {
    $total_setor += (int)$t['setor'];
}

include __DIR__ . '/../templates/header.php';
include_once __DIR__ . '/../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Setoran Tabungan</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="tabungan_ringkasan.php">Ringkasan Tabungan</a></div>
                <div class="breadcrumb-item active">Setoran</div>
            </div>
        </div>

        <div class="section-body">

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-<?= stripos($pesan, 'berhasil') !== false ? 'success' : 'warning' ?>">
                    <?= htmlspecialchars($pesan) ?>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-12 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list mr-2" style="color:#0d9488;"></i>Daftar Setoran</h5>
                            <div class="card-header-action">
                                <span class="tb-chip green">
                                    <span class="lbl">Total Setoran</span>
                                    <b><?= tabungan_rupiah($total_setor) ?></b>
                                </span>
                                <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#modalTambahSetor">
                                    <i class="fas fa-plus-circle mr-1"></i> Tambah Setoran
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover" id="tableSetor" style="width:100%;">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>NISN</th>
                                            <th>Nama Siswa</th>
                                            <th>Kelas</th>
                                            <th>Tanggal</th>
                                            <th>Setoran</th>
                                            <th>Admin/Bendahara</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($transaksi)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4 text-muted">Belum ada transaksi setoran.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($transaksi as $i => $t): ?>
                                                <tr>
                                                    <td><?= $i + 1 ?></td>
                                                    <td><?= htmlspecialchars((string)$t['nisn']) ?></td>
                                                    <td><?= htmlspecialchars((string)($t['nama_siswa'] ?? '-')) ?></td>
                                                    <td><?= htmlspecialchars((string)($t['nama_kelas'] ?? '-')) ?></td>
                                                    <td><?= htmlspecialchars(tabungan_tanggal_indo((string)$t['tgl'])) ?></td>
                                                    <td class="font-weight-bold text-success"><?= tabungan_rupiah($t['setor']) ?></td>
                                                    <td><?= htmlspecialchars(tabungan_aktor_label($t['petugas'] ?? '-')) ?></td>
                                                    <td>
                                                        <div class="tb-actions">
                                                            <button type="button" class="btn btn-warning btn-sm" title="Edit Setoran" data-tb-edit-setor
                                                                data-id="<?= (int)$t['id_tabungan'] ?>"
                                                                data-nisn="<?= htmlspecialchars((string)$t['nisn']) ?>"
                                                                data-setor="<?= (int)$t['setor'] ?>"
                                                                data-tgl="<?= htmlspecialchars((string)$t['tgl']) ?>">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-danger btn-sm" title="Hapus Setoran" data-tb-hapus-setor data-tb-native="1"
                                                                data-id="<?= (int)$t['id_tabungan'] ?>"
                                                                data-nama="<?= htmlspecialchars((string)($t['nama_siswa'] ?? '-')) ?>"
                                                                data-nominal="<?= tabungan_rupiah($t['setor']) ?>"
                                                                data-tb-konfirmasi="Data setoran siswa ini akan dihapus dan tidak dapat dikembalikan."
                                                                data-tb-judul="Hapus Setoran?">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<!-- Modal tambah setoran -->
<div class="modal fade" id="modalTambahSetor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="formSetor" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_tabungan" id="setor_id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle mr-2" style="color:#16a34a;"></i>Tambah Setoran</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="setor_nisn">Siswa</label>
                        <select class="form-control tb-select2" id="setor_nisn" name="nisn" required data-placeholder="Cari nama / NISN siswa...">
                            <option value="">-- Pilih Siswa --</option>
                            <?php foreach ($siswa_list as $s): ?>
                                <option value="<?= htmlspecialchars((string)$s['nisn']) ?>">
                                    <?= htmlspecialchars((string)$s['nama_siswa']) ?> &mdash; <?= htmlspecialchars((string)($s['nama_kelas'] ?? '-')) ?> (<?= htmlspecialchars((string)$s['nisn']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="setor_nilai">Nominal Setoran</label>
                        <div class="input-group">
                            <div class="input-group-text">Rp</div>
                            <input type="text" class="form-control tb-rupiah" id="setor_nilai" name="setor" placeholder="0" required>
                        </div>
                        <div class="form-text">Saldo siswa saat ini: <strong id="setor_saldo">-</strong></div>
                    </div>

                    <div class="form-group">
                        <label for="setor_tgl">Tanggal Setoran</label>
                        <input type="date" class="form-control" id="setor_tgl" name="tgl" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Simpan Setoran</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal ubah setoran -->
<div class="modal fade" id="modalUbahSetor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="formUbahSetor" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="aksi" value="ubah">
                <input type="hidden" name="id_tabungan" id="ubah_setor_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen mr-2"></i>Ubah Setoran</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="ubah_setor_nisn">Siswa</label>
                        <select class="form-control" id="ubah_setor_nisn" name="nisn" required>
                            <?php foreach ($siswa_list as $s): ?>
                                <option value="<?= htmlspecialchars((string)$s['nisn']) ?>">
                                    <?= htmlspecialchars((string)$s['nama_siswa']) ?> &mdash; <?= htmlspecialchars((string)($s['nama_kelas'] ?? '-')) ?> (<?= htmlspecialchars((string)$s['nisn']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ubah_setor_nilai">Nominal Setoran</label>
                        <div class="input-group">
                            <div class="input-group-text">Rp</div>
                            <input type="text" class="form-control tb-rupiah" id="ubah_setor_nilai" name="setor" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ubah_setor_tgl">Tanggal Setoran</label>
                        <input type="date" class="form-control" id="ubah_setor_tgl" name="tgl" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form hapus (hidden) -->
<form method="POST" id="formHapusSetor" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="aksi" value="hapus">
    <input type="hidden" name="id_tabungan" id="hapus_setor_id">
</form>

<script>
(function () {
    var saldoUrl = '<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan/api/saldo.php') ?>';

    function rupiahAngka(n) {
        return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
    }

    /* Ambil saldo siswa (AJAX) */
    function cekSaldo(nisn, target) {
        if (!nisn) {
            document.getElementById(target).textContent = '-';
            return;
        }
        fetch(saldoUrl + '?nisn=' + encodeURIComponent(nisn), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                document.getElementById(target).textContent = data && data.success ? rupiahAngka(data.saldo) : '-';
            })
            .catch(function () {
                document.getElementById(target).textContent = '-';
            });
    }

    var selectSiswa = document.getElementById('setor_nisn');

    /* Select2 memunculkan event 'change' lewat jQuery (.trigger), bukan event
       DOM asli, sehingga listener harus dipasang dengan jQuery. jQuery dimuat
       di footer, jadi pemasangan listener ditunda sampai DOM siap. */
    document.addEventListener('DOMContentLoaded', function () {
        if (!selectSiswa) {
            return;
        }
        var $ = window.jQuery;
        if ($) {
            $(selectSiswa).on('change', function () {
                cekSaldo(this.value, 'setor_saldo');
            });
        } else {
            selectSiswa.addEventListener('change', function () {
                cekSaldo(this.value, 'setor_saldo');
            });
        }
    });

    /* Modal tambah setoran: bersihkan form & muat saldo saat dibuka */
    document.addEventListener('DOMContentLoaded', function () {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        $('#modalTambahSetor').on('shown.bs.modal', function () {
            var inputNilai = document.getElementById('setor_nilai');
            if (inputNilai) {
                inputNilai.value = '';
            }
            var inputTgl = document.getElementById('setor_tgl');
            if (inputTgl) {
                inputTgl.value = '<?= date('Y-m-d') ?>';
            }
            cekSaldo(selectSiswa ? selectSiswa.value : '', 'setor_saldo');
        });
    });

    /* Edit */
    var formUbah = document.getElementById('formUbahSetor');
    document.querySelectorAll('[data-tb-edit-setor]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('ubah_setor_id').value = btn.getAttribute('data-id');
            document.getElementById('ubah_setor_nisn').value = btn.getAttribute('data-nisn');
            document.getElementById('ubah_setor_nilai').value = Number(btn.getAttribute('data-setor') || 0).toLocaleString('id-ID');
            document.getElementById('ubah_setor_tgl').value = btn.getAttribute('data-tgl');
            $('#modalUbahSetor').modal('show');
        });
    });

    /* Hapus */
    var formHapus = document.getElementById('formHapusSetor');
    document.querySelectorAll('[data-tb-hapus-setor]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var pesan = btn.getAttribute('data-tb-konfirmasi') || 'Yakin ingin menghapus data ini?';
            var nama = btn.getAttribute('data-nama') || '-';
            var nominal = btn.getAttribute('data-nominal') || '-';
            if (window.Swal) {
                Swal.fire({
                    title: btn.getAttribute('data-tb-judul') || 'Hapus Setoran?',
                    html: '<div style="text-align:left"><b>' + nama + '</b><br>' + nominal + '</div><br><small>' + pesan + '</small>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Hapus',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#e11d48',
                    reverseButtons: true
                }).then(function (r) {
                    if (r.isConfirmed) {
                        document.getElementById('hapus_setor_id').value = btn.getAttribute('data-id');
                        formHapus.submit();
                    }
                });
            } else if (confirm(nama + ' - ' + nominal + '\n\n' + pesan)) {
                document.getElementById('hapus_setor_id').value = btn.getAttribute('data-id');
                formHapus.submit();
            }
        });
    });
})();
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
