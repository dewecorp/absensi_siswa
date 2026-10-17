/* ============================================================
   SIMAD Rekap Tabel -> Kartu (semester & rekap les)
   Membuat versi kartu dari tabel rekap lebar (#semesterTable atau
   tabel ber-class semester-card-source), supaya tidak perlu geser
   kiri/kanan; tabel lama disembunyikan (tetap dipakai untuk ekspor
   dan cetak).
   Struktur tabel dibaca dari <thead> (dukung colspan & sub-header),
   jadi halaman absensi, sholat, dan rekap les bisa pakai tabel sama.
   ============================================================ */
"use strict";
(function () {

    // Pemetaan label kolom -> nama panjang + warna kartu
    var LABEL_MAP = {
        H: { label: 'Hadir', cls: 'stat-hadir' },
        S: { label: 'Sakit', cls: 'stat-sakit' },
        I: { label: 'Izin', cls: 'stat-izin' },
        A: { label: 'Alpa', cls: 'stat-alpa' },
        TH: { label: 'Tidak Hadir', cls: 'stat-alpa' },
        B: { label: 'Berhalangan', cls: 'stat-izin' }
    };

    function clean(text) {
        return String(text == null ? '' : text).replace(/\s+/g, ' ').trim();
    }

    // Siswa laki-laki tidak punya status Berhalangan (hanya untuk perempuan),
    // jadi kartu/pill Berhalangan tidak dibuat untuk baris dengan data-jk laki-laki.
    function isLakiRow(row) {
        var jk = clean(row && row.getAttribute ? row.getAttribute('data-jk') : '').toUpperCase();
        if (!jk) return false;
        return jk === 'L' || jk === 'PRIA' || jk.indexOf('LAKI') !== -1;
    }

    function isBerhalanganLabel(text) {
        var t = clean(text).toUpperCase();
        return t === 'B' || t.indexOf('BERHALANGAN') !== -1;
    }

    function cellText(td) {
        return clean(td ? td.textContent : '');
    }

    function cellValue(td) {
        var text = cellText(td);
        if (text === '' || text === '-' || text === '–' || text === '—') {
            return { text: '0', zero: true };
        }
        return { text: text, zero: (/^0+$/.test(text)) };
    }

    /**
     * Baca struktur header: kelompok kolom (row 1) + sub label tiap kolom (row 2).
     * lead = jumlah kolom rowspan (No, Nama Siswa) yang tidak punya sub label.
     */
    function readMeta(table) {
        var head = table.tHead;
        if (!head || !head.rows.length) return null;

        var top = head.rows[0];
        var sub = head.rows.length > 1 ? head.rows[1] : null;
        var lead = 0;
        var groups = [];
        var col = 0;
        var i;

        for (i = 0; i < top.cells.length; i++) {
            var th = top.cells[i];
            var span = parseInt(th.getAttribute('colspan') || '1', 10);
            if (!span || span < 1) span = 1;
            var rowspan = parseInt(th.getAttribute('rowspan') || '1', 10);
            if (rowspan > 1) lead += span;
            groups.push({ label: clean(th.textContent), span: span, start: col });
            col += span;
        }

        var subs = [];
        if (sub) {
            for (i = 0; i < sub.cells.length; i++) {
                var sth = sub.cells[i];
                var sspan = parseInt(sth.getAttribute('colspan') || '1', 10);
                if (!sspan || sspan < 1) sspan = 1;
                for (var k = 0; k < sspan; k++) subs.push(clean(sth.textContent));
            }
        }

        return { groups: groups, subs: subs, lead: lead };
    }

    function buildMonthBox(block) {
        var box = document.createElement('div');
        box.className = 'att-semester-month';

        var name = document.createElement('div');
        name.className = 'att-semester-month-name';
        name.textContent = block.label;
        name.title = block.label;

        var pills = document.createElement('div');
        pills.className = 'att-semester-pills';
        if (block.pills.length) {
            // Banyak kolom (mis. rekap les: satu kolom per tanggal jadwal) ->
            // grid membungkus ke beberapa baris supaya tiap pill tetap terbaca.
            pills.style.gridTemplateColumns = block.pills.length > 6
                ? 'repeat(auto-fill, minmax(52px, 1fr))'
                : 'repeat(' + block.pills.length + ', minmax(0, 1fr))';
        }

        block.pills.forEach(function (p) {
            var pill = document.createElement('div');
            pill.className = 'att-sem-pill' + (p.cls ? ' ' + p.cls : '') + (p.zero ? ' is-zero' : '');

            var lbl = document.createElement('div');
            lbl.className = 'att-sem-pill-lbl';
            lbl.textContent = p.short;
            lbl.title = p.full;

            var val = document.createElement('div');
            val.className = 'att-sem-pill-val';
            val.textContent = p.value;

            pill.appendChild(lbl);
            pill.appendChild(val);
            pills.appendChild(pill);
        });

        box.appendChild(name);
        box.appendChild(pills);
        return box;
    }

    function buildTotals(block) {
        var wrap = document.createElement('div');
        wrap.className = 'att-semester-total';

        var title = document.createElement('div');
        title.className = 'att-semester-total-title';
        title.textContent = block.label || 'Total';
        wrap.appendChild(title);

        var stats = document.createElement('div');
        stats.className = 'att-semester-total-stats';
        if (block.pills.length) {
            stats.style.gridTemplateColumns = 'repeat(' + block.pills.length + ', minmax(0, 1fr))';
        }

        block.pills.forEach(function (p) {
            var box = document.createElement('div');
            box.className = 'att-monthly-stat-box' + (p.cls ? ' ' + p.cls : '');

            var val = document.createElement('div');
            val.className = 'att-monthly-stat-val';
            val.textContent = p.value;

            var lbl = document.createElement('div');
            lbl.className = 'att-monthly-stat-lbl';
            lbl.textContent = p.full;

            box.appendChild(val);
            box.appendChild(lbl);
            stats.appendChild(box);
        });

        wrap.appendChild(stats);
        return wrap;
    }

    function buildCard(row, meta, index) {
        var tds = row.cells;
        var no = String(index + 1);
        var name = '';
        var months = [];
        var totals = null;
        var skipB = isLakiRow(row);

        meta.groups.forEach(function (grp) {
            if (!grp.span) return;

            if (/^\s*no\.?\s*$/i.test(grp.label)) {
                var noText = cellText(tds[grp.start]);
                if (noText) no = noText;
                return;
            }
            if (/nama/i.test(grp.label)) {
                name = cellText(tds[grp.start]);
                return;
            }

            var pills = [];
            for (var c = grp.start; c < grp.start + grp.span && c < tds.length; c++) {
                var rawSub = (c < meta.lead) ? '' : (meta.subs[c - meta.lead] || '');
                if (skipB && (isBerhalanganLabel(rawSub) || isBerhalanganLabel(grp.label))) continue;
                var info = LABEL_MAP[rawSub.toUpperCase()] || null;
                var value = cellValue(tds[c]);
                // Rekap les: label kolom berupa tanggal, status ada di isi sel (H/S/I/A).
                // Warna pill ikut status pada isi sel supaya tetap mudah dibaca.
                var valInfo = info ? null : (LABEL_MAP[clean(value.text).toUpperCase()] || null);
                pills.push({
                    short: rawSub || grp.label,
                    full: info ? info.label : ((rawSub || grp.label) + (valInfo ? ' - ' + valInfo.label : '')),
                    cls: info ? info.cls : (valInfo ? valInfo.cls : ''),
                    value: value.text,
                    zero: value.zero
                });
            }

            if (/total|jumlah/i.test(grp.label)) {
                totals = { label: grp.label, pills: pills };
            } else {
                months.push({ label: grp.label, pills: pills });
            }
        });

        var card = document.createElement('div');
        card.className = 'att-semester-card';

        var head = document.createElement('div');
        head.className = 'att-semester-head';

        var avatar = document.createElement('div');
        avatar.className = 'att-semester-avatar';
        avatar.textContent = no;

        var nameEl = document.createElement('div');
        nameEl.className = 'att-semester-name';
        nameEl.textContent = name;

        head.appendChild(avatar);
        head.appendChild(nameEl);
        card.appendChild(head);

        if (months.length) {
            var monthsWrap = document.createElement('div');
            monthsWrap.className = 'att-semester-months att-sem-cols-' + Math.min(Math.max(months.length, 1), 6);
            months.forEach(function (block) {
                monthsWrap.appendChild(buildMonthBox(block));
            });
            card.appendChild(monthsWrap);
        }

        if (totals) card.appendChild(buildTotals(totals));

        return card;
    }

    function enhanceTable(table) {
        var meta = readMeta(table);
        if (!meta || !meta.groups.length) return false;

        var rows = table.tBodies && table.tBodies.length ? table.tBodies[0].rows : [];
        if (!rows.length) return false;

        var holder = table.closest ? table.closest('.table-responsive') : null;
        if (!holder) holder = table;
        if (!holder.parentNode) return false;

        var grid = holder.parentNode.querySelector('.att-semester-grid[data-source="' + (table.id || 'semesterTable') + '"]');
        if (!grid) {
            grid = document.createElement('div');
            grid.className = 'att-semester-grid';
            grid.setAttribute('data-source', table.id || 'semesterTable');
            holder.parentNode.insertBefore(grid, holder.nextSibling);
        }

        while (grid.firstChild) grid.removeChild(grid.firstChild);
        for (var i = 0; i < rows.length; i++) {
            grid.appendChild(buildCard(rows[i], meta, i));
        }

        holder.classList.add('att-semester-wrap-hidden');
        return true;
    }

    function buildCards(table) {
        if (!table || table.dataset.semesterCards === '1') return;
        if (enhanceTable(table)) table.dataset.semesterCards = '1';
    }

    function runAll() {
        var tables = document.querySelectorAll('table#semesterTable, table.semester-card-source');
        for (var i = 0; i < tables.length; i++) buildCards(tables[i]);
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

    window.addEventListener('resize', runAll);
    window.SemesterCardsRefresh = runAll;
})();
