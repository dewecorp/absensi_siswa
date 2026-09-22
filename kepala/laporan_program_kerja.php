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
#table-laporan th{white-space:nowrap;font-size:.78rem}
#table-laporan td{font-size:.8rem;vertical-align:top}
.bukti-link{max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block}
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
<div class="row mb-3">
<div class="col-md-3">
<label class="font-weight-bold">Filter Komponen</label>
<select id="filter-komponen" class="form-control">
<option value="0">Semua Komponen (<?=count($komponen_list)?>)</option>
<?php foreach($komponen_list as $k=>$v):?>
<option value="<?=$k?>" <?=$filter_komponen==$k?'selected':''?>><?=$k?>. <?=htmlspecialchars($v['nama'])?></option>
<?php endforeach;?>
</select>
</div>
<div class="col-md-3">
<label class="font-weight-bold">Filter Status</label>
<select id="filter-status" class="form-control">
<option value="">Semua Status</option>
<?php foreach($status_opts as $sv=>$sl):?>
<option value="<?=$sv?>" <?=$filter_status===$sv?'selected':''?>><?=htmlspecialchars($sl)?></option>
<?php endforeach;?>
</select>
</div>
</div>
<div class="mb-2">
<?php foreach($status_opts as $sv=>$sl): $c=(int)($cnt_st[$sv]??0); $bc=$status_badge[$sv]; $ic=$status_icon[$sv];?>
<span class="badge <?=$bc?>" style="font-size:.82rem;padding:6px 10px;margin-right:6px"><i class="fas <?=$ic?>"></i> <?=htmlspecialchars($sl)?>: <?=$c?></span>
<?php endforeach;?>
</div>

<div class="card">
<div class="card-header"><h4>Tabel Laporan Program Kerja</h4><div class="card-header-action d-flex align-items-center">
<span class="badge badge-info mr-2"><?=count($rows)?> data</span>
<?php $q=http_build_query(array_filter(['komponen'=>$filter_komponen?:null,'status'=>$filter_status?:null])); $qs=$q?'?'.$q:''; ?>
<a href="export_program_kerja_excel.php<?=$qs?>" class="btn btn-success btn-sm mr-1"><i class="fas fa-file-excel"></i> Excel</a>
<a href="cetak_program_kerja.php<?=$qs?>" target="_blank" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf"></i> PDF</a>
</div></div>
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
<?php if($is_editable):?><th class="text-center" style="min-width:130px">Ubah Status</th><th class="text-center" style="min-width:90px">Aksi</th><?php endif;?>
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
<td class="text-center">
<div class="btn-group-vertical" role="group">
<button class="btn btn-info btn-sm btn-status-cycle mb-1" data-id="<?=$r['id']?>" data-status="<?=$sv?>" title="Ubah Status (siklus)"><i class="fas <?=$ic?>"></i> Ubah</button>
<button class="btn btn-outline-secondary btn-sm btn-status-pick mb-1" data-id="<?=$r['id']?>" title="Pilih Status"><i class="fas fa-list"></i> Pilih</button>
</div>
</td>
<td class="text-center">
<button class="btn btn-warning btn-sm btn-edit-laporan" data-id="<?=$r['id']?>" data-bukti="<?=htmlspecialchars($r['bukti']??'',ENT_QUOTES,'UTF-8')?>" data-evaluasi="<?=htmlspecialchars($r['evaluasi']??'',ENT_QUOTES,'UTF-8')?>" data-tindak="<?=htmlspecialchars($r['tindak_lanjut']??'',ENT_QUOTES,'UTF-8')?>" data-info="<?=$r['komponen']?>. <?=htmlspecialchars($kom['nama'],ENT_QUOTES,'UTF-8')?> - <?=htmlspecialchars($r['program'],ENT_QUOTES,'UTF-8')?>" title="Edit Bukti / Evaluasi / Tindak Lanjut"><i class="fas fa-edit"></i></button>
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
 var t=$('#table-laporan').DataTable({scrollX:true, autoWidth:false, paging:true, pageLength:10, language:{search:"Cari:", lengthMenu:"Tampilkan _MENU_", zeroRecords:"Tidak ada data", info:"Menampilkan _START_ - _END_ dari _TOTAL_", paginate:{first:"Awal",last:"Akhir",next:"Next",previous:"Prev"}}});
 $('#filter-komponen,#filter-status').on('change',function(){ location.href=prokerBuildUrl($('#filter-komponen').val(), $('#filter-status').val()); });
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
  var id=$(this).data('id');
  Swal.fire({
   title:'Ubah Status',
   input:'select',
   inputOptions:{belum_terlaksana:'Belum Terlaksana',proses:'Proses',terlaksana:'Terlaksana'},
   inputPlaceholder:'Pilih status',
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
