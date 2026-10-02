<?php
// Konektor AI per guru untuk generate soal (Gemini + OpenAI/ChatGPT).
// Kunci API milik masing-masing guru (tersimpan di tb_guru, bukan admin).

if (!function_exists('ai_helper_schema')) {
    // Tambah kolom AI ke tb_guru bila belum ada. Dipanggil di halaman profil guru.
    function ai_helper_schema(PDO $pdo): void {
        foreach ([
            "ai_provider VARCHAR(20) NULL DEFAULT 'gemini'",
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

if (!function_exists('ai_guru_config')) {
    // Ambil konfigurasi AI milik satu guru. Kembalikan ['provider','gemini_key','gemini_model','openai_email','openai_key','openai_model'].
    function ai_guru_config(PDO $pdo, int $guru_id): array {
        $out = [
            'provider' => 'gemini',
            'gemini_key' => '',
            'gemini_model' => '',
            'openai_email' => '',
            'openai_key' => '',
            'openai_model' => '',
        ];
        try {
            $st = $pdo->prepare("SELECT ai_provider, ai_gemini_key, ai_gemini_model, ai_openai_email, ai_openai_key, ai_openai_model FROM tb_guru WHERE id_guru = ? LIMIT 1");
            $st->execute([$guru_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (in_array($row['ai_provider'] ?? '', ['gemini', 'openai'], true)) {
                    $out['provider'] = $row['ai_provider'];
                }
                $out['gemini_key'] = trim((string)($row['ai_gemini_key'] ?? ''));
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
        $total = array_sum($paket);
        $rincian = [];
        foreach ($paket as $b => $n) {
            $rincian[] = '- ' . $n . ' butir ' . $b;
        }
        $lines = [];
        $lines[] = 'Anda adalah penyusun soal profesional untuk madrasah/sekolah di Indonesia.';
        $lines[] = 'Buatkan 1 PAKET soal berjumlah ' . $total . ' butir beserta kisi-kisi, kunci jawaban, dan pembahasan singkat.';
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
        $lines[] = '- Tingkat kesulitan: ACAK dan MERATA untuk tiap bentuk soal (setiap bentuk wajib ada yang Mudah, Sedang, dan Sukar bila jumlah memungkinkan; bila jumlah < 3, variasikan sebisa mungkin). Tandai tiap butir pada field "kesulitan".';
        $lines[] = 'Level kognitif (WAJIB untuk tiap butir kisi-kisi dan soal, tulis persis L1/L2/L3/L4):';
        $lines[] = '- L1 = Mengingat (C1).';
        $lines[] = '- L2 = Memahami (C2).';
        $lines[] = '- L3 = Mengaplikasikan/Menganalisis (C3-C4).';
        $lines[] = '- L4 = Mengevaluasi/Mencipta (C5-C6).';
        $lines[] = 'Sebar level L1 sampai L4 secara wajar sesuai topik dan bentuk soal; cantumkan field "level_kognitif" pada tiap butir kisi-kisi dan soal.';
        $lines[] = 'Aturan per bentuk:';
        $lines[] = '- Pilihan Ganda: tiap butir tepat 4 opsi berlabel A-D dan satu kunci (A/B/C/D).';
        $lines[] = '- Pilihan Ganda Kompleks: tiap butir 4-5 opsi, kunci bisa lebih dari satu (contoh "A,C"); siswa memilih semua yang benar.';
        $lines[] = '- Menjodohkan: tiap butir WAJIB berisi "tabel" berisi 3-5 baris pasangan. Tiap baris WAJIB punya 4 kolom: "no" (1,2,3...), "kiri" (pernyataan/soal), "huruf" (A,B,C...), "kanan" (pilihan jawaban, acak, tidak berurutan dengan kiri). Kunci format "1-B,2-A,3-C" (pasangan nomor-huruf). Contoh tabel: [{"no":1,"kiri":"Sunan Ampel","huruf":"B","kanan":"Moh Limo"},{"no":2,"kiri":"Sunan Giri","huruf":"A","kanan":"Pesantren Giri"}].';
        $lines[] = '- Isian Singkat: kunci berupa jawaban singkat 1-5 kata.';
        $lines[] = '- Uraian: kunci berupa jawaban uraian 1-3 kalimat sebagai acuan penskoran.';
        $lines[] = 'Kisi-kisi WAJIB memuat kolom: no, materi, cp, tp, indikator, bentuk, level_kognitif (L1-L4), kesulitan, bobot.';
        $lines[] = 'CP = turunkan dari kurikulum: Permendikdasmen CP 046 (fase/kelas terkait) atau KMA 1503+KBC (madrasah). TP = jabaran operasional topik/sub-topik di atas (1-2 kalimat, diawali kata kerja operasional).';
        $lines[] = 'Jawab HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan di luar JSON) memakai skema persis ini:';
        $lines[] = '{"kisi_kisi":[{"no":1,"materi":"...","cp":"...","tp":"...","indikator":"...","bentuk":"Pilihan Ganda","level_kognitif":"L2","kesulitan":"Sedang","bobot":1}],"soal":[{"no":1,"bentuk":"Pilihan Ganda","pertanyaan":"...","opsi":{"A":"...","B":"...","C":"...","D":"..."},"tabel":[],"kunci":"A","pembahasan":"...","indikator":"...","level_kognitif":"L2","kesulitan":"Sedang","bobot":1}]}';
        $lines[] = 'Untuk bentuk selain Pilihan Ganda / Pilihan Ganda Kompleks, isi "opsi" dengan {} dan "kunci" dengan jawaban benar sesuai aturan bentuk di atas. Untuk Menjodohkan, "opsi" tetap {} dan "tabel" WAJIB diisi sesuai aturan. Nomor "no" urut 1 sampai ' . $total . ' untuk seluruh paket.';
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
    function ai_generate_soal(string $provider, string $api_key, string $model, string $prompt): array {
        if ($api_key === '') {
            return [false, 'API key belum diisi. Isi di Profil > Konektor AI.'];
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
        $want = ai_normalize_gemini_model($model);
        $candidates = [];
        if ($want !== '') {
            $candidates[] = $want;
        }
        foreach (ai_gemini_list_models($api_key) as $m) {
            if (!in_array($m, $candidates, true)) {
                $candidates[] = $m;
            }
        }
        foreach (['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-pro-latest'] as $fb) {
            if (!in_array($fb, $candidates, true)) {
                $candidates[] = $fb;
            }
        }
        $last_err = 'Tidak ada model Gemini yang bisa dipakai.';
        foreach ($candidates as $try) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($try) . ':generateContent?key=' . urlencode($api_key);
            [$code, $body] = ai_http_post_json($url, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.7, 'responseMimeType' => 'application/json'],
            ]);
            if ($code >= 200 && $code < 300) {
                $j = json_decode($body, true);
                $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($text === '') {
                    return [false, 'Respons Gemini kosong (model: ' . $try . ').'];
                }
                return ai_parse_soal_json($text);
            }
            $last_err = 'Gemini HTTP ' . $code . ' (model: ' . $try . '): ' . mb_substr($body, 0, 200);
            // Hanya fallback bila model tidak ada / tidak didukung; error lain langsung berhenti.
            if ($code !== 404) {
                break;
            }
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
    function ai_test_connection(string $provider, string $api_key, string $model): array {
        if ($api_key === '') {
            return [false, 'API key belum diisi.'];
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
        foreach (ai_gemini_list_models($api_key) as $m) {
            if (!in_array($m, $candidates, true)) {
                $candidates[] = $m;
            }
        }
        foreach (['gemini-2.5-flash', 'gemini-flash-latest', 'gemini-1.5-flash', 'gemini-1.5-pro'] as $fb) {
            if (!in_array($fb, $candidates, true)) {
                $candidates[] = $fb;
            }
        }
        foreach ($candidates as $try) {
            [$code, $body] = ai_http_post_json(
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($try) . ':generateContent?key=' . urlencode($api_key),
                ['contents' => [['parts' => [['text' => 'Balas tepat dengan kata: OK']]]]],
                [],
                30
            );
            if ($code === 200) {
                return [true, 'Koneksi Gemini OK (model: ' . $try . ').'];
            }
            if ($code !== 404) {
                return [false, 'Gemini HTTP ' . $code . ' (model: ' . $try . '): ' . mb_substr($body, 0, 150)];
            }
        }
        return [false, 'Gemini HTTP 404: tidak ada model tersedia. Coba lagi nanti.'];
    }
}
