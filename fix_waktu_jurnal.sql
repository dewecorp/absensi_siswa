-- PERBAIKI SEMUA WAKTU JURNAL LES YANG SALAH
-- Copy semua script ini dan jalankan di phpMyAdmin > tab SQL

-- 1. Perbaiki semua waktu yang salah (13.00 - 13.00) dengan mengambil dari jadwal_les
UPDATE tb_jurnal_les jl
INNER JOIN tb_jadwal_les j ON jl.id_guru = j.id_guru AND jl.tanggal = j.tanggal
SET jl.waktu = CONCAT(TIME_FORMAT(j.waktu_mulai, '%H:%i'), ' - ', TIME_FORMAT(j.waktu_selesai, '%H:%i'))
WHERE jl.waktu LIKE '%.%. - %.%';

-- 2. Cek hasil
SELECT id, tanggal, waktu, mapel FROM tb_jurnal_les ORDER BY tanggal DESC LIMIT 20;

