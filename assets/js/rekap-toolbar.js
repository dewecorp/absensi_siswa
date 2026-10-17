/* ============================================================
   SIMAD Rekap Toolbar
   Menaruh tombol Ekspor (Excel/PDF) sejajar dengan baris filter pada
   halaman rekap (kehadiran harian, sholat berjamaah, sholat dhuha).

   Tombol dipindah ke DALAM <form> filter sebagai satu kolom Bootstrap
   (col-md-N dihitung otomatis supaya total 12 kolom) dan diberi label
   kosong setinggi label filter, jadi posisinya persis sejajar dengan
   kotak input filter (bukan lagi di baris terpisah).
   Fungsi onclick tombol tidak berubah.
   ============================================================ */
"use strict";
(function () {
    function isVisible(el) {
        return !!(el && getComputedStyle(el).display !== 'none');
    }

    function findFilterForm() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            if (forms[i].querySelector('select[name="filter_type"], #filterType')) {
                return forms[i];
            }
        }
        return null;
    }

    function findExportGroup(form) {
        var scope = (form.closest && form.closest('.card')) || document;
        var groups = scope.querySelectorAll('.btn-group');
        for (var i = 0; i < groups.length; i++) {
            if (groups[i].querySelector('[onclick^="export"]')) return groups[i];
        }
        return null;
    }

    function directExportHolder(form) {
        var kids = form.children || [];
        for (var i = 0; i < kids.length; i++) {
            if (kids[i].classList && kids[i].classList.contains('att-export-row')) return kids[i];
        }
        return null;
    }

    function run() {
        var form = findFilterForm();
        if (!form) return;

        if (directExportHolder(form)) return;

        var group = findExportGroup(form);
        if (!group) return;

        var oldRow = group.closest ? group.closest('.row') : null;

        form.classList.add('att-filter-form');

        var holder = document.createElement('div');
        holder.className = 'form-group col-12 att-export-row';

        // Label kosong: supaya tombol mulai tepat di baris input filter.
        var spacer = document.createElement('label');
        spacer.className = 'att-export-label';
        spacer.innerHTML = '&nbsp;';

        group.classList.remove('float-right');
        holder.appendChild(spacer);
        holder.appendChild(group);
        form.appendChild(holder);

        // Buang pembungkus lama (row + col kosong) supaya tidak ada sisa jarak.
        if (oldRow && oldRow !== form && oldRow.parentNode &&
            !oldRow.querySelector('button, input, select, textarea, table') &&
            !oldRow.textContent.trim()) {
            oldRow.parentNode.removeChild(oldRow);
        }
    }

    function init() {
        run();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.RekapToolbarRefresh = run;
})();
