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
$kepala_madrasah=$school_profile['nama_kepala']??$school_profile['kepala_madrasah']??'.........................';
$nip_kepala=$school_profile['nip_kepala']??'-';
$logo_path='../assets/img/'.($school_profile['logo']??'logo.png');
$months=['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
$tempat=$school_profile['tempat_jadwal']??'Tempat';
$tanggal=$tempat.', '.date('d').' '.$months[date('F')].' '.date('Y');
$qr_content="Ditandatangani secara elektronik oleh:\n".$kepala_madrasah."\nKepala Madrasah\nTanggal: ".date('d F Y');
$qr_url="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=".urlencode($qr_content);
$komponen_list=prokerGetKomponenNama($pdo);
$status_opts=['belum_terlaksana'=>'Belum Terlaksana','proses'=>'Proses','terlaksana'=>'Terlaksana'];
$komp=(int)($_GET['komponen']??0); $st=trim($_GET['status']??''); if(!isset($status_opts[$st])) $st='';
$where=[]; $params=[];
if($komp>=1){$where[]="komponen=?"; $params[]=$komp;}
if($st!==''){ $where[]="status=?"; $params[]=$st; }
if($where){$stmt=$pdo->prepare("SELECT * FROM tb_program_kerja WHERE ".implode(' AND ',$where)." ORDER BY komponen ASC, id ASC"); $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);} else {$rows=$pdo->query("SELECT * FROM tb_program_kerja ORDER BY komponen ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);}
$filename_tahun=str_replace('/','-', $tahun_ajaran);
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Cetak Program Kerja - <?=htmlspecialchars($filename_tahun)?></title>
<style>
body{font-family:Arial,sans-serif;font-size:10px;margin:15px}
@media print{.no-print{display:none} @page{margin:0.8cm; size:A4 landscape}}
.header{display:flex;align-items:center;justify-content:center;padding-bottom:8px;border-bottom:2px solid #000;position:relative}
.header h1{margin:0;font-size:16px} .header h2{margin:0;font-size:13px}
.title{text-align:center;font-weight:bold;font-size:13px;margin:12px 0 10px;text-decoration:underline}
table{width:100%;border-collapse:collapse;margin-bottom:12px}
th,td{border:1px solid #000;padding:3px 4px;vertical-align:top;font-size:9px}
th{background:#f0f0f0;text-align:center;font-weight:bold}
.text-center{text-align:center} .text-right{text-align:right} .bold{font-weight:bold}
.ttd-box{margin-top:18px;display:flex;justify-content:space-between;page-break-inside:avoid}
.ttd-item{text-align:center;width:30%} .ttd-space{height:65px}
.small{font-size:8px;color:#555}
</style></head>
<body onload="window.print()">
<div class="no-print" style="margin-bottom:12px;text-align:right">
<button onclick="window.print()" style="padding:8px 16px;background:#007bff;color:#fff;border:none;cursor:pointer">Cetak / Simpan PDF</button>
<button onclick="window.location.reload()" style="padding:8px 16px;background:#6c757d;color:#fff;border:none;cursor:pointer">Muat Ulang</button>
<button onclick="window.close()" style="padding:8px 16px;background:#dc3545;color:#fff;border:none;cursor:pointer">Tutup</button>
</div>
<div class="header">
<div style="position:absolute;left:0"><img src="<?=$logo_path?>" alt="Logo" style="height:50px"></div>
<div style="text-align:center;width:100%">
<h2><?=htmlspecialchars($foundation_name)?></h2>
<h1><?=htmlspecialchars($school_name)?></h1>
<p style="margin:2px 0 0;font-size:11px"><?=htmlspecialchars($school_address)?></p>
<p style="margin:2px 0 0;font-size:11px;font-weight:bold">TAHUN AJARAN <?=htmlspecialchars($tahun_ajaran)?></p>
</div>
</div>
<div class="title">PROGRAM KERJA MADRASAH</div>
<?php if($komp):?><p class="text-center" style="font-size:10px">Filter: Komponen: <?=htmlspecialchars($komponen_list[$komp]??'-')?></p><?php endif;?>
<table>
<thead><tr><th>No</th><th>Komponen</th><th>Program</th><th>Kegiatan</th><th>Tujuan</th><th>Indikator</th><th>Target</th><th>Waktu</th><th>PJ</th><th>Anggaran</th><th>Sumber Dana</th></tr></thead>
<tbody>
<?php $no=1; foreach($rows as $r): $w='-'; if(!empty($r['waktu_mulai'])) $w=date('d-m-Y',strtotime($r['waktu_mulai'])); if(!empty($r['waktu_selesai'])) $w.=($w!='-'?' s/d ':'').date('d-m-Y',strtotime($r['waktu_selesai'])); if($w==='-'&&!empty($r['waktu'])) $w=htmlspecialchars($r['waktu']);?>
<tr>
<td class="text-center"><?=$no++?></td>
<td><?=$r['komponen']?>. <?=htmlspecialchars($komponen_list[(int)$r['komponen']]??'-')?></td>
<td><?=htmlspecialchars($r['program'])?></td>
<td><?=htmlspecialchars($r['kegiatan'])?></td>
<td><?=htmlspecialchars($r['tujuan']??'-')?></td>
<td><?=htmlspecialchars($r['indikator']??'-')?></td>
<td class="text-center"><?=htmlspecialchars($r['target']??'-')?></td>
<td class="text-center"><?=htmlspecialchars($w)?></td>
<td><?=htmlspecialchars($r['penanggung_jawab']??'-')?></td>
<td class="text-right"><?= $r['anggaran']>0?'Rp '.number_format($r['anggaran'],0,',','.'): '-'?></td>
<td><?=htmlspecialchars($r['sumber_dana']??'-')?></td>
</tr>
<?php endforeach;?>
<?php if(empty($rows)):?><tr><td colspan="11" class="text-center">Tidak ada data</td></tr><?php endif;?>
</tbody>
</table>
<p class="small">Total data: <?=count($rows)?> | Dicetak: <?=date('d-m-Y H:i')?></p>
<div class="ttd-box">
<div class="ttd-item"></div>
<div class="ttd-item">
<p style="margin-bottom:4px"><?=htmlspecialchars($tanggal)?></p>
<p>Mengetahui,<br>Kepala Madrasah</p>
<div class="ttd-space"><img src="<?=$qr_url?>" alt="QR" style="height:65px"></div>
<p class="bold" style="margin-bottom:0"><?=htmlspecialchars($kepala_madrasah)?></p>
<?php if($nip_kepala!=='-'):?><p style="margin-top:2px">NIP. <?=htmlspecialchars($nip_kepala)?></p><?php endif;?>
</div>
</div>
</body></html>
