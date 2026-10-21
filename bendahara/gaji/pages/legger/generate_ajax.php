<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Token tidak valid. Silakan refresh halaman.']); exit(); }

$periode = $_POST['periode'] ?? date('Y-m');

try {
    // Get settings untuk jumlah_periode, periode_mulai, periode_akhir
    $sql = "SELECT jumlah_periode, periode_mulai, periode_akhir FROM settings LIMIT 1";
    $result_settings = $conn->query($sql);
    $settings = $result_settings ? $result_settings->fetch_assoc() : [];
    $jumlah_periode = isset($settings['jumlah_periode']) ? intval($settings['jumlah_periode']) : 1;
    $periode_mulai = isset($settings['periode_mulai']) ? $settings['periode_mulai'] : '';
    $periode_akhir = isset($settings['periode_akhir']) ? $settings['periode_akhir'] : '';
    
    // Get tunjangan: hanya yang aktif
    $sql = "SELECT t.* FROM tunjangan t 
            WHERE t.aktif = 1
            ORDER BY t.nama_tunjangan";
    $result_tunjangan = $conn->query($sql);
    if (!$result_tunjangan) {
        throw new Exception('Gagal mengambil data tunjangan: ' . $conn->error);
    }
    $tunjangan = $result_tunjangan->fetch_all(MYSQLI_ASSOC);
    
    // Get potongan: hanya yang aktif
    $sql = "SELECT p.* FROM potongan p 
            WHERE p.aktif = 1
            ORDER BY p.nama_potongan";
    $stmt_potongan = $conn->prepare($sql);
    if (!$stmt_potongan) {
        throw new Exception('Gagal prepare query potongan: ' . $conn->error);
    }
    $stmt_potongan->execute();
    $result_potongan = $stmt_potongan->get_result();
    if (!$result_potongan) {
        throw new Exception('Gagal mengambil data potongan: ' . $conn->error);
    }
    $potongan = $result_potongan->fetch_all(MYSQLI_ASSOC);
    $stmt_potongan->close();
    
    // Get all teachers with masa_bakti
    $sql = "SELECT id, nama_lengkap, masa_bakti FROM guru ORDER BY nama_lengkap";
    $result_guru = $conn->query($sql);
    if (!$result_guru) {
        throw new Exception('Gagal mengambil data guru: ' . $conn->error);
    }
    $guru = $result_guru->fetch_all(MYSQLI_ASSOC);
    
    $total_guru = count($guru);
    $processed = 0;
    $errors = [];
    
    // Start transaction for each guru separately to avoid rollback on single error
    foreach ($guru as $g) {
        try {
            // Start transaction for this guru
            $conn->begin_transaction();
            // Get gaji pokok dari tabel gaji_pokok (gaji pokok tidak tergantung periode)
            $sql = "SELECT jumlah FROM gaji_pokok WHERE guru_id = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Gagal prepare query gaji_pokok: ' . $conn->error);
            }
            $stmt->bind_param("i", $g['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $gaji_pokok_row = $result->fetch_assoc();
            $gaji_pokok_jumlah = isset($gaji_pokok_row['jumlah']) ? floatval($gaji_pokok_row['jumlah']) : 0;
            // Kalikan dengan jumlah_periode karena ini untuk periode tertentu
            $gaji_pokok_jumlah = $gaji_pokok_jumlah * $jumlah_periode;
            $stmt->close();
            
            $total_tunjangan = 0;
            $tunjangan_details = [];
            
            // Get masa_bakti from guru table for Masa Bakti calculation
            $masa_bakti_guru = isset($g['masa_bakti']) ? intval($g['masa_bakti']) : 0;
            
            foreach ($tunjangan as $t) {
                $jumlah = 0;
                
                // Check if this is "Masa Bakti" tunjangan (case-insensitive)
                $nama_tunjangan_lower = strtolower(trim($t['nama_tunjangan']));
                $is_masa_bakti = (strpos($nama_tunjangan_lower, 'masa') !== false && strpos($nama_tunjangan_lower, 'bakti') !== false);
                
                if ($is_masa_bakti) {
                    $jumlah_tunjangan_per_tahun = isset($t['jumlah_tunjangan']) ? floatval($t['jumlah_tunjangan']) : 0;
                    $jumlah_per_bulan = $masa_bakti_guru * $jumlah_tunjangan_per_tahun;
                    
                    $sql_check = "SELECT id FROM tunjangan_detail WHERE guru_id = ? AND tunjangan_id = ? AND periode = ?";
                    $stmt_check = $conn->prepare($sql_check);
                    if ($stmt_check) {
                        $stmt_check->bind_param("iis", $g['id'], $t['id'], $periode);
                        $stmt_check->execute();
                        $result_check = $stmt_check->get_result();
                        $existing = $result_check->fetch_assoc();
                        $stmt_check->close();
                        
                        if ($existing) {
                            $sql_update = "UPDATE tunjangan_detail SET jumlah = ? WHERE id = ?";
                            $stmt_update = $conn->prepare($sql_update);
                            if ($stmt_update) {
                                $stmt_update->bind_param("di", $jumlah_per_bulan, $existing['id']);
                                $stmt_update->execute();
                                $stmt_update->close();
                            }
                        } else {
                            $sql_insert = "INSERT INTO tunjangan_detail (guru_id, tunjangan_id, periode, jumlah) VALUES (?, ?, ?, ?)";
                            $stmt_insert = $conn->prepare($sql_insert);
                            if ($stmt_insert) {
                                $stmt_insert->bind_param("iisd", $g['id'], $t['id'], $periode, $jumlah_per_bulan);
                                $stmt_insert->execute();
                                $stmt_insert->close();
                            }
                        }
                    }
                    $jumlah = $jumlah_per_bulan * $jumlah_periode;
                } else {
                    $sql_latest = "SELECT jumlah FROM tunjangan_detail WHERE guru_id = ? AND tunjangan_id = ? ORDER BY periode DESC LIMIT 1";
                    $stmt_latest = $conn->prepare($sql_latest);
                    $jumlah_per_bulan = 0;
                    if ($stmt_latest) {
                        $stmt_latest->bind_param("ii", $g['id'], $t['id']);
                        $stmt_latest->execute();
                        $result_latest = $stmt_latest->get_result();
                        $row_latest = $result_latest->fetch_assoc();
                        $stmt_latest->close();
                        if ($row_latest) {
                            $jumlah_per_bulan = isset($row_latest['jumlah']) ? floatval($row_latest['jumlah']) : 0;
                        }
                    }
                    $jumlah = $jumlah_per_bulan * $jumlah_periode;
                }
                
                $total_tunjangan += $jumlah;
                $tunjangan_details[$t['id']] = $jumlah;
            }
            
            $total_potongan = 0;
            $potongan_details = [];
            foreach ($potongan as $p) {
                $sql_latest_pot = "SELECT jumlah FROM potongan_detail WHERE guru_id = ? AND potongan_id = ? ORDER BY periode DESC LIMIT 1";
                $stmt_latest_pot = $conn->prepare($sql_latest_pot);
                $jumlah_per_bulan_pot = 0;
                if ($stmt_latest_pot) {
                    $stmt_latest_pot->bind_param("ii", $g['id'], $p['id']);
                    $stmt_latest_pot->execute();
                    $result_latest_pot = $stmt_latest_pot->get_result();
                    $row_latest_pot = $result_latest_pot->fetch_assoc();
                    $stmt_latest_pot->close();
                    if ($row_latest_pot) {
                        $jumlah_per_bulan_pot = isset($row_latest_pot['jumlah']) ? floatval($row_latest_pot['jumlah']) : 0;
                    }
                }
                $jumlah = $jumlah_per_bulan_pot * $jumlah_periode;
                $total_potongan += $jumlah;
                $potongan_details[$p['id']] = $jumlah;
            }
            
            // PERHITUNGAN GAJI BERSIH:
            // Formula: Gaji Bersih = Gaji Pokok + Total Tunjangan - Total Potongan
            // Langkah:
            // 1. Ambil Gaji Pokok dari tabel gaji_pokok
            // 2. Jumlahkan semua Tunjangan dari tunjangan_detail
            // 3. Jumlahkan semua Potongan dari potongan_detail
            // 4. Hitung: Gaji Pokok + Total Tunjangan - Total Potongan
            $gaji_bersih = $gaji_pokok_jumlah + $total_tunjangan - $total_potongan;
            
            // Insert or update legger
            $sql = "INSERT INTO legger_gaji (guru_id, periode, gaji_pokok, total_tunjangan, total_potongan, gaji_bersih) 
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    gaji_pokok=VALUES(gaji_pokok), 
                    total_tunjangan=VALUES(total_tunjangan), 
                    total_potongan=VALUES(total_potongan), 
                    gaji_bersih=VALUES(gaji_bersih)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Gagal prepare query legger_gaji: ' . $conn->error);
            }
            $stmt->bind_param("isdddd", $g['id'], $periode, $gaji_pokok_jumlah, $total_tunjangan, $total_potongan, $gaji_bersih);
            
            if (!$stmt->execute()) {
                throw new Exception('Gagal insert/update legger_gaji untuk guru ' . $g['nama_lengkap'] . ': ' . $stmt->error);
            }
            
            // Get legger_id
            $legger_id = $conn->insert_id;
            if (!$legger_id) {
                $sql = "SELECT id FROM legger_gaji WHERE guru_id = ? AND periode = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("is", $g['id'], $periode);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $legger_id = $row['id'];
                    }
                    $stmt->close();
                }
            } else {
                $stmt->close();
            }
            
            // Simpan data lama dari legger_detail (untuk mempertahankan data potongan yang tidak ada di potongan_detail)
            $old_legger_details = [];
            $existing_detail_ids = []; // Untuk tracking item yang akan di-update/insert
            if ($legger_id) {
                $sql = "SELECT * FROM legger_detail WHERE legger_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $legger_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $old_legger_details[$row['jenis']][$row['item_id']] = $row;
                    }
                    $stmt->close();
                }
            }

            if ($legger_id) {
                $sql = "DELETE ld FROM legger_detail ld
                        INNER JOIN tunjangan t ON ld.item_id = t.id
                        WHERE ld.legger_id = ?
                        AND ld.jenis = 'tunjangan'
                        AND t.aktif = 0";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $legger_id);
                    $stmt->execute();
                    $stmt->close();
                }

                $sql = "DELETE ld FROM legger_detail ld
                        INNER JOIN potongan p ON ld.item_id = p.id
                        WHERE ld.legger_id = ?
                        AND ld.jenis = 'potongan'
                        AND p.aktif = 0";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $legger_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            // Update atau Insert tunjangan details (jumlah sudah dikalikan dengan jumlah_periode)
            // Hanya update/insert tunjangan yang jumlahnya > 0 (guru benar-benar menerima tunjangan)
            if ($legger_id) {
                foreach ($tunjangan as $t) {
                    $jumlah = isset($tunjangan_details[$t['id']]) ? $tunjangan_details[$t['id']] : 0;
                    // Hanya update/insert jika jumlah > 0
                    if ($jumlah > 0) {
                        // Cek apakah sudah ada
                        if (isset($old_legger_details['tunjangan'][$t['id']])) {
                            // UPDATE data yang sudah ada
                            $sql = "UPDATE legger_detail SET nama_item = ?, jumlah = ? WHERE legger_id = ? AND jenis = 'tunjangan' AND item_id = ?";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $stmt->bind_param("sdii", $t['nama_tunjangan'], $jumlah, $legger_id, $t['id']);
                                $stmt->execute();
                                $stmt->close();
                            }
                        } else {
                            // INSERT data baru
                            $sql = "INSERT INTO legger_detail (legger_id, jenis, item_id, nama_item, jumlah) VALUES (?, 'tunjangan', ?, ?, ?)";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $stmt->bind_param("iisd", $legger_id, $t['id'], $t['nama_tunjangan'], $jumlah);
                                $stmt->execute();
                                $stmt->close();
                            }
                        }
                        $existing_detail_ids['tunjangan'][$t['id']] = true;
                    } else {
                        // Jika jumlah = 0, hapus jika ada (tunjangan tidak perlu dipertahankan jika jumlah = 0)
                        if (isset($old_legger_details['tunjangan'][$t['id']])) {
                            $sql = "DELETE FROM legger_detail WHERE legger_id = ? AND jenis = 'tunjangan' AND item_id = ?";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $stmt->bind_param("ii", $legger_id, $t['id']);
                                $stmt->execute();
                                $stmt->close();
                            }
                        }
                    }
                }
                
                foreach ($potongan as $p) {
                    $jumlah = isset($potongan_details[$p['id']]) ? $potongan_details[$p['id']] : 0;
                    if ($jumlah > 0) {
                        if (isset($old_legger_details['potongan'][$p['id']])) {
                            $sql = "UPDATE legger_detail SET nama_item = ?, jumlah = ? WHERE legger_id = ? AND jenis = 'potongan' AND item_id = ?";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $stmt->bind_param("sdii", $p['nama_potongan'], $jumlah, $legger_id, $p['id']);
                                $stmt->execute();
                                $stmt->close();
                            }
                        } else {
                            $sql = "INSERT INTO legger_detail (legger_id, jenis, item_id, nama_item, jumlah) VALUES (?, 'potongan', ?, ?, ?)";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $stmt->bind_param("iisd", $legger_id, $p['id'], $p['nama_potongan'], $jumlah);
                                $stmt->execute();
                                $stmt->close();
                            }
                        }
                        $existing_detail_ids['potongan'][$p['id']] = true;
                    } else {
                        if (isset($old_legger_details['potongan'][$p['id']])) {
                            $sql = "DELETE FROM legger_detail WHERE legger_id = ? AND jenis = 'potongan' AND item_id = ?";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $stmt->bind_param("ii", $legger_id, $p['id']);
                                $stmt->execute();
                                $stmt->close();
                            }
                        }
                    }
                }
                
                // Hapus tunjangan yang tidak ada lagi di list baru (sudah dihandle di loop di atas)
                // Tidak perlu hapus lagi karena sudah dihandle saat jumlah = 0
            }
            
            // Commit transaction for this guru
            $conn->commit();
            $processed++;
            
        } catch (Exception $e) {
            // Rollback transaction for this guru only
            if (method_exists($conn, 'rollback')) {
                $conn->rollback();
            }
            $errors[] = $g['nama_lengkap'] . ': ' . $e->getMessage();
            // Continue processing other gurus
        }
    }
    
    // Log activity only if at least one guru was processed
    if ($processed > 0) {
        gajiLogActivity($conn, "Generate legger gaji periode {$periode} untuk {$processed} guru", 'success');
    }
    
    // Get period label with range if jumlah_periode > 1
    $period_label = $periode;
    if ($jumlah_periode > 1 && !empty($settings['periode_mulai']) && !empty($settings['periode_akhir'])) {
        if (function_exists('getPeriodRangeLabel')) {
            $period_label = getPeriodRangeLabel($settings['periode_mulai'], $settings['periode_akhir']);
        } else {
            $period_label = $settings['periode_mulai'] . ' - ' . $settings['periode_akhir'];
        }
    } else if (function_exists('getPeriodLabel')) {
        $period_label = getPeriodLabel($periode);
    }
    
    // Prepare response message
    $message = "Legger gaji berhasil digenerate untuk {$processed} guru pada periode {$period_label}";
    if (!empty($errors)) {
        $message .= ". Terjadi kesalahan pada " . count($errors) . " guru: " . implode(', ', array_slice($errors, 0, 3));
    }
    
    echo json_encode([
        'success' => $processed > 0,
        'message' => $message,
        'processed' => $processed,
        'total' => $total_guru,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    if (method_exists($conn, 'rollback')) {
        $conn->rollback();
    }
    
    // Return error with more details
    $error_message = 'Gagal generate legger: ' . $e->getMessage();
    if (isset($processed) && $processed > 0) {
        $error_message .= " (Berhasil memproses {$processed} dari {$total_guru} guru)";
    }
    
    echo json_encode([
        'success' => false,
        'message' => $error_message,
        'error' => $e->getMessage()
    ]);
}
?>
