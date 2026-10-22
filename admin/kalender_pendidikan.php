<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'tata_usaha', 'kepala_madrasah', 'guru', 'wali', 'siswa'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$can_edit = ($user_level === 'admin');

$page_title = 'Kalender Pendidikan';

$dashboard_url = '../admin/dashboard.php';
if ($user_level === 'tata_usaha') {
    $dashboard_url = '../tata_usaha/dashboard.php';
} elseif ($user_level === 'kepala_madrasah') {
    $dashboard_url = '../kepala/dashboard.php';
} elseif ($user_level === 'guru') {
    $dashboard_url = '../guru/dashboard.php';
} elseif ($user_level === 'wali') {
    $dashboard_url = '../wali/dashboard.php';
} elseif ($user_level === 'siswa') {
    $dashboard_url = '../siswa/dashboard.php';
}

// Get Active Semester & Year
$school_profile = getSchoolProfile($pdo);
$tahun_ajaran_aktif = $school_profile['tahun_ajaran'];

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<!-- FullCalendar CSS -->
<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' />
<style>
    .fc-event { cursor: pointer; }
    <?php if ($can_edit): ?>
    .fc-daygrid-day { cursor: pointer; }
    <?php else: ?>
    .fc-daygrid-day { cursor: default !important; }
    <?php endif; ?>
    .fc-event-title {
        white-space: normal !important;
        overflow: visible !important;
        font-size: 0.85em !important;
        padding: 2px 4px !important;
        line-height: 1.2 !important;
    }
    .fc-daygrid-event {
        white-space: normal !important;
        align-items: flex-start !important;
    }
    #calendar {
        background: white;
        padding: 14px;
        border-radius: 14px;
        width: 100%;
        border: 1px solid #e2e8f0;
    }
    .card-body {
        padding: 15px !important;
    }
    .card-header-form {
        display: flex;
        align-items: center;
    }

    /* Modern Kalender Semester Cards */
    #stats-container {
        margin-left: -8px;
        margin-right: -8px;
    }
    #stats-container > [class*="col-"] {
        padding-left: 8px;
        padding-right: 8px;
    }
    .kalender-sem-card {
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        border-radius: 16px;
        padding: 16px 18px;
        box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .kalender-sem-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
    }
    .kalender-sem-card.sem-1 {
        border-top: 4px solid #2563eb;
    }
    .kalender-sem-card.sem-2 {
        border-top: 4px solid #f59e0b;
    }
    .kalender-sem-head {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid #f1f5f9;
    }
    .kalender-sem-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        color: #ffffff;
        flex-shrink: 0;
    }
    .kalender-sem-card.sem-1 .kalender-sem-icon {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.28);
    }
    .kalender-sem-card.sem-2 .kalender-sem-icon {
        background: linear-gradient(135deg, #fbbf24 0%, #d97706 100%) !important;
        box-shadow: 0 4px 12px rgba(217, 119, 6, 0.28);
    }
    .kalender-sem-title h5 {
        font-size: 1rem;
        font-weight: 800;
        color: #0f172a;
        margin: 0;
        line-height: 1.25;
    }
    .kalender-sem-period {
        font-size: 0.78rem;
        font-weight: 600;
        color: #64748b;
    }
    .kalender-sem-body {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .kalender-sem-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 7px 12px;
        border-radius: 10px;
        background: #f8fafc;
        font-size: 0.86rem;
    }
    .kalender-sem-row .lbl {
        font-weight: 600;
        color: #475569;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .kalender-sem-row .val {
        font-weight: 800;
        color: #0f172a;
        font-size: 0.95rem;
    }
    .kalender-sem-row.row-holiday {
        background: #fef2f2;
    }
    .kalender-sem-row.row-holiday .lbl,
    .kalender-sem-row.row-holiday .val {
        color: #dc2626 !important;
    }
    .kalender-sem-row.row-effective {
        background: #f0fdf4;
    }
    .kalender-sem-row.row-effective .lbl,
    .kalender-sem-row.row-effective .val {
        color: #16a34a !important;
    }

    /* ============================================================
       FullCalendar Controls: Tombol & Chevron Kontras Tinggi
       ============================================================ */
    .fc .fc-toolbar.fc-header-toolbar {
        margin-bottom: 1.25rem !important;
    }
    .fc .fc-toolbar-title {
        font-size: 1.35rem !important;
        font-weight: 800 !important;
        color: #0f172a !important;
    }

    /* Tombol Utama (Prev, Next, Today, View) */
    .fc .fc-button {
        font-weight: 700 !important;
        border-radius: 10px !important;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.12) !important;
        transition: all .15s ease !important;
        border: 1px solid #1e293b !important;
        background-color: #1e293b !important;
        color: #ffffff !important;
        padding: 0.45em 0.85em !important;
    }
    .fc .fc-button:hover {
        background-color: #0f172a !important;
        border-color: #0f172a !important;
        color: #ffffff !important;
    }
    .fc .fc-button:focus {
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25) !important;
    }

    /* KONTUR CHEVRON ICON PUTIH TERANG KONTRAST TINGGI */
    .fc .fc-button .fc-icon,
    .fc-button .fc-icon,
    .main-content .fc-button .fc-icon,
    .main-content .fc-button span.fc-icon,
    .main-content #calendar span.fc-icon {
        color: #ffffff !important;
        fill: #ffffff !important;
        font-size: 1.45em !important;
        font-weight: 900 !important;
        line-height: 1 !important;
        display: inline-block !important;
        vertical-align: middle !important;
        opacity: 1 !important;
        filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.45));
    }

    /* Tombol 'hari ini' (Today) */
    .fc .fc-today-button {
        background-color: #475569 !important;
        border-color: #475569 !important;
        color: #ffffff !important;
        text-transform: capitalize !important;
    }
    .fc .fc-today-button:hover {
        background-color: #334155 !important;
        border-color: #334155 !important;
    }
    .fc .fc-today-button:disabled {
        background-color: #64748b !important;
        border-color: #64748b !important;
        color: #f1f5f9 !important;
        opacity: 0.85 !important;
    }

    /* Tombol View (Bulan, Minggu, Agenda) */
    .fc .fc-button-group {
        border-radius: 10px;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
    }
    .fc .fc-button-group > .fc-button {
        border-radius: 0 !important;
    }
    .fc .fc-button-group > .fc-button:first-child {
        border-top-left-radius: 10px !important;
        border-bottom-left-radius: 10px !important;
    }
    .fc .fc-button-group > .fc-button:last-child {
        border-top-right-radius: 10px !important;
        border-bottom-right-radius: 10px !important;
    }
    .fc .fc-button-primary:not(:disabled).fc-button-active,
    .fc .fc-button-primary:not(:disabled):active {
        background-color: #2563eb !important;
        border-color: #2563eb !important;
        color: #ffffff !important;
    }

    /* Mobile Responsive Styles for FullCalendar & Stats */
    @media (max-width: 767.98px) {
        /* Override rule 50% mobile-app.css agar stat semester tidak bertumpuk */
        .main-content #stats-container,
        #stats-container {
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
        .main-content #stats-container > [class*="col-"],
        #stats-container > [class*="col-"] {
            flex: 0 0 100% !important;
            max-width: 100% !important;
            width: 100% !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            margin-bottom: 12px !important;
        }

        .kalender-sem-card {
            padding: 14px 16px;
        }
        .kalender-sem-row {
            padding: 6px 10px;
            font-size: 0.82rem;
        }
        .kalender-sem-row .val {
            font-size: 0.9rem;
        }

        .fc .fc-toolbar {
            flex-direction: column !important;
            align-items: center !important;
            gap: 10px !important;
        }
        .fc .fc-toolbar-title {
            font-size: 1.15rem !important;
            text-align: center !important;
            order: 2;
        }
        .fc .fc-toolbar-chunk:first-child {
            order: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .fc .fc-toolbar-chunk:last-child {
            order: 3;
        }
        .fc .fc-button {
            padding: 0.45em 0.75em !important;
            font-size: 0.85rem !important;
        }
        .fc .fc-button .fc-icon {
            font-size: 1.35em !important;
        }
        #calendar {
            min-height: 420px;
            padding: 8px 6px;
            border-radius: 12px;
        }
        .fc-header-toolbar {
            margin-bottom: 1em !important;
        }
        .fc-daygrid-day-number {
            font-size: 0.78rem;
            padding: 3px !important;
        }
        .fc-col-header-cell-cushion {
            font-size: 0.75rem;
            padding: 4px 2px !important;
        }
        /* Make filter and add button stack nicely */
        .card-header {
            flex-direction: column;
            align-items: flex-start !important;
            gap: 10px;
        }
        .card-header-form {
            width: 100%;
            margin-right: 0 !important;
        }
        .card-header-action {
            width: 100%;
        }
        .card-header-action .btn {
            width: 100%;
        }
    }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Kalender Kegiatan</h4>
                    <div class="card-header-form mr-3">
                        <select id="filterTahunAjaran" class="form-control">
                            <option value="">Semua Tahun Ajaran</option>
                            <?php
                            $stmt_thn = $pdo->query("SELECT DISTINCT tahun_ajaran FROM tb_kalender_pendidikan ORDER BY tahun_ajaran DESC");
                            $tahun_dropdown = [];
                            while ($thn = $stmt_thn->fetch()) {
                                $tahun_dropdown[] = trim((string)$thn['tahun_ajaran']);
                            }
                            $tahun_dropdown = array_values(array_unique(array_filter($tahun_dropdown)));
                            if (!empty($tahun_ajaran_aktif) && !in_array($tahun_ajaran_aktif, $tahun_dropdown, true)) {
                                array_unshift($tahun_dropdown, $tahun_ajaran_aktif);
                            }
                            foreach ($tahun_dropdown as $thn) {
                                $selected = ($thn == $tahun_ajaran_aktif) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($thn)."' $selected>".htmlspecialchars($thn)."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="card-header-action">
                        <?php if ($can_edit): ?>
                        <button class="btn btn-primary" id="btnTambahKegiatan">
                            <i class="fas fa-plus"></i> Tambah Kegiatan
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row mb-4" id="stats-container">
                        <div class="col-md-6 mb-3">
                            <div class="kalender-sem-card sem-1">
                                <div class="kalender-sem-head">
                                    <div class="kalender-sem-icon">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div class="kalender-sem-title">
                                        <h5>Semester 1</h5>
                                        <span class="kalender-sem-period">Juli - Desember</span>
                                    </div>
                                </div>
                                <div class="kalender-sem-body">
                                    <div class="kalender-sem-row">
                                        <span class="lbl"><i class="fas fa-calendar-day mr-1 text-muted"></i>Hari Kalender:</span>
                                        <b class="val" id="sem1-total">-</b>
                                    </div>
                                    <div class="kalender-sem-row row-holiday">
                                        <span class="lbl"><i class="fas fa-umbrella-beach mr-1"></i>Hari Libur:</span>
                                        <b class="val" id="sem1-holiday">-</b>
                                    </div>
                                    <div class="kalender-sem-row row-effective">
                                        <span class="lbl"><i class="fas fa-check-circle mr-1"></i>Hari Efektif:</span>
                                        <b class="val" id="sem1-effective">-</b>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="kalender-sem-card sem-2">
                                <div class="kalender-sem-head">
                                    <div class="kalender-sem-icon">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                    <div class="kalender-sem-title">
                                        <h5>Semester 2</h5>
                                        <span class="kalender-sem-period">Januari - Juni</span>
                                    </div>
                                </div>
                                <div class="kalender-sem-body">
                                    <div class="kalender-sem-row">
                                        <span class="lbl"><i class="fas fa-calendar-day mr-1 text-muted"></i>Hari Kalender:</span>
                                        <b class="val" id="sem2-total">-</b>
                                    </div>
                                    <div class="kalender-sem-row row-holiday">
                                        <span class="lbl"><i class="fas fa-umbrella-beach mr-1"></i>Hari Libur:</span>
                                        <b class="val" id="sem2-holiday">-</b>
                                    </div>
                                    <div class="kalender-sem-row row-effective">
                                        <span class="lbl"><i class="fas fa-check-circle mr-1"></i>Hari Efektif:</span>
                                        <b class="val" id="sem2-effective">-</b>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id='calendar'></div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_edit): ?>
