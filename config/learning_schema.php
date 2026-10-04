<?php
// Skema Database untuk modul Guru & Wali Kelas (19 Fitur Pembelajaran & Pengelolaan Kelas)
// Otomatis membuat tabel-tabel jika belum ada saat di-include.

if (!function_exists('ensure_learning_schema')) {
    function ensure_learning_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return;

        // 1. Perangkat Pembelajaran
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_perangkat_pembelajaran (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                jenis_perangkat VARCHAR(50) NOT NULL,
                judul VARCHAR(255) NOT NULL,
                id_mapel INT NULL,
                id_kelas INT NULL,
                materi_tp VARCHAR(255) NULL,
                semester VARCHAR(20) NULL,
                tahun_ajaran VARCHAR(30) NULL,
                file_path VARCHAR(255) NULL,
                status ENUM('Draft','Aktif','Arsip') NOT NULL DEFAULT 'Draft',
                cp TEXT NULL,
                tp TEXT NULL,
                materi TEXT NULL,
                tujuan_pembelajaran TEXT NULL,
                indikator TEXT NULL,
                deskripsi TEXT NULL,
                isi_dokumen LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_kelas (id_kelas),
                INDEX idx_mapel (id_mapel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 2. Tugas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_tugas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                judul VARCHAR(255) NOT NULL,
                id_mapel INT NULL,
                id_kelas INT NULL,
                materi_tp VARCHAR(255) NULL,
                jenis_tugas VARCHAR(50) NOT NULL DEFAULT 'Individu',
                instruksi TEXT NULL,
                tgl_mulai DATETIME NULL,
                deadline DATETIME NULL,
                nilai_maksimal INT NOT NULL DEFAULT 100,
                lampiran VARCHAR(255) NULL,
                status ENUM('Draft','Aktif','Selesai','Arsip') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_kelas (id_kelas),
                INDEX idx_mapel (id_mapel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 3. Pengumpulan Tugas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_tugas_pengumpulan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_tugas INT NOT NULL,
                id_siswa INT NOT NULL,
                file_path VARCHAR(255) NULL,
                catatan_siswa TEXT NULL,
                tgl_kumpul DATETIME NULL,
                keterlambatan VARCHAR(100) NULL,
                nilai DECIMAL(5,2) NULL,
                status_periksa ENUM('Belum Diperiksa','Sudah Diperiksa','Perlu Revisi') NOT NULL DEFAULT 'Belum Diperiksa',
                feedback TEXT NULL,
                diperiksa_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_tugas_siswa (id_tugas, id_siswa),
                INDEX idx_tugas (id_tugas),
                INDEX idx_siswa (id_siswa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 4 & 5. Bank Soal (1 paket = banyak butir berbagi kode_paket)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_bank_soal (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                kode_soal VARCHAR(50) NOT NULL,
                kode_paket VARCHAR(50) NULL,
                jenis_soal ENUM('Pilihan Ganda','Pilihan Ganda Kompleks','Menjodohkan','Isian Singkat','Uraian') NOT NULL DEFAULT 'Pilihan Ganda',
                id_mapel INT NULL,
                id_kelas INT NULL,
                kurikulum VARCHAR(30) NULL DEFAULT 'PERMENDIKDASMEN_046',
                semester VARCHAR(20) NULL,
                jenis_asesmen VARCHAR(60) NULL,
                topik VARCHAR(255) NOT NULL DEFAULT '',
                sub_topik VARCHAR(255) NULL,
                materi_tp VARCHAR(255) NULL,
                cp TEXT NULL,
                tp TEXT NULL,
                atp TEXT NULL,
                indikator TEXT NULL,
                level_kognitif VARCHAR(10) NULL DEFAULT 'L2',
                tingkat_kesulitan ENUM('Mudah','Sedang','Sukar') NOT NULL DEFAULT 'Sedang',
                bobot DECIMAL(5,2) NOT NULL DEFAULT 1.00,
                pertanyaan TEXT NOT NULL,
                pilihan_jawaban TEXT NULL,
                jawaban_benar TEXT NULL,
                pembahasan TEXT NULL,
                file_soal VARCHAR(255) NULL,
                status ENUM('Draft','Aktif','Arsip') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_mapel (id_mapel),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Migrasi bank soal lama -> 5 bentuk + kolom kurikulum/CP/TP/ATP
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM tb_bank_soal")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (in_array('jenis_soal', (array)$cols, true)) {
                $pdo->exec("ALTER TABLE tb_bank_soal MODIFY COLUMN jenis_soal ENUM('Pilihan Ganda','Pilihan Ganda Kompleks','Menjodohkan','Isian Singkat','Uraian') NOT NULL DEFAULT 'Pilihan Ganda'");
                $pdo->exec("UPDATE tb_bank_soal SET jenis_soal = 'Isian Singkat' WHERE jenis_soal = 'Benar/Salah'");
            }
            foreach ([
                "kode_paket VARCHAR(50) NULL",
                "kurikulum VARCHAR(30) NULL DEFAULT 'PERMENDIKDASMEN_046'",
                "semester VARCHAR(20) NULL",
                "jenis_asesmen VARCHAR(60) NULL",
                "topik VARCHAR(255) NOT NULL DEFAULT ''",
                "sub_topik VARCHAR(255) NULL",
                "level_kognitif VARCHAR(10) NULL",
                "cp TEXT NULL",
                "tp TEXT NULL",
                "atp TEXT NULL",
                "file_soal VARCHAR(255) NULL",
            ] as $colDef) {
                $colName = explode(' ', trim($colDef), 2)[0];
                $has = $pdo->query("SHOW COLUMNS FROM tb_bank_soal LIKE '" . addslashes($colName) . "'")->fetch(PDO::FETCH_ASSOC);
                if (!$has) {
                    $pdo->exec("ALTER TABLE tb_bank_soal ADD COLUMN {$colDef}");
                }
            }
        } catch (Throwable $e) { /* abaikan bila tabel belum ada */
        }

        // Migrasi perangkat: kolom isi_dokumen untuk hasil Generate AI
        try {
            $has_isi = $pdo->query("SHOW COLUMNS FROM tb_perangkat_pembelajaran LIKE 'isi_dokumen'")->fetch(PDO::FETCH_ASSOC);
            if (!$has_isi) {
                $pdo->exec("ALTER TABLE tb_perangkat_pembelajaran ADD COLUMN isi_dokumen LONGTEXT NULL AFTER deskripsi");
            }
        } catch (Throwable $e) { /* abaikan bila tabel belum ada */
        }

        // 6. Bahan Ajar
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_bahan_ajar (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                judul VARCHAR(255) NOT NULL,
                jenis ENUM('PDF','Video','Link','Presentasi','LKPD','Dokumen') NOT NULL DEFAULT 'PDF',
                id_mapel INT NULL,
                id_kelas INT NULL,
                materi_tp VARCHAR(255) NULL,
                file_link TEXT NOT NULL,
                semester VARCHAR(20) NULL,
                tahun_ajaran VARCHAR(30) NULL,
                status ENUM('Draft','Aktif','Arsip') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_mapel (id_mapel),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 7 & 8. Catatan Perkembangan Siswa & Master Data Perkembangan
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_master_perkembangan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NULL,
                aspek VARCHAR(100) NOT NULL,
                kendala VARCHAR(255) NOT NULL,
                tindak_lanjut TEXT NOT NULL,
                ringkasan TEXT NOT NULL,
                perkembangan_akademik TEXT NULL,
                perkembangan_sikap TEXT NULL,
                perkembangan_keterampilan TEXT NULL,
                keaktifan TEXT NULL,
                potensi TEXT NULL,
                rekomendasi TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_aspek (aspek),
                INDEX idx_guru (id_guru)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Pastikan kolom-kolom rincian aspek tersedia jika tabel sudah ada sebelumnya
        foreach ([
            'perkembangan_akademik' => 'TEXT NULL',
            'perkembangan_sikap' => 'TEXT NULL',
            'perkembangan_keterampilan' => 'TEXT NULL',
            'keaktifan' => 'TEXT NULL',
            'potensi' => 'TEXT NULL',
            'rekomendasi' => 'TEXT NULL'
        ] as $mCol => $mDef) {
            try {
                $check = $pdo->query("SHOW COLUMNS FROM tb_master_perkembangan LIKE '$mCol'")->fetch();
                if (!$check) {
                    $pdo->exec("ALTER TABLE tb_master_perkembangan ADD COLUMN $mCol $mDef");
                }
            } catch (Throwable $e) {}
        }

        // Seed data awal master perkembangan jika masih kosong.
        // Didefinisikan di luar blok try agar bisa dipakai ulang oleh backfill.
        $seeds = [
                    // 1. Akademik
                    [
                        'aspek' => 'Akademik',
                        'kendala' => 'Kesulitan memahami konsep materi yang abstrak dan operasi hitung tingkat lanjut',
                        'tindak_lanjut' => 'Memberikan bimbingan remedial personal, visualisasi media konkret, dan tutor sebaya saat jam pendampingan',
                        'ringkasan' => 'Peserta didik menunjukkan motivasi belajar yang baik namun memerlukan bimbingan tambahan pada materi abstrak; tindak lanjut berupa tutor sebaya dan latihan terbimbing terbukti efektif meningkatkan pemahaman.',
                        'perkembangan_akademik' => 'Capaian pemahaman materi di bawah rata-rata kelas; perlu pengulangan konsep dasar secara bertahap.',
                        'perkembangan_sikap' => 'Bersikap terbuka dan mau menerima bimbingan tambahan dari guru.',
                        'perkembangan_keterampilan' => 'Keterampilan berhitung dan penyelesaian soal latihan meningkat perlahan dengan pendampingan.',
                        'keaktifan' => 'Cukup aktif bertanya ketika mengalami kesulitan memahami materi.',
                        'potensi' => 'Berpotensi berkembang baik bila didampingi dengan metode visual dan latihan rutin.',
                        'rekomendasi' => 'Lanjutkan remedial dan latihan mandiri terbimbing minimal 2 kali seminggu.'
                    ],
                    [
                        'aspek' => 'Akademik',
                        'kendala' => 'Kurang fokus saat jam pelajaran berlangsung dan lambat menyelesaikan tugas mandiri di kelas',
                        'tindak_lanjut' => 'Mengatur posisi duduk di barisan depan, memecah instruksi tugas menjadi bagian kecil, dan memberikan apresiasi berkala atas progres yang dicapai',
                        'ringkasan' => 'Konsentrasi belajar peserta didik perlu terus didorong; setelah penyesuaian tempat duduk dan instruksi bertahap, peserta didik mampu menyelesaikan tugas dengan lebih tenang dan tepat waktu.',
                        'perkembangan_akademik' => 'Capaian akademik cukup, namun kecepatan penyelesaian tugas di bawah standar kelas.',
                        'perkembangan_sikap' => 'Sikap terhadap guru dan teman baik, hanya perlu pembiasaan disiplin waktu belajar.',
                        'perkembangan_keterampilan' => 'Keterampilan mengerjakan tugas mandiri membaik bila instruksi dipecah menjadi langkah kecil.',
                        'keaktifan' => 'Keaktifan sedang; perlu dipancing dengan pertanyaan langsung dan apresiasi.',
                        'potensi' => 'Mampu mengikuti pelajaran dengan baik bila suasana belajar kondusif.',
                        'rekomendasi' => 'Pertahankan posisi duduk strategis dan pantau penyelesaian tugas harian.'
                    ],
                    [
                        'aspek' => 'Akademik',
                        'kendala' => 'Hasil asesmen harian masih di bawah kriteria ketercapaian tujuan pembelajaran (KKTP)',
                        'tindak_lanjut' => 'Mengikutsertakan dalam program bimbingan remedial terstruktur dan memberikan modul latihan mandiri bertahap',
                        'ringkasan' => 'Capaian tujuan pembelajaran belum optimal pada asesmen awal; peserta didik dijadwalkan mengikuti remedial dan latihan mandiri dengan pengawasan intensif.',
                        'perkembangan_akademik' => 'Belum mencapai KKTP pada beberapa tujuan pembelajaran; dijadwalkan remedial.',
                        'perkembangan_sikap' => 'Sikap belajar positif dan tidak putus asa menghadapi nilai yang kurang.',
                        'perkembangan_keterampilan' => 'Keterampilan menjawab soal pilihan ganda cukup, perlu penguatan soal uraian.',
                        'keaktifan' => 'Hadir mengikuti remedial sesuai jadwal yang ditentukan.',
                        'potensi' => 'Memiliki kemauan kuat untuk memperbaiki hasil belajar.',
                        'rekomendasi' => 'Ikuti remedial terstruktur dan ulangan susulan setelah penguasaan materi membaik.'
                    ],
                    // 2. Sikap & Karakter
                    [
                        'aspek' => 'Sikap & Karakter',
                        'kendala' => 'Kurang sabar dalam antre dan sering menyela pembicaraan guru/teman saat kegiatan kelas berlangsung',
                        'tindak_lanjut' => 'Pembiasaan adab mendengarkan, penerapan konsekuensi logis positif, dan penguatan adab santun madrasah',
                        'ringkasan' => 'Sikap sosial berkembang baik; sedang dibimbing penguatan adab berbicara, mendengarkan orang lain, dan saling menghargai antarteman di madrasah.',
                        'perkembangan_akademik' => 'Capaian akademik tidak terganggu; pemahaman materi tetap mengikuti standar kelas.',
                        'perkembangan_sikap' => 'Adab berbicara dan mendengarkan masih perlu dibiasakan secara konsisten setiap hari.',
                        'perkembangan_keterampilan' => 'Terampil bergaul, hanya perlu mengendalikan dorongan menyela pembicaraan.',
                        'keaktifan' => 'Sangat aktif, justru perlu dilatih menahan diri dan memberi kesempatan kepada teman.',
                        'potensi' => 'Memiliki keberanian berpendapat yang baik; diarahkan menjadi teladan adab santun.',
                        'rekomendasi' => 'Terapkan pembiasaan antre dan budaya angkat tangan sebelum berbicara selama 1 bulan.'
                    ],
                    [
                        'aspek' => 'Sikap & Karakter',
                        'kendala' => 'Cenderung pasif dalam kerja kelompok dan enggan berbagi peran dengan teman sebaya',
                        'tindak_lanjut' => 'Memberikan peran tugas spesifik dalam kelompok kecil dan membimbing komunikasi asertif yang santun',
                        'ringkasan' => 'Kerja sama tim masih perlu didorong; guru memberikan penugasan peran terstruktur agar peserta didik lebih percaya diri dalam berinteraksi.',
                        'perkembangan_akademik' => 'Hasil tugas individu baik; nilai kelompok ikut terdongkrak setelah pembagian peran jelas.',
                        'perkembangan_sikap' => 'Sikap kooperatif mulai tumbuh setelah diberi peran dan tanggung jawab spesifik.',
                        'perkembangan_keterampilan' => 'Keterampilan kerja tim berkembang lewat latihan peran dalam kelompok kecil.',
                        'keaktifan' => 'Awalnya pasif, berangsur aktif setelah mendapat peran yang sesuai minat.',
                        'potensi' => 'Berpotensi menjadi pendengar dan pencatat yang teliti dalam kelompok.',
                        'rekomendasi' => 'Rotasi peran kelompok tiap pekan agar berani mencoba peran berbeda.'
                    ],
                    // 3. Keterampilan
                    [
                        'aspek' => 'Keterampilan',
                        'kendala' => 'Kurang percaya diri saat mempresentasikan hasil karya atau proyek di depan kelas',
                        'tindak_lanjut' => 'Melatih presentasi dalam kelompok kecil terlebih dahulu dan memberikan penguatan motivasi serta apresiasi keberanian',
                        'ringkasan' => 'Keterampilan praktik dan pembuatan karya sangat baik; bimbingan difokuskan pada penguatan keberanian berbicara di depan umum dan rasa percaya diri.',
                        'perkembangan_akademik' => 'Penguasaan materi presentasi baik; hambatan murni pada keberanian tampil.',
                        'perkembangan_sikap' => 'Sikap santun dan menghargai audiens sudah baik saat tampil.',
                        'perkembangan_keterampilan' => 'Karya dan media presentasi rapi serta kreatif; teknik vokal dan kontak mata perlu dilatih.',
                        'keaktifan' => 'Aktif menyiapkan bahan presentasi, perlu didorong untuk tampil lebih sering.',
                        'potensi' => 'Bakat komunikasi visual kuat; cocok dibina sebagai presenter kelas.',
                        'rekomendasi' => 'Latihan presentasi berjenjang: pasangan, kelompok kecil, lalu kelas penuh.'
                    ],
                    [
                        'aspek' => 'Keterampilan',
                        'kendala' => 'Kerapian dan ketelitian dalam menyelesaikan tugas praktik/proyek masih kurang optimal',
                        'tindak_lanjut' => 'Memberikan lembar ceklis panduan langkah kerja bertahap dan memverifikasi proses sebelum hasil akhir diserahkan',
                        'ringkasan' => 'Kreativitas dan antusiasme tinggi; pendampingan difokuskan pada peningkatan kerapian dan ketelitian proses kerja.',
                        'perkembangan_akademik' => 'Pemahaman prosedur praktik baik, eksekusi perlu ketelitian lebih.',
                        'perkembangan_sikap' => 'Antusias dan tidak mudah menyerah; perlu pembiasaan memeriksa ulang hasil kerja.',
                        'perkembangan_keterampilan' => 'Keterampilan dasar praktik di atas rata-rata; kerapian finishing perlu ditingkatkan.',
                        'keaktifan' => 'Sangat aktif dalam kegiatan praktik dan proyek kelompok.',
                        'potensi' => 'Kreativitas tinggi, berpotensi menghasilkan karya unggulan bila teliti.',
                        'rekomendasi' => 'Wajibkan ceklis verifikasi mandiri sebelum karya dikumpulkan.'
                    ],
                    // 4. Kedisiplinan
                    [
                        'aspek' => 'Kedisiplinan',
                        'kendala' => 'Sering terlambat masuk kelas dan mengumpulkan tugas melewati batas waktu yang ditentukan',
                        'tindak_lanjut' => 'Menyusun lembar pengingat tugas harian dan berkoordinasi dengan wali kelas serta orang tua siswa',
                        'ringkasan' => 'Perlu pendampingan manajemen waktu belajar; koordinasi intensif bersama wali kelas dan orang tua membantu peserta didik lebih disiplin mengumpulkan tugas.',
                        'perkembangan_akademik' => 'Capaian akademik fluktuatif karena keterlambatan mengerjakan tugas.',
                        'perkembangan_sikap' => 'Sikap terhadap aturan membaik setelah pembiasaan pengingat harian.',
                        'perkembangan_keterampilan' => 'Keterampilan mengerjakan tugas baik bila dikerjakan tepat waktu.',
                        'keaktifan' => 'Kehadiran dan partisipasi membaik setelah koordinasi dengan orang tua.',
                        'potensi' => 'Mampu disiplin bila didukung sistem pengingat yang konsisten.',
                        'rekomendasi' => 'Terapkan jurnal keterlambatan dan apresiasi pekan tanpa keterlambatan.'
                    ],
                    [
                        'aspek' => 'Kedisiplinan',
                        'kendala' => 'Kurang tertib dalam merapikan kembali perlengkapan belajar dan kebersihan ruang kelas',
                        'tindak_lanjut' => 'Pembiasaan tanggung jawab piket dan pemeriksaan kerapian meja/loker sebelum jam kepulangan',
                        'ringkasan' => 'Tanggung jawab terhadap barang milik pribadi dan fasilitas madrasah terus dibiasakan agar peserta didik memiliki kepedulian lingkungan yang tinggi.',
                        'perkembangan_akademik' => 'Tidak berpengaruh langsung pada nilai, namun kerapian catatan perlu ditingkatkan.',
                        'perkembangan_sikap' => 'Kepedulian terhadap kebersihan lingkungan kelas mulai tumbuh.',
                        'perkembangan_keterampilan' => 'Keterampilan menata alat dan bahan belajar perlu pembiasaan rutin.',
                        'keaktifan' => 'Aktif dalam jadwal piket setelah diberi tanggung jawab bergilir.',
                        'potensi' => 'Berpotensi menjadi contoh ketertiban bila dibiasakan konsisten.',
                        'rekomendasi' => 'Tunjuk sebagai koordinator kerapian kelas bergilir tiap pekan.'
                    ],
                    // 5. Ibadah & Spiritual
                    [
                        'aspek' => 'Ibadah & Spiritual',
                        'kendala' => 'Gerakan dan bacaan sholat berjamaah masih sering terburu-buru dan kurang khusyuk',
                        'tindak_lanjut' => 'Bimbingan bacaan dan gerakan sholat secara perlahan (tuma\'ninah) saat sholat dhuha dan dzuhur berjamaah',
                        'ringkasan' => 'Kehadiran sholat berjamaah sangat rutin; saat ini dalam tahap pembimbingan adab sholat tuma\'ninah dan penyempurnaan wudhu agar lebih khusyuk.',
                        'perkembangan_akademik' => 'Hafalan bacaan sholat sudah baik; pemahaman makna bacaan terus ditingkatkan.',
                        'perkembangan_sikap' => 'Adab berwudhu dan menuju tempat sholat sudah tertib.',
                        'perkembangan_keterampilan' => 'Gerakan sholat perlu dilatih perlahan agar tuma\'ninah.',
                        'keaktifan' => 'Selalu hadir sholat dhuha dan dzuhur berjamaah tanpa perlu diingatkan.',
                        'potensi' => 'Berpotensi menjadi muadzin atau imam cilik bila dibina intensif.',
                        'rekomendasi' => 'Dampingi praktik sholat 5 menit tiap selesai pelajaran agama.'
                    ],
                    [
                        'aspek' => 'Ibadah & Spiritual',
                        'kendala' => 'Belum lancar membaca Al-Qur\'an/Juz Amma sesuai kaidah tajwid dan makharijul huruf',
                        'tindak_lanjut' => 'Mengikutsertakan dalam program tahsin/tadarus terbimbing 15 menit sebelum kegiatan belajar mengajar dimulai',
                        'ringkasan' => 'Semangat mengaji sangat positif; diberikan bimbingan tahsin khusus untuk memperbaiki makharijul huruf dan hukum tajwid secara konsisten.',
                        'perkembangan_akademik' => 'Kelancaran membaca di bawah target kelas; program tahsin sudah berjalan.',
                        'perkembangan_sikap' => 'Adab memegang dan membaca mushaf sangat baik.',
                        'perkembangan_keterampilan' => 'Keterampilan melafalkan huruf hijaiyah membaik lewat latihan talaqqi.',
                        'keaktifan' => 'Aktif mengikuti tadarus pagi dan tidak malu mengulang bacaan.',
                        'potensi' => 'Kecintaan pada Al-Qur\'an tinggi; prospek tahfidz juz pendek baik.',
                        'rekomendasi' => 'Setoran bacaan 1 halaman tiap hari kepada guru tahsin.'
                    ],
                    // 6. Sosial Emosional
                    [
                        'aspek' => 'Sosial Emosional',
                        'kendala' => 'Mudah cemas dan putus asa saat menghadapi materi atau tugas pembelajaran yang dirasa sulit',
                        'tindak_lanjut' => 'Pendekatan mindful dan validasi emosi, serta pemberian tugas berjenjang dari tingkat yang paling dikuasai',
                        'ringkasan' => 'Resiliensi emosional peserta didik dalam menghadapi tantangan belajar sedang ditingkatkan melalui apresiasi proses usaha dan penguatan mental positif.',
                        'perkembangan_akademik' => 'Hasil belajar naik-turun mengikuti kondisi emosi; tugas berjenjang membantu stabil.',
                        'perkembangan_sikap' => 'Mulai berani mengakui kesulitan dan meminta bantuan guru.',
                        'perkembangan_keterampilan' => 'Menyelesaikan tugas kecil dengan baik bila diberi langkah yang jelas.',
                        'keaktifan' => 'Perlu penyemangat agar tidak menarik diri dari diskusi kelas.',
                        'potensi' => 'Kepekaan emosi tinggi yang bisa diarahkan menjadi empati sosial.',
                        'rekomendasi' => 'Berikan umpan balik positif atas usaha, bukan hanya hasil akhir.'
                    ],
                    [
                        'aspek' => 'Sosial Emosional',
                        'kendala' => 'Mudah tersulut emosi saat terjadi selisih paham dengan teman saat bermain atau diskusi',
                        'tindak_lanjut' => 'Konseling individual mengenai teknik regulasi emosi (menarik napas) dan mediasi damai berlandaskan cinta kasih',
                        'ringkasan' => 'Pengelolaan emosi dibimbing melalui mediasi damai dan pembiasaan meminta maaf serta memaafkan demi menjaga kerukunan di madrasah.',
                        'perkembangan_akademik' => 'Konsentrasi belajar terganggu sesaat setelah konflik; pulih setelah mediasi.',
                        'perkembangan_sikap' => 'Mulai mampu meminta maaf lebih dulu setelah dibimbing teknik menenangkan diri.',
                        'perkembangan_keterampilan' => 'Keterampilan berdiskusi membaik dengan aturan giliran bicara.',
                        'keaktifan' => 'Aktif bergaul; frekuensi konflik menurun setelah pembiasaan mediasi.',
                        'potensi' => 'Jiwa kepemimpinan kuat bila emosi terkendali.',
                        'rekomendasi' => 'Latihan regulasi emosi tiap pagi dan jurnal perasaan harian.'
                    ],
                    // 7. Keaktifan & Partisipasi
                    [
                        'aspek' => 'Keaktifan & Partisipasi',
                        'kendala' => 'Jarang mengajukan pertanyaan atau merespons pertanyaan pemantik dari guru di depan kelas',
                        'tindak_lanjut' => 'Menerapkan metode diskusi berpasangan (think-pair-share) agar peserta didik lebih berani berbicara sebelum forum kelas',
                        'ringkasan' => 'Pemahaman konsep materi baik namun masih pemalu untuk aktif mengemukakan pendapat; metode diskusi berpasangan berhasil meningkatkan keberaniannya.',
                        'perkembangan_akademik' => 'Nilai tulis baik; nilai lisan perlu ditingkatkan lewat partisipasi.',
                        'perkembangan_sikap' => 'Sopan dan menghargai teman yang berbicara; tinggal berani tampil sendiri.',
                        'perkembangan_keterampilan' => 'Keterampilan menyampaikan gagasan tertulis baik, lisan perlu latihan.',
                        'keaktifan' => 'Partisipasi lisan rendah di forum besar, baik dalam kelompok kecil.',
                        'potensi' => 'Gagasan yang disampaikan berkualitas bila diberi kesempatan.',
                        'rekomendasi' => 'Beri peran juru bicara kelompok bergilir tiap pekan.'
                    ],
                ];

        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM tb_master_perkembangan")->fetchColumn();
            if ($cnt === 0) {
                $ins = $pdo->prepare("
                    INSERT INTO tb_master_perkembangan (id_guru, aspek, kendala, tindak_lanjut, ringkasan, perkembangan_akademik, perkembangan_sikap, perkembangan_keterampilan, keaktifan, potensi, rekomendasi)
                    VALUES (NULL, :aspek, :kendala, :tindak_lanjut, :ringkasan, :akademik, :sikap, :keterampilan, :keaktifan, :potensi, :rekomendasi)
                ");
                foreach ($seeds as $sd) {
                    $ins->execute([
                        ':aspek' => $sd['aspek'],
                        ':kendala' => $sd['kendala'],
                        ':tindak_lanjut' => $sd['tindak_lanjut'],
                        ':ringkasan' => $sd['ringkasan'],
                        ':akademik' => $sd['perkembangan_akademik'],
                        ':sikap' => $sd['perkembangan_sikap'],
                        ':keterampilan' => $sd['perkembangan_keterampilan'],
                        ':keaktifan' => $sd['keaktifan'],
                        ':potensi' => $sd['potensi'],
                        ':rekomendasi' => $sd['rekomendasi']
                    ]);
                }
            }
        } catch (Throwable $e) {}

        // Backfill: isi kolom rincian aspek yang masih kosong pada baris master lama
        // dengan mencocokkan field kendala (unik per template bawaan).
        try {
            $upd = $pdo->prepare("
                UPDATE tb_master_perkembangan SET
                    perkembangan_akademik = COALESCE(NULLIF(perkembangan_akademik, ''), :akademik),
                    perkembangan_sikap = COALESCE(NULLIF(perkembangan_sikap, ''), :sikap),
                    perkembangan_keterampilan = COALESCE(NULLIF(perkembangan_keterampilan, ''), :keterampilan),
                    keaktifan = COALESCE(NULLIF(keaktifan, ''), :keaktifan),
                    potensi = COALESCE(NULLIF(potensi, ''), :potensi),
                    rekomendasi = COALESCE(NULLIF(rekomendasi, ''), :rekomendasi)
                WHERE kendala = :kendala
                  AND (perkembangan_akademik IS NULL OR perkembangan_akademik = ''
                    OR perkembangan_sikap IS NULL OR perkembangan_sikap = ''
                    OR perkembangan_keterampilan IS NULL OR perkembangan_keterampilan = ''
                    OR keaktifan IS NULL OR keaktifan = ''
                    OR potensi IS NULL OR potensi = ''
                    OR rekomendasi IS NULL OR rekomendasi = '')
            ");
            foreach ($seeds as $sd) {
                $upd->execute([
                    ':akademik' => $sd['perkembangan_akademik'],
                    ':sikap' => $sd['perkembangan_sikap'],
                    ':keterampilan' => $sd['perkembangan_keterampilan'],
                    ':keaktifan' => $sd['keaktifan'],
                    ':potensi' => $sd['potensi'],
                    ':rekomendasi' => $sd['rekomendasi'],
                    ':kendala' => $sd['kendala']
                ]);
            }
        } catch (Throwable $e) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_catatan_perkembangan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NULL,
                id_mapel INT NULL,
                tanggal DATE NOT NULL,
                kategori VARCHAR(100) NOT NULL DEFAULT 'Akademik',
                ringkasan TEXT NOT NULL,
                kendala TEXT NULL,
                tindak_lanjut TEXT NULL,
                status ENUM('Aktif','Selesai','Dalam Pemantauan') NOT NULL DEFAULT 'Aktif',
                perkembangan_akademik TEXT NULL,
                perkembangan_sikap TEXT NULL,
                perkembangan_keterampilan TEXT NULL,
                keaktifan TEXT NULL,
                potensi TEXT NULL,
                catatan_guru TEXT NULL,
                rekomendasi TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_siswa (id_siswa),
                INDEX idx_guru (id_guru),
                INDEX idx_kelas (id_kelas),
                INDEX idx_tanggal (tanggal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 9. Komunikasi Kelas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_komunikasi_kelas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NOT NULL,
                tanggal DATE NOT NULL,
                judul VARCHAR(255) NOT NULL,
                jenis ENUM('Pengumuman','Pesan','Diskusi') NOT NULL DEFAULT 'Pengumuman',
                id_kelas INT NOT NULL,
                isi TEXT NOT NULL,
                lampiran VARCHAR(255) NULL,
                status ENUM('Terkirim','Draft','Arsip') NOT NULL DEFAULT 'Terkirim',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_guru (id_guru),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_komunikasi_kelas_read (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_komunikasi INT NOT NULL,
                id_siswa INT NOT NULL,
                dibaca_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_baca (id_komunikasi, id_siswa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 10. Pembinaan Siswa (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_pembinaan_siswa (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                jenis_pembinaan ENUM('Akademik','Kedisiplinan','Sikap','Kehadiran','Sosial','Lainnya') NOT NULL DEFAULT 'Akademik',
                permasalahan TEXT NOT NULL,
                tindakan TEXT NOT NULL,
                tindak_lanjut TEXT NULL,
                status ENUM('Berjalan','Selesai','Dalam Pemantauan') NOT NULL DEFAULT 'Berjalan',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 10b. Master Data Pembinaan (template permasalahan/kasus, tindakan, rencana tindak lanjut).
        // $seeds_pembinaan dipakai seed + backfill agar scope-nya aman.
        // SELARAS 1:1 dengan 12 template pelanggaran (jenis + redaksi boleh beda gaya, makna sama).
        $seeds_pembinaan = [
            // Kedisiplinan: pasangannya Terlambat / Seragam / Lupa buku / Sampah
            ['jenis' => 'Kedisiplinan', 'permasalahan' => 'Terlambat masuk kelas lebih dari 15 menit', 'tindakan' => 'Teguran + surat pernyataan + pembiasaan datang 10 menit lebih awal', 'tindak_lanjut' => 'Pantau keterlambatan 2 pekan, libatkan orang tua bila berulang'],
            ['jenis' => 'Kedisiplinan', 'permasalahan' => 'Tidak memakai atribut seragam lengkap', 'tindakan' => 'Teguran + pembinaan tata tertib berpakaian', 'tindak_lanjut' => 'Cek seragam tiap pagi selama 1 pekan'],
            ['jenis' => 'Kedisiplinan', 'permasalahan' => 'Lupa membawa buku atau alat tulis', 'tindakan' => 'Teguran + ceklis perlengkapan harian + pinjam dengan izin guru', 'tindak_lanjut' => 'Ceklis tas tiap pagi 2 pekan, koordinasi orang tua'],
            ['jenis' => 'Kedisiplinan', 'permasalahan' => 'Membuang sampah sembarangan di lingkungan madrasah', 'tindakan' => 'Teguran + membersihkan area yang dikotori + piket tambahan', 'tindak_lanjut' => 'Pantau kebersihan 2 pekan, apresiasi kelas terbersih'],
            // Kedisiplinan lanjutan: Keluar kelas / Gaduh / Gawai
            ['jenis' => 'Kedisiplinan', 'permasalahan' => 'Keluar kelas tanpa izin guru saat KBM', 'tindakan' => 'Teguran tertulis + surat pernyataan tidak mengulangi', 'tindak_lanjut' => 'Pantau izin keluar-masuk 2 pekan'],
            ['jenis' => 'Kedisiplinan', 'permasalahan' => 'Gaduh dan mengganggu jalannya KBM', 'tindakan' => 'Teguran + duduk terpisah sementara + pembinaan adab kelas', 'tindak_lanjut' => 'Pantau ketertiban KBM 2 pekan'],
            ['jenis' => 'Kedisiplinan', 'permasalahan' => 'Bermain gawai saat KBM berlangsung', 'tindakan' => 'Gawai dititipkan + surat pernyataan + pembinaan tata tertib gawai', 'tindak_lanjut' => 'Pantau 1 bulan, libatkan orang tua atur screen time'],
            // Akademik: Tugas / Menyontek
            ['jenis' => 'Akademik', 'permasalahan' => 'Tidak mengerjakan tugas/PR 2 kali berturut-turut', 'tindakan' => 'Tugas tambahan + dampingi pembagian tugas menjadi langkah kecil', 'tindak_lanjut' => 'Ceklis tugas harian, koordinasi dengan orang tua'],
            ['jenis' => 'Akademik', 'permasalahan' => 'Menyontek saat ulangan/asesmen', 'tindakan' => 'Pembinaan kejujuran + ulangan susulan + pemberitahuan orang tua', 'tindak_lanjut' => 'Pantau kejujuran asesmen 1 bulan'],
            // Sikap: Berkelahi / Merusak fasilitas
            ['jenis' => 'Sikap', 'permasalahan' => 'Berkelahi dengan teman', 'tindakan' => 'Mediasi damai + latihan regulasi emosi + meminta maaf', 'tindak_lanjut' => 'Pantau kerukunan 1 bulan, libatkan guru BK bila berulang'],
            ['jenis' => 'Sikap', 'permasalahan' => 'Merusak fasilitas madrasah', 'tindakan' => 'Ganti rugi + kerja sosial + surat pernyataan', 'tindak_lanjut' => 'Pantau kepedulian fasilitas 1 bulan'],
            // Kehadiran: Membolos
            ['jenis' => 'Kehadiran', 'permasalahan' => 'Membolos sekolah tanpa keterangan', 'tindakan' => 'Pemanggilan orang tua + pembinaan intensif wali kelas', 'tindak_lanjut' => 'Pemantauan kehadiran harian 1 bulan'],
        ];
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_master_pembinaan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NULL,
                jenis VARCHAR(50) NOT NULL,
                permasalahan TEXT NOT NULL,
                tindakan TEXT NOT NULL,
                tindak_lanjut TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_jenis (jenis),
                INDEX idx_guru (id_guru)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        try {
            $cntB = (int)$pdo->query("SELECT COUNT(*) FROM tb_master_pembinaan")->fetchColumn();
            if ($cntB === 0) {
                $insB = $pdo->prepare("INSERT INTO tb_master_pembinaan (id_guru, jenis, permasalahan, tindakan, tindak_lanjut) VALUES (NULL, :jenis, :permasalahan, :tindakan, :tindak_lanjut)");
                foreach ($seeds_pembinaan as $sd) {
                    $insB->execute([':jenis' => $sd['jenis'], ':permasalahan' => $sd['permasalahan'], ':tindakan' => $sd['tindakan'], ':tindak_lanjut' => $sd['tindak_lanjut']]);
                }
            } else {
                // Sinkronkan seed selaras: update template lama yang redaksinya beda jauh dari sumber pelanggaran.
                $mapSync = [
                    'Nilai harian turun pada 2 asesmen terakhir' => $seeds_pembinaan[7],
                    'Tugas sering tidak selesai tepat waktu' => $seeds_pembinaan[7],
                    'Sering terlambat masuk kelas pagi' => $seeds_pembinaan[0],
                    'Tidak memakai seragam sesuai ketentuan' => $seeds_pembinaan[1],
                    'Kurang sopan saat berbicara dengan guru/teman' => null, // biarkan (tidak ada di 12 pelanggaran)
                    'Pasif dalam kerja kelompok' => null,
                    'Alpa tanpa keterangan' => null, // ganti jadi Membolos agar nyambung
                    'Sering izin dengan alasan tidak jelas' => null,
                    'Berselisih dengan teman sekelas' => null, // biarkan (Sosial umum)
                    'Menyendiri, enggan bergaul' => null,
                    'Ketergantungan gawai saat jam belajar' => $seeds_pembinaan[6],
                    'Belum lancar membaca Al-Quran sesuai tajwid' => null,
                ];
                // Tambahkan seed baru yang belum ada (hindari duplikat redaksi).
                $exM = $pdo->query("SELECT permasalahan FROM tb_master_pembinaan WHERE id_guru IS NULL OR id_guru = 0")->fetchAll(PDO::FETCH_COLUMN);
                $exN = array_map(function($s) { return mb_strtolower(trim((string)$s)); }, (array)$exM);
                $insB2 = $pdo->prepare("INSERT INTO tb_master_pembinaan (id_guru, jenis, permasalahan, tindakan, tindak_lanjut) VALUES (NULL, :jenis, :permasalahan, :tindakan, :tindak_lanjut)");
                foreach ($seeds_pembinaan as $sd) {
                    if (!in_array(mb_strtolower(trim($sd['permasalahan'])), $exN, true)) {
                        $insB2->execute([':jenis' => $sd['jenis'], ':permasalahan' => $sd['permasalahan'], ':tindakan' => $sd['tindakan'], ':tindak_lanjut' => $sd['tindak_lanjut']]);
                    }
                }
            }
        } catch (Throwable $e) {}

        // 11. Pelanggaran Siswa (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_pelanggaran_siswa (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                jenis_pelanggaran VARCHAR(255) NOT NULL,
                kategori VARCHAR(100) NULL,
                poin INT NOT NULL DEFAULT 0,
                tindakan TEXT NOT NULL,
                orang_tua VARCHAR(255) NULL,
                status ENUM('Dicatat','Ditindaklanjuti','Selesai') NOT NULL DEFAULT 'Dicatat',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 11b. Master Data Pelanggaran (template jenis, tindakan/sanksi, poin).
        // $seeds_pelanggaran dipakai seed agar scope-nya aman.
        $seeds_pelanggaran = [
            ['kategori' => 'Ringan', 'jenis' => 'Terlambat masuk kelas lebih dari 15 menit', 'tindakan' => 'Teguran lisan + dicatat di buku kedisiplinan', 'poin' => 5],
            ['kategori' => 'Ringan', 'jenis' => 'Tidak memakai atribut seragam lengkap', 'tindakan' => 'Teguran + pembinaan tata tertib berpakaian', 'poin' => 5],
            ['kategori' => 'Ringan', 'jenis' => 'Lupa membawa buku atau alat tulis', 'tindakan' => 'Teguran + pinjam dengan izin guru', 'poin' => 5],
            ['kategori' => 'Ringan', 'jenis' => 'Membuang sampah sembarangan di lingkungan madrasah', 'tindakan' => 'Teguran + membersihkan area yang dikotori', 'poin' => 5],
            ['kategori' => 'Sedang', 'jenis' => 'Tidak mengerjakan tugas/PR 2 kali berturut-turut', 'tindakan' => 'Panggilan wali + tugas tambahan + pemberitahuan orang tua', 'poin' => 15],
            ['kategori' => 'Sedang', 'jenis' => 'Keluar kelas tanpa izin guru saat KBM', 'tindakan' => 'Teguran tertulis + surat pernyataan tidak mengulangi', 'poin' => 15],
            ['kategori' => 'Sedang', 'jenis' => 'Gaduh dan mengganggu jalannya KBM', 'tindakan' => 'Teguran + duduk terpisah sementara + pembinaan adab kelas', 'poin' => 15],
            ['kategori' => 'Sedang', 'jenis' => 'Menyontek saat ulangan/asesmen', 'tindakan' => 'Nilai dibatalkan + pembinaan kejujuran + pemberitahuan orang tua', 'poin' => 20],
            ['kategori' => 'Sedang', 'jenis' => 'Bermain gawai saat KBM berlangsung', 'tindakan' => 'Gawai disita 1 hari + surat pernyataan + pembinaan', 'poin' => 20],
            ['kategori' => 'Berat', 'jenis' => 'Berkelahi dengan teman', 'tindakan' => 'Mediasi + skorsing 1 hari + pemanggilan orang tua', 'poin' => 40],
            ['kategori' => 'Berat', 'jenis' => 'Merusak fasilitas madrasah', 'tindakan' => 'Ganti rugi + surat pernyataan + pemanggilan orang tua', 'poin' => 50],
            ['kategori' => 'Berat', 'jenis' => 'Membolos sekolah tanpa keterangan', 'tindakan' => 'Pemanggilan orang tua + pembinaan intensif wali kelas', 'poin' => 30],
        ];
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_master_pelanggaran (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NULL,
                kategori VARCHAR(50) NOT NULL,
                jenis VARCHAR(255) NOT NULL,
                tindakan TEXT NOT NULL,
                poin INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_kategori (kategori),
                INDEX idx_guru (id_guru)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        try {
            $cntP = (int)$pdo->query("SELECT COUNT(*) FROM tb_master_pelanggaran")->fetchColumn();
            if ($cntP === 0) {
                $insP = $pdo->prepare("INSERT INTO tb_master_pelanggaran (id_guru, kategori, jenis, tindakan, poin) VALUES (NULL, :kategori, :jenis, :tindakan, :poin)");
                foreach ($seeds_pelanggaran as $sd) {
                    $insP->execute([':kategori' => $sd['kategori'], ':jenis' => $sd['jenis'], ':tindakan' => $sd['tindakan'], ':poin' => $sd['poin']]);
                }
            }
        } catch (Throwable $e) {}

        // Relasi alur: 1 Pelanggaran -> 2 Pembinaan -> 3 Tindak Lanjut (bisa dari Pembinaan / Konseling).
        // Tambah kolom penghubung bila belum ada (aman untuk data lama).
        foreach ([
            ['tb_pelanggaran_siswa', 'jenis_binaan', 'VARCHAR(50) NULL'],
            ['tb_master_pelanggaran', 'jenis_binaan', 'VARCHAR(50) NULL'],
            ['tb_pembinaan_siswa', 'id_pelanggaran', 'INT NULL'],
            ['tb_tindak_lanjut_wali', 'id_pembinaan', 'INT NULL'],
            ['tb_tindak_lanjut_wali', 'id_konseling', 'INT NULL'],
        ] as $rel) {
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM {$rel[0]} LIKE '{$rel[1]}'")->fetch();
                if (!$chk) {
                    $pdo->exec("ALTER TABLE {$rel[0]} ADD COLUMN {$rel[1]} {$rel[2]}");
                }
            } catch (Throwable $e) {}
        }

        // Mapping resmi: jenis pelanggaran (Alur 1) -> jenis pembinaan (Alur 2).
        // Dipakai deteksi otomatis di modal pembinaan + validasi server.
        if (!function_exists('pelanggaran_jenis_binaan_map')) {
            function pelanggaran_jenis_binaan_map(): array {
                return [
                    'Kedisiplinan' => 'Kedisiplinan',
                    'Kehadiran' => 'Kehadiran',
                    'Akademik' => 'Akademik',
                    'Sikap' => 'Sikap',
                    'Sosial' => 'Sosial',
                    'Ibadah' => 'Sikap',
                    'Kebersihan' => 'Kedisiplinan',
                ];
            }
        }
        if (!function_exists('pelanggaran_deteksi_jenis')) {
            // Deteksi jenis pelanggaran dari teks + kategori. Return: ['jenis', 'via'].
            // Urutan prioritas: Kehadiran (absensi murni) -> Sikap (akhlak berat) ->
            // Sosial -> Akademik -> Kedisiplinan (tata tertib umum).
            // 'Terlambat masuk kelas' = Kedisiplinan (bukan Kehadiran); Kehadiran hanya
            // untuk alpa/bolos/tidak masuk.
            function pelanggaran_deteksi_jenis(string $teks, string $kategori = ''): array {
                $norm = function($s) {
                    $s = mb_strtolower((string)$s);
                    $s = str_replace(['-', '_'], ' ', $s);
                    $s = preg_replace('/\s+/', ' ', $s);
                    return ' ' . trim((string)$s) . ' ';
                };
                $t = $norm($teks);
                $has = function(array $keys) use ($t, $norm) {
                    foreach ($keys as $k) {
                        $nk = trim((string)$norm($k));
                        if ($nk !== '' && mb_strpos($t, $nk) !== false) return $k;
                    }
                    return null;
                };
                // 1. Kehadiran = absensi murni (tanpa 'terlambat': itu disiplin waktu).
                if ($m = $has(['alpa', 'bolos', 'membolos', 'tidak masuk', 'absen tanpa', 'tanpa keterangan'])) {
                    return ['jenis' => 'Kehadiran', 'via' => 'keyword:' . $m];
                }
                // 2. Sikap = akhlak/berat (cek duluan agar 'berkelahi dengan teman' tidak jadi Sosial).
                if ($m = $has(['berkelahi', 'tawuran', 'memukul', 'menendang', 'merokok', 'rokok', 'vape', 'mencuri', 'mengambil milik', 'berbohong', 'bohong', 'dusta', 'melawan guru', 'membentak', 'berkata kasar', 'berkata kotor', 'mengumpat', 'mengejek', 'membully', 'bully', 'mencaci', 'caci', 'fitnah', 'tidak sopan', 'tidak santun', 'kurang sopan', 'kasar', 'sholat', 'shalat', 'ibadah', 'mengaji', 'puasa', 'jujur', 'sopan', 'santun', 'adab', 'akhlak'])) {
                    return ['jenis' => 'Sikap', 'via' => 'keyword:' . $m];
                }
                // 3. Sosial = relasi teman/kelompok.
                if ($m = $has(['berselisih', 'bertengkar', 'cekcok', 'menyendiri', 'mengucilkan', 'dikucilkan', 'kerjasama', 'kerja sama', 'gotong royong', 'bergaul'])) {
                    return ['jenis' => 'Sosial', 'via' => 'keyword:' . $m];
                }
                // 4. Akademik = KBM/tugas/asesmen (tanpa kata umum 'kelas'/'belajar' agar tidak telan disiplin).
                if ($m = $has(['menyontek', 'nyontek', 'contekan', 'tidak mengerjakan tugas', 'tidak mengerjakan pr', 'ulangan', 'asesmen', 'ujian', 'nilai harian', 'tugas'])) {
                    return ['jenis' => 'Akademik', 'via' => 'keyword:' . $m];
                }
                // 5. Kedisiplinan = tata tertib/waktu/seragam/kebersihan/gawai/gaduh.
                // Termasuk 'terlambat masuk kelas' dan 'tidak memakai atribut seragam'.
                if ($m = $has(['terlambat', 'telat', 'seragam', 'atribut', 'pakaian', 'berseragam', 'sepatu', 'rambut', 'kuku', 'gondrong', 'tata tertib', 'disiplin', 'apel', 'upacara', 'baris', 'piket', 'sampah', 'kebersihan', 'lupa membawa', 'tidak membawa', 'gawai', 'handphone', 'gadget', 'main hp', 'bermain gawai', 'keluar kelas', 'tanpa izin', 'gaduh', 'ribut', 'berisik', 'mengganggu', 'buku', 'alat tulis', 'izin'])) {
                    return ['jenis' => 'Kedisiplinan', 'via' => 'keyword:' . $m];
                }
                // Fallback kategori: Ringan/Sedang dominan kedisiplinan.
                $kat = mb_strtolower(trim($kategori));
                if ($kat === 'ringan') return ['jenis' => 'Kedisiplinan', 'via' => 'fallback:kategori-ringan'];
                if ($kat === 'sedang') return ['jenis' => 'Kedisiplinan', 'via' => 'fallback:kategori-sedang'];
                if ($kat === 'berat') return ['jenis' => 'Sikap', 'via' => 'fallback:kategori-berat'];
                return ['jenis' => 'Kedisiplinan', 'via' => 'fallback:default'];
            }
        }
        // Backfill jenis_binaan untuk data pelanggaran lama yang masih kosong.
        // Dijalankan SETELAH fungsi deteksi didefinisikan agar tidak pernah bocor.
        try {
            $stBf = $pdo->query("SELECT id, jenis_pelanggaran, kategori FROM tb_pelanggaran_siswa WHERE jenis_binaan IS NULL OR jenis_binaan = '' LIMIT 500");
            $bfRows = $stBf ? $stBf->fetchAll(PDO::FETCH_ASSOC) : [];
            if (!empty($bfRows)) {
                $stU = $pdo->prepare("UPDATE tb_pelanggaran_siswa SET jenis_binaan = ? WHERE id = ?");
                foreach ($bfRows as $br) {
                    $det = pelanggaran_deteksi_jenis((string)($br['jenis_pelanggaran'] ?? ''), (string)($br['kategori'] ?? ''));
                    $stU->execute([$det['jenis'], (int)$br['id']]);
                }
            }
        } catch (Throwable $e) {}
        // Backfill master: isi jenis_binaan template yang masih kosong.
        try {
            $chkM = $pdo->query("SHOW COLUMNS FROM tb_master_pelanggaran LIKE 'jenis_binaan'")->fetch();
            if ($chkM) {
                $stBfM = $pdo->query("SELECT id, jenis, kategori FROM tb_master_pelanggaran WHERE jenis_binaan IS NULL OR jenis_binaan = '' LIMIT 500");
                $bfM = $stBfM ? $stBfM->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($bfM)) {
                    $stUM = $pdo->prepare("UPDATE tb_master_pelanggaran SET jenis_binaan = ? WHERE id = ?");
                    foreach ($bfM as $br) {
                        $det = pelanggaran_deteksi_jenis((string)($br['jenis'] ?? ''), (string)($br['kategori'] ?? ''));
                        $stUM->execute([$det['jenis'], (int)$br['id']]);
                    }
                }
            }
        } catch (Throwable $e) {}
        if (!function_exists('pelanggaran_sanksi_by_poin')) {
            function pelanggaran_sanksi_by_poin(int $total): array {
                if ($total >= 100) return ['level' => 'Dikeluarkan (DO)', 'badge' => 'dark', 'desc' => 'Poin mencapai/melewati 100: usulan pemberhentian / dikeluarkan dari madrasah + pemanggilan orang tua + berita acara.'];
                if ($total >= 75) return ['level' => 'Skorsing', 'badge' => 'danger', 'desc' => 'Poin 75-99: skorsing + surat peringatan keras + pembinaan intensif wali + pemanggilan orang tua.'];
                if ($total >= 50) return ['level' => 'SP 1 / Pembinaan Khusus', 'badge' => 'warning', 'desc' => 'Poin 50-74: surat peringatan 1 + pembinaan khusus terjadwal + kontrak perilaku.'];
                if ($total >= 25) return ['level' => 'Dalam Pemantauan', 'badge' => 'info', 'desc' => 'Poin 25-49: masuk daftar pemantauan wali + teguran tertulis + koordinasi orang tua.'];
                return ['level' => 'Pembinaan Ringan', 'badge' => 'success', 'desc' => 'Poin 0-24: teguran lisan/tertulis + pembinaan adab di kelas.'];
            }
        }

        // 13. Konseling Awal (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_konseling_awal (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                topik VARCHAR(255) NOT NULL,
                ringkasan_masalah TEXT NOT NULL,
                tindak_lanjut TEXT NULL,
                status ENUM('Terbuka','Proses','Selesai') NOT NULL DEFAULT 'Terbuka',
                follow_up TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 13b. Master Data Konseling (template topik, ringkasan, tindak lanjut, follow up).
        $seeds_konseling = [
            ['topik' => 'Motivasi Belajar', 'ringkasan' => 'Siswa kurang semangat belajar dan mudah menyerah saat menghadapi tugas sulit.', 'tindak_lanjut' => 'Bimbingan motivasi + target belajar kecil yang terukur disepakati bersama.', 'follow_up' => 'Pantau progres tiap pekan, jadwal pertemuan ulang 2 minggu lagi.'],
            ['topik' => 'Motivasi Belajar', 'ringkasan' => 'Hasil belajar menurun karena kurang fokus dan manajemen waktu yang belum tertata.', 'tindak_lanjut' => 'Susun jadwal belajar harian bersama + ceklis tugas.', 'follow_up' => 'Evaluasi jadwal belajar tiap pekan bersama wali kelas.'],
            ['topik' => 'Penyesuaian Sosial', 'ringkasan' => 'Siswa sulit beradaptasi dengan teman baru dan cenderung menyendiri di kelas.', 'tindak_lanjut' => 'Libatkan dalam kelompok kecil + pendampingan teman sebaya.', 'follow_up' => 'Observasi interaksi sosial 2 pekan, konseling lanjutan bila perlu.'],
            ['topik' => 'Penyesuaian Sosial', 'ringkasan' => 'Terjadi selisih paham dengan teman sekelas yang mengganggu kenyamanan belajar.', 'tindak_lanjut' => 'Mediasi damai + latihan meminta maaf dan memaafkan.', 'follow_up' => 'Pantau kerukunan 2 pekan ke depan.'],
            ['topik' => 'Keluarga', 'ringkasan' => 'Siswa terlihat murung dan kurang konsentrasi diduga ada persoalan di rumah.', 'tindak_lanjut' => 'Pendekatan empatik + komunikasi dengan orang tua bila siswa bersedia.', 'follow_up' => 'Pertemuan lanjutan sesuai kesiapan siswa, jaga kerahasiaan.'],
            ['topik' => 'Keluarga', 'ringkasan' => 'Pola asuh kurang konsisten membuat siswa bingung membagi waktu belajar dan bermain.', 'tindak_lanjut' => 'Koordinasi dengan orang tua untuk menyepakati aturan screen time.', 'follow_up' => 'Hubungi orang tua 1 bulan lagi untuk evaluasi.'],
            ['topik' => 'Kedisiplinan', 'ringkasan' => 'Siswa sering terlambat dan kurang tertib mengikuti tata tertib madrasah.', 'tindak_lanjut' => 'Kontrak perilaku + pengingat harian disepakati bersama.', 'follow_up' => 'Pantau kedisiplinan 1 bulan, apresiasi tiap pekan tanpa pelanggaran.'],
            ['topik' => 'Kecemasan / Emosi', 'ringkasan' => 'Siswa mudah cemas saat ulangan dan takut salah menjawab di depan kelas.', 'tindak_lanjut' => 'Latihan regulasi emosi (tarik napas) + tugas berjenjang dari yang mudah.', 'follow_up' => 'Latihan tiap pagi + jurnal perasaan harian, evaluasi 2 pekan.'],
            ['topik' => 'Kecemasan / Emosi', 'ringkasan' => 'Siswa mudah tersulut emosi saat berbeda pendapat dengan teman.', 'tindak_lanjut' => 'Konseling regulasi emosi + mediasi damai berlandaskan kasih sayang.', 'follow_up' => 'Pantau 2 pekan, libatkan guru BK bila berulang.'],
            ['topik' => 'Minat & Bakat', 'ringkasan' => 'Siswa belum mengenali minatnya dan bingung memilih kegiatan ekstrakurikuler.', 'tindak_lanjut' => 'Eksplorasi minat via observasi + coba 2 kegiatan selama sebulan.', 'follow_up' => 'Evaluasi kecocokan kegiatan bulan depan.'],
            ['topik' => 'Ibadah & Spiritual', 'ringkasan' => 'Semangat ibadah fluktuatif, perlu penguatan pembiasaan sholat dan mengaji.', 'tindak_lanjut' => 'Dampingi program tahsin/sholat berjamaah + target hafalan kecil.', 'follow_up' => 'Setoran berkala tiap pekan ke guru pendamping.'],
            ['topik' => 'Lainnya', 'ringkasan' => 'Keluhan umum terkait kenyamanan belajar di kelas.', 'tindak_lanjut' => 'Penyesuaian tempat duduk + komunikasi intensif wali kelas.', 'follow_up' => 'Tanya kabar berkala tiap pekan.'],
        ];
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_master_konseling (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NULL,
                topik VARCHAR(100) NOT NULL,
                ringkasan TEXT NOT NULL,
                tindak_lanjut TEXT NULL,
                follow_up TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_topik (topik),
                INDEX idx_guru (id_guru)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        try {
            $cntK = (int)$pdo->query("SELECT COUNT(*) FROM tb_master_konseling")->fetchColumn();
            if ($cntK === 0) {
                $insK = $pdo->prepare("INSERT INTO tb_master_konseling (id_guru, topik, ringkasan, tindak_lanjut, follow_up) VALUES (NULL, :topik, :ringkasan, :tindak_lanjut, :follow_up)");
                foreach ($seeds_konseling as $sd) {
                    $insK->execute([':topik' => $sd['topik'], ':ringkasan' => $sd['ringkasan'], ':tindak_lanjut' => $sd['tindak_lanjut'], ':follow_up' => $sd['follow_up']]);
                }
            }
        } catch (Throwable $e) {}

        // 13c. Master Data Tindak Lanjut (template tindakan/langkah perbaikan per sumber).
        $seeds_tl = [
            ['sumber' => 'Pelanggaran', 'tindakan' => 'Teguran tertulis + surat pernyataan tidak mengulangi', 'penanggung_jawab' => 'Wali Kelas'],
            ['sumber' => 'Pelanggaran', 'tindakan' => 'Pemanggilan orang tua + pembinaan intensif 2 pekan', 'penanggung_jawab' => 'Wali Kelas'],
            ['sumber' => 'Pelanggaran', 'tindakan' => 'Skorsing 1 hari + tugas refleksi tertulis + konseling', 'penanggung_jawab' => 'Wali Kelas'],
            ['sumber' => 'Pelanggaran', 'tindakan' => 'Mediasi damai + kontrak perilaku disepakati bersama', 'penanggung_jawab' => 'Wali Kelas'],
            ['sumber' => 'Pelanggaran', 'tindakan' => 'Ganti rugi / kerja sosial + pengawasan harian 1 bulan', 'penanggung_jawab' => 'Wali Kelas'],
            ['sumber' => 'Pembinaan', 'tindakan' => 'Bimbingan personal terjadwal 2x seminggu', 'penanggung_jawab' => 'Wali Kelas'],
            ['sumber' => 'Pembinaan', 'tindakan' => 'Tutor sebaya + ceklis tugas harian', 'penanggung_jawab' => 'Wali Kelas'],
            ['sumber' => 'Konseling', 'tindakan' => 'Konseling lanjutan 2 minggu + jurnal perasaan harian', 'penanggung_jawab' => 'Guru BK'],
            ['sumber' => 'Konseling', 'tindakan' => 'Koordinasi orang tua + pendampingan teman sebaya', 'penanggung_jawab' => 'Wali Kelas'],
            ['sumber' => 'Perkembangan', 'tindakan' => 'Remedial terstruktur + modul latihan mandiri', 'penanggung_jawab' => 'Guru Mapel'],
            ['sumber' => 'Perkembangan', 'tindakan' => 'Pengayaan proyek + presentasi kelompok kecil', 'penanggung_jawab' => 'Guru Mapel'],
            ['sumber' => 'Pelanggaran', 'tindakan' => 'Pantau kedisiplinan harian + apresiasi pekan tanpa pelanggaran', 'penanggung_jawab' => 'Orang Tua'],
        ];
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_master_tindak_lanjut (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_guru INT NULL,
                sumber VARCHAR(50) NOT NULL,
                tindakan TEXT NOT NULL,
                penanggung_jawab VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_sumber (sumber),
                INDEX idx_guru (id_guru)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        try {
            $cntTL = (int)$pdo->query("SELECT COUNT(*) FROM tb_master_tindak_lanjut")->fetchColumn();
            if ($cntTL === 0) {
                $insTL = $pdo->prepare("INSERT INTO tb_master_tindak_lanjut (id_guru, sumber, tindakan, penanggung_jawab) VALUES (NULL, :sumber, :tindakan, :pj)");
                foreach ($seeds_tl as $sd) {
                    $insTL->execute([':sumber' => $sd['sumber'], ':tindakan' => $sd['tindakan'], ':pj' => $sd['penanggung_jawab']]);
                }
            }
        } catch (Throwable $e) {}

        // 14. Tindak Lanjut (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_tindak_lanjut_wali (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                sumber ENUM('Pembinaan','Pelanggaran','Konseling','Perkembangan') NOT NULL DEFAULT 'Pembinaan',
                tindakan TEXT NOT NULL,
                penanggung_jawab VARCHAR(100) NOT NULL,
                target_selesai DATE NULL,
                tanggal_selesai DATE NULL,
                status ENUM('Rencana','Proses','Selesai','Dibatalkan') NOT NULL DEFAULT 'Rencana',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 15. Komunikasi Orang Tua (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_komunikasi_ortu (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_siswa INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                nama_ortu VARCHAR(150) NULL,
                jenis_informasi ENUM('Pengumuman Kelas','Pesan Individu','Informasi Kehadiran','Informasi Tugas','Informasi Perkembangan') NOT NULL DEFAULT 'Pengumuman Kelas',
                judul VARCHAR(255) NOT NULL,
                isi TEXT NOT NULL,
                status_kirim ENUM('Terkirim','Draft','Gagal') NOT NULL DEFAULT 'Terkirim',
                status_dibaca ENUM('Belum Dibaca','Sudah Dibaca') NOT NULL DEFAULT 'Belum Dibaca',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_siswa (id_siswa),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 16. Agenda Kelas (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_agenda_kelas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_kelas INT NOT NULL,
                tanggal DATE NOT NULL,
                waktu_mulai TIME NULL,
                waktu_selesai TIME NULL,
                nama_agenda VARCHAR(255) NOT NULL,
                jenis ENUM('Ujian','Kegiatan Kelas','Kegiatan Madrasah','Piket','Projek','Kokurikuler') NOT NULL DEFAULT 'Kegiatan Kelas',
                tempat VARCHAR(150) NULL,
                penanggung_jawab VARCHAR(100) NULL,
                status ENUM('Rencana','Berjalan','Selesai','Batal') NOT NULL DEFAULT 'Rencana',
                keterangan TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_kelas (id_kelas),
                INDEX idx_tanggal (tanggal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 17. Jadwal Piket Kelas (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_jadwal_piket_kelas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                id_kelas INT NOT NULL,
                hari VARCHAR(20) NOT NULL,
                id_siswa INT NOT NULL,
                tugas VARCHAR(100) NOT NULL DEFAULT 'Piket Umum',
                urutan INT NOT NULL DEFAULT 1,
                status ENUM('Aktif','Nonaktif') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_kelas (id_kelas),
                INDEX idx_hari (hari),
                INDEX idx_siswa (id_siswa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 18. Projek / Kokurikuler (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_projek_kokurikuler (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_wali INT NOT NULL,
                nama_projek VARCHAR(255) NOT NULL,
                tema VARCHAR(255) NOT NULL,
                id_kelas INT NOT NULL,
                pembimbing VARCHAR(150) NULL,
                tgl_mulai DATE NULL,
                tgl_selesai DATE NULL,
                deskripsi TEXT NULL,
                status ENUM('Perencanaan','Berjalan','Selesai','Arsip') NOT NULL DEFAULT 'Perencanaan',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wali (id_wali),
                INDEX idx_kelas (id_kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 19. Anggota Projek (Level Wali)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tb_projek_anggota (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_projek INT NOT NULL,
                id_siswa INT NOT NULL,
                peran ENUM('Ketua','Sekretaris','Anggota','Presentator','Lainnya') NOT NULL DEFAULT 'Anggota',
                kelompok VARCHAR(100) NULL,
                catatan TEXT NULL,
                status ENUM('Aktif','Selesai','Keluar') NOT NULL DEFAULT 'Aktif',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_projek_siswa (id_projek, id_siswa),
                INDEX idx_projek (id_projek),
                INDEX idx_siswa (id_siswa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $done = true;
    }
}
