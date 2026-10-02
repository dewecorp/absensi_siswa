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
        $lines[] = '{"kisi_kisi":[{"no":1,"materi":"...","cp":"...","tp":"...","indikator":"...","bentuk":"Pilihan Ganda","level_kognitif":"L2","kesulitan":"Sedang","bobot":1}],"soal":[{"no":1,"bentuk":"Pilihan Ganda","pertanyaan":"...","opsi":{"A":"...","B":"...","C":"...","D":"..."},"tabel":[],"kunci":"A","pembahasan":"...","cp":"...","tp":"...","indikator":"...","level_kognitif":"L2","kesulitan":"Sedang","bobot":1}]}';
        $lines[] = 'Untuk bentuk selain Pilihan Ganda / Pilihan Ganda Kompleks, isi "opsi" dengan {} dan "kunci" dengan jawaban benar sesuai aturan bentuk di atas. Untuk Menjodohkan, "opsi" tetap {} dan "tabel" WAJIB diisi sesuai aturan. Nomor "no" pada "kisi_kisi" urut 1 sampai ' . $total . '. Nomor "no" pada "soal" urut 1 sampai ' . $total_soal_item . ' (Menjodohkan hanya 1 nomor karena hanya 1 butir soal).';
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
