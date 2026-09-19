<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isAuthorized(['admin','kepala_madrasah'])) redirect('../login.php');

$user_level = getUserLevel();
$is_editable = in_array($user_level, ['admin','kepala_madrasah']);
$page_title = 'Program Kerja Madrasah';

$school_profile = getSchoolProfile($pdo);
$tahun_ajaran = $school_profile['tahun_ajaran'] ?? date('Y').'/'.(date('Y')+1);

$komponen_list = [
 1=>['nama'=>'Manajemen & Kepemimpinan','ruang'=>'Visi-misi, tujuan madrasah, kebijakan, pembagian tugas, koordinasi, rapat, pengambilan keputusan'],
 2=>['nama'=>'Kurikulum & Pembelajaran','ruang'=>'Kurikulum madrasah, perangkat ajar, jadwal, asesmen, supervisi pembelajaran, pengembangan pembelajaran'],
 3=>['nama'=>'Kesiswaan','ruang'=>'PPDB, data siswa, tata tertib, pembinaan karakter, ekstrakurikuler, prestasi, kehadiran, layanan siswa'],
 4=>['nama'=>'Pendidik & Tenaga Kependidikan','ruang'=>'Pembagian tugas, disiplin, pengembangan kompetensi, PKG, supervisi guru, pelatihan, kesejahteraan'],
 5=>['nama'=>'Keuangan & Penganggaran','ruang'=>'RKAM, RAPBM/RKAM, BOS/BOP, pemasukan-pengeluaran, laporan keuangan, pengendalian anggaran'],
 6=>['nama'=>'Sarana & Prasarana','ruang'=>'Inventaris, pemeliharaan gedung, ruang kelas, perpustakaan, laboratorium, fasilitas pembelajaran'],
 7=>['nama'=>'Administrasi & Tata Usaha','ruang'=>'Persuratan, arsip, dokumen madrasah, data EMIS/administrasi, buku administrasi, legalitas'],
 8=>['nama'=>'Hubungan Masyarakat','ruang'=>'Komite, wali siswa, yayasan, masyarakat, instansi pemerintah, komunikasi dan publikasi madrasah'],
 9=>['nama'=>'Pengembangan Mutu Madrasah','ruang'=>'Evaluasi diri, indikator mutu, program peningkatan mutu, monitoring dan evaluasi'],
 10=>['nama'=>'Supervisi & Evaluasi','ruang'=>'Supervisi akademik/manajerial, monitoring program, evaluasi kinerja, tindak lanjut'],
 11=>['nama'=>'Keagamaan & Karakter','ruang'=>'Pembiasaan keagamaan, akhlak, ibadah, budaya madrasah, moderasi dan nilai-nilai Islam'],
 12=>['nama'=>'Ekstrakurikuler & Prestasi','ruang'=>'Pramuka, olahraga, seni, keagamaan, olimpiade, kompetisi dan pembinaan prestasi'],
 13=>['nama'=>'Digitalisasi Madrasah','ruang'=>'Sistem informasi, digitalisasi administrasi, website, database, keamanan data dan teknologi pembelajaran'],
 14=>['nama'=>'Kemitraan & Pengembangan','ruang'=>'Kerja sama dengan pihak luar, program unggulan, inovasi dan pengembangan sumber daya'],
 15=>['nama'=>'Manajemen Risiko & Keamanan','ruang'=>'Keselamatan siswa, keamanan lingkungan, perlindungan data, kesiapsiagaan dan penanganan masalah'],
];

