<?php
// Konektor AI per guru untuk generate soal (Gemini + OpenAI/ChatGPT).
// Kunci API milik masing-masing guru (tersimpan di tb_guru, bukan admin).

if (!function_exists('ai_helper_schema')) {
    // Tambah kolom AI ke tb_guru bila belum ada. Dipanggil di halaman profil guru.
    function ai_helper_schema(PDO $pdo): void {
        foreach ([
            "ai_provider VARCHAR(20) NULL DEFAULT 'gemini'",
            "ai_gemini_email VARCHAR(255) NULL",
            "ai_gemini_key VARCHAR(255) NULL",
            "ai_gemini_model VARCHAR(100) NULL",
            "ai_openai_email VARCHAR(255) NULL",
            "ai_openai_key VARCHAR(255) NULL",
            "ai_openai_model VARCHAR(100) NULL",
        ] as $col) {
            $name = explode(' ', $col, 2)[0];
            try {
                $has = $pdo->query("SHOW COLUMNS FROM tb_guru LIKE '" . addslashes($name) . "'")->fetch(PDO::FETCH_ASSOC);
                if (!$has) {
                    $pdo->exec("ALTER TABLE tb_guru ADD COLUMN {$col}");
                }
            } catch (Throwable $e) { /* abaikan */
            }
        }
    }
}

if (!function_exists('ai_get_system_gemini_key')) {
    // Ambil Master Key Gemini tingkat madrasah dari database/env bila guru belum isi
    function ai_get_system_gemini_key(PDO $pdo): string {
        $k = trim((string)getenv('GEMINI_API_KEY'));
        if ($k === '') { $k = trim((string)getenv('GOOGLE_API_KEY')); }
        if ($k === '') {
            try {
                $st = $pdo->prepare("SELECT nilai FROM tb_pengaturan_aplikasi WHERE kunci = 'master_gemini_key' LIMIT 1");
                $st->execute();
                $k = trim((string)$st->fetchColumn());
            } catch (Throwable $e) {}
        }
        return $k;
    }
}

if (!function_exists('ai_guru_config')) {
    // Ambil konfigurasi AI milik satu guru. Kembalikan ['provider','gemini_email','gemini_key','gemini_model','openai_email','openai_key','openai_model'].
    function ai_guru_config(PDO $pdo, int $guru_id): array {
        $out = [
            'provider' => 'gemini',
            'gemini_email' => '',
            'gemini_key' => '',
            'gemini_key_pribadi' => '',
            'gemini_model' => '',
            'openai_email' => '',
            'openai_key' => '',
            'openai_model' => '',
            'is_system_key' => false,
        ];
        try {
            $st = $pdo->prepare("SELECT ai_provider, ai_gemini_email, ai_gemini_key, ai_gemini_model, ai_openai_email, ai_openai_key, ai_openai_model FROM tb_guru WHERE id_guru = ? LIMIT 1");
            $st->execute([$guru_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (in_array($row['ai_provider'] ?? '', ['gemini', 'openai'], true)) {
                    $out['provider'] = $row['ai_provider'];
                }
                $out['gemini_email'] = trim((string)($row['ai_gemini_email'] ?? ''));
                $out['gemini_key'] = trim((string)($row['ai_gemini_key'] ?? ''));
                $out['gemini_key_pribadi'] = $out['gemini_key'];
                $gm = trim((string)($row['ai_gemini_model'] ?? ''));
                if ($gm !== '' && function_exists('ai_normalize_gemini_model')) {
                    $gm = ai_normalize_gemini_model($gm);
                }
                $out['gemini_model'] = $gm;
                $out['openai_email'] = trim((string)($row['ai_openai_email'] ?? ''));
                $out['openai_key'] = trim((string)($row['ai_openai_key'] ?? ''));
                $out['openai_model'] = trim((string)($row['ai_openai_model'] ?? ''));
            }
        } catch (Throwable $e) { /* tabel/kolom belum ada */
        }
        if ($out['gemini_key'] === '') {
            $sys = ai_get_system_gemini_key($pdo);
            if ($sys !== '') {
                $out['gemini_key'] = $sys;
                $out['is_system_key'] = true;
            }
        }
        return $out;
    }
}

if (!function_exists('ai_http_post_json')) {
    // POST JSON via cURL (fallback stream). Kembalikan [http_code, body, error].
    function ai_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 90): array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $err = (string)curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$code, (string)$body, $err];
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ((array)($http_response_header ?? []) as $h) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', (string)$h, $m)) {
                $code = (int)$m[1];
                break;
            }
        }
        return [$code, (string)$body, $body === false ? 'Koneksi gagal.' : ''];
    }
}

if (!function_exists('ai_asesmen_list')) {
    // Daftar jenis asesmen baku (juga dipakai sebagai nama file unduhan).
    function ai_asesmen_list(): array {
        return [
            'Asesmen Formatif Harian',
            'Asesmen Sumatif Tengah Semester',
            'Asesmen Sumatif Akhir Semester',
            'Asesmen Sumatif Akhir Tahun',
            'AKM/ANBK',
        ];
    }
}

if (!function_exists('ai_asesmen_short')) {
    // Singkatan baku jenis asesmen untuk nama file.
    function ai_asesmen_short(string $jenis): string {
        $map = [
            'Asesmen Formatif Harian' => 'AFH',
            'Asesmen Sumatif Tengah Semester' => 'ASTS',
            'Asesmen Sumatif Akhir Semester' => 'ASAS',
            'Asesmen Sumatif Akhir Tahun' => 'ASAT',
            'AKM/ANBK' => 'AKM-ANBK',
        ];
        $jenis = trim($jenis);
        if (isset($map[$jenis])) {
            return $map[$jenis];
        }
        return 'SOAL';
    }
}

if (!function_exists('ai_slug_part')) {
    // Slug satu segmen nama file: huruf-angka, spasi jadi strip, kosong jadi '-'.
    function ai_slug_part(string $v, int $max = 30): string {
        $v = trim($v);
        if ($v === '' || $v === '-') {
            return '-';
        }
        // Tahun ajaran 2025/2026 -> 2025-2026 agar aman di semua OS.
        $v = str_replace('/', '-', $v);
        $v = preg_replace('/[^A-Za-z0-9\-]+/', '-', $v);
        $v = preg_replace('/-+/', '-', $v);
        $v = trim($v, '-');
        if ($v === '') {
            return '-';
        }
        return substr($v, 0, $max);
    }
}

if (!function_exists('ai_nama_file_asesmen')) {
    // Pola: SINGKATAN_mapel_kelas_semester_tahun.ext, contoh AFH_Matematika_7_Ganjil_2025-2026.pdf
    function ai_nama_file_asesmen(string $jenis, string $ext, array $ctx = []): string {
        $short = ai_asesmen_short($jenis);
        $mapel = ai_slug_part((string)($ctx['mapel'] ?? ''));
        $kelas = ai_slug_part((string)($ctx['kelas'] ?? ''));
        $semester = ai_slug_part((string)($ctx['semester'] ?? ''));
        $tahun = ai_slug_part((string)($ctx['tahun'] ?? ''), 12);
        $base = $short . '_' . $mapel . '_' . $kelas . '_' . $semester . '_' . $tahun;
        $base = substr($base, 0, 120);
        return $base . '.' . ltrim($ext, '.');
    }
}

if (!function_exists('ai_zip_stored')) {
    // Bangun berkas ZIP standar (kompresi Deflate bila tersedia, fallback Stored) untuk berkas DOCX valid.
    function ai_zip_stored(array $files): string {
        $local = '';
        $central = '';
        $offset = 0;
        $time = time();
        $dtime = getdate($time);
        $dosTime = ($dtime['hours'] << 11) | ($dtime['minutes'] << 5) | floor($dtime['seconds'] / 2);
        $dosDate = (($dtime['year'] - 1980) << 9) | ($dtime['mon'] << 5) | $dtime['mday'];

        foreach ($files as $name => $data) {
            $name = str_replace('\\', '/', (string)$name);
            $data = (string)$data;
            $uncompressedSize = strlen($data);
            $crc = crc32($data) & 0xFFFFFFFF;

            $method = 0;
            $cdata = $data;
            $compressedSize = $uncompressedSize;
            if (function_exists('gzdeflate')) {
                $compressed = gzdeflate($data);
                if ($compressed !== false && strlen($compressed) < $uncompressedSize) {
                    $method = 8;
                    $cdata = $compressed;
                    $compressedSize = strlen($cdata);
                }
            }

            $nlen = strlen($name);

            $lh = pack('V', 0x04034b50)
                . pack('v', 20)
                . pack('v', 0)
                . pack('v', $method)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $compressedSize)
                . pack('V', $uncompressedSize)
                . pack('v', $nlen)
                . pack('v', 0);

            $local .= $lh . $name . $cdata;

            $cd = pack('V', 0x02014b50)
                . pack('v', 20)
                . pack('v', 20)
                . pack('v', 0)
                . pack('v', $method)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $compressedSize)
                . pack('V', $uncompressedSize)
                . pack('v', $nlen)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('V', 0x20)
                . pack('V', $offset)
                . $name;

            $central .= $cd;
            $offset += strlen($lh) + $nlen + $compressedSize;
        }

        $eocd = pack('V', 0x06054b50)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', count($files))
            . pack('v', count($files))
            . pack('V', strlen($central))
            . pack('V', $offset)
            . pack('v', 0);

        return $local . $central . $eocd;
    }
}

