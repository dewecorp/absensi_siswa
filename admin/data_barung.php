<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'Data Barung';

// DataTables
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

// --- Ensure schema (best-effort) ---
$schema_error = null;
try {
    // tb_tingkat_barung (created by data_tingkat_barung.php, but ensure here too)
    $stmt = $pdo->query("SHOW TABLES LIKE 'tb_tingkat_barung'");
    $exists = (bool)$stmt->fetch(PDO::FETCH_NUM);
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE tb_tingkat_barung (
                id_tingkat_barung INT AUTO_INCREMENT PRIMARY KEY,
                nama_tingkat VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // tb_peserta_didik_barung (peserta didik per tingkat)
    $stmt = $pdo->query("SHOW TABLES LIKE 'tb_peserta_didik_barung'");
    $exists = (bool)$stmt->fetch(PDO::FETCH_NUM);
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE tb_peserta_didik_barung (
                id_peserta_didik_barung INT AUTO_INCREMENT PRIMARY KEY,
                id_tingkat_barung INT NOT NULL,
                nama_peserta_didik VARCHAR(120) NOT NULL,
                nta VARCHAR(50) NOT NULL,
                tempat_lahir VARCHAR(120) NULL,
                tanggal_lahir DATE NULL,
                INDEX idx_tingkat (id_tingkat_barung)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        // Ensure new columns exist
        $required_cols = [
            'tempat_lahir' => "VARCHAR(120) NULL",
            'tanggal_lahir' => "DATE NULL",
        ];
        foreach ($required_cols as $col => $typeDef) {
            $colStmt = $pdo->query("SHOW COLUMNS FROM tb_peserta_didik_barung LIKE '" . addslashes($col) . "'");
            $has_col = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
            if (!$has_col) {
                $pdo->exec("ALTER TABLE tb_peserta_didik_barung ADD COLUMN {$col} {$typeDef}");
            }
        }
    }
} catch (Exception $e) {
    $schema_error = $e->getMessage();
}

function ensureInt($v): int {
    return (int)($v ?? 0);
}

// --- Fetch tingkat list ---
$tingkat_list = [];
$fetch_error = null;
try {
    $tingkat_list = $pdo->query("
            SELECT id_tingkat_barung, nama_tingkat
            FROM tb_tingkat_barung
            ORDER BY
                CASE
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('pramula', 'pra-mula') OR LOWER(nama_tingkat) = 'pra mula' THEN 1
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('mula') THEN 2
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('bantu') THEN 3
                    WHEN LOWER(REPLACE(nama_tingkat, ' ', '')) IN ('tata') THEN 4
                    ELSE 99
                END,
                nama_tingkat ASC
        ")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fetch_error = $e->getMessage();
}

$selected_tingkat_id = ensureInt($_GET['tingkat'] ?? 0);
if ($selected_tingkat_id <= 0 && !empty($tingkat_list)) {
    $selected_tingkat_id = (int)($tingkat_list[0]['id_tingkat_barung'] ?? 0);
}

// Resolve selected tingkat name early (used for template filename too)
$selected_tingkat_name = '';
foreach ($tingkat_list as $t) {
    if ((int)($t['id_tingkat_barung'] ?? 0) === $selected_tingkat_id) {
        $selected_tingkat_name = (string)($t['nama_tingkat'] ?? '');
        break;
    }
}

// --- Download import template (Excel/CSV) ---
if (isset($_GET['download_template'])) {
    $format = strtolower((string)($_GET['format'] ?? 'xlsx'));
    if (!in_array($format, ['xlsx', 'csv'], true)) {
        $format = 'xlsx';
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        http_response_code(500);
        echo 'Dependensi Excel belum terpasang. Jalankan composer install.';
        exit;
    }
    require_once $autoload;

    $safeTingkat = trim(preg_replace('/[^a-zA-Z0-9_-]+/', '_', $selected_tingkat_name !== '' ? $selected_tingkat_name : 'tingkat'));
    $filenameBase = 'template_import_barung_' . ($safeTingkat !== '' ? $safeTingkat : 'tingkat');

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Template');

    $sheet->setCellValue('A1', 'Nama Peserta Didik');
    $sheet->setCellValue('B1', 'NTA');
    $sheet->setCellValue('C1', 'Tempat Lahir');
    $sheet->setCellValue('D1', 'Tanggal Lahir (YYYY-MM-DD)');
    $sheet->setCellValue('A2', 'Contoh: Ahmad');
    $sheet->setCellValue('B2', '12345');
    $sheet->setCellValue('C2', 'Padang');
    $sheet->setCellValue('D2', '2013-01-15');
    $sheet->setCellValue('A3', 'Contoh: Siti');
    $sheet->setCellValue('B3', '67890');
    $sheet->setCellValue('C3', 'Bukittinggi');
    $sheet->setCellValue('D3', '2013-08-20');

    // Basic styling for header
    $sheet->getStyle('A1:D1')->getFont()->setBold(true);
    $sheet->getColumnDimension('A')->setWidth(28);
    $sheet->getColumnDimension('B')->setWidth(18);
    $sheet->getColumnDimension('C')->setWidth(22);
    $sheet->getColumnDimension('D')->setWidth(24);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->setUseBOM(true);
        $writer->save('php://output');
        exit;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// --- CRUD handling ---
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Import peserta didik from Excel/CSV
    if (isset($_POST['import_peserta_didik'])) {
        $is_ajax = isset($_POST['ajax']) && (string)$_POST['ajax'] === '1';
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        if ($id_tingkat <= 0) {
            $message = ['type' => 'warning', 'text' => 'Pilih tingkat barung untuk import.'];
        } elseif (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
            $message = ['type' => 'warning', 'text' => 'File import tidak ditemukan.'];
        } else {
            $file = $_FILES['import_file'];
            $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                $message = ['type' => 'danger', 'text' => 'Gagal upload file (error code: ' . $err . ').'];
            } else {
                $tmpPath = (string)($file['tmp_name'] ?? '');
                $origName = (string)($file['name'] ?? '');
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                    $message = ['type' => 'warning', 'text' => 'Format file tidak didukung. Gunakan .xlsx, .xls, atau .csv'];
                } else {
                    try {
                        $autoload = __DIR__ . '/../vendor/autoload.php';
                        if (!file_exists($autoload)) {
                            throw new Exception('Dependensi Excel belum terpasang. Jalankan composer install.');
                        }
                        require_once $autoload;

                        $reader = null;
                        if ($ext === 'csv') {
                            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                            $reader->setDelimiter(',');
                            $reader->setEnclosure('"');
                            $reader->setSheetIndex(0);
                        } else {
                            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
                        }
                        $reader->setReadDataOnly(true);
                        $spreadsheet = $reader->load($tmpPath);
                        $sheet = $spreadsheet->getSheet(0);
                        $highestRow = (int)$sheet->getHighestRow();
                        $highestCol = $sheet->getHighestColumn();

                        // Detect header (row 1) for columns
                        $headerMap = ['nama' => null, 'nta' => null, 'tempat' => null, 'tanggal' => null];
                        $headerRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, true, true);
                        $headerRow = $headerRow[1] ?? [];
                        foreach ($headerRow as $col => $val) {
                            $txt = strtolower(trim((string)$val));
                            if ($txt === '') continue;
                            if ($headerMap['nama'] === null && (str_contains($txt, 'nama') || str_contains($txt, 'peserta'))) {
                                $headerMap['nama'] = $col;
                                continue;
                            }
                            if ($headerMap['nta'] === null && str_contains($txt, 'nta')) {
                                $headerMap['nta'] = $col;
                                continue;
                            }
                            if ($headerMap['tempat'] === null && (str_contains($txt, 'tempat') && str_contains($txt, 'lahir'))) {
                                $headerMap['tempat'] = $col;
                                continue;
                            }
                            if ($headerMap['tanggal'] === null && (str_contains($txt, 'tanggal') && str_contains($txt, 'lahir'))) {
                                $headerMap['tanggal'] = $col;
                                continue;
                            }
                        }

                        $startRow = ($headerMap['nama'] !== null || $headerMap['nta'] !== null || $headerMap['tempat'] !== null || $headerMap['tanggal'] !== null) ? 2 : 1;
                        if ($headerMap['nama'] === null) $headerMap['nama'] = 'A';
                        if ($headerMap['nta'] === null) $headerMap['nta'] = 'B';
                        if ($headerMap['tempat'] === null) $headerMap['tempat'] = 'C';
                        if ($headerMap['tanggal'] === null) $headerMap['tanggal'] = 'D';

                        $inserted = 0;
                        $skipped = 0;

                        $pdo->beginTransaction();
                        $stmtIns = $pdo->prepare("
                            INSERT INTO tb_peserta_didik_barung (id_tingkat_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir)
                            VALUES (?, ?, ?, ?, ?)
                        ");

                        for ($r = $startRow; $r <= $highestRow; $r++) {
                            $nama = trim((string)$sheet->getCell($headerMap['nama'] . $r)->getValue());
                            $nta = trim((string)$sheet->getCell($headerMap['nta'] . $r)->getValue());
                            $tempat = trim((string)$sheet->getCell($headerMap['tempat'] . $r)->getValue());
                            $tglRaw = $sheet->getCell($headerMap['tanggal'] . $r)->getValue();
                            $tgl = '';
                            if ($tglRaw !== null && $tglRaw !== '') {
                                if (is_numeric($tglRaw)) {
                                    try {
                                        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$tglRaw);
                                        $tgl = $dt->format('Y-m-d');
                                    } catch (Exception $e) {
                                        $tgl = '';
                                    }
                                } else {
                                    $tgl = trim((string)$tglRaw);
                                }
                            }
                            $tgl = $tgl !== '' ? substr($tgl, 0, 10) : null;

                            if ($nama === '' && $nta === '') {
                                $skipped++;
                                continue;
                            }
                            if ($nama === '' || $nta === '') {
                                $skipped++;
                                continue;
                            }

                            $stmtIns->execute([$id_tingkat, $nama, $nta, ($tempat !== '' ? $tempat : null), $tgl]);
                            $inserted++;
                        }

                        $pdo->commit();

                        $username = $_SESSION['username'] ?? 'system';
                        logActivity($pdo, $username, 'Import Peserta Didik Barung', "Tingkat ID {$id_tingkat}: inserted {$inserted}, skipped {$skipped}, file {$origName}");

                        $message = ['type' => 'success', 'text' => "Import selesai. Berhasil: {$inserted} baris, dilewati: {$skipped} baris."];
                        $selected_tingkat_id = $id_tingkat;
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $message = ['type' => 'danger', 'text' => 'Gagal import: ' . $e->getMessage()];
                    }
                }
            }
        }

        if ($is_ajax) {
            // Return JSON response for AJAX import
            $type = $message['type'] ?? 'danger';
            $text = $message['text'] ?? 'Terjadi kesalahan.';
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => $type === 'success',
                'type' => $type,
                'message' => $text,
                'selected_tingkat_id' => $selected_tingkat_id,
            ]);
            exit;
        }
    }

    // Add peserta
    if (isset($_POST['add_peserta_didik'])) {
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        $nama = sanitizeInput($_POST['nama_peserta_didik'] ?? '');
        $nta = sanitizeInput($_POST['nta'] ?? '');
        $tempat = sanitizeInput($_POST['tempat_lahir'] ?? '');
        $tgl = sanitizeInput($_POST['tanggal_lahir'] ?? '');
        $tgl = $tgl !== '' ? substr($tgl, 0, 10) : null;

        if ($id_tingkat <= 0 || $nama === '' || $nta === '') {
            $message = ['type' => 'warning', 'text' => 'Harap lengkapi tingkat, nama peserta didik, dan NTA.'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO tb_peserta_didik_barung (id_tingkat_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $ok = $stmt->execute([$id_tingkat, $nama, $nta, ($tempat !== '' ? $tempat : null), $tgl]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Tambah Peserta Didik Barung', "{$nama} ({$nta}) tingkat ID {$id_tingkat}");
                    $message = ['type' => 'success', 'text' => 'Peserta didik berhasil ditambahkan!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menambahkan peserta didik.'];
                }
                $selected_tingkat_id = $id_tingkat;
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Update peserta
    if (isset($_POST['update_peserta_didik'])) {
        $id_peserta = ensureInt($_POST['id_peserta_didik_barung'] ?? 0);
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        $nama = sanitizeInput($_POST['nama_peserta_didik'] ?? '');
        $nta = sanitizeInput($_POST['nta'] ?? '');
        $tempat = sanitizeInput($_POST['tempat_lahir'] ?? '');
        $tgl = sanitizeInput($_POST['tanggal_lahir'] ?? '');
        $tgl = $tgl !== '' ? substr($tgl, 0, 10) : null;

        if ($id_peserta <= 0 || $id_tingkat <= 0 || $nama === '' || $nta === '') {
            $message = ['type' => 'warning', 'text' => 'Harap lengkapi data edit.'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE tb_peserta_didik_barung
                    SET id_tingkat_barung = ?, nama_peserta_didik = ?, nta = ?, tempat_lahir = ?, tanggal_lahir = ?
                    WHERE id_peserta_didik_barung = ?
                ");
                $ok = $stmt->execute([$id_tingkat, $nama, $nta, ($tempat !== '' ? $tempat : null), $tgl, $id_peserta]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Update Peserta Didik Barung', "ID {$id_peserta}: {$nama} ({$nta}) tingkat ID {$id_tingkat}");
                    $message = ['type' => 'success', 'text' => 'Data peserta didik berhasil diperbarui!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal memperbarui data.'];
                }
                $selected_tingkat_id = $id_tingkat;
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Delete peserta
    if (isset($_POST['delete_peserta_didik'])) {
        $id_peserta = ensureInt($_POST['id_peserta_didik_barung'] ?? 0);
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);

        if ($id_peserta <= 0) {
            $message = ['type' => 'warning', 'text' => 'ID tidak valid.'];
        } else {
            try {
                $nameStmt = $pdo->prepare("SELECT nama_peserta_didik FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung = ?");
                $nameStmt->execute([$id_peserta]);
                $nama = (string)($nameStmt->fetchColumn() ?: '-');

                $stmt = $pdo->prepare("DELETE FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung = ?");
                $ok = $stmt->execute([$id_peserta]);
                if ($ok) {
                    $username = $_SESSION['username'] ?? 'system';
                    logActivity($pdo, $username, 'Hapus Peserta Didik Barung', "ID {$id_peserta}: {$nama}");
                    $message = ['type' => 'success', 'text' => 'Peserta didik berhasil dihapus!'];
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal menghapus data.'];
                }
                if ($id_tingkat > 0) $selected_tingkat_id = $id_tingkat;
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Error DB: ' . $e->getMessage()];
            }
        }
    }

    // Multiple delete peserta (by selected checkboxes)
    if (isset($_POST['delete_peserta_didik_multiple'])) {
        $id_tingkat = ensureInt($_POST['id_tingkat_barung'] ?? 0);
        $selected = $_POST['selected_ids'] ?? [];
        if (!is_array($selected)) $selected = [];
        $selected = array_values(array_filter(array_map('intval', $selected), fn($v) => $v > 0));

        if ($id_tingkat <= 0 || empty($selected)) {
            $message = ['type' => 'warning', 'text' => 'Pilih minimal 1 peserta didik untuk dihapus.'];
        } else {
            try {
                $pdo->beginTransaction();
                $placeholders = str_repeat('?,', count($selected) - 1) . '?';
                $sql = "DELETE FROM tb_peserta_didik_barung WHERE id_peserta_didik_barung IN ($placeholders) AND id_tingkat_barung = ?";
                $params = array_merge($selected, [$id_tingkat]);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $deleted = (int)$stmt->rowCount();

                $username = $_SESSION['username'] ?? 'system';
                logActivity($pdo, $username, 'Hapus Peserta Didik Barung (Multiple)', "Tingkat ID {$id_tingkat}: deleted {$deleted}");
                $pdo->commit();

                $message = ['type' => 'success', 'text' => "Berhasil menghapus {$deleted} peserta didik."];
                $selected_tingkat_id = $id_tingkat;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = ['type' => 'danger', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
            }
        }
    }
}

// --- Fetch peserta list by tingkat ---
$peserta_rows = [];
$table_error = null;
if ($selected_tingkat_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id_peserta_didik_barung, nama_peserta_didik, nta, tempat_lahir, tanggal_lahir, id_tingkat_barung
            FROM tb_peserta_didik_barung
            WHERE id_tingkat_barung = ?
            ORDER BY nama_peserta_didik ASC
        ");
        $stmt->execute([$selected_tingkat_id]);
        $peserta_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $table_error = $e->getMessage();
    }
}

// Page-specific JS
$js_page = [];
if ($message) {
    $swal_icon = $message['type'] === 'success' ? 'success' : ($message['type'] === 'warning' ? 'warning' : 'error');
    $swal_title = $message['type'] === 'success' ? 'Berhasil!' : 'Perhatian!';
    $swal_text = json_encode($message['text']);
    $js_page[] = "
        Swal.fire({
            icon: '{$swal_icon}',
            title: '{$swal_title}',
            text: {$swal_text},
            timer: " . ($message['type'] === 'success' ? 1800 : 2500) . ",
            showConfirmButton: false
        });
    ";
}

$js_page[] = "
$(document).ready(function() {
    var table = $('#table-1').DataTable({
        'order': [[1, 'asc']],
        'columnDefs': [
            { 'sortable': false, 'targets': [0, 6] }
        ],
        'language': {
            'lengthMenu': 'Tampilkan _MENU_ entri',
            'zeroRecords': 'Tidak ada data yang ditemukan',
            'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
            'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
            'search': 'Cari:',
            'paginate': {
                'first': 'Pertama',
                'last': 'Terakhir',
                'next': 'Selanjutnya',
                'previous': 'Sebelumnya'
            }
        }
    });

    table.on('order.dt search.dt draw.dt', function() {
        var info = table.page.info();
        var start = info.page * info.length;
        table.column(1, { search: 'applied', order: 'applied' }).nodes().each(function(cell, i) {
            $(cell).text(start + i + 1);
        });
    }).draw();

    // Edit modal fill
    $(document).on('click', '.edit-btn', function() {
        $('#edit_id_peserta_didik_barung').val($(this).data('id'));
        $('#edit_id_tingkat_barung').val($(this).data('tingkat'));
        $('#edit_nama_peserta_didik').val($(this).data('nama') || '');
        $('#edit_nta').val($(this).data('nta') || '');
        $('#edit_tempat_lahir').val($(this).data('tempat') || '');
        $('#edit_tanggal_lahir').val($(this).data('tanggal') || '');
        $('#editModal').modal('show');
    });

    // Delete confirmation
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var nama = $(this).data('nama') || '-';
        var tingkat = $(this).data('tingkat') || '';
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus \"' + nama + '\"?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method=\"POST\" action=\"\">' +
                    '<input type=\"hidden\" name=\"id_peserta_didik_barung\" value=\"' + id + '\">' +
                    '<input type=\"hidden\" name=\"id_tingkat_barung\" value=\"' + tingkat + '\">' +
                    '<input type=\"hidden\" name=\"delete_peserta_didik\" value=\"1\">' +
                    '</form>');
                $('body').append(form);
                form.submit();
            }
        });
    });

    function updateDeleteSelectedUI() {
        var checkedCount = $('.row-check:checked').length;
        if (checkedCount > 0) {
            $('#btn-delete-selected').removeClass('d-none').text('Hapus Terpilih (' + checkedCount + ')');
        } else {
            $('#btn-delete-selected').addClass('d-none').text('Hapus Terpilih');
        }
    }

    // Select all (only visible rows under current filter)
    $('#checkAllRows').on('change', function() {
        var isChecked = $(this).prop('checked');
        var rows = table.rows({ search: 'applied' }).nodes();
        $('.row-check', rows).prop('checked', isChecked);
        updateDeleteSelectedUI();
    });

    // Row checkbox handler
    $('#table-1 tbody').on('change', '.row-check', function() {
        var rows = table.rows({ search: 'applied' }).nodes();
        var total = $('.row-check', rows).length;
        var checked = $('.row-check:checked', rows).length;
        $('#checkAllRows').prop('checked', total > 0 && total === checked);
        updateDeleteSelectedUI();
    });

    // Reset when table redraws (paging/search)
    table.on('draw.dt', function() {
        $('#checkAllRows').prop('checked', false);
        updateDeleteSelectedUI();
    });

    // Multiple delete button
    $('#btn-delete-selected').on('click', function(e) {
        e.preventDefault();
        var ids = $('.row-check:checked').map(function() { return $(this).val(); }).get();
        if (!ids || ids.length === 0) return;

        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Hapus ' + ids.length + ' peserta didik terpilih?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = $('<form method=\"POST\" action=\"\">' +
                    '<input type=\"hidden\" name=\"id_tingkat_barung\" value=\"<?= (int)$selected_tingkat_id ?>\">' +
                    '<input type=\"hidden\" name=\"delete_peserta_didik_multiple\" value=\"1\">' +
                    '</form>');
                ids.forEach(function(id) {
                    form.append('<input type=\"hidden\" name=\"selected_ids[]\" value=\"' + id + '\">');
                });
                $('body').append(form);
                form.submit();
            }
        });
    });

    updateDeleteSelectedUI();

    // AJAX Import with progress bar (upload progress + processing state)
    $('#importForm').on('submit', function(e) {
        e.preventDefault();
        var form = this;
        var formData = new FormData(form);
        formData.append('ajax', '1');

        // UI state
        $('#importProgressWrap').removeClass('d-none');
        $('#importProgressBar').css('width', '0%').attr('aria-valuenow', 0).text('0%');
        $('#importStatusText').text('Mengunggah file...');
        $('#importSubmitBtn').prop('disabled', true);
        $('#importCloseBtn').prop('disabled', true);
        $('.import-template-btn').addClass('disabled').attr('aria-disabled', 'true');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);

        xhr.upload.onprogress = function(ev) {
            if (!ev.lengthComputable) return;
            var pct = Math.round((ev.loaded / ev.total) * 100);
            if (pct > 100) pct = 100;
            $('#importProgressBar').css('width', pct + '%').attr('aria-valuenow', pct).text(pct + '%');
            if (pct >= 100) {
                $('#importStatusText').text('Memproses data...');
            }
        };

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;

            $('#importSubmitBtn').prop('disabled', false);
            $('#importCloseBtn').prop('disabled', false);
            $('.import-template-btn').removeClass('disabled').removeAttr('aria-disabled');

            var resp = null;
            try { resp = JSON.parse(xhr.responseText); } catch (e) {}

            if (xhr.status !== 200 || !resp) {
                Swal.fire({ icon: 'error', title: 'Gagal', text: 'Import gagal. Silakan coba lagi.' });
                return;
            }

            var icon = (resp.type === 'success') ? 'success' : (resp.type === 'warning' ? 'warning' : 'error');
            Swal.fire({ icon: icon, title: resp.ok ? 'Berhasil!' : 'Perhatian!', text: resp.message })
                .then(function() {
                    var tingkat = resp.selected_tingkat_id || $('select[name=\"id_tingkat_barung\"]', form).val() || '';
                    var url = new URL(window.location.href);
                    if (tingkat) url.searchParams.set('tingkat', tingkat);
                    url.searchParams.delete('download_template');
                    url.searchParams.delete('format');
                    window.location.href = url.toString();
                });
        };

        xhr.send(formData);
    });
});
";

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Barung</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Ekstrakurikuler</div>
                <div class="breadcrumb-item">Barung</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Data Barung</h4>
                    <div class="card-header-action">
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addModal" type="button" <?php echo $selected_tingkat_id > 0 ? '' : 'disabled'; ?>>
                            <i class="fas fa-plus"></i> Tambah Peserta Didik
                        </button>
                        <button class="btn btn-success ml-1" data-toggle="modal" data-target="#importModal" type="button" <?php echo $selected_tingkat_id > 0 ? '' : 'disabled'; ?>>
                            <i class="fas fa-file-excel"></i> Import Excel
                        </button>
                        <button class="btn btn-danger ml-1 d-none" id="btn-delete-selected" type="button" <?php echo $selected_tingkat_id > 0 ? '' : 'disabled'; ?>>
                            <i class="fas fa-trash"></i> Hapus Terpilih
                        </button>
                    </div>
                </div>

                <div class="card-body">
                    <?php if ($schema_error || $fetch_error || $table_error): ?>
                        <div class="alert alert-danger">
                            <strong>Terjadi masalah pada database.</strong><br>
                            <?php if (!empty($schema_error)) echo htmlspecialchars($schema_error) . '<br>'; ?>
                            <?php if (!empty($fetch_error)) echo htmlspecialchars($fetch_error) . '<br>'; ?>
                            <?php if (!empty($table_error)) echo htmlspecialchars($table_error); ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <div class="d-flex flex-wrap" style="gap:8px;">
                            <?php if (!empty($tingkat_list)): ?>
                                <?php foreach ($tingkat_list as $t): ?>
                                    <?php
                                        $tid = (int)($t['id_tingkat_barung'] ?? 0);
                                        $active = $tid === $selected_tingkat_id;
                                    ?>
                                    <a href="?tingkat=<?= $tid ?>" class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-primary' ?>">
                                        <?= htmlspecialchars($t['nama_tingkat'] ?? '') ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted">
                                    Belum ada tingkat barung. Silakan buat di menu <strong>Data Tingkat Barung</strong>.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped" id="table-1">
                            <thead>
                                <tr>
                                    <th class="text-center" width="36px"><input type="checkbox" id="checkAllRows"></th>
                                    <th class="text-center" width="6%">No</th>
                                    <th>Nama Peserta Didik</th>
                                    <th width="14%">NTA</th>
                                    <th>Tempat Lahir</th>
                                    <th width="14%">Tanggal Lahir</th>
                                    <th width="15%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($peserta_rows)): ?>
                                    <?php foreach ($peserta_rows as $idx => $row): ?>
                                        <tr>
                                            <td class="text-center">
                                                <input type="checkbox" class="row-check" value="<?= (int)($row['id_peserta_didik_barung'] ?? 0) ?>">
                                            </td>
                                            <td class="text-center"><?= (int)($idx + 1) ?></td>
                                            <td><?= htmlspecialchars($row['nama_peserta_didik'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['nta'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($row['tempat_lahir'] ?? '') ?></td>
                                            <td><?= !empty($row['tanggal_lahir']) ? htmlspecialchars($row['tanggal_lahir']) : '' ?></td>
                                            <td>
                                                <button class="btn btn-warning btn-sm edit-btn"
                                                    data-id="<?= (int)($row['id_peserta_didik_barung'] ?? 0) ?>"
                                                    data-tingkat="<?= (int)($row['id_tingkat_barung'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama_peserta_didik'] ?? '', ENT_QUOTES) ?>"
                                                    data-nta="<?= htmlspecialchars($row['nta'] ?? '', ENT_QUOTES) ?>"
                                                    data-tempat="<?= htmlspecialchars($row['tempat_lahir'] ?? '', ENT_QUOTES) ?>"
                                                    data-tanggal="<?= htmlspecialchars($row['tanggal_lahir'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-danger btn-sm delete-btn"
                                                    data-id="<?= (int)($row['id_peserta_didik_barung'] ?? 0) ?>"
                                                    data-tingkat="<?= (int)($row['id_tingkat_barung'] ?? 0) ?>"
                                                    data-nama="<?= htmlspecialchars($row['nama_peserta_didik'] ?? '', ENT_QUOTES) ?>"
                                                    type="button">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Peserta Didik <?= $selected_tingkat_name !== '' ? '(' . htmlspecialchars($selected_tingkat_name) . ')' : '' ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="add_peserta_didik" value="1">
                <input type="hidden" name="id_tingkat_barung" value="<?= (int)$selected_tingkat_id ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nama Peserta Didik</label>
                        <input type="text" class="form-control" name="nama_peserta_didik" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>NTA</label>
                        <input type="text" class="form-control" name="nta" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Tempat Lahir</label>
                        <input type="text" class="form-control" name="tempat_lahir" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Lahir</label>
                        <input type="date" class="form-control" name="tanggal_lahir">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel">Edit Peserta Didik</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="update_peserta_didik" value="1">
                <input type="hidden" name="id_peserta_didik_barung" id="edit_id_peserta_didik_barung" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tingkat</label>
                        <select class="form-control" name="id_tingkat_barung" id="edit_id_tingkat_barung" required>
                            <option value="">Pilih Tingkat</option>
                            <?php foreach ($tingkat_list as $t): ?>
                                <option value="<?= (int)($t['id_tingkat_barung'] ?? 0) ?>">
                                    <?= htmlspecialchars($t['nama_tingkat'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Peserta Didik</label>
                        <input type="text" class="form-control" name="nama_peserta_didik" id="edit_nama_peserta_didik" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>NTA</label>
                        <input type="text" class="form-control" name="nta" id="edit_nta" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Tempat Lahir</label>
                        <input type="text" class="form-control" name="tempat_lahir" id="edit_tempat_lahir" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Lahir</label>
                        <input type="date" class="form-control" name="tanggal_lahir" id="edit_tanggal_lahir">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel">Import Peserta Didik (Excel/CSV)</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="importForm">
                <input type="hidden" name="import_peserta_didik" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Tingkat</label>
                        <select class="form-control" name="id_tingkat_barung" required>
                            <option value="">Pilih Tingkat</option>
                            <?php foreach ($tingkat_list as $t): ?>
                                <option value="<?= (int)($t['id_tingkat_barung'] ?? 0) ?>" <?= ((int)($t['id_tingkat_barung'] ?? 0) === (int)$selected_tingkat_id) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['nama_tingkat'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">File akan di-import ke tingkat yang dipilih.</small>
                    </div>

                    <div class="form-group">
                        <label>File (.xlsx / .xls / .csv)</label>
                        <input type="file" class="form-control" name="import_file" accept=".xlsx,.xls,.csv" required>
                        <small class="text-muted">
                            Kolom yang didukung: <strong>Nama</strong>, <strong>NTA</strong>, <strong>Tempat Lahir</strong>, <strong>Tanggal Lahir</strong>.
                            Baris pertama boleh header (mis. "Nama Peserta Didik", "NTA").
                        </small>
                    </div>

                    <div class="d-none" id="importProgressWrap">
                        <div class="progress" style="height: 18px;">
                            <div id="importProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                                 role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                        </div>
                        <small class="text-muted d-block mt-2" id="importStatusText">Mengunggah file...</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <a class="btn btn-outline-primary import-template-btn" target="_blank"
                       href="?tingkat=<?= (int)$selected_tingkat_id ?>&download_template=1&format=xlsx">
                        Download Template (XLSX)
                    </a>
                    <a class="btn btn-outline-primary import-template-btn" target="_blank"
                       href="?tingkat=<?= (int)$selected_tingkat_id ?>&download_template=1&format=csv">
                        Download Template (CSV)
                    </a>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" id="importCloseBtn">Batal</button>
                    <button type="submit" class="btn btn-success" id="importSubmitBtn">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>

