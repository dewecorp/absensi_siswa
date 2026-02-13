<?php
require_once '../config/database.php';
require_once '../config/functions.php';

header('Content-Type: application/json');

if (!isAuthorized(['admin', 'tata_usaha', 'kepala_madrasah', 'guru', 'wali', 'siswa'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

// Restricted actions for Admin and TU only
if (in_array($action, ['create', 'update', 'delete'])) {
    if (!isAuthorized(['admin', 'tata_usaha'])) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized for this action']);
        exit;
    }
}

switch ($action) {
    case 'fetch':
        try {
            $tahun_filter = $_GET['tahun_ajaran'] ?? '';
            $query = "SELECT * FROM tb_kalender_pendidikan";
            $params = [];
            
            if ($tahun_filter) {
                $query .= " WHERE tahun_ajaran = ?";
                $params[] = $tahun_filter;
            }
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            
            $events = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // FullCalendar end date is exclusive, add 1 day for display if it's a range
                $endDate = new DateTime($row['tgl_selesai']);
                $endDate->modify('+1 day');
                
                // Map bootstrap classes to colors
                $color_map = [
                    'danger'  => '#fc544b', // Red
                    'success' => '#47c363', // Green
                    'primary' => '#6777ef', // Blue
                    'warning' => '#ffa426', // Yellow
                    'info'    => '#3abaf4'  // Light Blue
                ];
                $bg_color = $color_map[$row['warna']] ?? $color_map['info'];
                
                $events[] = [
                    'id' => $row['id_kalender'],
                    'title' => $row['nama_kegiatan'],
                    'start' => $row['tgl_mulai'],
                    'end' => $endDate->format('Y-m-d'),
                    'allDay' => true,
                    'backgroundColor' => $bg_color,
                    'borderColor' => $bg_color,
                    'extendedProps' => [
                        'nama_kegiatan' => $row['nama_kegiatan'],
                        'tahun_ajaran' => $row['tahun_ajaran'],
                        'warna' => $row['warna']
                    ]
                ];
            }
            echo json_encode($events);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'get_stats':
        $tahun_ajaran = $_GET['tahun_ajaran'] ?? '';
        if (empty($tahun_ajaran)) {
            echo json_encode(['status' => 'error', 'message' => 'Tahun ajaran harus dipilih']);
            break;
        }

        // Split tahun ajaran (e.g., 2025/2026)
        $years = explode('/', $tahun_ajaran);
        $year1 = $years[0];
        $year2 = $years[1] ?? ($year1 + 1);

        $semesters = [
            1 => ['start' => "$year1-07-01", 'end' => "$year1-12-31"],
            2 => ['start' => "$year2-01-01", 'end' => "$year2-06-30"]
        ];

        $stats = [];
        foreach ($semesters as $sem => $range) {
            $start = new DateTime($range['start']);
            $end = new DateTime($range['end']);
            $total_days = $start->diff($end)->days + 1;

            // Count red days (libur) from database for this semester
            $stmt = $pdo->prepare("SELECT tgl_mulai, tgl_selesai FROM tb_kalender_pendidikan 
                                  WHERE warna = 'danger' AND tahun_ajaran = ? 
                                  AND ((tgl_mulai BETWEEN ? AND ?) OR (tgl_selesai BETWEEN ? AND ?))");
            $stmt->execute([$tahun_ajaran, $range['start'], $range['end'], $range['start'], $range['end']]);
            $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $holiday_dates = [];
            foreach ($holidays as $h) {
                $h_start = new DateTime(max($h['tgl_mulai'], $range['start']));
                $h_end = new DateTime(min($h['tgl_selesai'], $range['end']));
                
                $period = new DatePeriod($h_start, new DateInterval('P1D'), $h_end->modify('+1 day'));
                foreach ($period as $date) {
                    $holiday_dates[$date->format('Y-m-d')] = true;
                }
            }
            
            // Also count Fridays as holidays if not already in holiday_dates
            $period_total = new DatePeriod($start, new DateInterval('P1D'), (new DateTime($range['end']))->modify('+1 day'));
            foreach ($period_total as $date) {
                if ($date->format('N') == 5) { // 5 is Friday
                    $holiday_dates[$date->format('Y-m-d')] = true;
                }
            }

            $holiday_count = count($holiday_dates);
            $effective_days = $total_days - $holiday_count;

            $stats["semester_$sem"] = [
                'total' => $total_days,
                'holiday' => $holiday_count,
                'effective' => $effective_days
            ];
        }

        echo json_encode($stats);
        break;

    case 'create':
        $nama = $_POST['nama_kegiatan'] ?? '';
        $mulai = $_POST['tgl_mulai'] ?? '';
        $durasi = $_POST['durasi'] ?? '1';
        $selesai = ($durasi === 'more') ? ($_POST['tgl_selesai'] ?? $mulai) : $mulai;
        $tahun = $_POST['tahun_ajaran'] ?? '';
        $warna = $_POST['warna'] ?? 'info';

        if ($nama && $mulai && $tahun) {
            try {
                $stmt = $pdo->prepare("INSERT INTO tb_kalender_pendidikan (nama_kegiatan, tgl_mulai, tgl_selesai, tahun_ajaran, warna) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$nama, $mulai, $selesai, $tahun, $warna]);
                echo json_encode(['status' => 'success', 'message' => 'Kegiatan berhasil ditambahkan']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap']);
        }
        break;

    case 'update':
        $id = $_POST['id_kalender'] ?? '';
        $nama = $_POST['nama_kegiatan'] ?? '';
        $mulai = $_POST['tgl_mulai'] ?? '';
        $durasi = $_POST['durasi'] ?? '1';
        $selesai = ($durasi === 'more') ? ($_POST['tgl_selesai'] ?? $mulai) : $mulai;
        $tahun = $_POST['tahun_ajaran'] ?? '';
        $warna = $_POST['warna'] ?? 'info';

        if ($id && $nama && $mulai && $tahun) {
            try {
                $stmt = $pdo->prepare("UPDATE tb_kalender_pendidikan SET nama_kegiatan = ?, tgl_mulai = ?, tgl_selesai = ?, tahun_ajaran = ?, warna = ? WHERE id_kalender = ?");
                $stmt->execute([$nama, $mulai, $selesai, $tahun, $warna, $id]);
                echo json_encode(['status' => 'success', 'message' => 'Kegiatan berhasil diperbarui']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap']);
        }
        break;

    case 'delete':
        $id = $_POST['id_kalender'] ?? '';
        if ($id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM tb_kalender_pendidikan WHERE id_kalender = ?");
                $stmt->execute([$id]);
                echo json_encode(['status' => 'success', 'message' => 'Kegiatan berhasil dihapus']);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ID tidak ditemukan']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid']);
        break;
}