<!-- Modal Tambah/Edit Kegiatan -->
<div class="modal fade" id="modalKegiatan" tabindex="-1" role="dialog" aria-labelledby="modalKegiatanLabel" aria-hidden="true">
    <div class="modal-dialog" role="dialog">
        <div class="modal-content">
            <form id="formKegiatan">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalKegiatanLabel">Tambah Kegiatan</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_kalender" id="id_kalender">
                    <div class="form-group">
                        <label>Nama Kegiatan</label>
                        <input type="text" name="nama_kegiatan" id="nama_kegiatan" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Tahun Ajaran</label>
                        <input type="text" name="tahun_ajaran" id="tahun_ajaran" class="form-control" value="<?= htmlspecialchars($tahun_ajaran_aktif) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Jenis Kegiatan (Warna)</label>
                        <div class="selectgroup selectgroup-pills w-100">
                            <label class="selectgroup-item">
                                <input type="radio" name="warna" value="danger" class="selectgroup-input">
                                <span class="selectgroup-button selectgroup-button-icon text-danger"><i class="fas fa-circle mr-1"></i> Libur</span>
                            </label>
                            <label class="selectgroup-item">
                                <input type="radio" name="warna" value="success" class="selectgroup-input">
                                <span class="selectgroup-button selectgroup-button-icon text-success"><i class="fas fa-circle mr-1"></i> Asesmen</span>
                            </label>
                            <label class="selectgroup-item">
                                <input type="radio" name="warna" value="primary" class="selectgroup-input">
                                <span class="selectgroup-button selectgroup-button-icon text-primary"><i class="fas fa-circle mr-1"></i> Ujian</span>
                            </label>
                            <label class="selectgroup-item">
                                <input type="radio" name="warna" value="warning" class="selectgroup-input">
                                <span class="selectgroup-button selectgroup-button-icon text-warning"><i class="fas fa-circle mr-1"></i> Rapor</span>
                            </label>
                            <label class="selectgroup-item">
                                <input type="radio" name="warna" value="info" class="selectgroup-input" checked>
                                <span class="selectgroup-button selectgroup-button-icon text-info"><i class="fas fa-circle mr-1"></i> Lainnya</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Durasi Kegiatan</label>
                        <div class="selectgroup w-100">
                            <label class="selectgroup-item">
                                <input type="radio" name="durasi" value="1" class="selectgroup-input" checked>
                                <span class="selectgroup-button">1 Hari</span>
                            </label>
                            <label class="selectgroup-item">
                                <input type="radio" name="durasi" value="more" class="selectgroup-input">
                                <span class="selectgroup-button">Lebih dari 1 Hari</span>
                            </label>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6" id="col-mulai">
                            <div class="form-group">
                                <label id="label-mulai">Tanggal</label>
                                <input type="date" name="tgl_mulai" id="tgl_mulai" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6 d-none" id="col-selesai">
                            <div class="form-group">
                                <label>Sampai Tanggal</label>
                                <input type="date" name="tgl_selesai" id="tgl_selesai" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-whitesmoke br">
                    <button type="button" class="btn btn-danger d-none" id="btnHapusKegiatan">Hapus</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once '../templates/footer.php'; ?>

