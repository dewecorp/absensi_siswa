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
$komponen_list=prokerGetKomponenList($pdo);
$matriks=prokerGetMatriks($pdo);
$komp=(int)($_GET['komponen']??0);
if($komp>=1){ $komponen_list = isset($komponen_list[$komp]) ? [$komp=>$komponen_list[$komp]] : []; }
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Komponen_Program_Kerja_".$filename_tahun.".xls");
header("Pragma: no-cache"); header("Expires: 0");
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif}table{border-collapse:collapse;width:100%}th,td{border:1px solid #000;padding:4px;vertical-align:top;font-size:11px}th{background:#f0f0f0;text-align:center;font-weight:bold}.text-center{text-align:center}.bold{font-weight:bold}.header-title{font-size:14px;font-weight:bold;text-align:center;border:none}.header-text{text-align:center;border:none}.no-border{border:none}.grup{background:#e8eefc;text-align:left;font-weight:bold}</style></head><body>
<table>
<tr><td colspan="7" class="header-title"><?=htmlspecialchars($foundation_name)?></td></tr>
<tr><td colspan="7" class="header-title"><?=htmlspecialchars($school_name)?></td></tr>
<tr><td colspan="7" class="header-text"><?=htmlspecialchars($school_address)?></td></tr>
<tr><td colspan="7" class="header-text bold">TAHUN AJARAN <?=htmlspecialchars($tahun_ajaran)?></td></tr>
<tr><td colspan="7" class="no-border"></td></tr>
<tr><td colspan="7" class="header-title" style="text-decoration:underline">KOMPONEN &amp; PROGRAM KERJA MADRASAH</td></tr>
<tr><td colspan="7" class="no-border"></td></tr>
<tr><th style="width:40px">No</th><th>Program</th><th>Kegiatan</th><th>Tujuan</th><th>Indikator Keberhasilan</th><th>Evaluasi</th><th>Tindak Lanjut</th></tr>
<?php if(empty($komponen_list)):?>
<tr><td colspan="7" class="text-center">Tidak ada data</td></tr>
<?php else: foreach($komponen_list as $k=>$v): $m=$matriks[$k]??[];?>
<tr><td colspan="7" class="grup">Komponen <?=htmlspecialchars($k)?>. <?=htmlspecialchars($v['nama'])?><?=trim((string)($v['ruang']??''))!==''?' — '.htmlspecialchars($v['ruang']):''?></td></tr>
<?php if(empty($m)):?>
<tr><td class="text-center">-</td><td colspan="6">Belum ada program</td></tr>
<?php else: $no=1; foreach($m as $r):?>
<tr>
<td class="text-center"><?=$no++?></td>
<td><?=htmlspecialchars($r['program'])?></td>
<td><?=htmlspecialchars($r['kegiatan'])?></td>
<td><?=htmlspecialchars($r['tujuan']??'')?></td>
<td><?=htmlspecialchars($r['indikator']??'')?></td>
<td><?=htmlspecialchars($r['evaluasi']??'')?></td>
<td><?=htmlspecialchars($r['tindak']??'')?></td>
</tr>
<?php endforeach; endif; endforeach; endif;?>
</table>
</body></html>