if (!function_exists('ai_build_soal_prompt')) {
    // Susun prompt generate 1 PAKET soal multi-bentuk + kisi-kisi.
    // $in['paket'] = ['Pilihan Ganda'=>5, 'Uraian'=>2, ...]; fallback legacy $in['bentuk']+$in['jumlah'].
    function ai_build_soal_prompt(array $in): string {
        $is_kma = strtoupper($in['kurikulum'] ?? '') === 'KMA';
        $kur = $is_kma
            ? 'KMA 1503 Tahun 2025 tentang Kurikulum Berbasis Cinta (KBC) untuk Madrasah'
            : 'Permendikdasmen Nomor 46 Tahun 2025 (Standar Kompetensi Lulusan, Standar Isi, dan Struktur Kurikulum TK/RA, SD/MI, SMP/MTs, SMA/MA)';
        $allow = ['Pilihan Ganda', 'Pilihan Ganda Kompleks', 'Menjodohkan', 'Isian Singkat', 'Uraian'];
        $paket = [];
        if (!empty($in['paket']) && is_array($in['paket'])) {
            foreach ($allow as $b) {
                $n = (int)($in['paket'][$b] ?? 0);
                if ($n > 0) {
                    $paket[$b] = $n;
                }
            }
        }
        if (!$paket) {
            $b = trim((string)($in['bentuk'] ?? 'Pilihan Ganda'));
            if (!in_array($b, $allow, true)) {
                $b = 'Pilihan Ganda';
            }
            $paket = [$b => max(1, (int)($in['jumlah'] ?? 5))];
        }
        // Total butir = jumlah semua termasuk tiap baris pasangan Menjodohkan
        // (contoh: 10+5+5+5+5 = 30 butir; 1 butir soal Menjodohkan berisi N baris tabel).
        $total = array_sum($paket);
        $n_menjodohkan = (int)($paket['Menjodohkan'] ?? 0);
        $total_soal_item = $total - ($n_menjodohkan > 0 ? $n_menjodohkan - 1 : 0);
        $rincian = [];
        foreach ($paket as $b => $n) {
            if ($b === 'Menjodohkan') {
                $rincian[] = '- ' . $n . ' butir Menjodohkan = 1 butir soal berisi 1 tabel 4 kolom dengan tepat ' . $n . ' baris pasangan (pernyataan no 1-' . $n . ', pilihan jawaban huruf A-' . chr(64 + $n) . ') + dijabarkan menjadi ' . $n . ' baris kisi-kisi';
            } else {
                $rincian[] = '- ' . $n . ' butir ' . $b;
            }
        }
        $lines = [];
        $lines[] = 'Anda adalah penyusun soal profesional untuk madrasah/sekolah di Indonesia.';
        $lines[] = 'Buatkan 1 PAKET soal berjumlah ' . $total . ' butir beserta kisi-kisi (' . $total . ' baris kisi-kisi), kunci jawaban, dan pembahasan singkat.';
        $lines[] = 'Komposisi paket (WAJIB dipenuhi tepat, tiap butir beri field "bentuk" sesuai jenisnya):';
        $lines = array_merge($lines, $rincian);
        $lines[] = 'WAJIB patuh pada dasar kurikulum berikut: ' . $kur . '.';
        if ($is_kma) {
            $lines[] = 'Prinsip KBC: cinta kepada Allah, cinta kepada sesama, cinta kepada lingkungan; integrasikan nilai moderasi beragama dan adab bila relevan dengan topik.';
        } else {
            $lines[] = 'Prinsip Kurikulum Merdeka / Standar Isi: fokus pada topik dan profil pelajar Pancasila.';
        }
        $lines[] = 'Konteks pembelajaran:';
        $lines[] = '- Mata pelajaran: ' . $in['mapel'];
        $lines[] = '- Kelas: ' . $in['kelas'];
        if (trim((string)($in['jenis_asesmen'] ?? '')) !== '') $lines[] = '- Jenis asesmen: ' . trim((string)$in['jenis_asesmen']) . ' (sesuaikan cakupan dan kedalaman: formatif harian fokus 1 topik; sumatif tengah/akhir semester mencakup materi semester berjalan; sumatif akhir tahun mencakup 2 semester; AKM/ANBK fokus literasi-numerasi dengan stimulus).';
        if (trim((string)($in['semester'] ?? '')) !== '') $lines[] = '- Semester: ' . trim((string)$in['semester']) . ' (FOKUS hanya pada materi semester ini, JANGAN mengambil materi semester lain).';
        $lines[] = '- Topik / Pokok bahasan (WAJIB, fokus utama): ' . trim((string)($in['topik'] ?? ''));
        if (trim((string)($in['sub_topik'] ?? '')) !== '') $lines[] = '- Sub topik (fokus turunan): ' . trim((string)$in['sub_topik']);
        if (trim((string)($in['materi'] ?? '')) !== '') {
            $lines[] = '- Materi detail: ' . $in['materi'];
            $lines[] = 'BATASAN TOPIK: hanya buat soal dari topik/sub-topik dan materi di atas. JANGAN melebar ke bab lain di luar itu.';
        } else {
            $lines[] = '- Materi detail: tidak diberikan (opsional). Susun soal dari topik/sub-topik di atas sesuai mapel/kelas/semester dan kurikulum.';
        }
        if (trim((string)($in['instruksi_tambahan'] ?? '')) !== '') {
            $lines[] = '- Instruksi / Perintah Tambahan Khusus: ' . trim((string)$in['instruksi_tambahan']) . ' (PENTING: Wajib dipatuhi dan diimplementasikan pada pembuatan butir soal/kisi-kisi terkait).';
        }

        // Penanganan Karakteristik Khusus & Gambar
        $d_kar = [];
        $has_hots = false;
        $has_literacy = false;
        $has_numeracy = false;
        $has_with_image = false;
        if (!empty($in['karakteristik']) && is_array($in['karakteristik'])) {
            foreach ($in['karakteristik'] as $kn => $kn_jml) {
                $kn_clean = trim((string)$kn);
                $jml_kar = (int)$kn_jml;
                if ($jml_kar > 0) {
                    $d_kar[] = $jml_kar . ' butir ' . $kn_clean;
                    $kl = strtolower($kn_clean);
                    if (strpos($kl, 'hots') !== false) $has_hots = true;
                    if (strpos($kl, 'literacy') !== false || strpos($kl, 'literasi') !== false) $has_literacy = true;
                    if (strpos($kl, 'numeracy') !== false || strpos($kl, 'numerasi') !== false) $has_numeracy = true;
                    if (strpos($kl, 'image') !== false || strpos($kl, 'gambar') !== false) $has_with_image = true;
                }
            }
        }

        if (!$has_with_image) {
            $lines[] = '- ATURAN PENTING GAMBAR / VISUAL: Karakteristik "With Image" TIDAK DIPILIH oleh pengguna. DILARANG KERAS membuat gambar/ilustrasi pada butir soal mana pun! JANGAN menuliskan blok [GAMBAR: ...] dan field "gambar" pada JSON WAJIB string kosong "". DILARANG menambahkan tag "With Image" pada karakteristik!';
        } else {
            $lines[] = '- ATURAN GAMBAR / ILUSTRASI SOAL (Hanya untuk butir yang diminta With Image):';
            $lines[] = '  1. RELEVANSI 100%: Gambar WAJIB langsung terkait dengan materi pokok dan berupa diagram teknis ilmiah atau posisi gerak.';
            $lines[] = '  2. FORMAT BLOK: [GAMBAR: Nama objek teknis dalam bahasa Indonesia | search: english search keywords for Wikimedia]';
        }

        // Aturan Level Kesulitan & Taksonomi Bloom / Karakteristik
        $d_kesulitan = [];
        if (!empty($in['kesulitan_dist']) && is_array($in['kesulitan_dist'])) {
            foreach ($in['kesulitan_dist'] as $kd => $kv) {
                if ((int)$kv > 0) {
                    $d_kesulitan[] = (int)$kv . ' butir ' . $kd;
                }
            }
        }
        if ($d_kesulitan) {
            $lines[] = '- Distribusi tingkat kesulitan (WAJIB dipenuhi persis): ' . implode(', ', $d_kesulitan) . '. Tandai tiap butir pada field "kesulitan" dengan nilai persis "Mudah", "Sedang", atau "Sukar".';
        } else {
            $lines[] = '- Tingkat kesulitan: ACAK dan MERATA untuk tiap bentuk soal (Mudah, Sedang, Sukar). Tandai tiap butir pada field "kesulitan".';
        }

        $d_bloom = [];
        if (!empty($in['bloom']) && is_array($in['bloom'])) {
            $d_bloom = array_map('strtoupper', $in['bloom']);
        }
        if ($d_bloom) {
            $lines[] = '- Level Kognitif Taksonomi Bloom (WAJIB gunakan hanya level berikut: ' . implode(', ', $d_bloom) . '). Sebar butir soal secara proporsional ke level-level tersebut dan cantumkan pada field "level_kognitif".';
        } else {
            $lines[] = 'Level kognitif (WAJIB untuk tiap butir kisi-kisi dan soal, cantumkan field "level_kognitif" dengan nilai persis C1, C2, C3, C4, C5, atau C6):';
            $lines[] = '- Kognitif C1 (Mengingat), C2 (Memahami), C3 (Mengaplikasikan), C4 (Menganalisis), C5 (Mengevaluasi), C6 (Mencipta).';
        }
        if ($d_kar) {
            $lines[] = '=======================================================';
            $lines[] = 'DISIPLIN IMPLEMENTASI KARAKTERISTIK / BENTUK KHUSUS SOAL (MUTLAK WAJIB DIPENUHI):';
            $lines[] = 'Target komposisi karakteristik: ' . implode(', ', $d_kar) . '.';
            $lines[] = 'PRINSIP PENTING TINGKAT KESULITAN & KARAKTERISTIK:';
            $lines[] = '- Karakteristik khusus (HOTS, Literacy, Numeracy, With Image) BUKAN HANYA untuk tingkat kesulitan Sukar!';
            $lines[] = '- Soal dengan tingkat kesulitan "Mudah" maupun "Sedang" JUGA BISA dan WAJIB dibuat dengan karakteristik HOTS / Literacy / Numeracy jika diminta pada komposisi.';
            $lines[] = '  * HOTS level Mudah/Sedang: stimulus kasus kontekstual sederhana atau situasi sehari-hari yang dekat dengan siswa, menganalisis hubungan sebab-akibat sederhana tanpa jebakan rumit.';
            $lines[] = '  * Literacy level Mudah/Sedang: wacana bacaan ringkas dan lugas (1-2 paragraf jelas), pertanyaan menguji pemahaman ide pokok, pesan tersirat, atau hubungan sebab-akibat dalam teks.';
            $lines[] = '  * Numeracy level Mudah/Sedang: data tabel/angka sederhana dengan perhitungan atau interpretasi langsung yang terukur.';
            $lines[] = '- PISAHKAN butir: Jika pengguna meminta ' . implode(' dan ', $d_kar) . ' dari total ' . $total . ' butir, PASTIKAN JUMLAH BUTIR YANG DIBERI LABEL DAN CIRI MASING-MASING KARAKTERISTIK TEPAT SESUAI TARGET TERSEBUT! Jangan menggabungkan seluruh karakteristik ke dalam butir yang sama sehingga butir lain kosong.';
            $lines[] = '- Tiap butir soal dan kisi-kisi WAJIB memiliki field "karakteristik" yang diisi persis sesuai jenisnya ("HOTS", "Literacy", "Numeracy", "With Image", atau jika satu butir memenuhi dua kriteria tulis misal "HOTS, Literacy"). Jika butir reguler, isi "".';
            $lines[] = 'DILARANG KERAS MEMBUAT SOAL TEKSTUAL HAFALAN BIASA untuk butir-butir berkarakteristik khusus! Ikuti panduan baku berikut:';

            if ($has_hots) {
                $lines[] = '--- STANDAR BAKU BUTIR HOTS (HIGHER ORDER THINKING SKILLS) ---';
                $lines[] = '1. DILARANG KERAS membuat pertanyaan hafalan definisi langsung, pengertian, atau hafalan langkah prosedural teoritis polos (contoh DILARANG: "Jelaskan bagaimana cara melakukan teknik...", "Sebutkan pengertian...", "Apa fungsi...", "Sebutkan tahapan...").';
                $lines[] = '2. WAJIB DIAWALI STIMULUS KASUS / SITUASI OTENTIK (2-4 kalimat): ceritakan skenario masalah nyata, dinamika pertandingan/lapangan, dilema taktis/strategi, situasi krisis, atau fenomena empiris yang relevan dengan topik materi.';
                $lines[] = '3. Dapat diterapkan pada tingkat kesulitan Mudah, Sedang, maupun Sukar (dengan menyesuaikan kompleksitas kasus stimulus).';
                $lines[] = '4. Siswa diminta mengidentifikasi akar masalah dari stimulus tersebut, membandingkan efektivitas opsi tindakan, memecahkan kebuntuan situasi, atau merumuskan strategi perbaikan berbasis prinsip materi.';
            }

            if ($has_literacy) {
                $lines[] = '--- STANDAR BAKU BUTIR LITERACY (LITERASI MEMBACA & ANALISIS TEKS) ---';
                $lines[] = '1. WAJIB MENYAJIKAN TEKS STIMULUS BACAAN yang utuh, bermakna, dan informatif (1-2 paragraf narasi deskriptif, artikel populer edukatif, studi kasus, atau dialog bermakna) sebelum kalimat pertanyaan.';
                $lines[] = '2. Dapat diterapkan pada tingkat kesulitan Mudah, Sedang, maupun Sukar.';
                $lines[] = '3. Format penulisan pada field "pertanyaan": Awali dengan teks bacaan terstruktur, contoh:';
                $lines[] = '   "Bacalah wacana berikut dengan cermat!\n\n[Teks bacaan narasi/artikel 1-2 paragraf kaya informasi]\n\nBerdasarkan wacana di atas, [kalimat pertanyaan analitis]..."';
                $lines[] = '4. Pertanyaan harus menguji kompetensi literasi: menemukan informasi tersirat, menganalisis hubungan kausalitas antar-peristiwa dalam bacaan, menyimpulkan pesan utama, atau mengevaluasi sudut pandang penulis.';
                $lines[] = '5. DILARANG membuat soal literasi yang berdiri sendiri tanpa teks bacaan!';
            }

            if ($has_numeracy) {
                $lines[] = '--- STANDAR BAKU BUTIR NUMERACY (NUMERASI & INTERPRETASI DATA) ---';
                $lines[] = '1. WAJIB MENYAJIKAN DATA KUANTITATIF (angka statistik, tabel data mini, persentase, perbandingan rasio, skor/waktu, atau kalkulasi logis) di dalam stimulus soal, disesuaikan dengan konteks materi yang sedang diuji.';
                $lines[] = '2. Siswa dituntut membaca data numerik, melakukan perbandingan atau perhitungan matematis logis, lalu mengambil keputusan/kesimpulan berbasis data tersebut.';
                $lines[] = '3. DILARANG membuat soal numerasi tanpa mencantumkan data angka/kuantitatif di dalam soal!';
            }

            if ($has_with_image) {
                $lines[] = '--- STANDAR BAKU BUTIR WITH IMAGE (SOAL BERGAMBAR / BERILUSTRASI VISUAL) ---';
                $lines[] = '1. WAJIB menyertakan blok dengan format persis:';
                $lines[] = '   [GAMBAR: Nama objek/diagram edukatif dalam bahasa Indonesia | search: english search keywords for Wikimedia]';
                $lines[] = '   Contoh:';
                $lines[] = '   - Mapel PJOK: [GAMBAR: Diagram teknik operan di atas kepala bola tangan | search: handball overhead pass technique]';
                $lines[] = '   - Mapel IPA: [GAMBAR: Diagram sistem pencernaan manusia dengan label organ | search: human digestive system diagram]';
                $lines[] = '   - Mapel Matematika: [GAMBAR: Jaring-jaring bangun ruang prisma segitiga | search: triangular prism net geometry]';
                $lines[] = '   - Mapel IPS: [GAMBAR: Peta persebaran flora dan fauna di Indonesia | search: map of fauna regions of indonesia]';
                $lines[] = '2. Pada teks pertanyaan, WAJIB MERUJUK SECARA LANGSUNG ke gambar tersebut (contoh: "Perhatikan gambar teknik gerakan di atas! Analisislah posisi kedua lengan saat...").';
                $lines[] = '3. DILARANG membuat deskripsi puitis/cerita orang tersenyum/suasana hati! Tuliskan objek visual materi pelajaran secara presisi dan teknis.';
                $lines[] = '4. Field "gambar" pada JSON diisi persis sama dengan teks di dalam blok [GAMBAR: ...] tersebut.';
                $lines[] = '5. Soal TIDAK BOLEH bisa dijawab tanpa melihat gambar!';
            }
            $lines[] = '=======================================================';
        }
        $lines[] = 'Aturan per bentuk:';
        $lines[] = '- Pilihan Ganda: tiap butir tepat 4 opsi berlabel A-D dan satu kunci (A/B/C/D).';
        $lines[] = '- Pilihan Ganda Kompleks: tiap butir memiliki 4 opsi berlabel A-D, dan kunci jawaban WAJIB TEPAT 2 PILIHAN BENAR SAJA (contoh "A,C" atau "B,D"). Siswa memilih tepat 2 jawaban yang benar.';
        $lines[] = '- Menjodohkan: Pada bagian "soal", HANYA DIBUAT 1 BUTIR SOAL yang memuat 1 tabel 4 kolom ("no", "kiri", "huruf", "kanan") dengan tepat ' . $n_menjodohkan . ' baris pasangan. TETAPI pada bagian "kisi_kisi", WAJIB DIBUAT TEPAT ' . $n_menjodohkan . ' BARIS KISI-KISI terpisah (masing-masing nomor urut untuk tiap baris pasangan yang dijodohkan dengan indikator spesifik per baris pasangan).';
        $lines[] = '- Isian Singkat: kunci berupa jawaban singkat 1-5 kata.';
        $lines[] = '- Uraian: kunci berupa jawaban uraian 1-3 kalimat sebagai acuan penskoran.';
        $lines[] = 'Kisi-kisi WAJIB memuat kolom: no, materi, cp, tp, indikator, bentuk, level_kognitif (L1-L4), kesulitan, karakteristik, bobot.';
        $lines[] = 'CP = turunkan dari kurikulum: Permendikdasmen CP 046 (fase/kelas terkait) atau KMA 1503+KBC (madrasah). TP = jabaran operasional topik/sub-topik di atas (1-2 kalimat, diawali kata kerja operasional).';
        $lines[] = 'Jawab HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan di luar JSON) memakai skema persis ini:';
        $lines[] = '{"kisi_kisi":[{"no":1,"materi":"...","cp":"...","tp":"...","indikator":"...","bentuk":"Pilihan Ganda","level_kognitif":"L2","kesulitan":"Sedang","karakteristik":"HOTS","bobot":1}],"soal":[{"no":1,"bentuk":"Pilihan Ganda","karakteristik":"HOTS","pertanyaan":"...","gambar":"...","opsi":{"A":"...","B":"...","C":"...","D":"..."},"tabel":[],"kunci":"A","pembahasan":"...","cp":"...","tp":"...","indikator":"...","level_kognitif":"L2","kesulitan":"Sedang","bobot":1}]}';
        if (!$has_with_image) {
            $lines[] = 'Field "gambar", "gambar_caption", dan "gambar_svg" pada tiap butir soal: WAJIB STRING KOSONG "" karena pengguna TIDAK MEMILIH opsi With Image. DILARANG MENULISKAN BLOK [GAMBAR: ...] DI DALAM TEKS PERTANYAAN!';
        } else {
            $lines[] = 'KETENTUAN KHUSUS UNTUK BUTIR WITH IMAGE (ILUSTRASI VISUAL ASLI GENERATOR):';
            $lines[] = '1. Generator WAJIB membuat ilustrasi/diagram edukatif sendiri menggunakan format SVG mandiri pada field "gambar_svg" (DILARANG mengambil URL web luar).';
            $lines[] = '2. Pada field "gambar_caption", tuliskan judul formal diagram (contoh: "Diagram Lapangan Bola Voli dan Ukuran Garis Serang").';
            $lines[] = '3. Pada field "gambar_svg", tuliskan kode XML SVG utuh diawali <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 300"> dan diakhiri </svg>. Diagram harus memiliki latar belakang terang, garis dimensi berpanah, serta label teks nama/angka yang tajam dan presisi sesuai materi soal.';
            $lines[] = '4. Field "gambar" diisi sama dengan gambar_caption. Pada teks "pertanyaan", cantumkan kalimat stimulus pengantar (misal: "Perhatikan diagram berikut!") dan sertakan blok [GAMBAR: {gambar_caption}].';
            $lines[] = '5. Untuk butir soal reguler yang TIDAK memiliki karakteristik With Image, field "gambar_svg", "gambar_caption", dan "gambar" WAJIB diisi string kosong "".';
        }
        $lines[] = 'Untuk bentuk selain Pilihan Ganda / Pilihan Ganda Kompleks, isi "opsi" dengan {} dan "kunci" dengan jawaban benar sesuai aturan bentuk di atas. Untuk Menjodohkan, "opsi" tetap {} dan "tabel" WAJIB diisi sesuai aturan. Nomor "no" pada "kisi_kisi" urut 1 sampai ' . $total . '. Nomor "no" pada "soal" urut 1 sampai ' . $total_soal_item . ' (Menjodohkan hanya 1 nomor karena hanya 1 butir soal).';
        return implode("\n", $lines);
    }
}

