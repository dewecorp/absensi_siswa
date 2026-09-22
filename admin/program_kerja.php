<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/program_kerja_master.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin','kepala_madrasah'])) redirect('../login.php');

$user_level = getUserLevel();
$is_editable = in_array($user_level, ['admin','kepala_madrasah']);
$page_title = 'Program Kerja Madrasah';

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y').'/'.(date('Y')+1);


prokerEnsureMaster($pdo);
$komponen_list=prokerGetKomponenList($pdo);
$matriks=prokerGetMatriks($pdo);
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
 if(!in_array('tahun_ajaran',$cols)) $pdo->exec("ALTER TABLE tb_program_kerja ADD COLUMN tahun_ajaran VARCHAR(20) NULL AFTER tindak_lanjut");
 if(!in_array('bukti',$cols)) $pdo->exec("ALTER TABLE tb_program_kerja ADD COLUMN bukti VARCHAR(255) NULL");
 if(!in_array('waktu_mulai',$cols)) $pdo->exec("ALTER TABLE tb_program_kerja ADD COLUMN waktu_mulai DATE NULL AFTER target");
 if(!in_array('waktu_selesai',$cols)) $pdo->exec("ALTER TABLE tb_program_kerja ADD COLUMN waktu_selesai DATE NULL AFTER waktu_mulai");
 if(!in_array('status',$cols)) $pdo->exec("ALTER TABLE tb_program_kerja ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'belum_terlaksana' AFTER tindak_lanjut");
 if(in_array('waktu',$cols) && in_array('waktu_mulai',$cols)){
  $pdo->exec("UPDATE tb_program_kerja SET waktu_mulai=waktu WHERE (waktu_mulai IS NULL OR waktu_mulai='0000-00-00') AND waktu IS NOT NULL AND waktu REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}'");
 }
 $pdo->exec("UPDATE tb_program_kerja SET status='belum_terlaksana' WHERE status IS NULL OR status=''");
} catch(PDOException $e){ error_log($e->getMessage()); }

$upload_dir = __DIR__ . '/../assets/dokumen/program_kerja/';
if(!is_dir($upload_dir)) @mkdir($upload_dir,0755,true);

$message='';
if(isset($_SESSION['flash_message'])){ $message=$_SESSION['flash_message']; unset($_SESSION['flash_message']); }

