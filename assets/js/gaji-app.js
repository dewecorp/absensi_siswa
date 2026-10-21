/* ============================================================
   SIMAD - Modul Gaji & Honor (helper tampilan mobile app)
   File: assets/js/gaji-app.js
   ------------------------------------------------------------
   Mengubah tabel pada halaman Gaji/Honor menjadi kartu di layar
   kecil: setiap sel diberi label (dari header tabel) otomatis,
   sehingga nominal/ketikaan tetap terbaca tanpa geser horizontal.
   ============================================================ */
(function () {
    'use strict';

    var MIN_COLUMNS = 3; // tabel 1-2 kolom tetap ditampilkan sebagai tabel

    /**
     * Susun label per kolom dari header tabel.
     * Menangani header 2 baris (rowspan/colspan) seperti tabel Legger.
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
                        // Label paling dalam (baris paling bawah) menang
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

        table.classList.add('gaji-table');

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
                // abaikan tabel yang bermasalah
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

    function start() {
        scan();

        if (typeof MutationObserver === 'undefined') {
            return;
        }

        var observer = new MutationObserver(schedule);
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    // DataTable selesai menggambar ulang -> label diperbarui
    if (window.jQuery) {
        window.jQuery(document).on('draw.dt', schedule);
    }
})();