$indikator_by_komponen=[
1=>[
 ['t'=>'Visi, misi, tujuan dan kebijakan madrasah tersosialisasi kepada warga madrasah','target'=>'100%'],
 ['t'=>'Program kerja madrasah tersusun sebelum tahun pelajaran berjalan','target'=>'100%'],
 ['t'=>'Rapat koordinasi dilaksanakan sesuai agenda','target'=>'≥90%'],
 ['t'=>'Pembagian tugas guru dan tenaga kependidikan terlaksana dengan jelas','target'=>'100%'],
 ['t'=>'Keputusan/kebijakan madrasah terdokumentasi','target'=>'100%'],
 ['t'=>'Program kerja dimonitor dan dievaluasi secara berkala','target'=>'≥90%'],
],
2=>[
 ['t'=>'Dokumen kurikulum madrasah tersedia dan diperbarui sesuai ketentuan','target'=>'100%'],
 ['t'=>'Guru memiliki perangkat/perencanaan pembelajaran','target'=>'100%'],
 ['t'=>'Jadwal pembelajaran tersusun dan terlaksana','target'=>'100%'],
 ['t'=>'Supervisi akademik guru terlaksana','target'=>'100%'],
 ['t'=>'Pembelajaran sesuai dengan perencanaan','target'=>'≥90%'],
 ['t'=>'Asesmen dilaksanakan sesuai tujuan pembelajaran','target'=>'≥90%'],
 ['t'=>'Program remedial dan pengayaan terlaksana sesuai kebutuhan','target'=>'≥90%'],
],
3=>[
 ['t'=>'Data peserta didik diperbarui dan terdokumentasi','target'=>'100%'],
 ['t'=>'PPDB/penerimaan peserta didik terlaksana sesuai prosedur','target'=>'100%'],
 ['t'=>'Kehadiran siswa terpantau','target'=>'100%'],
 ['t'=>'Tata tertib siswa tersosialisasi','target'=>'100%'],
 ['t'=>'Pembinaan siswa bermasalah terlaksana','target'=>'100% kasus'],
 ['t'=>'Kegiatan ekstrakurikuler berjalan sesuai program','target'=>'≥90%'],
 ['t'=>'Prestasi siswa terdokumentasi','target'=>'100%'],
],
4=>[
 ['t'=>'Pembagian tugas PTK sesuai kompetensi dan kebutuhan madrasah','target'=>'100%'],
 ['t'=>'Kehadiran dan kedisiplinan PTK termonitor','target'=>'100%'],
 ['t'=>'Supervisi/penilaian kinerja guru dilaksanakan','target'=>'100%'],
 ['t'=>'Guru mendapatkan pembinaan berdasarkan hasil supervisi','target'=>'≥90%'],
 ['t'=>'Program pengembangan kompetensi guru terlaksana','target'=>'≥90%'],
 ['t'=>'Administrasi PTK terdokumentasi dengan baik','target'=>'100%'],
],
5=>[
 ['t'=>'RKAM/RKAM madrasah tersusun sesuai kebutuhan dan prioritas','target'=>'100%'],
 ['t'=>'Realisasi anggaran sesuai rencana','target'=>'≥90%'],
 ['t'=>'Pengeluaran memiliki bukti transaksi','target'=>'100%'],
 ['t'=>'Laporan keuangan disusun secara berkala','target'=>'100%'],
 ['t'=>'Penggunaan anggaran dapat dipertanggungjawabkan','target'=>'100%'],
 ['t'=>'Evaluasi penggunaan anggaran dilakukan berkala','target'=>'≥90%'],
],
6=>[
 ['t'=>'Data inventaris sarpras tersedia dan diperbarui','target'=>'100%'],
 ['t'=>'Sarpras pembelajaran tersedia sesuai kebutuhan','target'=>'≥90%'],
 ['t'=>'Pemeliharaan sarpras dilaksanakan secara berkala','target'=>'≥90%'],
 ['t'=>'Kerusakan sarpras ditindaklanjuti','target'=>'≥90%'],
 ['t'=>'Penggunaan sarpras tercatat dan terkontrol','target'=>'≥90%'],
],
7=>[
 ['t'=>'Administrasi surat masuk/keluar terdokumentasi','target'=>'100%'],
 ['t'=>'Arsip dokumen madrasah tertata','target'=>'100%'],
 ['t'=>'Data administrasi madrasah diperbarui secara berkala','target'=>'100%'],
 ['t'=>'Dokumen penting madrasah tersedia dan mudah ditemukan','target'=>'100%'],
 ['t'=>'Pelayanan administrasi berjalan sesuai prosedur','target'=>'≥90%'],
],
8=>[
 ['t'=>'Komunikasi dengan wali siswa terlaksana secara efektif','target'=>'≥90%'],
 ['t'=>'Pertemuan dengan komite/yayasan dilaksanakan sesuai kebutuhan','target'=>'≥90%'],
 ['t'=>'Kegiatan madrasah dipublikasikan secara positif dan informatif','target'=>'≥90%'],
 ['t'=>'Kerja sama dengan masyarakat/instansi terjalin','target'=>'Sesuai target'],
 ['t'=>'Pengaduan/masukan dari masyarakat ditindaklanjuti','target'=>'100%'],
],
9=>[
 ['t'=>'Evaluasi program madrasah dilakukan secara berkala','target'=>'≥90%'],
 ['t'=>'Hasil evaluasi memiliki tindak lanjut','target'=>'100%'],
 ['t'=>'Program peningkatan mutu berdasarkan hasil evaluasi','target'=>'≥90%'],
 ['t'=>'Data capaian mutu terdokumentasi','target'=>'100%'],
 ['t'=>'Terjadi perbaikan pada indikator mutu yang ditargetkan','target'=>'≥80%'],
],
10=>[
 ['t'=>'Program supervisi kepala madrasah tersusun','target'=>'100%'],
 ['t'=>'Guru mendapatkan supervisi sesuai jadwal','target'=>'100%'],
 ['t'=>'Hasil supervisi terdokumentasi','target'=>'100%'],
 ['t'=>'Temuan supervisi memiliki rekomendasi','target'=>'100%'],
 ['t'=>'Tindak lanjut supervisi dilaksanakan','target'=>'≥90%'],
 ['t'=>'Supervisi ulang dilakukan terhadap guru yang membutuhkan','target'=>'100%'],
],
11=>[
 ['t'=>'Program pembiasaan keagamaan terlaksana','target'=>'≥90%'],
 ['t'=>'Kegiatan keagamaan terjadwal dan terdokumentasi','target'=>'100%'],
 ['t'=>'Pembinaan akhlak dan karakter siswa terlaksana','target'=>'≥90%'],
 ['t'=>'Budaya positif madrasah diterapkan','target'=>'≥90%'],
 ['t'=>'Keteladanan dan pembiasaan nilai keagamaan terintegrasi dalam kegiatan madrasah','target'=>'≥90%'],
],
12=>[
 ['t'=>'Program ekstrakurikuler tersusun','target'=>'100%'],
 ['t'=>'Kegiatan ekstrakurikuler terlaksana sesuai jadwal','target'=>'≥90%'],
 ['t'=>'Pembina ekstrakurikuler ditetapkan','target'=>'100%'],
 ['t'=>'Prestasi siswa didokumentasikan','target'=>'100%'],
 ['t'=>'Peserta didik mendapatkan pembinaan sesuai minat dan bakat','target'=>'≥90%'],
],
13=>[
 ['t'=>'Data madrasah tersimpan dalam sistem digital','target'=>'≥90%'],
 ['t'=>'Administrasi prioritas telah didigitalisasi','target'=>'≥80%'],
 ['t'=>'Sistem informasi madrasah dapat digunakan','target'=>'≥90%'],
 ['t'=>'Backup data dilakukan secara berkala','target'=>'100%'],
 ['t'=>'Keamanan akun dan data diperhatikan','target'=>'100%'],
],
14=>[
 ['t'=>'Kerja sama dengan pihak eksternal terdokumentasi','target'=>'100%'],
 ['t'=>'Program kerja sama dilaksanakan sesuai kesepakatan','target'=>'≥90%'],
 ['t'=>'Program inovasi madrasah terlaksana','target'=>'≥80%'],
 ['t'=>'Sumber daya/dukungan eksternal dimanfaatkan untuk pengembangan madrasah','target'=>'≥80%'],
],
15=>[
 ['t'=>'Risiko utama madrasah teridentifikasi','target'=>'100%'],
 ['t'=>'Prosedur penanganan keadaan darurat tersedia','target'=>'100%'],
 ['t'=>'Keamanan lingkungan madrasah dimonitor','target'=>'≥90%'],
 ['t'=>'Insiden/kasus terdokumentasi dan ditindaklanjuti','target'=>'100%'],
 ['t'=>'Data dan dokumen penting memiliki mekanisme pencadangan','target'=>'100%'],
],
];
$evaluasi_by_komponen=[
1=>['Keterlaksanaan program','Koordinasi','Disiplin','Efektivitas kepemimpinan'],
2=>['Kelengkapan administrasi','Kualitas pembelajaran','Asesmen','Hasil belajar'],
3=>['Kehadiran','Tata tertib','Karakter','Prestasi','Layanan siswa'],
4=>['Kinerja','Kehadiran','Kompetensi','Tanggung jawab','Profesionalisme'],
5=>['Realisasi anggaran','Kesesuaian RKAM','Bukti transaksi','Efisiensi'],
6=>['Kondisi','Kebutuhan','Pemanfaatan','Inventaris'],
7=>['Arsip','Persuratan','Data','Dokumen','Pelayanan'],
8=>['Kedisiplinan','Ibadah','Akhlak','Budaya positif','Pembiasaan'],
9=>['Komunikasi','Partisipasi','Kerja sama','Kepuasan/masukan'],
10=>['Target mutu','Capaian','Kesenjangan','Efektivitas program'],
11=>['Cakupan supervisi','Hasil supervisi','Temuan','Tindak lanjut'],
12=>['Kehadiran','Kegiatan','Minat','Prestasi'],
13=>['Penggunaan sistem','Kelengkapan data','Keamanan','Backup'],
14=>['Jumlah kerja sama','Manfaat','Keberlanjutan','Hasil program'],
15=>['Risiko','Insiden','Keamanan','Kesiapsiagaan'],
];
$tindak_lanjut_by_komponen=[
1=>['Memperbaiki koordinasi','Menyempurnakan SOP','Melakukan rapat evaluasi','Menetapkan program prioritas'],
2=>['Pembinaan guru','Supervisi lanjutan','Workshop','Perbaikan perangkat dan strategi pembelajaran'],
3=>['Pembinaan siswa','Konseling','Komunikasi dengan wali','Program pengembangan minat dan bakat'],
4=>['Coaching','Pelatihan','Pembagian tugas ulang','Supervisi','Pendampingan individual'],
5=>['Mengendalikan pengeluaran','Menyesuaikan prioritas','Memperbaiki administrasi keuangan','Evaluasi anggaran'],
6=>['Perbaikan','Pemeliharaan','Pengadaan berdasarkan prioritas','Pembaruan inventaris'],
7=>['Penataan arsip','Digitalisasi','SOP administrasi','Pembaruan data dan pembinaan tenaga administrasi'],
8=>['Penguatan pembiasaan','Keteladanan guru','Pembinaan siswa','Evaluasi program keagamaan'],
9=>['Memperbaiki pola komunikasi','Forum koordinasi','Tindak lanjut pengaduan','Memperluas kemitraan'],
10=>['Menetapkan program perbaikan','Menetapkan prioritas mutu','Monitoring berkala'],
11=>['Supervisi ulang','Coaching','Pendampingan','Workshop','Pemantauan hasil perbaikan'],
12=>['Penguatan pembinaan','Penyediaan fasilitas','Seleksi/pemetaan bakat','Persiapan kompetisi'],
13=>['Pelatihan pengguna','Penyempurnaan sistem','Backup berkala','Peningkatan keamanan'],
14=>['Memperkuat kerja sama yang relevan','Memperbaiki program','Mencari mitra baru'],
15=>['Memperbarui SOP','Simulasi','Perbaikan fasilitas keamanan','Pencadangan data','Mitigasi risiko'],
];
$tujuan_by_komponen=[
1=>['Mewujudkan pengelolaan madrasah yang efektif, transparan, partisipatif, terarah, dan berorientasi pada peningkatan mutu melalui perencanaan, koordinasi, pelaksanaan, monitoring, dan evaluasi yang berkelanjutan.'],
2=>['Meningkatkan kualitas pengelolaan dan pelaksanaan pembelajaran agar sesuai dengan kurikulum yang berlaku, kebutuhan peserta didik, serta mampu meningkatkan kompetensi, karakter, dan hasil belajar peserta didik.'],
3=>['Mewujudkan pengelolaan peserta didik yang tertib, terarah, inklusif, dan berkelanjutan melalui pelayanan, pembinaan karakter, kedisiplinan, pengembangan minat dan bakat, serta peningkatan prestasi siswa.'],
4=>['Meningkatkan profesionalisme, kompetensi, kedisiplinan, kinerja, tanggung jawab, dan kesejahteraan pendidik serta tenaga kependidikan sesuai tugas dan tanggung jawab masing-masing.'],
5=>['Mewujudkan pengelolaan keuangan madrasah yang efektif, efisien, transparan, tertib, akuntabel, dan sesuai dengan perencanaan serta skala prioritas kebutuhan madrasah.'],
6=>['Menjamin ketersediaan, kelayakan, pemanfaatan, pemeliharaan, dan pengembangan sarana prasarana yang mendukung kegiatan pembelajaran dan pelayanan madrasah secara optimal.'],
7=>['Mewujudkan administrasi madrasah yang tertib, lengkap, akurat, efektif, mudah diakses, terdokumentasi dengan baik, serta mendukung kelancaran seluruh kegiatan madrasah.'],
8=>['Membentuk budaya madrasah yang religius, berakhlak, disiplin, peduli, bertanggung jawab, dan mencerminkan nilai-nilai keislaman dalam kehidupan sehari-hari.'],
9=>['Membangun komunikasi, koordinasi, kepercayaan, dan kemitraan yang harmonis antara madrasah dengan orang tua/wali siswa, komite, yayasan, masyarakat, dan lembaga terkait.'],
10=>['Meningkatkan mutu madrasah secara berkelanjutan melalui pemetaan kondisi, penetapan target, pelaksanaan program perbaikan, monitoring, evaluasi, dan tindak lanjut berdasarkan data.'],
11=>['Meningkatkan kualitas kinerja pendidik dan tenaga kependidikan melalui supervisi yang sistematis, objektif, konstruktif, berkelanjutan, serta diikuti pembinaan dan tindak lanjut.'],
12=>['Mengembangkan potensi, minat, bakat, kreativitas, keterampilan, dan karakter peserta didik melalui kegiatan ekstrakurikuler serta pembinaan prestasi secara terencana dan berkelanjutan.'],
13=>['Meningkatkan efektivitas, efisiensi, ketepatan, dan keamanan pengelolaan data serta administrasi madrasah melalui pemanfaatan teknologi informasi dan sistem digital.'],
14=>['Mengembangkan madrasah melalui kerja sama dan kemitraan yang produktif dengan berbagai pihak serta mendorong inovasi dan program pengembangan yang sesuai dengan kebutuhan dan potensi madrasah.'],
15=>['Mewujudkan lingkungan madrasah yang aman, tertib, sehat, nyaman, dan siap menghadapi berbagai risiko melalui identifikasi risiko, pencegahan, penanganan, serta evaluasi secara berkala.'],
];
$program_by_komponen=[
1=>['Penyusunan program kerja madrasah','Penyusunan rencana kerja tahunan','Penyusunan rencana pengembangan madrasah','Penyusunan pembagian tugas PTK','Rapat koordinasi rutin','Rapat evaluasi program','Penguatan tata kelola madrasah','Penyusunan dan penerapan SOP','Monitoring pelaksanaan program','Evaluasi kinerja madrasah'],
2=>['Penyusunan/pengembangan kurikulum madrasah','Penyusunan kalender pendidikan','Penyusunan jadwal pelajaran','Pengelolaan perangkat pembelajaran','Pengembangan metode dan model pembelajaran','Program pembelajaran berbasis kebutuhan siswa','Pelaksanaan asesmen','Analisis hasil belajar','Program remedial dan pengayaan','Supervisi akademik','Pengembangan pembelajaran berbasis teknologi'],
3=>['Penerimaan peserta didik baru','Pendataan dan pemutakhiran data siswa','Pengelolaan kehadiran siswa','Pembinaan kedisiplinan','Pembinaan karakter siswa','Penanganan siswa bermasalah','Pembinaan minat dan bakat','Program prestasi siswa','Pengelolaan organisasi/kegiatan siswa','Pendataan alumni'],
4=>['Pemetaan kebutuhan PTK','Pembagian tugas dan beban kerja','Monitoring kehadiran dan kedisiplinan','Penilaian kinerja guru','Supervisi guru','Pembinaan profesionalisme','Pelatihan/workshop guru','Pengembangan kompetensi berkelanjutan','Evaluasi kinerja PTK','Pemberian penghargaan/apresiasi'],
5=>['Penyusunan RKAM','Penyusunan rencana anggaran kegiatan','Pengelolaan penerimaan madrasah','Pengelolaan pengeluaran','Pengendalian penggunaan anggaran','Administrasi bukti transaksi','Pembukuan keuangan','Laporan keuangan berkala','Evaluasi realisasi anggaran','Transparansi dan akuntabilitas keuangan'],
6=>['Pendataan/inventarisasi sarpras','Analisis kebutuhan sarpras','Pengadaan sarpras prioritas','Pemeliharaan gedung','Pemeliharaan ruang kelas','Pemeliharaan fasilitas pembelajaran','Pengelolaan perpustakaan','Pengelolaan laboratorium/fasilitas praktik','Pengelolaan fasilitas olahraga','Penghapusan sarpras yang tidak layak sesuai prosedur'],
7=>['Pengelolaan surat masuk dan keluar','Penataan arsip madrasah','Pengelolaan dokumen kelembagaan','Pengelolaan data siswa','Pengelolaan data PTK','Administrasi kegiatan madrasah','Administrasi rapat dan notulen','Pengelolaan dokumen legalitas','Digitalisasi administrasi','Backup dan pengamanan dokumen'],
8=>['Pembiasaan ibadah','Pembiasaan membaca Al-Qur\'an','Kegiatan doa dan dzikir','Pembinaan akhlak','Program kepedulian sosial','Pembiasaan disiplin dan tanggung jawab','Peringatan hari besar Islam','Kegiatan keagamaan siswa','Penguatan budaya religius madrasah','Integrasi nilai keagamaan dalam pembelajaran'],
9=>['Komunikasi dengan wali siswa','Pertemuan wali siswa','Koordinasi dengan komite','Koordinasi dengan yayasan','Kerja sama dengan masyarakat','Kerja sama dengan instansi/lembaga','Pengelolaan informasi dan publikasi','Pengelolaan website/media sosial madrasah','Penanganan aspirasi dan pengaduan'],
10=>['Evaluasi diri madrasah','Pemetaan kondisi dan kebutuhan madrasah','Penetapan target mutu','Penyusunan program peningkatan mutu','Monitoring indikator mutu','Evaluasi capaian mutu','Program perbaikan berkelanjutan','Pengembangan program unggulan','Persiapan akreditasi/evaluasi eksternal'],
11=>['Penyusunan program supervisi','Penyusunan instrumen supervisi','Supervisi administrasi pembelajaran','Supervisi pelaksanaan pembelajaran','Observasi kelas','Supervisi tenaga kependidikan','Analisis hasil supervisi','Pembinaan berdasarkan hasil supervisi','Supervisi ulang','Evaluasi pelaksanaan program madrasah'],
12=>['Penyusunan program ekstrakurikuler','Penetapan pembina ekstrakurikuler','Pelaksanaan kegiatan ekstrakurikuler','Pembinaan olahraga','Pembinaan seni','Pembinaan keagamaan','Pembinaan Pramuka','Pembinaan olimpiade/akademik','Pemetaan bakat dan minat siswa','Persiapan kompetisi','Dokumentasi dan apresiasi prestasi'],
13=>['Digitalisasi administrasi','Pengembangan sistem informasi madrasah','Pengelolaan database madrasah','Digitalisasi data siswa','Digitalisasi data PTK','Pengelolaan website madrasah','Pemanfaatan teknologi dalam pembelajaran','Pengarsipan dokumen digital','Backup data berkala','Pengamanan akun dan data'],
14=>['Identifikasi calon mitra','Penyusunan program kerja sama','Kerja sama dengan lembaga pendidikan','Kerja sama dengan pemerintah/instansi','Kerja sama dengan dunia usaha/masyarakat','Pengembangan program unggulan','Inovasi pengelolaan madrasah','Pengembangan sumber pendanaan yang sah','Evaluasi manfaat kerja sama'],
15=>['Identifikasi risiko madrasah','Penyusunan SOP keadaan darurat','Pengamanan lingkungan madrasah','Keselamatan peserta didik','Pencegahan dan penanganan perundungan','Kesiapsiagaan bencana','Keamanan data dan dokumen','Backup data','Pencatatan dan penanganan insiden','Evaluasi dan mitigasi risiko'],
];
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

