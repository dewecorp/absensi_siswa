<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and authorized
if (!isAuthorized(['admin', 'guru', 'wali', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$page_title = 'Scan Absensi QR';

// Additional JS for this page
$js_libs = [
    "https://unpkg.com/html5-qrcode"
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Scan Absensi QR Code</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Scan Absensi</a></div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12 col-md-6 col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h4>Scanner</h4>
                        </div>
                        <div class="card-body">
                            <style>
                                #reader {
                                    width: 100%;
                                    border-radius: 5px;
                                    overflow: hidden;
                                }
                                #reader video {
                                    width: 100% !important;
                                    height: auto !important;
                                    object-fit: cover;
                                    border-radius: 5px;
                                }
                            </style>
                            <div id="reader"></div>
                            <div class="mt-3 text-center">
                                <button id="start-scan" class="btn btn-primary btn-lg btn-block">
                                    <i class="fas fa-camera"></i> Mulai Scan
                                </button>
                                <button id="stop-scan" class="btn btn-danger btn-lg btn-block" style="display: none;">
                                    <i class="fas fa-stop"></i> Stop Scan
                                </button>
                            </div>
                            <div class="mt-3">
                                <div class="form-group">
                                    <label>Pilih Kamera</label>
                                    <select id="camera-select" class="form-control">
                                        <option value="">Default Camera</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-md-6 col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h4>Hasil Scan Terakhir</h4>
                        </div>
                        <div class="card-body" id="scan-result-container">
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-qrcode fa-5x mb-3"></i>
                                <p>Belum ada data yang discan.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mt-4">
                        <div class="card-header">
                            <h4>Log Aktivitas Scan</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-sm w-100 text-center">
                                    <thead>
                                        <tr>
                                            <th>Waktu</th>
                                            <th>Siswa</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="scan-log-body">
                                        <!-- Log items will be added here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Audio for beep sound -->
<audio id="beep-sound" src="../assets/audio/beep.mp3" preload="auto"></audio>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const html5QrCode = new Html5Qrcode("reader");
    let isScanning = false;
    const beepSound = document.getElementById('beep-sound');
    
    // Fallback if beep sound file doesn't exist
    function playWebAudioBeep() {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        
        osc.connect(gain);
        gain.connect(ctx.destination);
        
        osc.type = "sine";
        osc.frequency.value = 800; // Hz
        gain.gain.value = 0.1; // Volume
        
        osc.start();
        setTimeout(() => {
            osc.stop();
            ctx.close();
        }, 200); // 200ms
    }

    function playBeep() {
        if (beepSound) {
            beepSound.play().catch(e => {
                console.log('Audio play failed, using fallback', e);
                playWebAudioBeep();
            });
        } else {
            playWebAudioBeep();
        }
    }

    function onScanSuccess(decodedText, decodedResult) {
        // Prevent multiple scans of the same code in short time
        if (window.lastScannedCode === decodedText && (Date.now() - window.lastScanTime < 3000)) {
            return;
        }
        
        window.lastScannedCode = decodedText;
        window.lastScanTime = Date.now();
        
        playBeep();
        
        // Process the scanned code
        processAttendance(decodedText);
    }

    function onScanFailure(error) {
        // handle scan failure, usually better to ignore and keep scanning.
        // console.warn(`Code scan error = ${error}`);
    }
    
    function processAttendance(nisn) {
        // Show loading state
        Swal.fire({
            title: 'Memproses...',
            text: 'Sedang mencari data siswa...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        $.ajax({
            url: 'process_scan.php',
            type: 'POST',
            dataType: 'json',
            data: { nisn: nisn },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: response.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    
                    updateResultView(response.data);
                    addToLog(response.data);
                } else {
                    Swal.fire({
                        icon: response.icon || 'error',
                        title: response.title || 'Gagal!',
                        text: response.message
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Terjadi kesalahan koneksi server.'
                });
            }
        });
    }
    
    function updateResultView(data) {
        const container = document.getElementById('scan-result-container');
        const statusBadge = data.keterangan == 'Hadir' ? 'success' : 'warning';
        
        const html = `
            <div class="text-center animate__animated animate__fadeIn">
                <img src="../assets/img/avatar/avatar-1.png" class="rounded-circle mb-3" width="100">
                <h4>${data.nama_siswa}</h4>
                <p class="text-muted mb-1">${data.nisn}</p>
                <p class="text-muted mb-3">${data.kelas}</p>
                
                <div class="alert alert-${statusBadge} mb-0">
                    <i class="fas fa-check-circle mr-2"></i>
                    <strong>${data.keterangan}</strong> pada ${data.jam_masuk}
                </div>
            </div>
        `;
        
        container.innerHTML = html;
    }
    
    function addToLog(data) {
        const tbody = document.getElementById('scan-log-body');
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${data.jam_masuk}</td>
            <td>${data.nama_siswa}</td>
            <td><div class="badge badge-success">${data.keterangan}</div></td>
        `;
        
        if (tbody.firstChild) {
            tbody.insertBefore(row, tbody.firstChild);
        } else {
            tbody.appendChild(row);
        }
        
        // Keep only last 10 items
        if (tbody.children.length > 10) {
            tbody.removeChild(tbody.lastChild);
        }
    }

    // Camera handling
    const startButton = document.getElementById('start-scan');
    const stopButton = document.getElementById('stop-scan');
    const cameraSelect = document.getElementById('camera-select');

    // Check if context is secure
    const isSecureContext = window.isSecureContext;
    if (!isSecureContext && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
        Swal.fire({
            icon: 'warning',
            title: 'Koneksi Tidak Aman',
            html: 'Akses kamera mungkin diblokir karena website tidak menggunakan HTTPS.<br>Silakan gunakan <b>localhost</b> atau aktifkan <b>HTTPS</b>.',
            footer: '<a href="https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts" target="_blank">Pelajari lebih lanjut</a>'
        });
    }

    function initCamera() {
        Html5Qrcode.getCameras().then(devices => {
            if (devices && devices.length) {
                // Clear existing options first (except default if needed, but we replace all)
                cameraSelect.innerHTML = '';
                
                devices.forEach(device => {
                    const option = document.createElement('option');
                    option.value = device.id;
                    option.text = device.label || `Camera ${cameraSelect.length + 1}`;
                    cameraSelect.appendChild(option);
                });
                
                startButton.disabled = false;
            } else {
                Swal.fire('Error', 'Tidak ada kamera yang terdeteksi. Pastikan kamera terhubung.', 'error');
            }
        }).catch(err => {
            console.error("Error getting cameras", err);
            
            // Detailed error handling
            let errorMessage = 'Gagal mengakses kamera.';
            let errorDetails = err.name || err;
            
            if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                errorMessage = 'Izin kamera ditolak. Silakan izinkan akses kamera di browser Anda.';
            } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                errorMessage = 'Kamera tidak ditemukan. Pastikan kamera terpasang dengan benar.';
            } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
                errorMessage = 'Kamera sedang digunakan oleh aplikasi lain.';
            } else if (err.name === 'OverconstrainedError') {
                errorMessage = 'Kamera tidak memenuhi spesifikasi yang diminta.';
            } else if (err.name === 'SecurityError') {
                errorMessage = 'Akses kamera diblokir karena alasan keamanan (mungkin HTTPS diperlukan).';
            }
            
            Swal.fire({
                icon: 'error',
                title: 'Akses Kamera Gagal',
                html: `${errorMessage}<br><br><small class="text-muted">Code: ${errorDetails}</small>`,
                footer: '<button class="btn btn-sm btn-info" onclick="requestCameraPermission()">Coba Minta Izin Manual</button>'
            });
        });
    }
    
    // Function to manually request permission
    window.requestCameraPermission = function() {
        navigator.mediaDevices.getUserMedia({ video: true })
            .then(function(stream) {
                // Permission granted
                stream.getTracks().forEach(track => track.stop()); // Stop immediately
                Swal.fire('Sukses', 'Izin kamera diberikan! Silakan refresh halaman.', 'success').then(() => {
                    location.reload();
                });
            })
            .catch(function(err) {
                Swal.fire('Gagal', 'Masih tidak dapat mengakses kamera: ' + err.name, 'error');
            });
    };

    // Initialize
    initCamera();

    startButton.addEventListener('click', () => {
        const cameraId = cameraSelect.value;
        if (!cameraId) {
            Swal.fire('Warning', 'Pilih kamera terlebih dahulu', 'warning');
            return;
        }
        
        html5QrCode.start(
            cameraId, 
            {
                fps: 10,
                qrbox: { width: 250, height: 250 }
            },
            onScanSuccess,
            onScanFailure
        ).then(() => {
            startButton.style.display = 'none';
            stopButton.style.display = 'block';
            cameraSelect.disabled = true;
            isScanning = true;
        }).catch(err => {
            console.error("Error starting scanner", err);
            Swal.fire('Error', 'Gagal memulai kamera: ' + err, 'error');
        });
    });
    
    stopButton.addEventListener('click', () => {
        html5QrCode.stop().then(() => {
            startButton.style.display = 'block';
            stopButton.style.display = 'none';
            cameraSelect.disabled = false;
            isScanning = false;
            
            // Clear the video placeholder
            document.getElementById('reader').innerHTML = '';
        }).catch(err => {
            console.error("Failed to stop scanning", err);
        });
    });
});
</script>

<?php
include '../templates/footer.php';
?>