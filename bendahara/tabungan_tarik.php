<?php
/**
 * Transaksi Penarikan Tabungan (+ sinkronisasi ke Pembayaran).
 * Menu: Tabungan > Penarikan.
 *
 * Penarikan dengan tujuan "Pembayaran" langsung dicatat ke tabel `pembayaran`
 * (modul Pembayaran Siswa / Sibayar di dalam SIMAD) sehingga tagihan siswa
 * menjadi lunas. Nominal selalu dihitung ulang di server dari data tagihan
 * terbaru (tidak mempercayai nilai dari peramban).
 */

require_once __DIR__ . '/tabungan/config/config.php';

$page_title = 'Penarikan Tabungan';
$csrf = appCsrfToken();
$pesan = '';
$pesan_tipe = 'warning';

/* ================= PROSES POST ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!appVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $pesan = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $aksi = (string)($_POST['aksi'] ?? '');

        /* ---------- Simpan penarikan ---------- */
        if ($aksi === 'tambah' || $aksi === 'ubah') {
            $nisn = trim((string)($_POST['nisn'] ?? ''));
            $tujuan = ((string)($_POST['tujuan_tarik'] ?? 'lainnya') === 'pembayaran') ? 'pembayaran' : 'lainnya';
            $tgl = trim((string)($_POST['tgl'] ?? date('Y-m-d')));
            $keterangan = trim((string)($_POST['keterangan_tarik'] ?? ''));
            $id = (int)($_POST['id_tabungan'] ?? 0);

            $siswa = $nisn !== '' ? tabungan_siswa($pdo, $nisn) : null;
            if (!$siswa) {
                $pesan = 'Siswa tidak ditemukan. Pilih siswa yang benar.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
                $pesan = 'Tanggal penarikan tidak valid.';
            } else {
                $items_terpilih = [];
                $jenis_bayar_nama = [];
                $jenis_bayar_id = '';
                $nominal = 0;

                if ($tujuan === 'pembayaran') {
                    // Item tagihan yang dipilih peramban: "id_jenis_bayar|tahun_ajaran".
                    $pilih = [];
                    if (isset($_POST['item_pilih']) && is_array($_POST['item_pilih'])) {
                        foreach ($_POST['item_pilih'] as $v) {
                            $v = trim((string)$v);
                            if ($v === '') {
                                continue;
                            }
                            $pecah = explode('|', $v, 2);
                            $id_pilih = (int)($pecah[0] ?? 0);
                            if ($id_pilih <= 0) {
                                continue;
                            }
                            $pilih[] = [
                                'id' => $id_pilih,
                                'ta' => trim((string)($pecah[1] ?? '')),
                            ];
                        }
                    }

                    if (!$pilih) {
                        $pesan = 'Pilih minimal satu tagihan yang akan dibayar.';
                    } else {
                        $tagihan = tabungan_tagihan_siswa($nisn);
                        if (!$tagihan['ok']) {
                            $pesan = $tagihan['message'] !== '' ? $tagihan['message'] : 'Gagal memuat data tagihan siswa.';
                        } else {
                            $tahun_ajaran = (string)$tagihan['tahun_ajaran'];
                            if (!tabungan_boleh_ditagihkan($tahun_ajaran)) {
                                $pesan = 'Transaksi ditolak. Tahun ajaran aktif belum memasuki tanggal mulai.';
                            } else {
                                // Peta tagihan live (kunci: id_jenis_bayar|tahun_ajaran).
                                $peta = [];
                                $ada_tunggakan = false;
                                foreach ($tagihan['items'] as $item) {
                                    $peta[(int)$item['id_jenis_bayar'] . '|' . (string)$item['tahun_ajaran']] = $item;
                                    if (!empty($item['is_tunggakan'])) {
                                        $ada_tunggakan = true;
                                    }
                                }

                                $post_item = (isset($_POST['item']) && is_array($_POST['item'])) ? $_POST['item'] : [];
                                $items_terpilih = [];
                                $jenis_bayar_nama = [];
                                $jenis_bayar_id = '';
                                $nominal = 0;
                                $pilih_tunggakan = false;
                                $pilih_aktif = false;

                                foreach ($pilih as $p) {
                                    $kunci = $p['id'] . '|' . $p['ta'];
                                    $item = $peta[$kunci] ?? null;
                                    if (!$item) {
                                        continue;
                                    }
                                    if ((int)$item['sisa'] <= 0) {
                                        continue;
                                    }
                                    if (!empty($item['is_tunggakan'])) {
                                        $pilih_tunggakan = true;
                                    } else {
                                        $pilih_aktif = true;
                                    }

                                    $post = (isset($post_item[$kunci]) && is_array($post_item[$kunci])) ? $post_item[$kunci] : [];
                                    $tipe = (string)$item['tipe_bayar'];
                                    $item_terpilih = [
                                        'id_jenis_bayar' => (int)$item['id_jenis_bayar'],
                                        'nama_pembayaran' => (string)$item['nama_pembayaran'],
                                        'tipe_bayar' => $tipe,
                                        'tahun_ajaran' => (string)$item['tahun_ajaran'],
                                        'nominal' => (int)$item['nominal'],
                                        'bulan' => [],
                                        'jumlah' => 0,
                                        'cicilan_ke' => (int)($item['cicilan_ke'] ?? 0),
                                        'sisa' => 0,
                                    ];

                                    if ($tipe === 'Bulanan') {
                                        $bulan_req = [];
                                        foreach ((array)($post['bulan'] ?? []) as $m) {
                                            $m = trim((string)$m);
                                            if ($m !== '') {
                                                $bulan_req[] = $m;
                                            }
                                        }
                                        // Hanya bulan yang benar-benar belum lunas (data live).
                                        $bulan_valid = array_values(array_intersect($bulan_req, (array)$item['bulan']));
                                        if (!$bulan_valid) {
                                            $pesan = 'Belum ada bulan yang valid dipilih untuk "' . $item['nama_pembayaran'] . '".';
                                            break;
                                        }
                                        $jumlah = (int)$item['nominal'] * count($bulan_valid);
                                        $item_terpilih['bulan'] = $bulan_valid;
                                    } else {
                                        $jumlah = (int)preg_replace('/[^0-9]/', '', (string)($post['jumlah'] ?? '0'));
                                        if ($jumlah <= 0) {
                                            $jumlah = (int)$item['sisa'];
                                        }
                                        if ($jumlah > (int)$item['sisa']) {
                                            $pesan = 'Nominal pembayaran "' . $item['nama_pembayaran'] . '" melebihi sisa tagihan (' . tabungan_rupiah((int)$item['sisa']) . ').';
                                            break;
                                        }
                                    }

                                    $item_terpilih['jumlah'] = $jumlah;
                                    $item_terpilih['sisa'] = $jumlah;
                                    $items_terpilih[] = $item_terpilih;
                                    $jenis_bayar_nama[] = (string)$item['nama_pembayaran'];
                                    $jenis_bayar_id .= (string)$item['id_jenis_bayar'] . ',';
                                    $nominal += $jumlah;
                                }

                                if ($pesan === '' && !$items_terpilih) {
                                    $pesan = 'Tagihan yang dipilih tidak memiliki tunggakan. Periksa kembali tagihan siswa.';
                                } elseif ($pesan === '' && $ada_tunggakan && $pilih_aktif && !$pilih_tunggakan) {
                                    // Sama seperti e-Tabs asli: tunggakan lama didahulukan.
                                    $pesan = 'Prioritas Tunggakan! Siswa masih memiliki tunggakan tahun ajaran lama. Pilih dan lunasi tunggakan terlebih dahulu sebelum membayar tagihan tahun ajaran aktif.';
                                }
                            }
                        }
                    }
                } else {
                    $nominal = (int)preg_replace('/[^0-9]/', '', (string)($_POST['tarik'] ?? '0'));
                    if ($nominal <= 0) {
                        $pesan = 'Nominal penarikan harus lebih dari 0.';
                    } elseif ($keterangan === '') {
                        $pesan = 'Keterangan penarikan wajib diisi untuk penarikan lainnya.';
                    }
                }

                if ($pesan === '') {
                    $saldo = tabungan_saldo($pdo, $nisn);

                    if ($aksi === 'tambah') {
                        if ($saldo < $nominal) {
                            $pesan = 'Saldo tidak mencukupi.';
                        } else {
                            $payment_ref = null;
                            $payment_sync = 'none';
                            $payment_detail = null;

                            if ($tujuan === 'pembayaran') {
                                $detail_items = [];
                                foreach ($items_terpilih as $item) {
                                    $detail_items[] = [
                                        'id_jenis_bayar' => (int)$item['id_jenis_bayar'],
                                        'nama_pembayaran' => (string)$item['nama_pembayaran'],
                                        'tipe_bayar' => (string)$item['tipe_bayar'],
                                        'tahun_ajaran' => (string)$item['tahun_ajaran'],
                                        'nominal' => (int)$item['nominal'],
                                        'sisa' => (int)$item['sisa'],
                                        'jumlah' => (int)($item['jumlah'] ?? $item['sisa']),
                                        'cicilan_ke' => (int)($item['cicilan_ke'] ?? 0),
                                        'bulan' => array_values((array)$item['bulan']),
                                    ];
                                }
                                $payment_detail = json_encode([
                                    'items' => $detail_items,
                                    'tahun_ajaran' => tabungan_tahun_ajaran_aktif(),
                                ], JSON_UNESCAPED_UNICODE);
                            }

                            $hasil = tabungan_tambah_transaksi($pdo, [
                                'nisn' => $nisn,
                                'setor' => 0,
                                'tarik' => $nominal,
                                'tgl' => $tgl,
                                'jenis' => 'TR',
                                'petugas' => tabungan_aktor_nama(),
                                'tujuan_tarik' => $tujuan,
                                'jenis_bayar' => $jenis_bayar_nama ? implode(', ', $jenis_bayar_nama) : null,
                                'jenis_bayar_id' => $jenis_bayar_id !== '' ? rtrim($jenis_bayar_id, ',') : null,
                                'keterangan_tarik' => $keterangan !== '' ? $keterangan : null,
                                'payment_sync' => $payment_sync,
                                'payment_detail' => $payment_detail,
                            ]);

                            if (!$hasil['ok']) {
                                $pesan = $hasil['message'];
                            } else {
                                $id_trx = $hasil['id'];

                                if ($tujuan === 'pembayaran') {
                                    $res = tabungan_catat_pembayaran([
                                        'nisn' => $nisn,
                                        'tgl' => $tgl,
                                        'items' => $items_terpilih,
                                        'keterangan' => 'Potongan Tabungan',
                                    ]);

                                    if ($res['ok']) {
                                        $detail = json_decode((string)$payment_detail, true) ?: [];
                                        if (!isset($detail['items']) || !is_array($detail['items'])) {
                                            $detail['items'] = [];
                                        }
                                        foreach ((array)($res['items'] ?? []) as $hasil_item) {
                                            foreach ($detail['items'] as $idx => $di) {
                                                if ((int)($di['id_jenis_bayar'] ?? 0) === (int)$hasil_item['id_jenis_bayar']
                                                    && (string)($di['tahun_ajaran'] ?? '') === (string)($hasil_item['tahun_ajaran'] ?? '')
                                                    && empty($di['no_transaksi'])) {
                                                    $detail['items'][$idx]['no_transaksi'] = $hasil_item['no_transaksi'];
                                                    $detail['items'][$idx]['jumlah_bayar'] = $hasil_item['jumlah_bayar'];
                                                    break;
                                                }
                                            }
                                        }
                                        $payment_detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
                                        $payment_ref = implode(', ', $res['no_transaksi']);
                                        $payment_sync = 'success';
                                        $pesan = 'Penarikan & pembayaran berhasil dicatat. No Transaksi: ' . $payment_ref;
                                        $pesan_tipe = 'success';
                                    } else {
                                        $payment_sync = 'failed';
                                        $detail = json_decode((string)$payment_detail, true) ?: [];
                                        $detail['error'] = $res['message'];
                                        $payment_detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
                                        $pesan = 'Penarikan tabungan berhasil disimpan! Namun pencatatan ke Pembayaran belum berhasil (' . $res['message'] . '). Gunakan tombol Sinkron Ulang pada tabel.';
                                    }

                                    $upd = $pdo->prepare("UPDATE tb_tabungan SET payment_ref = ?, payment_sync = ?, payment_detail = ? WHERE id_tabungan = ?");
                                    $upd->execute([$payment_ref, $payment_sync, $payment_detail, $id_trx]);
                                } else {
                                    $pesan = 'Penarikan berhasil ditambahkan.';
                                    $pesan_tipe = 'success';
                                }

                                logActivity($pdo, (string)($_SESSION['username'] ?? 'bendahara'), 'Penarikan Tabungan', 'Penarikan ' . tabungan_rupiah($nominal) . ' untuk ' . $siswa['nama_siswa'] . ' (NISN ' . $nisn . ') - ' . ($tujuan === 'pembayaran' ? 'Pembayaran' : 'Lainnya'));
                            }
                        }
                    } else {
                        // Ubah penarikan (hanya penarikan non-pembayaran).
                        $st = $pdo->prepare("SELECT * FROM tb_tabungan WHERE id_tabungan = ? LIMIT 1");
                        $st->execute([$id]);
                        $lama = $st->fetch(PDO::FETCH_ASSOC);
                        if (!$lama) {
                            $pesan = 'Transaksi tidak ditemukan.';
                        } elseif ((string)$lama['jenis'] !== 'TR') {
                            $pesan = 'Transaksi tidak valid.';
                        } elseif ((string)$lama['tujuan_tarik'] === 'pembayaran') {
                            $pesan = 'Penarikan yang sudah terhubung ke Pembayaran tidak dapat diubah. Hapus penarikan bila diperlukan.';
                        } elseif ($saldo + (int)$lama['tarik'] < $nominal) {
                            $pesan = 'Saldo tidak mencukupi untuk perubahan ini.';
                        } else {
                            $hasil = tabungan_ubah_transaksi($pdo, $id, [
                                'nisn' => $nisn,
                                'setor' => 0,
                                'tarik' => $nominal,
                                'tgl' => $tgl,
                                'tujuan_tarik' => 'lainnya',
                                'keterangan_tarik' => $keterangan,
                            ]);
                            $pesan = $hasil['message'];
                            $pesan_tipe = $hasil['ok'] ? 'success' : 'warning';
                            if ($hasil['ok']) {
                                logActivity($pdo, (string)($_SESSION['username'] ?? 'bendahara'), 'Ubah Penarikan Tabungan', 'Ubah penarikan ' . tabungan_rupiah($nominal) . ' untuk ' . $siswa['nama_siswa'] . ' (NISN ' . $nisn . ')');
                            }
                        }
                    }
                }
            }
        }

        /* ---------- Hapus penarikan ---------- */
        elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_tabungan'] ?? 0);
            $hasil = tabungan_hapus_transaksi($pdo, $id);
            if ($hasil['ok']) {
                $trx = $hasil['trx'] ?? [];
                // Hapus pembayaran yang dibuat dari penarikan ini (agar tidak ada tagihan fiktif).
                $detail = json_decode((string)($trx['payment_detail'] ?? ''), true);
                $refs = [];
                if (is_array($detail) && !empty($detail['items'])) {
                    foreach ((array)$detail['items'] as $di) {
                        if (!empty($di['no_transaksi'])) {
                            $refs[] = (string)$di['no_transaksi'];
                        }
                    }
                }
                // Cadangan: nomor transaksi pada payment_ref (mis. transaksi lama e-Tabs).
                foreach (explode(',', (string)($trx['payment_ref'] ?? '')) as $ref) {
                    $ref = trim($ref);
                    if ($ref !== '') {
                        $refs[] = $ref;
                    }
                }
                $refs = array_values(array_unique($refs));
                if ($refs) {
                    tabungan_hapus_pembayaran($refs);
                }
                logActivity($pdo, (string)($_SESSION['username'] ?? 'bendahara'), 'Hapus Penarikan Tabungan', 'Hapus penarikan #' . $id);
                $pesan = 'Penarikan berhasil dihapus.';
                $pesan_tipe = 'success';
            } else {
                $pesan = $hasil['message'];
            }
        }

        /* ---------- Sinkron ulang ke Pembayaran ---------- */
        elseif ($aksi === 'resync') {
            $id = (int)($_POST['id_tabungan'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM tb_tabungan WHERE id_tabungan = ? LIMIT 1");
            $st->execute([$id]);
            $trx = $st->fetch(PDO::FETCH_ASSOC);

            if (!$trx) {
                $pesan = 'Transaksi tidak ditemukan.';
            } elseif ((string)$trx['tujuan_tarik'] !== 'pembayaran') {
                $pesan = 'Penarikan ini bukan transaksi pembayaran.';
            } else {
                $detail = json_decode((string)($trx['payment_detail'] ?? ''), true);
                if (!is_array($detail) || empty($detail['items'])) {
                    $pesan = 'Detail pembayaran lama kosong, tidak bisa sinkron ulang.';
                } else {
                    // Jangan dobel catat: lewati item yang sudah punya no transaksi.
                    $items = [];
                    foreach ((array)$detail['items'] as $di) {
                        $no = trim((string)($di['no_transaksi'] ?? ''));
                        if ($no !== '' && tabungan_pembayaran_sudah_ada($no)) {
                            continue;
                        }
                        $items[] = [
                            'id_jenis_bayar' => (int)($di['id_jenis_bayar'] ?? 0),
                            'nama_pembayaran' => (string)($di['nama_pembayaran'] ?? ''),
                            'tipe_bayar' => (string)($di['tipe_bayar'] ?? 'Bulanan'),
                            'tahun_ajaran' => (string)($di['tahun_ajaran'] ?? ''),
                            'nominal' => (int)($di['nominal'] ?? 0),
                            'sisa' => (int)($di['sisa'] ?? 0),
                            'jumlah' => (int)($di['jumlah'] ?? ($di['sisa'] ?? 0)),
                            'cicilan_ke' => (int)($di['cicilan_ke'] ?? 0),
                            'bulan' => array_values((array)($di['bulan'] ?? [])),
                        ];
                    }

                    if (!$items) {
                        // Semua item sudah tercatat di modul Pembayaran -> tandai sukses.
                        $pdo->prepare("UPDATE tb_tabungan SET payment_sync = 'success' WHERE id_tabungan = ?")->execute([$id]);
                        $pesan = 'Transaksi pembayaran ini sudah tercatat di modul Pembayaran.';
                    } else {
                        $res = tabungan_catat_pembayaran([
                            'nisn' => (string)$trx['nisn'],
                            'tgl' => (string)$trx['tgl'],
                            'items' => $items,
                            'keterangan' => 'Potongan Tabungan',
                        ]);

                        if ($res['ok']) {
                            $detail['items'] = is_array($detail['items'] ?? null) ? $detail['items'] : [];
                            foreach ((array)($res['items'] ?? []) as $hasil_item) {
                                foreach ($detail['items'] as $idx => $di) {
                                    if ((int)($di['id_jenis_bayar'] ?? 0) === (int)$hasil_item['id_jenis_bayar']
                                        && (string)($di['tahun_ajaran'] ?? '') === (string)($hasil_item['tahun_ajaran'] ?? '')
                                        && empty($di['no_transaksi'])) {
                                        $detail['items'][$idx]['no_transaksi'] = $hasil_item['no_transaksi'];
                                        $detail['items'][$idx]['jumlah_bayar'] = $hasil_item['jumlah_bayar'];
                                        break;
                                    }
                                }
                            }
                            unset($detail['error']);
                            $upd = $pdo->prepare("UPDATE tb_tabungan SET payment_sync = 'success', payment_ref = ?, payment_detail = ? WHERE id_tabungan = ?");
                            $upd->execute([implode(', ', $res['no_transaksi']), json_encode($detail, JSON_UNESCAPED_UNICODE), $id]);
                            $pesan = 'Transaksi berhasil disinkronkan ke Pembayaran: ' . implode(', ', $res['no_transaksi']);
                            $pesan_tipe = 'success';
                        } else {
                            $detail['error'] = $res['message'];
                            $upd = $pdo->prepare("UPDATE tb_tabungan SET payment_sync = 'failed', payment_detail = ? WHERE id_tabungan = ?");
                            $upd->execute([json_encode($detail, JSON_UNESCAPED_UNICODE), $id]);
                            $pesan = 'Sinkron ulang ke Pembayaran gagal: ' . $res['message'];
                        }
                    }
                }
            }
        }
    }
}