if (!function_exists('ai_build_perangkat_prompt')) {
    // Susun prompt generate 1 dokumen Perangkat Pembelajaran (CP/TP, ATP, Modul Ajar,
    // RPP, Silabus, Prota, Promes, KKTP, Lainnya) sesuai kurikulum madrasah/sekolah.
    // $in: kurikulum, jenis_perangkat, mapel, kelas, semester, tahun_ajaran,
    //      topik, sub_topik, materi, instruksi_tambahan.
    function ai_build_perangkat_prompt(array $in): string {
        $is_kma = strtoupper($in['kurikulum'] ?? '') === 'KMA';
        $kur = $is_kma
            ? 'KMA 1503 Tahun 2025 tentang Kurikulum Berbasis Cinta (KBC) untuk Madrasah'
            : 'Permendikdasmen Nomor 46 Tahun 2025 (Standar Kompetensi Lulusan, Standar Isi, dan Struktur Kurikulum TK/RA, SD/MI, SMP/MTs, SMA/MA)';
        $jenis = trim((string)($in['jenis_perangkat'] ?? 'Modul Ajar'));
        $lines = [];
        $lines[] = 'Anda adalah penyusun perangkat pembelajaran profesional untuk madrasah/sekolah di Indonesia.';
        $lines[] = 'Buatkan 1 dokumen "' . $jenis . '" yang lengkap, rapi, dan siap pakai beserta kunci/penjelasan bila relevan.';
        $lines[] = 'WAJIB patuh pada dasar kurikulum berikut: ' . $kur . '.';
        if ($is_kma) {
            $lines[] = 'WAJIB IMPLEMENTASI KURIKULUM BERBASIS CINTA (KBC) KMA 1503 TAHUN 2025:';
            $lines[] = '- Insersikan 3 dimensi KBC (Cinta kepada Allah, Cinta kepada Sesama, Cinta kepada Lingkungan) secara eksplisit di seluruh bagian dokumen.';
            $lines[] = '- Pada CP, TP, kegiatan pembelajaran (pendahuluan, inti, penutup), asesmen, dan refleksi: cantumkan dengan jelas integrasi nilai cinta kasih, adab madrasah, dan moderasi beragama.';
        } else {
            $lines[] = 'Prinsip Kurikulum Merdeka / Standar Isi: fokus pada topik dan profil pelajar Pancasila.';
        }
        $lines[] = 'Konteks pembelajaran:';
        $lines[] = '- Jenis perangkat: ' . $jenis;
        $lines[] = '- Mata pelajaran: ' . trim((string)($in['mapel'] ?? '-'));
        $lines[] = '- Kelas: ' . trim((string)($in['kelas'] ?? '-'));
        if (trim((string)($in['semester'] ?? '')) !== '') $lines[] = '- Semester: ' . trim((string)$in['semester']);
        if (trim((string)($in['tahun_ajaran'] ?? '')) !== '') $lines[] = '- Tahun ajaran: ' . trim((string)$in['tahun_ajaran']);
        $lines[] = '- Topik / Materi pokok (WAJIB, fokus utama): ' . trim((string)($in['topik'] ?? ''));
        if (trim((string)($in['sub_topik'] ?? '')) !== '') $lines[] = '- Sub topik (fokus turunan): ' . trim((string)$in['sub_topik']);
        if (trim((string)($in['materi'] ?? '')) !== '') {
            $lines[] = '- Materi detail: ' . $in['materi'];
            $lines[] = 'BATASAN TOPIK: hanya susun perangkat dari topik/sub-topik dan materi di atas. JANGAN melebar ke bab lain di luar itu.';
        } else {
            $lines[] = '- Materi detail: tidak diberikan (opsional). Susun perangkat dari topik/sub-topik di atas sesuai mapel/kelas/semester dan kurikulum.';
        }
        if (trim((string)($in['instruksi_tambahan'] ?? '')) !== '') {
            $lines[] = '- Instruksi / Perintah Tambahan Khusus: ' . trim((string)$in['instruksi_tambahan']) . ' (PENTING: Wajib dipatuhi dan diimplementasikan pada dokumen yang dibuat. Bila instruksi meminta gambar/ilustrasi, WAJIB menuliskan blok [GAMBAR: uraian detail objek gambar agar guru dapat menggambar ulang/menempelkan gambar pada naskah cetak] pada bagian yang memerlukan gambar).';
        }
        $lines[] = 'Struktur dokumen sesuai jenis perangkat:';
        $lines[] = '- CP/TP: tulis CP (turunan kurikulum sesuai fase/kelas) + daftar TP operasional per pertemuan (diawali kata kerja operasional), masing-masing 1-2 kalimat.';
        $lines[] = '- ATP: tulis alur tujuan pembelajaran per pertemuan/bab secara berurutan (tujuan, materi pokok, perkiraan JP).';
        $lines[] = '- Modul Ajar: tulis identitas (mapel, kelas, semester, alokasi waktu), CP, TP, materi pokok, kegiatan inti (pendahuluan-inti-penutup), asesmen, pengayaan-remedial, refleksi, media/sumber belajar.';
        $lines[] = '- RPP: tulis identitas, KI/KD atau CP-TP, indikator, tujuan, materi, metode/model, langkah pembelajaran per pertemuan, penilaian, media/sumber.';
        $lines[] = '- Silabus: tulis tabel identitas, KI/KD atau CP, materi pokok, kegiatan pembelajaran, indikator, penilaian, alokasi waktu, sumber belajar.';
        $lines[] = '- Program Tahunan (Prota): tulis tabel semester, bab/topik, alokasi JP per semester sesuai kalender pendidikan.';
        $lines[] = '- Program Semester (Promes): tulis tabel minggu efektif, bab/topik, JP, keterangan per bulan.';
        $lines[] = '- Kriteria Ketercapaian (KKTP): tulis tabel tujuan/indikator, rentang nilai, kriteria ketuntasan, dan tindak lanjut.';
        $lines[] = '- LKPD (Lembar Kerja Peserta Didik): tulis identitas kelompok/nama siswa, capaian & tujuan pembelajaran, petunjuk pengerjaan, stimulus materi/kasus kontekstual, langkah kegiatan bertahap, tabel pengamatan/pengisian data peserta didik, pertanyaan diskusi analitis, dan lembar kesimpulan/refleksi.';
        $lines[] = '- PPT (Slide Show) Materi Pembelajaran: susun naskah presentasi slide demi slide secara lengkap dan menarik (Slide 1: Judul & Identitas, Slide 2: Tujuan Pembelajaran & Apersepsi KBC, Slide 3-7: Materi Pokok & Visualisasi [GAMBAR: ...], Slide 8: Aktivitas/Diskusi Interaktif Siswa, Slide 9: Rangkuman & Refleksi, Slide 10: Penugasan & Penutup); sertakan poin-poin teks tampilan slide dan narasi catatan pembicara (speaker notes) untuk guru.';
        $lines[] = '- Materi Kokurikuler: tulis modul/panduan kegiatan kokurikuler lengkap (tema projek/kokurikuler, alokasi JP/waktu, dimensi profil Pancasila & PPRA / pilar cinta KBC, tujuan projek, latar belakang & stimulus kontekstual, alur tahapan aktivitas kokurikuler (tahap pengenalan, kontekstualisasi, aksi nyata, refleksi & tindak lanjut), tabel rubrik penilaian observasi kokurikuler, dan panduan aksi peserta didik).';
        $lines[] = '- Lainnya: susun dokumen pembelajaran yang rapi sesuai topik (judul, tujuan, uraian materi, penutup).';
        $lines[] = 'PEDOMAN MUTLAK & DISIPLIN TINGGI VISUALISASI GAMBAR [GAMBAR: ...]:';
        $lines[] = '1. RELEVANSI TOTAL DENGAN TEMA & SUBJEK: Setiap blok [GAMBAR: ...] WAJIB 100% fokus pada konsep materi pokok yang sedang dipelajari. DILARANG KERAS membuat deskripsi gambar yang tidak sesuai tema atau menyimpang ke hal-hal umum.';
        $lines[] = '2. KESESUAIAN JENJANG & SUBJEK DIDIK (SANGAT PENTING):';
        $lines[] = '   - Bila konteks materi/kegiatan adalah madrasah/sekolah (MI/SD, MTs, MA): Subjek yang digambarkan WAJIB adalah PESERTA DIDIK / MURID MADRASAH (anak-anak usia sekolah berseragam sekolah rapi di lingkungan madrasah/kelas).';
        $lines[] = '   - DILARANG KERAS mendeskripsikan warga masyarakat dewasa, orang kampung, atau petani jika konteksnya adalah kegiatan murid madrasah.';
        $lines[] = '   - Contoh BENAR: [GAMBAR: Peserta didik madrasah ibtidaiyah berseragam sekolah sedang memilah sampah plastik di tempat sampah daur ulang madrasah didampingi guru]';
        $lines[] = '   - Contoh SALAH: [GAMBAR: Warga desa sedang kerja bakti membersihkan rumput di kebun]';
        $lines[] = '3. MATERI SAINS / DIAGRAM / ANATOMI:';
        $lines[] = '   - Jika materi sains (anatomi tubuh manusia, organ pencernaan, pernapasan, tata surya, siklus air, tumbuhan, metamorfosis): Tuliskan istilah ilmiah dan bagian spesifiknya dengan presisi.';
        $lines[] = '   - Contoh BENAR: [GAMBAR: Diagram sistem pencernaan manusia dengan label organ mulut, kerongkongan, lambung, usus halus, usus besar, anus]';
        $lines[] = '   - DILARANG menggunakan bahasa kiasan atau umum yang ambigu.';
        $lines[] = '4. JANGAN MEMBUAT GAMBAR JIKA TIDAK DIBUTUHKAN: Hanya cantumkan blok [GAMBAR: ...] bila materi atau jenis perangkat (seperti LKPD, PPT, Modul Ajar) benar-benar memerlukan stimulus visual untuk pembelajaran siswa.';
        $lines[] = 'Jawab HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan di luar JSON) memakai skema persis ini:';
        $lines[] = '{"judul":"...","cp":"...","tp":"...","materi":"...","tujuan_pembelajaran":"...","indikator":"...","deskripsi":"...","isi_dokumen":"..."}';
        $lines[] = 'Field "isi_dokumen": teks UTUH dan LENGKAP seluruh dokumen sesuai struktur jenis perangkat di atas (format teks biasa dengan baris baru; bagian tabel ditulis sebagai teks berkolom memakai pemisah " | "). WAJIB diisi penuh, minimal 15 baris, JANGAN dikosongkan, JANGAN diringkas, JANGAN diganti kalimat seperti "lihat lampiran" atau sejenisnya. Field "deskripsi": ringkasan 1-2 kalimat isi dokumen.';
        return implode("\n", $lines);
    }
}

if (!function_exists('ai_parse_soal_json')) {
    // Ambil blok JSON dari teks AI (tahan bungkus markdown) lalu validasi struktur minimal.
    function ai_parse_soal_json(string $text): array {
        $t = trim($text);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $t, $m)) {
            $t = $m[1];
        } elseif (($p1 = strpos($t, '{')) !== false && ($p2 = strrpos($t, '}')) !== false && $p2 > $p1) {
            $t = substr($t, $p1, $p2 - $p1 + 1);
        }
        $t = preg_replace('/[\x00-\x1F\x7F]/u', '', $t);
        $data = json_decode($t, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Coba perbaiki koma menggantung
            $t_fix = preg_replace('/,\s*([\]}])/', '$1', $t);
            $data = json_decode($t_fix, true);
        }

        // Jika JSON terputus di tengah jalan (misal output token panjang), coba tutup kurung kurawal/siku
        if (!is_array($data)) {
            $t_closed = $t;
            $open_curly = substr_count($t_closed, '{') - substr_count($t_closed, '}');
            $open_square = substr_count($t_closed, '[') - substr_count($t_closed, ']');
            // Hapus trailing koma atau properti yang terpotong di akhir
            $t_closed = preg_replace('/,\s*"[^"]*"?\s*:\s*[^,}\]]*$/s', '', $t_closed);
            $t_closed = preg_replace('/,\s*$/', '', $t_closed);
            for ($i = 0; $i < $open_square; $i++) { $t_closed .= ']'; }
            for ($i = 0; $i < $open_curly; $i++) { $t_closed .= '}'; }
            $data = json_decode($t_closed, true);
        }

        if (!is_array($data) || empty($data['soal']) || !is_array($data['soal'])) {
            $arr_alt = json_decode($t, true);
            if (is_array($arr_alt) && array_is_list($arr_alt)) {
                $data = ['kisi_kisi' => [], 'soal' => $arr_alt];
            } else {
                $errMsg = 'Format respons dari model AI tidak dapat dibaca sebagai naskah soal. Hal ini biasanya terjadi jika jumlah butir terlalu banyak sehingga respons terpotong. Silakan coba kurangi jumlah soal atau klik Generate ulang.';
                return [false, $errMsg, $errMsg];
            }
        }

        // Normalisasi: bila AI membungkus butir soal ke dalam index numerik internal [0=>..., 1=>...]
        $unwrap_list = function(array $items): array {
            $out = [];
            foreach ($items as $k => $v) {
                if (!is_array($v)) continue;
                $has_numeric_sub = false;
                foreach ($v as $subk => $subv) {
                    if (is_numeric($subk) && is_array($subv) && (isset($subv['pertanyaan']) || isset($subv['indikator']) || isset($subv['bentuk']))) {
                        $out[] = $subv;
                        $has_numeric_sub = true;
                    }
                }
                if (!$has_numeric_sub) {
                    $out[] = $v;
                }
            }
            return array_values($out);
        };

        if (!empty($data['soal']) && is_array($data['soal'])) {
            $data['soal'] = $unwrap_list($data['soal']);
        }
        if (!empty($data['kisi_kisi']) && is_array($data['kisi_kisi'])) {
            $data['kisi_kisi'] = $unwrap_list($data['kisi_kisi']);
        } else {
            $data['kisi_kisi'] = [];
        }
        return [true, $data, ''];
    }
}

