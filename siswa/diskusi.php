<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';
require_once '../config/diskusi_helper.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = (int)($_SESSION['user_id'] ?? 0);
$st = $pdo->prepare("SELECT s.*, k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas WHERE s.id_siswa = ?");
$st->execute([$id_siswa]);
$student = $st->fetch(PDO::FETCH_ASSOC);
if (!$student || empty($student['id_kelas'])) {
    echo "Data kelas siswa tidak ditemukan.";
    exit;
}
$id_kelas = (int)$student['id_kelas'];
$nama_siswa = (string)($student['nama_siswa'] ?? 'Siswa');

$message = null;
$author_key = 's_' . (int)$id_siswa;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['ajax_like'])) {
        header('Content-Type: application/json; charset=UTF-8');
        $target = ($_POST['target'] ?? 'post') === 'komentar' ? 'komentar' : 'post';
        $target_id = (int)($_POST['target_id'] ?? 0);
        if ($target_id <= 0) {
            echo json_encode(['ok' => false]);
            exit;
        }
        try {
            $liked = diskusi_toggle_like($pdo, $target, $target_id, $author_key, 'siswa', 0, (int)$id_siswa);
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM tb_diskusi_suka WHERE target = ? AND target_id = ?");
            $cnt->execute([$target, $target_id]);
            echo json_encode(['ok' => true, 'liked' => $liked, 'count' => (int)$cnt->fetchColumn()]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    $action = $_POST['action'] ?? '';
    $back_anchor = '';
    try {
        if ($action === 'post_add') {
            $isi = trim((string)($_POST['isi'] ?? ''));
            $bg = diskusi_allowed_bg($_POST['bg'] ?? 'none');
            if ($isi === '' && empty($_FILES['file_diskusi']['name'])) {
                throw new RuntimeException('Tulis pesan atau lampirkan file.');
            }
            [$fp, $fk, $fn] = diskusi_handle_upload($_FILES['file_diskusi'] ?? []);
            $pdo->prepare("INSERT INTO tb_diskusi_post (id_kelas, author_role, id_guru, id_siswa, isi, bg, file_path, file_kind, file_name) VALUES (?, 'siswa', NULL, ?, ?, ?, ?, ?, ?)")
                ->execute([$id_kelas, $id_siswa, $isi, $bg, $fp, $fk, $fn]);
            $message = ['type' => 'success', 'text' => 'Postingan terkirim.'];
        } elseif ($action === 'post_delete') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM tb_diskusi_post WHERE id = ? AND id_kelas = ?");
            $st->execute([$id, $id_kelas]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p || $p['author_role'] !== 'siswa' || (int)$p['id_siswa'] !== (int)$id_siswa) {
                throw new RuntimeException('Anda hanya boleh menghapus postingan sendiri.');
            }
            $pdo->prepare("DELETE FROM tb_diskusi_suka WHERE target = 'post' AND target_id = ?")->execute([$id]);
            $kids = $pdo->prepare("SELECT id FROM tb_diskusi_komentar WHERE id_post = ?");
            $kids->execute([$id]);
            foreach ($kids->fetchAll(PDO::FETCH_COLUMN) as $kid) {
                $pdo->prepare("DELETE FROM tb_diskusi_suka WHERE target = 'komentar' AND target_id = ?")->execute([(int)$kid]);
            }
            $pdo->prepare("DELETE FROM tb_diskusi_komentar WHERE id_post = ?")->execute([$id]);
            diskusi_delete_file($p['file_path'] ?? null);
            $pdo->prepare("DELETE FROM tb_diskusi_post WHERE id = ?")->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Postingan dihapus.'];
        } elseif ($action === 'comment_add') {
            $id_post = (int)($_POST['id_post'] ?? 0);
            $parent_id = (int)($_POST['parent_id'] ?? 0);
            $isi = trim((string)($_POST['isi'] ?? ''));
            if ($id_post <= 0 || ($isi === '' && empty($_FILES['file_komen']['name']))) {
                throw new RuntimeException('Tulis balasan atau lampirkan file.');
            }
            $st = $pdo->prepare("SELECT id FROM tb_diskusi_post WHERE id = ? AND id_kelas = ?");
            $st->execute([$id_post, $id_kelas]);
            if (!$st->fetchColumn()) {
                throw new RuntimeException('Postingan tidak ditemukan.');
            }
            if ($parent_id > 0) {
                $stP = $pdo->prepare("SELECT id FROM tb_diskusi_komentar WHERE id = ? AND id_post = ?");
                $stP->execute([$parent_id, $id_post]);
                if (!$stP->fetchColumn()) {
                    $parent_id = 0;
                }
            }
            [$cfp, $cfk, $cfn] = diskusi_handle_upload($_FILES['file_komen'] ?? []);
            $pdo->prepare("INSERT INTO tb_diskusi_komentar (id_post, parent_id, author_role, id_guru, id_siswa, isi, file_path, file_kind, file_name) VALUES (?, ?, 'siswa', NULL, ?, ?, ?, ?, ?)")
                ->execute([$id_post, $parent_id > 0 ? $parent_id : null, $id_siswa, $isi, $cfp, $cfk, $cfn]);
            $back_anchor = '#post-' . $id_post;
            $message = ['type' => 'success', 'text' => 'Komentar terkirim.'];
        } elseif ($action === 'comment_delete') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT c.*, p.id_kelas FROM tb_diskusi_komentar c JOIN tb_diskusi_post p ON p.id = c.id_post WHERE c.id = ?");
            $st->execute([$id]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c || $c['author_role'] !== 'siswa' || (int)$c['id_siswa'] !== (int)$id_siswa || (int)$c['id_kelas'] !== $id_kelas) {
                throw new RuntimeException('Anda hanya boleh menghapus komentar sendiri.');
            }
            $kids = $pdo->prepare("SELECT id, file_path FROM tb_diskusi_komentar WHERE parent_id = ?");
            $kids->execute([$id]);
            foreach ($kids->fetchAll(PDO::FETCH_ASSOC) as $kid) {
                $pdo->prepare("DELETE FROM tb_diskusi_suka WHERE target = 'komentar' AND target_id = ?")->execute([(int)$kid['id']]);
                diskusi_delete_file($kid['file_path'] ?? null);
            }
            $pdo->prepare("DELETE FROM tb_diskusi_komentar WHERE parent_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tb_diskusi_suka WHERE target = 'komentar' AND target_id = ?")->execute([$id]);
            diskusi_delete_file($c['file_path'] ?? null);
            $pdo->prepare("DELETE FROM tb_diskusi_komentar WHERE id = ?")->execute([$id]);
            $back_anchor = '#post-' . (int)$c['id_post'];
            $message = ['type' => 'success', 'text' => 'Komentar dihapus.'];
        } elseif ($action === 'like_toggle') {
            $target = ($_POST['target'] ?? 'post') === 'komentar' ? 'komentar' : 'post';
            $target_id = (int)($_POST['target_id'] ?? 0);
            if ($target_id > 0) {
                diskusi_toggle_like($pdo, $target, $target_id, $author_key, 'siswa', 0, (int)$id_siswa);
                $back_anchor = $target === 'post' ? ('#post-' . $target_id) : '';
            }
        }
    } catch (Throwable $e) {
        $message = ['type' => 'danger', 'text' => $e->getMessage()];
    }

    if (!isset($_POST['ajax_like']) && !empty($message) && $message['type'] === 'success') {
        header('Location: diskusi.php' . $back_anchor);
        exit;
    }
}

