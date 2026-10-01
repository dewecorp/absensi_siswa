<?php
// Konektor AI per guru untuk generate soal (Gemini + OpenAI/ChatGPT).
// Kunci API milik masing-masing guru (tersimpan di tb_guru, bukan admin).

if (!function_exists('ai_helper_schema')) {
    // Tambah kolom AI ke tb_guru bila belum ada. Dipanggil di halaman profil guru.
    function ai_helper_schema(PDO $pdo): void {
        foreach ([
            "ai_provider VARCHAR(20) NULL DEFAULT 'gemini'",
            "ai_gemini_key VARCHAR(255) NULL",
            "ai_gemini_model VARCHAR(100) NULL DEFAULT 'gemini-2.0-flash'",
            "ai_openai_key VARCHAR(255) NULL",
            "ai_openai_model VARCHAR(100) NULL DEFAULT 'gpt-4o-mini'",
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
    // Ambil konfigurasi AI milik satu guru. Kembalikan ['provider','gemini_key','gemini_model','openai_key','openai_model'].
    function ai_guru_config(PDO $pdo, int $guru_id): array {
        $out = [
            'provider' => 'gemini',
            'gemini_key' => '',
            'gemini_model' => 'gemini-2.0-flash',
            'openai_key' => '',
            'openai_model' => 'gpt-4o-mini',
        ];
        try {
            $st = $pdo->prepare("SELECT ai_provider, ai_gemini_key, ai_gemini_model, ai_openai_key, ai_openai_model FROM tb_guru WHERE id_guru = ? LIMIT 1");
            $st->execute([$guru_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (in_array($row['ai_provider'] ?? '', ['gemini', 'openai'], true)) {
                    $out['provider'] = $row['ai_provider'];
                }
                $out['gemini_key'] = trim((string)($row['ai_gemini_key'] ?? ''));
                if (trim((string)($row['ai_gemini_model'] ?? '')) !== '') {
                    $out['gemini_model'] = trim((string)$row['ai_gemini_model']);
                }
                $out['openai_key'] = trim((string)($row['ai_openai_key'] ?? ''));
                if (trim((string)($row['ai_openai_model'] ?? '')) !== '') {
                    $out['openai_model'] = trim((string)$row['ai_openai_model']);
                }
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

if (!function_exists('ai_build_soal_prompt')) {
    // Susun prompt generate soal + kisi-kisi. Kurikulum: PERMENDIKDASMEN_046 atau KMA_1503_KBC.
    function ai_build_soal_prompt(array $in): string {
        $is_kma = strtoupper($in['kurikulum'] ?? '') === 'KMA';
        $kur = $is_kma
            ? 'KMA 1503 Tahun 2025 tentang Kurikulum Berbasis Cinta (KBC) untuk Madrasah'
            : 'Permendikdasmen Nomor 46 Tahun 2025 (Standar Kompetensi Lulusan, Standar Isi, dan Struktur Kurikulum TK/RA, SD/MI, SMP/MTs, SMA/MA)';
        $lines = [];
        $lines[] = 'Anda adalah penyusun soal profesional untuk madrasah/sekolah di Indonesia.';
        $lines[] = 'Buatkan ' . (int)$in['jumlah'] . ' butir soal ' . $in['bentuk'] . ' beserta kisi-kisi, kunci jawaban, dan pembahasan singkat.';
        $lines[] = 'WAJIB patuh pada dasar kurikulum berikut: ' . $kur . '.';
        if ($is_kma) {
            $lines[] = 'Prinsip KBC: cinta kepada Allah, cinta kepada sesama, cinta kepada lingkungan; integrasikan nilai moderasi beragama dan adab bila relevan dengan materi.';
        } else {
            $lines[] = 'Prinsip Kurikulum Merdeka / Standar Isi: fokus pada CP-TP-ATP dan profil pelajar Pancasila.';
        }
        $lines[] = 'Konteks pembelajaran:';
        $lines[] = '- Mata pelajaran: ' . $in['mapel'];
        $lines[] = '- Kelas: ' . $in['kelas'];
        $lines[] = '- Materi: ' . $in['materi'];
        if ($in['cp'] !== '') $lines[] = '- Capaian Pembelajaran (CP): ' . $in['cp'];
        if ($in['tp'] !== '') $lines[] = '- Tujuan Pembelajaran (TP): ' . $in['tp'];
        if ($in['atp'] !== '') $lines[] = '- Alur Tujuan Pembelajaran (ATP): ' . $in['atp'];
        $lines[] = '- Tingkat kesulitan dominan: ' . $in['kesulitan'] . ' (boleh variasi Mudah/Sedang/Sukar, tandai tiap butir).';
        if ($in['bentuk'] === 'Pilihan Ganda') {
            $lines[] = 'Aturan Pilihan Ganda: tiap butir tepat 4 opsi berlabel A-D dan satu kunci (A/B/C/D).';
        } elseif ($in['bentuk'] === 'Pilihan Ganda Kompleks') {
            $lines[] = 'Aturan Pilihan Ganda Kompleks: tiap butir beri 4-5 opsi, kunci dapat lebih dari satu (contoh kunci "A,C" atau "B,D,E"); siswa memilih semua yang benar.';
        } elseif ($in['bentuk'] === 'Menjodohkan') {
            $lines[] = 'Aturan Menjodohkan: tiap butir beri kolom kiri 3-5 item dan kanan 3-5 pasangan acak, kunci format "1-B,2-A,3-C" (pasangan kiri-kanan).';
        } elseif ($in['bentuk'] === 'Isian Singkat') {
            $lines[] = 'Aturan Isian Singkat: kunci berupa jawaban singkat 1-5 kata.';
        } else {
            $lines[] = 'Aturan Uraian: kunci berupa jawaban uraian singkat 1-3 kalimat sebagai acuan penskoran.';
        }
        $lines[] = 'Jawab HANYA dengan JSON valid (tanpa markdown, tanpa penjelasan di luar JSON) memakai skema persis ini:';
        $lines[] = '{"kisi_kisi":[{"no":1,"materi":"...","indikator":"...","bentuk":"...","kesulitan":"...","bobot":1}],"soal":[{"no":1,"pertanyaan":"...","opsi":{"A":"...","B":"...","C":"...","D":"..."},"kunci":"A","pembahasan":"...","indikator":"...","kesulitan":"Sedang","bobot":1}]}';
        $lines[] = 'Untuk bentuk selain Pilihan Ganda, isi "opsi" dengan {} dan "kunci" dengan jawaban benar sesuai aturan bentuk di atas.';
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

if (!function_exists('ai_generate_soal')) {
    // Panggil provider AI milik guru. Kembalikan [ok, data|pesan_error].
    function ai_generate_soal(string $provider, string $api_key, string $model, string $prompt): array {
        if ($api_key === '') {
            return [false, 'API key belum diisi. Isi di Profil > Konektor AI.'];
        }
        if ($provider === 'openai') {
            $url = 'https://api.openai.com/v1/chat/completions';
            [$code, $body] = ai_http_post_json($url, [
                'model' => $model !== '' ? $model : 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Jawab hanya JSON valid sesuai skema yang diminta.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ], ['Authorization: Bearer ' . $api_key]);
            if ($code < 200 || $code >= 300) {
                return [false, 'OpenAI HTTP ' . $code . ': ' . mb_substr($body, 0, 200)];
            }
            $j = json_decode($body, true);
            $text = $j['choices'][0]['message']['content'] ?? '';
            if ($text === '') {
                return [false, 'Respons OpenAI kosong.'];
            }
            return ai_parse_soal_json($text);
        }
        // Default: Gemini
        $model = $model !== '' ? $model : 'gemini-2.0-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($api_key);
        [$code, $body] = ai_http_post_json($url, [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.7, 'responseMimeType' => 'application/json'],
        ]);
        if ($code < 200 || $code >= 300) {
            return [false, 'Gemini HTTP ' . $code . ': ' . mb_substr($body, 0, 200)];
        }
        $j = json_decode($body, true);
        $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            return [false, 'Respons Gemini kosong.'];
        }
        return ai_parse_soal_json($text);
    }
}

if (!function_exists('ai_test_connection')) {
    // Tes koneksi ringan per provider milik guru.
    function ai_test_connection(string $provider, string $api_key, string $model): array {
        if ($api_key === '') {
            return [false, 'API key belum diisi.'];
        }
        if ($provider === 'openai') {
            [$code, $body] = ai_http_post_json('https://api.openai.com/v1/models', [], ['Authorization: Bearer ' . $api_key], 30);
            if ($code === 200) {
                return [true, 'Koneksi OpenAI OK.'];
            }
            return [false, 'OpenAI HTTP ' . $code . ': ' . mb_substr($body, 0, 150)];
        }
        $model = $model !== '' ? $model : 'gemini-2.0-flash';
        [$code, $body] = ai_http_post_json(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($api_key),
            ['contents' => [['parts' => [['text' => 'Balas tepat dengan kata: OK']]]]],
            [],
            30
        );
        if ($code === 200) {
            return [true, 'Koneksi Gemini OK.'];
        }
        return [false, 'Gemini HTTP ' . $code . ': ' . mb_substr($body, 0, 150)];
    }
}
