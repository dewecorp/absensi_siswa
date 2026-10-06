<?php
// Helper Diskusi Kelas ala timeline (dua arah guru/wali/siswa).
// Kirim teks + gambar + video + file, komentar, dan suka.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/learning_schema.php';

function diskusi_upload_base(): string {
    $dir = dirname(__DIR__) . '/uploads/diskusi/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function diskusi_file_kind(string $ext): string {
    $ext = strtolower($ext);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return 'image';
    if (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'], true)) return 'video';
    return 'file';
}

function diskusi_allowed_ext(string $ext): bool {
    return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'avi', 'mkv', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar'], true);
}

function diskusi_handle_upload(array $file): array {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'none', null];
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Unggah file gagal (kode ' . (int)$file['error'] . ').');
    }
    if (($file['size'] ?? 0) > 25 * 1024 * 1024) {
        throw new RuntimeException('Ukuran file maksimal 25MB.');
    }
    $orig = trim((string)($file['name'] ?? 'file'));
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!diskusi_allowed_ext($ext)) {
        throw new RuntimeException('Format file tidak didukung.');
    }
    $name = 'diskusi_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], diskusi_upload_base() . $name)) {
        throw new RuntimeException('Gagal menyimpan file.');
    }
    return [$name, diskusi_file_kind($ext), $orig];
}

function diskusi_file_url(?string $stored): ?string {
    $stored = trim((string)$stored);
    if ($stored === '') return null;
    if (preg_match('#^https?://#i', $stored)) return $stored;
    $segs = explode('/', ltrim(str_replace('\\', '/', $stored), '/'));
    foreach ($segs as $i => $s) {
        if ($s !== '' && $s !== '.' && $s !== '..') $segs[$i] = rawurlencode(rawurldecode($s));
    }
    return '../uploads/diskusi/' . implode('/', $segs);
}

function diskusi_delete_file(?string $stored): void {
    $stored = trim((string)$stored);
    if ($stored === '' || preg_match('#^https?://#i', $stored)) return;
    $p = diskusi_upload_base() . basename(str_replace('\\', '/', $stored));
    if (is_file($p)) {
        @unlink($p);
    }
}

function diskusi_initials(string $name): string {
    $out = '';
    foreach (preg_split('/\s+/', trim($name)) as $w) {
        if ($w !== '') {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
            if (mb_strlen($out) >= 2) break;
        }
    }
    return $out !== '' ? $out : '?';
}

function diskusi_avatar_color(string $key): string {
    $palette = ['#6777ef', '#3abaf4', '#47c363', '#ffa426', '#fc544b', '#9467ef', '#20c997', '#ec4899'];
    return $palette[abs(crc32($key)) % count($palette)];
}

function diskusi_bg_list(): array {
    return [
        'none' => ['label' => 'Putih', 'css' => '#ffffff', 'dark' => false],
        'sunset' => ['label' => 'Senja', 'css' => 'linear-gradient(135deg,#ff9a3c,#fc4a6d)', 'dark' => true],
        'ocean' => ['label' => 'Laut', 'css' => 'linear-gradient(135deg,#2193b0,#6dd5ed)', 'dark' => true],
        'grape' => ['label' => 'Anggur', 'css' => 'linear-gradient(135deg,#7b2ff7,#f107a3)', 'dark' => true],
        'forest' => ['label' => 'Hutan', 'css' => 'linear-gradient(135deg,#11998e,#38ef7d)', 'dark' => true],
        'night' => ['label' => 'Malam', 'css' => 'linear-gradient(135deg,#232526,#414345)', 'dark' => true],
        'candy' => ['label' => 'Permen', 'css' => 'linear-gradient(135deg,#ff6fd8,#ffc3a0)', 'dark' => false],
        'mint' => ['label' => 'Mint', 'css' => 'linear-gradient(135deg,#a8ff78,#78ffd6)', 'dark' => false],
        'pattern-dot' => ['label' => 'Titik', 'css' => 'radial-gradient(#6777ef 1.5px, #eef2ff 1.5px)', 'dark' => false, 'size' => '18px 18px'],
        'pattern-line' => ['label' => 'Garis', 'css' => 'repeating-linear-gradient(45deg,#eef2ff 0 10px,#ffffff 10px 20px)', 'dark' => false],
    ];
}

