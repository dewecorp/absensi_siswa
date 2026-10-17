/* ============================================================
   SIMAD Filter Rekap - Auto Submit
   Semua kontrol filter (tanggal, bulan, kelas, guru, siswa)
   langsung mengirim form saat nilainya diubah, jadi tombol "Cari"
   tidak diperlukan lagi.

   Aturan supaya tidak dobel submit:
   - Kontrol yang punya onchange inline dibiarkan (sudah satu jalur).
   - Select "Jenis Filter" diatur masing-masing halaman
     (show/hide input + submit), jadi tidak diikat di sini.
   - Select yang masih kosong (mis. "Pilih Siswa...") tidak submit.
   Dipakai di semua halaman rekap lewat templates/footer.php dan
   templates/user_footer.php.
   ============================================================ */
"use strict";
(function () {
    // Jeda singkat supaya dua jalur event (native + jQuery/select2)
    // tidak menghasilkan dua submit untuk satu kali ubah.
    var LOCK_MS = 250;

    function findForm(el) {
        if (el.form) return el.form;
        return el.closest ? el.closest('form') : null;
    }

    function submitForm(form) {
        if (!form) return;
        if (form.requestSubmit) {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function isFilterForm(form) {
        return !!(form.querySelector &&
            form.querySelector('select[name="filter_type"], #filterType'));
    }

    function bindControl(el) {
        if (!el || el.dataset.filterAutoSubmit === '1') return;
        // Sudah punya jalur submit sendiri.
        if (el.hasAttribute('onchange')) return;
        // Jenis filter punya penanganan sendiri di tiap halaman.
        if (el.tagName === 'SELECT' && (el.name === 'filter_type' || el.id === 'filterType')) return;

        el.dataset.filterAutoSubmit = '1';

        var lastSubmit = 0;
        var handler = function () {
            // Belum dipilih (mis. "Pilih Siswa...") atau dikosongkan -> jangan submit.
            if (el.value === '' || el.value == null) return;
            var now = Date.now();
            if (now - lastSubmit < LOCK_MS) return;
            lastSubmit = now;
            submitForm(findForm(el));
        };

        // Jalur native + jalur jQuery (selectric/select2 memicu change lewat
        // jQuery yang tidak selalu sampai ke addEventListener).
        el.addEventListener('change', handler);
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.on) {
            window.jQuery(el).on('change', handler);
        }
    }

    function runAll() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            var form = forms[i];
            if (!isFilterForm(form)) continue;
            var controls = form.querySelectorAll('select, input[type="date"], input[type="month"]');
            for (var j = 0; j < controls.length; j++) {
                // Jangan sentuh kontrol di dalam modal (konteksnya beda).
                if (controls[j].closest && controls[j].closest('.modal')) continue;
                bindControl(controls[j]);
            }
        }
    }

    function init() {
        runAll();
        var scheduled = false;
        function schedule() {
            if (scheduled) return;
            scheduled = true;
            window.requestAnimationFrame(function () {
                scheduled = false;
                runAll();
            });
        }
        try {
            var observer = new MutationObserver(schedule);
            observer.observe(document.body || document.documentElement, { childList: true, subtree: true });
        } catch (err) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.FilterAutoSubmitRefresh = init;
})();
