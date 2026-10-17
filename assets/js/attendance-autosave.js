/* ============================================================
   Auto-save kehadiran (tanpa tombol simpan)
   Klik tombol status -> langsung tersimpan via AJAX.
   Dipakai: Kehadiran Harian, Les Siswa, Sholat Dhuha,
   Sholat Berjamaah (guru/wali/admin).
   ============================================================ */
(function () {
    // Konfigurasi toastr default jika library toastr aktif
    if (typeof toastr !== 'undefined') {
        toastr.options = {
            closeButton: false,
            debug: false,
            newestOnTop: true,
            progressBar: false,
            positionClass: 'toast-top-right',
            preventDuplicates: false,
            onclick: null,
            showDuration: '200',
            hideDuration: '300',
            timeOut: '1500',
            extendedTimeOut: '800',
            showEasing: 'swing',
            hideEasing: 'linear',
            showMethod: 'fadeIn',
            hideMethod: 'fadeOut'
        };
    }

    function showToast(type, message, title) {
        if (typeof toastr !== 'undefined') {
            toastr.options = toastr.options || {};
            toastr.options.positionClass = 'toast-top-right';
            toastr.options.timeOut = 1500;
            if (type === 'success') {
                toastr.success(message, title || 'Tersimpan');
            } else if (type === 'warning') {
                toastr.warning(message, title || 'Peringatan');
            } else {
                toastr.error(message, title || 'Error');
            }
            return;
        }

        // Fallback jika toastr belum termuat
        if (typeof Swal !== 'undefined') {
            if (type === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Tersimpan',
                    text: message,
                    timer: 900,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    icon: type === 'warning' ? 'warning' : 'error',
                    title: type === 'warning' ? 'Gagal' : 'Error',
                    text: message,
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        }
    }

    window.simadAutoSaveAttendance = function (idSiswa, status) {
        var tanggalEl = document.getElementById('tanggalInput');
        var tanggal = tanggalEl && tanggalEl.value
            ? tanggalEl.value
            : new Date().toISOString().slice(0, 10);

        var body = new URLSearchParams();
        body.append('single_absensi_siswa', '1');
        body.append('id_siswa', idSiswa);
        body.append('tanggal', tanggal);
        body.append('status', status);

        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        })
        .then(function (res) { return res.json(); })
        .then(function (resp) {
            if (resp && resp.success) {
                showToast('success', 'Kehadiran diperbarui', 'Tersimpan');
            } else {
                showToast('warning', (resp && resp.error) ? resp.error : 'Gagal menyimpan kehadiran', 'Gagal');
            }
        })
        .catch(function () {
            showToast('error', 'Gagal menyimpan kehadiran', 'Error');
        });
    };
})();
