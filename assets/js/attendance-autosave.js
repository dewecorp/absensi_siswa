/* ============================================================
   Auto-save kehadiran (tanpa tombol simpan)
   Klik tombol status -> langsung tersimpan via AJAX.
   Dipakai: Kehadiran Harian, Les Siswa, Sholat Dhuha,
   Sholat Berjamaah (guru/wali/admin).
   ============================================================ */
(function () {
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
            if (typeof Swal === 'undefined') return;
            if (resp && resp.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Tersimpan',
                    text: 'Kehadiran diperbarui',
                    timer: 900,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: 'Gagal',
                    text: (resp && resp.error) ? resp.error : 'Gagal menyimpan kehadiran',
                    confirmButtonText: 'OK'
                });
            }
        })
        .catch(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Gagal menyimpan kehadiran',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        });
    };
})();
