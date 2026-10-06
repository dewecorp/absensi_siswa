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
        '👍','👎','👏','🙌','🙏','👋','✌️','🤝','💪','👀',
        '❤️','🧡','💛','💚','💙','💜','🖤','🤍','💔','💯',
        '🔥','⭐','✨','🎉','🎊','🎁','🏆','🥇','✅','❌',
        '📚','✏️','📝','🎒','🏫','📖','💡','🔔','📌','📎',
        '😇','🕌','🕋','🤲','🌙','⭐','🌟','💫','🙂','🙃',
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
