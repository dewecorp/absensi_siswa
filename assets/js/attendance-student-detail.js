/* ============================================================
   SIMAD Student Detail (Rekap Per Siswa)
   Desktop : judul + ringkasan (kartu/alert/kotak warna) diganti
             header siswa + grid kartu statistik yang rapi.
   Mobile  : ringkasan + tabel riwayat jadi kartu & timeline,
             tabel asli disembunyikan.
   ============================================================ */
"use strict";
(function () {
    var BREAKPOINT = 767.98;

    function clean(text) {
        return String(text == null ? '' : text).replace(/\s+/g, ' ').trim();
    }

    /* Rapikan label ringkasan: "Total Hadir" -> "Hadir",
       "Hadir/Melaksanakan" -> "Hadir". */
    function cleanLabel(text) {
        return clean(text)
            .replace(/^total\s+/i, '')
            .replace(/\s*\/\s*melaksanakan\s*$/i, '')
            .replace(/\s*\/\s*berjamaah\s*$/i, '')
            .trim();
    }

    function statusKey(text, badgeClass) {
        var t = (text || '').toLowerCase();
        if (t.indexOf('tidak') !== -1 || t.indexOf('alpa') !== -1) return 'alpa';
        if (t.indexOf('berhalangan') !== -1 || t.indexOf('izin') !== -1) return 'izin';
        if (t.indexOf('sakit') !== -1) return 'sakit';
        if (t.indexOf('hadir') !== -1 || t.indexOf('melaksanakan') !== -1) return 'hadir';
        if (badgeClass) {
            if (/badge-success/.test(badgeClass)) return 'hadir';
            if (/badge-info/.test(badgeClass)) return 'izin';
            if (/badge-warning/.test(badgeClass)) return 'sakit';
            if (/badge-danger/.test(badgeClass)) return 'alpa';
        }
        return 'neutral';
    }

    function statClass(label) {
        var l = (label || '').toLowerCase();
        if (l.indexOf('berhalangan') !== -1) return 'stat-berhalangan';
        if (l.indexOf('tidak') !== -1 || l.indexOf('alpa') !== -1) return 'stat-alpa';
        if (l.indexOf('sakit') !== -1) return 'stat-sakit';
        if (l.indexOf('izin') !== -1) return 'stat-izin';
        if (l.indexOf('hadir') !== -1 || l.indexOf('melaksanakan') !== -1) return 'stat-hadir';
        return '';
    }

    // Berhalangan hanya untuk siswa perempuan (udzur). Halaman yang menandai
    // jenis kelamin siswa lewat atribut data-jk dipakai sebagai acuan.
    function isMaleBlock(block) {
        if (!block || !block.querySelector) return false;
        var marker = block.querySelector('[data-jk]');
        if (!marker) return false;
        var jk = clean(marker.getAttribute('data-jk')).toUpperCase();
        if (!jk) return false;
        return jk === 'L' || jk === 'PRIA' || jk.indexOf('LAKI') !== -1;
    }

    function isBerhalanganLabel(text) {
        return /berhalangan/i.test(clean(text));
    }

    /* Sembunyikan kartu khusus perempuan (Berhalangan) untuk siswa laki-laki */
    function hideMaleOnlyStats(block) {
        if (!isMaleBlock(block)) return;
        var nodes = block.querySelectorAll('.card-statistic-1, .d-inline-block, .alert');
        for (var i = 0; i < nodes.length; i++) {
            var labelEl = nodes[i].querySelector('h4, small');
            var text = labelEl ? labelEl.textContent : clean(nodes[i].textContent);
            if (isBerhalanganLabel(text)) nodes[i].style.display = 'none';
        }
    }

    /* Ambil nama + kelas siswa dari blok (mendukung semua bentuk halaman rekap) */
    function findIdentity(block) {
        var i;

        // Bentuk 1: <h5>Nama (Kelas)</h5>  (rekap sholat wali/guru)
        var h5 = block.querySelector('h5');
        if (h5) {
            var text = clean(h5.textContent);
            var m = text.match(/^(.*?)\s*\(([^)]*)\)\s*$/);
            if (m) return { name: clean(m[1]), kelas: clean(m[2]) };
            if (text) return { name: text, kelas: '' };
        }

        // Bentuk 2: tabel identitas "Nama Siswa : ..." / "Kelas : ..." (rekap sholat admin/kepala)
        var rows = block.querySelectorAll('tr');
        var name = '';
        var kelas = '';
        var nisn = '';
        for (i = 0; i < rows.length; i++) {
            if (!rows[i].cells || rows[i].cells.length < 2) continue;
            var label = clean(rows[i].cells[0].textContent).toLowerCase().replace(/:\s*$/, '');
            var value = clean(rows[i].cells[1].textContent).replace(/^:\s*/, '');
            if (!value) continue;
            if (!name && /^(nama siswa|nama)$/.test(label)) name = value;
            else if (!kelas && /^kelas/.test(label)) kelas = value;
            else if (!nisn && /^nisn/.test(label)) nisn = value;
        }
        if (name) return { name: name, kelas: kelas, nisn: nisn };

        // Bentuk 3: teks alert "... untuk Nama Siswa" (rekap absensi)
        var body = block.querySelector('.alert-body');
        if (body) {
            var bodyText = clean(body.textContent);
            var m2 = bodyText.match(/untuk\s+(.+)$/i);
            if (m2) return { name: clean(m2[1]), kelas: '' };
        }
        return null;
    }

    /* Ringkasan: pola kartu statistik, kotak warna, atau alert "Label: nilai" */
    function collectStats(block) {
        var stats = [];
        var i;
        var male = isMaleBlock(block);
        var cards = block.querySelectorAll('.card-statistic-1');
        for (i = 0; i < cards.length; i++) {
            var head = cards[i].querySelector('.card-header h4');
            var val = cards[i].querySelector('.card-body');
            var label = clean(head ? head.textContent : '');
            var valueText = clean(val ? val.textContent : '');
            var num = valueText.match(/\d+/);
            if (male && isBerhalanganLabel(label)) continue;
            if (label && num) stats.push({ label: cleanLabel(label), value: num[0] });
        }
        if (stats.length) return stats;

        var boxes = block.querySelectorAll('.d-inline-block');
        for (i = 0; i < boxes.length; i++) {
            var big = boxes[i].querySelector('h3');
            var small = boxes[i].querySelector('small');
            if (!big || !small) continue;
            var boxLabel = clean(small.textContent);
            var boxValue = clean(big.textContent);
            if (male && isBerhalanganLabel(boxLabel)) continue;
            if (boxLabel && boxValue) stats.push({ label: cleanLabel(boxLabel), value: boxValue });
        }
        if (stats.length) return stats;

        var alerts = block.querySelectorAll('.alert');
        for (i = 0; i < alerts.length; i++) {
            var text = clean(alerts[i].textContent);
            var m = text.match(/^(.+?):\s*([0-9]+)\s*$/);
            if (m) {
                var alertLabel = cleanLabel(clean(m[1]).replace(/\s*\/.*$/, ''));
                if (male && isBerhalanganLabel(alertLabel)) continue;
                stats.push({ label: alertLabel, value: m[2] });
            }
        }
        return stats;
    }

    function buildHeader(identity) {
        if (!identity || !identity.name) return null;

        var wrap = document.createElement('div');
        wrap.className = 'att-student-header';

        var avatar = document.createElement('div');
        avatar.className = 'att-student-avatar';
        avatar.innerHTML = '<i class="fas fa-user"></i>';

        var info = document.createElement('div');
        info.style.minWidth = '0';

        var name = document.createElement('div');
        name.className = 'att-student-header-name';
        name.textContent = identity.name;

        var sub = document.createElement('div');
        sub.className = 'att-student-header-sub';
        sub.textContent = identity.kelas ? 'Kelas ' + identity.kelas + ' · Riwayat kehadiran' : 'Riwayat kehadiran';

        info.appendChild(name);
        info.appendChild(sub);
        wrap.appendChild(avatar);
        wrap.appendChild(info);
        return wrap;
    }

    function buildStats(stats) {
        if (!stats.length) return null;

        var grid = document.createElement('div');
        grid.className = 'att-student-stats';

        stats.forEach(function (item) {
            var box = document.createElement('div');
            var cls = statClass(item.label);
            box.className = 'att-monthly-stat-box' + (cls ? ' ' + cls : '');

            var val = document.createElement('div');
            val.className = 'att-monthly-stat-val';
            val.textContent = item.value;

            var lbl = document.createElement('div');
            lbl.className = 'att-monthly-stat-lbl';
            lbl.textContent = item.label;

            box.appendChild(val);
            box.appendChild(lbl);
            grid.appendChild(box);
        });

        return grid;
    }

    /* ---------- versi desktop (rapi) ---------- */

    function buildDeskHead(identity) {
        if (!identity || !identity.name) return null;

        var wrap = document.createElement('div');
        wrap.className = 'att-desk-head';

        var avatar = document.createElement('div');
        avatar.className = 'att-desk-avatar';
        avatar.innerHTML = '<i class="fas fa-user"></i>';

        var info = document.createElement('div');
        info.style.minWidth = '0';

        var name = document.createElement('div');
        name.className = 'att-desk-name';
        name.textContent = identity.name;

        var bits = [];
        if (identity.kelas) bits.push('Kelas ' + identity.kelas);
        if (identity.nisn) bits.push('NISN ' + identity.nisn);
        bits.push('Riwayat kehadiran');

        var sub = document.createElement('div');
        sub.className = 'att-desk-sub';
        sub.textContent = bits.join(' · ');

        info.appendChild(name);
        info.appendChild(sub);
        wrap.appendChild(avatar);
        wrap.appendChild(info);
        return wrap;
    }

    function buildDeskStats(stats) {
        if (!stats.length) return null;

        var grid = document.createElement('div');
        grid.className = 'att-desk-stats';

        stats.forEach(function (item) {
            var box = document.createElement('div');
            var cls = statClass(item.label);
            box.className = 'att-desk-stat' + (cls ? ' ' + cls : '');

            var val = document.createElement('div');
            val.className = 'att-desk-val';
            val.textContent = item.value;

            var lbl = document.createElement('div');
            lbl.className = 'att-desk-lbl';
            lbl.textContent = item.label;

            box.appendChild(val);
            box.appendChild(lbl);
            grid.appendChild(box);
        });

        return grid;
    }

    function readColumns(table) {
        var head = table.tHead;
        if (!head || !head.rows.length) return null;
        var cells = head.rows[head.rows.length - 1].cells;
        var idx = { no: -1, date: -1, status: -1, note: -1 };

        for (var i = 0; i < cells.length; i++) {
            var label = clean(cells[i].textContent).toLowerCase();
            if (idx.no < 0 && /^no\.?$/.test(label)) idx.no = i;
            else if (idx.date < 0 && /tanggal|date/.test(label)) idx.date = i;
            else if (idx.status < 0 && /status/.test(label)) idx.status = i;
            else if (idx.note < 0 && /catatan|keterangan|note/.test(label)) idx.note = i;
        }

        if (idx.status < 0) {
            for (var c = 0; c < cells.length; c++) {
                if (cells[c].querySelector && cells[c].querySelector('.badge')) { idx.status = c; break; }
            }
        }
        return idx;
    }

    function buildTimeline(table) {
        var idx = readColumns(table);
        if (!idx) return null;
        var rows = table.tBodies && table.tBodies.length ? table.tBodies[0].rows : [];
        if (!rows.length) return null;

        var wrap = document.createElement('div');
        wrap.className = 'att-student-timeline';

        var title = document.createElement('div');
        title.className = 'att-student-timeline-title';
        title.textContent = 'Rincian Kehadiran (' + rows.length + ')';
        wrap.appendChild(title);

        var items = document.createElement('div');
        items.className = 'att-student-timeline-items';
        wrap.appendChild(items);

        for (var r = 0; r < rows.length; r++) {
            var tds = rows[r].cells;
            var dateText = idx.date >= 0 && tds[idx.date] ? clean(tds[idx.date].textContent) : '';
            var statusTd = idx.status >= 0 && tds[idx.status] ? tds[idx.status] : null;
            var statusText = statusTd ? clean(statusTd.textContent) : '';
            var badge = statusTd ? statusTd.querySelector('.badge') : null;
            var key = statusKey(statusText, badge ? badge.className : '');
            var noteText = idx.note >= 0 && tds[idx.note] ? clean(tds[idx.note].textContent) : '';
            var noText = idx.no >= 0 && tds[idx.no] ? clean(tds[idx.no].textContent) : String(r + 1);

            var item = document.createElement('div');
            item.className = 'att-student-item is-' + key;

            var main = document.createElement('div');
            main.className = 'att-student-item-main';

            var no = document.createElement('div');
            no.className = 'att-student-item-no';
            no.textContent = noText;

            var date = document.createElement('div');
            date.className = 'att-student-item-date';
            date.textContent = dateText;

            main.appendChild(no);
            main.appendChild(date);

            if (statusText) {
                var chip = document.createElement('div');
                chip.className = 'att-student-chip is-' + key;
                chip.textContent = statusText;
                main.appendChild(chip);
            }
            item.appendChild(main);

            if (noteText && !/^[-–—]+$/.test(noteText)) {
                var note = document.createElement('div');
                note.className = 'att-student-item-note';
                note.textContent = noteText;
                item.appendChild(note);
            }

            items.appendChild(item);
        }

        return wrap;
    }

    function markActions(block, table) {
        block.querySelectorAll('.btn-group').forEach(function (group) {
            group.classList.add('att-student-actions');
        });
        var card = table.closest ? table.closest('.card') : null;
        if (card) {
            var prev = card.previousElementSibling;
            if (prev && prev.querySelector && prev.querySelector('button')) {
                prev.classList.add('att-student-actions');
            }
        }
    }

    function currentMode() {
        return window.innerWidth > BREAKPOINT ? 'desk' : 'mobile';
    }

    /* Hapus hasil render sebelumnya (dipakai saat ukuran layar berubah) */
    function cleanupRendered(block) {
        block.querySelectorAll('.att-desk-head, .att-desk-stats, .att-student-header, .att-student-stats, .att-student-timeline')
            .forEach(function (el) {
                if (el.parentNode) el.parentNode.removeChild(el);
            });
        block.querySelectorAll('.att-student-summary-old, .att-student-title-old, .att-student-table-old')
            .forEach(function (el) {
                el.classList.remove('att-student-summary-old', 'att-student-title-old', 'att-student-table-old');
            });
    }

    function enhance(table) {
        var mode = currentMode();
        if (!table || table.dataset.studentDetail === mode) return;
        if (!table.tBodies || !table.tBodies.length || !table.tBodies[0].rows.length) return;

        var holder = table.closest('.table-responsive') || table;
        if (!holder.parentNode) return;
        var block = table.closest('.mt-4') || table.closest('.card-body') || holder.parentNode;
        if (!block) return;

        cleanupRendered(block);
        hideMaleOnlyStats(block);

        var identity = findIdentity(block);
        var stats = collectStats(block);

        // Sembunyikan judul + ringkasan lama (diganti head + kartu rapi).
        var oldSummary = block.querySelector('.row.mb-4, .row.mt-3');
        if (oldSummary) oldSummary.classList.add('att-student-summary-old');

        block.querySelectorAll('h5, .alert.alert-has-icon').forEach(function (el) {
            el.classList.add('att-student-title-old');
        });

        var anchor = oldSummary || holder;

        if (mode === 'desk') {
            var head = buildDeskHead(identity);
            var deskStats = buildDeskStats(stats);
            var deskTimeline = buildTimeline(table);
            if (head) block.insertBefore(head, anchor);
            if (deskStats) block.insertBefore(deskStats, anchor);
            if (deskTimeline) block.insertBefore(deskTimeline, anchor);
        } else {
            var header = buildHeader(identity);
            var boxes = buildStats(stats);
            var timeline = buildTimeline(table);
            if (header) block.insertBefore(header, anchor);
            if (boxes) block.insertBefore(boxes, anchor);
            if (timeline) block.insertBefore(timeline, anchor);
        }

        // Tabel riwayat lama diganti timeline di semua ukuran layar.
        holder.classList.add('att-student-table-old');
        markActions(block, table);

        table.dataset.studentDetail = mode;
    }

    function runAll() {
        var tables = document.querySelectorAll('table#studentTable');
        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];
            var holder = table.closest ? (table.closest('.table-responsive') || table) : null;
            var block = table.closest ? (table.closest('.mt-4') || table.closest('.card-body') || (holder && holder.parentNode)) : null;
            // Berlaku juga di desktop: kartu Berhalangan tidak ditampilkan untuk siswa laki-laki.
            if (block) hideMaleOnlyStats(block);
            enhance(table);
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

    window.addEventListener('resize', runAll);
    window.StudentDetailRefresh = runAll;
})();
