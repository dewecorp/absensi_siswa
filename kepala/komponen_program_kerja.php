<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin','kepala_madrasah'])) redirect('../login.php');

$user_level = getUserLevel();
$page_title = 'Komponen Program Kerja';

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y').'/'.(date('Y')+1);
ensureTbJabatanMaster($pdo);

$_komponen_default = [
 1=>['nama'=>'Manajemen dan Kepemimpinan','ruang'=>'Visi-misi, tujuan madrasah, kebijakan, pembagian tugas, koordinasi, rapat, pengambilan keputusan'],
 2=>['nama'=>'Kurikulum dan Pembelajaran','ruang'=>'Kurikulum madrasah, perangkat ajar, jadwal, asesmen, supervisi pembelajaran, pengembangan pembelajaran'],
 3=>['nama'=>'Kesiswaan','ruang'=>'PPDB, data siswa, tata tertib, pembinaan karakter, ekstrakurikuler, prestasi, kehadiran, layanan siswa'],
 4=>['nama'=>'Pendidik dan Tenaga Kependidikan','ruang'=>'Pembagian tugas, disiplin, pengembangan kompetensi, PKG, supervisi guru, pelatihan, kesejahteraan'],
 5=>['nama'=>'Keuangan dan Penganggaran','ruang'=>'RKAM, RAPBM/RKAM, BOS/BOP, pemasukan-pengeluaran, laporan keuangan, pengendalian anggaran'],
 6=>['nama'=>'Sarana dan Prasarana','ruang'=>'Inventaris, pemeliharaan gedung, ruang kelas, perpustakaan, laboratorium, fasilitas pembelajaran'],
 7=>['nama'=>'Administrasi dan Tata Usaha','ruang'=>'Persuratan, arsip, dokumen madrasah, data EMIS/administrasi, buku administrasi, legalitas'],
 8=>['nama'=>'Keagamaan dan Pembentukan Karakter','ruang'=>'Pembiasaan keagamaan, akhlak, ibadah, budaya madrasah, moderasi dan nilai-nilai Islam'],
 9=>['nama'=>'Hubungan Masyarakat','ruang'=>'Komite, wali siswa, yayasan, masyarakat, instansi pemerintah, komunikasi dan publikasi madrasah'],
 10=>['nama'=>'Pengembangan Mutu Madrasah','ruang'=>'Evaluasi diri, indikator mutu, program peningkatan mutu, monitoring dan evaluasi'],
 11=>['nama'=>'Supervisi dan Evaluasi','ruang'=>'Supervisi akademik/manajerial, monitoring program, evaluasi kinerja, tindak lanjut'],
 12=>['nama'=>'Ekstrakurikuler dan Prestasi','ruang'=>'Pramuka, olahraga, seni, keagamaan, olimpiade, kompetisi dan pembinaan prestasi'],
 13=>['nama'=>'Digitalisasi Madrasah','ruang'=>'Sistem informasi, digitalisasi administrasi, website, database, keamanan data dan teknologi pembelajaran'],
 14=>['nama'=>'Kemitraan dan Pengembangan Madrasah','ruang'=>'Kerja sama dengan pihak luar, program unggulan, inovasi dan pengembangan sumber daya'],
 15=>['nama'=>'Manajemen Risiko dan Keamanan','ruang'=>'Keselamatan siswa, keamanan lingkungan, perlindungan data, kesiapsiagaan dan penanganan masalah'],
];

