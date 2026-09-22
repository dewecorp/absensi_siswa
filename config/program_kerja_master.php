<?php
// Master Komponen + Detail Program Kerja - sumber tunggal dinamis dari database.
// Dipakai oleh: kepala/program_kerja.php, admin/program_kerja.php,
// kepala/komponen_program_kerja.php, cetak & export.
// Tidak ada data default yang di-hardcode. Semua data diambil dari tabel.

if (!function_exists('prokerEnsureMaster')) {
function prokerEnsureMaster(PDO $pdo): void {
 try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS tb_program_kerja_master_komponen (id_komponen INT PRIMARY KEY AUTO_INCREMENT, nomor INT NOT NULL UNIQUE, nama VARCHAR(200) NOT NULL, ruang TEXT, status VARCHAR(20) NOT NULL DEFAULT 'Aktif', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  try { $c=$pdo->query("SHOW COLUMNS FROM tb_program_kerja_master_komponen")->fetchAll(PDO::FETCH_COLUMN,0); if(!in_array('status',$c)) $pdo->exec("ALTER TABLE tb_program_kerja_master_komponen ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Aktif' AFTER ruang"); } catch(Throwable $e){}
  $pdo->exec("CREATE TABLE IF NOT EXISTS tb_program_kerja_master_detail (id INT PRIMARY KEY AUTO_INCREMENT, komponen INT NOT NULL, program VARCHAR(255) NOT NULL, kegiatan TEXT NOT NULL, tujuan TEXT, indikator TEXT, target VARCHAR(100) NULL, evaluasi TEXT, tindak_lanjut TEXT, urutan INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX(komponen), INDEX(program)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 } catch(Throwable $e){ error_log('prokerEnsureMaster: '.$e->getMessage()); }
}
}

if (!function_exists('prokerGetKomponenList')) {
function prokerGetKomponenList(PDO $pdo): array {
 $out=[];
 try {
  $rows=$pdo->query("SELECT nomor,nama,ruang FROM tb_program_kerja_master_komponen WHERE status='Aktif' ORDER BY nomor ASC")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r){ $out[(int)$r['nomor']]=['nama'=>$r['nama'],'ruang'=>$r['ruang']]; }
 } catch(Throwable $e){}
 return $out;
}
}

if (!function_exists('prokerGetKomponenNama')) {
function prokerGetKomponenNama(PDO $pdo): array {
 $out=[];
 try {
  $rows=$pdo->query("SELECT nomor,nama FROM tb_program_kerja_master_komponen ORDER BY nomor ASC")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r){ $out[(int)$r['nomor']]=$r['nama']; }
 } catch(Throwable $e){}
 return $out;
}
}

if (!function_exists('prokerGetMatriks')) {
function prokerGetMatriks(PDO $pdo): array {
 $m=[];
 try {
  $rows=$pdo->query("SELECT id,komponen,program,kegiatan,tujuan,indikator,target,evaluasi,tindak_lanjut FROM tb_program_kerja_master_detail ORDER BY komponen ASC, urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r){
   $k=(int)$r['komponen'];
   $m[$k][]=['id'=>(int)$r['id'],'program'=>$r['program'],'kegiatan'=>$r['kegiatan'],'tujuan'=>$r['tujuan'],'indikator'=>$r['indikator'],'target'=>$r['target']??'','evaluasi'=>$r['evaluasi'],'tindak'=>$r['tindak_lanjut']];
  }
 } catch(Throwable $e){}
 return $m;
}
}
