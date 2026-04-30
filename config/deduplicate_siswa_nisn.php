<?php
/**
 * Gabungan baris duplikat di tb_siswa menurut NISN (sama Trim atau nilai UNSIGNED),
 * pindahkan relasi child ke satu id_siswa (terkecil), hapus siswa sisanya.
 *
 * Pemakaian (CLI dari folder proyek):
 *   php config/deduplicate_siswa_nisn.php           # pratinjau
 *   php config/deduplicate_siswa_nisn.php --apply # eksekusi
 *   php config/deduplicate_siswa_nisn.php --apply --add-unique # + indeks UNIQUE(nisn)
 */

require_once __DIR__ . '/database.php';

$apply = in_array('--apply', $argv ?? [], true);
$addUnique = in_array('--add-unique', $argv ?? [], true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Jalankan dari CLI: php config/deduplicate_siswa_nisn.php [--apply] [--add-unique]\n");
    exit(1);
}

/** @return string[] */
function siswa_tables_with_column(PDO $pdo): array {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $sql = "SELECT DISTINCT c.TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS c
            INNER JOIN INFORMATION_SCHEMA.TABLES t
              ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = ?
              AND t.TABLE_TYPE = 'BASE TABLE'
              AND c.COLUMN_NAME = 'id_siswa'
              AND c.TABLE_NAME <> 'tb_siswa'
            ORDER BY c.TABLE_NAME";
    $st = $pdo->prepare($sql);
    $st->execute([$db]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function delete_nilai_semester_conflicts_for_merge(PDO $pdo, int $keeper, int $loser): void {
    $sql = 'DELETE ns_loser FROM tb_nilai_semester ns_loser
            INNER JOIN tb_nilai_semester ns_keep ON
              ns_keep.id_siswa = ? AND ns_loser.id_siswa = ?
              AND ns_keep.id_mapel = ns_loser.id_mapel
              AND ns_keep.jenis_semester = ns_loser.jenis_semester
              AND ns_keep.tahun_ajaran = ns_loser.tahun_ajaran
              AND ns_keep.semester = ns_loser.semester';
    $pdo->prepare($sql)->execute([$keeper, $loser]);
}

function delete_nilai_harian_detail_conflicts_for_merge(PDO $pdo, int $keeper, int $loser): void {
    $sql = 'DELETE d FROM tb_nilai_harian_detail d
            INNER JOIN tb_nilai_harian_detail k ON
              k.id_header = d.id_header AND k.id_siswa = ? AND d.id_siswa = ?';
    $pdo->prepare($sql)->execute([$keeper, $loser]);
}

/** @param int[] $losers */
function merge_siswa_group(PDO $pdo, int $keeper, array $losers, array $tables, bool $apply): array {
    sort($losers, SORT_NUMERIC);
    $log = [];

    foreach ($losers as $loser) {
        $loser = (int) $loser;
        if ($loser === $keeper) {
            continue;
        }
        $log[] = "  Gabungkan id_siswa {$loser} -> {$keeper}";

        if (!$apply) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            if (in_array('tb_nilai_harian_detail', $tables, true)) {
                delete_nilai_harian_detail_conflicts_for_merge($pdo, $keeper, $loser);
            }
            if (in_array('tb_nilai_semester', $tables, true)) {
                delete_nilai_semester_conflicts_for_merge($pdo, $keeper, $loser);
            }

            foreach ($tables as $table) {
                $t = '`' . str_replace('`', '``', $table) . '`';
                $pdo->exec(
                    "UPDATE {$t} SET id_siswa = " . (int) $keeper
                    . " WHERE id_siswa = " . $loser
                );
            }

            $pdo->prepare('DELETE FROM tb_siswa WHERE id_siswa = ?')->execute([$loser]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    return $log;
}

function count_nisn_needs_trim(PDO $pdo): int {
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM tb_siswa WHERE nisn <> TRIM(nisn)'
    )->fetchColumn();
}

function normalize_trim_all_nisn_execute(PDO $pdo): void {
    $pdo->exec('UPDATE tb_siswa SET nisn = TRIM(nisn) WHERE nisn <> TRIM(nisn)');
}

function dedupe_child_absensi_dates_execute(PDO $pdo): void {
    $sqlDel = '
        DELETE dup FROM tb_absensi dup
        INNER JOIN tb_absensi keep ON
          keep.id_siswa = dup.id_siswa
          AND keep.tanggal = dup.tanggal
          AND keep.id_absensi < dup.id_absensi';
    $sqlDelSh = '
        DELETE dup FROM tb_sholat dup
        INNER JOIN tb_sholat keep ON
          keep.id_siswa = dup.id_siswa
          AND keep.tanggal = dup.tanggal
          AND keep.id_sholat < dup.id_sholat';
    $sqlDelDh = '
        DELETE dup FROM tb_sholat_dhuha dup
        INNER JOIN tb_sholat_dhuha keep ON
          keep.id_siswa = dup.id_siswa
          AND keep.tanggal = dup.tanggal
          AND keep.id_sholat < dup.id_sholat';

    foreach ([$sqlDel => 'tb_absensi', $sqlDelSh => 'tb_sholat', $sqlDelDh => 'tb_sholat_dhuha'] as $sql => $tbl) {
        if ($pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl))->fetch()) {
            $pdo->exec($sql);
        }
    }
}

function fetch_numeric_duplicate_groups(PDO $pdo): array {
    $sql = '
        SELECT MIN(id_siswa) AS keeper,
               GROUP_CONCAT(id_siswa ORDER BY id_siswa SEPARATOR ",") AS ids
        FROM tb_siswa
        WHERE LENGTH(TRIM(nisn)) > 0 AND TRIM(nisn) REGEXP "^[0-9]+$"
        GROUP BY CAST(TRIM(nisn) AS UNSIGNED)
        HAVING COUNT(*) > 1';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_trim_duplicate_groups(PDO $pdo): array {
    $sql = '
        SELECT MIN(id_siswa) AS keeper,
               GROUP_CONCAT(id_siswa ORDER BY id_siswa SEPARATOR ",") AS ids
        FROM tb_siswa
        WHERE LENGTH(TRIM(nisn)) > 0
        GROUP BY TRIM(nisn)
        HAVING COUNT(*) > 1';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** @param array{array{keeper:string|int,ids:string}} $groups */
function process_duplicate_groups(PDO $pdo, array $groups, array $tables, bool $apply): int {
    $merged = 0;
    foreach ($groups as $g) {
        $idsList = explode(',', $g['ids']);
        $idsInt = array_map('intval', $idsList);
        $keeper = (int) $g['keeper'];
        $losers = array_values(array_diff($idsInt, [$keeper]));
        if (!$losers) {
            continue;
        }

        echo "Grup NISN: id_siswa=(" . implode(',', $idsInt) . ") simpan pk={$keeper}\n";
        $lines = merge_siswa_group($pdo, $keeper, $losers, $tables, $apply);
        foreach ($lines as $line) {
            echo $line . "\n";
            $merged++;
        }
        echo "\n";
    }
    return $merged;
}

// --- utama ---

$tables = siswa_tables_with_column($pdo);
echo 'Tabel yang punya kolom id_siswa (selain tb_siswa): ' . implode(', ', $tables) . "\n\n";

$cTrim = count_nisn_needs_trim($pdo);
echo "Baris dengan nisn perlu TRIM (spasi tepi): {$cTrim}\n";

$nGroups = fetch_numeric_duplicate_groups($pdo);
$tGroups = fetch_trim_duplicate_groups($pdo);
echo 'Grup duplikat (setara angka UNSIGNED): ' . count($nGroups) . "\n";
echo 'Grup duplikat (TRIM sama): ' . count($tGroups) . "\n";

if (!$apply) {
    echo "\nPratinjau: baris rangkap pada absensi/sholat per (id_siswa,tanggal) bisa dibersihkan setelah --apply.\n";
    foreach ($nGroups as $g) {
        echo " [angka] simpan {$g['keeper']}, ids {$g['ids']}\n";
    }
    foreach ($tGroups as $g) {
        echo " [trim] simpan {$g['keeper']}, ids {$g['ids']}\n";
    }
    echo "\nGunakan --apply untuk menggabungkan data.\n";
    exit(0);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    normalize_trim_all_nisn_execute($pdo);

    $done = process_duplicate_groups($pdo, fetch_numeric_duplicate_groups($pdo), $tables, true);
    $done += process_duplicate_groups($pdo, fetch_trim_duplicate_groups($pdo), $tables, true);

    dedupe_child_absensi_dates_execute($pdo);

    echo "--- Selesai. Total operasi gabung loser->keeper: {$done} ---\n";
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

if (!$addUnique) {
    echo "Opsional: php config/deduplicate_siswa_nisn.php --apply --add-unique\n";
    exit(0);
}

$idxExists = $pdo->query(
    "SHOW INDEX FROM tb_siswa WHERE Key_name = 'uk_tb_siswa_nisn'"
)->fetch();
if ($idxExists) {
    echo "Indeks uk_tb_siswa_nisn sudah ada.\n";
    exit(0);
}

$remain = fetch_numeric_duplicate_groups($pdo);
$tremain = fetch_trim_duplicate_groups($pdo);
if (count($remain) || count($tremain)) {
    fwrite(STDERR, "Masih ada duplikat NISN — tidak bisa menambah UNIQUE. Periksa data.\n");
    exit(1);
}

$pdo->exec('ALTER TABLE tb_siswa ADD UNIQUE KEY uk_tb_siswa_nisn (nisn)');
echo "ALTER TABLE tb_siswa UNIQUE KEY uk_tb_siswa_nisn ditambahkan.\n";