function diskusi_bg_style(string $bg): array {
    $list = diskusi_bg_list();
    if (!isset($list[$bg])) {
        $bg = 'none';
    }
    return [$bg, $list[$bg]];
}

function diskusi_allowed_bg(?string $bg): string {
    $bg = trim((string)$bg);
    $list = diskusi_bg_list();
    return isset($list[$bg]) ? $bg : 'none';
}

function diskusi_emoji_list(): array {
    return [
        '😀','😁','😂','🤣','😊','😉','😋','😎','😍','🥰',
        '🤔','🤨','😐','🙄','😴','🤯','🥳','😭','😢','😮',
        '😡','😱','🤗','🤫','😇','🤠','🥺','😜','🤪','😝',
        '🙂','🙃','😌','😔','😤','🥲','😷','🤒','🤕','😇',
        '👍','👎','👏','🙌','🙏','👋','✌️','🤝','💪','👀',
        '👌','✊','🤞','🤟','👈','👉','👆','👇','☝️','🫶',
        '❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','💯',
        '🔥','⭐','✨','🎉','🎊','🎁','🏆','🥇','✅','❌',
        '❗','❓','‼️','💬','💭','🔴','🟢','🟡','🔵','⚪',
        '📍','🗺️','🧭','🏠','🏫','🕌','🕋','📌','📎','🧷',
        '🕐','🕑','🕒','🕓','🕔','🕕','🕖','🕗','🕘','🕙',
        '🕚','🕛','⏰','⏱️','🕰️','📅','📆','🗓️','⌛','⏳',
        '📷','📸','🎥','📹','🎬','🎤','🎧','📞','📢','🔔',
        '📚','✏️','📝','🎒','📖','💡','🔖','📊','📈','🧾',
        '🤲','🌙','⭐','🌟','💫','🙏','🕊️','🌸','🌺','🌈',
    ];
}