$message='';
if(isset($_SESSION['flash_message'])){ $message=$_SESSION['flash_message']; unset($_SESSION['flash_message']); }

if($_SERVER['REQUEST_METHOD']=='POST' && $is_editable){
 $redirect=$_SERVER['PHP_SELF'].(!empty($_GET['session_type'])?'?session_type='.urlencode($_GET['session_type']):'');
 if(isset($_POST['add_program'])){
  $komponen=(int)($_POST['komponen']??0);
  $program=trim($_POST['program']??'');
  $kegiatan=trim($_POST['kegiatan']??'');
  if($komponen<1||$komponen>15||$program==''||$kegiatan==''){
   $_SESSION['flash_message']=['type'=>'danger','text'=>'Komponen, Program dan Kegiatan wajib diisi!'];
  } else {
   $anggaran=(float)preg_replace('/[^0-9]/','',$_POST['anggaran']??'0');
   $wm=!empty($_POST['waktu_mulai'])?$_POST['waktu_mulai']:null; $ws=!empty($_POST['waktu_selesai'])?$_POST['waktu_selesai']:null;
   $st=trim($_POST['status']??'belum_terlaksana'); if(!isset($status_opts[$st])) $st='belum_terlaksana';
   $up=handleUploadProker('bukti');
   if(isset($up['error'])){ $_SESSION['flash_message']=['type'=>'danger','text'=>$up['error']]; header("Location: $redirect"); exit; }
   $bukti=$up['file']??null;
    $ev=is_array($_POST['evaluasi']??null)?implode(', ',array_map('trim',$_POST['evaluasi'])):trim($_POST['evaluasi']??'');
    $tl=is_array($_POST['tindak_lanjut']??null)?implode(', ',array_map('trim',$_POST['tindak_lanjut'])):trim($_POST['tindak_lanjut']??'');
    $stmt=$pdo->prepare("INSERT INTO tb_program_kerja (komponen,program,kegiatan,tujuan,indikator,target,waktu_mulai,waktu_selesai,penanggung_jawab,anggaran,sumber_dana,bukti,evaluasi,tindak_lanjut,status,tahun_ajaran) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
   $ok=$stmt->execute([$komponen,$program,$kegiatan,trim($_POST['tujuan']??''),trim($_POST['indikator']??''),trim($_POST['target']??''),$wm,$ws,trim($_POST['penanggung_jawab']??''),$anggaran,trim($_POST['sumber_dana']??''),$bukti,$ev,$tl,$st,$tahun_ajaran]);
   $_SESSION['flash_message']=$ok?['type'=>'success','text'=>'Program kerja ditambahkan!']:['type'=>'danger','text'=>'Gagal menambah!'];
   if($ok) logActivity($pdo,$_SESSION['username']??'system','Tambah Program Kerja',"$program - komp $komponen");
  }
  header("Location: $redirect"); exit;
  } elseif(isset($_POST['update_program'])){
  $id=(int)($_POST['id']??0);
  $komponen=(int)($_POST['komponen']??0);
  $program=trim($_POST['program']??'');
  $kegiatan=trim($_POST['kegiatan']??'');
  if($id<=0||$komponen<1||$komponen>15||$program==''||$kegiatan==''){
   $_SESSION['flash_message']=['type'=>'danger','text'=>'Data tidak valid!'];
  } else {
   $anggaran=(float)preg_replace('/[^0-9]/','',$_POST['anggaran']??'0');
   $wm=!empty($_POST['waktu_mulai'])?$_POST['waktu_mulai']:null; $ws=!empty($_POST['waktu_selesai'])?$_POST['waktu_selesai']:null;
   $st=trim($_POST['status']??'belum_terlaksana'); if(!isset($status_opts[$st])) $st='belum_terlaksana';
   $cur=$pdo->prepare("SELECT bukti FROM tb_program_kerja WHERE id=?"); $cur->execute([$id]); $old=$cur->fetchColumn();
   $bukti=$old;
   if(isset($_FILES['bukti'])&&$_FILES['bukti']['error']==0){
    $up=handleUploadProker('bukti');
    if(isset($up['error'])){ $_SESSION['flash_message']=['type'=>'danger','text'=>$up['error']]; header("Location: $redirect"); exit; }
    if($old && file_exists($upload_dir.$old)) @unlink($upload_dir.$old);
    $bukti=$up['file'];
   }
    $ev=is_array($_POST['evaluasi']??null)?implode(', ',array_map('trim',$_POST['evaluasi'])):trim($_POST['evaluasi']??'');
    $tl=is_array($_POST['tindak_lanjut']??null)?implode(', ',array_map('trim',$_POST['tindak_lanjut'])):trim($_POST['tindak_lanjut']??'');
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
if($filter_komponen>=1&&$filter_komponen<=15){ $where[]="komponen=?"; $params[]=$filter_komponen; }
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
.bukti-link{max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block}
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
<option value="0">Semua Komponen (15)</option>
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
<div class="col-md-6 text-right d-flex align-items-end justify-content-end">
<?php if($is_editable):?>
<button class="btn btn-primary" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Tambah Program</button>
<?php endif;?>
</div>
</div>
<div class="mb-2">
<?php foreach($status_opts as $sv=>$sl): $c=(int)($cnt_st[$sv]??0); $bc=$status_badge[$sv]; $ic=$status_icon[$sv];?>
<span class="badge <?=$bc?>" style="font-size:.82rem;padding:6px 10px;margin-right:6px"><i class="fas <?=$ic?>"></i> <?=htmlspecialchars($sl)?>: <?=$c?></span>
<?php endforeach;?>
</div>

<div class="card">
<div class="card-header"><h4>Tabel Program Kerja</h4><div class="card-header-action d-flex align-items-center">
<span class="badge badge-info mr-2"><?=count($rows)?> data</span>
<?php $q=http_build_query(array_filter(['komponen'=>$filter_komponen?:null,'status'=>$filter_status?:null])); $qs=$q?'?'.$q:''; ?>
<a href="export_program_kerja_excel.php<?=$qs?>" class="btn btn-success btn-sm mr-1"><i class="fas fa-file-excel"></i> Excel</a>
<a href="cetak_program_kerja.php<?=$qs?>" target="_blank" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf"></i> PDF</a>
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
<th style="min-width:120px">Bukti / Dokumen</th>
<th style="min-width:140px">Evaluasi</th>
<th style="min-width:140px">Tindak Lanjut</th>
<th class="text-center" style="min-width:120px">Status</th>
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
<td class="text-center"><?php if(!empty($r['bukti'])): ?><a href="../assets/dokumen/program_kerja/<?=htmlspecialchars($r['bukti'])?>" target="_blank" class="bukti-link btn btn-sm btn-outline-primary"><i class="fas fa-file"></i> <?=htmlspecialchars($r['bukti'])?></a><?php else: ?>-<?php endif;?></td>
<td><?php $evs=array_filter(array_map('trim',explode(',',$r['evaluasi']??''))); if($evs): foreach($evs as $ev):?><span class="badge badge-light border" style="font-size:.72rem;margin:1px 2px;white-space:normal"><?=htmlspecialchars($ev)?></span><?php endforeach; else:?>-<?php endif;?></td>
<td><?php $tls=array_filter(array_map('trim',explode(',',$r['tindak_lanjut']??''))); if($tls): foreach($tls as $tl):?><span class="badge badge-light border" style="font-size:.72rem;margin:1px 2px;white-space:normal"><?=htmlspecialchars($tl)?></span><?php endforeach; else:?>-<?php endif;?></td>
<td class="text-center"><?php $sv=$r['status']??'belum_terlaksana'; if(!isset($status_opts[$sv])) $sv='belum_terlaksana'; $bc=$status_badge[$sv]; $ic=$status_icon[$sv]; $sl=$status_opts[$sv];?><span class="badge <?=$bc?>"><i class="fas <?=$ic?>"></i> <?=htmlspecialchars($sl)?></span></td>
<?php if($is_editable):?>
<td class="text-center">
<div class="btn-group">
<button class="btn btn-warning btn-sm btn-edit" data-row='<?=htmlspecialchars(json_encode($r),ENT_QUOTES)?>' title="Edit"><i class="fas fa-edit"></i></button>
<button class="btn btn-info btn-sm btn-status-cycle" data-id="<?=$r['id']?>" data-status="<?=$sv?>" title="Ubah Status"><i class="fas <?=$ic?>"></i></button>
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
<div class="card-header"><h4>Daftar Komponen (15)</h4></div>
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
<div class="form-group"><label>Kegiatan *</label><textarea name="kegiatan" class="form-control" rows="2" required></textarea></div>
<div class="row">
<div class="col-md-6"><div class="form-group"><label>Tujuan</label><select name="tujuan" id="add_tujuan" class="form-control"></select><small class="text-muted">Pilih komponen dulu</small></div></div>
<div class="col-md-6"><div class="form-group"><label>Indikator Keberhasilan</label><select name="indikator" id="add_indikator" class="form-control"></select><small class="text-muted">Target auto-terisi</small></div></div>
</div>
<div class="row">
<div class="col-md-4"><div class="form-group"><label>Target</label><input type="text" name="target" id="add_target" class="form-control" placeholder="100% / ≥90%"></div></div>
<div class="col-md-2"><div class="form-group"><label>Penanggung Jawab</label><input type="text" name="penanggung_jawab" class="form-control" placeholder="Nama / jabatan"></div></div>
<div class="col-md-2"><div class="form-group"><label>Anggaran (Rp)</label><input type="text" name="anggaran" class="form-control uang" placeholder="0"></div></div>
<div class="col-md-2"><div class="form-group"><label>Sumber Dana</label><input type="text" name="sumber_dana" class="form-control" placeholder="BOS / BOP / Komite"></div></div>
<div class="col-md-2"><div class="form-group"><label>Status</label><select name="status" class="form-control"><?php foreach($status_opts as $sv=>$sl):?><option value="<?=$sv?>"><?=htmlspecialchars($sl)?></option><?php endforeach;?></select></div></div>
</div>
<div class="row">
<div class="col-md-6"><div class="form-group"><label>Bukti / Dokumen (pdf/jpg/doc/xls max 5MB)</label><input type="file" name="bukti" class="form-control"></div></div>
<div class="col-md-3"><div class="form-group"><label>Evaluasi (pilih banyak)</label>
<div class="dropdown" id="dd-add-evaluasi"><button class="btn btn-outline-secondary btn-block dropdown-toggle text-left" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><span class="dd-label">-- Pilih Evaluasi --</span></button><div class="dropdown-menu w-100" style="max-height:220px;overflow-y:auto;padding:6px"></div></div>
<div id="add_evaluasi_hidden"></div></div></div>
<div class="col-md-3"><div class="form-group"><label>Tindak Lanjut (pilih banyak)</label>
<div class="dropdown" id="dd-add-tindak"><button class="btn btn-outline-secondary btn-block dropdown-toggle text-left" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><span class="dd-label">-- Pilih Tindak Lanjut --</span></button><div class="dropdown-menu w-100" style="max-height:220px;overflow-y:auto;padding:6px"></div></div>
<div id="add_tindak_hidden"></div></div></div>
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
<div class="form-group"><label>Kegiatan *</label><textarea name="kegiatan" id="edit_kegiatan" class="form-control" rows="2" required></textarea></div>
<div class="row">
<div class="col-md-6"><div class="form-group"><label>Tujuan</label><select name="tujuan" id="edit_tujuan" class="form-control"></select></div></div>
<div class="col-md-6"><div class="form-group"><label>Indikator Keberhasilan</label><select name="indikator" id="edit_indikator" class="form-control"></select></div></div>
</div>
<div class="row">
<div class="col-md-3"><div class="form-group"><label>Target</label><input type="text" name="target" id="edit_target" class="form-control"></div></div>
<div class="col-md-2"><div class="form-group"><label>Penanggung Jawab</label><input type="text" name="penanggung_jawab" id="edit_pj" class="form-control"></div></div>
<div class="col-md-2"><div class="form-group"><label>Anggaran (Rp)</label><input type="text" name="anggaran" id="edit_anggaran" class="form-control"></div></div>
<div class="col-md-2"><div class="form-group"><label>Sumber Dana</label><input type="text" name="sumber_dana" id="edit_sumber" class="form-control"></div></div>
<div class="col-md-2"><div class="form-group"><label>Status</label><select name="status" id="edit_status" class="form-control"><?php foreach($status_opts as $sv=>$sl):?><option value="<?=$sv?>"><?=htmlspecialchars($sl)?></option><?php endforeach;?></select></div></div>
</div>
<div class="row">
<div class="col-md-6"><div class="form-group"><label>Bukti / Dokumen (kosongkan jika tidak ganti)</label><input type="file" name="bukti" class="form-control"><small id="edit_bukti_old" class="text-muted"></small></div></div>
<div class="col-md-3"><div class="form-group"><label>Evaluasi (pilih banyak)</label>
<div class="dropdown" id="dd-edit-evaluasi"><button class="btn btn-outline-secondary btn-block dropdown-toggle text-left" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><span class="dd-label">-- Pilih Evaluasi --</span></button><div class="dropdown-menu w-100" style="max-height:220px;overflow-y:auto;padding:6px"></div></div>
<div id="edit_evaluasi_hidden"></div></div></div>
<div class="col-md-3"><div class="form-group"><label>Tindak Lanjut (pilih banyak)</label>
<div class="dropdown" id="dd-edit-tl"><button class="btn btn-outline-secondary btn-block dropdown-toggle text-left" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><span class="dd-label">-- Pilih Tindak Lanjut --</span></button><div class="dropdown-menu w-100" style="max-height:220px;overflow-y:auto;padding:6px"></div></div>
<div id="edit_tl_hidden"></div></div></div>
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
var programMap=<?=json_encode($program_by_komponen, JSON_UNESCAPED_UNICODE)?>;
var indikatorMap=<?=json_encode($indikator_by_komponen, JSON_UNESCAPED_UNICODE)?>;
var evaluasiMap=<?=json_encode($evaluasi_by_komponen, JSON_UNESCAPED_UNICODE)?>;
var tindakMap=<?=json_encode($tindak_lanjut_by_komponen, JSON_UNESCAPED_UNICODE)?>;
var tujuanMap=<?=json_encode($tujuan_by_komponen, JSON_UNESCAPED_UNICODE)?>;
function fillProgram(k, selId, cur){ var $s=$(selId); $s.empty().append('<option value="">-- Pilih Program --</option>'); (programMap[k]||[]).forEach(function(v){ $s.append($('<option>').val(v).text(v)); }); if(cur){ if($s.find('option').filter(function(){return $(this).val()===cur;}).length===0) $s.append($('<option>').val(cur).text(cur)); $s.val(cur); } }
function fillIndikator(k, selId, targetId, cur){ var $s=$(selId); $s.empty().append('<option value="">-- Pilih Indikator --</option>'); (indikatorMap[k]||[]).forEach(function(it){ $s.append($('<option>').val(it.t).attr('data-target',it.target).text(it.t+' ('+it.target+')')); }); if(cur){ if($s.find('option').filter(function(){return $(this).val()===cur;}).length===0) $s.append($('<option>').val(cur).text(cur)); $s.val(cur); } }
function ddHidden(container){
 if(container==='#dd-add-evaluasi') return $('#add_evaluasi_hidden');
 if(container==='#dd-add-tindak') return $('#add_tindak_hidden');
 if(container==='#dd-edit-evaluasi') return $('#edit_evaluasi_hidden');
 return $('#edit_tl_hidden');
}
function ddName(container){
 if(container==='#dd-add-evaluasi'||container==='#dd-edit-evaluasi') return 'evaluasi[]';
 return 'tindak_lanjut[]';
}
function renderDD(container, list, selected){
 if(!selected) selected=[];
 var $menu=$(container+' .dropdown-menu'); var $hid=ddHidden(container); var name=ddName(container);
 $menu.empty(); $hid.empty();
 list.forEach(function(v){
  var id='cb_'+Math.random().toString(36).slice(2,7);
  var esc=$('<div>').text(v).html();
  var chk=$('<div class="form-check"><input class="form-check-input" type="checkbox" value="'+esc+'" id="'+id+'"><label class="form-check-label" for="'+id+'" style="font-size:.82rem">'+esc+'</label></div>');
  if(selected.indexOf(v)>=0) chk.find('input').prop('checked',true);
  $menu.append(chk);
  if(selected.indexOf(v)>=0) $hid.append($('<input type="hidden" name="'+name+'" value="'+esc+'">'));
 });
 $menu.find('input').off('change').on('change', function(){ syncDD(container); });
 syncLabel(container);
}
function syncDD(container){
 var $menu=$(container+' .dropdown-menu'); var vals=[]; $menu.find('input:checked').each(function(){ vals.push($(this).val()); });
 var $hid=ddHidden(container); var name=ddName(container);
 $hid.empty(); vals.forEach(function(v){ $hid.append($('<input type="hidden" name="'+name+'" value="'+$('<div>').text(v).html()+'">')); });
 syncLabel(container);
}
function syncLabel(container){ var $menu=$(container+' .dropdown-menu'); var vals=[]; $menu.find('input:checked').each(function(){ vals.push($(this).val()); }); var $lab=$(container+' .dd-label'); if(!vals.length) $lab.text('-- Pilih --'); else if(vals.length<=2) $lab.text(vals.join(', ')); else $lab.text(vals.length+' terpilih'); }
function fillEval(k, cur){ var a=cur? (cur+'').split(',').map(function(s){return s.trim()}).filter(Boolean):[]; renderDD('#dd-add-evaluasi', evaluasiMap[k]||[], a); }
function fillTindak(k, cur){ var a=cur? (cur+'').split(',').map(function(s){return s.trim()}).filter(Boolean):[]; renderDD('#dd-add-tindak', tindakMap[k]||[], a); }
function fillEvalEdit(k, cur){ var a=cur? (cur+'').split(',').map(function(s){return s.trim()}).filter(Boolean):[]; renderDD('#dd-edit-evaluasi', evaluasiMap[k]||[], a); }
function fillTindakEdit(k, cur){ var a=cur? (cur+'').split(',').map(function(s){return s.trim()}).filter(Boolean):[]; renderDD('#dd-edit-tl', tindakMap[k]||[], a); }
function fillTujuan(k, cur){ var $s=$('#add_tujuan'); $s.empty().append('<option value="">-- Pilih Tujuan --</option>'); (tujuanMap[k]||[]).forEach(function(v){ $s.append($('<option>').val(v).text(v)); }); if(cur){ if($s.find('option').filter(function(){return $(this).val()===cur;}).length===0) $s.append($('<option>').val(cur).text(cur)); $s.val(cur); } }
function fillTujuanEdit(k, cur){ var $s=$('#edit_tujuan'); $s.empty().append('<option value="">-- Pilih Tujuan --</option>'); (tujuanMap[k]||[]).forEach(function(v){ $s.append($('<option>').val(v).text(v)); }); if(cur){ if($s.find('option').filter(function(){return $(this).val()===cur;}).length===0) $s.append($('<option>').val(cur).text(cur)); $s.val(cur); } }
$(document).on('click','.dropdown-menu',function(e){ e.stopPropagation(); });
$(document).on('change','#addModal select[name="komponen"]',function(){ var k=$(this).val(); fillProgram(k,'#add_program_sel',''); fillIndikator(k,'#add_indikator','#add_target',''); fillEval(k,''); fillTindak(k,''); fillTujuan(k,''); });
$(document).on('change','#edit_komponen',function(){ var k=$(this).val(); fillProgram(k,'#edit_program_sel',''); fillIndikator(k,'#edit_indikator','#edit_target',''); fillEvalEdit(k,''); fillTindakEdit(k,''); fillTujuanEdit(k,''); });
$(document).on('change','#add_indikator',function(){ var tg=$(this).find('option:selected').data('target'); if(tg) $('#add_target').val(tg); });
$(document).on('change','#edit_indikator',function(){ var tg=$(this).find('option:selected').data('target'); if(tg) $('#edit_target').val(tg); });
function fmtRupiah(v){ var s=(v+'').split('.')[0].split(',')[0].replace(/\D/g,''); return s?Number(s).toLocaleString('id-ID'):''; }
function bindRupiah(sel){ $(document).on('input', sel, function(){ var p=this.selectionStart, l=this.value.length; this.value=fmtRupiah(this.value); }); $(document).on('blur', sel, function(){ this.value=fmtRupiah(this.value); }); }
$(document).ready(function(){
 bindRupiah('input[name="anggaran"]'); bindRupiah('#edit_anggaran');
 $('form').on('submit', function(){ $(this).find('input[name="anggaran"]').each(function(){ this.value=this.value.replace(/\D/g,''); }); });
 var t=$('#table-proker').DataTable({scrollX:true, autoWidth:false, paging:true, pageLength:10, language:{search:"Cari:", lengthMenu:"Tampilkan _MENU_", zeroRecords:"Tidak ada data", info:"Menampilkan _START_ - _END_ dari _TOTAL_", paginate:{first:"Awal",last:"Akhir",next:"Next",previous:"Prev"}}});
 <?php if($message):?>Swal.fire({icon:'<?= $message['type']=='success'?'success':'error'?>',title:'<?= addslashes($message['text'])?>', timer:2000, showConfirmButton:false});<?php endif;?>
 $(document).on('click','.btn-edit',function(){
  var r=$(this).data('row'); if(typeof r==='string') try{r=JSON.parse(r)}catch(e){r=$(this).attr('data-row'); r=JSON.parse(r)}
  $('#edit_id').val(r.id); $('#edit_komponen').val(r.komponen); fillProgram(r.komponen,'#edit_program_sel',r.program); $('#edit_kegiatan').val(r.kegiatan);
  fillIndikator(r.komponen,'#edit_indikator','#edit_target',r.indikator);
  fillEvalEdit(r.komponen,r.evaluasi); fillTindakEdit(r.komponen,r.tindak_lanjut); fillTujuanEdit(r.komponen,r.tujuan);
  $('#edit_target').val(r.target); $('#edit_waktu_mulai').val(r.waktu_mulai||r.waktu||''); $('#edit_waktu_selesai').val(r.waktu_selesai||'');
  $('#edit_pj').val(r.penanggung_jawab); $('#edit_anggaran').val(fmtRupiah(r.anggaran)); $('#edit_sumber').val(r.sumber_dana); $('#edit_status').val(r.status||'belum_terlaksana');
  $('#edit_bukti_old').text(r.bukti ? 'File saat ini: '+r.bukti : 'Belum ada file');
  $('#editModal').modal('show');
 });
  $(document).on('click','.btn-del',function(){
  var id=$(this).data('id'), prog=$(this).data('prog');
  Swal.fire({title:'Hapus?',text:'Hapus program "'+prog+'"?',icon:'warning',showCancelButton:true,confirmButtonColor:'#d33',confirmButtonText:'Ya, Hapus'}).then(function(res){ if(res.isConfirmed){ $('#del_id').val(id); $('#delForm').submit(); }});
 });
 function buildUrl(k,s){ var p=[]; if(k&&k!='0') p.push('komponen='+k); if(s) p.push('status='+encodeURIComponent(s)); return p.length?'?'+p.join('&'):location.pathname; }
 $('#filter-komponen,#filter-status').on('change',function(){ location.href=buildUrl($('#filter-komponen').val(), $('#filter-status').val()); });
 $(document).on('click','.btn-status-cycle',function(){
  var id=$(this).data('id'), cur=$(this).data('status');
  var order=['belum_terlaksana','proses','terlaksana'], idx=order.indexOf(cur); var nxt=order[(idx+1)%3];
  $.post('',{update_status:1,id:id,status:nxt},function(r){ if(r&&r.success) location.reload(); },'json');
 });
});
</script>