if($_SERVER['REQUEST_METHOD']=='POST' && $is_editable){
 $redirect=$_SERVER['PHP_SELF'].(!empty($_GET['session_type'])?'?session_type='.urlencode($_GET['session_type']):'');
 if(isset($_POST['add_program'])){
  $komponen=(int)($_POST['komponen']??0);
  $program=trim($_POST['program']??'');
  $kegiatan=trim($_POST['kegiatan']??'');
  if(!isset($komponen_list[$komponen])||$program==''||$kegiatan==''){
   $_SESSION['flash_message']=['type'=>'danger','text'=>'Komponen, Program dan Kegiatan wajib diisi!'];
  } else {
   $anggaran=(float)preg_replace('/[^0-9]/','',$_POST['anggaran']??'0');
   $wm=!empty($_POST['waktu_mulai'])?$_POST['waktu_mulai']:null; $ws=!empty($_POST['waktu_selesai'])?$_POST['waktu_selesai']:null;
   $st='belum_terlaksana';
    $stmt=$pdo->prepare("INSERT INTO tb_program_kerja (komponen,program,kegiatan,tujuan,indikator,target,waktu_mulai,waktu_selesai,penanggung_jawab,anggaran,sumber_dana,bukti,evaluasi,tindak_lanjut,status,tahun_ajaran) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
   $ok=$stmt->execute([$komponen,$program,$kegiatan,trim($_POST['tujuan']??''),trim($_POST['indikator']??''),trim($_POST['target']??''),$wm,$ws,trim($_POST['penanggung_jawab']??''),$anggaran,trim($_POST['sumber_dana']??''),null,'','',$st,$tahun_ajaran]);
   $_SESSION['flash_message']=$ok?['type'=>'success','text'=>'Program kerja ditambahkan!']:['type'=>'danger','text'=>'Gagal menambah!'];
   if($ok) logActivity($pdo,$_SESSION['username']??'system','Tambah Program Kerja',"$program - komp $komponen");
  }
  header("Location: $redirect"); exit;
  } elseif(isset($_POST['update_program'])){
  $id=(int)($_POST['id']??0);
  $komponen=(int)($_POST['komponen']??0);
  $program=trim($_POST['program']??'');
  $kegiatan=trim($_POST['kegiatan']??'');
  if($id<=0||!isset($komponen_list[$komponen])||$program==''||$kegiatan==''){
   $_SESSION['flash_message']=['type'=>'danger','text'=>'Data tidak valid!'];
  } else {
   $anggaran=(float)preg_replace('/[^0-9]/','',$_POST['anggaran']??'0');
   $wm=!empty($_POST['waktu_mulai'])?$_POST['waktu_mulai']:null; $ws=!empty($_POST['waktu_selesai'])?$_POST['waktu_selesai']:null;
   $cur=$pdo->prepare("SELECT bukti,status,evaluasi,tindak_lanjut FROM tb_program_kerja WHERE id=?"); $cur->execute([$id]); $curRow=$cur->fetch(PDO::FETCH_ASSOC)?:[];
   $old=$curRow['bukti']??null; $bukti=$old;
   $st=$curRow['status']??'belum_terlaksana'; if(!isset($status_opts[$st])) $st='belum_terlaksana';
   $ev=$curRow['evaluasi']??''; $tl=$curRow['tindak_lanjut']??'';
     $stmt=$pdo->prepare("UPDATE tb_program_kerja SET komponen=?,program=?,kegiatan=?,tujuan=?,indikator=?,target=?,waktu_mulai=?,waktu_selesai=?,penanggung_jawab=?,anggaran=?,sumber_dana=?,bukti=?,evaluasi=?,tindak_lanjut=?,status=? WHERE id=?");
   $ok=$stmt->execute([$komponen,$program,$kegiatan,trim($_POST['tujuan']??''),trim($_POST['indikator']??''),trim($_POST['target']??''),$wm,$ws,trim($_POST['penanggung_jawab']??''),$anggaran,trim($_POST['sumber_dana']??''),$bukti,$ev,$tl,$st,$id]);
   $_SESSION['flash_message']=$ok?['type'=>'success','text'=>'Program kerja diupdate!']:['type'=>'danger','text'=>'Gagal update!'];
   if($ok) logActivity($pdo,$_SESSION['username']??'system','Update Program Kerja',"ID $id");
  }
  header("Location: $redirect"); exit;
  } elseif(isset($_POST['delete_program'])){
  $id=(int)($_POST['id']??0);
  $cur=$pdo->prepare("SELECT bukti FROM tb_program_kerja WHERE id=?"); $cur->execute([$id]); $old=$cur->fetchColumn();
  if($old && file_exists($upload_dir.$old)) @unlink($upload_dir.$old);
  $stmt=$pdo->prepare("DELETE FROM tb_program_kerja WHERE id=?");
  $ok=$stmt->execute([$id]);
  $_SESSION['flash_message']=$ok?['type'=>'success','text'=>'Data dihapus!']:['type'=>'danger','text'=>'Gagal hapus!'];
  if($ok) logActivity($pdo,$_SESSION['username']??'system','Hapus Program Kerja',"ID $id");
  header("Location: $redirect"); exit;
  } elseif(isset($_POST['update_status'])){
  $id=(int)($_POST['id']??0); $st=trim($_POST['status']??'');
  if(!isset($status_opts[$st])){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Status tidak valid!']; header("Location: $redirect"); exit; }
  $ok=$pdo->prepare("UPDATE tb_program_kerja SET status=? WHERE id=?")->execute([$st,$id]);
  if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){ header('Content-Type: application/json'); echo json_encode(['success'=>(bool)$ok]); exit; }
  $_SESSION['flash_message']=$ok?['type'=>'success','text'=>'Status diupdate!']:['type'=>'danger','text'=>'Gagal update status!'];
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
#table-proker th{white-space:nowrap;font-size:.78rem}
#table-proker td{font-size:.8rem;vertical-align:top}
</style>
<div class="main-content">
<section class="section">
<div class="section-header">
<h1>Program Kerja <small style="font-size:55%;font-weight:700;margin-left:8px;vertical-align:middle;color:#5f6fb4;background:#eef1ff;border:1px solid #d6dcff;border-radius:999px;padding:4px 10px;">TA: <?=htmlspecialchars($tahun_ajaran)?></small></h1>
<div class="section-header-breadcrumb"><div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div><div class="breadcrumb-item">Program Kerja</div></div>
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
<div class="col-md-9 text-right d-flex align-items-end justify-content-end">
<?php if($is_editable):?>
<button class="btn btn-primary" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Tambah Program</button>
<?php endif;?>
</div>
</div>

<div class="card">
<div class="card-header"><h4>Tabel Program Kerja</h4><div class="card-header-action d-flex align-items-center">
<span class="badge badge-info mr-2"><?=count($rows)?> data</span>
<?php $q=http_build_query(array_filter(['komponen'=>$filter_komponen?:null,'status'=>$filter_status?:null])); $qs=$q?'?'.$q:''; ?>
<a href="export_progja_excel.php<?=$qs?>" class="btn btn-success btn-sm mr-1"><i class="fas fa-file-excel"></i> Excel</a>
<a href="cetak_progja.php<?=$qs?>" target="_blank" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf"></i> PDF</a>
</div></div>
<div class="card-body">
<div class="table-responsive">
<table class="table table-striped table-bordered" id="table-proker" style="width:100%">
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
<?php if($is_editable):?><th class="text-center" style="min-width:130px">Aksi</th><?php endif;?>
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
<?php if($is_editable):?>
<td class="text-center">
<div class="btn-group">
<button class="btn btn-warning btn-sm btn-edit" data-row='<?=htmlspecialchars(json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8')?>' title="Edit"><i class="fas fa-edit"></i></button>
<button class="btn btn-danger btn-sm btn-del" data-id="<?=$r['id']?>" data-prog="<?=htmlspecialchars($r['program'])?>" title="Hapus"><i class="fas fa-trash"></i></button>
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

<div class="card">
<div class="card-header"><h4>Daftar Komponen (<?=count($komponen_list)?>)</h4></div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm table-bordered mb-0">
<thead><tr><th>No</th><th>Komponen</th><th>Ruang Lingkup</th></tr></thead>
<tbody>
<?php foreach($komponen_list as $k=>$v):?><tr><td class="text-center"><?=$k?></td><td><strong><?=htmlspecialchars($v['nama'])?></strong></td><td><?=htmlspecialchars($v['ruang'])?></td></tr><?php endforeach;?>
</tbody>
</table>
</div>
</div>
</div>

</div>
</section>
</div>

<?php if($is_editable):?>
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog modal-xl" role="document">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Tambah Program Kerja</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
<form method="POST" enctype="multipart/form-data">
<div class="modal-body">
<div class="row">
<div class="col-md-3"><div class="form-group"><label>Komponen *</label><select name="komponen" class="form-control" required><option value="">-- Pilih --</option><?php foreach($komponen_list as $k=>$v):?><option value="<?=$k?>"><?=$k?>. <?=htmlspecialchars($v['nama'])?></option><?php endforeach;?></select></div></div>
<div class="col-md-3"><div class="form-group"><label>Program *</label><select name="program" id="add_program_sel" class="form-control" required><option value="">-- Pilih Program --</option></select></div></div>
<div class="col-md-3"><div class="form-group"><label>Waktu Mulai</label><input type="date" name="waktu_mulai" class="form-control"></div></div>
<div class="col-md-3"><div class="form-group"><label>Waktu Selesai</label><input type="date" name="waktu_selesai" class="form-control"></div></div>
</div>
<div class="row">
<div class="col-md-3"><div class="form-group"><label>Kegiatan *</label><textarea id="add_kegiatan" name="kegiatan" class="form-control" rows="3" readonly required placeholder="Pilih Program dulu"></textarea></div></div>
<div class="col-md-3"><div class="form-group"><label>Tujuan</label><input type="text" id="add_tujuan" name="tujuan" class="form-control" readonly placeholder="Auto"></div></div>
<div class="col-md-3"><div class="form-group"><label>Indikator Keberhasilan</label><input type="text" id="add_indikator" name="indikator" class="form-control" readonly placeholder="Auto"></div></div>
<div class="col-md-3"><div class="form-group"><label>Target</label><input type="text" id="add_target" name="target" class="form-control" placeholder="cth: 100%, 90%, selesai tepat waktu"></div></div>
</div>

<div class="row">
<div class="col-md-4"><div class="form-group"><label>Penanggung Jawab</label><input type="text" name="penanggung_jawab" class="form-control" placeholder="Nama / jabatan"></div></div>
<div class="col-md-4"><div class="form-group"><label>Anggaran (Rp)</label><input type="text" name="anggaran" class="form-control uang" placeholder="0"></div></div>
<div class="col-md-4"><div class="form-group"><label>Sumber Dana</label><input type="text" name="sumber_dana" class="form-control" placeholder="BOS / BOP / Komite"></div></div>
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button><button type="submit" name="add_program" class="btn btn-primary">Simpan</button></div>
</form>
</div>
</div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog modal-xl" role="document">
<div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Edit Program Kerja</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
<form method="POST" enctype="multipart/form-data" id="editForm">
<input type="hidden" name="id" id="edit_id">
<div class="modal-body">
<div class="row">
<div class="col-md-3"><div class="form-group"><label>Komponen *</label><select name="komponen" id="edit_komponen" class="form-control" required><option value="">-- Pilih --</option><?php foreach($komponen_list as $k=>$v):?><option value="<?=$k?>"><?=$k?>. <?=htmlspecialchars($v['nama'])?></option><?php endforeach;?></select></div></div>
<div class="col-md-3"><div class="form-group"><label>Program *</label><select name="program" id="edit_program_sel" class="form-control" required><option value="">-- Pilih Program --</option></select></div></div>
<div class="col-md-3"><div class="form-group"><label>Waktu Mulai</label><input type="date" name="waktu_mulai" id="edit_waktu_mulai" class="form-control"></div></div>
<div class="col-md-3"><div class="form-group"><label>Waktu Selesai</label><input type="date" name="waktu_selesai" id="edit_waktu_selesai" class="form-control"></div></div>
</div>
<div class="row">
<div class="col-md-3"><div class="form-group"><label>Kegiatan *</label><textarea id="edit_kegiatan" name="kegiatan" class="form-control" rows="3" readonly required placeholder="Pilih Program dulu"></textarea></div></div>
<div class="col-md-3"><div class="form-group"><label>Tujuan</label><input type="text" id="edit_tujuan" name="tujuan" class="form-control" readonly placeholder="Auto"></div></div>
<div class="col-md-3"><div class="form-group"><label>Indikator Keberhasilan</label><input type="text" id="edit_indikator" name="indikator" class="form-control" readonly placeholder="Auto"></div></div>
<div class="col-md-3"><div class="form-group"><label>Target</label><input type="text" name="target" id="edit_target" class="form-control" placeholder="cth: 100%, 90%, selesai tepat waktu"></div></div>
</div>

<div class="row">
<div class="col-md-4"><div class="form-group"><label>Penanggung Jawab</label><input type="text" name="penanggung_jawab" id="edit_pj" class="form-control"></div></div>
<div class="col-md-4"><div class="form-group"><label>Anggaran (Rp)</label><input type="text" name="anggaran" id="edit_anggaran" class="form-control"></div></div>
<div class="col-md-4"><div class="form-group"><label>Sumber Dana</label><input type="text" name="sumber_dana" id="edit_sumber" class="form-control"></div></div>
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button><button type="submit" name="update_program" class="btn btn-primary">Update</button></div>
</form>
</div>
</div>
</div>

<form id="delForm" method="POST" style="display:none"><input type="hidden" name="id" id="del_id"><input type="hidden" name="delete_program" value="1"></form>
<?php endif;?>

<?php include '../templates/footer.php'; ?>
<script>
var matriks=<?=json_encode($matriks ?? [], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)?>;

function idxByProgram(k, prog){ var list=matriks[k]||matriks[String(k)]||[]; for(var i=0;i<list.length;i++) if((list[i].program||'').trim()===String(prog).trim()) return i; return -1; }
function syncMatriksAdd(){
 var k=$('#addModal select[name="komponen"]').val(); var prog=$('#add_program_sel').val();
 if(!k || !prog){ $('#add_kegiatan,#add_tujuan,#add_indikator,#add_target').val('');  return; }
 var idx=idxByProgram(k,prog); if(idx<0) return;
 var m=matriks[k][idx]||matriks[String(k)][idx];
 $('#add_kegiatan').val(m.kegiatan||'');
 $('#add_tujuan').val(m.tujuan||'');
 $('#add_indikator').val(m.indikator||''); if(!$('#add_target').val()) $('#add_target').val(m.target||'');
}
function syncMatriksEdit(){
 var k=$('#edit_komponen').val(); var prog=$('#edit_program_sel').val();
 if(!k || !prog){ $('#edit_kegiatan,#edit_tujuan,#edit_indikator,#edit_target').val('');  return; }
 var idx=idxByProgram(k,prog); if(idx<0) return;
 var m=matriks[k][idx]||matriks[String(k)][idx];
 $('#edit_kegiatan').val(m.kegiatan||'');
 $('#edit_tujuan').val(m.tujuan||'');
 $('#edit_indikator').val(m.indikator||''); $('#edit_target').val(m.target||'');
}
function fillProgram(k, selId, cur){
 var $s=$(selId); $s.empty().append('<option value="">-- Pilih Program --</option>');
 var list=matriks[k]||matriks[String(k)]||[];
 list.forEach(function(r){ $s.append($('<option>').val(r.program).text(r.program)); });
 if(cur){
  if($s.find('option').filter(function(){return $(this).val()===cur;}).length===0) $s.append($('<option>').val(cur).text(cur));
  $s.val(cur);
 }
}
 $(document).on('change','#addModal select[name="komponen"]',function(){ var k=$(this).val(); fillProgram(k,'#add_program_sel',''); $('#add_kegiatan,#add_tujuan,#add_indikator,#add_target').val('');  });
 $(document).on('change','#edit_komponen',function(){ var k=$(this).val(); fillProgram(k,'#edit_program_sel',''); $('#edit_kegiatan,#edit_tujuan,#edit_indikator,#edit_target').val('');  });
$(document).on('change','#add_program_sel',syncMatriksAdd);
$(document).on('change','#edit_program_sel',syncMatriksEdit);
function fmtRupiah(v){
 var s=(v+'').trim();
 if(/^\d+\.\d{1,2}$/.test(s)) s=s.split('.')[0];
 s=s.replace(/\D/g,'').replace(/^0+(?=\d)/,'');
 return s? s.replace(/\B(?=(\d{3})+(?!\d))/g,".") : '';
}
function bindRupiah(sel){ $(document).on('input', sel, function(){ var p=this.selectionStart, l=this.value.length; this.value=fmtRupiah(this.value); }); $(document).on('blur', sel, function(){ this.value=fmtRupiah(this.value); }); }
$(document).ready(function(){
 bindRupiah('input[name="anggaran"]'); bindRupiah('#edit_anggaran');
 $('form').on('submit', function(){ $(this).find('input[name="anggaran"]').each(function(){ this.value=this.value.replace(/\D/g,''); }); });
 var t=$('#table-proker').DataTable({scrollX:true, autoWidth:false, paging:true, pageLength:10, language:{search:"Cari:", lengthMenu:"Tampilkan _MENU_", zeroRecords:"Tidak ada data", info:"Menampilkan _START_ - _END_ dari _TOTAL_", paginate:{first:"Awal",last:"Akhir",next:"Next",previous:"Prev"}}});
 <?php if($message):?>Swal.fire({icon:'<?= $message['type']=='success'?'success':'error'?>',title:'<?= addslashes($message['text'])?>', timer:2000, showConfirmButton:false});<?php endif;?>
 $(document).on('click','.btn-edit',function(){
  var r=$(this).data('row'); if(typeof r==='string') try{r=JSON.parse(r)}catch(e){r=$(this).attr('data-row'); r=JSON.parse(r)}
  $('#edit_id').val(r.id); $('#edit_komponen').val(r.komponen); fillProgram(r.komponen,'#edit_program_sel',r.program);
  syncMatriksEdit();
  $('#edit_waktu_mulai').val(r.waktu_mulai||r.waktu||''); $('#edit_waktu_selesai').val(r.waktu_selesai||'');
  $('#edit_pj').val(r.penanggung_jawab); $('#edit_anggaran').val(fmtRupiah(r.anggaran)); $('#edit_sumber').val(r.sumber_dana);
  $('#editModal').modal('show');
 });
  $(document).on('click','.btn-del',function(){
  var id=$(this).data('id'), prog=$(this).data('prog');
  Swal.fire({title:'Hapus?',text:'Hapus program "'+prog+'"?',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',confirmButtonText:'Ya, Hapus'}).then(function(res){ if(res.isConfirmed){ $('#del_id').val(id); $('#delForm').submit(); }});
 });
  function buildUrl(k){ var p=[]; if(k&&k!='0') p.push('komponen='+k); return p.length?'?'+p.join('&'):location.pathname; }
 $('#filter-komponen').on('change',function(){ location.href=buildUrl($('#filter-komponen').val()); });
});
</script>