function diskusi_toggle_like(PDO $pdo, string $target, int $target_id, string $author_key, string $role, int $gid, int $sid): bool {
    $chk = $pdo->prepare("SELECT id FROM tb_diskusi_suka WHERE target = ? AND target_id = ? AND author_key = ?");
    $chk->execute([$target, $target_id, $author_key]);
    $id = $chk->fetchColumn();
    if ($id) {
        $pdo->prepare("DELETE FROM tb_diskusi_suka WHERE id = ?")->execute([$id]);
        return false;
    }
    $pdo->prepare("INSERT INTO tb_diskusi_suka (target, target_id, author_role, id_guru, id_siswa, author_key) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$target, $target_id, $role, $gid ?: null, $sid ?: null, $author_key]);
    return true;
}

function diskusi_youtube_id(?string $url): ?string {
    $url = trim((string)$url);
    if ($url === '') return null;
    if (preg_match('~(?:youtube\.com/(?:watch\?[^#]*v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~i', $url, $m)) {
        return $m[1];
    }
    return null;
}

function diskusi_fetch_link_meta(string $url): array {
    $url = trim((string)$url);
    $host = '';
    try { $host = (string)(parse_url($url, PHP_URL_HOST) ?: ''); } catch (Throwable $e) {}
    $host = strtolower($host);
    $fallback = ['url' => $url, 'host' => $host, 'title' => $host !== '' ? $host : $url, 'desc' => '', 'image' => ''];
    if (!preg_match('~^https?://~i', $url)) return $fallback;
    if (!function_exists('diskusi_upload_base')) return $fallback;
    try {
        $cache_dir = diskusi_upload_base() . '.linkmeta/';
        if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
        $cache_file = $cache_dir . md5(strtolower($url)) . '.json';
        if (is_file($cache_file) && (time() - (int)@filemtime($cache_file)) < 7 * 86400) {
            $cached = json_decode((string)@file_get_contents($cache_file), true);
            if (is_array($cached) && !empty($cached['url'])) return $cached;
        }
    } catch (Throwable $e) { $cache_file = ''; }
    $html = '';
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (SIMAD Link Preview)');
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $html = (string)curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 4, 'header' => "User-Agent: Mozilla/5.0 (SIMAD Link Preview)\r\n", 'follow_location' => 1, 'max_redirects' => 3]]);
            $html = (string)@file_get_contents($url, false, $ctx, 0, 300000);
        }
    } catch (Throwable $e) { $html = ''; }
    if ($html === '') return $fallback;
    $html = substr($html, 0, 300000);
    $get_meta = function ($names) use ($html) {
        foreach ((array)$names as $nm) {
            if (preg_match('~<meta[^>]+(?:property|name)=["\']' . preg_quote($nm, '~') . '["\'][^>]*content=["\']([^"\']+)~i', $html, $m)) return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            if (preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]*?(?:property|name)=["\']' . preg_quote($nm, '~') . '["\']~i', $html, $m)) return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        return '';
    };
    $title = $get_meta(['og:title', 'twitter:title']);
    if ($title === '' && preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    if ($title === '') $title = $host !== '' ? $host : $url;
    $desc = $get_meta(['og:description', 'twitter:description', 'description']);
    if (mb_strlen($desc) > 180) $desc = mb_substr($desc, 0, 177) . '...';
    $img = $get_meta(['og:image', 'twitter:image']);
    if ($img !== '') {
        if (strpos($img, '//') === 0) $img = 'https:' . $img;
        elseif (strpos($img, '/') === 0 && $host !== '') {
            $scheme = (string)(parse_url($url, PHP_URL_SCHEME) ?: 'https');
            $img = $scheme . '://' . $host . $img;
        }
        if (!preg_match('~^https?://~i', $img)) $img = '';
    }
    $meta = ['url' => $url, 'host' => $host, 'title' => $title, 'desc' => $desc, 'image' => $img];
    if (!empty($cache_file)) @file_put_contents($cache_file, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $meta;
}

function diskusi_format_text(?string $text): string {
    $text = trim((string)$text);
    if ($text === '') return '';

    $parts = preg_split('~(https?://[^\s<>"\']+)~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $previewed = [];
    foreach ($parts as $i => $part) {
        if (($i % 2) === 1) {
            $url = rtrim(trim($part), '.,;:!?)]}');
            $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $yt = diskusi_youtube_id($url);
            if ($yt !== null) {
                $out .= '<a href="' . $safe . '" target="_blank" rel="noopener" style="color:#1877f2;font-weight:600;word-break:break-all;">' . $safe . '</a>';
                $out .= '<span class="diskusi-yt-wrap" style="display:block;margin-top:8px;border-radius:12px;overflow:hidden;background:#000;">';
                $out .= '<span style="display:block;position:relative;width:100%;padding-bottom:56.25%;">';
                $out .= '<iframe src="https://www.youtube.com/embed/' . htmlspecialchars($yt, ENT_QUOTES, 'UTF-8') . '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>';
                $out .= '</span></span>';
            } elseif (preg_match('~\.(jpg|jpeg|png|gif|webp)(\?.*)?$~i', $url)) {
                $out .= '<a href="' . $safe . '" target="_blank" rel="noopener" style="color:#1877f2;font-weight:600;word-break:break-all;">' . $safe . '</a>';
                $out .= '<a href="' . $safe . '" target="_blank" rel="noopener" style="display:block;margin-top:8px;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;background:#f8fafc;"><img src="' . $safe . '" alt="pratinjau tautan" loading="lazy" style="display:block;width:100%;height:auto;max-height:600px;object-fit:contain;"></a>';
            } elseif (preg_match('~\.(mp4|webm|mov)(\?.*)?$~i', $url)) {
                $out .= '<a href="' . $safe . '" target="_blank" rel="noopener" style="color:#1877f2;font-weight:600;word-break:break-all;">' . $safe . '</a>';
                $out .= '<video src="' . $safe . '" controls preload="metadata" style="display:block;margin-top:8px;width:100%;max-height:320px;border-radius:12px;background:#000;"></video>';
            } else {
                $out .= '<a href="' . $safe . '" target="_blank" rel="noopener" style="color:#1877f2;font-weight:600;word-break:break-all;">' . $safe . '</a>';
                $lkey = strtolower($url);
                if (!isset($previewed[$lkey])) {
                    $previewed[$lkey] = true;
                    $meta = diskusi_fetch_link_meta($url);
                    $m_url = htmlspecialchars($meta['url'], ENT_QUOTES, 'UTF-8');
                    $m_host = htmlspecialchars(strtoupper((string)$meta['host']), ENT_QUOTES, 'UTF-8');
                    $m_title = htmlspecialchars((string)$meta['title'], ENT_QUOTES, 'UTF-8');
                    $m_desc = htmlspecialchars((string)$meta['desc'], ENT_QUOTES, 'UTF-8');
                    $m_img = htmlspecialchars((string)$meta['image'], ENT_QUOTES, 'UTF-8');
                    $out .= '<a href="' . $m_url . '" target="_blank" rel="noopener" style="display:block;margin-top:8px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;text-decoration:none;max-width:100%;">';
                    if ($m_img !== '') $out .= '<img src="' . $m_img . '" alt="" loading="lazy" style="display:block;width:100%;height:auto;max-height:420px;object-fit:contain;background:#f8fafc;border:0;">';
                    $out .= '<span style="display:block;padding:10px 12px;">';
                    $out .= '<span style="display:block;font-weight:700;font-size:14px;color:#111;line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">' . $m_title . '</span>';
                    if ($m_desc !== '') $out .= '<span style="display:block;font-size:13px;color:#65676b;line-height:1.45;margin-top:2px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">' . $m_desc . '</span>';
                    if ($m_host !== '') $out .= '<span style="display:block;font-size:11px;color:#9ca3af;margin-top:4px;letter-spacing:.3px;">' . $m_host . '</span>';
                    $out .= '</span></a>';
                }
            }
            continue;
        }
        $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
        $formatted = preg_replace_callback('/@([A-Za-z0-9\.\_\-\s]{2,40})/u', function($matches) {
            $tag_name = trim($matches[1], " \t\n\r\0\x0B.,");
            if ($tag_name === '') return $matches[0];
            return '<span class="diskusi-mention" style="display:inline-flex;align-items:center;gap:3px;background:#e7f3ff;color:#1877f2;font-weight:700;font-size:13px;padding:1px 8px;border-radius:999px;white-space:nowrap;"><i class="fas fa-at" style="font-size:11px;"></i>' . $tag_name . '</span>';
        }, $escaped);
        $out .= $formatted;
    }
    return nl2br($out);
}

function forum_get_mentionable_users(PDO $pdo): array {
    $users = [];
    try {
        $st = $pdo->query("SELECT nama_guru AS name, 'Guru' AS role, foto FROM tb_guru WHERE nama_guru IS NOT NULL AND nama_guru != '' ORDER BY nama_guru ASC");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $users[] = ['name' => trim($r['name']), 'role' => $r['role'], 'foto' => $r['foto'] ?? ''];
        }
        $st2 = $pdo->query("SELECT COALESCE(NULLIF(nama,''), username) AS name, level AS role, foto FROM tb_pengguna WHERE (nama IS NOT NULL AND nama != '') OR (username IS NOT NULL AND username != '') ORDER BY nama ASC");
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nm = trim($r['name']);
            $already = false;
            foreach ($users as $u) {
                if (strcasecmp($u['name'], $nm) === 0) { $already = true; break; }
            }
            if (!$already && $nm !== '') {
                $users[] = ['name' => $nm, 'role' => ucfirst($r['role'] ?? 'Staf'), 'foto' => $r['foto'] ?? ''];
            }
        }
    } catch (Throwable $e) {}
    return $users;
}