if (!function_exists('ai_parse_perangkat_json')) {
    // Ambil blok JSON dokumen perangkat dari teks AI lalu validasi struktur minimal.
    function ai_parse_perangkat_json(string $text): array {
        $t = trim($text);
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $t, $m)) {
            $t = $m[1];
        } elseif (($p1 = strpos($t, '{')) !== false && ($p2 = strrpos($t, '}')) !== false && $p2 > $p1) {
            $t = substr($t, $p1, $p2 - $p1 + 1);
        }
        $data = json_decode($t, true);
        if (!is_array($data) || trim((string)($data['judul'] ?? '')) === '') {
            return [false, null, 'Respons AI bukan JSON perangkat yang valid (judul kosong).'];
        }
        foreach (['cp', 'tp', 'materi', 'tujuan_pembelajaran', 'indikator', 'deskripsi', 'isi_dokumen'] as $f) {
            if (!isset($data[$f]) || !is_string($data[$f])) {
                $data[$f] = trim((string)($data[$f] ?? ''));
            }
        }
        return [true, $data, ''];
    }
}

if (!function_exists('ai_builtin_diagram_svg')) {
    // Generator vektor SVG bawaan untuk materi kurikulum madrasah & sekolah (PJOK, MTK, IPA, IPS, PAI)
    // Menghasilkan diagram bersih, presisi secara proporsi, dan berlabel jelas tanpa ketergantungan web luar.
    function ai_builtin_diagram_svg(string $desc): string {
        $clean = mb_strtolower(trim($desc), 'UTF-8');
        $clean = preg_replace('/\|\s*search:.*$/i', '', $clean);

        // 1. PJOK: Lapangan Bola Voli
        if (strpos($clean, 'voli') !== false || strpos($clean, 'volleyball') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 620 380" font-family="Arial, sans-serif">'
                . '<rect width="620" height="380" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="590" height="350" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="310" y="42" font-size="15" font-weight="bold" text-anchor="middle" fill="#1e293b">DIAGRAM LAPANGAN BOLA VOLI STANDAR FIVB</text>'
                . '<rect x="70" y="80" width="480" height="240" fill="#2563eb" stroke="#ffffff" stroke-width="4"/>'
                . '<line x1="310" y1="80" x2="310" y2="320" stroke="#ffffff" stroke-width="4"/>'
                . '<line x1="230" y1="80" x2="230" y2="320" stroke="#ffffff" stroke-width="3"/>'
                . '<line x1="390" y1="80" x2="390" y2="320" stroke="#ffffff" stroke-width="3"/>'
                . '<rect x="307" y="70" width="6" height="260" fill="#ef4444" fill-opacity="0.85"/>'
                . '<text x="310" y="65" font-size="12" font-weight="bold" text-anchor="middle" fill="#dc2626">JARING / NET</text>'
                . '<defs><marker id="arrV" markerWidth="8" markerHeight="6" refX="4" refY="3" orient="auto"><polygon points="0 0, 8 3, 0 6" fill="#0f172a"/></marker></defs>'
                . '<line x1="70" y1="345" x2="550" y2="345" stroke="#0f172a" stroke-width="1.8" marker-start="url(#arrV)" marker-end="url(#arrV)"/>'
                . '<text x="310" y="365" font-size="13" font-weight="bold" text-anchor="middle" fill="#0f172a">Panjang Total = 18 Meter</text>'
                . '<line x1="570" y1="80" x2="570" y2="320" stroke="#0f172a" stroke-width="1.8" marker-start="url(#arrV)" marker-end="url(#arrV)"/>'
                . '<text x="590" y="205" font-size="13" font-weight="bold" text-anchor="middle" fill="#0f172a" transform="rotate(90, 590, 205)">Lebar = 9 Meter</text>'
                . '<line x1="230" y1="95" x2="310" y2="95" stroke="#ffffff" stroke-width="1.5" marker-start="url(#arrV)" marker-end="url(#arrV)"/>'
                . '<text x="270" y="115" font-size="11" font-weight="bold" text-anchor="middle" fill="#ffffff">3 Meter</text>'
                . '<text x="230" y="72" font-size="11" text-anchor="middle" fill="#475569" font-weight="bold">Garis Serang</text>'
                . '<text x="390" y="72" font-size="11" text-anchor="middle" fill="#475569" font-weight="bold">Garis Serang</text>'
                . '<text x="150" y="205" font-size="12" fill="#ffffff" text-anchor="middle" font-weight="bold">Daerah Belakang</text>'
                . '<text x="270" y="205" font-size="12" fill="#ffffff" text-anchor="middle" font-weight="bold">Daerah Serang</text>'
                . '<text x="350" y="205" font-size="12" fill="#ffffff" text-anchor="middle" font-weight="bold">Daerah Serang</text>'
                . '<text x="470" y="205" font-size="12" fill="#ffffff" text-anchor="middle" font-weight="bold">Daerah Belakang</text>'
                . '</svg>';
        }

        // 2. PJOK: Lapangan Sepak Bola
        if (strpos($clean, 'sepak bola') !== false || strpos($clean, 'football') !== false || strpos($clean, 'soccer') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 620 380" font-family="Arial, sans-serif">'
                . '<rect width="620" height="380" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="590" height="350" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="310" y="42" font-size="15" font-weight="bold" text-anchor="middle" fill="#1e293b">DIAGRAM LAPANGAN SEPAK BOLA STANDAR FIFA</text>'
                . '<rect x="60" y="70" width="500" height="260" fill="#16a34a" stroke="#ffffff" stroke-width="3"/>'
                . '<line x1="310" y1="70" x2="310" y2="330" stroke="#ffffff" stroke-width="3"/>'
                . '<circle cx="310" cy="200" r="45" fill="none" stroke="#ffffff" stroke-width="3"/>'
                . '<circle cx="310" cy="200" r="3" fill="#ffffff"/>'
                . '<rect x="60" y="125" width="75" height="150" fill="none" stroke="#ffffff" stroke-width="2.5"/>'
                . '<rect x="60" y="155" width="30" height="90" fill="none" stroke="#ffffff" stroke-width="2"/>'
                . '<rect x="485" y="125" width="75" height="150" fill="none" stroke="#ffffff" stroke-width="2.5"/>'
                . '<rect x="530" y="155" width="30" height="90" fill="none" stroke="#ffffff" stroke-width="2"/>'
                . '<defs><marker id="arrF" markerWidth="8" markerHeight="6" refX="4" refY="3" orient="auto"><polygon points="0 0, 8 3, 0 6" fill="#0f172a"/></marker></defs>'
                . '<line x1="60" y1="350" x2="560" y2="350" stroke="#0f172a" stroke-width="1.8" marker-start="url(#arrF)" marker-end="url(#arrF)"/>'
                . '<text x="310" y="368" font-size="12" font-weight="bold" text-anchor="middle" fill="#0f172a">Panjang Lapangan: 100 - 110 Meter</text>'
                . '<line x1="580" y1="70" x2="580" y2="330" stroke="#0f172a" stroke-width="1.8" marker-start="url(#arrF)" marker-end="url(#arrF)"/>'
                . '<text x="600" y="205" font-size="12" font-weight="bold" text-anchor="middle" fill="#0f172a" transform="rotate(90, 600, 205)">Lebar: 64 - 75 M</text>'
                . '</svg>';
        }

        // 3. PJOK: Lapangan Bola Basket
        if (strpos($clean, 'basket') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 620 380" font-family="Arial, sans-serif">'
                . '<rect width="620" height="380" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="590" height="350" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="310" y="42" font-size="15" font-weight="bold" text-anchor="middle" fill="#1e293b">DIAGRAM LAPANGAN BOLA BASKET STANDAR FIBA</text>'
                . '<rect x="70" y="75" width="480" height="250" fill="#ea580c" stroke="#ffffff" stroke-width="3"/>'
                . '<line x1="310" y1="75" x2="310" y2="325" stroke="#ffffff" stroke-width="3"/>'
                . '<circle cx="310" cy="200" r="40" fill="none" stroke="#ffffff" stroke-width="3"/>'
                . '<rect x="70" y="155" width="80" height="90" fill="none" stroke="#ffffff" stroke-width="2.5"/>'
                . '<circle cx="150" cy="200" r="30" fill="none" stroke="#ffffff" stroke-width="2"/>'
                . '<rect x="470" y="155" width="80" height="90" fill="none" stroke="#ffffff" stroke-width="2.5"/>'
                . '<circle cx="470" cy="200" r="30" fill="none" stroke="#ffffff" stroke-width="2"/>'
                . '<text x="310" y="358" font-size="12" font-weight="bold" text-anchor="middle" fill="#0f172a">Panjang: 28 Meter | Lebar: 15 Meter</text>'
                . '</svg>';
        }

        // 4. PJOK: Lapangan Bulu Tangkis
        if (strpos($clean, 'bulu tangkis') !== false || strpos($clean, 'bulutangkis') !== false || strpos($clean, 'badminton') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 620 380" font-family="Arial, sans-serif">'
                . '<rect width="620" height="380" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="590" height="350" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="310" y="42" font-size="15" font-weight="bold" text-anchor="middle" fill="#1e293b">DIAGRAM LAPANGAN BULU TANGKIS STANDAR BWF</text>'
                . '<rect x="80" y="80" width="460" height="230" fill="#059669" stroke="#ffffff" stroke-width="3"/>'
                . '<line x1="310" y1="80" x2="310" y2="310" stroke="#ef4444" stroke-width="4"/>'
                . '<line x1="250" y1="80" x2="250" y2="310" stroke="#ffffff" stroke-width="2"/>'
                . '<line x1="370" y1="80" x2="370" y2="310" stroke="#ffffff" stroke-width="2"/>'
                . '<line x1="80" y1="195" x2="250" y2="195" stroke="#ffffff" stroke-width="2"/>'
                . '<line x1="370" y1="195" x2="540" y2="195" stroke="#ffffff" stroke-width="2"/>'
                . '<text x="310" y="355" font-size="12" font-weight="bold" text-anchor="middle" fill="#0f172a">Panjang: 13,40 M | Lebar Ganda: 6,10 M | Lebar Tunggal: 5,18 M</text>'
                . '</svg>';
        }

        // 5. Matematika: Bangun Ruang Kubus
        if (strpos($clean, 'kubus') !== false || strpos($clean, 'cube') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 540 360" font-family="Arial, sans-serif">'
                . '<rect width="540" height="360" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="510" height="330" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="270" y="45" font-size="16" font-weight="bold" text-anchor="middle" fill="#1e293b">BANGUN RUANG: KUBUS</text>'
                . '<path d="M 160 140 L 320 140 L 320 300 L 160 300 Z" fill="#93c5fd" fill-opacity="0.35" stroke="#1d4ed8" stroke-width="2.5"/>'
                . '<path d="M 230 70 L 390 70 L 390 230 L 230 230 Z" fill="none" stroke="#1d4ed8" stroke-width="2.5" stroke-dasharray="6,4"/>'
                . '<line x1="160" y1="140" x2="230" y2="70" stroke="#1d4ed8" stroke-width="2.5"/>'
                . '<line x1="320" y1="140" x2="390" y2="70" stroke="#1d4ed8" stroke-width="2.5"/>'
                . '<line x1="320" y1="300" x2="390" y2="230" stroke="#1d4ed8" stroke-width="2.5"/>'
                . '<line x1="160" y1="300" x2="230" y2="230" stroke="#1d4ed8" stroke-width="2.5" stroke-dasharray="6,4"/>'
                . '<text x="240" y="325" font-size="14" font-weight="bold" text-anchor="middle" fill="#0f172a">s (sisi/rusuk)</text>'
                . '<text x="135" y="225" font-size="14" font-weight="bold" text-anchor="middle" fill="#0f172a">s</text>'
                . '<text x="375" y="180" font-size="14" font-weight="bold" text-anchor="middle" fill="#0f172a">s</text>'
                . '<text x="270" y="340" font-size="12" fill="#475569" text-anchor="middle">Volume = s × s × s = s³ | Luas Permukaan = 6 × s²</text>'
                . '</svg>';
        }

        // 6. Matematika: Bangun Ruang Balok
        if (strpos($clean, 'balok') !== false || strpos($clean, 'cuboid') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 560 360" font-family="Arial, sans-serif">'
                . '<rect width="560" height="360" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="530" height="330" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="280" y="45" font-size="16" font-weight="bold" text-anchor="middle" fill="#1e293b">BANGUN RUANG: BALOK</text>'
                . '<rect x="120" y="150" width="260" height="130" fill="#a7f3d0" fill-opacity="0.4" stroke="#047857" stroke-width="2.5"/>'
                . '<line x1="120" y1="150" x2="200" y2="90" stroke="#047857" stroke-width="2.5"/>'
                . '<line x1="380" y1="150" x2="460" y2="90" stroke="#047857" stroke-width="2.5"/>'
                . '<line x1="380" y1="280" x2="460" y2="220" stroke="#047857" stroke-width="2.5"/>'
                . '<line x1="200" y1="90" x2="460" y2="90" stroke="#047857" stroke-width="2.5"/>'
                . '<line x1="460" y1="90" x2="460" y2="220" stroke="#047857" stroke-width="2.5"/>'
                . '<line x1="120" y1="280" x2="200" y2="220" stroke="#047857" stroke-width="2" stroke-dasharray="6,4"/>'
                . '<line x1="200" y1="220" x2="460" y2="220" stroke="#047857" stroke-width="2" stroke-dasharray="6,4"/>'
                . '<line x1="200" y1="90" x2="200" y2="220" stroke="#047857" stroke-width="2" stroke-dasharray="6,4"/>'
                . '<text x="250" y="305" font-size="14" font-weight="bold" text-anchor="middle" fill="#0f172a">Panjang (p)</text>'
                . '<text x="95" y="220" font-size="14" font-weight="bold" text-anchor="middle" fill="#0f172a">Tinggi (t)</text>'
                . '<text x="435" y="145" font-size="14" font-weight="bold" text-anchor="middle" fill="#0f172a">Lebar (l)</text>'
                . '<text x="280" y="340" font-size="12" fill="#475569" text-anchor="middle">Volume = p × l × t | Luas Permukaan = 2 × (pl + pt + lt)</text>'
                . '</svg>';
        }

        // 7. Matematika: Tabung (Silinder)
        if (strpos($clean, 'tabung') !== false || strpos($clean, 'silinder') !== false || strpos($clean, 'cylinder') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 540 360" font-family="Arial, sans-serif">'
                . '<rect width="540" height="360" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="510" height="330" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="270" y="45" font-size="16" font-weight="bold" text-anchor="middle" fill="#1e293b">BANGUN RUANG: TABUNG</text>'
                . '<ellipse cx="270" cy="110" rx="100" ry="30" fill="#fed7aa" fill-opacity="0.5" stroke="#ea580c" stroke-width="2.5"/>'
                . '<path d="M 170 110 L 170 250 A 100 30 0 0 0 370 250 L 370 110 Z" fill="#fed7aa" fill-opacity="0.3" stroke="none"/>'
                . '<line x1="170" y1="110" x2="170" y2="250" stroke="#ea580c" stroke-width="2.5"/>'
                . '<line x1="370" y1="110" x2="370" y2="250" stroke="#ea580c" stroke-width="2.5"/>'
                . '<path d="M 170 250 A 100 30 0 0 0 370 250" fill="none" stroke="#ea580c" stroke-width="2.5"/>'
                . '<path d="M 170 250 A 100 30 0 0 1 370 250" fill="none" stroke="#ea580c" stroke-width="2" stroke-dasharray="6,4"/>'
                . '<line x1="270" y1="110" x2="370" y2="110" stroke="#0f172a" stroke-width="2"/>'
                . '<text x="320" y="100" font-size="13" font-weight="bold" fill="#0f172a">r (jari-jari)</text>'
                . '<line x1="140" y1="110" x2="140" y2="250" stroke="#0f172a" stroke-width="1.8"/>'
                . '<text x="120" y="185" font-size="13" font-weight="bold" text-anchor="middle" fill="#0f172a">t</text>'
                . '<text x="270" y="325" font-size="12" fill="#475569" text-anchor="middle">Volume = π × r² × t | Luas Selimut = 2 × π × r × t</text>'
                . '</svg>';
        }

        // 8. Matematika: Koordinat Kartesius
        if (strpos($clean, 'kartesius') !== false || strpos($clean, 'koordinat') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 540 360" font-family="Arial, sans-serif">'
                . '<rect width="540" height="360" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="510" height="330" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="270" y="42" font-size="15" font-weight="bold" text-anchor="middle" fill="#1e293b">SISTEM KOORDINAT KARTESIUS</text>'
                . '<g stroke="#e2e8f0" stroke-width="1">'
                . '<line x1="70" y1="100" x2="470" y2="100"/><line x1="70" y1="150" x2="470" y2="150"/><line x1="70" y1="250" x2="470" y2="250"/><line x1="70" y1="300" x2="470" y2="300"/>'
                . '<line x1="120" y1="60" x2="120" y2="320"/><line x1="170" y1="60" x2="170" y2="320"/><line x1="220" y1="60" x2="220" y2="320"/>'
                . '<line x1="320" y1="60" x2="320" y2="320"/><line x1="370" y1="60" x2="370" y2="320"/><line x1="420" y1="60" x2="420" y2="320"/>'
                . '</g>'
                . '<line x1="70" y1="200" x2="470" y2="200" stroke="#0f172a" stroke-width="2.5"/>'
                . '<line x1="270" y1="60" x2="270" y2="320" stroke="#0f172a" stroke-width="2.5"/>'
                . '<text x="480" y="205" font-size="14" font-weight="bold" fill="#0f172a">X</text>'
                . '<text x="270" y="55" font-size="14" font-weight="bold" text-anchor="middle" fill="#0f172a">Y</text>'
                . '<circle cx="370" cy="150" r="6" fill="#dc2626"/>'
                . '<text x="380" y="145" font-size="12" font-weight="bold" fill="#dc2626">Titik A (2, 1)</text>'
                . '<circle cx="170" cy="250" r="6" fill="#2563eb"/>'
                . '<text x="110" y="265" font-size="12" font-weight="bold" fill="#2563eb">Titik B (-2, -1)</text>'
                . '</svg>';
        }

        // 9. IPA: Siklus Air (Daur Air)
        if (strpos($clean, 'siklus air') !== false || strpos($clean, 'daur air') !== false || strpos($clean, 'water cycle') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 370" font-family="Arial, sans-serif">'
                . '<rect width="600" height="370" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="570" height="340" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="300" y="42" font-size="15" font-weight="bold" text-anchor="middle" fill="#1e293b">SKEMA SIKLUS AIR (DAUR HIDROLOGI)</text>'
                . '<circle cx="80" cy="90" r="35" fill="#f59e0b"/>'
                . '<rect x="230" y="70" width="140" height="40" rx="20" fill="#94a3b8" fill-opacity="0.7"/>'
                . '<text x="300" y="95" font-size="12" font-weight="bold" text-anchor="middle" fill="#0f172a">2. Kondensasi (Awan)</text>'
                . '<rect x="420" y="160" width="130" height="35" rx="6" fill="#60a5fa" fill-opacity="0.3" stroke="#2563eb"/>'
                . '<text x="485" y="182" font-size="12" font-weight="bold" text-anchor="middle" fill="#1d4ed8">3. Presipitasi (Hujan)</text>'
                . '<path d="M 40 320 Q 200 260 380 290 Q 480 310 560 320 L 560 340 L 40 340 Z" fill="#0284c7"/>'
                . '<text x="300" y="325" font-size="13" font-weight="bold" text-anchor="middle" fill="#ffffff">Laut / Danau / Sungai</text>'
                . '<rect x="60" y="200" width="125" height="35" rx="6" fill="#fef08a" stroke="#ca8a04"/>'
                . '<text x="122" y="222" font-size="12" font-weight="bold" text-anchor="middle" fill="#854d0e">1. Evaporasi (Uap)</text>'
                . '<text x="300" y="275" font-size="12" font-weight="bold" fill="#0f172a">4. Infiltrasi (Penyerapan Tanah)</text>'
                . '</svg>';
        }

        // 10. IPA: Sistem Organ Pencernaan / Pernapasan (Bagan Alir Edukasi)
        if (strpos($clean, 'pencernaan') !== false || strpos($clean, 'lambung') !== false || strpos($clean, 'usus') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 360" font-family="Arial, sans-serif">'
                . '<rect width="600" height="360" fill="#f8fafc"/>'
                . '<rect x="15" y="15" width="570" height="330" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
                . '<text x="300" y="42" font-size="15" font-weight="bold" text-anchor="middle" fill="#1e293b">BAGAN URUTAN SISTEM PENCERNAAN MANUSIA</text>'
                . '<g font-size="13" font-weight="bold" text-anchor="middle">'
                . '<rect x="40" y="80" width="130" height="50" rx="8" fill="#dbeafe" stroke="#2563eb" stroke-width="2"/><text x="105" y="110" fill="#1e40af">1. Mulut</text>'
                . '<rect x="235" y="80" width="130" height="50" rx="8" fill="#dbeafe" stroke="#2563eb" stroke-width="2"/><text x="300" y="110" fill="#1e40af">2. Kerongkongan</text>'
                . '<rect x="430" y="80" width="130" height="50" rx="8" fill="#fef3c7" stroke="#d97706" stroke-width="2"/><text x="495" y="110" fill="#92400e">3. Lambung</text>'
                . '<rect x="430" y="200" width="130" height="50" rx="8" fill="#fef3c7" stroke="#d97706" stroke-width="2"/><text x="495" y="230" fill="#92400e">4. Usus Halus</text>'
                . '<rect x="235" y="200" width="130" height="50" rx="8" fill="#dcfce7" stroke="#16a34a" stroke-width="2"/><text x="300" y="230" fill="#166534">5. Usus Besar</text>'
                . '<rect x="40" y="200" width="130" height="50" rx="8" fill="#dcfce7" stroke="#16a34a" stroke-width="2"/><text x="105" y="230" fill="#166534">6. Anus</text>'
                . '</g>'
                . '<defs><marker id="arrS" markerWidth="8" markerHeight="6" refX="4" refY="3" orient="auto"><polygon points="0 0, 8 3, 0 6" fill="#475569"/></marker></defs>'
                . '<line x1="170" y1="105" x2="235" y2="105" stroke="#475569" stroke-width="2" marker-end="url(#arrS)"/>'
                . '<line x1="365" y1="105" x2="430" y2="105" stroke="#475569" stroke-width="2" marker-end="url(#arrS)"/>'
                . '<line x1="495" y1="130" x2="495" y2="200" stroke="#475569" stroke-width="2" marker-end="url(#arrS)"/>'
                . '<line x1="430" y1="225" x2="365" y2="225" stroke="#475569" stroke-width="2" marker-end="url(#arrS)"/>'
                . '<line x1="235" y1="225" x2="170" y2="225" stroke="#475569" stroke-width="2" marker-end="url(#arrS)"/>'
                . '<text x="300" y="310" font-size="12" fill="#64748b" text-anchor="middle">Proses pencernaan mekanik dan kimiawi berlangsung secara berurutan.</text>'
                . '</svg>';
        }

        // 11. Lingkungan / Poster / Pohon / Reboisasi / Sampah
        if (strpos($clean, 'poster') !== false || strpos($clean, 'pohon') !== false || strpos($clean, 'lingkungan') !== false || strpos($clean, 'menanam') !== false || strpos($clean, 'reboisasi') !== false) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 560 380" font-family="Arial, sans-serif">'
                . '<rect width="560" height="380" fill="#f0fdf4"/>'
                . '<rect x="15" y="15" width="530" height="350" rx="10" fill="#ffffff" stroke="#16a34a" stroke-width="3"/>'
                . '<rect x="35" y="30" width="490" height="46" rx="6" fill="#15803d"/>'
                . '<text x="280" y="60" font-size="17" font-weight="bold" text-anchor="middle" fill="#ffffff" letter-spacing="1">POSTER AJAKAN MENANAM POHON</text>'
                . '<!-- Pohon Rindang -->'
                . '<rect x="265" y="170" width="30" height="110" fill="#78350f" rx="3"/>'
                . '<circle cx="280" cy="150" r="65" fill="#22c55e" fill-opacity="0.95"/>'
                . '<circle cx="230" cy="165" r="50" fill="#16a34a" fill-opacity="0.95"/>'
                . '<circle cx="330" cy="165" r="50" fill="#15803d" fill-opacity="0.95"/>'
                . '<circle cx="280" cy="115" r="45" fill="#4ade80" fill-opacity="0.95"/>'
                . '<!-- Slogan Badges -->'
                . '<rect x="40" y="100" width="140" height="32" rx="16" fill="#dcfce7" stroke="#16a34a" stroke-width="1.5"/>'
                . '<text x="110" y="121" font-size="11" font-weight="bold" fill="#14532d" text-anchor="middle">Oksigen Bersih</text>'
                . '<rect x="40" y="145" width="140" height="32" rx="16" fill="#dcfce7" stroke="#16a34a" stroke-width="1.5"/>'
                . '<text x="110" y="166" font-size="11" font-weight="bold" fill="#14532d" text-anchor="middle">Cegah Banjir</text>'
                . '<rect x="380" y="100" width="140" height="32" rx="16" fill="#dcfce7" stroke="#16a34a" stroke-width="1.5"/>'
                . '<text x="450" y="121" font-size="11" font-weight="bold" fill="#14532d" text-anchor="middle">Cegah Longsor</text>'
                . '<rect x="380" y="145" width="140" height="32" rx="16" fill="#dcfce7" stroke="#16a34a" stroke-width="1.5"/>'
                . '<text x="450" y="166" font-size="11" font-weight="bold" fill="#14532d" text-anchor="middle">Bumi Lebih Sejuk</text>'
                . '<!-- Tanah & Rumput -->'
                . '<path d="M 40 280 Q 280 260 520 280 L 520 295 L 40 295 Z" fill="#84cc16"/>'
                . '<!-- Call To Action -->'
                . '<rect x="60" y="310" width="440" height="38" rx="6" fill="#fef08a" stroke="#ca8a04" stroke-width="2"/>'
                . '<text x="280" y="334" font-size="13" font-weight="bold" text-anchor="middle" fill="#854d0e">"Satu Pohon Hari Ini, Berjuta Nafas Untuk Masa Depan"</text>'
                . '</svg>';
        }

        // 12. Generic Card Diagram Pendidikan (Fallback Bersih)
        $title = htmlspecialchars(mb_strtoupper(mb_substr($clean, 0, 48), 'UTF-8'));
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 540 340" font-family="Arial, sans-serif">'
            . '<rect width="540" height="340" fill="#f8fafc"/>'
            . '<rect x="15" y="15" width="510" height="310" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>'
            . '<rect x="30" y="30" width="480" height="42" rx="6" fill="#f1f5f9"/>'
            . '<text x="270" y="56" font-size="14" font-weight="bold" text-anchor="middle" fill="#1e293b">DIAGRAM EDUKASI: ' . $title . '</text>'
            . '<rect x="50" y="95" width="440" height="180" rx="6" fill="#f8fafc" stroke="#94a3b8" stroke-dasharray="6,4"/>'
            . '<circle cx="270" cy="185" r="50" fill="#dbeafe" stroke="#2563eb" stroke-width="2"/>'
            . '<text x="270" y="190" font-size="13" font-weight="bold" text-anchor="middle" fill="#1d4ed8">Stimulus Soal</text>'
            . '<text x="270" y="305" font-size="12" fill="#64748b" text-anchor="middle">Gunakan data visual pada diagram di atas untuk menjawab pertanyaan.</text>'
            . '</svg>';
    }
}

