<?php
/**
 * Ringkasan data Gaji & Honor (modul Gaji).
 *
 * Dipakai bersama oleh:
 *  - Halaman "Ringkasan Gaji" (bendahara/gaji/pages/dashboard.php)
 *  - Dashboard Bendahara (bendahara/dashboard.php)
 *
 * supaya angka di dua tempat selalu sama.
 */

if (!function_exists('gaji_periode_label')) {
    function gaji_periode_label($periode) {
        $bulan = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
            '04' => 'April', '05' => 'Mei', '06' => 'Juni',
            '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
            '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];

        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $periode, $matches)) {
            return ($bulan[$matches[2]] ?? $matches[2]) . ' ' . $matches[1];
        }

        return (string) $periode;
    }
}

if (!function_exists('gaji_ringkasan_cards')) {
    /**
     * Definisi kartu ringkasan gaji & honor.
     * Satu sumber untuk halaman "Ringkasan Gaji" dan Dashboard Bendahara,
     * memakai komponen kartu ringkasan yang sama dengan kartu lain di SIMAD.
     *
     * @return array<int, array{label:string, value:string, icon:string, tone:string, sub:string}>
     */
    function gaji_ringkasan_cards(array $stats): array {
        $rp = function ($value) {
            if (function_exists('formatRupiah')) {
                return formatRupiah($value);
            }
            return 'Rp ' . number_format((float) $value, 0, ',', '.');
        };

        return [
            [
                'label' => 'Total Guru',
                'value' => number_format((int) ($stats['total_guru'] ?? 0), 0, ',', '.'),
                'icon' => 'fa-users',
                'tone' => 'indigo',
                'sub' => 'guru terdaftar',
            ],
            [
                'label' => 'Total Gaji Pokok',
                'value' => $rp($stats['total_gaji_pokok'] ?? 0),
                'icon' => 'fa-money-bill-wave',
                'tone' => 'blue',
                'sub' => 'dari data gaji pokok',
            ],
            [
                'label' => 'Total Tunjangan',
                'value' => $rp($stats['total_tunjangan'] ?? 0),
                'icon' => 'fa-wallet',
                'tone' => 'teal',
                'sub' => 'tunjangan aktif',
            ],
            [
                'label' => 'Total Potongan',
                'value' => $rp($stats['total_potongan'] ?? 0),
                'icon' => 'fa-minus-circle',
                'tone' => 'orange',
                'sub' => 'potongan aktif',
            ],
            [
                'label' => 'Total Gaji Bersih',
                'value' => $rp($stats['total_gaji_bersih'] ?? 0),
                // Catatan: "fa-bank" hanya ada di Font Awesome Pro,
                // sedangkan SIMAD memakai Font Awesome 5.7.2 (free) -> ikon kosong.
                'icon' => 'fa-hand-holding-usd',
                'tone' => 'green',
                'sub' => 'pokok + tunjangan - potongan',
            ],
            [
                'label' => 'Total Pengeluaran',
                'value' => $rp($stats['total_pengeluaran'] ?? 0),
                'icon' => 'fa-receipt',
                'tone' => 'rose',
                'sub' => 'gaji + honor + insentif',
            ],
            [
                'label' => 'Total Insentif',
                'value' => $rp($stats['total_insentif'] ?? 0),
                'icon' => 'fa-gift',
                'tone' => 'violet',
                'sub' => 'insentif guru',
            ],
            [
                'label' => 'Total Honor',
                'value' => $rp($stats['total_honor'] ?? 0),
                'icon' => 'fa-award',
                'tone' => 'amber',
                'sub' => 'honor pembina & ekstrakurikuler',
            ],
        ];
    }
}