$st = $pdo->prepare("
    SELECT p.*, g.nama_guru, s.nama_siswa,
           (SELECT COUNT(*) FROM tb_diskusi_komentar c WHERE c.id_post = p.id) AS jml_komentar,
           (SELECT COUNT(*) FROM tb_diskusi_suka l WHERE l.target = 'post' AND l.target_id = p.id) AS jml_suka,
           (SELECT COUNT(*) FROM tb_diskusi_suka l WHERE l.target = 'post' AND l.target_id = p.id AND l.author_key = ?) AS saya_suka
    FROM tb_diskusi_post p
    LEFT JOIN tb_guru g ON g.id_guru = p.id_guru
    LEFT JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    WHERE p.id_kelas = ?
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT 30
");
$st->execute([$author_key, $id_kelas]);
$posts = $st->fetchAll(PDO::FETCH_ASSOC);

$comments_map = [];
if (!empty($posts)) {
    $ids = array_map(static function ($p) { return (int)$p['id']; }, $posts);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st2 = $pdo->prepare("
        SELECT c.*, g.nama_guru, s.nama_siswa,
               (SELECT COUNT(*) FROM tb_diskusi_suka l WHERE l.target = 'komentar' AND l.target_id = c.id) AS jml_suka,
               (SELECT COUNT(*) FROM tb_diskusi_suka l WHERE l.target = 'komentar' AND l.target_id = c.id AND l.author_key = ?) AS saya_suka
        FROM tb_diskusi_komentar c
        LEFT JOIN tb_guru g ON g.id_guru = c.id_guru
        LEFT JOIN tb_siswa s ON s.id_siswa = c.id_siswa
        WHERE c.id_post IN ($in)
        ORDER BY c.created_at ASC, c.id ASC
    ");
    $st2->execute(array_merge([$author_key], $ids));
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $comments_map[(int)$c['id_post']][] = $c;
    }
}

$page_title = 'Diskusi Kelas';
$emoji_js = json_encode(diskusi_emoji_list());
$js_page = [<<<JS
var DISKUSI_EMOJI = $emoji_js;
$(document).on('change', '#diskusiFile', function() {
    var f = this.files && this.files[0];
    if (!f) { $('#diskusiPrev').hide().empty(); return; }
    var box = $('#diskusiPrev').show().empty();
    var url = URL.createObjectURL(f);
    if (f.type.indexOf('image/') === 0) {
        box.html('<img src="' + url + '" style="max-width:100%;border-radius:8px;" alt="pratinjau">');
    } else if (f.type.indexOf('video/') === 0) {
        box.html('<video src="' + url + '" controls style="max-width:100%;border-radius:8px;"></video>');
    } else {
        box.html('<div class="small text-muted"><i class="fas fa-paperclip mr-1"></i>' + $('<div>').text(f.name).html() + '</div>');
    }
});
function diskusiPlacePanel(btn, box) {
    var r = btn[0].getBoundingClientRect();
    var w = 284, h = Math.min(212, Math.max(120, box.outerHeight() || 160));
    var left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));
    var top = r.top - h - 8;
    if (top < 8) top = Math.min(window.innerHeight - h - 8, r.bottom + 8);
    box.css({ left: left + 'px', top: Math.max(8, top) + 'px' });
}
function diskusiInsertEmoji(btn, targetSel) {
    var box = btn.closest('.diskusi-emoji-wrap').find('.diskusi-emoji-panel');
    var willShow = !box.is(':visible');
    $('.diskusi-emoji-panel').hide();
    if (!willShow) return;
    if (!box.data('built')) {
        var html = '';
        DISKUSI_EMOJI.forEach(function(e) { html += '<button type="button" class="diskusi-emoji" data-e="' + e + '" style="font-size:20px;background:none;border:0;padding:4px;">' + e + '</button>'; });
        box.html(html);
        box.data('built', true);
    }
    box.show();
    diskusiPlacePanel(btn, box);
    box.off('click.e').on('click.e', '.diskusi-emoji', function(ev) {
        ev.preventDefault();
        var t = $(targetSel)[0];
        var e = $(this).data('e');
        if (!t) return;
        var s = t.selectionStart || t.value.length, en = t.selectionEnd || t.value.length;
        t.value = t.value.substring(0, s) + e + t.value.substring(en);
        t.focus();
    });
}
$(document).on('click', '.diskusi-emoji-btn', function(e) {
    e.preventDefault();
    e.stopPropagation();
    diskusiInsertEmoji($(this), $(this).data('target'));
});
$(document).on('input', 'textarea.diskusi-pill', function() {
    this.style.height = 'auto';
    this.style.height = (this.scrollHeight) + 'px';
});
$(document).on('click', function(e) {
    if (!$(e.target).closest('.diskusi-emoji-wrap').length) $('.diskusi-emoji-panel').hide();
});
$(window).on('scroll resize', function() { $('.diskusi-emoji-panel').hide(); });
$(document).on('click', '.diskusi-bg-pick', function(e) {
    e.preventDefault();
    var bg = $(this).data('bg');
    $('#diskusiBg').val(bg);
    $('.diskusi-bg-pick').removeClass('active');
    $(this).addClass('active');
    var ta = $('#diskusiIsi');
    if (bg === 'none') {
        ta.css({ background: '#f0f2f5', color: '#111' });
    } else {
        var css = $(this).data('css');
        var dark = String($(this).data('dark')) === '1';
        ta.css({ background: css, color: dark ? '#fff' : '#111' });
    }
});
$(document).on('click', '.diskusi-like', function(e) {
    e.preventDefault();
    var btn = $(this);
    var isPost = (btn.data('target') === 'post');
    $.post('', { ajax_like: 1, target: btn.data('target'), target_id: btn.data('id') }, function(res) {
        if (res && res.ok) {
            btn.find('.like-count').text(res.count);
            if (isPost) {
                btn.toggleClass('liked', !!res.liked);
                btn.find('i').attr('class', res.liked ? 'fas fa-thumbs-up mr-1' : 'far fa-thumbs-up mr-1');
            } else {
                btn.toggleClass('text-primary', !!res.liked).toggleClass('text-muted', !res.liked);
            }
        }
    }, 'json');
});
$(document).on('click', '.diskusi-toggle-komen', function(e) {
    e.preventDefault();
    var box = $($(this).attr('href'));
    if (box.length) { box.toggle(); box.find('input[name="isi"]').focus(); }
});
$(document).on('click', '.diskusi-reply-btn', function(e) {
    e.preventDefault();
    var btn = $(this);
    var postId = btn.data('post');
    var parentId = btn.data('parent');
    var targetName = btn.data('name');
    var form = $('#komen-form-' + postId);
    if (!form.length) return;
    $('#komen-' + postId).show();
    form.find('.diskusi-parent-id').val(parentId);
    var ind = form.find('.diskusi-reply-indicator');
    ind.find('.reply-name').text(targetName);
    ind.removeClass('d-none').addClass('d-flex');
    var input = form.find('input[name="isi"]');
    if (!input.val().trim()) {
        input.val('@' + targetName + ' ');
    }
    input.focus();
});
$(document).on('click', '.cancel-reply', function(e) {
    e.preventDefault();
    var form = $(this).closest('.diskusi-comment-form');
    form.find('.diskusi-parent-id').val(0);
    form.find('.diskusi-reply-indicator').addClass('d-none').removeClass('d-flex');
});
$(document).on('change', '.diskusi-file-komen', function() {
    var f = this.files && this.files[0];
    var form = $(this).closest('.diskusi-comment-form');
    var prev = form.find('.diskusi-komen-file-prev');
    if (f) {
        prev.find('.file-name').text(f.name);
        prev.removeClass('d-none');
    } else {
        prev.addClass('d-none');
    }
});
$(document).on('click', '.clear-file', function(e) {
    e.preventDefault();
    var form = $(this).closest('.diskusi-comment-form');
    form.find('.diskusi-file-komen').val('');
    form.find('.diskusi-komen-file-prev').addClass('d-none');
});
$(document).on('click', '.diskusi-share-btn', function(e) {
    e.preventDefault();
    var postId = $(this).data('post');
    var url = window.location.href.split('#')[0] + '#post-' + postId;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            Swal.fire({ icon: 'success', title: 'Tautan disalin!', text: 'Tautan postingan berhasil disalin.', timer: 1800, showConfirmButton: false });
        });
    } else {
        var temp = $('<input>');
        $('body').append(temp);
        temp.val(url).select();
        document.execCommand('copy');
        temp.remove();
        Swal.fire({ icon: 'success', title: 'Tautan disalin!', text: 'Tautan postingan berhasil disalin.', timer: 1800, showConfirmButton: false });
    }
});
$(document).on('click', '[data-lightbox="1"]', function(e) {
    e.preventDefault();
    var imgUrl = $(this).attr('href');
    Swal.fire({
        imageUrl: imgUrl,
        imageAlt: 'Foto Diskusi',
        showCloseButton: true,
        showConfirmButton: false,
        customClass: { image: 'img-fluid rounded' }
    });
});
JS
];