if (!function_exists('ai_generate_diagram_svg')) {
    // Meminta AI Gemini menyusun ilustrasi/diagram edukatif presisi dalam format XML SVG murni
    function ai_generate_diagram_svg(string $provider, string $api_key, string $model, string $desc, string $materi = ''): string {
        if ($api_key === '') {
            return ai_builtin_diagram_svg($desc);
        }

        $prompt = "Buatkan ilustrasi atau diagram edukasi dalam format stand-alone valid SVG untuk stimulus naskah soal madrasah berikut:\n"
            . "\"$desc\"\n"
            . ($materi !== '' ? "Konteks Materi: $materi\n" : "")
            . "\nKetentuan pembuatan SVG:\n"
            . "1. Keluarkan HANYA kode XML SVG utuh (diawali <svg dan diakhiri </svg>) tanpa penjelasan teks dan tanpa markdown backtick.\n"
            . "2. Gunakan atribut viewBox=\"0 0 600 360\" dengan latar belakang putih/terang yang elegan dan bersih.\n"
            . "3. Sajikan diagram secara terukur dan presisi (misal garis lapangan olahraga dengan ukuran resmi, bangun ruang geometri bergaris dimensi, bagan organ dengan panah label nama, grafik berangka).\n"
            . "4. Gunakan font sans-serif bersih, ukuran font 12-16px dengan warna kontras tajam agar mudah dibaca oleh siswa madrasah.\n"
            . "5. Pastikan semua elemen visual relevan dan matematis/ilmiah tepat.";

        $candidates = [];
        $want = ai_normalize_gemini_model($model);
        if ($want !== '') $candidates[] = $want;

        $listed = ai_gemini_list_models($api_key);
        foreach ($listed as $m) {
            if (!in_array($m, $candidates, true)) $candidates[] = $m;
        }
        foreach (['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-flash-lite-latest', 'gemini-1.5-flash'] as $std) {
            if (!in_array($std, $candidates, true)) $candidates[] = $std;
        }

        foreach ($candidates as $try) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($try) . ':generateContent?key=' . urlencode($api_key);
            $payload = [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 8192
                ]
            ];
            [$code, $body] = ai_http_post_json($url, $payload, [], 35);
            if ($code >= 200 && $code < 300) {
                $j = json_decode($body, true);
                $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if (preg_match('/<svg[\s\S]*?<\/svg>/i', $text, $m_svg)) {
                    return $m_svg[0];
                }
            }
        }

        return ai_builtin_diagram_svg($desc);
    }
}

