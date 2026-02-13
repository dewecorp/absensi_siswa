<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['admin', 'tata_usaha', 'kepala_madrasah', 'guru', 'wali', 'siswa'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$can_edit = in_array($user_level, ['admin', 'tata_usaha']);

$page_title = 'Kalender Pendidikan';

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
    .fc-daygrid-day { cursor: pointer; }
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
        padding: 10px;
        border-radius: 5px;
        width: 100%;
    }
    .card-body {
        padding: 15px !important;
    }
    .card-header-form {
        display: flex;
        align-items: center;
    }

    /* Mobile Responsive Styles for FullCalendar */
    @media (max-width: 768px) {
        .fc .fc-toolbar {
            flex-direction: column;
            gap: 10px;
        }
        .fc .fc-toolbar-title {
            font-size: 1.2rem;
        }
        .fc .fc-button {
            padding: 0.4em 0.6em;
            font-size: 0.85rem;
        }
        #calendar {
            min-height: 400px;
            padding: 5px;
        }
        .fc-header-toolbar {
            margin-bottom: 1em !important;
        }
        /* Fix overlapping days in mobile */
        .fc-daygrid-day-number {
            font-size: 0.8rem;
        }
        .fc-col-header-cell-cushion {
            font-size: 0.75rem;
            padding: 2px !important;
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
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Master Data</a></div>
                <div class="breadcrumb-item">Kalender Pendidikan</div>
            </div>
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
                            while($thn = $stmt_thn->fetch()) {
                                $selected = ($thn['tahun_ajaran'] == $tahun_ajaran_aktif) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($thn['tahun_ajaran'])."' $selected>".htmlspecialchars($thn['tahun_ajaran'])."</option>";
                            }
                            if ($stmt_thn->rowCount() == 0) {
                                echo "<option value='".htmlspecialchars($tahun_ajaran_aktif)."' selected>".htmlspecialchars($tahun_ajaran_aktif)."</option>";
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
                        <div class="col-md-6">
                            <div class="card card-statistic-1 mb-0 shadow-sm border">
                                <div class="card-icon bg-primary">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Semester 1 (Juli - Des)</h4>
                                    </div>
                                    <div class="card-body" style="font-size: 14px;">
                                        <div class="d-flex justify-content-between">
                                            <span>Hari Kalender:</span>
                                            <b id="sem1-total">-</b>
                                        </div>
                                        <div class="d-flex justify-content-between text-danger">
                                            <span>Hari Libur:</span>
                                            <b id="sem1-holiday">-</b>
                                        </div>
                                        <div class="d-flex justify-content-between text-success">
                                            <span>Hari Efektif:</span>
                                            <b id="sem1-effective">-</b>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card card-statistic-1 mb-0 shadow-sm border">
                                <div class="card-icon bg-warning">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="card-wrap">
                                    <div class="card-header">
                                        <h4>Semester 2 (Jan - Juni)</h4>
                                    </div>
                                    <div class="card-body" style="font-size: 14px;">
                                        <div class="d-flex justify-content-between">
                                            <span>Hari Kalender:</span>
                                            <b id="sem2-total">-</b>
                                        </div>
                                        <div class="d-flex justify-content-between text-danger">
                                            <span>Hari Libur:</span>
                                            <b id="sem2-holiday">-</b>
                                        </div>
                                        <div class="d-flex justify-content-between text-success">
                                            <span>Hari Efektif:</span>
                                            <b id="sem2-effective">-</b>
                                        </div>
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
                 // Set tahun ajaran modal to match current filter
                 var currentFilter = $('#filterTahunAjaran').val();
                 if (currentFilter) {
                     $('#tahun_ajaran').val(currentFilter);
                 }
                 $('#tgl_mulai').val(info.dateStr);
                 $('#tgl_selesai').val(info.dateStr); // Set end date to same as start date
                 $('#modalKegiatan').modal('show');
                 $('#modalKegiatanLabel').text('Tambah Kegiatan');
             }
        },
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

        // Set tahun ajaran modal to match current filter
        var currentFilter = $('#filterTahunAjaran').val();
        if (currentFilter) {
            $('#tahun_ajaran').val(currentFilter);
        }
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
});
</script>
