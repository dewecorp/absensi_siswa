<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = (int)($_SESSION['user_id'] ?? 0);
$id_tugas = (int)($_GET['id_tugas'] ?? $_GET['id'] ?? 0);

if ($id_tugas <= 0) {
    redirect('tugas.php');
}

$stmt = $pdo->prepare("
    SELECT t.*, m.nama_mapel, g.nama_guru
    FROM tb_tugas t
    LEFT JOIN tb_mata_pelajaran m ON m.id_mapel = t.id_mapel
    LEFT JOIN tb_guru g ON g.id_guru = t.id_guru
    WHERE t.id = ? AND t.status = 'Aktif'
    LIMIT 1
");
$stmt->execute([$id_tugas]);
$tugas = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tugas || !in_array($tugas['jenis_tugas'], ['Kuis', 'CBT'], true)) {
    redirect('tugas.php');
}

$is_upcoming = !empty($tugas['tgl_mulai']) && strtotime($tugas['tgl_mulai']) > time();
$is_expired = !empty($tugas['deadline']) && strtotime($tugas['deadline']) < time();

if ($is_upcoming) {
    $page_title = 'Ujian Belum Dibuka';
    include '../templates/header.php';
    include '../templates/sidebar.php';
    echo '<div class="main-content"><section class="section"><div class="section-body"><div class="card shadow-sm border-warning"><div class="card-body text-center py-5"><div class="mb-3 text-warning" style="font-size: 50px;"><i class="fas fa-hourglass-start"></i></div><h3 class="text-warning font-weight-bold">Ujian Belum Dimulai</h3><p class="text-muted">Kuis / CBT ini baru akan dibuka pada tanggal: <strong>' . date('d/m/Y H:i', strtotime($tugas['tgl_mulai'])) . '</strong>.</p><a href="tugas.php" class="btn btn-primary mt-2"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a></div></div></div></section></div>';
    include '../templates/footer.php';
    exit;
}

$stSoal = $pdo->prepare("SELECT * FROM tb_tugas_soal WHERE id_tugas = ? ORDER BY id ASC");
$stSoal->execute([$id_tugas]);
$soal_list = $stSoal->fetchAll(PDO::FETCH_ASSOC);

$stSub = $pdo->prepare("SELECT * FROM tb_tugas_pengumpulan WHERE id_tugas = ? AND id_siswa = ?");
$stSub->execute([$id_tugas, $id_siswa]);
$submission = $stSub->fetch(PDO::FETCH_ASSOC);

if ($is_expired && empty($submission)) {
    $page_title = 'Waktu Habis';
    include '../templates/header.php';
    include '../templates/sidebar.php';
    echo '<div class="main-content"><section class="section"><div class="section-body"><div class="card shadow-sm border-danger"><div class="card-body text-center py-5"><div class="mb-3 text-danger" style="font-size: 50px;"><i class="fas fa-clock"></i></div><h3 class="text-danger font-weight-bold">Waktu Pengerjaan Telah Berakhir</h3><p class="text-muted">Batas waktu tugas ini sudah terlewati.</p><a href="tugas.php" class="btn btn-primary mt-2"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a></div></div></div></section></div>';
    include '../templates/footer.php';
    exit;
}