function diskusi_get_mentionable_users(PDO $pdo, int $id_kelas): array {
    $users = [];
    try {
        if ($id_kelas > 0) {
            $st2 = $pdo->prepare("SELECT nama_siswa AS name, 'Siswa' AS role, foto FROM tb_siswa WHERE id_kelas = ? AND nama_siswa IS NOT NULL AND nama_siswa != '' ORDER BY nama_siswa ASC");
            $st2->execute([$id_kelas]);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nm = trim((string)$r['name']);
                if ($nm !== '') {
                    $users[] = ['name' => $nm, 'role' => 'Siswa', 'foto' => $r['foto'] ?? ''];
                }
            }
        }
    } catch (Throwable $e) {}
    return $users;
}

function diskusi_avatar_html(string $name, ?string $foto = null, string $avatar_key = 'g_0', int $size = 44, string $extra_style = ''): string {
    $foto = trim((string)$foto);
    $img_path = null;
    if ($foto !== '') {
        $base = dirname(__DIR__);
        if (is_file($base . '/uploads/' . $foto)) {
            $img_path = '../uploads/' . rawurlencode($foto);
        } elseif (is_file($base . '/assets/img/' . $foto)) {
            $img_path = '../assets/img/' . rawurlencode($foto);
        } elseif (is_file($base . '/assets/img/siswa/' . $foto)) {
            $img_path = '../assets/img/siswa/' . rawurlencode($foto);
        }
    }

    if ($img_path) {
        return '<img src="' . htmlspecialchars($img_path) . '" alt="' . htmlspecialchars($name) . '" class="diskusi-avatar" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:cover;border-radius:50%;' . $extra_style . '">';
    }

    $avatar_col = diskusi_avatar_color($avatar_key);
    $initials = diskusi_initials($name);
    $font_size = max(10, (int)round($size * 0.38));
    return '<span class="diskusi-avatar" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . $font_size . 'px;background:' . htmlspecialchars($avatar_col) . ';' . $extra_style . '">' . htmlspecialchars($initials) . '</span>';
}

