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
ensureTbJabatanMaster($pdo);

$komponen_list = [
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



$program_by_komponen=[
1=>['Perencanaan dan pengelolaan madrasah','Tata kelola madrasah','Koordinasi','Monitoring','Evaluasi manajemen'],
2=>['Pengembangan kurikulum','Perencanaan pembelajaran','Pelaksanaan pembelajaran','Asesmen','Pembelajaran inovatif','KBC dan karakter'],
3=>['Penerimaan siswa','Administrasi siswa','Kedisiplinan','Karakter','Prestasi','Perlindungan siswa'],
4=>['Pembagian tugas','Kedisiplinan','Kinerja','Kompetensi','Profesionalisme'],
5=>['Perencanaan anggaran','Pengelolaan keuangan','Pengendalian anggaran','Pelaporan','Efisiensi'],
6=>['Inventarisasi','Kebutuhan sarpras','Pengadaan','Pemeliharaan','Lingkungan'],
7=>['Persuratan','Kearsipan','Administrasi PTK','Administrasi siswa','Digitalisasi'],
8=>['Budaya religius','Ibadah','Akhlak','Karakter','Keagamaan'],
9=>['Komunikasi wali','Komite','Yayasan','Publikasi','Pengaduan'],
10=>['Pemetaan mutu','Target mutu','Peningkatan mutu','Inovasi','Evaluasi'],
11=>['Perencanaan supervisi','Supervisi administrasi','Supervisi akademik','Tindak lanjut','Evaluasi'],
12=>['Pengembangan bakat','Ekstrakurikuler','Pramuka','Kompetisi','Apresiasi'],
13=>['Administrasi digital','Sistem informasi','Database','Backup','Keamanan'],
14=>['Pemetaan mitra','Kerja sama','Pengembangan','Kemitraan pendidikan','Pengembangan sumber daya'],
15=>['Pemetaan risiko','Keamanan','Keselamatan','Kedaruratan','Perlindungan siswa','Keamanan data'],
];
$kegiatan_by_komponen=[
1=>['Menyusun program kerja kepala madrasah','Penataan struktur organisasi dan pembagian tugas','Rapat koordinasi rutin','Monitoring program kerja','Evaluasi kinerja madrasah'],
2=>['Review dan pengembangan kurikulum madrasah','Pemeriksaan perangkat pembelajaran','Supervisi dan observasi pembelajaran','Pelaksanaan dan analisis asesmen','Pengembangan media dan metode pembelajaran','Integrasi Kurikulum Berbasis Cinta dan karakter'],
3=>['Pelaksanaan PPDB','Pemutakhiran data siswa','Pembinaan tata tertib siswa','Pembinaan karakter siswa','Pembinaan siswa berprestasi','Penanganan masalah siswa'],
4=>['Penyusunan pembagian tugas PTK','Monitoring kehadiran dan kedisiplinan','Penilaian kinerja guru','Pelatihan/workshop','Coaching dan pembinaan'],
5=>['Penyusunan RKAM','Pencatatan pemasukan dan pengeluaran','Monitoring realisasi anggaran','Laporan keuangan berkala','Evaluasi penggunaan anggaran'],
6=>['Pendataan sarpras','Analisis kebutuhan','Pengadaan sarpras prioritas','Perawatan sarpras','Penataan lingkungan madrasah'],
7=>['Pengelolaan surat masuk/keluar','Penataan arsip','Pengelolaan dokumen kepegawaian','Pengelolaan dokumen siswa','Digitalisasi dokumen'],
8=>['Pembiasaan doa dan membaca Al-Qur\'an','Pembiasaan ibadah berjamaah','Pembinaan akhlakul karimah','Pembinaan disiplin dan tanggung jawab','PHBI dan kegiatan keislaman'],
9=>['Pertemuan dengan wali siswa','Koordinasi dengan komite','Koordinasi dengan yayasan','Pengelolaan website/media sosial','Pengelolaan aspirasi'],
10=>['Evaluasi kondisi madrasah','Penetapan indikator mutu','Pelaksanaan program peningkatan mutu','Pengembangan program unggulan madrasah','Evaluasi mutu tahunan'],
11=>['Penyusunan program supervisi','Pemeriksaan perangkat pembelajaran','Observasi pembelajaran','Pembinaan guru','Analisis hasil supervisi'],
12=>['Pemetaan minat dan bakat','Pelaksanaan kegiatan ekstrakurikuler','Pembinaan kepramukaan','Pembinaan lomba/olimpiade','Penghargaan prestasi'],
13=>['Digitalisasi administrasi','Pengembangan aplikasi madrasah','Pengelolaan database madrasah','Pencadangan data','Pengamanan akun dan data'],
14=>['Identifikasi calon mitra','Penyusunan kerja sama','Program inovasi madrasah','Kerja sama dengan lembaga pendidikan','Pemanfaatan dukungan mitra'],
15=>['Identifikasi risiko madrasah','Pemeriksaan lingkungan madrasah','Pembinaan keselamatan siswa','Penyusunan SOP keadaan darurat','Pencegahan dan penanganan perundungan','Backup dan perlindungan data'],
];
$tujuan_by_komponen=[
1=>['Menjadi pedoman pelaksanaan seluruh program madrasah','Mewujudkan pembagian tugas yang jelas','Meningkatkan koordinasi antarbidang','Mengetahui perkembangan pelaksanaan program','Mengetahui efektivitas pengelolaan madrasah'],
2=>['Menyesuaikan kurikulum dengan kebutuhan madrasah dan peserta didik','Menjamin kesiapan guru dalam melaksanakan pembelajaran','Meningkatkan kualitas proses pembelajaran','Mengetahui pencapaian kompetensi siswa','Meningkatkan keterlibatan siswa','Membentuk pembelajaran yang menumbuhkan karakter dan nilai keislaman'],
3=>['Mendapatkan peserta didik sesuai ketentuan','Menjamin data siswa akurat','Meningkatkan kedisiplinan','Membentuk siswa berakhlak dan bertanggung jawab','Mengembangkan potensi siswa','Memberikan layanan terhadap permasalahan siswa'],
4=>['Menjamin tugas sesuai kebutuhan dan kompetensi','Meningkatkan kedisiplinan PTK','Mengetahui capaian kinerja','Meningkatkan kompetensi PTK','Meningkatkan profesionalitas'],
5=>['Menetapkan kebutuhan dan prioritas anggaran','Mewujudkan administrasi keuangan tertib','Mengendalikan penggunaan dana','Mewujudkan transparansi dan akuntabilitas','Memastikan dana digunakan sesuai prioritas'],
6=>['Mengetahui kondisi dan jumlah sarpras','Menentukan prioritas pengadaan','Memenuhi kebutuhan pembelajaran','Menjaga kelayakan sarpras','Mewujudkan lingkungan aman, bersih dan nyaman'],
7=>['Menjamin administrasi persuratan tertib','Memudahkan pencarian dokumen','Menjamin data PTK lengkap','Menjamin data siswa akurat','Meningkatkan efisiensi administrasi'],
8=>['Membentuk budaya religius','Membentuk kedisiplinan beribadah','Membentuk perilaku terpuji','Membentuk karakter siswa','Meningkatkan pemahaman dan pengalaman keagamaan'],
9=>['Meningkatkan komunikasi','Membangun sinergi','Menyelaraskan kebijakan madrasah dan yayasan','Menyampaikan informasi madrasah','Meningkatkan kualitas pelayanan'],
10=>['Mengetahui kekuatan dan kelemahan','Menentukan arah peningkatan mutu','Meningkatkan kualitas madrasah','Meningkatkan keunggulan madrasah','Mengetahui perkembangan mutu'],
11=>['Menjamin supervisi terlaksana sistematis','Meningkatkan kelengkapan administrasi guru','Meningkatkan kualitas pembelajaran','Memperbaiki temuan supervisi','Mengetahui perkembangan guru'],
12=>['Mengetahui potensi siswa','Mengembangkan potensi siswa','Membentuk karakter dan keterampilan','Meningkatkan prestasi siswa','Meningkatkan motivasi siswa'],
13=>['Meningkatkan efisiensi kerja','Mempermudah pengelolaan data','Menjamin data terpusat dan akurat','Mencegah kehilangan data','Melindungi data madrasah'],
14=>['Menemukan pihak yang dapat mendukung madrasah','Meningkatkan dukungan terhadap program madrasah','Menghasilkan inovasi sesuai kebutuhan','Meningkatkan kualitas pendidikan','Meningkatkan sumber daya madrasah'],
15=>['Mengetahui potensi risiko','Menciptakan lingkungan aman','Mengurangi risiko kecelakaan','Menjamin kesiapan menghadapi keadaan darurat','Mewujudkan lingkungan aman bagi siswa','Mencegah kehilangan/penyalahgunaan data'],
];
$indikator_by_komponen=[
1=>[
 ['t'=>'Program kerja tersusun, disahkan, dan tersosialisasi','target'=>'100%'],
 ['t'=>'Seluruh PTK memiliki tugas dan tanggung jawab','target'=>'100%'],
 ['t'=>'Rapat terlaksana sesuai jadwal dan menghasilkan keputusan','target'=>'≥90%'],
 ['t'=>'Program terpantau secara berkala','target'=>'≥90%'],
 ['t'=>'Evaluasi terlaksana dan menghasilkan rekomendasi','target'=>'100%'],
],
2=>[
 ['t'=>'Dokumen kurikulum tersedia dan diperbarui','target'=>'100%'],
 ['t'=>'Perangkat pembelajaran tersedia dan sesuai','target'=>'100%'],
 ['t'=>'Guru melaksanakan pembelajaran sesuai perencanaan','target'=>'≥90%'],
 ['t'=>'Asesmen terlaksana dan hasilnya dianalisis','target'=>'100%'],
 ['t'=>'Guru menggunakan metode/media yang sesuai','target'=>'≥90%'],
 ['t'=>'Nilai karakter terintegrasi dalam pembelajaran','target'=>'≥90%'],
],
3=>[
 ['t'=>'PPDB terlaksana tertib dan terdokumentasi','target'=>'100%'],
 ['t'=>'Data siswa diperbarui secara berkala','target'=>'100%'],
 ['t'=>'Pelanggaran siswa menurun','target'=>'≥90%'],
 ['t'=>'Program pembinaan terlaksana','target'=>'≥90%'],
 ['t'=>'Siswa mengikuti dan memperoleh prestasi','target'=>'100%'],
 ['t'=>'Kasus ditangani dan terdokumentasi','target'=>'100%'],
],
4=>[
 ['t'=>'Seluruh PTK memiliki tugas jelas','target'=>'100%'],
 ['t'=>'Kehadiran dan ketepatan waktu meningkat','target'=>'≥90%'],
 ['t'=>'Penilaian seluruh guru terlaksana','target'=>'100%'],
 ['t'=>'PTK mengikuti kegiatan pengembangan','target'=>'≥90%'],
 ['t'=>'Guru menunjukkan perbaikan kinerja','target'=>'≥90%'],
],
5=>[
 ['t'=>'RKAM tersusun sesuai kebutuhan','target'=>'100%'],
 ['t'=>'Seluruh transaksi tercatat','target'=>'100%'],
 ['t'=>'Realisasi sesuai rencana','target'=>'≥90%'],
 ['t'=>'Laporan tersedia tepat waktu','target'=>'100%'],
 ['t'=>'Penggunaan dana efektif dan efisien','target'=>'≥90%'],
],
6=>[
 ['t'=>'Data inventaris lengkap','target'=>'100%'],
 ['t'=>'Daftar kebutuhan tersusun berdasarkan prioritas','target'=>'100%'],
 ['t'=>'Sarpras prioritas tersedia','target'=>'≥90%'],
 ['t'=>'Sarpras terawat dan dapat digunakan','target'=>'≥90%'],
 ['t'=>'Lingkungan tertata','target'=>'≥90%'],
],
7=>[
 ['t'=>'Seluruh surat tercatat dan terarsip','target'=>'100%'],
 ['t'=>'Dokumen tertata dan mudah ditemukan','target'=>'100%'],
 ['t'=>'Dokumen PTK lengkap','target'=>'100%'],
 ['t'=>'Dokumen siswa lengkap','target'=>'100%'],
 ['t'=>'Dokumen prioritas tersedia dalam bentuk digital','target'=>'≥80%'],
],
8=>[
 ['t'=>'Pembiasaan terlaksana rutin','target'=>'≥90%'],
 ['t'=>'Siswa mengikuti kegiatan secara konsisten','target'=>'≥90%'],
 ['t'=>'Perilaku positif meningkat','target'=>'≥90%'],
 ['t'=>'Kedisiplinan meningkat','target'=>'≥90%'],
 ['t'=>'Kegiatan terlaksana','target'=>'100%'],
],
9=>[
 ['t'=>'Pertemuan terlaksana dan informasi tersampaikan','target'=>'≥90%'],
 ['t'=>'Koordinasi terlaksana','target'=>'≥90%'],
 ['t'=>'Koordinasi terdokumentasi','target'=>'100%'],
 ['t'=>'Informasi kegiatan dipublikasikan','target'=>'≥90%'],
 ['t'=>'Aspirasi ditangani','target'=>'100%'],
],
10=>[
 ['t'=>'Pemetaan tersedia','target'=>'100%'],
 ['t'=>'Target mutu terukur','target'=>'100%'],
 ['t'=>'Target prioritas tercapai','target'=>'≥90%'],
 ['t'=>'Program unggulan terlaksana','target'=>'≥80%'],
 ['t'=>'Laporan mutu tersedia','target'=>'100%'],
],
11=>[
 ['t'=>'Program dan jadwal tersedia','target'=>'100%'],
 ['t'=>'Administrasi guru memenuhi target','target'=>'100%'],
 ['t'=>'Guru mencapai standar yang ditetapkan madrasah','target'=>'≥90%'],
 ['t'=>'Temuan ditindaklanjuti','target'=>'≥90%'],
 ['t'=>'Tersedia rekap hasil supervisi','target'=>'100%'],
],
12=>[
 ['t'=>'Data minat/bakat tersedia','target'=>'100%'],
 ['t'=>'Kegiatan terlaksana sesuai jadwal','target'=>'≥90%'],
 ['t'=>'Kegiatan berjalan','target'=>'≥90%'],
 ['t'=>'Siswa mengikuti kompetisi','target'=>'≥90%'],
 ['t'=>'Prestasi terdokumentasi dan diapresiasi','target'=>'100%'],
],
13=>[
 ['t'=>'Administrasi prioritas terdigitalisasi','target'=>'≥90%'],
 ['t'=>'Sistem dapat digunakan','target'=>'≥90%'],
 ['t'=>'Database terbarui','target'=>'100%'],
 ['t'=>'Backup dilakukan berkala','target'=>'100%'],
 ['t'=>'Akses data terkendali','target'=>'100%'],
],
14=>[
 ['t'=>'Database calon mitra tersedia','target'=>'100%'],
 ['t'=>'Kerja sama terdokumentasi','target'=>'100%'],
 ['t'=>'Program inovasi terlaksana','target'=>'≥80%'],
 ['t'=>'Program kerja sama terlaksana','target'=>'≥90%'],
 ['t'=>'Dukungan digunakan sesuai tujuan','target'=>'≥90%'],
],
15=>[
 ['t'=>'Daftar risiko tersedia','target'=>'100%'],
 ['t'=>'Risiko keamanan teridentifikasi dan ditangani','target'=>'100%'],
 ['t'=>'Siswa memahami prosedur keselamatan','target'=>'≥90%'],
 ['t'=>'SOP tersedia dan dipahami','target'=>'100%'],
 ['t'=>'Kasus dicegah/ditangani sesuai prosedur','target'=>'100%'],
 ['t'=>'Backup dan pengamanan berjalan','target'=>'100%'],
],
];
$evaluasi_by_komponen=[
1=>['Membandingkan rencana dengan pelaksanaan','Mengevaluasi kesesuaian tugas dan pelaksanaan','Memeriksa notulen dan tindak lanjut','Membandingkan target dan realisasi','Analisis capaian program'],
2=>['Menelaah kesesuaian dokumen dan pelaksanaan','Pemeriksaan administrasi guru','Observasi kelas menggunakan instrumen','Analisis hasil belajar','Observasi dan refleksi pembelajaran','Observasi perangkat dan praktik pembelajaran'],
3=>['Evaluasi proses dan hasil PPDB','Pemeriksaan database','Rekap pelanggaran','Observasi dan catatan perkembangan','Rekap prestasi','Evaluasi penyelesaian kasus'],
4=>['Evaluasi beban dan pelaksanaan tugas','Analisis absensi','Analisis hasil penilaian','Evaluasi hasil pelatihan','Evaluasi hasil coaching'],
5=>['Membandingkan rencana dengan kebutuhan','Pemeriksaan pembukuan','Membandingkan anggaran dan realisasi','Pemeriksaan laporan dan bukti','Analisis realisasi'],
6=>['Pemeriksaan fisik dan data','Membandingkan kebutuhan dan ketersediaan','Evaluasi pemanfaatan','Pemeriksaan berkala','Observasi kondisi lingkungan'],
7=>['Pemeriksaan buku agenda/arsip','Pemeriksaan arsip','Audit administrasi','Pemeriksaan dokumen','Pemeriksaan database'],
8=>['Observasi','Rekap dan observasi','Observasi dan catatan pembinaan','Analisis pelanggaran','Evaluasi kegiatan'],
9=>['Evaluasi partisipasi dan masukan','Evaluasi hasil koordinasi','Evaluasi hasil rapat','Evaluasi konten dan jangkauan','Evaluasi penyelesaian'],
10=>['Analisis data','Membandingkan target dan capaian','Evaluasi capaian','Evaluasi dampak','Analisis capaian tahunan'],
11=>['Evaluasi kesesuaian jadwal','Analisis instrumen','Analisis hasil observasi','Monitoring tindak lanjut','Membandingkan hasil antarperiode'],
12=>['Analisis pemetaan','Evaluasi kehadiran dan kegiatan','Evaluasi kegiatan','Evaluasi hasil kompetisi','Evaluasi pencapaian'],
13=>['Evaluasi penggunaan','Evaluasi fungsi sistem','Pemeriksaan data','Pemeriksaan backup','Audit akses'],
14=>['Evaluasi relevansi mitra','Evaluasi pelaksanaan','Evaluasi hasil','Evaluasi manfaat','Evaluasi manfaat'],
15=>['Analisis tingkat risiko','Pemeriksaan berkala','Observasi/simulasi','Simulasi/evaluasi','Evaluasi kasus','Audit data'],
];
$tindak_lanjut_by_komponen=[
1=>['Revisi program yang belum sesuai','Penyesuaian pembagian tugas','Menindaklanjuti keputusan rapat','Pendampingan terhadap program yang tertinggal','Menetapkan program perbaikan'],
2=>['Penyempurnaan dokumen','Pendampingan guru yang belum lengkap','Pembinaan dan supervisi ulang','Remedial, pengayaan, dan perbaikan pembelajaran','Workshop dan pendampingan','Penguatan praktik pembelajaran'],
3=>['Perbaikan mekanisme PPDB','Perbaikan data','Pembinaan individual/kelompok','Program pembinaan lanjutan','Pembinaan intensif','Pendampingan dan koordinasi dengan wali'],
4=>['Penyesuaian tugas','Pembinaan bagi yang membutuhkan','Pembinaan dan pengembangan','Pendampingan penerapan hasil pelatihan','Coaching lanjutan/supervisi ulang'],
5=>['Revisi prioritas bila diperlukan','Perbaikan administrasi','Pengendalian/pengalihan sesuai ketentuan','Koreksi dan penyempurnaan','Penyesuaian prioritas anggaran'],
6=>['Pembaruan inventaris','Menetapkan prioritas','Pengadaan bertahap','Perbaikan/pemeliharaan','Penataan lanjutan'],
7=>['Penataan arsip','Digitalisasi/penataan ulang','Melengkapi dokumen','Pemutakhiran data','Backup dan pembaruan'],
8=>['Penguatan pembiasaan','Pembinaan siswa','Pendampingan','Pembinaan lanjutan','Penyempurnaan program'],
9=>['Menindaklanjuti masukan','Pelaksanaan kesepakatan','Tindak lanjut keputusan','Peningkatan publikasi','Perbaikan pelayanan'],
10=>['Menentukan prioritas','Revisi target/program','Program perbaikan','Pengembangan berkelanjutan','Menetapkan program tahun berikutnya'],
11=>['Penyesuaian jadwal','Pendampingan','Coaching/pembinaan','Supervisi ulang','Program pengembangan kompetensi'],
12=>['Penempatan kegiatan sesuai potensi','Perbaikan program','Penguatan pembinaan','Pembinaan lanjutan','Pengembangan pembinaan'],
13=>['Pengembangan sistem','Perbaikan fitur','Sinkronisasi data','Penjadwalan backup otomatis','Penguatan keamanan'],
14=>['Menindaklanjuti calon mitra','Perpanjangan/pengembangan','Pengembangan program','Penguatan kerja sama','Pengembangan kemitraan'],
15=>['Menetapkan mitigasi','Perbaikan fasilitas','Pembinaan ulang','Penyempurnaan SOP','Pendampingan dan pencegahan','Penguatan sistem keamanan'],
];
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
    $stmt=$pdo->prepare("INSERT INTO tb_program_kerja (komponen,program,kegiatan,tujuan,indikator,target,waktu_mulai,waktu_selesai,penanggung_jawab,anggaran,sumber_dana,bukti,evaluasi,tindak_lanjut,status,tahun_ajaran) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
   $ok=$stmt->execute([$komponen,$program,$kegiatan,trim($_POST['tujuan']??''),trim($_POST['indikator']??''),trim($_POST['target']??''),$wm,$ws,trim($_POST['penanggung_jawab']??''),$anggaran,trim($_POST['sumber_dana']??''),$bukti,trim($_POST['evaluasi']??''),trim($_POST['tindak_lanjut']??''),$st,$tahun_ajaran]);
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
     $stmt=$pdo->prepare("UPDATE tb_program_kerja SET komponen=?,program=?,kegiatan=?,tujuan=?,indikator=?,target=?,waktu_mulai=?,waktu_selesai=?,penanggung_jawab=?,anggaran=?,sumber_dana=?,bukti=?,evaluasi=?,tindak_lanjut=?,status=? WHERE id=?");
   $ok=$stmt->execute([$komponen,$program,$kegiatan,trim($_POST['tujuan']??''),trim($_POST['indikator']??''),trim($_POST['target']??''),$wm,$ws,trim($_POST['penanggung_jawab']??''),$anggaran,trim($_POST['sumber_dana']??''),$bukti,trim($_POST['evaluasi']??''),trim($_POST['tindak_lanjut']??''),$st,$id]);
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
<td><?=nl2br(htmlspecialchars($r['evaluasi']??'-'))?></td>
<td><?=nl2br(htmlspecialchars($r['tindak_lanjut']??'-'))?></td>
<td class="text-center"><?php $sv=$r['status']??'belum_terlaksana'; if(!isset($status_opts[$sv])) $sv='belum_terlaksana'; $bc=$status_badge[$sv]; $ic=$status_icon[$sv]; $sl=$status_opts[$sv];?><span class="badge <?=$bc?>"><i class="fas <?=$ic?>"></i> <?=htmlspecialchars($sl)?></span></td>
<?php if($is_editable):?>
<td class="text-center">
<div class="btn-group">
<button class="btn btn-warning btn-sm btn-edit" data-row='<?=htmlspecialchars(json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE),ENT_QUOTES,'UTF-8')?>' title="Edit"><i class="fas fa-edit"></i></button>
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
<div class="form-group"><label>Kegiatan *</label><input type="text" id="add_kegiatan" name="kegiatan" class="form-control" readonly required placeholder="Pilih Program dulu"></div>
<div class="row">
<div class="col-md-6"><div class="form-group"><label>Tujuan</label><input type="text" id="add_tujuan" name="tujuan" class="form-control" readonly placeholder="Auto"></div></div>
<div class="col-md-6"><div class="form-group"><label>Indikator Keberhasilan</label><div class="input-group"><input type="text" id="add_indikator" name="indikator" class="form-control" readonly placeholder="Auto"><div class="input-group-append"><span class="input-group-text" id="add_target_badge"></span></div></div></div></div>
</div>
<input type="hidden" name="target" id="add_target">
<div class="row">
<div class="col-md-3"><div class="form-group"><label>Penanggung Jawab</label><select name="penanggung_jawab" class="form-control"><option value="">-- Pilih Jabatan --</option><?php foreach(getJabatanList($pdo) as $j):?><option value="<?=htmlspecialchars($j['nama_jabatan'])?>"><?=htmlspecialchars($j['nama_jabatan'])?></option><?php endforeach;?></select></div></div>
<div class="col-md-2"><div class="form-group"><label>Anggaran (Rp)</label><input type="text" name="anggaran" class="form-control uang" placeholder="0"></div></div>
<div class="col-md-2"><div class="form-group"><label>Sumber Dana</label><input type="text" name="sumber_dana" class="form-control" placeholder="BOS / BOP / Komite"></div></div>
<div class="col-md-2"><div class="form-group"><label>Status</label><select name="status" class="form-control"><?php foreach($status_opts as $sv=>$sl):?><option value="<?=$sv?>"><?=htmlspecialchars($sl)?></option><?php endforeach;?></select></div></div>
</div>
<div class="row">
<div class="col-md-6"><div class="form-group"><label>Bukti / Dokumen (pdf/jpg/doc/xls max 5MB)</label><input type="file" name="bukti" class="form-control"></div></div>
<div class="col-md-3"><div class="form-group"><label>Evaluasi</label><input type="text" id="add_evaluasi" name="evaluasi" class="form-control" readonly placeholder="Auto"></div></div>
<div class="col-md-3"><div class="form-group"><label>Tindak Lanjut</label><input type="text" id="add_tindak" name="tindak_lanjut" class="form-control" readonly placeholder="Auto"></div></div>
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
<div class="form-group"><label>Kegiatan *</label><input type="text" id="edit_kegiatan" name="kegiatan" class="form-control" readonly required placeholder="Pilih Program dulu"></div>
<div class="row">
<div class="col-md-6"><div class="form-group"><label>Tujuan</label><input type="text" id="edit_tujuan" name="tujuan" class="form-control" readonly placeholder="Auto"></div></div>
<div class="col-md-6"><div class="form-group"><label>Indikator Keberhasilan</label><div class="input-group"><input type="text" id="edit_indikator" name="indikator" class="form-control" readonly placeholder="Auto"><input type="hidden" id="edit_target_h"><div class="input-group-append"><span class="input-group-text" id="edit_target_badge"></span></div></div></div></div>
</div>
<input type="hidden" name="target" id="edit_target">
<div class="row">
<div class="col-md-2"><div class="form-group"><label>Penanggung Jawab</label><select name="penanggung_jawab" id="edit_pj" class="form-control"><option value="">-- Pilih Jabatan --</option><?php foreach(getJabatanList($pdo) as $j):?><option value="<?=htmlspecialchars($j['nama_jabatan'])?>"><?=htmlspecialchars($j['nama_jabatan'])?></option><?php endforeach;?></select></div></div>
<div class="col-md-2"><div class="form-group"><label>Anggaran (Rp)</label><input type="text" name="anggaran" id="edit_anggaran" class="form-control"></div></div>
<div class="col-md-2"><div class="form-group"><label>Sumber Dana</label><input type="text" name="sumber_dana" id="edit_sumber" class="form-control"></div></div>
<div class="col-md-2"><div class="form-group"><label>Status</label><select name="status" id="edit_status" class="form-control"><?php foreach($status_opts as $sv=>$sl):?><option value="<?=$sv?>"><?=htmlspecialchars($sl)?></option><?php endforeach;?></select></div></div>
</div>
<div class="row">
<div class="col-md-6"><div class="form-group"><label>Bukti / Dokumen (kosongkan jika tidak ganti)</label><input type="file" name="bukti" class="form-control"><small id="edit_bukti_old" class="text-muted"></small></div></div>
<div class="col-md-3"><div class="form-group"><label>Evaluasi</label><input type="text" id="edit_evaluasi" name="evaluasi" class="form-control" readonly placeholder="Auto"></div></div>
<div class="col-md-3"><div class="form-group"><label>Tindak Lanjut</label><input type="text" id="edit_tl" name="tindak_lanjut" class="form-control" readonly placeholder="Auto"></div></div>
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
if(!matriks || typeof matriks!=='object' || !Object.keys(matriks).length){
  var _pm=<?=json_encode($program_by_komponen ?? [], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  var _km=<?=json_encode($kegiatan_by_komponen ?? [], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  var _tm=<?=json_encode($tujuan_by_komponen ?? [], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  var _im=<?=json_encode($indikator_by_komponen ?? [], JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  if(_pm && Object.keys(_pm).length){ matriks={}; Object.keys(_pm).forEach(function(k){ (_pm[k]||[]).forEach(function(p,i){ if(!matriks[k]) matriks[k]=[]; var ind=_im[k]&&_im[k][i]?_im[k][i]:{t:'',target:''}; matriks[k].push({program:p, kegiatan:(_km[k]||[])[i]||'', tujuan:(_tm[k]||[])[i]||(_tm[k]||[])[0]||'', indikator:ind.t||ind.indikator||'', target:ind.target||'', evaluasi:'', tindak:''}); }); }); }
}
function idxByProgram(k, prog){ var list=matriks[k]||matriks[String(k)]||[]; for(var i=0;i<list.length;i++) if((list[i].program||'').trim()===String(prog).trim()) return i; return -1; }
function syncMatriksAdd(){
 var k=$('#addModal select[name="komponen"]').val(); var prog=$('#add_program_sel').val();
 if(!k || !prog){ $('#add_kegiatan,#add_tujuan,#add_indikator,#add_target').val(''); $('#add_target_badge').text(''); $('#add_evaluasi,#add_tindak').val(''); return; }
 var idx=idxByProgram(k,prog); if(idx<0) return;
 var m=matriks[k][idx]||matriks[String(k)][idx];
 $('#add_kegiatan').val(m.kegiatan||'');
 $('#add_tujuan').val(m.tujuan||'');
 $('#add_indikator').val(m.indikator||''); $('#add_target').val(m.target||''); $('#add_target_badge').text(m.target||'');
 $('#add_evaluasi').val(m.evaluasi||'');
 $('#add_tindak').val(m.tindak||'');
}
function syncMatriksEdit(){
 var k=$('#edit_komponen').val(); var prog=$('#edit_program_sel').val();
 if(!k || !prog){ $('#edit_kegiatan,#edit_tujuan,#edit_indikator,#edit_target').val(''); $('#edit_target_badge').text(''); $('#edit_evaluasi,#edit_tl').val(''); return; }
 var idx=idxByProgram(k,prog); if(idx<0) return;
 var m=matriks[k][idx]||matriks[String(k)][idx];
 $('#edit_kegiatan').val(m.kegiatan||'');
 $('#edit_tujuan').val(m.tujuan||'');
 $('#edit_indikator').val(m.indikator||''); $('#edit_target').val(m.target||''); $('#edit_target_badge').text(m.target||'');
 $('#edit_evaluasi').val(m.evaluasi||'');
 $('#edit_tl').val(m.tindak||'');
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
$(document).on('change','#addModal select[name="komponen"]',function(){ var k=$(this).val(); fillProgram(k,'#add_program_sel',''); $('#add_kegiatan,#add_tujuan,#add_indikator,#add_target,#add_evaluasi,#add_tindak').val(''); $('#add_target_badge').text(''); });
$(document).on('change','#edit_komponen',function(){ var k=$(this).val(); fillProgram(k,'#edit_program_sel',''); $('#edit_kegiatan,#edit_tujuan,#edit_indikator,#edit_target,#edit_evaluasi,#edit_tl').val(''); $('#edit_target_badge').text(''); });
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