/* ================= DATA ================= */
$siswa_list = tabungan_daftar_siswa($pdo, ['hanya_aktif' => true]);
$transaksi = tabungan_transaksi($pdo, ['jenis' => 'TR', 'limit' => 1000]);
$total_tarik = 0;
foreach ($transaksi as $t) {
    $total_tarik += (int)$t['tarik'];
}

include __DIR__ . '/../templates/header.php';
include_once __DIR__ . '/../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Penarikan Tabungan</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="tabungan_ringkasan.php">Ringkasan Tabungan</a></div>
                <div class="breadcrumb-item active">Penarikan</div>
            </div>
        </div>

        <div class="section-body">

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($pesan_tipe) ?>">
                    <?= htmlspecialchars($pesan) ?>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-12 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list mr-2" style="color:#be123c;"></i>Daftar Penarikan</h5>
                            <div class="card-header-action">
                                <span class="tb-chip rose">
                                    <span class="lbl">Total Penarikan</span>
                                    <b><?= tabungan_rupiah($total_tarik) ?></b>
                                </span>
                                <button type="button" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#modalTambahTarik">
                                    <i class="fas fa-plus-circle mr-1"></i> Tambah Penarikan
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover" id="tableTarik" style="width:100%;">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>NISN</th>
                                            <th>Nama Siswa</th>
                                            <th>Kelas</th>
                                            <th>Tanggal</th>
                                            <th>Tujuan</th>
                                            <th>Jenis / Keterangan</th>
                                            <th>Penarikan</th>
                                            <th>Sinkron</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($transaksi)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center py-4 text-muted">Belum ada transaksi penarikan.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($transaksi as $i => $t):
                                                $sync = (string)($t['payment_sync'] ?? 'none');
                                                $tujuan = (string)($t['tujuan_tarik'] ?? 'lainnya');
                                                $jenis = trim((string)($t['jenis_bayar'] ?? ''));
                                                if ($jenis === '') {
                                                    $jenis = trim((string)($t['keterangan_tarik'] ?? ''));
                                                }
                                                if ($jenis === '') {
                                                    $jenis = '-';
                                                }
                                                $sync_label = [
                                                    'success' => '<span class="tb-sync success"><i class="fas fa-check"></i> Tersinkron</span>',
                                                    'failed' => '<span class="tb-sync failed"><i class="fas fa-rotate"></i> Gagal</span>',
                                                    'mock' => '<span class="tb-sync mock"><i class="fas fa-rotate"></i> Mock</span>',
                                                    'none' => '<span class="tb-sync none">-</span>',
                                                ][$sync] ?? '<span class="tb-sync none">-</span>';
                                            ?>
                                                <tr>
                                                    <td><?= $i + 1 ?></td>
                                                    <td><?= htmlspecialchars((string)$t['nisn']) ?></td>
                                                    <td><?= htmlspecialchars((string)($t['nama_siswa'] ?? '-')) ?></td>
                                                    <td><?= htmlspecialchars((string)($t['nama_kelas'] ?? '-')) ?></td>
                                                    <td><?= htmlspecialchars(tabungan_tanggal_indo((string)$t['tgl'])) ?></td>
                                                    <td>
                                                        <?php if ($tujuan === 'pembayaran'): ?>
                                                            <span class="badge badge-primary">Pembayaran</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-secondary">Lainnya</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($jenis) ?></td>
                                                    <td class="font-weight-bold text-danger"><?= tabungan_rupiah($t['tarik']) ?></td>
                                                    <td><?= $sync_label ?></td>
                                                    <td>
                                                        <div class="tb-actions">
                                                            <?php if ($tujuan === 'pembayaran' && $sync !== 'success'): ?>
                                                                <form method="POST" style="display:inline;">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                                    <input type="hidden" name="aksi" value="resync">
                                                                    <input type="hidden" name="id_tabungan" value="<?= (int)$t['id_tabungan'] ?>">
                                                                    <button type="submit" class="btn btn-info btn-sm" title="Sinkron Ulang ke Pembayaran" data-tb-konfirmasi="Transaksi pembayaran lama akan dikirim ke modul Pembayaran tanpa membuat penarikan baru." data-tb-judul="Sinkron Ulang?">
                                                                        <i class="fas fa-sync-alt"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <?php if ($tujuan !== 'pembayaran'): ?>
                                                                <button type="button" class="btn btn-warning btn-sm" title="Edit Penarikan" data-tb-edit-tarik
                                                                    data-id="<?= (int)$t['id_tabungan'] ?>"
                                                                    data-nisn="<?= htmlspecialchars((string)$t['nisn']) ?>"
                                                                    data-tarik="<?= (int)$t['tarik'] ?>"
                                                                    data-tgl="<?= htmlspecialchars((string)$t['tgl']) ?>"
                                                                    data-keterangan="<?= htmlspecialchars((string)($t['keterangan_tarik'] ?? '')) ?>">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn btn-danger btn-sm" title="Hapus Penarikan" data-tb-hapus-tarik data-tb-native="1"
                                                                data-id="<?= (int)$t['id_tabungan'] ?>"
                                                                data-nama="<?= htmlspecialchars((string)($t['nama_siswa'] ?? '-')) ?>"
                                                                data-nominal="<?= tabungan_rupiah($t['tarik']) ?>"
                                                                data-tb-konfirmasi="Data penarikan akan dihapus. Bila penarikan ini terhubung ke Pembayaran, transaksi pembayarannya juga dihapus." data-tb-judul="Hapus Penarikan?">
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

