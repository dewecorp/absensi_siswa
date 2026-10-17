<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/program_kerja_master.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin','kepala_madrasah'])) redirect('../login.php');

$user_level = getUserLevel();
$is_editable = in_array($user_level, ['admin','kepala_madrasah']);
$page_title = 'Laporan Program Kerja';

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y').'/'.(date('Y')+1);
ensureTbJabatanMaster($pdo);

$upload_dir = __DIR__ . '/../assets/dokumen/program_kerja/';
if(!is_dir($upload_dir)) @mkdir($upload_dir,0755,true);

function handleUploadProker($field){
 global $upload_dir;
 if(!isset($_FILES[$field])||$_FILES[$field]['error']!=0) return null;
 $allowed=['pdf','jpg','jpeg','png','doc','docx','xls','xlsx'];
 $ext=strtolower(pathinfo($_FILES[$field]['name'],PATHINFO_EXTENSION));
 if(!in_array($ext,$allowed)) return ['error'=>'Format tidak didukung (pdf/jpg/png/doc/xls)'];
 if($_FILES[$field]['size']>5*1024*1024) return ['error'=>'Ukuran maksimal 5MB'];
 $fn='proker_'.time().'_'.uniqid().'.'.$ext;
 if(!move_uploaded_file($_FILES[$field]['tmp_name'],$upload_dir.$fn)) return ['error'=>'Gagal upload'];
 return ['file'=>$fn];
}

