<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/program_kerja_master.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin','kepala_madrasah'])) redirect('../login.php');

$user_level = getUserLevel();
$page_title = 'Komponen Program Kerja';

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y').'/'.(date('Y')+1);
ensureTbJabatanMaster($pdo);
prokerEnsureMaster($pdo);

$komponen_list = prokerGetKomponenList($pdo);
$_komponen_master_by_nomor = [];
try {
 $rowsK2=$pdo->query("SELECT id_komponen, nomor, nama, ruang, status FROM tb_program_kerja_master_komponen ORDER BY nomor ASC")->fetchAll(PDO::FETCH_ASSOC);
 foreach($rowsK2 as $r){ $_komponen_master_by_nomor[(int)$r['nomor']] = ['id_komponen'=>(int)$r['id_komponen'],'nama'=>$r['nama'],'ruang'=>$r['ruang'],'status'=>$r['status']]; }
} catch(Throwable $e){ $_komponen_master_by_nomor=[]; }

$matriks = prokerGetMatriks($pdo);
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
} catch(PDOException $e){ error_log($e->getMessage()); }

$status_opts=['belum_terlaksana'=>'Belum Terlaksana','proses'=>'Proses','terlaksana'=>'Terlaksana'];
$status_badge=['belum_terlaksana'=>'badge-secondary','proses'=>'badge-warning','terlaksana'=>'badge-success'];
$status_icon=['belum_terlaksana'=>'fa-clock','proses'=>'fa-sync fa-spin','terlaksana'=>'fa-check-circle'];

$upload_dir = __DIR__ . '/../assets/dokumen/program_kerja/';
if(!is_dir($upload_dir)) @mkdir($upload_dir,0755,true);

$message='';
if(isset($_SESSION['flash_message'])){ $message=$_SESSION['flash_message']; unset($_SESSION['flash_message']); }

$is_editable = in_array($user_level, ['admin','kepala_madrasah']);
$redirect=$_SERVER['PHP_SELF'].(!empty($_GET['session_type'])?'?session_type='.urlencode($_GET['session_type']):'');