if (!function_exists('gaji_ringkasan_data')) {
    /**
     * @return array{stats: array, periode_aktif: string, periode_aktif_label: string, jumlah_periode: int, chart_periods: array, chart_totals: array}
     */
    function gaji_ringkasan_data(mysqli $conn): array {
        $stats = [];

        // Total guru
        $result = $conn->query("SELECT COUNT(*) as total FROM guru");
        $stats['total_guru'] = $result ? (int) ($result->fetch_assoc()['total'] ?? 0) : 0;

        // Periode aktif dari settings
        $result = $conn->query("SELECT periode_aktif, jumlah_periode, periode_mulai, periode_akhir FROM settings LIMIT 1");
        $settings = $result ? $result->fetch_assoc() : [];
        $periode_aktif = $settings['periode_aktif'] ?? date('Y-m');
        $jumlah_periode = isset($settings['jumlah_periode']) ? (int) $settings['jumlah_periode'] : 1;
        $periode_mulai = $settings['periode_mulai'] ?? '';
        $periode_akhir = $settings['periode_akhir'] ?? '';

        // Daftar periode yang dipakai query
        $periode_list = [];
        if ($jumlah_periode > 1 && !empty($periode_mulai) && !empty($periode_akhir)) {
            $start = new DateTime($periode_mulai . '-01');
            $end = new DateTime($periode_akhir . '-01');
            while ($start <= $end) {
                $periode_list[] = $start->format('Y-m');
                $start->modify('+1 month');
            }
        } else {
            $periode_list[] = $periode_aktif;
        }

        $periode_placeholders = implode(',', array_fill(0, count($periode_list), '?'));
        $periode_types = str_repeat('s', count($periode_list));

        // Statistik dari legger_gaji
        $sql = "SELECT
                COALESCE(SUM(gaji_pokok), 0) as total_gaji_pokok,
                COALESCE(SUM(total_tunjangan), 0) as total_tunjangan,
                COALESCE(SUM(total_potongan), 0) as total_potongan,
                COALESCE(SUM(gaji_bersih), 0) as total_gaji_bersih
                FROM legger_gaji
                WHERE periode IN ($periode_placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($periode_types, ...$periode_list);
        $stmt->execute();
        $legger_stats = $stmt->get_result()->fetch_assoc();

        $sql_check = "SELECT COUNT(*) as count FROM legger_gaji WHERE periode IN ($periode_placeholders)";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param($periode_types, ...$periode_list);
        $stmt_check->execute();
        $legger_exists = ((int) ($stmt_check->get_result()->fetch_assoc()['count'] ?? 0)) > 0;
        $stmt_check->close();

        if ($legger_exists && $legger_stats) {
            $stats['total_gaji_pokok'] = (float) ($legger_stats['total_gaji_pokok'] ?? 0);
            $stats['total_tunjangan'] = (float) ($legger_stats['total_tunjangan'] ?? 0);
            $stats['total_potongan'] = (float) ($legger_stats['total_potongan'] ?? 0);
            $stats['total_gaji_bersih'] = (float) ($legger_stats['total_gaji_bersih'] ?? 0);
            $stmt->close();
        } else {
            $stmt->close();

            // Gaji pokok: jumlahkan baris "berjalan" tiap guru (tanpa menghitung riwayat per-periode)
            $total_pokok = 0;
            $guru_pokok = $conn->query("SELECT id FROM guru ORDER BY id");
            if ($guru_pokok) {
                while ($g_row = $guru_pokok->fetch_assoc()) {
                    $total_pokok += gaji_ambil_gaji_pokok_modul($conn, (int) $g_row['id'], $periode_aktif ?? '');
                }
                $guru_pokok->free();
            }
            $stats['total_gaji_pokok'] = $total_pokok * $jumlah_periode;

            $sql = "SELECT COALESCE(SUM(td.jumlah), 0) as total
                    FROM tunjangan_detail td
                    JOIN tunjangan t ON td.tunjangan_id = t.id
                    WHERE td.periode IN ($periode_placeholders)
                    AND t.aktif = 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($periode_types, ...$periode_list);
            $stmt->execute();
            $stats['total_tunjangan'] = ((float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0)) * $jumlah_periode;
            $stmt->close();

            $sql = "SELECT COALESCE(SUM(pd.jumlah), 0) as total
                    FROM potongan_detail pd
                    JOIN potongan p ON pd.potongan_id = p.id
                    WHERE pd.periode IN ($periode_placeholders)
                    AND p.aktif = 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($periode_types, ...$periode_list);
            $stmt->execute();
            $stats['total_potongan'] = ((float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0)) * $jumlah_periode;
            $stmt->close();

            $stats['total_gaji_bersih'] = $stats['total_gaji_pokok'] + $stats['total_tunjangan'] - $stats['total_potongan'];
        }

        // Insentif
        $stats['total_insentif'] = 0;
        $check_insentif = $conn->query("SHOW TABLES LIKE 'insentif_detail'");
        if ($check_insentif && $check_insentif->num_rows > 0) {
            $check_master = $conn->query("SHOW TABLES LIKE 'insentif'");
            if ($check_master && $check_master->num_rows > 0) {
                $result_insentif = $conn->query("SELECT COALESCE(SUM(idt.jumlah), 0) as total FROM insentif_detail idt JOIN insentif i ON idt.insentif_id = i.id WHERE i.aktif = 1");
            } else {
                $result_insentif = $conn->query("SELECT COALESCE(SUM(jumlah), 0) as total FROM insentif_detail");
            }
            if ($result_insentif) {
                $stats['total_insentif'] = (float) ($result_insentif->fetch_assoc()['total'] ?? 0);
            }
        }

        // Honor (dari legger honor)
        $stats['total_honor'] = 0;
        $check_honor = $conn->query("SHOW TABLES LIKE 'legger_honor'");
        if ($check_honor && $check_honor->num_rows > 0) {
            $sql_honor = "SELECT COALESCE(SUM(total_honor), 0) as total FROM legger_honor WHERE periode IN ($periode_placeholders)";
            $stmt_honor = $conn->prepare($sql_honor);
            $stmt_honor->bind_param($periode_types, ...$periode_list);
            $stmt_honor->execute();
            $result_honor = $stmt_honor->get_result();
            if ($result_honor) {
                $stats['total_honor'] = (float) ($result_honor->fetch_assoc()['total'] ?? 0);
            }
            $stmt_honor->close();
        }

        $stats['total_pengeluaran'] = (float) ($stats['total_gaji_bersih'] ?? 0)
            + (float) ($stats['total_honor'] ?? 0)
            + (float) ($stats['total_insentif'] ?? 0);

        // Grafik total gaji per periode
        $chart_periods = [];
        $chart_totals = [];
        $result = $conn->query("SELECT periode, SUM(gaji_bersih) as total FROM legger_gaji GROUP BY periode ORDER BY periode ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $chart_periods[] = $row['periode'];
                $chart_totals[] = (float) $row['total'];
            }
        }

        // Label periode
        if ($jumlah_periode > 1 && !empty($periode_mulai) && !empty($periode_akhir)) {
            $periode_aktif_label = gaji_periode_label($periode_mulai) . ' - ' . gaji_periode_label($periode_akhir);
        } else {
            $periode_aktif_label = gaji_periode_label($periode_aktif);
        }

        return [
            'stats' => $stats,
            'periode_aktif' => $periode_aktif,
            'periode_aktif_label' => $periode_aktif_label,
            'jumlah_periode' => $jumlah_periode,
            'chart_periods' => $chart_periods,
            'chart_totals' => $chart_totals,
        ];
    }
}
