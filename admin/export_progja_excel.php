<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/program_kerja_master.php';
if (!isAuthorized(['admin','kepala_madrasah'])) die('Unauthorized');
$school_profile=getSchoolProfile($pdo);
$school_name=strtoupper($school_profile['nama_madrasah']??'Sistem Informasi Madrasah');
$foundation_name=strtoupper($school_profile['nama_yayasan']??'');
$school_address=$school_profile['alamat']??'';
$tahun_ajaran=$school_profile['tahun_ajaran']?? date('Y').'/'.(date('Y')+1);
$filename_tahun=str_replace('/','-',$tahun_ajaran);
$komponen_list=prokerGetKomponenNama($pdo);
$status_opts=['belum_terlaksana'=>'Belum Terlaksana','proses'=>'Proses','terlaksana'=>'Terlaksana'];
$komp=(int)($_GET['komponen']??0); $st=trim($_GET['status']??''); if(!isset($status_opts[$st])) $st='';
$where=[]; $params=[];
if($komp>=1){$where[]="komponen=?"; $params[]=$komp;}
if($st!==''){ $where[]="status=?"; $params[]=$st; }
if($where){$stmt=$pdo->prepare("SELECT * FROM tb_program_kerja WHERE ".implode(' AND ',$where)." ORDER BY komponen ASC, id ASC"); $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);} else {$rows=$pdo->query("SELECT * FROM tb_program_kerja ORDER BY komponen ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);}
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Program_Kerja_".$filename_tahun.".xls");
header("Pragma: no-cache"); header("Expires: 0");
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif}table{border-collapse:collapse;width:100%}th,td{border:1px solid #000;padding:4px;vertical-align:top;font-size:11px}th{background:#f0f0f0;text-align:center;font-weight:bold}.text-center{text-align:center}.text-right{text-align:right}.bold{font-weight:bold}.header-title{font-size:14px;font-weight:bold;text-align:center;border:none}.header-text{text-align:center;border:none}.no-border{border:none}</style></head><body>
<table>
<tr><td colspan="12" class="header-title"><?=htmlspecialchars($foundation_name)?></td></tr>
<tr><td colspan="12" class="header-title"><?=htmlspecialchars($school_name)?></td></tr>
<tr><td colspan="12" class="header-text"><?=htmlspecialchars($school_address)?></td></tr>
<tr><td colspan="12" class="header-text bold">TAHUN AJARAN <?=htmlspecialchars($tahun_ajaran)?></td></tr>
<tr><td colspan="12" class="no-border"></td></tr>
<tr><td colspan="12" class="header-title" style="text-decoration:underline">PROGRAM KERJA MADRASAH</td></tr>
<?php if($komp):?><tr><td colspan="12" class="header-text">Filter: Komponen: <?=htmlspecialchars($komponen_list[$komp]??'-')?></td></tr><?php endif;?>
<tr><td colspan="12" class="no-border"></td></tr>
<tr><th>No</th><th>Komponen</th><th>Program</th><th>Kegiatan</th><th>Tujuan</th><th>Indikator Keberhasilan</th><th>Target</th><th>Waktu Mulai</th><th>Waktu Selesai</th><th>Penanggung Jawab</th><th>Anggaran</th><th>Sumber Dana</th></tr>
<?php $no=1; foreach($rows as $r):?>
<tr>
<td class="text-center"><?=$no++?></td>
<td><?=$r['komponen']?>. <?=htmlspecialchars($komponen_list[(int)$r['komponen']]??'-')?></td>
<td><?=htmlspecialchars($r['program'])?></td>
<td><?=htmlspecialchars($r['kegiatan'])?></td>
<td><?=htmlspecialchars($r['tujuan']??'-')?></td>
<td><?=htmlspecialchars($r['indikator']??'-')?></td>
<td><?=htmlspecialchars($r['target']??'-')?></td>
<td class="text-center"><?=!empty($r['waktu_mulai'])?date('d-m-Y',strtotime($r['waktu_mulai'])):(!empty($r['waktu'])?htmlspecialchars($r['waktu']):'-')?></td>
<td class="text-center"><?=!empty($r['waktu_selesai'])?date('d-m-Y',strtotime($r['waktu_selesai'])):'-'?></td>
<td><?=htmlspecialchars($r['penanggung_jawab']??'-')?></td>
<td class="text-right"><?= $r['anggaran']>0?number_format($r['anggaran'],0,',','.'): '-'?></td>
<td><?=htmlspecialchars($r['sumber_dana']??'-')?></td>
</tr>
<?php endforeach;?>
</table>
</body></html>
