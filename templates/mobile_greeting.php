<?php
// Box sambutan mobile: salam | nama | jabatan, rata kiri + siluet masjid.
// Variabel dari dashboard: $mapp_greet_name, $mapp_greet_role.
$__greet_name = isset($mapp_greet_name) ? trim((string)$mapp_greet_name) : '';
if ($__greet_name === '') $__greet_name = 'Pengguna';
$__greet_role = isset($mapp_greet_role) ? trim((string)$mapp_greet_role) : '';
?>
<div class="d-lg-none mapp-greetbox">
    <div class="mapp-greet-salam">Assalamu&rsquo;alaikum,</div>
    <div class="mapp-greet-nama"><?php echo htmlspecialchars($__greet_name, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php if ($__greet_role !== ''): ?>
        <div class="mapp-greet-role"><?php echo htmlspecialchars($__greet_role, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
</div>