if (!function_exists('ai_resolve_image_file')) {
    // Menghasilkan diagram/ilustrasi edukasi mandiri berbasis SVG (tanpa ketergantungan web luar/scraping).
    // Prioritas: SVG Cache lokal -> Gemini SVG Generator -> Fallback Vektor Edukasi Bawaan.
    function ai_resolve_image_file(string $description, ?PDO $pdo = null, int $guru_id = 0): array {
        $desc = trim($description);
        if ($desc === '') return ['', ''];

        $hash = md5($desc);
        $dir = __DIR__ . '/../uploads/ai_images';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // 1. Cek apakah file SVG sudah pernah dibuat sebelumnya
        $svgPath = $dir . '/img_' . $hash . '.svg';
        if (is_file($svgPath) && filesize($svgPath) > 80) {
            return [$svgPath, (string)file_get_contents($svgPath)];
        }

        // 2. Cek apakah ada file JPG/PNG lokal sebelumnya
        $jpgPath = $dir . '/img_' . $hash . '.jpg';
        if (is_file($jpgPath) && filesize($jpgPath) > 2000) {
            return [$jpgPath, (string)file_get_contents($jpgPath)];
        }

        // 3. Buat ilustrasi diagram baru secara mandiri via Gemini atau engine bawaan
        $svgContent = '';
        if ($pdo instanceof PDO && $guru_id > 0) {
            $cfg = ai_guru_config($pdo, $guru_id);
            if (!empty($cfg['gemini_key'])) {
                $svgContent = ai_generate_diagram_svg($cfg['provider'], $cfg['gemini_key'], $cfg['gemini_model'], $desc);
            }
        } elseif (isset($GLOBALS['pdo']) && !empty($_SESSION['user_id'])) {
            $cfg = ai_guru_config($GLOBALS['pdo'], (int)$_SESSION['user_id']);
            if (!empty($cfg['gemini_key'])) {
                $svgContent = ai_generate_diagram_svg($cfg['provider'], $cfg['gemini_key'], $cfg['gemini_model'], $desc);
            }
        }

        // 4. Jika generator AI belum menghasilkan, gunakan engine vektor bawaan
        if ($svgContent === '' || stripos($svgContent, '<svg') === false) {
            $svgContent = ai_builtin_diagram_svg($desc);
        }

        // Simpan file SVG mandiri
        @file_put_contents($svgPath, $svgContent);
        return [$svgPath, $svgContent];
    }
}

if (!function_exists('ai_format_perangkat_html')) {
    // Format teks isi_dokumen perangkat menjadi HTML terstruktur rapi (heading, sub-heading, tabel pipe, box gambar)
    function ai_format_perangkat_html(string $raw, bool $is_pdf = false): string {
        $raw = trim($raw);
        if ($raw === '') return '(kosong)';

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $out = [];
        $table_buffer = [];

        $flush_table = function() use (&$out, &$table_buffer, $is_pdf) {
            if (empty($table_buffer)) return;
            $max_c = 0;
            $clean_rows = [];
            foreach ($table_buffer as $r) {
                $cols = array_map('trim', explode('|', $r));
                if (isset($cols[0]) && $cols[0] === '') array_shift($cols);
                if (isset($cols[count($cols)-1]) && $cols[count($cols)-1] === '') array_pop($cols);
                if (empty($cols)) continue;
                $is_sep = true;
                foreach ($cols as $c) {
                    if (!preg_match('/^:?-+:?$/', $c)) { $is_sep = false; break; }
                }
                if ($is_sep) continue;
                if (count($cols) > $max_c) $max_c = count($cols);
                $clean_rows[] = $cols;
            }

            if (empty($clean_rows)) {
                $table_buffer = [];
                return;
            }

            $fsize = $is_pdf ? ($max_c > 7 ? '7.5pt' : '8.5pt') : ($max_c > 7 ? '11px' : '13px');
            $pad   = $is_pdf ? ($max_c > 7 ? '3px 4px' : '4px 6px') : ($max_c > 7 ? '5px 7px' : '8px 10px');
            $wrap_open = $is_pdf ? '' : '<div style="width:100%;overflow-x:auto;margin:12px 0;">';
            $wrap_close = $is_pdf ? '' : '</div>';

            $table_style = 'width: 100%; border-collapse: collapse; margin: 6px 0; font-size: ' . $fsize . '; table-layout: auto; word-wrap: break-word;';
            $t = $wrap_open . '<table style="' . $table_style . '">';
            $first = true;
            foreach ($clean_rows as $cols) {
                $tag = $first ? 'th' : 'td';
                $th_style = $is_pdf
                    ? 'border: 1px solid #555; background: #e2e8f0; font-weight: bold; padding: ' . $pad . '; text-align: center;'
                    : 'border: 1px solid #cbd5e1; background: #f1f5f9; font-weight: 700; padding: ' . $pad . '; color: #0f172a; text-align: center;';
                $td_style = $is_pdf
                    ? 'border: 1px solid #555; background: #fff; padding: ' . $pad . ';'
                    : 'border: 1px solid #cbd5e1; background: #fff; padding: ' . $pad . '; color: #1e293b;';
                $style = $first ? $th_style : $td_style;

                $t .= '<tr>';
                foreach ($cols as $ci => $c) {
                    $align = ($first || $ci === 0 || preg_match('/^\d+(\s*JP)?$/i', $c) || $c === '-') ? 'text-align: center;' : 'text-align: left;';
                    $t .= '<' . $tag . ' style="' . $style . ' ' . $align . '">' . htmlspecialchars($c) . '</' . $tag . '>';
                }
                $t .= '</tr>';
                $first = false;
            }
            $t .= '</table>' . $wrap_close;
            $out[] = $t;
            $table_buffer = [];
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, '|') !== false && !preg_match('/^[A-Z]\./', $trimmed)) {
                $table_buffer[] = $trimmed;
                continue;
            } else {
                $flush_table();
            }

            if ($trimmed === '') {
                $out[] = '<div style="height: ' . ($is_pdf ? '6px' : '10px') . ';"></div>';
                continue;
            }

            // Real Image rendering
            if (preg_match('/^\[GAMBAR:\s*(.*?)\]$/i', $trimmed, $m)) {
                $desc = trim($m[1]);
                [$filePath, $imgData] = ai_resolve_image_file($desc);
                $imgHash = md5($desc);

                if ($is_pdf) {
                    if ($imgData !== '') {
                        $b64 = base64_encode($imgData);
                        $out[] = '<div style="text-align: center; margin: 12px 0;">'
                            . '<img src="data:image/jpeg;base64,' . $b64 . '" style="width: 480px; max-height: 280px; border-radius: 4px; border: 1px solid #777;">'
                            . '<div style="font-size: 8pt; color: #444; font-style: italic; margin-top: 4px;">Gambar: ' . htmlspecialchars($desc) . '</div>'
                            . '</div>';
                    } else {
                        $out[] = '<div style="border: 1px solid #d97706; background: #fffbeb; padding: 6px 10px; margin: 8px 0; font-size: 8.5pt; color: #92400e;"><strong>[GAMBAR: ' . htmlspecialchars($desc) . ']</strong></div>';
                    }
                } else {
                    $pollUrl = 'https://image.pollinations.ai/prompt/' . rawurlencode($desc) . '?width=650&height=380&nologo=true';
                    $localUrl = '../uploads/ai_images/img_' . $imgHash . '.jpg';
                    $src = is_file($filePath) ? $localUrl : $pollUrl;

                    $out[] = '<div class="my-3 text-center p-2 bg-white rounded border" style="max-width: 650px; margin-left: auto; margin-right: auto; box-shadow: 0 2px 6px rgba(0,0,0,0.08);">'
                        . '<img src="' . $src . '" alt="' . htmlspecialchars($desc) . '" style="max-width: 100%; height: auto; max-height: 360px; border-radius: 4px;" onerror="this.onerror=null;this.src=\'' . $pollUrl . '\';">'
                        . '<div class="mt-2 text-muted small font-italic"><i class="fas fa-image mr-1"></i>Gambar: ' . htmlspecialchars($desc) . '</div>'
                        . '</div>';
                }
                continue;
            }

            // Document main title
            if (preg_match('/^(MODUL AJAR|PROGRAM TAHUNAN|PROGRAM SEMESTER|ALUR TUJUAN|KRITERIA KETERCAPAIAN|RENCANA PELAKSANAAN|SILABUS|JURNAL MENGAJAR|PEMETAAN KOMPETENSI|LEMBAR KERJA|LKPD|PPT|SLIDE|PRESENTASI|POWERPOINT|MATERI KOKURIKULER|KOKURIKULER|PROJEK)/i', $trimmed)) {
                $title_style = $is_pdf
                    ? 'text-align: center; font-size: 14pt; font-weight: bold; margin: 10px 0 6px; color: #000; text-transform: uppercase;'
                    : 'text-align: center; font-size: 18px; font-weight: 800; margin: 16px 0 10px; color: #0f172a; letter-spacing: 0.5px; text-transform: uppercase;';
                $out[] = '<h3 style="' . $title_style . '">' . htmlspecialchars($trimmed) . '</h3>';
                continue;
            }

            // Major Section: A. IDENTITAS ..., B. CAPAIAN ..., etc.
            if (preg_match('/^([A-Z]\.\s+.*)$/', $trimmed, $m)) {
                $sec_style = $is_pdf
                    ? 'font-size: 11pt; font-weight: bold; color: #1e3a8a; border-bottom: 1px solid #93c5fd; padding-bottom: 2px; margin-top: 12px; margin-bottom: 4px; text-transform: uppercase;'
                    : 'font-size: 15px; font-weight: 800; color: #1e3a8a; border-bottom: 2px solid #bfdbfe; padding-bottom: 4px; margin-top: 22px; margin-bottom: 8px; text-transform: uppercase;';
                $out[] = '<h4 style="' . $sec_style . '">' . htmlspecialchars($m[1]) . '</h4>';
                continue;
            }

            // Sub section: 1. Pendahuluan:, 2. Inti:, etc.
            if (preg_match('/^(\d+\.\s+(Pendahuluan|Inti|Penutup|Kegiatan|Pertemuan|Asesmen|Refleksi|Pengayaan|Remedial).*?)$/i', $trimmed, $m)) {
                $subsec_style = $is_pdf
                    ? 'font-size: 10pt; font-weight: bold; color: #0f172a; margin-top: 8px; margin-bottom: 3px;'
                    : 'font-size: 14px; font-weight: 700; color: #0f172a; margin-top: 14px; margin-bottom: 4px;';
                $out[] = '<h5 style="' . $subsec_style . '">' . htmlspecialchars($m[1]) . '</h5>';
                continue;
            }

            // Normal text
            $p_style = $is_pdf
                ? 'margin-bottom: 4px; line-height: 1.45; font-size: 10pt; color: #111;'
                : 'margin-bottom: 8px; line-height: 1.7; font-size: 14px; color: #1e293b;';
            $out[] = '<p style="' . $p_style . '">' . nl2br(htmlspecialchars($trimmed)) . '</p>';
        }
        $flush_table();
        return implode("\n", $out);
    }
}