<!-- Scripts are loaded AFTER footer to ensure jQuery is available -->
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/id.js'></script>
<script>
$(document).ready(function() {
    var calendarEl = document.getElementById('calendar');
    if (!calendarEl) return;

    var sessionType = '<?= $_GET['session_type'] ?? "" ?>';
    
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: window.innerWidth < 768 ? 'listMonth' : 'dayGridMonth',
        locale: 'id',
        height: 'auto', // Adjust height to content to prevent double scrollbars and fill space
        contentHeight: 'auto',
        expandRows: true, // Make rows expand to fill height if needed
        headerToolbar: {
            left: window.innerWidth < 768 ? 'prev,next' : 'prev,next today',
            center: 'title',
            right: window.innerWidth < 768 ? 'listMonth,dayGridMonth' : 'dayGridMonth,dayGridWeek,listMonth'
        },
        handleWindowResize: true,
        windowResize: function(view) {
            if (window.innerWidth < 768) {
                calendar.changeView('listMonth');
            } else {
                calendar.changeView('dayGridMonth');
            }
        },
        events: function(info, successCallback, failureCallback) {
            $.ajax({
                url: 'ajax_kalender.php?action=fetch' + (sessionType ? '&session_type=' + sessionType : ''),
                type: 'GET',
                data: {
                    tahun_ajaran: $('#filterTahunAjaran').val()
                },
                dataType: 'json',
                success: function(data) {
                    successCallback(data);
                },
                error: function() {
                    failureCallback();
                }
            });
        },
        <?php if ($can_edit): ?>
        dateClick: function(info) {
            // Check if there's an event on this date
            var events = calendar.getEvents();
            var clickedDate = info.dateStr;
            var existingEvent = events.find(function(e) {
                var eventStart = e.startStr.split('T')[0];
                return eventStart === clickedDate;
            });

            if (existingEvent) {
                // If event exists, trigger eventClick logic
                calendar.trigger('eventClick', {
                    event: existingEvent,
                    el: null,
                    jsEvent: null,
                    view: calendar.view
                });
            } else {
                 // Otherwise, normal add logic
                 resetForm();
                 // Default ke tahun ajaran aktif profil (field sudah terisi default dari PHP)
                 $('#tgl_mulai').val(info.dateStr);
                 $('#tgl_selesai').val(info.dateStr); // Set end date to same as start date
                 $('#modalKegiatan').modal('show');
                 $('#modalKegiatanLabel').text('Tambah Kegiatan');
             }
        },
        <?php endif; ?>
        eventClick: function(info) {
            <?php if ($can_edit): ?>
            resetForm();
            var event = info.event;
            
            console.log("Event Data:", event); // Debugging
            console.log("Extended Props:", event.extendedProps); // Debugging

            // Populate basic fields
            $('#id_kalender').val(event.id);
            
            // Try different ways to get the name
            var nama = event.extendedProps.nama_kegiatan || event.title || "";
            $('#nama_kegiatan').val(nama);
            
            $('#tahun_ajaran').val(event.extendedProps.tahun_ajaran);
            
            // Set warna
            if (event.extendedProps.warna) {
                $('input[name="warna"][value="' + event.extendedProps.warna + '"]').prop('checked', true);
            } else {
                $('input[name="warna"][value="info"]').prop('checked', true);
            }
            
            // Date handling
            var start = event.startStr.split('T')[0];
            $('#tgl_mulai').val(start);
            
            if (event.end && event.endStr) {
                var endStr = event.endStr.split('T')[0];
                
                // FullCalendar's end date is exclusive (e.g., if it ends on 2026-03-01, endStr is 2026-03-02)
                // We need to subtract 1 day to get the actual end date for our database
                var endDateObj = new Date(event.end);
                endDateObj.setDate(endDateObj.getDate() - 1);
                
                // Get local YYYY-MM-DD
                var year = endDateObj.getFullYear();
                var month = ('0' + (endDateObj.getMonth() + 1)).slice(-2);
                var day = ('0' + endDateObj.getDate()).slice(-2);
                var endFormatted = year + '-' + month + '-' + day;
                
                // If after subtracting 1 day it's still different from start, it's multi-day
                if (endFormatted !== start) {
                    $('input[name="durasi"][value="more"]').prop('checked', true).trigger('change');
                    $('#tgl_selesai').val(endFormatted);
                } else {
                    $('input[name="durasi"][value="1"]').prop('checked', true).trigger('change');
                }
            } else {
                $('input[name="durasi"][value="1"]').prop('checked', true).trigger('change');
            }
            
            $('#btnHapusKegiatan').removeClass('d-none');
            $('#modalKegiatanLabel').text('Edit Kegiatan');
            $('#modalKegiatan').modal('show');
            <?php else: ?>
            // Show read-only details for other roles
            var event = info.event;
            var nama = event.extendedProps.nama_kegiatan || event.title || "";
            var start = event.startStr.split('T')[0];
            var endFormatted = start;
            
            if (event.end && event.endStr) {
                var endDateObj = new Date(event.end);
                endDateObj.setDate(endDateObj.getDate() - 1);
                var year = endDateObj.getFullYear();
                var month = ('0' + (endDateObj.getMonth() + 1)).slice(-2);
                var day = ('0' + endDateObj.getDate()).slice(-2);
                endFormatted = year + '-' + month + '-' + day;
            }

            Swal.fire({
                title: nama,
                html: `
                    <div class="text-left">
                        <p><b>Mulai:</b> ${start}</p>
                        <p><b>Selesai:</b> ${endFormatted}</p>
                        <p><b>Tahun Ajaran:</b> ${event.extendedProps.tahun_ajaran}</p>
                        <p><b>Kategori:</b> ${event.extendedProps.warna == 'danger' ? 'Libur' : 'Kegiatan'}</p>
                    </div>
                `,
                icon: 'info',
                confirmButtonText: 'Tutup'
            });
            <?php endif; ?>
        }
    });
    calendar.render();
    loadStats();

    // Filter Tahun Ajaran
    $('#filterTahunAjaran').on('change', function() {
        calendar.refetchEvents();
        loadStats();
    });

    function loadStats() {
        var tahun_ajaran = $('#filterTahunAjaran').val();
        if (!tahun_ajaran) return;

        $.ajax({
            url: 'ajax_kalender.php?action=get_stats' + (sessionType ? '&session_type=' + sessionType : ''),
            type: 'GET',
            data: { tahun_ajaran: tahun_ajaran },
            dataType: 'json',
            success: function(data) {
                if (data.semester_1) {
                    $('#sem1-total').text(data.semester_1.total + ' Hari');
                    $('#sem1-holiday').text(data.semester_1.holiday + ' Hari');
                    $('#sem1-effective').text(data.semester_1.effective + ' Hari');
                }
                if (data.semester_2) {
                    $('#sem2-total').text(data.semester_2.total + ' Hari');
                    $('#sem2-holiday').text(data.semester_2.holiday + ' Hari');
                    $('#sem2-effective').text(data.semester_2.effective + ' Hari');
                }
            }
        });
    }

    <?php if ($can_edit): ?>
    // Toggle durasi logic
    $('input[name="durasi"]').on('change', function() {
        if ($(this).val() === 'more') {
            $('#col-selesai').removeClass('d-none');
            $('#tgl_selesai').attr('required', true);
            $('#label-mulai').text('Mulai Tanggal');
            $('#col-mulai').removeClass('col-md-12').addClass('col-md-6');
        } else {
            $('#col-selesai').addClass('d-none');
            $('#tgl_selesai').removeAttr('required').val('');
            $('#label-mulai').text('Tanggal');
            $('#col-mulai').removeClass('col-md-6').addClass('col-md-12');
        }
    });

    // Sync tgl_selesai with tgl_mulai when tgl_mulai changes
    $('#tgl_mulai').on('change', function() {
        var startVal = $(this).val();
        var endVal = $('#tgl_selesai').val();
        
        // If end date is empty or before start date, update it
        if (!endVal || endVal < startVal) {
            $('#tgl_selesai').val(startVal);
        }
    });

    $('#btnTambahKegiatan').on('click', function() {
        resetForm();
        
        // Get current date from calendar to set default date in datepicker
        var calDate = calendar.getDate();
        var year = calDate.getFullYear();
        var month = ('0' + (calDate.getMonth() + 1)).slice(-2);
        var day = ('0' + calDate.getDate()).slice(-2);
        var defaultDate = year + '-' + month + '-' + day;
        $('#tgl_mulai').val(defaultDate);
        $('#tgl_selesai').val(defaultDate); // Set end date to same as start date

        $('#modalKegiatan').modal('show');
        $('#modalKegiatanLabel').text('Tambah Kegiatan');
    });

    function resetForm() {
        $('#formKegiatan')[0].reset();
        $('#id_kalender').val('');
        $('input[name="warna"][value="info"]').prop('checked', true);
        $('input[name="durasi"][value="1"]').prop('checked', true).trigger('change');
        $('#btnHapusKegiatan').addClass('d-none');
    }

    // Submit Form
    $('#formKegiatan').on('submit', function(e) {
        e.preventDefault();
        var formData = $(this).serialize();
        var action = $('#id_kalender').val() ? 'update' : 'create';

        $.ajax({
            url: 'ajax_kalender.php?action=' + action + (sessionType ? '&session_type=' + sessionType : ''),
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    $('#modalKegiatan').modal('hide');
                    calendar.refetchEvents();
                    loadStats(); // Update stats after adding/editing
                    Swal.fire({
                        title: 'Berhasil',
                        text: response.message,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire('Gagal', response.message, 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
            }
        });
    });

    // Delete Activity
    $('#btnHapusKegiatan').on('click', function() {
        var id = $('#id_kalender').val();
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: 'Kegiatan ini akan dihapus permanen!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax_kalender.php?action=delete' + (sessionType ? '&session_type=' + sessionType : ''),
                    type: 'POST',
                    data: { id_kalender: id },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            $('#modalKegiatan').modal('hide');
                            calendar.refetchEvents();
                            loadStats(); // Update stats after deleting
                            Swal.fire({
                                title: 'Berhasil',
                                text: response.message,
                                icon: 'success',
                                timer: 1500,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire('Gagal', response.message, 'error');
                        }
                    }
                });
            }
        });
    });
    <?php endif; ?>
});
</script>
