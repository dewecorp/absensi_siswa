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
            $lines[] = '- Instruksi / Perintah Tambahan Khusus: ' . trim((string)$in['instruksi_tambahan']) . ' (PENTING: Wajib dipatuhi dan diimplementasikan pada pembuatan butir soal/kisi-kisi terkait. Bila instruksi meminta gambar/ilustrasi pada butir tertentu, WAJIB menuliskan blok deskripsi gambar dengan format persis: [GAMBAR: uraian detail objek gambar agar guru dapat menggambar ulang/menempelkan gambar pada naskah cetak] tepat setelah kalimat pertanyaan butir tersebut, dan biarkan field "gambar" pada JSON tetap terisi teks uraian yang sama).';
        }
        $lines[] = '- Aturan gambar/ilustrasi: bila materi atau instruksi tambahan meminta gambar (misal diagram organ, peta, bangun datar/ruang, rangkaian listrik, grafik), WAJIB sisipkan blok [GAMBAR: ...] pada butir soal yang memerlukan gambar. Jangan menulis soal "perhatikan gambar berikut" tanpa blok [GAMBAR: ...]. Bila tidak memerlukan gambar, JANGAN menulis blok [GAMBAR: ...].';
        $lines[] = '- Tingkat kesulitan: ACAK dan MERATA untuk tiap bentuk soal (setiap bentuk wajib ada yang Mudah, Sedang, dan Sukar bila jumlah memungkinkan; bila jumlah < 3, variasikan sebisa mungkin). Tandai tiap butir pada field "kesulitan".';
        $lines[] = 'Level kognitif (WAJIB untuk tiap butir kisi-kisi dan soal, cantumkan field "level_kognitif" dengan nilai persis C1, C2, C3, atau C4 / L1-L4):';
        $lines[] = '- Kognitif C1 Knowledge (Mengingat): Pada level ini pelajar perlu mengingat istilah, fakta, & detail tanpa perlu memahami konsep materinya.';
        $lines[] = '- Kognitif C2 Comprehension (Memahami): Pada level ini pelajar perlu menyusun ringkasan & menjelaskan gagasan utama menggunakan kata-kata serta bahasanya sendiri tanpa menghubungkannya dengan pembahasan lainnya.';
        $lines[] = '- Kognitif C3 Application (Menerapkan): Pada level ini pelajar perlu mengaplikasikan atau menerapkan hasil belajar ke kehidupan sehari-hari maupun ke masalah dengan konteks berbeda dari contoh yang sudah pernah diberikan.';
        $lines[] = '- Kognitif C4 Analysis (Menganalisis): Pada level ini pelajar perlu melakukan analisis pemecahan masalah melalui tahap memisahkan bagian-bagian permasalahan, menguraikan pola permasalahan hingga menghubungkan sebab-akibat antara suatu materi terhadap bagian lainnya.';
        $lines[] = 'Sebarkan level kognitif (C1-C4) secara proporsional sesuai materi dan bentuk soal; tandai pada field "level_kognitif" (contoh: "C1", "C2", "C3", atau "C4").';
        $lines[] = 'Aturan per bentuk:';
        $lines[] = '- Pilihan Ganda: tiap butir tepat 4 opsi berlabel A-D dan satu kunci (A/B/C/D).';
        $lines[] = '- Pilihan Ganda Kompleks: tiap butir memiliki 4 opsi berlabel A-D, dan kunci jawaban WAJIB TEPAT 2 PILIHAN BENAR SAJA (contoh "A,C" atau "B,D"). Siswa memilih tepat 2 jawaban yang benar.';
        $lines[] = '- Menjodohkan: Pada bagian "soal", HANYA DIBUAT 1 BUTIR SOAL yang memuat 1 tabel 4 kolom ("no", "kiri", "huruf", "kanan") dengan tepat ' . $n_menjodohkan . ' baris pasangan. TETAPI pada bagian "kisi_kisi", WAJIB DIBUAT TEPAT ' . $n_menjodohkan . ' BARIS KISI-KISI terpisah (masing-masing nomor urut untuk tiap baris pasangan yang dijodohkan dengan indikator spesifik per baris pasangan).';
        $lines[] = '- Isian Singkat: kunci berupa jawaban singkat 1-5 kata.';
        $lines[] = '- Uraian: kunci berupa jawaban uraian 1-3 kalimat sebagai acuan penskoran.';
        $lines[] = 'Kisi-kisi WAJIB memuat kolom: no, materi, cp, tp, indikator, bentuk, level_kognitif (L1-L4), kesulitan, bobot.';
        $lines[] = 'CP = turunkan dari kurikulum: Permendikdasmen CP 046 (fase/kelas terkait) atau KMA 1503+KBC (madrasah). TP = jabaran operasional topik/sub-topik di atas (1-2 kalimat, diawali kata kerja operasional).';
        $lines[] = 'Jawab HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan di luar JSON) memakai skema persis ini:';
        $lines[] = '{"kisi_kisi":[{"no":1,"materi":"...","cp":"...","tp":"...","indikator":"...","bentuk":"Pilihan Ganda","level_kognitif":"L2","kesulitan":"Sedang","bobot":1}],"soal":[{"no":1,"bentuk":"Pilihan Ganda","pertanyaan":"...","gambar":"...","opsi":{"A":"...","B":"...","C":"...","D":"..."},"tabel":[],"kunci":"A","pembahasan":"...","cp":"...","tp":"...","indikator":"...","level_kognitif":"L2","kesulitan":"Sedang","bobot":1}]}';
        $lines[] = 'Field "gambar" pada tiap butir soal: isi dengan uraian detail objek gambar/ilustrasi yang harus ditempel guru bila butir tersebut memerlukan gambar (contoh: "Diagram sistem pencernaan manusia dengan label mulut, kerongkongan, lambung, usus halus, usus besar"); bila butir tidak memerlukan gambar, isi dengan string kosong "". Uraian yang sama WAJIB juga ditulis sebagai blok [GAMBAR: ...] di dalam teks "pertanyaan".';
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
        $lines[] = '- Lainnya: susun dokumen pembelajaran yang rapi sesuai topik (judul, tujuan, uraian materi, penutup).';
        $lines[] = 'INSTRUKSI VISUALISASI GAMBAR / ILUSTRASI:';
        $lines[] = 'Jika materi atau instruksi meminta gambar/ilustrasi (misal LKPD, PPT, Modul Ajar, diagram, denah, siklus, bangun ruang, adab, gotong royong, kegiatan kontekstual): WAJIB sertakan blok [GAMBAR: deskripsi visual yang jelas, spesifik, dan detail tentang objek gambar yang ditampilkan]. Sistem akan otomatis meng-generate dan menyematkan gambar nyata/foto/ilustrasi dari deskripsi tersebut ke dalam dokumen.';
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
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $t, $m)) {
            $t = $m[1];
        } elseif (($p1 = strpos($t, '{')) !== false && ($p2 = strrpos($t, '}')) !== false && $p2 > $p1) {
            $t = substr($t, $p1, $p2 - $p1 + 1);
        }
        $data = json_decode($t, true);
        if (!is_array($data) || empty($data['soal']) || !is_array($data['soal'])) {
            return [false, null, 'Respons AI bukan JSON soal yang valid.'];
        }
        if (empty($data['kisi_kisi']) || !is_array($data['kisi_kisi'])) {
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

if (!function_exists('ai_resolve_image_file')) {
    // Cari & unduh foto/diagram nyata edukasi dari ensiklopedia resmi (Wikipedia & Wikimedia Commons).
    // Bebas distorsi AI, tanpa watermark, dan 100% otentik sesuai materi kurikulum.
    function ai_resolve_image_file(string $description): array {
        $desc = trim($description);
        if ($desc === '') return ['', ''];

        $hash = md5($desc);
        $dir = __DIR__ . '/../uploads/ai_images';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $filePath = $dir . '/img_' . $hash . '.jpg';

        if (is_file($filePath) && filesize($filePath) > 2000) {
            return [$filePath, (string)file_get_contents($filePath)];
        }

        $clean = mb_strtolower($desc, 'UTF-8');

        // Pemetaan topik ensiklopedis materi kurikulum madrasah/sekolah ke artikel Wikipedia
        $prio_map = [
            'pencernaan' => 'Sistem_pencernaan_manusia',
            'lambung' => 'Lambung',
            'usus' => 'Usus_halus',
            'pernapasan' => 'Sistem_pernapasan',
            'pernafasan' => 'Sistem_pernapasan',
            'paru-paru' => 'Paru-paru',
            'peredaran darah' => 'Sistem_peredaran_darah',
            'jantung' => 'Jantung',
            'darah' => 'Darah',
            'rangka' => 'Kerangka_manusia',
            'tulang' => 'Kerangka_manusia',
            'otot' => 'Otot',
            'mata' => 'Mata',
            'telinga' => 'Telinga',
            'kulit' => 'Kulit',
            'hidung' => 'Hidung',
            'lidah' => 'Lidah',
            'fotosintesis' => 'Fotosintesis',
            'daur air' => 'Siklus_air',
            'siklus air' => 'Siklus_air',
            'hujan' => 'Hujan',
            'metamorfosis' => 'Metamorfosis',
            'kupu-kupu' => 'Kupu-kupu',
            'katak' => 'Katak',
            'rantai makanan' => 'Rantai_makanan',
            'jaring-jaring makanan' => 'Jaring-jaring_makanan',
            'ekosistem' => 'Ekosistem',
            'tata surya' => 'Tata_Surya',
            'planet' => 'Tata_Surya',
            'bumi' => 'Bumi',
            'bulan' => 'Bulan',
            'matahari' => 'Matahari',
            'gerhana' => 'Gerhana',
            'magnet' => 'Magnet',
            'listrik' => 'Sirkuit_listrik',
            'bunyi' => 'Bunyi',
            'cahaya' => 'Cahaya',
            'gaya' => 'Gaya_(fisika)',
            'energi' => 'Energi',
            'kalor' => 'Kalor',
            'gotong royong' => 'Gotong_royong',
            'kerja bakti' => 'Gotong_royong',
            'membersihkan' => 'Gotong_royong',
            'pancasila' => 'Garuda_Pancasila',
            'garuda' => 'Garuda_Pancasila',
            'sila' => 'Garuda_Pancasila',
            'sholat' => 'Salat',
            'salat' => 'Salat',
            'wudhu' => 'Wudu',
            'masjid' => 'Masjid',
            'madrasah' => 'Madrasah',
            'borobudur' => 'Borobudur',
            'prambanan' => 'Candi_Prambanan',
            'proklamasi' => 'Proklamasi_Kemerdekaan_Indonesia',
            'kemerdekaan' => 'Proklamasi_Kemerdekaan_Indonesia',
            'soekarno' => 'Soekarno',
            'peta indonesia' => 'Indonesia',
            'suku' => 'Suku_bangsa_di_Indonesia',
            'bhineka' => 'Bhinneka_Tunggal_Ika',
            'bhinneka' => 'Bhinneka_Tunggal_Ika',
            'norma' => 'Norma_sosial',
            'gunung' => 'Gunung_berapi',
            'laut' => 'Laut',
            'pohon' => 'Pohon',
        ];

        $matchedPage = '';
        foreach ($prio_map as $needle => $page) {
            if (strpos($clean, $needle) !== false) {
                $matchedPage = $page;
                break;
            }
        }

        $imgUrl = '';
        $ua = 'SIMAD-Madrasah/1.0 (admin@madrasah.sch.id)';

        if ($matchedPage !== '') {
            $summaryUrl = "https://id.wikipedia.org/api/rest_v1/page/summary/" . rawurlencode($matchedPage);
            $ch = curl_init($summaryUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $ua);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $imgUrl = $res['originalimage']['source'] ?? $res['thumbnail']['source'] ?? '';
        }

        if ($imgUrl === '') {
            // Pencarian cerdas di Wikipedia Indonesia dengan membuang kata struktur/deskripsi
            $stopwords = [
                'ilustrasi', 'gambar', 'foto', 'bagan', 'diagram', 'tabel', 'sebuah', 'suatu', 'tentang',
                'yang', 'dan', 'di', 'ke', 'dari', 'pada', 'untuk', 'dengan', 'adalah', 'sedang', 'sambil',
                'serta', 'oleh', 'berbagai', 'macam', 'jenis', 'sangat', 'secara', 'agar', 'bisa', 'dapat',
                'tersebut', 'mereka', 'kita', 'kami', 'anak-anak', 'peserta', 'didik', 'siswa', 'riang',
                'senang', 'gembira', 'tersenyum', 'susunan', 'organ', 'struktur', 'bagian', 'alat', 'sistem',
                'tahapan', 'langkah', 'cara', 'proses', 'hingga', 'sampai', 'mulai', 'berikut', 'ini'
            ];
            $cleanWords = preg_replace('/[^a-z0-9\s-]/u', ' ', $clean);
            $words = array_values(array_filter(explode(' ', $cleanWords), function($w) use ($stopwords) {
                return strlen($w) >= 3 && !in_array($w, $stopwords);
            }));
            $kw = !empty($words) ? implode(' ', array_slice($words, 0, 3)) : $desc;

            $searchUrl = "https://id.wikipedia.org/w/api.php?action=query&generator=search&gsrsearch=" . rawurlencode($kw) . "&gsrlimit=2&prop=pageimages&pithumbsize=900&format=json";
            $ch = curl_init($searchUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $ua);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (!empty($res['query']['pages'])) {
                foreach ($res['query']['pages'] as $p) {
                    if (!empty($p['thumbnail']['source'])) {
                        $imgUrl = $p['thumbnail']['source'];
                        break;
                    }
                }
            }
        }

        if ($imgUrl !== '') {
            $ch = curl_init($imgUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $ua);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $rawImg = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code == 200 && is_string($rawImg) && strlen($rawImg) > 2000) {
                // Resize gambar bila terlalu besar agar hemat memori & cepat dimuat
                if (extension_loaded('gd')) {
                    $srcImg = @imagecreatefromstring($rawImg);
                    if ($srcImg !== false) {
                        $w = imagesx($srcImg);
                        $h = imagesy($srcImg);
                        if ($w > 900) {
                            $nw = 900;
                            $nh = (int)floor($h * ($nw / $w));
                            $dstImg = imagecreatetruecolor($nw, $nh);
                            imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
                            imagedestroy($srcImg);
                            ob_start();
                            imagejpeg($dstImg, null, 85);
                            $rawImg = ob_get_clean();
                            imagedestroy($dstImg);
                        }
                    }
                }
                @file_put_contents($filePath, $rawImg);
                return [$filePath, $rawImg];
            }
        }

        return ['', ''];
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
            if (preg_match('/^(MODUL AJAR|PROGRAM TAHUNAN|PROGRAM SEMESTER|ALUR TUJUAN|KRITERIA KETERCAPAIAN|RENCANA PELAKSANAAN|SILABUS|JURNAL MENGAJAR|PEMETAAN KOMPETENSI|LEMBAR KERJA|LKPD|PPT|SLIDE|PRESENTASI|POWERPOINT)/i', $trimmed)) {
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
                if (preg_match('/^[A-Z]\.\s+/', $trimmed) || preg_match('/^(PROGRAM|MODUL|ALUR|SILABUS)/i', $trimmed)) {
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
        $want = ai_normalize_gemini_model($model);
        $candidates = [];
        if ($want !== '') {
            $candidates[] = $want;
        }

        $flash_first = ['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-1.5-flash'];
        foreach ($flash_first as $fl) {
            if (!in_array($fl, $candidates, true)) {
                $candidates[] = $fl;
            }
        }

        // Model dari akun (list) — Flash dulu, Pro belakangan agar hemat kuota.
        $listed = ai_gemini_list_models($api_key);
        usort($listed, function ($a, $b) {
            $score = function ($n) {
                $n = strtolower($n);
                if (strpos($n, 'flash') !== false) {
                    return 0;
                }
                return 1;
            };
            return $score($a) <=> $score($b);
        });
        foreach ($listed as $m) {
            if (!in_array($m, $candidates, true)) {
                $candidates[] = $m;
            }
        }
        // Pro hanya sebagai pilihan terakhir (kuota gratis sangat kecil).
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
            // HTTP 0 = koneksi putus (timeout/DNS/firewall): retry sekali sebelum menyerah.
            if ($code === 0) {
                sleep(2);
                [$code, $body, $cerr] = ai_http_post_json($url, $payload, [], 150);
            }
            // Overload server (500/502/503): retry sekali model yang sama.
            // Kuota habis (429): JANGAN retry model yang sama (boros kuota) — langsung pindah model lain.
            if (in_array($code, [500, 502, 503], true)) {
                sleep(3);
                [$code, $body, $cerr] = ai_http_post_json($url, $payload, [], 150);
            }
            if ($code === 429) {
                $hit_429 = true;
                $last_err = 'Kuota';
                sleep(2); // jeda singkat sebelum coba model berikutnya
                continue;
            }
            if ($code >= 200 && $code < 300) {
                $j = json_decode($body, true);
                $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text === '') {
                    return [false, 'Respons Gemini kosong (model: ' . $try . ').'];
                }
                return ai_parse_soal_json($text);
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
            // Model tidak cocok untuk output teks/JSON (misal model audio/gambar seperti *-tts):
            // lewati ke model berikutnya, jangan berhenti.
            if ($code === 400 && stripos($body, 'not supported') !== false) {
                sleep(1);
                continue;
            }
            $last_err = 'Gemini HTTP ' . $code . ' (model: ' . $try . '): ' . mb_substr($body, 0, 200);
            // Hanya fallback bila model tidak ada / tidak didukung; error lain langsung berhenti.
            if ($code !== 404) {
                break;
            }
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