try {
 $pdo->exec("CREATE TABLE IF NOT EXISTS tb_program_kerja_master_komponen (
  id_komponen INT PRIMARY KEY AUTO_INCREMENT,
  nomor INT NOT NULL UNIQUE,
  nama VARCHAR(200) NOT NULL,
  ruang TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'Aktif',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX(status)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 try {
  $colsExist = $pdo->query("SHOW COLUMNS FROM tb_program_kerja_master_komponen")->fetchAll(PDO::FETCH_COLUMN,0);
  if(!in_array('status',$colsExist)) $pdo->exec("ALTER TABLE tb_program_kerja_master_komponen ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Aktif' AFTER ruang");
 } catch(Throwable $e){}
 $cekK = $pdo->query("SELECT COUNT(*) FROM tb_program_kerja_master_komponen")->fetchColumn();
 if($cekK==0){
  $stmt=$pdo->prepare("INSERT INTO tb_program_kerja_master_komponen (nomor,nama,ruang,status) VALUES (?,?,?,'Aktif')");
  foreach($_komponen_default as $no=>$d){ $stmt->execute([$no,$d['nama'],$d['ruang']]); }
 }
} catch(Throwable $e){ error_log($e->getMessage()); }

$komponen_list = [];
try {
 $rowsK=$pdo->query("SELECT nomor, nama, ruang, status FROM tb_program_kerja_master_komponen WHERE status='Aktif' ORDER BY nomor ASC")->fetchAll(PDO::FETCH_ASSOC);
 foreach($rowsK as $r){ $komponen_list[(int)$r['nomor']]=['nama'=>$r['nama'],'ruang'=>$r['ruang']]; }
} catch(Throwable $e){}
if(empty($komponen_list)){
 foreach($_komponen_default as $no=>$d){ $komponen_list[(int)$no]=$d; }
}
$_komponen_master_by_nomor = [];
try {
 $rowsK2=$pdo->query("SELECT id_komponen, nomor, nama, ruang, status FROM tb_program_kerja_master_komponen ORDER BY nomor ASC")->fetchAll(PDO::FETCH_ASSOC);
 foreach($rowsK2 as $r){ $_komponen_master_by_nomor[(int)$r['nomor']] = ['id_komponen'=>(int)$r['id_komponen'],'nama'=>$r['nama'],'ruang'=>$r['ruang'],'status'=>$r['status']]; }
} catch(Throwable $e){ $_komponen_master_by_nomor=[]; }

$matriks = [
1=>[
 ['program'=>'Perencanaan dan pengelolaan madrasah','kegiatan'=>'Menyusun program kerja kepala madrasah','tujuan'=>'Menjadi pedoman pelaksanaan seluruh program madrasah','indikator'=>'Program kerja tersusun, disahkan, dan tersosialisasi','target'=>'100%','evaluasi'=>'Membandingkan rencana dengan pelaksanaan','tindak'=>'Revisi program yang belum sesuai'],
 ['program'=>'Tata kelola madrasah','kegiatan'=>'Penataan struktur organisasi dan pembagian tugas','tujuan'=>'Mewujudkan pembagian tugas yang jelas','indikator'=>'Seluruh PTK memiliki tugas dan tanggung jawab','target'=>'100%','evaluasi'=>'Mengevaluasi kesesuaian tugas dan pelaksanaan','tindak'=>'Penyesuaian pembagian tugas'],
 ['program'=>'Koordinasi','kegiatan'=>'Rapat koordinasi rutin','tujuan'=>'Meningkatkan koordinasi antarbidang','indikator'=>'Rapat terlaksana sesuai jadwal dan menghasilkan keputusan','target'=>'≥90%','evaluasi'=>'Memeriksa notulen dan tindak lanjut','tindak'=>'Menindaklanjuti keputusan rapat'],
 ['program'=>'Monitoring','kegiatan'=>'Monitoring program kerja','tujuan'=>'Mengetahui perkembangan pelaksanaan program','indikator'=>'Program terpantau secara berkala','target'=>'≥90%','evaluasi'=>'Membandingkan target dan realisasi','tindak'=>'Pendampingan terhadap program yang tertinggal'],
 ['program'=>'Evaluasi manajemen','kegiatan'=>'Evaluasi kinerja madrasah','tujuan'=>'Mengetahui efektivitas pengelolaan madrasah','indikator'=>'Evaluasi terlaksana dan menghasilkan rekomendasi','target'=>'100%','evaluasi'=>'Analisis capaian program','tindak'=>'Menetapkan program perbaikan'],
],
2=>[
 ['program'=>'Pengembangan kurikulum','kegiatan'=>'Review dan pengembangan kurikulum madrasah','tujuan'=>'Menyesuaikan kurikulum dengan kebutuhan madrasah dan peserta didik','indikator'=>'Dokumen kurikulum tersedia dan diperbarui','target'=>'100%','evaluasi'=>'Menelaah kesesuaian dokumen dan pelaksanaan','tindak'=>'Penyempurnaan dokumen'],
 ['program'=>'Perencanaan pembelajaran','kegiatan'=>'Pemeriksaan perangkat pembelajaran','tujuan'=>'Menjamin kesiapan guru dalam melaksanakan pembelajaran','indikator'=>'Perangkat pembelajaran tersedia dan sesuai','target'=>'100%','evaluasi'=>'Pemeriksaan administrasi guru','tindak'=>'Pendampingan guru yang belum lengkap'],
 ['program'=>'Pelaksanaan pembelajaran','kegiatan'=>'Supervisi dan observasi pembelajaran','tujuan'=>'Meningkatkan kualitas proses pembelajaran','indikator'=>'Guru melaksanakan pembelajaran sesuai perencanaan','target'=>'≥90%','evaluasi'=>'Observasi kelas menggunakan instrumen','tindak'=>'Pembinaan dan supervisi ulang'],
 ['program'=>'Asesmen','kegiatan'=>'Pelaksanaan dan analisis asesmen','tujuan'=>'Mengetahui pencapaian kompetensi siswa','indikator'=>'Asesmen terlaksana dan hasilnya dianalisis','target'=>'100%','evaluasi'=>'Analisis hasil belajar','tindak'=>'Remedial, pengayaan, dan perbaikan pembelajaran'],
 ['program'=>'Pembelajaran inovatif','kegiatan'=>'Pengembangan media dan metode pembelajaran','tujuan'=>'Meningkatkan keterlibatan siswa','indikator'=>'Guru menggunakan metode/media yang sesuai','target'=>'≥90%','evaluasi'=>'Observasi dan refleksi pembelajaran','tindak'=>'Workshop dan pendampingan'],
 ['program'=>'KBC dan karakter','kegiatan'=>'Integrasi Kurikulum Berbasis Cinta dan karakter','tujuan'=>'Membentuk pembelajaran yang menumbuhkan karakter dan nilai keislaman','indikator'=>'Nilai karakter terintegrasi dalam pembelajaran','target'=>'≥90%','evaluasi'=>'Observasi perangkat dan praktik pembelajaran','tindak'=>'Penguatan praktik pembelajaran'],
],
3=>[
 ['program'=>'Penerimaan siswa','kegiatan'=>'Pelaksanaan PPDB','tujuan'=>'Mendapatkan peserta didik sesuai ketentuan','indikator'=>'PPDB terlaksana tertib dan terdokumentasi','target'=>'100%','evaluasi'=>'Evaluasi proses dan hasil PPDB','tindak'=>'Perbaikan mekanisme PPDB'],
 ['program'=>'Administrasi siswa','kegiatan'=>'Pemutakhiran data siswa','tujuan'=>'Menjamin data siswa akurat','indikator'=>'Data siswa diperbarui secara berkala','target'=>'100%','evaluasi'=>'Pemeriksaan database','tindak'=>'Perbaikan data'],
 ['program'=>'Kedisiplinan','kegiatan'=>'Pembinaan tata tertib siswa','tujuan'=>'Meningkatkan kedisiplinan','indikator'=>'Pelanggaran siswa menurun','target'=>'≥90%','evaluasi'=>'Rekap pelanggaran','tindak'=>'Pembinaan individual/kelompok'],
 ['program'=>'Karakter','kegiatan'=>'Pembinaan karakter siswa','tujuan'=>'Membentuk siswa berakhlak dan bertanggung jawab','indikator'=>'Program pembinaan terlaksana','target'=>'≥90%','evaluasi'=>'Observasi dan catatan perkembangan','tindak'=>'Program pembinaan lanjutan'],
 ['program'=>'Prestasi','kegiatan'=>'Pembinaan siswa berprestasi','tujuan'=>'Mengembangkan potensi siswa','indikator'=>'Siswa mengikuti dan memperoleh prestasi','target'=>'100%','evaluasi'=>'Rekap prestasi','tindak'=>'Pembinaan intensif'],
 ['program'=>'Perlindungan siswa','kegiatan'=>'Penanganan masalah siswa','tujuan'=>'Memberikan layanan terhadap permasalahan siswa','indikator'=>'Kasus ditangani dan terdokumentasi','target'=>'100%','evaluasi'=>'Evaluasi penyelesaian kasus','tindak'=>'Pendampingan dan koordinasi dengan wali'],
],
4=>[
 ['program'=>'Pembagian tugas','kegiatan'=>'Penyusunan pembagian tugas PTK','tujuan'=>'Menjamin tugas sesuai kebutuhan dan kompetensi','indikator'=>'Seluruh PTK memiliki tugas jelas','target'=>'100%','evaluasi'=>'Evaluasi beban dan pelaksanaan tugas','tindak'=>'Penyesuaian tugas'],
 ['program'=>'Kedisiplinan','kegiatan'=>'Monitoring kehadiran dan kedisiplinan','tujuan'=>'Meningkatkan kedisiplinan PTK','indikator'=>'Kehadiran dan ketepatan waktu meningkat','target'=>'≥90%','evaluasi'=>'Analisis absensi','tindak'=>'Pembinaan bagi yang membutuhkan'],
 ['program'=>'Kinerja','kegiatan'=>'Penilaian kinerja guru','tujuan'=>'Mengetahui capaian kinerja','indikator'=>'Penilaian seluruh guru terlaksana','target'=>'100%','evaluasi'=>'Analisis hasil penilaian','tindak'=>'Pembinaan dan pengembangan'],
 ['program'=>'Kompetensi','kegiatan'=>'Pelatihan/workshop','tujuan'=>'Meningkatkan kompetensi PTK','indikator'=>'PTK mengikuti kegiatan pengembangan','target'=>'≥90%','evaluasi'=>'Evaluasi hasil pelatihan','tindak'=>'Pendampingan penerapan hasil pelatihan'],
 ['program'=>'Profesionalisme','kegiatan'=>'Coaching dan pembinaan','tujuan'=>'Meningkatkan profesionalitas','indikator'=>'Guru menunjukkan perbaikan kinerja','target'=>'≥90%','evaluasi'=>'Evaluasi hasil coaching','tindak'=>'Coaching lanjutan/supervisi ulang'],
],
5=>[
 ['program'=>'Perencanaan anggaran','kegiatan'=>'Penyusunan RKAM','tujuan'=>'Menetapkan kebutuhan dan prioritas anggaran','indikator'=>'RKAM tersusun sesuai kebutuhan','target'=>'100%','evaluasi'=>'Membandingkan rencana dengan kebutuhan','tindak'=>'Revisi prioritas bila diperlukan'],
 ['program'=>'Pengelolaan keuangan','kegiatan'=>'Pencatatan pemasukan dan pengeluaran','tujuan'=>'Mewujudkan administrasi keuangan tertib','indikator'=>'Seluruh transaksi tercatat','target'=>'100%','evaluasi'=>'Pemeriksaan pembukuan','tindak'=>'Perbaikan administrasi'],
 ['program'=>'Pengendalian anggaran','kegiatan'=>'Monitoring realisasi anggaran','tujuan'=>'Mengendalikan penggunaan dana','indikator'=>'Realisasi sesuai rencana','target'=>'≥90%','evaluasi'=>'Membandingkan anggaran dan realisasi','tindak'=>'Pengendalian/pengalihan sesuai ketentuan'],
 ['program'=>'Pelaporan','kegiatan'=>'Laporan keuangan berkala','tujuan'=>'Mewujudkan transparansi dan akuntabilitas','indikator'=>'Laporan tersedia tepat waktu','target'=>'100%','evaluasi'=>'Pemeriksaan laporan dan bukti','tindak'=>'Koreksi dan penyempurnaan'],
 ['program'=>'Efisiensi','kegiatan'=>'Evaluasi penggunaan anggaran','tujuan'=>'Memastikan dana digunakan sesuai prioritas','indikator'=>'Penggunaan dana efektif dan efisien','target'=>'≥90%','evaluasi'=>'Analisis realisasi','tindak'=>'Penyesuaian prioritas anggaran'],
],
6=>[
 ['program'=>'Inventarisasi','kegiatan'=>'Pendataan sarpras','tujuan'=>'Mengetahui kondisi dan jumlah sarpras','indikator'=>'Data inventaris lengkap','target'=>'100%','evaluasi'=>'Pemeriksaan fisik dan data','tindak'=>'Pembaruan inventaris'],
 ['program'=>'Kebutuhan sarpras','kegiatan'=>'Analisis kebutuhan','tujuan'=>'Menentukan prioritas pengadaan','indikator'=>'Daftar kebutuhan tersusun berdasarkan prioritas','target'=>'100%','evaluasi'=>'Membandingkan kebutuhan dan ketersediaan','tindak'=>'Menetapkan prioritas'],
 ['program'=>'Pengadaan','kegiatan'=>'Pengadaan sarpras prioritas','tujuan'=>'Memenuhi kebutuhan pembelajaran','indikator'=>'Sarpras prioritas tersedia','target'=>'≥90%','evaluasi'=>'Evaluasi pemanfaatan','tindak'=>'Pengadaan bertahap'],
 ['program'=>'Pemeliharaan','kegiatan'=>'Perawatan sarpras','tujuan'=>'Menjaga kelayakan sarpras','indikator'=>'Sarpras terawat dan dapat digunakan','target'=>'≥90%','evaluasi'=>'Pemeriksaan berkala','tindak'=>'Perbaikan/pemeliharaan'],
 ['program'=>'Lingkungan','kegiatan'=>'Penataan lingkungan madrasah','tujuan'=>'Mewujudkan lingkungan aman, bersih dan nyaman','indikator'=>'Lingkungan tertata','target'=>'≥90%','evaluasi'=>'Observasi kondisi lingkungan','tindak'=>'Penataan lanjutan'],
],
7=>[
 ['program'=>'Persuratan','kegiatan'=>'Pengelolaan surat masuk/keluar','tujuan'=>'Menjamin administrasi persuratan tertib','indikator'=>'Seluruh surat tercatat dan terarsip','target'=>'100%','evaluasi'=>'Pemeriksaan buku agenda/arsip','tindak'=>'Penataan arsip'],
 ['program'=>'Kearsipan','kegiatan'=>'Penataan arsip','tujuan'=>'Memudahkan pencarian dokumen','indikator'=>'Dokumen tertata dan mudah ditemukan','target'=>'100%','evaluasi'=>'Pemeriksaan arsip','tindak'=>'Digitalisasi/penataan ulang'],
 ['program'=>'Administrasi PTK','kegiatan'=>'Pengelolaan dokumen kepegawaian','tujuan'=>'Menjamin data PTK lengkap','indikator'=>'Dokumen PTK lengkap','target'=>'100%','evaluasi'=>'Audit administrasi','tindak'=>'Melengkapi dokumen'],
 ['program'=>'Administrasi siswa','kegiatan'=>'Pengelolaan dokumen siswa','tujuan'=>'Menjamin data siswa akurat','indikator'=>'Dokumen siswa lengkap','target'=>'100%','evaluasi'=>'Pemeriksaan dokumen','tindak'=>'Pemutakhiran data'],
 ['program'=>'Digitalisasi','kegiatan'=>'Digitalisasi dokumen','tujuan'=>'Meningkatkan efisiensi administrasi','indikator'=>'Dokumen prioritas tersedia dalam bentuk digital','target'=>'≥80%','evaluasi'=>'Pemeriksaan database','tindak'=>'Backup dan pembaruan'],
],
8=>[
 ['program'=>'Budaya religius','kegiatan'=>'Pembiasaan doa dan membaca Al-Qur\'an','tujuan'=>'Membentuk budaya religius','indikator'=>'Pembiasaan terlaksana rutin','target'=>'≥90%','evaluasi'=>'Observasi','tindak'=>'Penguatan pembiasaan'],
 ['program'=>'Ibadah','kegiatan'=>'Pembiasaan ibadah berjamaah','tujuan'=>'Membentuk kedisiplinan beribadah','indikator'=>'Siswa mengikuti kegiatan secara konsisten','target'=>'≥90%','evaluasi'=>'Rekap dan observasi','tindak'=>'Pembinaan siswa'],
 ['program'=>'Akhlak','kegiatan'=>'Pembinaan akhlakul karimah','tujuan'=>'Membentuk perilaku terpuji','indikator'=>'Perilaku positif meningkat','target'=>'≥90%','evaluasi'=>'Observasi dan catatan pembinaan','tindak'=>'Pendampingan'],
 ['program'=>'Karakter','kegiatan'=>'Pembinaan disiplin dan tanggung jawab','tujuan'=>'Membentuk karakter siswa','indikator'=>'Kedisiplinan meningkat','target'=>'≥90%','evaluasi'=>'Analisis pelanggaran','tindak'=>'Pembinaan lanjutan'],
 ['program'=>'Keagamaan','kegiatan'=>'PHBI dan kegiatan keislaman','tujuan'=>'Meningkatkan pemahaman dan pengalaman keagamaan','indikator'=>'Kegiatan terlaksana','target'=>'100%','evaluasi'=>'Evaluasi kegiatan','tindak'=>'Penyempurnaan program'],
],
9=>[
 ['program'=>'Komunikasi wali','kegiatan'=>'Pertemuan dengan wali siswa','tujuan'=>'Meningkatkan komunikasi','indikator'=>'Pertemuan terlaksana dan informasi tersampaikan','target'=>'≥90%','evaluasi'=>'Evaluasi partisipasi dan masukan','tindak'=>'Menindaklanjuti masukan'],
 ['program'=>'Komite','kegiatan'=>'Koordinasi dengan komite','tujuan'=>'Membangun sinergi','indikator'=>'Koordinasi terlaksana','target'=>'≥90%','evaluasi'=>'Evaluasi hasil koordinasi','tindak'=>'Pelaksanaan kesepakatan'],
 ['program'=>'Yayasan','kegiatan'=>'Koordinasi dengan yayasan','tujuan'=>'Menyelaraskan kebijakan madrasah dan yayasan','indikator'=>'Koordinasi terdokumentasi','target'=>'100%','evaluasi'=>'Evaluasi hasil rapat','tindak'=>'Tindak lanjut keputusan'],
 ['program'=>'Publikasi','kegiatan'=>'Pengelolaan website/media sosial','tujuan'=>'Menyampaikan informasi madrasah','indikator'=>'Informasi kegiatan dipublikasikan','target'=>'≥90%','evaluasi'=>'Evaluasi konten dan jangkauan','tindak'=>'Peningkatan publikasi'],
 ['program'=>'Pengaduan','kegiatan'=>'Pengelolaan aspirasi','tujuan'=>'Meningkatkan kualitas pelayanan','indikator'=>'Aspirasi ditangani','target'=>'100%','evaluasi'=>'Evaluasi penyelesaian','tindak'=>'Perbaikan pelayanan'],
],
10=>[
 ['program'=>'Pemetaan mutu','kegiatan'=>'Evaluasi kondisi madrasah','tujuan'=>'Mengetahui kekuatan dan kelemahan','indikator'=>'Pemetaan tersedia','target'=>'100%','evaluasi'=>'Analisis data','tindak'=>'Menentukan prioritas'],
 ['program'=>'Target mutu','kegiatan'=>'Penetapan indikator mutu','tujuan'=>'Menentukan arah peningkatan mutu','indikator'=>'Target mutu terukur','target'=>'100%','evaluasi'=>'Membandingkan target dan capaian','tindak'=>'Revisi target/program'],
 ['program'=>'Peningkatan mutu','kegiatan'=>'Pelaksanaan program peningkatan mutu','tujuan'=>'Meningkatkan kualitas madrasah','indikator'=>'Target prioritas tercapai','target'=>'≥90%','evaluasi'=>'Evaluasi capaian','tindak'=>'Program perbaikan'],
 ['program'=>'Inovasi','kegiatan'=>'Pengembangan program unggulan madrasah','tujuan'=>'Meningkatkan keunggulan madrasah','indikator'=>'Program unggulan terlaksana','target'=>'≥80%','evaluasi'=>'Evaluasi dampak','tindak'=>'Pengembangan berkelanjutan'],
 ['program'=>'Evaluasi','kegiatan'=>'Evaluasi mutu tahunan','tujuan'=>'Mengetahui perkembangan mutu','indikator'=>'Laporan mutu tersedia','target'=>'100%','evaluasi'=>'Analisis capaian tahunan','tindak'=>'Menetapkan program tahun berikutnya'],
],
11=>[
 ['program'=>'Perencanaan supervisi','kegiatan'=>'Penyusunan program supervisi','tujuan'=>'Menjamin supervisi terlaksana sistematis','indikator'=>'Program dan jadwal tersedia','target'=>'100%','evaluasi'=>'Evaluasi kesesuaian jadwal','tindak'=>'Penyesuaian jadwal'],
 ['program'=>'Supervisi administrasi','kegiatan'=>'Pemeriksaan perangkat pembelajaran','tujuan'=>'Meningkatkan kelengkapan administrasi guru','indikator'=>'Administrasi guru memenuhi target','target'=>'100%','evaluasi'=>'Analisis instrumen','tindak'=>'Pendampingan'],
 ['program'=>'Supervisi akademik','kegiatan'=>'Observasi pembelajaran','tujuan'=>'Meningkatkan kualitas pembelajaran','indikator'=>'Guru mencapai standar yang ditetapkan madrasah','target'=>'≥90%','evaluasi'=>'Analisis hasil observasi','tindak'=>'Coaching/pembinaan'],
 ['program'=>'Tindak lanjut','kegiatan'=>'Pembinaan guru','tujuan'=>'Memperbaiki temuan supervisi','indikator'=>'Temuan ditindaklanjuti','target'=>'≥90%','evaluasi'=>'Monitoring tindak lanjut','tindak'=>'Supervisi ulang'],
 ['program'=>'Evaluasi','kegiatan'=>'Analisis hasil supervisi','tujuan'=>'Mengetahui perkembangan guru','indikator'=>'Tersedia rekap hasil supervisi','target'=>'100%','evaluasi'=>'Membandingkan hasil antarperiode','tindak'=>'Program pengembangan kompetensi'],
],
12=>[
 ['program'=>'Pengembangan bakat','kegiatan'=>'Pemetaan minat dan bakat','tujuan'=>'Mengetahui potensi siswa','indikator'=>'Data minat/bakat tersedia','target'=>'100%','evaluasi'=>'Analisis pemetaan','tindak'=>'Penempatan kegiatan sesuai potensi'],
 ['program'=>'Ekstrakurikuler','kegiatan'=>'Pelaksanaan kegiatan ekstrakurikuler','tujuan'=>'Mengembangkan potensi siswa','indikator'=>'Kegiatan terlaksana sesuai jadwal','target'=>'≥90%','evaluasi'=>'Evaluasi kehadiran dan kegiatan','tindak'=>'Perbaikan program'],
 ['program'=>'Pramuka','kegiatan'=>'Pembinaan kepramukaan','tujuan'=>'Membentuk karakter dan keterampilan','indikator'=>'Kegiatan berjalan','target'=>'≥90%','evaluasi'=>'Evaluasi kegiatan','tindak'=>'Penguatan pembinaan'],
 ['program'=>'Kompetisi','kegiatan'=>'Pembinaan lomba/olimpiade','tujuan'=>'Meningkatkan prestasi siswa','indikator'=>'Siswa mengikuti kompetisi','target'=>'≥90%','evaluasi'=>'Evaluasi hasil kompetisi','tindak'=>'Pembinaan lanjutan'],
 ['program'=>'Apresiasi','kegiatan'=>'Penghargaan prestasi','tujuan'=>'Meningkatkan motivasi siswa','indikator'=>'Prestasi terdokumentasi dan diapresiasi','target'=>'100%','evaluasi'=>'Evaluasi pencapaian','tindak'=>'Pengembangan pembinaan'],
],
13=>[
 ['program'=>'Administrasi digital','kegiatan'=>'Digitalisasi administrasi','tujuan'=>'Meningkatkan efisiensi kerja','indikator'=>'Administrasi prioritas terdigitalisasi','target'=>'≥90%','evaluasi'=>'Evaluasi penggunaan','tindak'=>'Pengembangan sistem'],
 ['program'=>'Sistem informasi','kegiatan'=>'Pengembangan aplikasi madrasah','tujuan'=>'Mempermudah pengelolaan data','indikator'=>'Sistem dapat digunakan','target'=>'≥90%','evaluasi'=>'Evaluasi fungsi sistem','tindak'=>'Perbaikan fitur'],
 ['program'=>'Database','kegiatan'=>'Pengelolaan database madrasah','tujuan'=>'Menjamin data terpusat dan akurat','indikator'=>'Database terbarui','target'=>'100%','evaluasi'=>'Pemeriksaan data','tindak'=>'Sinkronisasi data'],
 ['program'=>'Backup','kegiatan'=>'Pencadangan data','tujuan'=>'Mencegah kehilangan data','indikator'=>'Backup dilakukan berkala','target'=>'100%','evaluasi'=>'Pemeriksaan backup','tindak'=>'Penjadwalan backup otomatis'],
 ['program'=>'Keamanan','kegiatan'=>'Pengamanan akun dan data','tujuan'=>'Melindungi data madrasah','indikator'=>'Akses data terkendali','target'=>'100%','evaluasi'=>'Audit akses','tindak'=>'Penguatan keamanan'],
],
14=>[
 ['program'=>'Pemetaan mitra','kegiatan'=>'Identifikasi calon mitra','tujuan'=>'Menemukan pihak yang dapat mendukung madrasah','indikator'=>'Database calon mitra tersedia','target'=>'100%','evaluasi'=>'Evaluasi relevansi mitra','tindak'=>'Menindaklanjuti calon mitra'],
 ['program'=>'Kerja sama','kegiatan'=>'Penyusunan kerja sama','tujuan'=>'Meningkatkan dukungan terhadap program madrasah','indikator'=>'Kerja sama terdokumentasi','target'=>'100%','evaluasi'=>'Evaluasi pelaksanaan','tindak'=>'Perpanjangan/pengembangan'],
 ['program'=>'Pengembangan','kegiatan'=>'Program inovasi madrasah','tujuan'=>'Menghasilkan inovasi sesuai kebutuhan','indikator'=>'Program inovasi terlaksana','target'=>'≥80%','evaluasi'=>'Evaluasi hasil','tindak'=>'Pengembangan program'],
 ['program'=>'Kemitraan pendidikan','kegiatan'=>'Kerja sama dengan lembaga pendidikan','tujuan'=>'Meningkatkan kualitas pendidikan','indikator'=>'Program kerja sama terlaksana','target'=>'≥90%','evaluasi'=>'Evaluasi manfaat','tindak'=>'Penguatan kerja sama'],
 ['program'=>'Pengembangan sumber daya','kegiatan'=>'Pemanfaatan dukungan mitra','tujuan'=>'Meningkatkan sumber daya madrasah','indikator'=>'Dukungan digunakan sesuai tujuan','target'=>'≥90%','evaluasi'=>'Evaluasi manfaat','tindak'=>'Pengembangan kemitraan'],
],
15=>[
 ['program'=>'Pemetaan risiko','kegiatan'=>'Identifikasi risiko madrasah','tujuan'=>'Mengetahui potensi risiko','indikator'=>'Daftar risiko tersedia','target'=>'100%','evaluasi'=>'Analisis tingkat risiko','tindak'=>'Menetapkan mitigasi'],
 ['program'=>'Keamanan','kegiatan'=>'Pemeriksaan lingkungan madrasah','tujuan'=>'Menciptakan lingkungan aman','indikator'=>'Risiko keamanan teridentifikasi dan ditangani','target'=>'100%','evaluasi'=>'Pemeriksaan berkala','tindak'=>'Perbaikan fasilitas'],
 ['program'=>'Keselamatan','kegiatan'=>'Pembinaan keselamatan siswa','tujuan'=>'Mengurangi risiko kecelakaan','indikator'=>'Siswa memahami prosedur keselamatan','target'=>'≥90%','evaluasi'=>'Observasi/simulasi','tindak'=>'Pembinaan ulang'],
 ['program'=>'Kedaruratan','kegiatan'=>'Penyusunan SOP keadaan darurat','tujuan'=>'Menjamin kesiapan menghadapi keadaan darurat','indikator'=>'SOP tersedia dan dipahami','target'=>'100%','evaluasi'=>'Simulasi/evaluasi','tindak'=>'Penyempurnaan SOP'],
 ['program'=>'Perlindungan siswa','kegiatan'=>'Pencegahan dan penanganan perundungan','tujuan'=>'Mewujudkan lingkungan aman bagi siswa','indikator'=>'Kasus dicegah/ditangani sesuai prosedur','target'=>'100%','evaluasi'=>'Evaluasi kasus','tindak'=>'Pendampingan dan pencegahan'],
 ['program'=>'Keamanan data','kegiatan'=>'Backup dan perlindungan data','tujuan'=>'Mencegah kehilangan/penyalahgunaan data','indikator'=>'Backup dan pengamanan berjalan','target'=>'100%','evaluasi'=>'Audit data','tindak'=>'Penguatan sistem keamanan'],
],
];

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
    $delM=$pdo->prepare("DELETE FROM tb_program_kerja_master_komponen WHERE nomor=?");
    $ok=$delM->execute([$nomor]);
    if($ok){ $_SESSION['flash_message']=['type'=>'success','text'=>'Master komponen no '.$nomor.' berhasil dihapus!']; logActivity($pdo,$_SESSION['username']??'system','Hapus Master Komponen Program Kerja',"No $nomor"); }
    else { $_SESSION['flash_message']=['type'=>'danger','text'=>'Gagal menghapus master komponen!']; }
   } catch(Throwable $e){ $_SESSION['flash_message']=['type'=>'danger','text'=>'Error: '.$e->getMessage()]; }
  }
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
#table-komponen th{white-space:nowrap;font-size:.8rem}
#table-komponen td{font-size:.8rem;vertical-align:top}
.komponen-card .card-header{cursor:pointer;user-select:none}
.komponen-card .card-header .fa-chevron-down{transition:transform .25s ease}
.komponen-card.collapsed .card-header .fa-chevron-down{transform:rotate(-90deg)}
.matrik-tbl th{font-size:.72rem;background:#f5f7ff;color:#414e9a}
.matrik-tbl td{font-size:.72rem}
</style>
<div class="main-content">
<section class="section">
<div class="section-header">
<h1>Komponen Program Kerja <small style="font-size:55%;font-weight:700;margin-left:8px;vertical-align:middle;color:#5f6fb4;background:#eef1ff;border:1px solid #d6dcff;border-radius:999px;padding:4px 10px;">TA: <?=htmlspecialchars($tahun_ajaran)?></small></h1>
<div class="section-header-breadcrumb"><div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div><div class="breadcrumb-item">Program Kerja</div><div class="breadcrumb-item">Komponen Program Kerja</div></div>
</div>
<div class="section-body">

<div class="card">
<div class="card-header d-flex justify-content-between align-items-center">
<h4>Daftar Komponen (<span id="jmlKomponenLabel"><?=count($komponen_list)?></span>)</h4>
<div>
<?php if($is_editable):?>
<button type="button" class="btn btn-primary btn-sm mr-1" data-toggle="modal" data-target="#modalTambahKomponen"><i class="fas fa-plus"></i> Tambah Komponen</button>
<?php endif;?>
<a href="program_kerja.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Kelola Program Kerja</a>
</div>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm table-bordered mb-0" id="table-komponen">
<thead><tr><th class="text-center" style="width:60px">No</th><th>Komponen</th><th>Ruang Lingkup</th><th class="text-center" style="width:90px">Total Program</th><th class="text-center" style="width:90px">Belum</th><th class="text-center" style="width:90px">Proses</th><th class="text-center" style="width:90px">Terlaksana</th><th class="text-center" style="width:200px">Aksi</th></tr></thead>
<tbody>
<?php foreach($komponen_list as $k=>$v): $rk=$rekap_komponen[$k]??['belum_terlaksana'=>0,'proses'=>0,'terlaksana'=>0,'total'=>0];?>
<tr>
<td class="text-center"><?=$k?></td>
<td><strong><?=htmlspecialchars($v['nama'])?></strong></td>
<td><?=htmlspecialchars($v['ruang'])?></td>
<td class="text-center"><span class="badge badge-info"><?=$rk['total']?></span></td>
<td class="text-center"><span class="badge <?=$status_badge['belum_terlaksana']?>"><i class="fas <?=$status_icon['belum_terlaksana']?>"></i> <?=$rk['belum_terlaksana']?></span></td>
<td class="text-center"><span class="badge <?=$status_badge['proses']?>"><i class="fas <?=$status_icon['proses']?>"></i> <?=$rk['proses']?></span></td>
<td class="text-center"><span class="badge <?=$status_badge['terlaksana']?>"><i class="fas <?=$status_icon['terlaksana']?>"></i> <?=$rk['terlaksana']?></span></td>
<td class="text-center">
<div class="btn-group" role="group">
<a href="program_kerja.php?komponen=<?=$k?>" class="btn btn-sm btn-outline-primary" title="Lihat / Kelola Program Komponen Ini"><i class="fas fa-list"></i></a>
<?php if($is_editable):?>
<button type="button" class="btn btn-sm btn-outline-warning btn-edit-master" data-nomor="<?=$k?>" data-nama="<?=htmlspecialchars($v['nama'],ENT_QUOTES,'UTF-8')?>" data-ruang="<?=htmlspecialchars($v['ruang'],ENT_QUOTES,'UTF-8')?>" title="Edit Master Komponen"><i class="fas fa-edit"></i></button>
<?php endif;?>
<?php if($is_editable):?>
<button type="button" class="btn btn-sm btn-outline-danger btn-del-komponen" data-komponen="<?=$k?>" data-nama="<?=htmlspecialchars($v['nama'],ENT_QUOTES,'UTF-8')?>" data-total="<?=$rk['total']?>" title="Hapus Semua Program pada Komponen Ini"><i class="fas fa-trash"></i></button>
<?php endif;?>
</div>
</td>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
</div>
</div>

<div class="row">
<?php foreach($komponen_list as $k=>$v): $m=$matriks[$k]??[]; $rk=$rekap_komponen[$k]??['belum_terlaksana'=>0,'proses'=>0,'terlaksana'=>0,'total'=>0];?>
<div class="col-12 mb-3">
<div class="card komponen-card collapsed" id="card-komponen-<?=$k?>">
<div class="card-header d-flex justify-content-between align-items-center" data-toggle="collapse" data-target="#collapse-<?=$k?>" aria-expanded="true" aria-controls="collapse-<?=$k?>">
<div>
<span class="badge badge-primary mr-2"><?=$k?></span>
<h4 style="display:inline-block;margin:0"><?=htmlspecialchars($v['nama'])?></h4>
<small class="text-muted ml-2"><i class="fas fa-info-circle"></i> <?=htmlspecialchars($v['ruang'])?></small>
</div>
<div class="d-flex align-items-center">
<span class="mr-2">
<span class="badge badge-secondary mr-1"><i class="fas <?=$status_icon['belum_terlaksana']?>"></i> <?=$rk['belum_terlaksana']?></span>
<span class="badge badge-warning mr-1"><i class="fas <?=$status_icon['proses']?>"></i> <?=$rk['proses']?></span>
<span class="badge badge-success mr-1"><i class="fas <?=$status_icon['terlaksana']?>"></i> <?=$rk['terlaksana']?></span>
<span class="badge badge-info">Total: <?=$rk['total']?></span>
</span>
<i class="fas fa-chevron-down text-muted"></i>
</div>
</div>
<div id="collapse-<?=$k?>" class="collapse" data-parent="#accordion">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm table-bordered mb-0 matrik-tbl">
<thead>
<tr>
<th class="text-center" style="width:40px">No</th>
<th style="min-width:140px">Program</th>
<th style="min-width:180px">Kegiatan</th>
<th style="min-width:180px">Tujuan</th>
<th style="min-width:200px">Indikator Keberhasilan</th>
<th style="width:90px">Target</th>
<th style="min-width:180px">Evaluasi</th>
<th style="min-width:180px">Tindak Lanjut</th>
</tr>
</thead>
<tbody>
<?php $no=1; foreach($m as $row):?>
<tr>
<td class="text-center"><?=$no++?></td>
<td><strong><?=htmlspecialchars($row['program'])?></strong></td>
<td><?=htmlspecialchars($row['kegiatan'])?></td>
<td><?=htmlspecialchars($row['tujuan'])?></td>
<td><?=htmlspecialchars($row['indikator'])?></td>
<td class="text-center"><span class="badge badge-info"><?=htmlspecialchars($row['target'])?></span></td>
<td><?=htmlspecialchars($row['evaluasi'])?></td>
<td><?=htmlspecialchars($row['tindak'])?></td>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
</div>
</div>
</div>
</div>
<?php endforeach;?>
</div>

</div>
</section>
</div>

<form id="delKomponenForm" method="POST" style="display:none">
<input type="hidden" name="delete_komponen_program" value="1">
<input type="hidden" name="komponen" id="delKomponenId" value="0">
</form>

<?php include '../templates/footer.php'; ?>
<script>
$(document).ready(function(){
 $('#table-komponen').DataTable({
   scrollX:false,
   autoWidth:false,
   paging:false,
   info:false,
   ordering:false,
   language:{search:"Cari Komponen:", zeroRecords:"Tidak ada data"}
 });
 $('.komponen-card .card-header').on('click', function(){
   var card=$(this).closest('.komponen-card');
   setTimeout(function(){
     var target=$(card).find('.collapse');
     if($(target).hasClass('show')){ $(card).removeClass('collapsed'); }
     else { $(card).addClass('collapsed'); }
   },50);
 });
 $(document).on('click','.btn-del-komponen',function(){
   var k=$(this).data('komponen');
   var nama=$(this).data('nama')||('Komponen '+k);
   var total=parseInt($(this).data('total')||'0',10);
   var txt='Hapus SEMUA program kerja pada komponen "'+nama+'"?';
   if(total>0){ txt+='\n\nTotal '+total+' data program akan dihapus PERMANEN beserta file bukti dokumennya.'; }
   else { txt+='\n\nKomponen ini belum memiliki program.'; }
   Swal.fire({
     title:'Hapus Program Per Komponen?',
     text:txt,
     icon:'warning',
     showCancelButton:true,
     confirmButtonColor:'#d33',
     cancelButtonColor:'#6c757d',
     confirmButtonText:'Ya, Hapus Semua',
     cancelButtonText:'Batal'
   }).then(function(res){
     if(res.isConfirmed){ $('#delKomponenId').val(k); $('#delKomponenForm').submit(); }
   });
 });
 <?php if($message):?>
 Swal.fire({icon:'<?= $message['type']=='success'?'success':'error'?>',title:'<?= addslashes($message['text'])?>', timer:2000, showConfirmButton:false});
 <?php endif;?>
});
</script>