if($_SERVER['REQUEST_METHOD']=='POST' && $is_editable){
 if(isset($_POST['add_master_komponen'])){
  $nomor=(int)($_POST['nomor']??0);
  $nama=trim($_POST['nama']??'');
  $ruang=trim($_POST['ruang']??'');
  if($nomor<1||$nama===''){
   $_SESSION['flash_message']=['type'=>'danger','text'=>'Nomor dan Nama Komponen wajib diisi!'];
  } else {
   try {
    $cekNo=$pdo->prepare("SELECT COUNT(*) FROM tb_program_kerja_master_komponen WHERE nomor=?"); $cekNo->execute([$nomor]);
    if($cekNo->fetchColumn()>0){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Nomor komponen sudah ada!']; }
    else {
     $stmt=$pdo->prepare("INSERT INTO tb_program_kerja_master_komponen (nomor,nama,ruang,status) VALUES (?,?,?,'Aktif')");
     $ok=$stmt->execute([$nomor,$nama,$ruang]);
     if($ok){ $_SESSION['flash_message']=['type'=>'success','text'=>'Komponen baru berhasil ditambahkan!']; logActivity($pdo,$_SESSION['username']??'system','Tambah Master Komponen Program Kerja',"No $nomor - $nama"); }
     else { $_SESSION['flash_message']=['type'=>'danger','text'=>'Gagal menambahkan komponen!']; }
    }
   } catch(Throwable $e){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Error: '.$e->getMessage()]; }
  }
  header("Location: $redirect"); exit;
 }
 elseif(isset($_POST['update_master_komponen'])){
  $nomor=(int)($_POST['nomor']??0);
  $nama=trim($_POST['nama']??'');
  $ruang=trim($_POST['ruang']??'');
  if($nomor<1||$nama===''){
   $_SESSION['flash_message']=['type'=>'danger','text'=>'Nomor dan Nama Komponen wajib diisi!'];
  } else {
   try {
    $cekNo=$pdo->prepare("SELECT COUNT(*) FROM tb_program_kerja_master_komponen WHERE nomor=?"); $cekNo->execute([$nomor]);
    if($cekNo->fetchColumn()==0){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Komponen dengan nomor tsb tidak ditemukan!']; }
    else {
     $stmt=$pdo->prepare("UPDATE tb_program_kerja_master_komponen SET nama=?,ruang=? WHERE nomor=?");
     $ok=$stmt->execute([$nama,$ruang,$nomor]);
     if($ok){ $_SESSION['flash_message']=['type'=>'success','text'=>'Komponen no '.$nomor.' berhasil diupdate!']; logActivity($pdo,$_SESSION['username']??'system','Update Master Komponen Program Kerja',"No $nomor - $nama"); }
     else { $_SESSION['flash_message']=['type'=>'danger','text'=>'Gagal update komponen!']; }
    }
   } catch(Throwable $e){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Error: '.$e->getMessage()]; }
  }
  header("Location: $redirect"); exit;
 }
 elseif(isset($_POST['delete_master_komponen'])){
  $nomor=(int)($_POST['nomor']??0);
  if($nomor<1){
   $_SESSION['flash_message']=['type'=>'danger','text'=>'Nomor komponen tidak valid!'];
  } else {
   try {
    $buktiRows=$pdo->prepare("SELECT bukti FROM tb_program_kerja WHERE komponen=? AND bukti IS NOT NULL AND bukti<>''");
    $buktiRows->execute([$nomor]);
    foreach($buktiRows->fetchAll(PDO::FETCH_COLUMN,0) as $bf){ if($bf && file_exists($upload_dir.$bf)) @unlink($upload_dir.$bf); }
    $pdo->prepare("DELETE FROM tb_program_kerja WHERE komponen=?")->execute([$nomor]);
    $pdo->prepare("DELETE FROM tb_program_kerja_master_detail WHERE komponen=?")->execute([$nomor]);
    $delM=$pdo->prepare("DELETE FROM tb_program_kerja_master_komponen WHERE nomor=?");
    $ok=$delM->execute([$nomor]);
    if($ok){ $_SESSION['flash_message']=['type'=>'success','text'=>'Master komponen no '.$nomor.' berhasil dihapus!']; logActivity($pdo,$_SESSION['username']??'system','Hapus Master Komponen Program Kerja',"No $nomor"); }
    else { $_SESSION['flash_message']=['type'=>'danger','text'=>'Gagal menghapus master komponen!']; }
   } catch(Throwable $e){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Error: '.$e->getMessage()]; }
  }
  header("Location: $redirect"); exit;
 }
  elseif(isset($_POST['add_master_detail'])){
   $program=trim($_POST['program']??''); $kegiatan=trim($_POST['kegiatan']??'');
   $ruang=trim($_POST['ruang']??'');
   $komponen=0; $err='';
   if(($_POST['komponen']??'')==='new'){
    $nomor=(int)($_POST['new_nomor']??0); $nama=trim($_POST['new_nama']??'');
    if($nomor<1||$nama===''){ $err='Nomor dan Nama Komponen baru wajib diisi!'; }
    else {
     try {
      $cekNo=$pdo->prepare("SELECT COUNT(*) FROM tb_program_kerja_master_komponen WHERE nomor=?"); $cekNo->execute([$nomor]);
      if($cekNo->fetchColumn()>0){ $err='Nomor komponen sudah ada!'; }
      else {
       $pdo->prepare("INSERT INTO tb_program_kerja_master_komponen (nomor,nama,ruang,status) VALUES (?,?,?,'Aktif')")->execute([$nomor,$nama,$ruang]);
       $komponen=$nomor;
      }
     } catch(Throwable $e){ $err='Error: '.$e->getMessage(); }
    }
   } else {
    $komponen=(int)($_POST['komponen']??0);
    if($komponen>0 && $ruang!==''){
     try { $pdo->prepare("UPDATE tb_program_kerja_master_komponen SET ruang=? WHERE nomor=?")->execute([$ruang,$komponen]); } catch(Throwable $e){}
    }
   }
   if($err!==''){ $_SESSION['flash_message']=['type'=>'danger','text'=>$err]; }
   elseif($komponen<1||$program===''||$kegiatan===''){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Komponen, Program dan Kegiatan wajib diisi!']; }
   else {
    try {
     $mx=$pdo->prepare("SELECT COALESCE(MAX(urutan),0)+1 FROM tb_program_kerja_master_detail WHERE komponen=?"); $mx->execute([$komponen]); $u=(int)$mx->fetchColumn();
     $stmt=$pdo->prepare("INSERT INTO tb_program_kerja_master_detail (komponen,program,kegiatan,tujuan,indikator,target,evaluasi,tindak_lanjut,urutan) VALUES (?,?,?,?,?,?,?,?,?)");
     $ok=$stmt->execute([$komponen,$program,$kegiatan,trim($_POST['tujuan']??''),trim($_POST['indikator']??''),trim($_POST['target']??''),trim($_POST['evaluasi']??''),trim($_POST['tindak_lanjut']??''),$u]);
     if($ok){ $_SESSION['flash_message']=['type'=>'success','text'=>'Program kerja berhasil disimpan!']; logActivity($pdo,$_SESSION['username']??'system','Tambah Detail Program Kerja',"Komp $komponen - $program"); }
     else { $_SESSION['flash_message']=['type'=>'danger','text'=>'Gagal menambah detail!']; }
    } catch(Throwable $e){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Error: '.$e->getMessage()]; }
   }
   header("Location: $redirect"); exit;
  }
  elseif(isset($_POST['update_master_detail'])){
   $id=(int)($_POST['id']??0); $komponen=(int)($_POST['komponen']??0); $program=trim($_POST['program']??''); $kegiatan=trim($_POST['kegiatan']??'');
   if($id<=0||$komponen<1||$program===''||$kegiatan===''){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Data tidak valid!']; }
   else {
    try {
     $stmt=$pdo->prepare("UPDATE tb_program_kerja_master_detail SET komponen=?,program=?,kegiatan=?,tujuan=?,indikator=?,target=?,evaluasi=?,tindak_lanjut=? WHERE id=?");
     $ok=$stmt->execute([$komponen,$program,$kegiatan,trim($_POST['tujuan']??''),trim($_POST['indikator']??''),trim($_POST['target']??''),trim($_POST['evaluasi']??''),trim($_POST['tindak_lanjut']??''),$id]);
     if($ok){ $_SESSION['flash_message']=['type'=>'success','text'=>'Detail program diupdate!']; logActivity($pdo,$_SESSION['username']??'system','Update Detail Program Kerja',"ID $id"); }
     else { $_SESSION['flash_message']=['type'=>'danger','text'=>'Gagal update!']; }
    } catch(Throwable $e){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Error: '.$e->getMessage()]; }
   }
   header("Location: $redirect"); exit;
  }
  elseif(isset($_POST['delete_master_detail'])){
   $id=(int)($_POST['id']??0);
   try { $ok=$pdo->prepare("DELETE FROM tb_program_kerja_master_detail WHERE id=?")->execute([$id]); $_SESSION['flash_message']=$ok?['type'=>'success','text'=>'Detail dihapus!']:['type'=>'danger','text'=>'Gagal hapus!']; if($ok) logActivity($pdo,$_SESSION['username']??'system','Hapus Detail Program Kerja',"ID $id"); }
   catch(Throwable $e){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Error: '.$e->getMessage()]; }
   header("Location: $redirect"); exit;
  }
  elseif(isset($_POST['delete_komponen_program'])){
   $komponen=(int)($_POST['komponen']??0);
  if($komponen>=1){
   try {
    $buktiRows=$pdo->prepare("SELECT bukti FROM tb_program_kerja WHERE komponen=? AND bukti IS NOT NULL AND bukti<>''");
    $buktiRows->execute([$komponen]);
    foreach($buktiRows->fetchAll(PDO::FETCH_COLUMN,0) as $bf){ if($bf && file_exists($upload_dir.$bf)) @unlink($upload_dir.$bf); }
    $del=$pdo->prepare("DELETE FROM tb_program_kerja WHERE komponen=?");
    $ok=$del->execute([$komponen]);
    if($ok){ $_SESSION['flash_message']=['type'=>'success','text'=>'Semua program pada komponen '.$komponen.' dihapus!']; logActivity($pdo,$_SESSION['username']??'system','Hapus Program Kerja Per Komponen',"Komponen $komponen"); }
    else { $_SESSION['flash_message']=['type'=>'danger','text'=>'Gagal menghapus!']; }
   } catch(Throwable $e){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Error: '.$e->getMessage()]; }
  } else {
   $_SESSION['flash_message']=['type'=>'danger','text'=>'Komponen tidak valid!'];
  }
  header("Location: $redirect"); exit;
 }
}

$rekap_komponen=[];
try {
 $rows=$pdo->query("SELECT komponen,status,COUNT(*) c FROM tb_program_kerja GROUP BY komponen,status")->fetchAll(PDO::FETCH_ASSOC);
 foreach($rows as $r){ $k=(int)$r['komponen']; if(!isset($rekap_komponen[$k])) $rekap_komponen[$k]=['belum_terlaksana'=>0,'proses'=>0,'terlaksana'=>0,'total'=>0]; $s=$r['status']??'belum_terlaksana'; if(!isset($status_opts[$s])) $s='belum_terlaksana'; $rekap_komponen[$k][$s]=(int)$r['c']; $rekap_komponen[$k]['total']+=(int)$r['c']; }
} catch(PDOException $e){}

$css_libs=['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs=['https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js','https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js'];
include '../templates/header.php';
include '../templates/sidebar.php';
?>
<style>
.table-responsive td{font-size:.85rem;vertical-align:top}
.table-responsive th{font-size:.8rem;white-space:nowrap}
#modalTambahDetail textarea,#modalEditDetail textarea{min-height:140px;resize:vertical}
</style>
<div class="main-content">
<section class="section">
<div class="section-header">
<h1>Komponen Program Kerja <small style="font-size:55%;font-weight:700;margin-left:8px;vertical-align:middle;color:#5f6fb4;background:#eef1ff;border:1px solid #d6dcff;border-radius:999px;padding:4px 10px;">TA: <?=htmlspecialchars($tahun_ajaran)?></small></h1>
<div class="section-header-breadcrumb"><div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div><div class="breadcrumb-item">Program Kerja</div><div class="breadcrumb-item">Komponen Program Kerja</div></div>
</div>
<div class="section-body">
<div class="mb-3 d-flex justify-content-end align-items-center flex-wrap">
<div>
<a href="export_komponen_program_kerja_excel.php" class="btn btn-success btn-sm mr-1"><i class="fas fa-file-excel"></i> Excel</a>
<a href="cetak_komponen_program_kerja.php" target="_blank" rel="noopener" class="btn btn-danger btn-sm mr-1"><i class="fas fa-file-pdf"></i> PDF</a>
<?php if($is_editable):?>
<button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modalTambahDetail">
<i class="fas fa-plus"></i> Tambah Program Kerja
</button>
<?php endif;?>
</div>
</div>
<?php if($komponen_list):?>
<div class="row">
<?php foreach($komponen_list as $k=>$v): $m=$matriks[$k]??[]; $rk=$rekap_komponen[$k]??['belum_terlaksana'=>0,'proses'=>0,'terlaksana'=>0,'total'=>0];?>
<div class="col-12 mb-3">
<div class="card" id="card-komponen-<?=$k?>">
<div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap">
<div>
<span class="badge badge-light mr-2">Komponen No. <?=$k?></span>
<h4 class="mb-0" style="display:inline-block;color:#fff"><?=htmlspecialchars($v['nama'])?></h4>
<small class="text-white ml-2"><i class="fas fa-info-circle"></i> <?=htmlspecialchars($v['ruang'])?></small>
</div>
<div class="d-flex flex-wrap align-items-center">
<span class="badge badge-light mr-1"><i class="fas <?=$status_icon['belum_terlaksana']?>"></i> <?=$rk['belum_terlaksana']?></span>
<span class="badge badge-warning mr-1"><i class="fas <?=$status_icon['proses']?>"></i> <?=$rk['proses']?></span>
<span class="badge badge-success mr-1"><i class="fas <?=$status_icon['terlaksana']?>"></i> <?=$rk['terlaksana']?></span>
<span class="badge badge-info">Total: <?=$rk['total']?></span>
</div>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<?php if(!empty($m)):?>
<table class="table table-striped table-bordered table-hover mb-0">
<thead class="thead-dark">
<tr>
<th class="text-center" style="width:50px;">No</th>
<th style="min-width:200px;">Program</th>
<th style="min-width:220px;">Kegiatan</th>
<th style="min-width:200px;">Tujuan</th>
<th style="min-width:220px;">Indikator Keberhasilan</th>
<th style="min-width:200px;">Evaluasi</th>
<th style="min-width:200px;">Tindak Lanjut</th>
<th class="text-center" style="width:120px;">Aksi</th>
</tr>
</thead>
<tbody>
<?php $no=1; foreach($m as $row):?>
<tr>
<td class="text-center"><?=$no++?></td>
<td><?=htmlspecialchars($row['program'])?></td>
<td><?=htmlspecialchars($row['kegiatan'])?></td>
<td><?=htmlspecialchars($row['tujuan'])?></td>
<td><?=htmlspecialchars($row['indikator'])?></td>
<td><?=htmlspecialchars($row['evaluasi'])?></td>
<td><?=htmlspecialchars($row['tindak'])?></td>
<?php if($is_editable):?><td class="text-center">
<div class="btn-group" role="group">
<button type="button" class="btn btn-sm btn-outline-warning btn-edit-detail" 
data-id="<?=($row['id']??0)?>" 
data-komponen="<?=$k?>" 
data-program="<?=htmlspecialchars($row['program'],ENT_QUOTES,'UTF-8')?>" 
data-kegiatan="<?=htmlspecialchars($row['kegiatan'],ENT_QUOTES,'UTF-8')?>" 
data-tujuan="<?=htmlspecialchars($row['tujuan']??'',ENT_QUOTES,'UTF-8')?>" 
data-indikator="<?=htmlspecialchars($row['indikator']??'',ENT_QUOTES,'UTF-8')?>" 
data-target="<?=htmlspecialchars($row['target']??'',ENT_QUOTES,'UTF-8')?>" 
data-evaluasi="<?=htmlspecialchars($row['evaluasi']??'',ENT_QUOTES,'UTF-8')?>" 
data-tindak="<?=htmlspecialchars($row['tindak']??'',ENT_QUOTES,'UTF-8')?>" 
title="Edit"><i class="fas fa-edit"></i></button>
<button type="button" class="btn btn-sm btn-outline-danger btn-del-detail" 
data-id="<?=($row['id']??0)?>" 
data-program="<?=htmlspecialchars($row['program'],ENT_QUOTES,'UTF-8')?>" 
title="Hapus"><i class="fas fa-trash"></i></button>
</div>
</td><?php else:?><td class="text-center" colspan="7">
<em class="text-muted">Tidak ada aksi - Mode Lihat</em>
</td><?php endif;?>
</tr>
<?php endforeach;?>
</tbody>
</table>
<?php else: ?>
<div class="alert alert-info text-center py-4">
<i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
<p class="mb-0">Belum ada program dan kegiatan untuk Komponen <strong><?=$k?>. <?=htmlspecialchars($v['nama'])?></strong>.</p>
</div>
<?php endif;?>
</div>
</div>
</div>
</div>
<?php endforeach;?>
</div>
<?php else:?>
<div class="alert alert-warning text-center py-4">
<i class="fas fa-folder-open fa-2x mb-2 d-block"></i>
<p class="mb-0">Belum ada komponen program kerja.</p>
</div>
<?php endif;?>

</div>
</section>
</div>

<div class="modal fade" id="modalTambahDetail" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog modal-lg" role="document">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title">Tambah Komponen / Program Kerja</h5>
<button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
</div>
<form method="POST">
<div class="modal-body">

<div class="form-group">
<label for="add_detail_komponen">Komponen <span class="text-danger">*</span></label>
<select name="komponen" id="add_detail_komponen" class="form-control" required>
<?php foreach($komponen_list as $kk=>$vv):?>
<option value="<?=$kk?>" data-ruang="<?=htmlspecialchars($vv['ruang']??'',ENT_QUOTES,'UTF-8')?>"><?=$kk?>. <?=htmlspecialchars($vv['nama'])?></option>
<?php endforeach;?>
<option value="new" data-ruang="">+ Tambah Komponen Baru...</option>
</select>
</div>

<div id="box_komponen_baru" style="display:none">
<div class="row">
<div class="col-md-3">
<div class="form-group">
<label for="new_nomor">No <span class="text-danger">*</span></label>
<input type="number" min="1" class="form-control" name="new_nomor" id="new_nomor" placeholder="No">
</div>
</div>
<div class="col-md-9">
<div class="form-group">
<label for="new_nama">Nama Komponen <span class="text-danger">*</span></label>
<input type="text" class="form-control" name="new_nama" id="new_nama" placeholder="Nama komponen baru">
</div>
</div>
</div>
</div>

<div class="form-group">
<label for="add_detail_ruang">Ruang Lingkup</label>
<textarea class="form-control" name="ruang" id="add_detail_ruang" rows="3" placeholder="Ruang lingkup komponen"></textarea>
<small class="text-muted">Ruang lingkup komponen terpilih, bisa diubah.</small>
</div>

<div class="alert alert-light border mb-2"><i class="fas fa-list"></i> Program Kerja</div>
<div class="row">
<div class="col-md-6">
<div class="form-group">
<label for="add_detail_program">Program <span class="text-danger">*</span></label>
<input type="text" class="form-control" name="program" id="add_detail_program" required placeholder="Nama program">
</div>
</div>
<div class="col-md-6">
<div class="form-group">
<label for="add_detail_kegiatan">Kegiatan <span class="text-danger">*</span></label>
<input type="text" class="form-control" name="kegiatan" id="add_detail_kegiatan" required placeholder="Kegiatan">
</div>
</div>
</div>

<div class="form-group">
<label for="add_detail_tujuan">Tujuan</label>
<textarea class="form-control" name="tujuan" id="add_detail_tujuan" rows="4"></textarea>
</div>

<div class="form-group">
<label for="add_detail_indikator">Indikator Keberhasilan</label>
<textarea class="form-control" name="indikator" id="add_detail_indikator" rows="4"></textarea>
</div>

<div class="form-group">
<label for="add_detail_evaluasi">Evaluasi</label>
<textarea class="form-control" name="evaluasi" id="add_detail_evaluasi" rows="4"></textarea>
</div>

<div class="form-group">
<label for="add_detail_tindak_lanjut">Tindak Lanjut</label>
<textarea class="form-control" name="tindak_lanjut" id="add_detail_tindak_lanjut" rows="4"></textarea>
</div>

</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
<button type="submit" name="add_master_detail" value="1" class="btn btn-primary">Simpan</button>
</div>
</form>
</div>
</div>
</div>
<div class="modal fade" id="modalEditDetail" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog modal-lg" role="document">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title">Edit Program Kerja</h5>
<button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
</div>
<form method="POST">
<input type="hidden" name="id" id="edit_detail_id">
<div class="modal-body">

<div class="form-group">
<label for="edit_detail_komponen">Komponen <span class="text-danger">*</span></label>
<select name="komponen" id="edit_detail_komponen" class="form-control" required>
<?php foreach($komponen_list as $kk=>$vv):?>
<option value="<?=$kk?>"><?=$kk?>. <?=htmlspecialchars($vv['nama'])?></option>
<?php endforeach;?>
</select>
</div>

<div class="row">
<div class="col-md-6">
<div class="form-group">
<label for="edit_detail_program">Program <span class="text-danger">*</span></label>
<input type="text" class="form-control" name="program" id="edit_detail_program" required>
</div>

</div>
<div class="col-md-6">
<div class="form-group">
<label for="edit_detail_kegiatan">Kegiatan <span class="text-danger">*</span></label>
<input type="text" class="form-control" name="kegiatan" id="edit_detail_kegiatan" required>
</div>
</div>
</div>

<div class="row">
<div class="col-md-6">
<div class="form-group">
<label for="edit_detail_tujuan">Tujuan</label>
<textarea class="form-control" name="tujuan" id="edit_detail_tujuan" rows="4"></textarea>
</div>
</div>
<div class="col-md-6">
<div class="form-group">
<label for="edit_detail_indikator">Indikator Keberhasilan</label>
<textarea class="form-control" name="indikator" id="edit_detail_indikator" rows="4"></textarea>
</div>
</div>
</div>

<div class="row">
<div class="col-md-6">
<div class="form-group">
<label for="edit_detail_evaluasi">Evaluasi</label>
<textarea class="form-control" name="evaluasi" id="edit_detail_evaluasi" rows="4"></textarea>
</div>
</div>
<div class="col-md-6">
<div class="form-group">
<label for="edit_detail_tindak">Tindak Lanjut</label>
<textarea class="form-control" name="tindak_lanjut" id="edit_detail_tindak" rows="4"></textarea>
</div>
</div>
</div>
</div>

<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times"></i> Batal</button>
<button type="submit" name="update_master_detail" value="1" class="btn btn-primary">Update</button>
</div>
</form>
</div>
</div>
</div>
<?php include '../templates/footer.php'; ?>
<script>
$(document).ready(function(){
   $(document).on('click','.btn-edit-detail',function(e){ e.stopPropagation(); var b=$(this); $('#edit_detail_id').val(b.data('id')); $('#edit_detail_komponen').val(b.data('komponen')); $('#edit_detail_program').val(b.data('program')); $('#edit_detail_kegiatan').val(b.data('kegiatan')); $('#edit_detail_tujuan').val(b.data('tujuan')); $('#edit_detail_indikator').val(b.data('indikator')); $('#edit_detail_evaluasi').val(b.data('evaluasi')); $('#edit_detail_tindak').val(b.data('tindak')); $('#modalEditDetail').modal('show'); });
   $(document).on('click','.btn-del-detail',function(e){ e.stopPropagation(); var id=$(this).data('id'), prog=$(this).data('program'); Swal.fire({title:'Hapus data?',text:'Hapus "'+prog+'"?',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',confirmButtonText:'Ya, Hapus'}).then(function(res){ if(res.isConfirmed){ $('#delDetailId').val(id); $('#delDetailForm').submit(); }}); });
   function syncKomponenFields(){
     var $s=$('#add_detail_komponen'), isNew=$s.val()==='new';
     $('#box_komponen_baru').toggle(isNew);
     $('#new_nomor,#new_nama').prop('required', isNew);
     $('#add_detail_ruang').val(isNew ? '' : ($s.find('option:selected').data('ruang')||''));
   }
   $('#add_detail_komponen').on('change', syncKomponenFields);
   syncKomponenFields();
   function autoGrow(el){ el.style.height='auto'; el.style.height=(el.scrollHeight+2)+'px'; }
   $(document).on('input','#modalTambahDetail textarea,#modalEditDetail textarea',function(){ autoGrow(this); });
   $('#modalTambahDetail').on('shown.bs.modal',function(){ $(this).find('textarea').each(function(){ autoGrow(this); }); });
   $('#modalEditDetail').on('shown.bs.modal',function(){ $(this).find('textarea').each(function(){ autoGrow(this); }); });
   <?php if($message):?>
   Swal.fire({icon:'<?= $message['type']=='success'?'success':'error'?>',title:'<?= addslashes($message['text'])?>', timer:2000, showConfirmButton:false});
   <?php endif;?>
});
</script>