function diskusi_render_comments_tree(array $komen_list, int $post_id, int $selected_kelas, array $kelas_ids, int $user_id, string $user_role): string {
    if (empty($komen_list)) return '';

    $by_parent = [];
    foreach ($komen_list as $c) {
        $pid = (int)($c['parent_id'] ?? 0);
        $by_parent[$pid][] = $c;
    }

    $render_node = function($parent_id) use (&$render_node, $by_parent, $post_id, $selected_kelas, $kelas_ids, $user_id, $user_role) {
        if (empty($by_parent[$parent_id])) return '';
        $html = '';
        foreach ($by_parent[$parent_id] as $c) {
            $cid = (int)$c['id'];
            $c_guru = ($c['author_role'] === 'guru');
            $cname = $c_guru ? ($c['nama_guru'] ?: 'Guru') : ($c['nama_siswa'] ?: 'Siswa');
            $cfoto = $c_guru ? ($c['foto_guru'] ?? ($c['foto'] ?? null)) : ($c['foto_siswa'] ?? ($c['foto'] ?? null));
            if ($user_role === 'guru') {
                $own_c = ($c_guru && (int)$c['id_guru'] === (int)$user_id);
                $can_del = $own_c || in_array($selected_kelas, $kelas_ids, true);
            } else {
                $own_c = (!$c_guru && (int)$c['id_siswa'] === (int)$user_id);
                $can_del = $own_c;
            }
            $c_liked = ((int)($c['saya_suka'] ?? 0) > 0);
            $avatar_key = ($c_guru ? 'g_' : 's_') . ($c_guru ? (int)$c['id_guru'] : (int)$c['id_siswa']);
            $avatar_html = diskusi_avatar_html($cname, $cfoto, $avatar_key, 32);
            $cfurl = diskusi_file_url($c['file_path'] ?? null);
            $cfk = $c['file_kind'] ?? 'none';
            $cfn = htmlspecialchars($c['file_name'] ?: 'Lampiran');
            $time_str = function_exists('timeAgo') ? htmlspecialchars(timeAgo($c['created_at'])) : '';

            $html .= '<div class="d-flex mt-2 diskusi-komen-item" id="comment-' . $cid . '" style="gap:8px;">';
            $html .= $avatar_html;
            $html .= '<div class="flex-grow-1">';
            $html .= '<div class="diskusi-bubble px-3 py-2">';
            $html .= '<div class="diskusi-nama"><strong>' . htmlspecialchars($cname) . '</strong>';
            if ($c_guru) {
                $html .= '<i class="fas fa-check-circle text-primary ml-1" title="Guru terverifikasi"></i>';
            }
            $html .= '</div>';
            if (trim((string)$c['isi']) !== '') {
                $html .= '<div class="diskusi-teks" style="white-space:pre-wrap;">' . diskusi_format_text($c['isi']) . '</div>';
            }
            if ($cfurl) {
                $html .= '<div class="mt-2 diskusi-comment-media">';
                if ($cfk === 'image') {
                    $html .= '<a href="' . htmlspecialchars($cfurl) . '" data-lightbox="1" target="_blank"><img src="' . htmlspecialchars($cfurl) . '" style="max-width:220px;max-height:220px;border-radius:10px;object-fit:cover;display:block;" alt="lampiran"></a>';
                } elseif ($cfk === 'video') {
                    $html .= '<video src="' . htmlspecialchars($cfurl) . '" controls style="max-width:260px;max-height:220px;border-radius:10px;display:block;"></video>';
                } else {
                    $html .= '<a href="' . htmlspecialchars($cfurl) . '" target="_blank" class="btn btn-sm btn-light border" style="border-radius:8px;font-size:12px;"><i class="fas fa-paperclip mr-1 text-primary"></i>' . $cfn . '</a>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';

            $raw_isi_attr = htmlspecialchars((string)$c['isi'], ENT_QUOTES, 'UTF-8');
            $html .= '<div class="small mt-1 d-flex align-items-center flex-wrap" style="gap:10px;">';
            $html .= '<a href="#" class="diskusi-like font-weight-bold ' . ($c_liked ? 'text-primary' : 'text-muted') . '" data-target="komentar" data-id="' . $cid . '" data-kelas="' . (int)$selected_kelas . '" style="text-decoration:none;">Suka (<span class="like-count">' . (int)$c['jml_suka'] . '</span>)</a>';
            $html .= '<a href="#" class="diskusi-reply-btn font-weight-bold text-muted" data-post="' . $post_id . '" data-parent="' . $cid . '" data-name="' . htmlspecialchars($cname) . '" style="text-decoration:none;">Balas</a>';
            if ($own_c) {
                $html .= '<a href="#" class="diskusi-edit-toggle font-weight-bold text-muted" data-type="comment" data-id="' . $cid . '" style="text-decoration:none;">Edit</a>';
            }
            if ($time_str !== '') {
                $html .= '<span class="text-muted">' . $time_str . '</span>';
            }
            if ($can_del) {
                $html .= '<form method="POST" class="d-inline diskusi-delete-form" data-type="komentar">';
                $html .= '<input type="hidden" name="action" value="comment_delete">';
                $html .= '<input type="hidden" name="id" value="' . $cid . '">';
                if ($user_role === 'guru') {
                    $html .= '<input type="hidden" name="id_kelas" value="' . (int)$selected_kelas . '">';
                }
                $html .= '<button type="button" class="btn btn-link btn-sm text-muted p-0 diskusi-delete-btn" style="font-size:11px;border:0;outline:none;box-shadow:none;text-decoration:none;" title="Hapus">Hapus</button>';
                $html .= '</form>';
            }
            $html .= '</div>';
            $html .= '<div class="diskusi-edit-box mt-2" id="edit-comment-' . $cid . '" style="display:none;">';
            if ($own_c) {
                $html .= '<form method="POST" enctype="multipart/form-data" class="diskusi-edit-form">';
                $html .= '<input type="hidden" name="action" value="comment_edit">';
                $html .= '<input type="hidden" name="id" value="' . $cid . '">';
                if ($user_role === 'guru') {
                    $html .= '<input type="hidden" name="id_kelas" value="' . (int)$selected_kelas . '">';
                }
                $html .= '<textarea name="isi" class="form-control form-control-sm" rows="2" maxlength="1000" style="border-radius:12px;font-size:13px;">' . htmlspecialchars((string)$c['isi']) . '</textarea>';
                $html .= '<div class="mt-1 d-flex align-items-center" style="gap:6px;">';
                $html .= '<label class="mb-0 small text-muted" style="cursor:pointer;" title="Ganti lampiran"><i class="fas fa-paperclip mr-1"></i><span style="font-size:11px;">File</span><input type="file" name="file_komen" class="d-none" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar"></label>';
                $html .= '<button type="submit" class="btn btn-primary btn-sm px-3" style="border-radius:999px;font-size:12px;">Simpan</button>';
                $html .= '<button type="button" class="btn btn-light btn-sm px-3 diskusi-edit-cancel" data-target="edit-comment-' . $cid . '" style="border-radius:999px;font-size:12px;">Batal</button>';
                $html .= '</div></form>';
            }
            $html .= '</div>';

            $sub_html = $render_node($cid);
            if ($sub_html !== '') {
                $html .= '<div class="diskusi-reply-box mt-1 pl-2" style="border-left: 2px solid #e5e7eb; margin-left: 12px;">' . $sub_html . '</div>';
            }

            $html .= '</div></div>';
        }
        return $html;
    };

    return $render_node(0);
}

function forum_render_comments_tree(array $komen_list, int $post_id, string $current_author_key = '', bool $is_admin = false): string {
    if (empty($komen_list)) return '';

    $by_parent = [];
    foreach ($komen_list as $c) {
        $pid = (int)($c['parent_id'] ?? 0);
        $by_parent[$pid][] = $c;
    }

    $render_node = function($parent_id) use (&$render_node, $by_parent, $post_id, $current_author_key, $is_admin) {
        if (empty($by_parent[$parent_id])) return '';
        $html = '';
        foreach ($by_parent[$parent_id] as $c) {
            $cid = (int)$c['id'];
            $cname = (string)($c['display_name'] ?: ($c['nama'] ?: ($c['nama_guru'] ?: ($c['author_name'] ?: 'Pengguna'))));
            $c_key = (string)($c['author_key'] ?: ('g_' . (int)$c['id_guru']));
            $cfoto = $c['foto_guru'] ?? ($c['foto_user'] ?? ($c['foto'] ?? null));
            $own_c = ($c_key !== '' && $c_key === $current_author_key);
            $can_del = $own_c || $is_admin;
            $c_liked = ((int)($c['saya_suka'] ?? 0) > 0);
            $avatar_html = diskusi_avatar_html($cname, $cfoto, $c_key, 32);
            $cfurl = diskusi_file_url($c['file_path'] ?? null);
            $cfk = $c['file_kind'] ?? 'none';
            $cfn = htmlspecialchars($c['file_name'] ?: 'Lampiran');
            $time_str = function_exists('timeAgo') ? htmlspecialchars(timeAgo($c['created_at'])) : '';
            $role_str = (string)($c['author_role'] ?? 'guru');

            $role_badge = '<i class="fas fa-check-circle text-primary ml-1" title="Guru"></i>';
            if ($role_str === 'admin') {
                $role_badge = '<span class="badge badge-danger ml-1" style="font-size:10px;">Admin</span>';
            } elseif (in_array($role_str, ['kepala_madrasah', 'kepala'], true)) {
                $role_badge = '<span class="badge badge-warning ml-1" style="font-size:10px;">Kepala</span>';
            } elseif (in_array($role_str, ['tata_usaha', 'tu'], true)) {
                $role_badge = '<span class="badge badge-info ml-1" style="font-size:10px;">TU</span>';
            }

            $html .= '<div class="d-flex mt-2 diskusi-komen-item" id="comment-' . $cid . '" style="gap:8px;">';
            $html .= $avatar_html;
            $html .= '<div class="flex-grow-1">';
            $html .= '<div class="diskusi-bubble px-3 py-2">';
            $html .= '<div class="diskusi-nama"><strong>' . htmlspecialchars($cname) . '</strong> ' . $role_badge . '</div>';
            if (trim((string)$c['isi']) !== '') {
                $html .= '<div class="diskusi-teks" style="white-space:pre-wrap;">' . diskusi_format_text($c['isi']) . '</div>';
            }
            if ($cfurl) {
                $html .= '<div class="mt-2 diskusi-comment-media">';
                if ($cfk === 'image') {
                    $html .= '<a href="' . htmlspecialchars($cfurl) . '" data-lightbox="1" target="_blank"><img src="' . htmlspecialchars($cfurl) . '" style="max-width:220px;max-height:220px;border-radius:10px;object-fit:cover;display:block;" alt="lampiran"></a>';
                } elseif ($cfk === 'video') {
                    $html .= '<video src="' . htmlspecialchars($cfurl) . '" controls style="max-width:260px;max-height:220px;border-radius:10px;display:block;"></video>';
                } else {
                    $html .= '<a href="' . htmlspecialchars($cfurl) . '" target="_blank" class="btn btn-sm btn-light border" style="border-radius:8px;font-size:12px;"><i class="fas fa-paperclip mr-1 text-primary"></i>' . $cfn . '</a>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';

            $html .= '<div class="small mt-1 d-flex align-items-center flex-wrap" style="gap:10px;">';
            $html .= '<a href="#" class="diskusi-like font-weight-bold ' . ($c_liked ? 'text-primary' : 'text-muted') . '" data-target="komentar" data-id="' . $cid . '" style="text-decoration:none;">Suka (<span class="like-count">' . (int)$c['jml_suka'] . '</span>)</a>';
            $html .= '<a href="#" class="diskusi-reply-btn font-weight-bold text-muted" data-post="' . $post_id . '" data-parent="' . $cid . '" data-name="' . htmlspecialchars($cname) . '" style="text-decoration:none;">Balas</a>';
            if ($own_c) {
                $html .= '<a href="#" class="diskusi-edit-toggle font-weight-bold text-muted" data-type="comment" data-id="' . $cid . '" style="text-decoration:none;">Edit</a>';
            }
            if ($time_str !== '') {
                $html .= '<span class="text-muted">' . $time_str . '</span>';
            }
            if ($can_del) {
                $html .= '<form method="POST" class="d-inline diskusi-delete-form" data-type="komentar">';
                $html .= '<input type="hidden" name="action" value="comment_delete">';
                $html .= '<input type="hidden" name="id" value="' . $cid . '">';
                $html .= '<button type="button" class="btn btn-link btn-sm text-muted p-0 diskusi-delete-btn" style="font-size:11px;border:0;outline:none;box-shadow:none;text-decoration:none;" title="Hapus">Hapus</button>';
                $html .= '</form>';
            }
            $html .= '</div>';
            $html .= '<div class="diskusi-edit-box mt-2" id="edit-comment-' . $cid . '" style="display:none;">';
            if ($own_c) {
                $html .= '<form method="POST" enctype="multipart/form-data" class="diskusi-edit-form">';
                $html .= '<input type="hidden" name="action" value="comment_edit">';
                $html .= '<input type="hidden" name="id" value="' . $cid . '">';
                $html .= '<textarea name="isi" class="form-control form-control-sm" rows="2" maxlength="1000" style="border-radius:12px;font-size:13px;">' . htmlspecialchars((string)$c['isi']) . '</textarea>';
                $html .= '<div class="mt-1 d-flex align-items-center" style="gap:6px;">';
                $html .= '<label class="mb-0 small text-muted" style="cursor:pointer;" title="Ganti lampiran"><i class="fas fa-paperclip mr-1"></i><span style="font-size:11px;">File</span><input type="file" name="file_komen" class="d-none" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar"></label>';
                $html .= '<button type="submit" class="btn btn-primary btn-sm px-3" style="border-radius:999px;font-size:12px;">Simpan</button>';
                $html .= '<button type="button" class="btn btn-light btn-sm px-3 diskusi-edit-cancel" data-target="edit-comment-' . $cid . '" style="border-radius:999px;font-size:12px;">Batal</button>';
                $html .= '</div></form>';
            }
            $html .= '</div>';

            $sub_html = $render_node($cid);
            if ($sub_html !== '') {
                $html .= '<div class="diskusi-reply-box mt-1 pl-2" style="border-left: 2px solid #e5e7eb; margin-left: 12px;">' . $sub_html . '</div>';
            }

            $html .= '</div></div>';
        }
        return $html;
    };

    return $render_node(0);
}