prokerEnsureMaster($pdo);
$komponen_list=prokerGetKomponenList($pdo);
$status_opts=['belum_terlaksana'=>'Belum Terlaksana','proses'=>'Proses','terlaksana'=>'Terlaksana'];
$status_badge=['belum_terlaksana'=>'badge-secondary','proses'=>'badge-warning','terlaksana'=>'badge-success'];
$status_icon=['belum_terlaksana'=>'fa-clock','proses'=>'fa-sync fa-spin','terlaksana'=>'fa-check-circle'];
try {
 $pdo->exec("CREATE TABLE IF NOT EXISTS tb_program_kerja (
  id INT PRIMARY KEY AUTO_INCREMENT,
  komponen TINYINT NOT NULL,
  program VARCHAR(255) NOT NULL,
  kegiatan TEXT NOT NULL,
  tujuan TEXT,
  indikator TEXT,
  target VARCHAR(255),
  waktu_mulai DATE NULL,
  waktu_selesai DATE NULL,
  penanggung_jawab VARCHAR(255),
  anggaran DECIMAL(15,2) DEFAULT 0,
  sumber_dana VARCHAR(255),
  bukti VARCHAR(255) NULL,
  evaluasi TEXT,
  tindak_lanjut TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'belum_terlaksana',
  tahun_ajaran VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX(komponen), INDEX(status)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $cols=$pdo->query("SHOW COLUMNS FROM tb_program_kerja")->fetchAll(PDO::FETCH_COLUMN,0);
 if(!in_array('status',$cols)) $pdo->exec("ALTER TABLE tb_program_kerja ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'belum_terlaksana' AFTER tindak_lanjut");
 $pdo->exec("UPDATE tb_program_kerja SET status='belum_terlaksana' WHERE status IS NULL OR status=''");
} catch(PDOException $e){ error_log($e->getMessage()); }

$message='';
if(isset($_SESSION['flash_message'])){ $message=$_SESSION['flash_message']; unset($_SESSION['flash_message']); }

if($_SERVER['REQUEST_METHOD']=='POST' && $is_editable){
 $redirect=$_SERVER['PHP_SELF'].(!empty($_GET['session_type'])?'?session_type='.urlencode($_GET['session_type']):'');
  if(isset($_POST['update_status'])){
   $id=(int)($_POST['id']??0); $st=trim($_POST['status']??'');
   if(!isset($status_opts[$st])){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Status tidak valid!']; header("Location: $redirect"); exit; }
   $ok=$pdo->prepare("UPDATE tb_program_kerja SET status=? WHERE id=?")->execute([$st,$id]);
   if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){ header('Content-Type: application/json'); echo json_encode(['success'=>(bool)$ok]); exit; }
   $_SESSION['flash_message']=$ok?['type'=>'success','text'=>'Status diupdate!']:['type'=>'danger','text'=>'Gagal update status!'];
   if($ok) logActivity($pdo,$_SESSION['username']??'system','Update Status Program Kerja',"ID $id - $st");
   header("Location: $redirect"); exit;
  } elseif(isset($_POST['update_laporan'])){
   $id=(int)($_POST['id']??0);
   if($id<=0){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Data tidak valid!']; header("Location: $redirect"); exit; }
   $cur=$pdo->prepare("SELECT bukti FROM tb_program_kerja WHERE id=?"); $cur->execute([$id]); $old=$cur->fetchColumn();
   $bukti=$old;
   if(isset($_FILES['bukti'])&&$_FILES['bukti']['error']==0){
    $up=handleUploadProker('bukti');
    if(isset($up['error'])){ $_SESSION['flash_message']=['type'=>'danger','text'=>$up['error']]; header("Location: $redirect"); exit; }
    if($old && file_exists($upload_dir.$old)) @unlink($upload_dir.$old);
    $bukti=$up['file'];
   }
   $ok=$pdo->prepare("UPDATE tb_program_kerja SET bukti=?, evaluasi=?, tindak_lanjut=? WHERE id=?")->execute([$bukti,trim($_POST['evaluasi']??''),trim($_POST['tindak_lanjut']??''),$id]);
   $_SESSION['flash_message']=$ok?['type'=>'success','text'=>'Laporan diupdate!']:['type'=>'danger','text'=>'Gagal update laporan!'];
   if($ok) logActivity($pdo,$_SESSION['username']??'system','Update Laporan Program Kerja',"ID $id");
   header("Location: $redirect"); exit;
  }
}

$filter_komponen=isset($_GET['komponen'])?(int)$_GET['komponen']:0;
$filter_status=trim($_GET['status']??''); if(!isset($status_opts[$filter_status])) $filter_status='';
$where=[]; $params=[];
if($filter_komponen>=1){ $where[]="komponen=?"; $params[]=$filter_komponen; }
if($filter_status!==''){ $where[]="status=?"; $params[]=$filter_status; }
if($where){
 $stmt=$pdo->prepare("SELECT * FROM tb_program_kerja WHERE ".implode(' AND ',$where)." ORDER BY komponen ASC, id ASC");
 $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
 $rows=$pdo->query("SELECT * FROM tb_program_kerja ORDER BY komponen ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
}
$cnt_st=$pdo->query("SELECT status,COUNT(*) c FROM tb_program_kerja GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$css_libs=['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs=['https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js','https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js'];
include '../templates/header.php';
include '../templates/sidebar.php';
?>
<style>
/* Styling Kartu Laporan Program Kerja */
.proker-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 16px;
  margin-top: 14px;
}
@media (max-width: 575.98px) {
  .proker-card-grid {
    grid-template-columns: 1fr;
    gap: 12px;
  }
}
.proker-report-card {
  background: #ffffff;
  border: 1px solid #e9edf5;
  border-radius: 16px;
  box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  position: relative;
  transition: all .2s ease;
  border-left: 5px solid var(--card-color, #6777ef);
}
.proker-report-card:hover {
  box-shadow: 0 10px 28px rgba(15, 23, 42, 0.12);
  transform: translateY(-2px);
}
.proker-report-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 10px;
}
.proker-komponen-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 8px;
  background: #eef2ff;
  color: #4338ca;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.proker-status-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
  user-select: none;
}
.proker-card-title {
  font-size: 15.5px;
  font-weight: 800;
  color: #0f172a;
  line-height: 1.35;
  margin-bottom: 6px;
}
.proker-card-kegiatan {
  font-size: 12.5px;
  color: #475569;
  line-height: 1.45;
  background: #f8fafc;
  padding: 8px 12px;
  border-radius: 10px;
  margin-bottom: 12px;
  border: 1px solid #f1f5f9;
}
.proker-meta-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 12px;
}
@media (max-width: 480px) {
  .proker-meta-grid {
    grid-template-columns: 1fr;
  }
}
.proker-meta-item {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 11.5px;
  color: #64748b;
}
.proker-meta-item i {
  width: 14px;
  text-align: center;
  flex-shrink: 0;
}
.proker-meta-item strong {
  color: #1e293b;
}
.proker-report-section {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 14px;
}
.proker-eval-box {
  background: #fffbeb;
  border: 1px solid #fef3c7;
  border-left: 3.5px solid #f59e0b;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 11.5px;
}
.proker-tindak-box {
  background: #f0fdf4;
  border: 1px solid #dcfce7;
  border-left: 3.5px solid #10b981;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 11.5px;
}
.proker-box-label {
  font-size: 10.5px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .4px;
  margin-bottom: 3px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.proker-eval-box .proker-box-label { color: #b45309; }
.proker-tindak-box .proker-box-label { color: #047857; }
.proker-box-content {
  color: #334155;
  line-height: 1.4;
  margin: 0;
}
.proker-bukti-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #f1f5f9;
  padding: 6px 10px;
  border-radius: 8px;
  font-size: 11.5px;
  margin-bottom: 12px;
}
.proker-card-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: auto;
  padding-top: 10px;
  border-top: 1px solid #f1f5f9;
}
.proker-card-actions .btn {
  font-size: 11.5px;
  font-weight: 700;
  border-radius: 8px;
  padding: 6px 10px;
}
.btn-toggle-view.active {
  background-color: #6777ef !important;
  color: #ffffff !important;
  border-color: #6777ef !important;
}

#table-laporan th{white-space:nowrap;font-size:.78rem}
#table-laporan td{font-size:.8rem;vertical-align:top}
#table-laporan th:last-child,#table-laporan td:last-child{white-space:nowrap;text-align:center}
#table-laporan td:last-child .btn{margin:1px 2px}
.bukti-link{max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block}
.swal2-select{border:1px solid #e3e6f0 !important;border-radius:.5rem !important;box-shadow:0 .15rem 1.75rem 0 rgba(58,59,69,.15) !important;padding:.5rem .75rem !important;font-size:.9rem;color:#5a5c69;background-color:#fff}
.swal2-select:focus{border-color:#bac8f3 !important;outline:0;box-shadow:0 0 0 .2rem rgba(78,115,223,.25) !important}
</style>
<div class="main-content">
<section class="section">
<div class="section-header">
<h1>Laporan Program Kerja <small style="font-size:55%;font-weight:700;margin-left:8px;vertical-align:middle;color:#5f6fb4;background:#eef1ff;border:1px solid #d6dcff;border-radius:999px;padding:4px 10px;">TA: <?=htmlspecialchars($tahun_ajaran)?></small></h1>
<div class="section-header-breadcrumb"><div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div><div class="breadcrumb-item">Program Kerja</div><div class="breadcrumb-item">Laporan Program Kerja</div></div>
</div>
<div class="section-body">
<div class="card mb-3 shadow-sm border-0">
<div class="card-body p-3">
<div class="row align-items-center">
  <div class="col-md-3 mb-2 mb-md-0">
    <label class="font-weight-bold small text-muted mb-1">Filter Komponen</label>
    <select id="filter-komponen" class="form-control form-control-sm">
      <option value="0">Semua Komponen (<?=count($komponen_list)?>)</option>
      <?php foreach($komponen_list as $k=>$v):?>
      <option value="<?=$k?>" <?=$filter_komponen==$k?'selected':''?>><?=$k?>. <?=htmlspecialchars($v['nama'])?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div class="col-md-3 mb-2 mb-md-0">
    <label class="font-weight-bold small text-muted mb-1">Filter Status</label>
    <select id="filter-status" class="form-control form-control-sm">
      <option value="">Semua Status</option>
      <?php foreach($status_opts as $sv=>$sl):?>
      <option value="<?=$sv?>" <?=$filter_status===$sv?'selected':''?>><?=htmlspecialchars($sl)?></option>
      <?php endforeach;?>
    </select>
  </div>
  <div class="col-md-3 mb-2 mb-md-0">
    <label class="font-weight-bold small text-muted mb-1">Cari Laporan</label>
    <div class="input-group input-group-sm">
      <div class="input-group-prepend"><span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span></div>
      <input type="text" id="search-card-proker" class="form-control" placeholder="Cari program/kegiatan...">
    </div>
  </div>
  <div class="col-md-3 text-md-right mt-2 mt-md-0">
    <label class="font-weight-bold small text-muted mb-1 d-none d-md-block">&nbsp;</label>
    <div class="d-flex align-items-center justify-content-md-end flex-wrap" style="gap: 5px;">
      <div class="btn-group btn-group-sm mr-1">
        <button type="button" class="btn btn-outline-primary btn-toggle-view active" id="btn-show-cards" title="Tampilan Kartu"><i class="fas fa-th-large mr-1"></i>Kartu</button>
        <button type="button" class="btn btn-outline-primary btn-toggle-view" id="btn-show-table" title="Tampilan Tabel"><i class="fas fa-table mr-1"></i>Tabel</button>
      </div>
      <?php $q=http_build_query(array_filter(['komponen'=>$filter_komponen?:null,'status'=>$filter_status?:null])); $qs=$q?'?'.$q:''; ?>
      <a href="export_program_kerja_excel.php<?=$qs?>" class="btn btn-success btn-sm" title="Export Excel"><i class="fas fa-file-excel"></i></a>
      <a href="cetak_program_kerja.php<?=$qs?>" target="_blank" class="btn btn-danger btn-sm" title="Cetak PDF"><i class="fas fa-file-pdf"></i></a>
    </div>
  </div>
</div>

<div class="d-flex align-items-center flex-wrap mt-3 pt-2 border-top" style="gap: 6px;">
  <span class="small font-weight-bold text-muted mr-1">Status:</span>
  <?php foreach($status_opts as $sv=>$sl): $c=(int)($cnt_st[$sv]??0); $bc=$status_badge[$sv]; $ic=$status_icon[$sv];?>
  <a href="javascript:void(0)" class="badge <?=$bc?> btn-filter-pill-status px-2.5 py-1.5 font-weight-bold" data-status="<?=$sv?>" style="font-size:11.5px; border-radius:999px; text-decoration:none;">
    <i class="fas <?=$ic?> mr-1"></i> <?=htmlspecialchars($sl)?>: <?=$c?>
  </a>
  <?php endforeach;?>
  <span class="badge badge-light border text-muted ml-auto font-weight-bold" style="font-size:11px; border-radius:999px;">
    Total: <span id="count-visible-cards"><?=count($rows)?></span> / <?=count($rows)?> data
  </span>
</div>
</div>
</div>

<!-- ============================================================
     TAMPILAN KARTU UTAMA (CARD VIEW)
     ============================================================ -->
<div id="proker-card-view">
<?php if(empty($rows)):?>
  <div class="card p-5 text-center shadow-sm border-0" style="border-radius:18px;">
    <div class="mb-3 text-muted"><i class="far fa-clipboard fa-4x"></i></div>
    <h5 class="font-weight-bold text-dark mb-1">Belum Ada Program Kerja</h5>
    <p class="text-muted small">Tidak ditemukan data program kerja pada filter yang dipilih.</p>
  </div>
<?php else:?>
  <?php 
  $pk_palette=['#6777ef','#3abaf4','#47c363','#ffa426','#9467ef','#20c997','#fc544b'];
  ?>
  <div class="proker-card-grid" id="prokerCardContainer">
  <?php foreach($rows as $r): 
    $kom=$komponen_list[(int)$r['komponen']]??['nama'=>'-','ruang'=>''];
    $card_color=$pk_palette[((int)$r['komponen'])%count($pk_palette)];
    $sv=$r['status']??'belum_terlaksana'; 
    if(!isset($status_opts[$sv])) $sv='belum_terlaksana';
    $bc=$status_badge[$sv]; 
    $ic=$status_icon[$sv]; 
    $sl=$status_opts[$sv];
    $wm=!empty($r['waktu_mulai'])?formatDateDMY($r['waktu_mulai']):(!empty($r['waktu'])?htmlspecialchars($r['waktu']):'-');
    $ws=!empty($r['waktu_selesai'])?formatDateDMY($r['waktu_selesai']):'-';
    $anggaran_str = $r['anggaran']>0 ? 'Rp '.number_format($r['anggaran'],0,',','.') : '-';
    $search_str = strtolower(implode(' ', [
      $r['komponen'], $kom['nama'], $r['program'], $r['kegiatan'], $r['tujuan']??'', $r['penanggung_jawab']??'', $sl
    ]));
  ?>
    <div class="proker-report-card" style="--card-color: <?=$card_color?>;" data-status="<?=$sv?>" data-komponen="<?=$r['komponen']?>" data-search="<?=htmlspecialchars($search_str, ENT_QUOTES, 'UTF-8')?>">
      <!-- Top Row: Komponen & Status -->
      <div class="proker-report-top">
        <span class="proker-komponen-badge" title="<?=htmlspecialchars($kom['ruang']??'')?>">
          <i class="fas fa-layer-group"></i> <?=$r['komponen']?>. <?=htmlspecialchars($kom['nama'])?>
        </span>
        <span class="badge <?=$bc?> proker-status-badge btn-status-cycle" data-id="<?=$r['id']?>" data-status="<?=$sv?>" title="Klik untuk ubah status secara cepat">
          <i class="fas <?=$ic?>"></i> <?=htmlspecialchars($sl)?>
        </span>
      </div>

      <!-- Judul Program -->
      <div class="proker-card-title"><?=htmlspecialchars($r['program'])?></div>

      <!-- Uraian Kegiatan -->
      <div class="proker-card-kegiatan">
        <i class="fas fa-tasks text-muted mr-1"></i> <?=nl2br(htmlspecialchars($r['kegiatan']))?>
      </div>

      <!-- Meta Info Grid -->
      <div class="proker-meta-grid">
        <div class="proker-meta-item" title="Periode Pelaksanaan">
          <i class="far fa-calendar-alt text-primary"></i>
          <span><strong><?=$wm?></strong><?= ($ws!=='-') ? ' s/d '.$ws : '' ?></span>
        </div>
        <div class="proker-meta-item" title="Penanggung Jawab">
          <i class="fas fa-user-tie text-info"></i>
          <span>PJ: <strong><?=htmlspecialchars($r['penanggung_jawab']??'-')?></strong></span>
        </div>
        <div class="proker-meta-item" title="Target Capaian">
          <i class="fas fa-bullseye text-warning"></i>
          <span>Target: <strong><?=htmlspecialchars($r['target']??'-')?></strong></span>
        </div>
        <div class="proker-meta-item" title="Anggaran & Sumber Dana">
          <i class="fas fa-coins text-success"></i>
          <span><strong><?=$anggaran_str?></strong> <?=!empty($r['sumber_dana'])?'('.htmlspecialchars($r['sumber_dana']).')':''?></span>
        </div>
      </div>

      <!-- Bukti / Dokumen Fisik -->
      <div class="proker-bukti-strip">
        <span class="text-muted"><i class="fas fa-paperclip mr-1"></i>Dokumen Bukti:</span>
        <?php if(!empty($r['bukti'])): ?>
          <a href="../assets/dokumen/program_kerja/<?=htmlspecialchars($r['bukti'])?>" target="_blank" class="bukti-link font-weight-bold text-primary" title="Buka Dokumen <?=htmlspecialchars($r['bukti'])?>">
            <i class="fas fa-file-download mr-1"></i><?=htmlspecialchars($r['bukti'])?>
          </a>
        <?php else: ?>
          <span class="text-muted font-italic small">Belum ada bukti</span>
        <?php endif;?>
      </div>

      <!-- Evaluasi & Tindak Lanjut -->
      <div class="proker-report-section">
        <div class="proker-eval-box">
          <div class="proker-box-label"><i class="fas fa-clipboard-check"></i> Evaluasi Pelaksanaan</div>
          <p class="proker-box-content"><?=!empty($r['evaluasi']) ? nl2br(htmlspecialchars($r['evaluasi'])) : '<span class="text-muted font-italic">Belum diisi</span>'?></p>
        </div>
        <div class="proker-tindak-box">
          <div class="proker-box-label"><i class="fas fa-arrow-alt-circle-right"></i> Tindak Lanjut & Rekomendasi</div>
          <p class="proker-box-content"><?=!empty($r['tindak_lanjut']) ? nl2br(htmlspecialchars($r['tindak_lanjut'])) : '<span class="text-muted font-italic">Belum diisi</span>'?></p>
        </div>
      </div>

      <!-- Card Action Buttons -->
      <?php if($is_editable):?>
      <div class="proker-card-actions">
        <button type="button" class="btn btn-outline-info btn-status-cycle" data-id="<?=$r['id']?>" data-status="<?=$sv?>" title="Siklus Status Cepat">
          <i class="fas <?=$ic?> mr-1"></i>Status
        </button>
        <button type="button" class="btn btn-outline-secondary btn-status-pick" data-id="<?=$r['id']?>" data-status="<?=$sv?>" title="Pilih Status Langsung">
          <i class="fas fa-list"></i>
        </button>
        <button type="button" class="btn btn-warning btn-edit-laporan ml-auto" data-id="<?=$r['id']?>" data-bukti="<?=htmlspecialchars($r['bukti']??'',ENT_QUOTES,'UTF-8')?>" data-evaluasi="<?=htmlspecialchars($r['evaluasi']??'',ENT_QUOTES,'UTF-8')?>" data-tindak="<?=htmlspecialchars($r['tindak_lanjut']??'',ENT_QUOTES,'UTF-8')?>" data-info="<?=$r['komponen']?>. <?=htmlspecialchars($kom['nama'],ENT_QUOTES,'UTF-8')?> - <?=htmlspecialchars($r['program'],ENT_QUOTES,'UTF-8')?>">
          <i class="fas fa-edit mr-1"></i> Edit Laporan
        </button>
      </div>
      <?php endif;?>
    </div>
  <?php endforeach;?>
  </div>
<?php endif;?>
</div>

<!-- ============================================================
     TAMPILAN TABEL CADANGAN (TABLE VIEW - DEFAULT HIDDEN)
     ============================================================ -->
<div id="proker-table-view" style="display: none;">
<div class="card shadow-sm border-0">
<div class="card-header bg-white"><h4>Tabel Laporan Program Kerja</h4></div>
<div class="card-body">
<div class="table-responsive">
<table class="table table-striped table-bordered" id="table-laporan" style="width:100%">
<thead>
<tr>
<th class="text-center" style="min-width:40px">No</th>
<th style="min-width:140px">Komponen</th>
<th style="min-width:160px">Program</th>
<th style="min-width:160px">Kegiatan</th>
<th style="min-width:140px">Tujuan</th>
<th style="min-width:140px">Indikator Keberhasilan</th>
<th style="min-width:100px">Target</th>
<th style="min-width:110px">Waktu Mulai</th>
<th style="min-width:110px">Waktu Selesai</th>
<th style="min-width:130px">Penanggung Jawab</th>
<th style="min-width:110px">Anggaran</th>
<th style="min-width:110px">Sumber Dana</th>
<th style="min-width:120px">Bukti / Dokumen</th>
<th style="min-width:140px">Evaluasi</th>
<th style="min-width:140px">Tindak Lanjut</th>
<th class="text-center" style="min-width:150px">Status</th>
<?php if($is_editable):?><th class="text-center" style="min-width:160px">Aksi</th><?php endif;?>
</tr>
</thead>
<tbody>
<?php $no=1; foreach($rows as $r): $kom=$komponen_list[(int)$r['komponen']]??['nama'=>'-','ruang'=>''];?>
<tr>
<td class="text-center"><?=$no++?></td>
<td><span class="badge badge-primary"><?=$r['komponen']?>. <?=htmlspecialchars($kom['nama'])?></span><br><small class="text-muted"><?=htmlspecialchars($kom['ruang'])?></small></td>
<td><?=htmlspecialchars($r['program'])?></td>
<td><?=nl2br(htmlspecialchars($r['kegiatan']))?></td>
<td><?=nl2br(htmlspecialchars($r['tujuan']??'-'))?></td>
<td><?=nl2br(htmlspecialchars($r['indikator']??'-'))?></td>
<td><?=htmlspecialchars($r['target']??'-')?></td>
<td><?=!empty($r['waktu_mulai'])?formatDateDMY($r['waktu_mulai']):(!empty($r['waktu'])?htmlspecialchars($r['waktu']):'-')?></td>
<td><?=!empty($r['waktu_selesai'])?formatDateDMY($r['waktu_selesai']):'-'?></td>
<td><?=htmlspecialchars($r['penanggung_jawab']??'-')?></td>
<td class="text-right"><?= $r['anggaran']>0?'Rp '.number_format($r['anggaran'],0,',','.'):'-' ?></td>
<td><?=htmlspecialchars($r['sumber_dana']??'-')?></td>
<td class="text-center"><?php if(!empty($r['bukti'])): ?><a href="../assets/dokumen/program_kerja/<?=htmlspecialchars($r['bukti'])?>" target="_blank" class="bukti-link btn btn-sm btn-outline-primary"><i class="fas fa-file"></i> <?=htmlspecialchars($r['bukti'])?></a><?php else: ?>-<?php endif;?></td>
<td><?=nl2br(htmlspecialchars($r['evaluasi']??'-'))?></td>
<td><?=nl2br(htmlspecialchars($r['tindak_lanjut']??'-'))?></td>
<td class="text-center"><?php $sv=$r['status']??'belum_terlaksana'; if(!isset($status_opts[$sv])) $sv='belum_terlaksana'; $bc=$status_badge[$sv]; $ic=$status_icon[$sv]; $sl=$status_opts[$sv];?><span class="badge <?=$bc?>"><i class="fas <?=$ic?>"></i> <?=htmlspecialchars($sl)?></span></td>
<?php if($is_editable):?>
<td class="text-center" style="white-space:nowrap">
<div class="d-inline-flex align-items-center">
<button class="btn btn-info btn-sm btn-status-cycle mr-1" data-id="<?=$r['id']?>" data-status="<?=$sv?>" title="Ubah Status (siklus)"><i class="fas <?=$ic?>"></i></button>
<button class="btn btn-outline-secondary btn-sm btn-status-pick mr-1" data-id="<?=$r['id']?>" data-status="<?=$sv?>" title="Pilih Status"><i class="fas fa-list"></i></button>
<button class="btn btn-warning btn-sm btn-edit-laporan" data-id="<?=$r['id']?>" data-bukti="<?=htmlspecialchars($r['bukti']??'',ENT_QUOTES,'UTF-8')?>" data-evaluasi="<?=htmlspecialchars($r['evaluasi']??'',ENT_QUOTES,'UTF-8')?>" data-tindak="<?=htmlspecialchars($r['tindak_lanjut']??'',ENT_QUOTES,'UTF-8')?>" data-info="<?=$r['komponen']?>. <?=htmlspecialchars($kom['nama'],ENT_QUOTES,'UTF-8')?> - <?=htmlspecialchars($r['program'],ENT_QUOTES,'UTF-8')?>" title="Edit Bukti / Evaluasi / Tindak Lanjut"><i class="fas fa-edit"></i></button>
</div>
</td>
<?php endif;?>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
</div>
</div>
</div>

</div>
</section>
</div>

<?php if($is_editable):?>
<div class="modal fade" id="modalEditLaporan" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog modal-lg" role="document">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Edit Laporan</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="id" id="lap_id">
<div class="modal-body">
<div class="alert alert-info py-2" id="lap_info"></div>
<div class="form-group"><label>Bukti / Dokumen (kosongkan jika tidak ganti)</label><input type="file" name="bukti" class="form-control"><small id="lap_bukti_old" class="text-muted"></small></div>
<div class="form-group"><label>Evaluasi</label><textarea name="evaluasi" id="lap_evaluasi" class="form-control" rows="4"></textarea></div>
<div class="form-group"><label>Tindak Lanjut</label><textarea name="tindak_lanjut" id="lap_tindak" class="form-control" rows="4"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button><button type="submit" name="update_laporan" value="1" class="btn btn-primary">Simpan</button></div>
</form>
</div>
</div>
</div>
<?php endif;?>

<?php include '../templates/footer.php'; ?>
<script>
function prokerBuildUrl(k,s){ var p=[]; if(k&&k!='0') p.push('komponen='+k); if(s) p.push('status='+encodeURIComponent(s)); return p.length?'?'+p.join('&'):location.pathname; }
$(document).ready(function(){
  // DataTable untuk view tabel cadangan
  var t=$('#table-laporan').DataTable({scrollX:true, autoWidth:false, paging:true, pageLength:10, language:{search:"Cari:", lengthMenu:"Tampilkan _MENU_", zeroRecords:"Tidak ada data", info:"Menampilkan _START_ - _END_ dari _TOTAL_", paginate:{first:"Awal",last:"Akhir",next:"Next",previous:"Prev"}}});

  // Switcher View: Kartu vs Tabel
  $('#btn-show-cards').on('click', function(){
    $(this).addClass('active');
    $('#btn-show-table').removeClass('active');
    $('#proker-card-view').show();
    $('#proker-table-view').hide();
  });
  $('#btn-show-table').on('click', function(){
    $(this).addClass('active');
    $('#btn-show-cards').removeClass('active');
    $('#proker-card-view').hide();
    $('#proker-table-view').show();
    t.columns.adjust().draw();
  });

  // Filter Server-side dropdowns
  $('#filter-komponen,#filter-status').on('change',function(){ location.href=prokerBuildUrl($('#filter-komponen').val(), $('#filter-status').val()); });

  // Filter Pill Status Click
  $('.btn-filter-pill-status').on('click', function(){
    var st = $(this).data('status');
    $('#filter-status').val(st).trigger('change');
  });

  // Live Client-side Search untuk Kartu
  $('#search-card-proker').on('input', function(){
    var q = $(this).val().toLowerCase().trim();
    var visible = 0;
    $('.proker-report-card').each(function(){
      var cardSearch = $(this).data('search') || '';
      if(!q || cardSearch.indexOf(q) !== -1){
        $(this).show();
        visible++;
      } else {
        $(this).hide();
      }
    });
    $('#count-visible-cards').text(visible);
  });

  <?php if($message):?>Swal.fire({icon:'<?= $message['type']=='success'?'success':'error'?>',title:'<?= addslashes($message['text'])?>', timer:2000, showConfirmButton:false});<?php endif;?>

  $(document).on('click','.btn-status-cycle',function(){
    var id=$(this).data('id'), cur=$(this).data('status');
    var order=['belum_terlaksana','proses','terlaksana'], idx=order.indexOf(cur); var nxt=order[(idx+1)%3];
    $.post('',{update_status:1,id:id,status:nxt},function(r){ if(r&&r.success) location.reload(); },'json');
  });

  $(document).on('click','.btn-edit-laporan',function(){
    var b=$(this);
    $('#lap_id').val(b.data('id'));
    $('#lap_info').text(b.data('info'));
    $('#lap_bukti_old').text(b.data('bukti') ? 'File saat ini: '+b.data('bukti') : 'Belum ada file');
    $('#lap_evaluasi').val(b.data('evaluasi'));
    $('#lap_tindak').val(b.data('tindak'));
    $('#modalEditLaporan').modal('show');
  });

  $(document).on('click','.btn-status-pick',function(){
    var id=$(this).data('id'), cur=$(this).data('status')||'belum_terlaksana';
    Swal.fire({
      title:'Ubah Status',
      input:'select',
      inputValue:cur,
      inputOptions:{belum_terlaksana:'Belum Terlaksana',proses:'Proses',terlaksana:'Terlaksana'},
      showCancelButton:true,
      confirmButtonText:'Simpan',
      cancelButtonText:'Batal'
    }).then(function(res){
      if(res.isConfirmed && res.value){
        $.post('',{update_status:1,id:id,status:res.value},function(r){ if(r&&r.success) location.reload(); },'json');
      }
    });
  });
});
</script>
