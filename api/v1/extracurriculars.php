<?php
/**
 * Extracurricular Data API Endpoint (Central Hub)
 * Sinkronisasi data ekstrakurikuler + pembina + anggota SIMAD ke aplikasi eksternal.
 *
 * Query params opsional:
 *   id_ekstrakurikuler - filter satu ekskul
 *   with_members=1     - sertakan daftar anggota aktif (default 1)
 *   with_pembina=1     - sertakan daftar pembina (default 1)
 *   updated_since      - format Y-m-d H:i:s (bila tb_anggota_ekskul.updated_at tersedia)
 *   limit              - batasi jumlah ekskul (maks 1000)
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../config/database.php';

// API key diambil dari tb_pengaturan_api (menu Pengaturan Endpoint). Fallback ke key lama bila tabel belum ada.
$__api_key_row = null;
try {
    $__api_key_row = $pdo->query("SELECT api_key FROM tb_pengaturan_api ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $__api_key_row = null; }
define('API_KEY', ($__api_key_row && !empty($__api_key_row['api_key'])) ? (string)$__api_key_row['api_key'] : 'SIS_CENTRAL_HUB_SECRET_2026');

$headers = function_exists('getallheaders') ? getallheaders() : [];
$provided_key = $_GET['api_key'] ?? ($headers['X-API-KEY'] ?? ($headers['x-api-key'] ?? ''));

if ($provided_key !== API_KEY) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid or missing API Key.'
    ]);
    exit;
}

$filter_id = (int)($_GET['id_ekstrakurikuler'] ?? 0);
$with_members = ($_GET['with_members'] ?? '1') !== '0';
$with_pembina = ($_GET['with_pembina'] ?? '1') !== '0';
$limit = (int)($_GET['limit'] ?? 0);

try {
    $where = '';
    $params = [];
    if ($filter_id > 0) {
        $where = 'WHERE e.id_ekstrakurikuler = ?';
        $params[] = $filter_id;
    }
    $limit_sql = $limit > 0 ? 'LIMIT ' . max(1, min($limit, 1000)) : '';

    $stmt = $pdo->prepare("
        SELECT e.id_ekstrakurikuler, e.nama_ekstrakurikuler, e.hari, e.waktu, e.waktu_selesai
        FROM tb_ekstrakurikuler e
        $where
        ORDER BY e.nama_ekstrakurikuler ASC
        $limit_sql
    ");
    $stmt->execute($params);
    $ekskul = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($ekskul as $e) {
        $eid = (int)$e['id_ekstrakurikuler'];
        $item = [
            'id_ekstrakurikuler' => $eid,
            'nama_ekstrakurikuler' => $e['nama_ekstrakurikuler'],
            'hari' => $e['hari'],
            'waktu' => $e['waktu'] !== null ? substr((string)$e['waktu'], 0, 5) : null,
            'waktu_selesai' => $e['waktu_selesai'] !== null ? substr((string)$e['waktu_selesai'], 0, 5) : null,
        ];

        $is_pramuka = stripos((string)$e['nama_ekstrakurikuler'], 'pramuka') !== false;

        if ($with_pembina) {
            if ($is_pramuka) {
                try {
                    $st = $pdo->query("
                        SELECT p.id_pembina_pramuka AS id_pembina, p.nama_pembina, p.jabatan,
                               GROUP_CONCAT(t.nama_tingkat SEPARATOR ', ') AS tingkat
                        FROM tb_pembina_pramuka p
                        LEFT JOIN tb_pembina_tingkat pt ON pt.id_pembina_pramuka = p.id_pembina_pramuka
                        LEFT JOIN tb_tingkat_barung t ON t.id_tingkat_barung = pt.id_tingkat_barung
                        GROUP BY p.id_pembina_pramuka
                        ORDER BY p.nama_pembina ASC
                    ");
                    $item['pembina'] = $st->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $ex) {
                    $item['pembina'] = [];
                }
            } else {
                try {
                    $st = $pdo->prepare("
                        SELECT pb.id_pembina, pb.nama_pembina
                        FROM tb_pembina_ekstrakurikuler m
                        JOIN tb_pembina pb ON pb.id_pembina = m.id_pembina
                        WHERE m.id_ekstrakurikuler = ?
                        ORDER BY pb.nama_pembina ASC
                    ");
                    $st->execute([$eid]);
                    $item['pembina'] = $st->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $ex) {
                    $item['pembina'] = [];
                }
            }
        }

        if ($with_members) {
            if ($is_pramuka) {
                // Anggota pramuka tersimpan di tb_peserta_didik_barung per tingkat barung.
                try {
                    $st = $pdo->query("
                        SELECT p.id_peserta_didik_barung AS id_anggota,
                               p.nama_peserta_didik AS nama_siswa, p.nta AS nisn,
                               p.tempat_lahir, p.tanggal_lahir, p.id_siswa,
                               t.nama_tingkat, t.golongan, p.tanggal_masuk,
                               s.id_kelas, k.nama_kelas
                        FROM tb_peserta_didik_barung p
                        LEFT JOIN tb_tingkat_barung t ON t.id_tingkat_barung = p.id_tingkat_barung
                        LEFT JOIN tb_siswa s ON s.id_siswa = p.id_siswa
                        LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
                        WHERE IFNULL(p.status, 'aktif') = 'aktif'
                        ORDER BY t.golongan ASC, t.nama_tingkat ASC, p.nama_peserta_didik ASC
                    ");
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                    $item['anggota'] = $rows;
                    $item['jumlah_anggota'] = count($rows);
                    $per_tingkat = [];
                    foreach ($rows as $r) {
                        $key = trim((string)($r['golongan'] ?? '') . ' ' . ($r['nama_tingkat'] ?? ''));
                        if ($key === '') $key = '-';
                        if (!isset($per_tingkat[$key])) $per_tingkat[$key] = 0;
                        $per_tingkat[$key]++;
                    }
                    $item['anggota_per_tingkat'] = $per_tingkat;
                } catch (PDOException $ex) {
                    $item['anggota'] = [];
                    $item['jumlah_anggota'] = 0;
                }
            } else {
                try {
                    $st = $pdo->prepare("
                        SELECT a.id AS id_anggota, s.id_siswa, s.nisn, s.nama_siswa,
                               k.id_kelas, k.nama_kelas, a.tanggal_masuk
                        FROM tb_anggota_ekskul a
                        JOIN tb_siswa s ON s.id_siswa = a.id_siswa
                        LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
                        WHERE a.id_ekstrakurikuler = ? AND a.status = 'aktif'
                        ORDER BY s.nama_siswa ASC
                    ");
                    $st->execute([$eid]);
                    $item['anggota'] = $st->fetchAll(PDO::FETCH_ASSOC);
                    $item['jumlah_anggota'] = count($item['anggota']);
                } catch (PDOException $ex) {
                    $item['anggota'] = [];
                    $item['jumlah_anggota'] = 0;
                }
            }
        }

        $result[] = $item;
    }

    echo json_encode([
        'status' => 'success',
        'total_data' => count($result),
        'last_sync' => date('Y-m-d H:i:s'),
        'data' => $result
    ], JSON_PRETTY_PRINT);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