if (!empty($message)) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
.diskusi-feed { max-width: 680px; margin: 0 auto; }
.diskusi-avatar { width: 44px; height: 44px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; flex-shrink: 0; font-size: 14px; box-shadow: 0 0 0 2px #fff, 0 2px 6px rgba(0,0,0,.15); }
.diskusi-card { border: 0; border-radius: 16px; box-shadow: 0 2px 12px rgba(15,23,42,.08); overflow: hidden; }
.diskusi-composer { border: 0; border-radius: 16px; box-shadow: 0 2px 12px rgba(15,23,42,.08); }
.diskusi-pill { background: #f0f2f5; border-radius: 20px; border: 0; padding: 10px 16px; resize: none; overflow: hidden; font-size: 14px; line-height: 1.45; }
.diskusi-pill:focus { background: #fff; box-shadow: 0 0 0 2px #6777ef33; outline: none; }
.diskusi-actionbar { border-top: 1px solid #eef2f7; }
.diskusi-act { flex: 1; border: 0; background: transparent; padding: 8px 4px; font-weight: 700; font-size: 13px; color: #65676b; border-radius: 8px; }
.diskusi-act:hover { background: #f0f2f5; }
.diskusi-act.liked { color: #1877f2 !important; background: #e7f3ff !important; }
.diskusi-media img, .diskusi-media video { width: 100%; max-height: 420px; object-fit: cover; border-radius: 0; display: block; }
.diskusi-bubble { background: #f0f2f5; border-radius: 16px; }
.diskusi-bubble .diskusi-teks { color: #111 !important; font-size: 14.5px; line-height: 1.55; font-weight: 500; }
.diskusi-nama { color: #111 !important; font-size: 13.5px; }
.diskusi-count { font-size: 12px; color: #65676b; }
.diskusi-status { border-radius: 12px; padding: 18px 16px; text-align: center; font-weight: 800; font-size: 19px; line-height: 1.4; white-space: pre-wrap; word-break: break-word; min-height: 130px; display: flex; align-items: center; justify-content: center; }
.diskusi-bg-pick { width: 30px; height: 30px; border-radius: 8px; border: 2px solid transparent; padding: 0; cursor: pointer; }
.diskusi-bg-pick.active { border-color: #1877f2; box-shadow: 0 0 0 2px #1877f233; }
.diskusi-emoji-wrap { position: relative; }
.diskusi-emoji-panel { display: none; position: fixed; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,.2); padding: 8px; z-index: 9999; width: 284px; max-height: 212px; overflow-y: auto; }
.diskusi-emoji { cursor: pointer; border-radius: 6px; }
.diskusi-emoji:hover { background: #f0f2f5; }
.diskusi-emoji-btn { border: 0; background: transparent; font-size: 20px; }
</style>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Diskusi Kelas <?= htmlspecialchars($student['nama_kelas'] ?? '') ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="diskusi-feed">
            <div class="card mb-3 diskusi-composer">
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="post_add">
                        <div class="d-flex align-items-start" style="gap:10px;">
                            <span class="diskusi-avatar" style="background:<?= htmlspecialchars(diskusi_avatar_color('s_' . $id_siswa)) ?>;"><?= htmlspecialchars(diskusi_initials($nama_siswa)) ?></span>
                            <textarea name="isi" id="diskusiIsi" class="form-control diskusi-pill flex-grow-1" rows="1" placeholder="Apa yang ingin Anda sampaikan?"></textarea>
                            <input type="hidden" name="bg" id="diskusiBg" value="none">
                        </div>
                        <div class="d-flex align-items-center mt-2 flex-wrap" style="gap:6px;">
                            <small class="text-muted font-weight-bold mr-1">Background:</small>
                            <?php foreach (diskusi_bg_list() as $bgk => $bgv): ?>
                                <button type="button" class="diskusi-bg-pick <?= $bgk === 'none' ? 'active' : '' ?>" data-bg="<?= htmlspecialchars($bgk) ?>" data-css="<?= htmlspecialchars($bgv['css']) ?>" data-dark="<?= !empty($bgv['dark']) ? '1' : '0' ?>" title="<?= htmlspecialchars($bgv['label']) ?>" style="background:<?= htmlspecialchars($bgv['css']) ?>;<?= isset($bgv['size']) ? ('background-size:' . htmlspecialchars($bgv['size']) . ';') : '' ?><?= $bgk === 'none' ? 'border-color:#ddd;background:#fff;' : '' ?>"></button>
                            <?php endforeach; ?>
                        </div>
                        <div id="diskusiPrev" class="mt-2" style="display:none;"></div>
                        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 flex-wrap" style="gap:8px;border-top:1px solid #eef2f7;">
                            <div class="d-flex align-items-center" style="gap:10px;">
                                <label class="mb-0 small font-weight-bold text-muted" style="cursor:pointer;">
                                    <i class="fas fa-images mr-1 text-success"></i> Foto/Video
                                    <input type="file" name="file_diskusi" id="diskusiFile" class="d-none" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar">
                                </label>
                                <span class="diskusi-emoji-wrap">
                                    <button type="button" class="diskusi-emoji-btn" data-target="#diskusiIsi" title="Emoticon">😀</button>
                                    <span class="diskusi-emoji-panel"></span>
                                </span>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm px-4" style="border-radius:999px;"><i class="fas fa-paper-plane mr-1"></i> Posting</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (empty($posts)): ?>
                <div class="card diskusi-card"><div class="card-body text-center text-muted py-4"><i class="fas fa-comments fa-2x mb-2"></i><div>Belum ada diskusi. Jadilah yang pertama menyapa kelas.</div></div></div>
            <?php endif; ?>

            <?php foreach ($posts as $p): ?>
                <?php
                $is_guru = ($p['author_role'] === 'guru');
                $aname = $is_guru ? ($p['nama_guru'] ?: 'Guru') : ($p['nama_siswa'] ?: 'Siswa');
                $furl = diskusi_file_url($p['file_path'] ?? null);
                $fk = $p['file_kind'] ?? 'none';
                $own_post = (!$is_guru && (int)$p['id_siswa'] === (int)$id_siswa);
                $komen = $comments_map[(int)$p['id']] ?? [];
                $liked = ((int)$p['saya_suka'] > 0);
                ?>
                <div class="card mb-3 diskusi-card" id="post-<?= (int)$p['id'] ?>">
                    <div class="card-body pb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center" style="gap:10px;">
                                <span class="diskusi-avatar" style="background:<?= htmlspecialchars(diskusi_avatar_color(($is_guru ? 'g_' : 's_') . ($is_guru ? (int)$p['id_guru'] : (int)$p['id_siswa']))) ?>;"><?= htmlspecialchars(diskusi_initials($aname)) ?></span>
                                <div style="line-height:1.25;">
                                    <div class="font-weight-bold" style="font-size:14px;"><?= htmlspecialchars($aname) ?>
                                        <?php if ($is_guru): ?><i class="fas fa-check-circle text-primary ml-1" title="Guru terverifikasi"></i><?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?= function_exists('timeAgo') ? htmlspecialchars(timeAgo($p['created_at'])) : htmlspecialchars($p['created_at']) ?></small>
                                </div>
                            </div>
                            <div class="d-flex align-items-center" style="gap:6px;">
                                <span class="badge badge-<?= $is_guru ? 'primary' : 'info' ?>"><?= $is_guru ? 'Guru' : 'Siswa' ?></span>
                                <?php if ($own_post): ?>
                                <form method="POST" onsubmit="return confirm('Hapus postingan ini?')">
                                    <input type="hidden" name="action" value="post_delete">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-sm btn-link text-muted p-1" title="Hapus"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php
                        [$bg_key, $bg_val] = diskusi_bg_style($p['bg'] ?? 'none');
                        $bg_dark = !empty($bg_val['dark']);
                        $bg_css = (string)($bg_val['css'] ?? '#ffffff');
                        $bg_size = isset($bg_val['size']) ? ('background-size:' . $bg_val['size'] . ';') : '';
                        ?>
                        <?php if (trim((string)$p['isi']) !== ''): ?>
                            <?php if ($bg_key !== 'none' && empty($furl)): ?>
                                <div class="diskusi-status mt-2" style="background:<?= htmlspecialchars($bg_css) ?>;<?= $bg_size ?>color:<?= $bg_dark ? '#fff' : '#111' ?>;<?= $bg_dark ? 'text-shadow:0 1px 3px rgba(0,0,0,.35);' : '' ?>"><?= nl2br(htmlspecialchars($p['isi'])) ?></div>
                            <?php else: ?>
                                <div class="mt-2" style="white-space:pre-wrap;font-size:15px;line-height:1.55;"><?= nl2br(htmlspecialchars($p['isi'])) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($furl): ?>
                        <div class="diskusi-media">
                            <?php if ($fk === 'image'): ?>
                                <a href="<?= htmlspecialchars($furl) ?>" data-lightbox="1" target="_blank"><img src="<?= htmlspecialchars($furl) ?>" alt="lampiran"></a>
                            <?php elseif ($fk === 'video'): ?>
                                <video src="<?= htmlspecialchars($furl) ?>" controls></video>
                            <?php else: ?>
                                <div class="mx-3 mb-2 p-2 rounded d-flex align-items-center" style="background:#f0f2f5;gap:10px;">
                                    <span class="d-inline-flex align-items-center justify-content-center" style="width:38px;height:38px;border-radius:10px;background:#fff;color:#1877f2;"><i class="fas fa-file-alt"></i></span>
                                    <span class="small font-weight-bold flex-grow-1 text-truncate"><?= htmlspecialchars($p['file_name'] ?: 'Lampiran') ?></span>
                                    <a href="<?= htmlspecialchars($furl) ?>" target="_blank" class="btn btn-sm btn-primary" style="border-radius:999px;">Unduh</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="px-3 py-1 d-flex justify-content-between diskusi-count">
                        <span><i class="fas fa-thumbs-up text-primary mr-1"></i><?= (int)$p['jml_suka'] ?> suka</span>
                        <span><?= (int)$p['jml_komentar'] ?> komentar</span>
                    </div>

                    <div class="mx-3 mb-1 diskusi-actionbar d-flex">
                        <button type="button" class="diskusi-act diskusi-like <?= $liked ? 'liked' : '' ?>" data-target="post" data-id="<?= (int)$p['id'] ?>">
                            <i class="<?= $liked ? 'fas' : 'far' ?> fa-thumbs-up mr-1"></i> Suka (<span class="like-count"><?= (int)$p['jml_suka'] ?></span>)
                        </button>
                        <a href="#komen-<?= (int)$p['id'] ?>" class="diskusi-act text-center diskusi-toggle-komen" style="text-decoration:none;"><i class="far fa-comment mr-1"></i> Komentar</a>
                        <button type="button" class="diskusi-act diskusi-share-btn text-center" data-post="<?= (int)$p['id'] ?>"><i class="far fa-share-square mr-1"></i> Bagikan</button>
                    </div>

                    <div id="komen-<?= (int)$p['id'] ?>" class="px-3 pb-3">
                        <div class="diskusi-comment-list mb-2">
                            <?= diskusi_render_comments_tree($komen, (int)$p['id'], $id_kelas, [$id_kelas], (int)$id_siswa, 'siswa') ?>
                        </div>
                        <form method="POST" enctype="multipart/form-data" class="diskusi-comment-form" id="komen-form-<?= (int)$p['id'] ?>">
                            <input type="hidden" name="action" value="comment_add">
                            <input type="hidden" name="id_post" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="parent_id" class="diskusi-parent-id" value="0">
                            
                            <div class="diskusi-reply-indicator small font-weight-bold text-primary mb-1 d-none align-items-center" style="gap:6px; background:#eef2ff; padding:4px 10px; border-radius:8px;">
                                <span><i class="fas fa-reply mr-1"></i> Membalas <span class="reply-name text-dark"></span></span>
                                <button type="button" class="btn btn-link btn-sm text-danger p-0 ml-auto cancel-reply" title="Batal Balas" style="font-size:12px;text-decoration:none;border:0;"><i class="fas fa-times"></i> Batal</button>
                            </div>

                            <div class="diskusi-komen-file-prev small text-muted mb-1 d-none" style="background:#f8fafc; padding:4px 8px; border-radius:6px;">
                                <i class="fas fa-paperclip text-primary mr-1"></i> <span class="file-name"></span>
                                <button type="button" class="btn btn-link btn-sm text-danger p-0 ml-1 clear-file" style="font-size:11px;border:0;">&times;</button>
                            </div>

                            <div class="d-flex align-items-center diskusi-emoji-wrap" style="gap:6px;">
                                <span class="diskusi-avatar" style="width:32px;height:32px;font-size:12px;background:<?= htmlspecialchars(diskusi_avatar_color('s_' . $id_siswa)) ?>;"><?= htmlspecialchars(diskusi_initials($nama_siswa)) ?></span>
                                <input type="text" name="isi" id="komen-isi-s-<?= (int)$p['id'] ?>" class="form-control form-control-sm diskusi-pill" placeholder="Tulis komentar atau balasan..." required maxlength="1000">
                                
                                <label class="mb-0 text-muted p-1" style="cursor:pointer;" title="Lampirkan foto/file">
                                    <i class="fas fa-paperclip" style="font-size:16px;"></i>
                                    <input type="file" name="file_komen" class="d-none diskusi-file-komen" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar">
                                </label>

                                <span style="position:relative;">
                                    <button type="button" class="diskusi-emoji-btn" data-target="#komen-isi-s-<?= (int)$p['id'] ?>" title="Emoticon">😀</button>
                                    <span class="diskusi-emoji-panel"></span>
                                </span>
                                
                                <button type="submit" class="btn btn-sm btn-primary" style="border-radius:50%;width:32px;height:32px;padding:0;flex-shrink:0;" title="Kirim"><i class="fas fa-paper-plane" style="font-size:12px;"></i></button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