if (!function_exists('ai_build_perangkat_docx')) {
    // Bangun file Word (.docx) paket OpenXML lengkap (Word 2013-365 compliant, anti-corrupt).
    function ai_build_perangkat_docx(array $dok): string {
        $esc = function ($s) {
            $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s);
            return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        };
        $par = function ($text, $bold = false, $size = 22) use ($esc) {
            $b = $bold ? '<w:b/>' : '';
            return '<w:p><w:pPr><w:spacing w:after="120" w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr>' . $b . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">' . $esc($text) . '</w:t></w:r></w:p>';
        };

        $body = $par($dok['judul'] ?? 'Dokumen Perangkat', true, 28);
        $sub = trim((string)($dok['jenis_perangkat'] ?? ''));
        if (!empty($dok['mapel'])) $sub .= ' — ' . $dok['mapel'];
        if (!empty($dok['kelas'])) $sub .= ' (Kelas ' . $dok['kelas'] . ')';
        if ($sub !== '') {
            $body .= $par($sub, false, 22);
        }
        if (!empty($dok['guru'])) {
            $body .= $par('Guru: ' . $dok['guru'], false, 20);
        }

        foreach ([
            'Capaian Pembelajaran (CP)' => $dok['cp'] ?? '',
            'Tujuan Pembelajaran (TP)' => $dok['tp'] ?? '',
            'Materi Pembelajaran' => $dok['materi'] ?? '',
            'Tujuan Pembelajaran Khusus' => $dok['tujuan_pembelajaran'] ?? '',
            'Indikator Ketercapaian' => $dok['indikator'] ?? '',
            'Deskripsi Ringkas' => $dok['deskripsi'] ?? '',
        ] as $label => $val) {
            $val = trim((string)$val);
            if ($val === '' || $val === '-') continue;
            $body .= $par($label, true, 24);
            foreach (preg_split('/\r\n|\r|\n/', $val) as $ln) {
                if (trim($ln) !== '') $body .= $par(trim($ln));
            }
        }

        $body .= $par('ISI DOKUMEN LENGKAP', true, 24);
        $isi_raw = trim((string)($dok['isi_dokumen'] ?? ''));

        $docx_lines = preg_split('/\r\n|\r|\n/', $isi_raw !== '' ? $isi_raw : '(kosong)');
        $tbl_buf = [];

        $flush_docx_tbl = function() use (&$body, &$tbl_buf, $par) {
            if (empty($tbl_buf)) return;
            $max_c = 0;
            $rows_data = [];
            foreach ($tbl_buf as $r) {
                $cols = array_map('trim', explode('|', $r));
                if (isset($cols[0]) && $cols[0] === '') array_shift($cols);
                if (isset($cols[count($cols)-1]) && $cols[count($cols)-1] === '') array_pop($cols);
                if (empty($cols)) continue;
                $is_sep = true;
                foreach ($cols as $c) {
                    if (!preg_match('/^:?-+:?$/', $c)) { $is_sep = false; break; }
                }
                if ($is_sep) continue;
                if (count($cols) > $max_c) $max_c = count($cols);
                $rows_data[] = $cols;
            }

            if ($max_c > 0 && !empty($rows_data)) {
                $total_w = 16500; // total dxa width for Landscape F4
                $col_w = floor($total_w / $max_c);
                $fontSize = ($max_c > 7) ? 17 : 20; // 8.5pt or 10pt

                $tbl_xml = '<w:tbl>'
                    . '<w:tblPr>'
                    . '<w:tblW w:w="' . $total_w . '" w:type="dxa"/>'
                    . '<w:tblBorders>'
                    . '<w:top w:val="single" w:sz="6" w:space="0" w:color="777777"/>'
                    . '<w:left w:val="single" w:sz="6" w:space="0" w:color="777777"/>'
                    . '<w:bottom w:val="single" w:sz="6" w:space="0" w:color="777777"/>'
                    . '<w:right w:val="single" w:sz="6" w:space="0" w:color="777777"/>'
                    . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>'
                    . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>'
                    . '</w:tblBorders>'
                    . '</w:tblPr>'
                    . '<w:tblGrid>';
                for ($ci = 0; $ci < $max_c; $ci++) {
                    $tbl_xml .= '<w:gridCol w:w="' . $col_w . '"/>';
                }
                $tbl_xml .= '</w:tblGrid>';

                $first = true;
                foreach ($rows_data as $row_cols) {
                    $tbl_xml .= '<w:tr>';
                    for ($ci = 0; $ci < $max_c; $ci++) {
                        $val = $row_cols[$ci] ?? '';
                        $shd = $first ? '<w:shd w:val="clear" w:color="auto" w:fill="EEEEEE"/>' : '';
                        $tbl_xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $col_w . '" w:type="dxa"/>' . $shd . '</w:tcPr>'
                            . $par($val, $first, $fontSize)
                            . '</w:tc>';
                    }
                    $tbl_xml .= '</w:tr>';
                    $first = false;
                }
                $tbl_xml .= '</w:tbl>';
                $body .= $tbl_xml;
            }
            $tbl_buf = [];
        };

        $embedded_images = [];

        foreach ($docx_lines as $ln) {
            $trimmed = trim($ln);
            if (strpos($trimmed, '|') !== false && !preg_match('/^[A-Z]\./', $trimmed)) {
                $tbl_buf[] = $trimmed;
                continue;
            } else {
                $flush_docx_tbl();
            }

            if (preg_match('/^\[GAMBAR:\s*(.*?)\]$/i', $trimmed, $m)) {
                $desc = trim($m[1]);
                [$filePath, $imgData] = ai_resolve_image_file($desc);
                if ($imgData !== '') {
                    $imgIndex = count($embedded_images) + 1;
                    $rId = 'rIdImg' . $imgIndex;
                    $mediaName = 'media/image' . $imgIndex . '.jpeg';
                    $embedded_images[$mediaName] = [
                        'rid' => $rId,
                        'data' => $imgData,
                    ];

                    $body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="140" w:after="100"/></w:pPr><w:r>'
                        . '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
                        . '<wp:extent cx="5400000" cy="3150000"/>'
                        . '<wp:docPr id="' . $imgIndex . '" name="Picture ' . $imgIndex . '"/>'
                        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                        . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                        . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                        . '<pic:nvPicPr><pic:cNvPr id="0" name="Picture ' . $imgIndex . '"/><pic:cNvPicPr/></pic:nvPicPr>'
                        . '<pic:blipFill><a:blip r:embed="' . $rId . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="5400000" cy="3150000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
                        . '</pic:pic>'
                        . '</a:graphicData>'
                        . '</a:graphic>'
                        . '</wp:inline></w:drawing>'
                        . '</w:r></w:p>'
                        . '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="180"/></w:pPr><w:r><w:rPr><w:i/><w:sz w:val="18"/><w:szCs w:val="18"/><w:color w:val="666666"/></w:rPr><w:t>Gambar: ' . $esc($desc) . '</w:t></w:r></w:p>';
                } else {
                    $body .= $par('[GAMBAR: ' . $desc . ']');
                }
                continue;
            }

            if (preg_match('/^[A-Z]\.\s+/', $trimmed)) {
                $body .= $par($trimmed, true, 24);
            } elseif (preg_match('/^\d+\.\s+(Pendahuluan|Inti|Penutup|Kegiatan|Pertemuan|Asesmen|Refleksi|Pengayaan|Remedial)/i', $trimmed)) {
                $body .= $par($trimmed, true, 22);
            } else {
                $body .= $par($trimmed !== '' ? $ln : ' ');
            }
        }
        $flush_docx_tbl();

        $doc_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body>' . $body . '<w:sectPr><w:pgSz w:w="18709" w:h="12189" w:orient="landscape"/><w:pgMar w:top="1000" w:right="1000" w:bottom="1000" w:left="1000"/></w:sectPr></w:body></w:document>';

        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Default Extension="jpg" ContentType="image/jpeg"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';

        $word_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($embedded_images as $mediaPath => $im) {
            $word_rels .= '<Relationship Id="' . $im['rid'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="' . $mediaPath . '"/>';
        }
        $word_rels .= '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults>'
            . '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
            . '</w:docDefaults>'
            . '</w:styles>';

        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $esc($dok['judul'] ?? 'Perangkat') . '</dc:title>'
            . '<dc:creator>SIMAD</dc:creator>'
            . '<cp:lastModifiedBy>SIMAD</cp:lastModifiedBy>'
            . '</cp:coreProperties>';

        $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Application>Microsoft Office Word</Application>'
            . '</Properties>';

        $docx_files = [
            '[Content_Types].xml' => $types,
            '_rels/.rels' => $rels,
            'word/_rels/document.xml.rels' => $word_rels,
            'word/document.xml' => $doc_xml,
            'word/styles.xml' => $styles,
            'docProps/core.xml' => $core,
            'docProps/app.xml' => $app,
        ];
        foreach ($embedded_images as $mediaPath => $im) {
            $docx_files['word/' . $mediaPath] = $im['data'];
        }

        return ai_zip_stored($docx_files);
    }
}

if (!function_exists('ai_build_perangkat_xlsx')) {
    // Bangun file Excel (.xlsx) dengan Sheet Identitas & Sheet Matriks/Tabel utuh (Landscape F4).
    function ai_build_perangkat_xlsx(array $dok): string {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            throw new Exception('PhpSpreadsheet tidak tersedia.');
        }

        $ss = new PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Sheet 1: Identitas & Capaian
        $sh1 = $ss->getActiveSheet();
        $sh1->setTitle('Identitas');
        $sh1->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sh1->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_FOLIO);

        $sh1->fromArray(['Bagian', 'Isi Rincian'], null, 'A1');
        $sh1->getStyle('A1:B1')->getFont()->setBold(true);
        $sh1->getStyle('A1:B1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE2E8F0');

        $r = 2;
        foreach ([
            'Jenis Perangkat' => $dok['jenis_perangkat'] ?? '',
            'Judul' => $dok['judul'] ?? '',
            'Mata Pelajaran' => $dok['mapel'] ?? '',
            'Kelas' => $dok['kelas'] ?? '',
            'Semester' => $dok['semester'] ?? '',
            'Tahun Ajaran' => $dok['tahun_ajaran'] ?? '',
            'Guru Pengampu' => $dok['guru'] ?? '',
            'Topik / Materi Pokok' => $dok['topik'] ?? '',
            'Capaian Pembelajaran (CP)' => $dok['cp'] ?? '',
            'Tujuan Pembelajaran (TP)' => $dok['tp'] ?? '',
            'Materi Pembelajaran' => $dok['materi'] ?? '',
            'Tujuan Pembelajaran Khusus' => $dok['tujuan_pembelajaran'] ?? '',
            'Indikator Ketercapaian' => $dok['indikator'] ?? '',
            'Deskripsi Ringkas' => $dok['deskripsi'] ?? '',
        ] as $label => $val) {
            $val = trim((string)$val);
            if ($val === '' || $val === '-') continue;
            $sh1->fromArray([$label, $val], null, 'A' . $r);
            $sh1->getStyle('A' . $r)->getFont()->setBold(true);
            $r++;
        }
        $sh1->getColumnDimension('A')->setWidth(30);
        $sh1->getColumnDimension('B')->setWidth(100);

        // Sheet 2: Matriks & Isi Dokumen Lengkap
        $sh2 = $ss->createSheet();
        $sh2->setTitle('Matriks Dokumen');
        $sh2->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sh2->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_FOLIO);

        $lines = preg_split('/\r\n|\r|\n/', trim((string)($dok['isi_dokumen'] ?? '')));
        $row_idx = 1;
        $max_col_idx = 1;

        foreach ($lines as $ln) {
            $trimmed = trim($ln);
            if ($trimmed === '') {
                $row_idx++;
                continue;
            }

            // Pipe table row
            if (strpos($trimmed, '|') !== false && !preg_match('/^[A-Z]\./', $trimmed)) {
                $cols = array_map('trim', explode('|', $trimmed));
                if (isset($cols[0]) && $cols[0] === '') array_shift($cols);
                if (isset($cols[count($cols)-1]) && $cols[count($cols)-1] === '') array_pop($cols);
                if (empty($cols)) continue;

                $is_sep = true;
                foreach ($cols as $c) {
                    if (!preg_match('/^:?-+:?$/', $c)) { $is_sep = false; break; }
                }
                if ($is_sep) continue;

                if (count($cols) > $max_col_idx) $max_col_idx = count($cols);
                $sh2->fromArray($cols, null, 'A' . $row_idx);

                if (preg_match('/^(No|Bab|Materi|Tujuan|Minggu|Bulan|Pertemuan)/i', $cols[0])) {
                    $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cols));
                    $sh2->getStyle('A' . $row_idx . ':' . $lastColLetter . $row_idx)->getFont()->setBold(true);
                    $sh2->getStyle('A' . $row_idx . ':' . $lastColLetter . $row_idx)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFE2E8F0');
                }
                $row_idx++;
            } else {
                $sh2->setCellValue('A' . $row_idx, $trimmed);
                if (preg_match('/^[A-Z]\.\s+/', $trimmed) || preg_match('/^(PROGRAM|MODUL|ALUR|SILABUS|MATERI|KOKURIKULER|LEMBAR|LKPD|PPT|SLIDE)/i', $trimmed)) {
                    $sh2->getStyle('A' . $row_idx)->getFont()->setBold(true)->setSize(12);
                }
                $row_idx++;
            }
        }

        for ($ci = 1; $ci <= max($max_col_idx, 6); $ci++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci);
            $sh2->getColumnDimension($colLetter)->setAutoSize(true);
        }

        $ss->setActiveSheetIndex(count($lines) > 5 && $max_col_idx > 3 ? 1 : 0);

        ob_start();
        $w = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
        $w->save('php://output');
        return ob_get_clean();
    }
}

if (!function_exists('ai_generate_perangkat')) {
    // Panggil provider AI milik guru untuk menyusun dokumen perangkat pembelajaran.
    // Memakai mesin & kuota yang sama dengan generate soal (Flash dulu, Pro terakhir).
    function ai_generate_perangkat(string $provider, string $api_key, string $model, string $prompt, string $email = ''): array {
        if ($api_key === '') {
            $sys_key = trim((string)getenv('GEMINI_API_KEY'));
            if ($sys_key === '') { $sys_key = trim((string)getenv('GOOGLE_API_KEY')); }
            if ($sys_key !== '') {
                $api_key = $sys_key;
            }
        }
        if ($api_key === '') {
            return [false, 'Belum ada kunci AI yang bisa dipakai. Pilih salah satu: (1) isi email Kemenag saja — pastikan operator sudah memasang kunci server; atau (2) isi API key pribadi (gratis di aistudio.google.com).'];
        }
        if ($provider === 'openai') {
            $model = ai_openai_pick_model($api_key, $model);
            $url = 'https://api.openai.com/v1/chat/completions';
            [$code, $body] = ai_http_post_json($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Jawab hanya JSON valid sesuai skema yang diminta.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ], ['Authorization: Bearer ' . $api_key]);
            if ($code < 200 || $code >= 300) {
                return [false, 'OpenAI HTTP ' . $code . ' (model: ' . $model . '): ' . mb_substr($body, 0, 200)];
            }
            $j = json_decode($body, true);
            $text = $j['choices'][0]['message']['content'] ?? '';
            if ($text === '') {
                return [false, 'Respons OpenAI kosong.'];
            }
            return ai_parse_perangkat_json($text);
        }
        // Default: Gemini — Flash dulu (kuota besar), Pro terakhir (kuota kecil, cepat 429).
        $want = ai_normalize_gemini_model($model);
        $candidates = [];
        if ($want !== '') {
            $candidates[] = $want;
        }
        foreach (['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-1.5-flash'] as $fl) {
            if (!in_array($fl, $candidates, true)) {
                $candidates[] = $fl;
            }
        }
        $listed = ai_gemini_list_models($api_key);
        usort($listed, function ($a, $b) {
            $score = function ($n) {
                return stripos($n, 'flash') !== false ? 0 : 1;
            };
            return $score($a) <=> $score($b);
        });
        foreach ($listed as $m) {
            if (!in_array($m, $candidates, true)) {
                $candidates[] = $m;
            }
        }
        foreach (['gemini-1.5-pro', 'gemini-2.0-pro', 'gemini-pro-latest'] as $pro_m) {
            if (!in_array($pro_m, $candidates, true)) {
                $candidates[] = $pro_m;
            }
        }
        $last_err = 'Tidak ada model Gemini yang bisa dipakai.';
        $hit_429 = false;
        foreach ($candidates as $try) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($try) . ':generateContent?key=' . urlencode($api_key);
            $payload = [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.7, 'responseMimeType' => 'application/json'],
            ];
            [$code, $body, $cerr] = ai_http_post_json($url, $payload);
            if ($code === 0) {
                sleep(2);
                [$code, $body, $cerr] = ai_http_post_json($url, $payload, [], 150);
            }
            if (in_array($code, [500, 502, 503], true)) {
                sleep(3);
                [$code, $body, $cerr] = ai_http_post_json($url, $payload, [], 150);
            }
            if ($code === 429) {
                $hit_429 = true;
                $last_err = 'Kuota';
                sleep(2);
                continue;
            }
            if ($code >= 200 && $code < 300) {
                $j = json_decode($body, true);
                $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text === '') {
                    return [false, 'Respons Gemini kosong (model: ' . $try . ').'];
                }
                return ai_parse_perangkat_json($text);
            }
            if ($code === 0) {
                $why = $cerr !== '' ? $cerr : 'timeout/DNS/firewall server';
                $last_err = 'Koneksi ke Gemini gagal (' . $why . '). Coba lagi beberapa saat.';
                break;
            }
            if (in_array($code, [500, 502, 503], true)) {
                $last_err = 'Server Gemini sibuk (' . $code . ', model: ' . $try . '). Sudah dicoba model lain, silakan coba lagi beberapa saat.';
                continue;
            }
            if ($code === 400 && stripos($body, 'not supported') !== false) {
                sleep(1);
                continue;
            }
            $last_err = 'Gemini HTTP ' . $code . ' (model: ' . $try . '): ' . mb_substr($body, 0, 200);
            if ($code !== 404) {
                break;
            }
        }
        if ($hit_429 && ($last_err === 'Kuota' || stripos($last_err, 'Tidak ada model') !== false)) {
            return [false, 'Kuota gratis Gemini habis sementara (429). Tunggu sekitar 1 menit, lalu coba lagi. Model hemat kuota (Flash) sudah dicoba otomatis.'];
        }
        return [false, $last_err];
    }
}