<!-- Modal tambah penarikan -->
<div class="modal fade" id="modalTambahTarik" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="formTarik" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="id_tabungan" id="tarik_id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-money-bill-transfer mr-2" style="color:#be123c;"></i>Tambah Penarikan</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="tarik_nisn">Siswa</label>
                        <select class="form-control tb-select2" id="tarik_nisn" name="nisn" required data-placeholder="Cari nama / NISN siswa...">
                            <option value="">-- Pilih Siswa --</option>
                            <?php foreach ($siswa_list as $s): ?>
                                <option value="<?= htmlspecialchars((string)$s['nisn']) ?>">
                                    <?= htmlspecialchars((string)$s['nama_siswa']) ?> &mdash; <?= htmlspecialchars((string)($s['nama_kelas'] ?? '-')) ?> (<?= htmlspecialchars((string)$s['nisn']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="tarik_saldo_info">Saldo Siswa</label>
                        <div class="tb-stat">
                            <div class="lbl">Saldo Tersedia</div>
                            <div class="val teal" id="tarik_saldo">-</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Tujuan Penarikan</label>
                        <div class="custom-control custom-radio mb-1">
                            <input type="radio" class="custom-control-input" id="tujuan_lainnya" name="tujuan_tarik" value="lainnya" checked>
                            <label class="custom-control-label" for="tujuan_lainnya">Lainnya (bayar di luar aplikasi)</label>
                        </div>
                        <div class="custom-control custom-radio">
                            <input type="radio" class="custom-control-input" id="tujuan_pembayaran" name="tujuan_tarik" value="pembayaran">
                            <label class="custom-control-label" for="tujuan_pembayaran">Pembayaran (potong tagihan siswa)</label>
                        </div>
                    </div>

                    <div id="panel_lainnya">
                        <div class="form-group">
                            <label for="tarik_nilai">Nominal Penarikan</label>
                            <div class="input-group">
                                <div class="input-group-text">Rp</div>
                                <input type="text" class="form-control tb-rupiah" id="tarik_nilai" name="tarik" placeholder="0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="tarik_keterangan">Keterangan</label>
                            <textarea class="form-control" id="tarik_keterangan" name="keterangan_tarik" rows="2" placeholder="Contoh: Penarikan tunai oleh orang tua"></textarea>
                        </div>
                    </div>

                    <div id="panel_pembayaran" style="display:none;">
                        <div class="form-group">
                            <label>Tagihan Siswa</label>
                            <div id="tarik_tagihan_info" class="form-text mb-2">Pilih siswa terlebih dahulu untuk memuat tagihan.</div>
                            <div id="tarik_daftar_tagihan" class="tb-tagihan"></div>
                            <div id="tarik_tunggakan" class="alert alert-danger py-2 px-3 mt-2" style="display:none; font-size:.8rem;"></div>
                        </div>

                        <div id="tarik_rincian" class="tb-rincian-panel" style="display:none;">
                            <div class="tb-rincian-panel-head">
                                <div>
                                    <div class="tb-rincian-panel-title">Rincian Tagihan Dipilih</div>
                                    <div class="tb-rincian-panel-sub" id="tarik_rincian_sub">Klik bulan untuk bayar sebagian</div>
                                </div>
                                <div class="tb-rincian-panel-total" id="tarik_rincian_total">Rp 0</div>
                            </div>
                            <div id="tarik_rincian_list"></div>
                        </div>

                        <div class="form-group">
                            <label for="tarik_nilai_bayar">Nominal Pembayaran</label>
                            <div class="input-group">
                                <div class="input-group-text">Rp</div>
                                <input type="text" class="form-control" id="tarik_nilai_bayar" value="0" readonly>
                            </div>
                            <div class="form-text">Nominal dihitung otomatis dari tagihan &amp; bulan yang dipilih.</div>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label for="tarik_tgl">Tanggal Penarikan</label>
                        <input type="date" class="form-control" id="tarik_tgl" name="tgl" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger" id="tarik_submit">
                        <i class="fas fa-save"></i> Simpan Penarikan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal ubah penarikan -->
<div class="modal fade" id="modalUbahTarik" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="formUbahTarik" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="aksi" value="ubah">
                <input type="hidden" name="id_tabungan" id="ubah_tarik_id">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen mr-2"></i>Ubah Penarikan</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="ubah_tarik_nisn">Siswa</label>
                        <select class="form-control" id="ubah_tarik_nisn" name="nisn" required>
                            <?php foreach ($siswa_list as $s): ?>
                                <option value="<?= htmlspecialchars((string)$s['nisn']) ?>">
                                    <?= htmlspecialchars((string)$s['nama_siswa']) ?> &mdash; <?= htmlspecialchars((string)($s['nama_kelas'] ?? '-')) ?> (<?= htmlspecialchars((string)$s['nisn']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ubah_tarik_nilai">Nominal Penarikan</label>
                        <div class="input-group">
                            <div class="input-group-text">Rp</div>
                            <input type="text" class="form-control tb-rupiah" id="ubah_tarik_nilai" name="tarik" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ubah_tarik_keterangan">Keterangan</label>
                        <textarea class="form-control" id="ubah_tarik_keterangan" name="keterangan_tarik" rows="2"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="ubah_tarik_tgl">Tanggal Penarikan</label>
                        <input type="date" class="form-control" id="ubah_tarik_tgl" name="tgl" required>
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
<form method="POST" id="formHapusTarik" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="aksi" value="hapus">
    <input type="hidden" name="id_tabungan" id="hapus_tarik_id">
</form>

<script>
(function () {
    var saldoUrl = '<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan/api/saldo.php') ?>';
    var tagihanUrl = '<?= htmlspecialchars(app_base_path() . '/bendahara/tabungan/api/tagihan.php') ?>';

    function rupiahAngka(n) {
        return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
    }

    var selectSiswa = document.getElementById('tarik_nisn');
    var daftarTagihan = document.getElementById('tarik_daftar_tagihan');
    var infoTagihan = document.getElementById('tarik_tagihan_info');
    var alertTunggakan = document.getElementById('tarik_tunggakan');
    var inputBayar = document.getElementById('tarik_nilai_bayar');
    var panelRincian = document.getElementById('tarik_rincian');
    var listRincian = document.getElementById('tarik_rincian_list');
    var subRincian = document.getElementById('tarik_rincian_sub');
    var totalRincian = document.getElementById('tarik_rincian_total');
    var tagihanReq = 0;
    var nisnTagihanTermuat = '';
    var tagihanItems = [];
    var pilihan = {};

    function angkaDari(el) {
        return Number(String((el && el.value) || '').replace(/[^0-9]/g, '')) || 0;
    }

    function keyOf(item) {
        return String(item.id_jenis_bayar) + '|' + String(item.tahun_ajaran || '');
    }

    function totalItemTerpilih(item) {
        var p = pilihan[keyOf(item)];
        if (!p) {
            return 0;
        }
        if (item.tipe_bayar === 'Bulanan') {
            var total = 0;
            (item.bulan || []).forEach(function (b) {
                if (p.bulan[b]) {
                    total += Number(item.nominal || 0);
                }
            });
            return total;
        }
        return Number(p.jumlah || 0);
    }

    function hitungTotal() {
        var total = 0;
        tagihanItems.forEach(function (item) {
            total += totalItemTerpilih(item);
        });
        inputBayar.value = rupiahAngka(total);
        if (totalRincian) {
            totalRincian.textContent = rupiahAngka(total);
        }
        return total;
    }

    function togglePilihItem(item) {
        var key = keyOf(item);
        if (pilihan[key]) {
            delete pilihan[key];
        } else {
            var p = { item: item, bulan: {}, jumlah: 0 };
            if (item.tipe_bayar === 'Bulanan') {
                (item.bulan || []).forEach(function (b) {
                    p.bulan[b] = true;
                });
            } else {
                p.jumlah = Number(item.sisa || 0);
            }
            pilihan[key] = p;
        }
        renderDaftarTagihan();
        renderRincian();
        hitungTotal();
    }

    function renderDaftarTagihan() {
        var html = '';
        tagihanItems.forEach(function (item) {
            var lunas = item.is_lunas || item.sisa <= 0;
            var key = keyOf(item);
            var aktif = !!pilihan[key];

            var meta = [];
            meta.push(item.tipe_bayar);
            meta.push(rupiahAngka(item.nominal) + (item.tipe_bayar === 'Bulanan' ? '/bulan' : ''));
            meta.push('T.A ' + item.tahun_ajaran);

            var badge = lunas
                ? '<span class="tb-badge lunas">Lunas</span>'
                : (item.is_tunggakan ? '<span class="tb-badge tunggakan">Tunggakan</span>' : '<span class="tb-badge aktif">Ada tagihan</span>');

            html += '<div class="tb-item-card' + (aktif ? ' aktif' : '') + (lunas ? ' lunas' : '') + '" data-key="' + key + '">';
            html += '<div class="tb-item-main">';
            html += '<span class="tb-item-check' + (aktif ? ' on' : '') + '"><i class="fas fa-check"></i></span>';
            html += '<span class="tb-item-body">';
            html += '<span class="tb-item-nama">' + item.nama_pembayaran + ' ' + badge + '</span>';
            html += '<span class="tb-item-meta">' + meta.join(' &bull; ') + '</span>';
            html += '</span>';
            html += '<span class="tb-item-sisa">' + (lunas ? '<span class="badge badge-success">Lunas</span>' : rupiahAngka(item.sisa)) + '</span>';
            html += '</div>';
            html += '</div>';
        });
        daftarTagihan.innerHTML = html;

        daftarTagihan.querySelectorAll('.tb-item-card').forEach(function (card) {
            card.addEventListener('click', function () {
                var key = card.getAttribute('data-key');
                var item = null;
                for (var i = 0; i < tagihanItems.length; i++) {
                    if (keyOf(tagihanItems[i]) === key) {
                        item = tagihanItems[i];
                        break;
                    }
                }
                if (!item || item.is_lunas || item.sisa <= 0) {
                    return;
                }
                togglePilihItem(item);
            });
        });
    }

    function perbaruiTotalItem(key) {
        var el = listRincian.querySelector('[data-total-key="' + key + '"]');
        var p = pilihan[key];
        if (!el || !p) {
            return;
        }
        el.textContent = rupiahAngka(totalItemTerpilih(p.item));
    }

    function renderRincian() {
        var keys = Object.keys(pilihan);
        if (!keys.length) {
            panelRincian.style.display = 'none';
            listRincian.innerHTML = '';
            return;
        }
        panelRincian.style.display = 'block';

        var html = '';
        var jumlahBulan = 0;

        keys.forEach(function (key) {
            var p = pilihan[key];
            var item = p.item;
            var meta = [];
            meta.push(item.tipe_bayar);
            meta.push('T.A ' + item.tahun_ajaran);

            if (item.tipe_bayar === 'Bulanan') {
                var n = 0;
                (item.bulan || []).forEach(function (b) {
                    if (p.bulan[b]) {
                        n++;
                    }
                });
                jumlahBulan += n;
                meta.push(n + ' bulan dipilih');
            } else {
                meta.push('sisa tagihan ' + rupiahAngka(item.sisa));
                if (item.sisa_cicilan) {
                    meta.push('sisa ' + item.sisa_cicilan + 'x cicilan');
                }
            }

            html += '<input type="hidden" name="item_pilih[]" value="' + key + '">';
            html += '<div class="tb-rincian-item">';
            html += '<div class="tb-rincian-item-head">';
            html += '<span class="tb-rincian-item-nama">' + item.nama_pembayaran + '</span>';
            html += '<span class="tb-rincian-item-total" data-total-key="' + key + '">' + rupiahAngka(totalItemTerpilih(item)) + '</span>';
            html += '</div>';
            html += '<div class="tb-rincian-meta">' + meta.join(' &bull; ') + '</div>';

            if (item.tipe_bayar === 'Bulanan') {
                (item.rincian || []).forEach(function (r) {
                    var on = !!p.bulan[r.bulan];
                    html += '<label class="tb-rincian-row' + (on ? ' on' : '') + '">';
                    html += '<input type="checkbox" class="tb-check tb-bulan-check" data-key="' + key + '" name="item[' + key + '][bulan][]" value="' + r.bulan + '"' + (on ? ' checked' : '') + '>';
                    html += '<span class="tb-rincian-row-nama">' + r.label + '</span>';
                    html += '<span class="tb-rincian-row-nom">' + rupiahAngka(r.sisa) + '</span>';
                    html += '</label>';
                });
                html += '<div class="tb-rincian-aksi">';
                html += '<button type="button" class="tb-link-btn" data-all="1" data-key="' + key + '">Pilih semua</button>';
                html += '<button type="button" class="tb-link-btn" data-all="0" data-key="' + key + '">Hapus semua</button>';
                html += '</div>';
            } else {
                html += '<div class="tb-jumlah-row">';
                html += '<div class="input-group input-group-sm">';
                html += '<div class="input-group-text">Rp</div>';
                html += '<input type="text" class="form-control tb-rupiah tb-jumlah-input" data-key="' + key + '" name="item[' + key + '][jumlah]" value="' + p.jumlah + '" data-max="' + item.sisa + '">';
                html += '</div>';
                html += '<div class="tb-jumlah-aksi">';
                var per = Number(item.per_cicilan || 0);
                var maxCicilan = Number(item.sisa_cicilan || 0);
                if (per > 0 && maxCicilan > 0) {
                    for (var c = 1; c <= Math.min(maxCicilan, 10); c++) {
                        var nilai = Math.min(c * per, Number(item.sisa || 0));
                        html += '<button type="button" class="tb-link-btn" data-cicilan="' + c + '" data-key="' + key + '" data-nilai="' + nilai + '">' + c + 'x cicilan (' + rupiahAngka(nilai) + ')</button>';
                    }
                }
                html += '<button type="button" class="tb-link-btn" data-semua="1" data-key="' + key + '" data-nilai="' + item.sisa + '">Bayar sisa (' + rupiahAngka(item.sisa) + ')</button>';
                html += '</div>';
                html += '<div class="tb-jumlah-info">Boleh dicicil: isi nominal sesuai kemampuan (maks. ' + rupiahAngka(item.sisa) + ')</div>';
                html += '</div>';
            }
            html += '</div>';
        });

        listRincian.innerHTML = html;
        subRincian.textContent = keys.length + ' jenis pembayaran dipilih' + (jumlahBulan ? ' • ' + jumlahBulan + ' bulan' : '');

        listRincian.querySelectorAll('.tb-bulan-check').forEach(function (c) {
            c.addEventListener('change', function () {
                var key = c.getAttribute('data-key');
                var p = pilihan[key];
                if (!p) {
                    return;
                }
                if (c.checked) {
                    p.bulan[c.value] = true;
                } else {
                    delete p.bulan[c.value];
                }
                var row = c.closest('.tb-rincian-row');
                if (row) {
                    row.classList.toggle('on', c.checked);
                }
                perbaruiTotalItem(key);
                hitungTotal();
            });
        });

        listRincian.querySelectorAll('.tb-jumlah-input').forEach(function (inp) {
            var update = function () {
                var key = inp.getAttribute('data-key');
                var p = pilihan[key];
                if (!p) {
                    return;
                }
                p.jumlah = angkaDari(inp);
                perbaruiTotalItem(key);
                hitungTotal();
            };
            inp.addEventListener('input', update);
            inp.addEventListener('change', update);
        });

        listRincian.querySelectorAll('[data-all]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-key');
                var p = pilihan[key];
                if (!p) {
                    return;
                }
                var semua = btn.getAttribute('data-all') === '1';
                (p.item.bulan || []).forEach(function (b) {
                    if (semua) {
                        p.bulan[b] = true;
                    } else {
                        delete p.bulan[b];
                    }
                });
                renderRincian();
                hitungTotal();
            });
        });

        listRincian.querySelectorAll('[data-cicilan], [data-semua]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-key');
                var p = pilihan[key];
                if (!p) {
                    return;
                }
                p.jumlah = Number(btn.getAttribute('data-nilai') || 0);
                renderRincian();
                hitungTotal();
            });
        });
    }

    function muatTagihan(nisn, force) {
        var reqId = ++tagihanReq;
        daftarTagihan.innerHTML = '';
        infoTagihan.textContent = 'Memuat tagihan...';
        alertTunggakan.style.display = 'none';
        inputBayar.value = rupiahAngka(0);

        if (force) {
            tagihanItems = [];
            pilihan = {};
            renderRincian();
        }

        if (!nisn) {
            infoTagihan.textContent = 'Pilih siswa terlebih dahulu untuk memuat tagihan.';
            nisnTagihanTermuat = '';
            return;
        }

        if (!force && nisn === nisnTagihanTermuat && daftarTagihan.childNodes.length) {
            return;
        }
        nisnTagihanTermuat = nisn;

        fetch(tagihanUrl + '?nisn=' + encodeURIComponent(nisn) + '&_=' + new Date().getTime(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function (data) {
                if (reqId !== tagihanReq) {
                    return;
                }
                if (!data || !data.success) {
                    infoTagihan.textContent = (data && data.message) ? data.message : 'Gagal memuat tagihan siswa.';
                    return;
                }

                document.getElementById('tarik_saldo').textContent = rupiahAngka(data.saldo);
                infoTagihan.textContent = 'Tahun ajaran ' + data.tahun_ajaran + ' • ' + data.items.length + ' jenis pembayaran';

                if (data.tunggakan_tahun_ajaran_lama && data.tunggakan_tahun_ajaran_lama.length) {
                    alertTunggakan.style.display = 'block';
                    alertTunggakan.innerHTML = '<b>Prioritas Tunggakan!</b> Siswa masih memiliki tunggakan tahun ajaran: ' +
                        data.tunggakan_tahun_ajaran_lama.join(', ') + '. Lunasi tunggakan tersebut terlebih dahulu (tersedia di daftar tagihan di bawah).';
                }

                tagihanItems = data.items || [];
                pilihan = {};

                if (!tagihanItems.length) {
                    daftarTagihan.innerHTML = '<div class="form-text">Tidak ada jenis pembayaran aktif untuk kelas siswa ini.</div>';
                    renderRincian();
                    hitungTotal();
                    return;
                }

                renderDaftarTagihan();
                renderRincian();
                hitungTotal();
            })
            .catch(function (err) {
                if (reqId !== tagihanReq) {
                    return;
                }
                infoTagihan.textContent = 'Gagal memuat tagihan siswa (' +
                    (err && err.message ? err.message : 'kesalahan jaringan') +
                    '). Silakan coba lagi.';
            });
    }

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
                muatTagihan(this.value, true);
            });
        } else {
            selectSiswa.addEventListener('change', function () {
                muatTagihan(this.value, true);
            });
        }
    });

    /* Ganti tujuan penarikan */
    var radioLainnya = document.getElementById('tujuan_lainnya');
    var radioBayar = document.getElementById('tujuan_pembayaran');
    function toggleTujuan() {
        var bayar = radioBayar && radioBayar.checked;
        document.getElementById('panel_pembayaran').style.display = bayar ? 'block' : 'none';
        document.getElementById('panel_lainnya').style.display = bayar ? 'none' : 'block';
        var inputLainnya = document.getElementById('tarik_nilai');
        var inputKet = document.getElementById('tarik_keterangan');
        if (inputLainnya) inputLainnya.required = !bayar;
        if (inputKet) inputKet.required = !bayar;
        if (bayar && selectSiswa) {
            /* Saat memilih "Pembayaran", tagihan langsung dimuat bila siswa sudah dipilih. */
            muatTagihan(selectSiswa.value);
        }
    }
    if (radioLainnya) radioLainnya.addEventListener('change', toggleTujuan);
    if (radioBayar) radioBayar.addEventListener('change', toggleTujuan);
    toggleTujuan();

    /* Modal tambah penarikan: bersihkan isian & muat tagihan saat modal dibuka */
    document.addEventListener('DOMContentLoaded', function () {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        $('#modalTambahTarik').on('shown.bs.modal', function () {
            var inputNilai = document.getElementById('tarik_nilai');
            if (inputNilai) {
                inputNilai.value = '';
            }
            var inputKet = document.getElementById('tarik_keterangan');
            if (inputKet) {
                inputKet.value = '';
            }
            var inputTgl = document.getElementById('tarik_tgl');
            if (inputTgl) {
                inputTgl.value = '<?= date('Y-m-d') ?>';
            }
            toggleTujuan();
            if (radioBayar && radioBayar.checked && selectSiswa) {
                muatTagihan(selectSiswa.value);
            }
        });
    });

    /* Validasi sebelum kirim */
    var formTarik = document.getElementById('formTarik');
    if (formTarik) {
        formTarik.addEventListener('submit', function (e) {
            var bayar = radioBayar && radioBayar.checked;
            if (bayar) {
                var total = hitungTotal();
                if (total <= 0) {
                    e.preventDefault();
                    if (window.Swal) {
                        Swal.fire({ title: 'Perhatian!', text: 'Pilih minimal satu jenis pembayaran yang memiliki tunggakan.', icon: 'warning' });
                    } else {
                        alert('Pilih minimal satu jenis pembayaran yang memiliki tunggakan.');
                    }
                    return;
                }
                var saldo = Number(String(document.getElementById('tarik_saldo').textContent).replace(/[^0-9]/g, ''));
                if (saldo > 0 && saldo < total) {
                    e.preventDefault();
                    if (window.Swal) {
                        Swal.fire({ title: 'Saldo tidak mencukupi', text: 'Saldo siswa ' + rupiahAngka(saldo) + ' lebih kecil dari nominal pembayaran ' + rupiahAngka(total) + '.', icon: 'error' });
                    } else {
                        alert('Saldo tidak mencukupi.');
                    }
                }
            }
        });
    }

    /* Edit penarikan */
    document.querySelectorAll('[data-tb-edit-tarik]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('ubah_tarik_id').value = btn.getAttribute('data-id');
            document.getElementById('ubah_tarik_nisn').value = btn.getAttribute('data-nisn');
            document.getElementById('ubah_tarik_nilai').value = Number(btn.getAttribute('data-tarik') || 0).toLocaleString('id-ID');
            document.getElementById('ubah_tarik_tgl').value = btn.getAttribute('data-tgl');
            document.getElementById('ubah_tarik_keterangan').value = btn.getAttribute('data-keterangan') || '';
            $('#modalUbahTarik').modal('show');
        });
    });

    /* Hapus penarikan */
    var formHapus = document.getElementById('formHapusTarik');
    document.querySelectorAll('[data-tb-hapus-tarik]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var pesan = btn.getAttribute('data-tb-konfirmasi') || 'Yakin ingin menghapus data ini?';
            var nama = btn.getAttribute('data-nama') || '-';
            var nominal = btn.getAttribute('data-nominal') || '-';
            if (window.Swal) {
                Swal.fire({
                    title: btn.getAttribute('data-tb-judul') || 'Hapus Penarikan?',
                    html: '<div style="text-align:left"><b>' + nama + '</b><br>' + nominal + '</div><br><small>' + pesan + '</small>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Hapus',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#e11d48',
                    reverseButtons: true
                }).then(function (r) {
                    if (r.isConfirmed) {
                        document.getElementById('hapus_tarik_id').value = btn.getAttribute('data-id');
                        formHapus.submit();
                    }
                });
            } else if (confirm(nama + ' - ' + nominal + '\n\n' + pesan)) {
                document.getElementById('hapus_tarik_id').value = btn.getAttribute('data-id');
                formHapus.submit();
            }
        });
    });
})();
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
