/* ============================================================
   SIMAD - Modul Tabungan Siswa (helper tampilan mobile app)
   File: assets/js/tabungan-app.js
   ------------------------------------------------------------
   1. Tabel pada halaman Tabungan berubah menjadi kartu di layar
      kecil: setiap sel diberi label (dari header tabel) otomatis.
   2. Input nominal rupiah diformat otomatis (angka + pemisah ribuan).
   3. Select2 untuk pencarian siswa (jika pustaka tersedia).
   4. Konfirmasi hapus menggunakan SweetAlert2 bila tersedia.
   ============================================================ */
(function () {
    'use strict';

    var MIN_COLUMNS = 3;

    /**
     * Susun label per kolom dari header tabel.
     * Menangani header 2 baris (rowspan/colspan).
     */
    function columnLabels(table) {
        var head = table.tHead;
        if (!head || !head.rows.length) {
            return [];
        }

        var occupied = [];
        var labels = [];

        for (var r = 0; r < head.rows.length; r++) {
            var row = head.rows[r];
            var col = 0;

            for (var i = 0; i < row.cells.length; i++) {
                while (occupied[col] > 0) {
                    col++;
                }

                var cell = row.cells[i];
                var colspan = cell.colSpan || 1;
                var rowspan = cell.rowSpan || 1;
                var text = (cell.textContent || '').replace(/\s+/g, ' ').trim();

                for (var k = 0; k < colspan; k++) {
                    if (text) {
                        labels[col] = text;
                    }
                    if (rowspan > 1) {
                        occupied[col] = rowspan;
                    }
                    col++;
                }
            }

            for (var j = 0; j < occupied.length; j++) {
                if (occupied[j] > 0) {
                    occupied[j] = occupied[j] - 1;
                }
            }
        }

        return labels;
    }

    function applyLabels(table) {
        var labels = columnLabels(table);
        if (labels.length < MIN_COLUMNS) {
            return;
        }

        var body = table.tBodies[0];
        if (!body) {
            return;
        }

        table.classList.add('tabungan-table');

        for (var r = 0; r < body.rows.length; r++) {
            var row = body.rows[r];
            for (var c = 0; c < row.cells.length && c < labels.length; c++) {
                var cell = row.cells[c];
                if (cell.getAttribute('data-label')) {
                    continue;
                }
                var label = labels[c];
                if (!label) {
                    continue;
                }
                cell.setAttribute('data-label', label);
            }
        }
    }

    function scan() {
        var tables = document.querySelectorAll('.main-content table');
        for (var i = 0; i < tables.length; i++) {
            try {
                applyLabels(tables[i]);
            } catch (e) {
                /* abaikan tabel bermasalah */
            }
        }
    }

    var timer = null;
    function schedule() {
        if (timer) {
            return;
        }
        timer = window.setTimeout(function () {
            timer = null;
            scan();
        }, 120);
    }

    /* ---------- Format input rupiah ---------- */
    function formatRupiah(value) {
        var digits = String(value == null ? '' : value).replace(/[^0-9]/g, '');
        if (digits === '') {
            return '';
        }
        return Number(digits).toLocaleString('id-ID');
    }

    function initRupiahInputs() {
        var inputs = document.querySelectorAll('.tb-rupiah');
        for (var i = 0; i < inputs.length; i++) {
            (function (input) {
                input.setAttribute('inputmode', 'numeric');
                input.setAttribute('autocomplete', 'off');

                var sync = function () {
                    var caretEnd = input.selectionStart;
                    var digits = input.value.replace(/[^0-9]/g, '');
                    input.value = digits === '' ? '' : formatRupiah(digits);
                    var pos = input.value.length;
                    try {
                        input.setSelectionRange(pos, pos);
                    } catch (e) { /* ignore */ }
                    if (caretEnd === null) { /* ignore */ }
                };

                input.addEventListener('input', sync);
                input.addEventListener('blur', sync);
                input.addEventListener('focus', function () {
                    input.value = input.value.replace(/[^0-9]/g, '');
                });
            })(inputs[i]);
        }
    }

    /* ---------- Select2 ---------- */
    function select2Options($el) {
        return {
            width: '100%',
            placeholder: $el.attr('data-placeholder') || 'Cari...',
            allowClear: true,
            language: {
                searchPlaceholder: 'Cari...',
                noResults: function () { return 'Tidak ada data'; }
            }
        };
    }

    /* Select di dalam modal yang belum tampil: inisialisasi ditunda sampai modal dibuka
       (kalau tidak, dropdown select2 bisa berukuran 0 dan tidak bisa diklik). */
    function select2DiModalTertutup($el) {
        var $modal = $el.closest('.modal');
        if (!$modal.length) {
            return false;
        }
        return !$modal.hasClass('show');
    }

    function initSelect2() {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }
        var $ = window.jQuery;
        $('.tb-select2').each(function () {
            var $el = $(this);
            if ($el.data('tb-select2-ready')) {
                return;
            }
            if (select2DiModalTertutup($el)) {
                $el.data('tb-select2-deferred', true);
                return;
            }
            $el.data('tb-select2-ready', true);
            $el.removeData('tb-select2-deferred');
            $el.select2(select2Options($el));
        });
    }

    /* Select2 di dalam modal: dijalankan ulang saat modal selesai dibuka. */
    function initSelect2DiModal($modal) {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }
        var $ = window.jQuery;
        $modal.find('.tb-select2').each(function () {
            var $el = $(this);
            if ($el.data('tb-select2-ready')) {
                return;
            }
            $el.data('tb-select2-ready', true);
            $el.removeData('tb-select2-deferred');
            $el.select2(select2Options($el));
        });
    }

    /* ---------- Konfirmasi hapus ---------- */
    function initKonfirmasiHapus() {
        var buttons = document.querySelectorAll('[data-tb-konfirmasi]');
        for (var i = 0; i < buttons.length; i++) {
            // Tombol yang sudah punya konfirmasi sendiri (data-tb-native) dilewati.
            if (buttons[i].hasAttribute('data-tb-native')) {
                continue;
            }
            (function (btn) {
                btn.addEventListener('click', function (event) {
                    var pesan = btn.getAttribute('data-tb-konfirmasi') || 'Yakin ingin menghapus data ini?';
                    var judul = btn.getAttribute('data-tb-judul') || 'Konfirmasi Hapus';

                    if (window.Swal) {
                        event.preventDefault();
                        window.Swal.fire({
                            title: judul,
                            text: pesan,
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Ya, Hapus',
                            cancelButtonText: 'Batal',
                            confirmButtonColor: '#e11d48',
                            reverseButtons: true
                        }).then(function (result) {
                            if (result.isConfirmed) {
                                if (btn.tagName === 'A') {
                                    window.location.href = btn.getAttribute('href');
                                } else if (btn.form) {
                                    btn.form.submit();
                                }
                            }
                        });
                    } else if (!window.confirm(judul + '\n\n' + pesan)) {
                        event.preventDefault();
                    }
                });
            })(buttons[i]);
        }
    }

    function start() {
        scan();
        initRupiahInputs();
        initSelect2();
        initKonfirmasiHapus();

        if (window.jQuery) {
            /* Select2 pada modal baru aktif setelah modal selesai dibuka. */
            window.jQuery(document).on('shown.bs.modal', '.modal', function () {
                initSelect2DiModal(window.jQuery(this));
            });
        }

        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function () {
                schedule();
                initRupiahInputs();
                initSelect2();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    /* DataTable selesai menggambar ulang -> label diperbarui */
    if (window.jQuery) {
        window.jQuery(document).on('draw.dt', schedule);
    }
})();