if (!function_exists('ai_normalize_gemini_model')) {
    // Normalisasi nama model Gemini. Model lama 2.0-flash sudah pensiun -> anggap otomatis.
    function ai_normalize_gemini_model(string $m): string {
        $m = trim($m);
        $m = preg_replace('#^models/#i', '', $m);
        $retired = ['gemini-2.0-flash', 'gemini-2.0-flash-001', 'gemini-1.0-pro', 'gemini-pro'];
        if ($m === '' || in_array(strtolower($m), $retired, true)) {
            return '';
        }
        return $m;
    }
}

if (!function_exists('ai_gemini_list_models')) {
    // Ambil daftar model Gemini yang mendukung generateContent. Kembalikan array nama pendek.
    function ai_gemini_list_models(string $api_key): array {
        if ($api_key === '') {
            return [];
        }
        [$code, $body] = ai_http_get('https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($api_key), [], 30);
        if ($code < 200 || $code >= 300) {
            return [];
        }
        $j = json_decode($body, true);
        $list = is_array($j['models'] ?? null) ? $j['models'] : [];
        // Model yang tidak bisa output teks/JSON (audio, gambar, video, embedding) — wajib dibuang
        // karena generate soal memakai responseMimeType application/json (contoh: *-tts error 400).
        $non_teks = ['tts', 'text-to-speech', 'speech', 'imagen', 'image-generation', 'embedding', 'embed-', 'aqa', 'veo', 'robotics', 'dwelling'];
        $out = [];
        foreach ($list as $m) {
            $name = (string)($m['name'] ?? '');
            $name = preg_replace('#^models/#i', '', $name);
            if ($name === '') {
                continue;
            }
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (is_array($methods) && !in_array('generateContent', $methods, true)) {
                continue;
            }
            $nl = strtolower($name);
            $skip = false;
            foreach ($non_teks as $bad) {
                if (strpos($nl, $bad) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $out[] = $name;
        }
        // Prioritaskan flash terbaru agar murah & cepat.
        usort($out, function ($a, $b) {
            $score = function ($n) {
                $n = strtolower($n);
                if (strpos($n, '2.5-flash') !== false) {
                    return 0;
                }
                if (strpos($n, 'flash') !== false) {
                    return 1;
                }
                if (strpos($n, '2.5-pro') !== false) {
                    return 2;
                }
                if (strpos($n, 'pro') !== false) {
                    return 3;
                }
                return 4;
            };
            return $score($a) <=> $score($b);
        });
        return $out;
    }
}

if (!function_exists('ai_openai_pick_model')) {
    // Pilih model OpenAI otomatis dari /v1/models bila input kosong.
    function ai_openai_pick_model(string $api_key, string $preferred): string {
        $preferred = trim($preferred);
        if ($preferred !== '') {
            return $preferred;
        }
        [$code, $body] = ai_http_get('https://api.openai.com/v1/models', ['Authorization: Bearer ' . $api_key], 30);
        if ($code !== 200) {
            return 'gpt-4o-mini';
        }
        $j = json_decode($body, true);
        $ids = [];
        foreach ((array)($j['data'] ?? []) as $m) {
            if (!empty($m['id'])) {
                $ids[] = (string)$m['id'];
            }
        }
        foreach (['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-3.5-turbo'] as $fav) {
            foreach ($ids as $id) {
                if (stripos($id, $fav) !== false) {
                    return $id;
                }
            }
        }
        foreach ($ids as $id) {
            if (stripos($id, 'gpt') !== false) {
                return $id;
            }
        }
        return 'gpt-4o-mini';
    }
}

if (!function_exists('ai_generate_soal')) {
    // Panggil provider AI milik guru. Model kosong = serahkan ke provider (otomatis).
    function ai_generate_soal(string $provider, string $api_key, string $model, string $prompt, string $email = ''): array {
        if ($api_key === '') {
            $sys_key = trim((string)getenv('GEMINI_API_KEY'));
            if ($sys_key === '') { $sys_key = trim((string)getenv('GOOGLE_API_KEY')); }
            if ($sys_key !== '') {
                $api_key = $sys_key;
            }
        }
        if ($api_key === '') {
            return [false, 'Belum ada kunci AI yang bisa dipakai. Pilih salah satu: (1) isi email Kemenag saja — pastikan operator sudah memasang kunci server; atau (2) isi API key pribadi (gratis di aistudio.google.com).'];
        }
        if ($provider === 'openai') {
            $model = ai_openai_pick_model($api_key, $model);
            $url = 'https://api.openai.com/v1/chat/completions';
            [$code, $body] = ai_http_post_json($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Jawab hanya JSON valid sesuai skema yang diminta.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ], ['Authorization: Bearer ' . $api_key]);
            if ($code < 200 || $code >= 300) {
                return [false, 'OpenAI HTTP ' . $code . ' (model: ' . $model . '): ' . mb_substr($body, 0, 200)];
            }
            $j = json_decode($body, true);
            $text = $j['choices'][0]['message']['content'] ?? '';
            if ($text === '') {
                return [false, 'Respons OpenAI kosong.'];
            }
            return ai_parse_soal_json($text);
        }
        // Default: Gemini — model otomatis bila kosong / pensiun.
        // PENTING: model Flash didahulukan karena kuota gratisnya jauh lebih besar
        // (Flash ~15 request/menit) dibanding model Pro (~2 request/menit, cepat kena 429).
        // Default: Gemini — Mode Full Fallback Dinamis (Mendukung Akun Pro maupun Free)
        // Ambil daftar model aktual yang AKTIF dan TERSEDIA di akun guru dari API Gemini
        $listed = ai_gemini_list_models($api_key);

        $candidates = [];
        $want = ai_normalize_gemini_model($model);
        if ($want !== '') {
            $candidates[] = $want;
        }

        // Masukkan semua model yang resmi diizinkan akun pengguna ke daftar kerja
        foreach ($listed as $m) {
            if (!in_array($m, $candidates, true)) {
                $candidates[] = $m;
            }
        }

        // Fallback cadangan jika list model akun gagal diambil (koneksi lambat/filter API)
        $fallback_standard = [
            'gemini-2.5-flash',
            'gemini-2.5-pro',
            'gemini-2.0-flash',
            'gemini-1.5-flash',
            'gemini-1.5-pro',
            'gemini-flash-latest',
            'gemini-pro-latest'
        ];
        foreach ($fallback_standard as $std) {
            if (!in_array($std, $candidates, true)) {
                $candidates[] = $std;
            }
        }

        $last_err = 'Tidak ada model Gemini yang bisa dipakai.';
        $hit_429 = false;
        foreach ($candidates as $try) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($try) . ':generateContent?key=' . urlencode($api_key);
            $payload = [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'responseMimeType' => 'application/json',
                    'maxOutputTokens' => 65536,
                ],
            ];
            [$code, $body, $cerr] = ai_http_post_json($url, $payload, [], 180);
            // HTTP 0 = koneksi putus (timeout/DNS/firewall): retry sekali sebelum beralih
            if ($code === 0) {
                sleep(1);
                [$code, $body, $cerr] = ai_http_post_json($url, $payload, [], 240);
            }
            // Overload server (500/502/503): retry sekali
            if (in_array($code, [500, 502, 503], true)) {
                sleep(1);
                [$code, $body, $cerr] = ai_http_post_json($url, $payload, [], 240);
            }
            // Jika limit rate / kuota (429): jangan berhenti, otomatis lompat ke model fallback berikutnya
            if ($code === 429) {
                $hit_429 = true;
                $last_err = 'Batas laju pemanggilan API (429) tercapai pada model ' . $try . '. Beralih ke model lain...';
                continue;
            }
            // Jika model tidak ditemukan (404) atau tidak didukung (400): langsung coba model lain di list
            if ($code === 404 || ($code === 400 && (stripos($body, 'not supported') !== false || stripos($body, 'not found') !== false))) {
                continue;
            }
            if ($code >= 200 && $code < 300) {
                $j = json_decode($body, true);
                $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text === '') {
                    // Coba model berikutnya bila respons teks kosong
                    continue;
                }
                $parsed = ai_parse_soal_json($text);
                // Jika parsing JSON valid, return langsung
                if ($parsed[0]) {
                    return $parsed;
                }
                // Jika JSON dari model ini terpotong parah, coba model berikutnya
                $last_err = $parsed[1] ?? 'Format JSON tidak valid.';
                continue;
            }
            if ($code === 0) {
                $why = $cerr !== '' ? $cerr : 'timeout/DNS/firewall server';
                $last_err = 'Koneksi ke Gemini gagal (' . $why . '). Coba lagi beberapa saat.';
                continue;
            }
            if (in_array($code, [500, 502, 503], true)) {
                $last_err = 'Server Gemini sibuk (' . $code . ', model: ' . $try . '). Sedang dicoba model lain...';
                continue;
            }
            $last_err = 'Gemini HTTP ' . $code . ' (model: ' . $try . '): ' . mb_substr($body, 0, 200);
        }
        if ($hit_429 && ($last_err === 'Kuota' || stripos($last_err, 'Tidak ada model') !== false)) {
            return [false, 'Kuota gratis Gemini habis sementara (429). Tunggu sekitar 1 menit, lalu coba lagi dengan jumlah soal lebih sedikit (misal 5 butir dulu). Model hemat kuota (Flash) sudah dicoba otomatis. Cek kuota di https://aistudio.google.com/apikey bila sering terjadi.'];
        }
        return [false, $last_err];
    }
}

if (!function_exists('ai_http_get')) {
    // GET ringan via cURL (fallback stream). Kembalikan [http_code, body].
    function ai_http_get(string $url, array $headers = [], int $timeout = 30): array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$code, (string)$body];
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ((array)($http_response_header ?? []) as $h) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', (string)$h, $m)) {
                $code = (int)$m[1];
                break;
            }
        }
        return [$code, (string)$body];
    }
}

if (!function_exists('ai_test_connection')) {
    // Tes koneksi ringan per provider milik guru.
    function ai_test_connection(string $provider, string $api_key, string $model, string $email = ''): array {
        if ($api_key === '') {
            return [false, 'Belum ada kunci AI yang bisa dipakai. Pilih salah satu: (1) isi email Kemenag saja — pastikan operator sudah memasang kunci server; atau (2) isi API key pribadi (gratis di aistudio.google.com).'];
        }
        if ($provider === 'openai') {
            [$code, $body] = ai_http_get('https://api.openai.com/v1/models', ['Authorization: Bearer ' . $api_key], 30);
            if ($code === 200) {
                return [true, 'Koneksi OpenAI OK.'];
            }
            return [false, 'OpenAI HTTP ' . $code . ': ' . mb_substr(trim($body) !== '' ? $body : 'kunci tidak valid / tanpa akses model', 0, 150)];
        }
        $want = ai_normalize_gemini_model($model);
        $candidates = [];
        if ($want !== '') {
            $candidates[] = $want;
        }

        // Flash dulu (kuota gratis besar), Pro paling akhir (kuota kecil, cepat 429).
        foreach (['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-1.5-flash'] as $fl) {
            if (!in_array($fl, $candidates, true)) {
                $candidates[] = $fl;
            }
        }
        $listed = ai_gemini_list_models($api_key);
        usort($listed, function ($a, $b) {
            $score = function ($n) {
                return stripos($n, 'flash') !== false ? 0 : 1;
            };
            return $score($a) <=> $score($b);
        });
        foreach ($listed as $m) {
            if (!in_array($m, $candidates, true)) {
                $candidates[] = $m;
            }
        }
        foreach (['gemini-1.5-pro', 'gemini-2.0-pro', 'gemini-pro-latest'] as $pro_m) {
            if (!in_array($pro_m, $candidates, true)) {
                $candidates[] = $pro_m;
            }
        }
        $hit_429 = false;
        $hit_overload = false;
        foreach ($candidates as $try) {
            [$code, $body] = ai_http_post_json(
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($try) . ':generateContent?key=' . urlencode($api_key),
                ['contents' => [['parts' => [['text' => 'Balas tepat dengan kata: OK']]]]],
                [],
                30
            );
            if ($code === 200) {
                $akun_info = trim($email) !== '' ? ' (Akun: ' . $email . ')' : '';
                return [true, 'Koneksi Gemini OK' . $akun_info . ' (model: ' . $try . ').'];
            }
            if ($code === 429) {
                $hit_429 = true;
                sleep(1);
                continue; // coba model berikutnya, jangan berhenti di Pro yang kuotanya kecil
            }
            if (in_array($code, [500, 502, 503], true)) {
                $hit_overload = true;
                sleep(2);
                continue; // server sibuk sementara — coba model berikutnya
            }
            if ($code !== 404) {
                return [false, 'Gemini HTTP ' . $code . ' (model: ' . $try . '): ' . mb_substr($body, 0, 150)];
            }
        }
        if ($hit_429) {
            return [false, 'Kuota gratis Gemini habis sementara (429). Tunggu sekitar 1 menit lalu tekan Tes Koneksi lagi. Bila sering terjadi, buat API key baru di aistudio.google.com dengan akun Gmail berbeda.'];
        }
        if ($hit_overload) {
            return [false, 'Server Gemini sedang overload (503) — lonjakan pemakaian yang biasanya sementara. Tunggu 1–2 menit lalu tekan Tes Koneksi lagi. API key Anda valid, tidak perlu diganti.'];
        }
        return [false, 'Gemini HTTP 404: tidak ada model tersedia. Coba lagi nanti.'];
    }
}