$just_saved = false;
$saved_nilai = null;
$error_msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_upcoming && (empty($submission) || !$is_expired)) {
    $jawaban_siswa = $_POST['jawaban'] ?? [];
    $total_skor = 0;
    $max_skor = 0;
    $detail_jawaban = [];

    foreach ($soal_list as $soal) {
        $sId = $soal['id'];
        $bobot = (int)($soal['bobot'] ?? 10);
        $max_skor += $bobot;
        $jwb = $jawaban_siswa[$sId] ?? '';
        if (is_array($jwb)) {
            $jwb = implode(',', $jwb);
        }
        $jwb = trim((string)$jwb);
        $detail_jawaban[$sId] = $jwb;

        if ($soal['tipe_soal'] === 'Pilihan Ganda') {
            if ($jwb !== '' && strcasecmp($jwb, trim((string)$soal['kunci_jawaban'])) === 0) {
                $total_skor += $bobot;
            }
        } elseif ($soal['tipe_soal'] === 'Pilihan Ganda Kompleks') {
            // Kunci jawaban & jawaban siswa format koma, misal "A,C"
            $kunci_arr = array_filter(array_map('strtoupper', array_map('trim', explode(',', (string)$soal['kunci_jawaban']))));
            $jwb_arr   = array_filter(array_map('strtoupper', array_map('trim', explode(',', $jwb))));
            $total_kunci = count($kunci_arr);
            if ($total_kunci > 0 && $jwb !== '') {
                $benar = count(array_intersect($kunci_arr, $jwb_arr));
                $salah = count(array_diff($jwb_arr, $kunci_arr));
                if ($benar === $total_kunci && $salah === 0) {
                    // Semua benar, tidak ada jawaban salah → skor penuh
                    $total_skor += $bobot;
                } elseif ($benar > 0 && $salah === 0) {
                    // Sebagian benar, tidak ada yang salah → setengah skor
                    $total_skor += ($bobot * 0.5);
                } elseif ($benar > 0 && $salah > 0) {
                    // Ada yang benar ada yang salah (1 benar 1 salah) → setengah skor
                    $total_skor += ($bobot * 0.5);
                }
                // Semua salah (benar = 0) → skor 0, tidak ditambah
            }
        } elseif ($soal['tipe_soal'] === 'Essay') {
            $kunci_essay = trim((string)$soal['kunci_jawaban']);
            if ($kunci_essay !== '' && $jwb !== '') {
                similar_text(strtolower($jwb), strtolower($kunci_essay), $percent);
                if ($percent >= 80.0) {
                    $total_skor += $bobot;
                } elseif ($percent >= 50.0) {
                    $total_skor += ($bobot * 0.5);
                }
            }
        }
    }

    $nilai_akhir = $max_skor > 0 ? round(($total_skor / $max_skor) * 100, 2) : 0;

    try {
        $stIns = $pdo->prepare("
            INSERT INTO tb_tugas_pengumpulan (
                id_tugas, id_siswa, catatan_siswa, nilai, tgl_kumpul, status_periksa
            ) VALUES (?, ?, ?, ?, NOW(), 'Sudah Diperiksa')
            ON DUPLICATE KEY UPDATE
                catatan_siswa = VALUES(catatan_siswa),
                nilai = VALUES(nilai),
                tgl_kumpul = NOW(),
                status_periksa = 'Sudah Diperiksa'
        ");
        $stIns->execute([$id_tugas, $id_siswa, json_encode($detail_jawaban), $nilai_akhir]);
        $just_saved = true;
        $saved_nilai = $nilai_akhir;
        $stSub->execute([$id_tugas, $id_siswa]);
        $submission = $stSub->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_msg = 'Gagal menyimpan jawaban: ' . $e->getMessage();
    }
}

$tampilkan_hasil_final = (!empty($submission) && $is_expired);

$saved_answers = [];
if (!empty($submission['catatan_siswa'])) {
    $saved_answers = json_decode($submission['catatan_siswa'], true) ?: [];
}

$total_soal = count($soal_list);
$bobot_json = json_encode(array_column($soal_list, 'bobot'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Kerjakan <?= htmlspecialchars($tugas['jenis_tugas']) ?> | SIMAD</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.2/css/all.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito:400,600,700&display=swap">
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;width:100%;max-width:100%;overflow-x:hidden;background-color:#f4f6f9;font-size:14px;font-weight:400;font-family:"Nunito","Segoe UI",arial;color:#6c757d}
.exam-topbar{position:sticky;top:0;z-index:100;background:#6777ef;color:#fff;padding:10px 14px;display:flex;align-items:center;gap:10px}
.exam-topbar a.back{color:#fff;text-decoration:none;font-weight:600;font-size:12px;letter-spacing:.5px;white-space:nowrap}
.exam-topbar .title{flex:1;min-width:0;font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.exam-wrap{max-width:1080px;margin:0 auto;padding:14px;width:100%}
.exam-info{background-color:#fff;border-radius:3px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#6c757d;box-shadow:0 4px 8px rgba(0,0,0,.03)}
.exam-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:14px;align-items:start}
.exam-card{background-color:#fff;border-radius:3px;border:none;box-shadow:0 4px 8px rgba(0,0,0,.03);overflow:hidden}
.exam-card-head{border-bottom:1px solid #f9f9f9;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:8px;color:#6777ef;font-weight:600}
.exam-card-body{padding:20px 25px}
.exam-card-foot{background:transparent;border:none;padding:12px 20px;display:flex;justify-content:space-between;gap:8px}
.badge{vertical-align:middle;padding:7px 12px;font-weight:600;letter-spacing:.3px;border-radius:30px;font-size:12px}
.badge-primary{background-color:#6777ef}
.badge-success{background-color:#47c363}
.badge-secondary{background-color:#cdd3d8}
.badge-warning{background-color:#ffa426;color:#fff}
.btn{font-weight:600;font-size:12px;line-height:24px;padding:.3rem .8rem;letter-spacing:.5px;border-radius:3px}
.btn:focus,.btn:active{box-shadow:none!important;outline:none}
.btn-lg{padding:.55rem 1.5rem;font-size:12px}
.btn-primary,.btn-primary.disabled{box-shadow:0 2px 6px #acb5f6;background-color:#6777ef;border-color:#6777ef;color:#fff}
.btn-primary:active,.btn-primary:hover,.btn-primary:focus{background-color:#394eea!important;border-color:#394eea!important;color:#fff!important}
.btn-success,.btn-success.disabled{box-shadow:0 2px 6px #81d694;background-color:#47c363;border-color:#47c363;color:#fff}
.btn-success:hover,.btn-success:focus,.btn-success:active{background-color:#3bb557!important;border-color:#3bb557!important;color:#fff!important}
.btn-warning,.btn-warning.disabled{box-shadow:0 2px 6px #ffc473;background-color:#ffa426;border-color:#ffa426;color:#fff}
.btn-warning:hover,.btn-warning:focus,.btn-warning:active{background-color:#ff990d!important;border-color:#ff990d!important;color:#fff!important}
.btn-secondary,.btn-secondary.disabled{box-shadow:0 2px 6px #e1e5e8;background-color:#cdd3d8;border-color:#cdd3d8;color:#fff}
.btn-secondary:hover,.btn-secondary:focus,.btn-secondary:active{background-color:#bfc6cd!important;border-color:#bfc6cd!important;color:#fff!important}
.btn-outline-danger{color:#fc544b;border-color:#fc544b}
.btn-outline-danger:hover,.btn-outline-danger:focus,.btn-outline-danger:active{background-color:#fb160a!important;border-color:#fb160a!important;color:#fff!important}
.btn-outline-secondary:hover,.btn-outline-secondary:focus,.btn-outline-secondary:active{background-color:#cdd3d8!important;border-color:#cdd3d8!important;color:#fff!important}
.nav-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(44px,1fr));gap:8px}
.btn-nav-soal{min-width:44px;min-height:44px;font-weight:600}
.custom-control-label{font-size:14px;line-height:1.5;color:#34395e}
textarea.form-control{font-size:14px}
#startExamOverlay{position:fixed;inset:0;z-index:1000;background:rgba(25,29,33,.92);display:flex;align-items:center;justify-content:center;padding:16px}
#startExamOverlay .panel{background:#fff;border-radius:3px;box-shadow:0 4px 8px rgba(0,0,0,.03);max-width:520px;width:100%;padding:28px 22px;text-align:center}
@media (max-width:900px){
  .exam-grid{grid-template-columns:minmax(0,1fr)}
  .exam-wrap{padding:8px}
  .exam-card-body{padding:16px}
}
</style>
</head>
<body>
<div class="exam-topbar">
  <div class="title"><?= htmlspecialchars($tugas['jenis_tugas']) ?>: <?= htmlspecialchars($tugas['judul']) ?></div>
  <span class="badge badge-warning text-dark font-weight-bold" id="examTimer"><i class="fas fa-clock mr-1"></i> <span id="timerCountdown">--:--:--</span></span>
  <span class="badge badge-light"><?= (int)$total_soal ?> soal</span>
</div>

<div class="exam-wrap">
  <div class="exam-info">
    <strong><?= htmlspecialchars($tugas['nama_mapel'] ?? '-') ?></strong>
    &bull; Deadline: <strong><?= !empty($tugas['deadline']) ? date('d/m/Y H:i', strtotime($tugas['deadline'])) : '-' ?></strong>
  </div>

  <?php if ($tampilkan_hasil_final): ?>
    <div class="exam-card"><div class="exam-card-body text-center py-5">
      <div class="mb-3 text-success" style="font-size:50px"><i class="fas fa-check-circle"></i></div>
      <h3 class="text-success font-weight-bold">Anda Telah Mengerjakan <?= htmlspecialchars($tugas['jenis_tugas']) ?> Ini</h3>
      <p class="text-muted">Tanggal: <?= date('d/m/Y H:i', strtotime($submission['tgl_kumpul'])) ?></p>
      <div class="display-4 font-weight-bold text-primary mb-3">Nilai: <?= (float)$submission['nilai'] ?> / 100</div>
      <a href="tugas.php" class="btn btn-primary"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Daftar Tugas</a>
    </div></div>

  <?php elseif (empty($soal_list)): ?>
    <div class="exam-card"><div class="exam-card-body text-center py-5">
      <p class="text-muted">Belum ada butir soal dari guru.</p>
      <a href="tugas.php" class="btn btn-primary">Kembali</a>
    </div></div>

  <?php else: ?>
    <?php if ($just_saved): ?>
      <div class="exam-card mb-3" id="resultSaved"><div class="exam-card-body text-center py-4">
        <div class="mb-2 text-success" style="font-size:44px"><i class="fas fa-check-circle"></i></div>
        <h4 class="font-weight-bold text-dark">Jawaban Tersimpan!</h4>
        <div class="display-4 font-weight-bold text-primary">Nilai: <?= (float)$saved_nilai ?> / 100</div>
        <p class="text-muted small">Selama waktu masih tersedia, Anda dapat memperbaiki jawaban.</p>
        <div class="d-flex justify-content-center" style="gap:8px;flex-wrap:wrap">
          <a href="tugas.php" class="btn btn-primary font-weight-bold"><i class="fas fa-list mr-1"></i> Ke Daftar Tugas</a>
          <button type="button" id="btnPerbaiki" class="btn btn-warning font-weight-bold"><i class="fas fa-edit mr-1"></i> Perbaiki Jawaban</button>
        </div>
      </div></div>
    <?php endif; ?>

    <?php if (!empty($submission) && !$just_saved): ?>
      <div class="alert alert-info">Jawaban Anda sudah tersimpan sebelumnya. Anda dapat memperbaiki selama waktu masih tersedia.</div>
    <?php endif; ?>

    <div id="startExamOverlay" <?= $just_saved ? 'style="display:none"' : '' ?>>
      <div class="panel">
        <div class="mb-3 text-primary" style="font-size:54px"><i class="fas fa-laptop-code"></i></div>
        <h3 class="font-weight-bold">Konfirmasi Memulai Ujian</h3>
        <p class="text-muted small">Dilarang membuka tab/jendela lain. 3x pelanggaran = ujian dihentikan otomatis.</p>
        <button type="button" id="btnStartExam" class="btn btn-success btn-lg btn-block font-weight-bold"><i class="fas fa-play mr-2"></i> Mulai Mengerjakan</button>
      </div>
    </div>

    <form method="POST" id="formKerjakanKuis" <?= $just_saved ? 'style="display:none"' : '' ?>>
      <div class="exam-grid">
        <div class="exam-card">
          <div class="exam-card-head">
            <strong id="labelNoSoal">Soal No. 1</strong>
            <span class="badge badge-primary" id="badgeBobotSoal">Bobot: 10</span>
          </div>
          <div class="exam-card-body">
            <?php foreach ($soal_list as $idx => $soal): ?>
              <div class="soal-item" id="soalBox_<?= $idx ?>" style="display:<?= $idx === 0 ? 'block' : 'none' ?>">
                <p class="font-weight-bold mb-4" style="font-size:16px;line-height:1.6"><?= nl2br(htmlspecialchars($soal['pertanyaan'])) ?></p>
                <?php if ($soal['tipe_soal'] === 'Pilihan Ganda'): ?>
                  <?php $val_pg = $saved_answers[$soal['id']] ?? ''; ?>
                  <?php foreach (['A' => $soal['pilihan_a'], 'B' => $soal['pilihan_b'], 'C' => $soal['pilihan_c'], 'D' => $soal['pilihan_d'], 'E' => $soal['pilihan_e']] as $k => $opt): ?>
                    <?php if (!empty($opt)): ?>
                      <div class="custom-control custom-radio mb-3">
                        <input type="radio" id="jwb_<?= $soal['id'] ?>_<?= $k ?>" name="jawaban[<?= $soal['id'] ?>]" value="<?= $k ?>" class="custom-control-input soal-input" data-index="<?= $idx ?>" <?= $val_pg === $k ? 'checked' : '' ?>>
                        <label class="custom-control-label" for="jwb_<?= $soal['id'] ?>_<?= $k ?>"><strong><?= $k ?>.</strong> <?= htmlspecialchars($opt) ?></label>
                      </div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php elseif ($soal['tipe_soal'] === 'Pilihan Ganda Kompleks'): ?>
                  <small class="text-info d-block mb-3"><i class="fas fa-info-circle mr-1"></i> Dapat memilih lebih dari 1 jawaban</small>
                  <?php $val_k = explode(',', (string)($saved_answers[$soal['id']] ?? '')); ?>
                  <?php foreach (['A' => $soal['pilihan_a'], 'B' => $soal['pilihan_b'], 'C' => $soal['pilihan_c'], 'D' => $soal['pilihan_d'], 'E' => $soal['pilihan_e']] as $k => $opt): ?>
                    <?php if (!empty($opt)): ?>
                      <div class="custom-control custom-checkbox mb-3">
                        <input type="checkbox" id="jwbk_<?= $soal['id'] ?>_<?= $k ?>" name="jawaban[<?= $soal['id'] ?>][]" value="<?= $k ?>" class="custom-control-input soal-input" data-index="<?= $idx ?>" <?= in_array($k, $val_k, true) ? 'checked' : '' ?>>
                        <label class="custom-control-label" for="jwbk_<?= $soal['id'] ?>_<?= $k ?>"><strong><?= $k ?>.</strong> <?= htmlspecialchars($opt) ?></label>
                      </div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  <textarea name="jawaban[<?= $soal['id'] ?>]" class="form-control soal-input" rows="5" data-index="<?= $idx ?>" placeholder="Tulis jawaban Anda..."><?= htmlspecialchars($saved_answers[$soal['id']] ?? '') ?></textarea>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="exam-card-foot">
            <button type="button" id="btnPrev" class="btn btn-secondary font-weight-bold" disabled><i class="fas fa-arrow-left mr-1"></i> Sebelumnya</button>
            <button type="button" id="btnNext" class="btn btn-primary font-weight-bold">Selanjutnya <i class="fas fa-arrow-right ml-1"></i></button>
          </div>
        </div>

        <div class="exam-card">
          <div class="exam-card-head"><strong><i class="fas fa-th mr-2"></i>Navigasi Soal</strong></div>
          <div class="exam-card-body">
            <div class="nav-grid">
              <?php foreach ($soal_list as $idx => $soal): ?>
                <button type="button" class="btn btn-outline-secondary btn-nav-soal" data-index="<?= $idx ?>" id="navBtn_<?= $idx ?>"><?= $idx + 1 ?></button>
              <?php endforeach; ?>
            </div>
            <hr>
            <button type="button" id="btnSubmitKuis" class="btn btn-success btn-lg btn-block font-weight-bold"><i class="fas fa-paper-plane mr-2"></i> Selesai &amp; Serahkan</button>
            <button type="button" id="btnKeluarUjian" class="btn btn-outline-danger btn-block btn-sm font-weight-bold mt-2"><i class="fas fa-sign-out-alt mr-1"></i> Keluar / Batal</button>
          </div>
        </div>
      </div>
    </form>
  <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function(){
  var TOTAL = <?= (int)$total_soal ?>;
  var BOBOT = <?= $bobot_json ?: '[]' ?>;
  var JUST_SAVED = <?= $just_saved ? 'true' : 'false' ?>;
  var SAVED_NILAI = <?= json_encode($saved_nilai) ?>;
  var current = 0;

  function showSoal(i){
    if(i < 0 || i >= TOTAL) return;
    current = i;
    document.querySelectorAll('.soal-item').forEach(function(el){ el.style.display = 'none'; });
    var box = document.getElementById('soalBox_' + i);
    if(box) box.style.display = 'block';
    document.getElementById('labelNoSoal').textContent = 'Soal No. ' + (i + 1);
    document.getElementById('badgeBobotSoal').textContent = 'Bobot: ' + (BOBOT[i] || 10);
    document.getElementById('btnPrev').disabled = (i === 0);
    document.getElementById('btnNext').disabled = (i === TOTAL - 1);
    document.querySelectorAll('.btn-nav-soal').forEach(function(b){ b.classList.remove('btn-primary'); });
    var nav = document.getElementById('navBtn_' + i);
    if(nav) nav.classList.add('btn-primary');
  }

  function markAnswered(idx){
    var box = document.getElementById('soalBox_' + idx);
    if(!box) return;
    var answered = false;
    box.querySelectorAll('input,textarea').forEach(function(inp){
      if(inp.type === 'checkbox' || inp.type === 'radio'){ if(inp.checked) answered = true; }
      else if(inp.value.trim() !== '') answered = true;
    });
    var nav = document.getElementById('navBtn_' + idx);
    if(!nav) return;
    if(answered){ nav.classList.remove('btn-outline-secondary'); nav.classList.add('btn-success'); }
    else { nav.classList.remove('btn-success'); nav.classList.add('btn-outline-secondary'); }
  }

  document.querySelectorAll('.btn-nav-soal').forEach(function(b){
    b.addEventListener('click', function(){ showSoal(parseInt(b.getAttribute('data-index'), 10)); });
  });
  document.getElementById('btnPrev').addEventListener('click', function(){ showSoal(current - 1); });
  document.getElementById('btnNext').addEventListener('click', function(){ showSoal(current + 1); });
  document.querySelectorAll('.soal-input').forEach(function(inp){
    inp.addEventListener('change', function(){ markAnswered(parseInt(inp.getAttribute('data-index'), 10)); });
    inp.addEventListener('input', function(){ markAnswered(parseInt(inp.getAttribute('data-index'), 10)); });
  });

  var startBtn = document.getElementById('btnStartExam');
  if(startBtn){
    startBtn.addEventListener('click', function(){
      document.getElementById('startExamOverlay').style.display = 'none';
    });
  }

  var perbaiki = document.getElementById('btnPerbaiki');
  if(perbaiki){
    perbaiki.addEventListener('click', function(){
      document.getElementById('resultSaved').style.display = 'none';
      document.getElementById('formKerjakanKuis').style.display = 'block';
    });
  }

  var keluar = document.getElementById('btnKeluarUjian');
  if(keluar){
    keluar.addEventListener('click', function(){
      Swal.fire({
        title: 'Keluar Ujian?',
        text: 'Jawaban belum tersimpan jika keluar sekarang.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Ya, Keluar',
        cancelButtonText: 'Lanjutkan'
      }).then(function(r){ if(r.isConfirmed) window.location.href = 'tugas.php'; });
    });
  }

  var submitBtn = document.getElementById('btnSubmitKuis');
  if(submitBtn){
    submitBtn.addEventListener('click', function(){
      var kosong = 0;
      for(var i = 0; i < TOTAL; i++){
        var box = document.getElementById('soalBox_' + i);
        var ok = false;
        box.querySelectorAll('input,textarea').forEach(function(inp){
          if(inp.type === 'checkbox' || inp.type === 'radio'){ if(inp.checked) ok = true; }
          else if(inp.value.trim() !== '') ok = true;
        });
        if(!ok) kosong++;
      }
      Swal.fire({
        title: 'Serahkan Jawaban?',
        text: kosong > 0 ? ('Masih ada ' + kosong + ' soal belum dijawab. Tetap serahkan?') : 'Pastikan semua jawaban benar. Tidak bisa diubah setelah waktu habis.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'Ya, Serahkan!',
        cancelButtonText: 'Periksa Lagi'
      }).then(function(r){ if(r.isConfirmed) document.getElementById('formKerjakanKuis').submit(); });
    });
  }

  var violation = 0;
  document.addEventListener('visibilitychange', function(){
    var form = document.getElementById('formKerjakanKuis');
    if(!form || form.style.display === 'none') return;
    if(!document.hidden) return;
    violation++;
    if(violation >= 3){
      form.innerHTML = '<div class="alert alert-danger text-center font-weight-bold p-4 m-3">Ujian dihentikan: terdeteksi pindah tab 3x. Nilai tidak disimpan.</div>';
      Swal.fire({title: 'Ujian Dihentikan!', text: 'Terdeteksi pindah tab 3x. Nilai tidak disimpan.', icon: 'error', allowOutsideClick: false}).then(function(){ window.location.href = 'tugas.php'; });
    } else {
      Swal.fire({title: 'Peringatan (' + violation + '/3)', text: 'Jangan buka tab lain! ' + (3 - violation) + 'x lagi ujian dihentikan.', icon: 'warning'});
    }
  });

  // Timer hitung mundur otomatis
  var durasiMenit = <?= (int)($tugas['durasi_menit'] ?? 90) ?>;
  var totalDetik = durasiMenit * 60;
  var timerKey = 'exam_timer_' + <?= (int)$id_tugas ?> + '_' + <?= (int)$id_siswa ?>;
  var savedSecs = sessionStorage.getItem(timerKey);
  if(savedSecs !== null) {
      totalDetik = parseInt(savedSecs, 10);
  }

  function updateTimer() {
      if(totalDetik <= 0) {
          document.getElementById('timerCountdown').textContent = '00:00:00';
          Swal.fire({
              title: 'Waktu Ujian Habis!',
              text: 'Waktu pengerjaan telah habis. Jawaban Anda akan diserahkan secara otomatis.',
              icon: 'warning',
              allowOutsideClick: false,
              allowEscapeKey: false,
              confirmButtonText: 'OK'
          }).then(function(){
              sessionStorage.removeItem(timerKey);
              document.getElementById('formKerjakanKuis').submit();
          });
          return;
      }
      var h = Math.floor(totalDetik / 3600);
      var m = Math.floor((totalDetik % 3600) / 60);
      var s = totalDetik % 60;
      var timeStr = (h < 10 ? '0' + h : h) + ':' + (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
      document.getElementById('timerCountdown').textContent = timeStr;
      sessionStorage.setItem(timerKey, totalDetik);
      totalDetik--;
  }

  var formEl = document.getElementById('formKerjakanKuis');
  if(formEl && formEl.style.display !== 'none') {
      updateTimer();
      setInterval(updateTimer, 1000);
  }

  document.querySelectorAll('.soal-input').forEach(function(inp){
    markAnswered(parseInt(inp.getAttribute('data-index'), 10));
  });

  if(JUST_SAVED && SAVED_NILAI !== null){
    Swal.fire({
      title: 'Nilai Anda: ' + SAVED_NILAI,
      text: 'Jawaban tersimpan. Ke daftar tugas atau perbaiki selama waktu tersedia.',
      icon: 'success',
      showDenyButton: true,
      confirmButtonText: 'Ke Daftar Tugas',
      denyButtonText: 'Perbaiki Jawaban'
    }).then(function(r){
      if(r.isConfirmed){ window.location.href = 'tugas.php'; }
      else if(r.isDenied){
        var rs = document.getElementById('resultSaved');
        if(rs) rs.style.display = 'none';
        var f = document.getElementById('formKerjakanKuis');
        if(f) f.style.display = 'block';
      }
    });
  }
  <?php if ($error_msg): ?>
  Swal.fire({title: 'Gagal', text: <?= json_encode($error_msg) ?>, icon: 'error'});
  <?php endif; ?>
})();
</script>
</body>
</html>
