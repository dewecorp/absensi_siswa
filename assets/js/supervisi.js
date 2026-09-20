/**
 * Modul SUPERVISI - Helper UI bersama (level Kepala Madrasah)
 * Menyediakan: init DataTable, export Excel (XLSX), cetak/PDF, konfirmasi hapus.
 * Tidak mengubah modul SIMAD lain.
 */
(function (window) {
    'use strict';

    var SV = window.SV || {};

    SV.initDataTable = function (selector, options) {
        options = options || {};
        var table = window.jQuery ? window.jQuery(selector) : null;
        if (!table || !table.length) {
            return null;
        }
        var dt = table.DataTable({
            language: {
                search: 'Cari:',
                lengthMenu: 'Tampilkan _MENU_ data',
                info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',
                infoEmpty: 'Tidak ada data',
                zeroRecords: 'Data tidak ditemukan',
                paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' }
            },
            pageLength: options.pageLength || 10,
            order: options.order || [],
            columnDefs: options.columnDefs || [],
            responsive: false
        });

        dt.on('order.dt search.dt draw.dt', function () {
            var info = dt.page.info();
            dt.column(0, { search: 'applied', order: 'applied' }).nodes().each(function (cell, i) {
                if (cell) {
                    cell.innerHTML = info.page * info.length + i + 1;
                }
            });
        }).draw();

        return dt;
    };

    SV.autoSubmitFilters = function (selector) {
        var forms = document.querySelectorAll(selector || 'form');
        Array.prototype.forEach.call(forms, function (form) {
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') {
                return;
            }
            if (form.getAttribute('data-sv-auto') === 'off') {
                return;
            }
            var controls = form.querySelectorAll('select, input[type="date"], input[type="text"][name], input[type="number"][name]');
            Array.prototype.forEach.call(controls, function (el) {
                el.addEventListener('change', function () {
                    form.submit();
                });
            });
        });
    };

    SV.autoGrowTextareas = function (selector) {
        var els = document.querySelectorAll(selector || 'textarea.sv-autogrow');
        Array.prototype.forEach.call(els, function (el) {
            var resize = function () {
                el.style.height = 'auto';
                el.style.height = (el.scrollHeight + 4) + 'px';
                el.style.overflowY = 'hidden';
            };
            if (!el.getAttribute('data-sv-grow')) {
                el.setAttribute('data-sv-grow', '1');
                el.addEventListener('input', resize);
            }
            resize();
        });
    };

    SV.getMeta = function () {
        function val(id) {
            var el = document.getElementById(id);
            return el ? el.value : '';
        }
        return {
            schoolName: val('svSchoolName') || 'MADRASAH',
            schoolLogo: val('svSchoolLogo') || '',
            academicYear: val('svAcademicYear') || '-',
            semester: val('svSemester') || '-',
            headName: val('svHeadName') || '-',
            headNip: val('svHeadNip') || '-',
            printPlace: val('svPrintPlace') || 'Padang',
            printDate: val('svPrintDate') || ''
        };
    };

    SV.cleanTable = function (table, removeActions) {
        var clone = table.cloneNode(true);
        if (removeActions) {
            for (var i = 0; i < clone.rows.length; i++) {
                if (clone.rows[i].cells.length > 0) {
                    clone.rows[i].deleteCell(-1);
                }
            }
        }
        return clone;
    };

    SV.exportExcel = function (tableId, title, filename, removeActions) {
        var table = document.getElementById(tableId);
        if (!table) {
            return;
        }
        var meta = SV.getMeta();
        var clean = SV.cleanTable(table, removeActions !== false);

        if (typeof XLSX !== 'undefined') {
            var header = [
                [meta.schoolName.toUpperCase()],
                [String(title || 'LAPORAN SUPERVISI').toUpperCase()],
                ['Tahun Ajaran: ' + meta.academicYear + '  |  Semester: ' + meta.semester],
                []
            ];
            var ws = XLSX.utils.aoa_to_sheet(header);
            XLSX.utils.sheet_add_dom(ws, clean, { origin: -1 });
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Supervisi');
            XLSX.writeFile(wb, (filename || 'supervisi') + '_' + meta.academicYear.replace(/\//g, '-') + '.xlsx');
        } else {
            var html = '<table border="1">' + clean.innerHTML + '</table>';
            var a = document.createElement('a');
            a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
            a.download = (filename || 'supervisi') + '.xls';
            a.click();
        }
    };

    SV.printPdf = function (tableId, title, removeActions) {
        var table = document.getElementById(tableId);
        if (!table) {
            return;
        }
        var meta = SV.getMeta();
        var clean = SV.cleanTable(table, removeActions !== false);
        var qrContent = 'Dokumen Sah: ' + meta.schoolName + '\nKepala Madrasah: ' + meta.headName + '\nNIP: ' + meta.headNip;
        var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' + encodeURIComponent(qrContent);

        var w = window.open('', '_blank');
        if (!w) {
            return;
        }
        w.document.write('<html><head><meta charset="utf-8"><title>' + title + '</title>');
        w.document.write('<style>');
        w.document.write('body{font-family:Arial,sans-serif;font-size:11px;}');
        w.document.write('table{border-collapse:collapse;width:100%;margin-bottom:16px;}');
        w.document.write('th,td{border:1px solid #000;padding:5px 6px;text-align:left;vertical-align:top;}');
        w.document.write('th{background:#f2f2f2;}');
        w.document.write('h2,h3{text-align:center;margin:2px 0;}');
        w.document.write('.header{display:flex;align-items:center;justify-content:center;position:relative;margin-bottom:12px;}');
        w.document.write('.logo{position:absolute;left:0;top:0;height:70px;}');
        w.document.write('.header-text{text-align:center;width:100%;}');
        w.document.write('.signature{margin-top:30px;display:flex;justify-content:flex-end;}');
        w.document.write('.signature-box{width:250px;text-align:left;}');
        w.document.write('.qr{height:80px;width:80px;object-fit:contain;}');
        w.document.write('</style></head><body>');
        w.document.write('<div class="header">');
        if (meta.schoolLogo) {
            w.document.write('<img src="' + meta.schoolLogo + '" class="logo">');
        }
        w.document.write('<div class="header-text">');
        w.document.write('<h2>' + meta.schoolName.toUpperCase() + '</h2>');
        w.document.write('<h3>' + String(title || '').toUpperCase() + '</h3>');
        w.document.write('<h3>Tahun Ajaran ' + meta.academicYear + ' - ' + meta.semester + '</h3>');
        w.document.write('</div></div>');
        w.document.write('<hr style="border:1px solid #000;margin-bottom:14px;">');
        w.document.write(clean.outerHTML);
        w.document.write('<div class="signature"><div class="signature-box">');
        w.document.write('<p>' + meta.printPlace + ', ' + meta.printDate + '</p>');
        w.document.write('<p>Kepala Madrasah,</p>');
        w.document.write('<div><img src="' + qrUrl + '" class="qr"></div>');
        w.document.write('<p style="margin-bottom:0;"><strong>' + meta.headName + '</strong></p>');
        w.document.write('<p style="margin-top:0;">NIP. ' + meta.headNip + '</p>');
        w.document.write('</div></div>');
        w.document.write('<script>window.onload=function(){setTimeout(function(){window.print();},400);}<\/script>');
        w.document.write('</body></html>');
        w.document.close();
    };

    SV.confirmDelete = function (options) {
        options = options || {};
        if (typeof Swal === 'undefined') {
            return window.confirm(options.text || 'Hapus data ini?');
        }
        Swal.fire({
            title: options.title || 'Konfirmasi Hapus',
            text: options.text || 'Data akan dihapus permanen.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then(function (result) {
            if (result.isConfirmed && typeof options.onConfirm === 'function') {
                options.onConfirm();
            }
        });
        return false;
    };

    SV.toast = function (icon, title) {
        if (typeof Swal === 'undefined') {
            return;
        }
        var Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true
        });
        Toast.fire({ icon: icon || 'success', title: title || '' });
    };

    SV.submitPost = function (action, fields) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = action;
        Object.keys(fields || {}).forEach(function (key) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    };

    window.SV = SV;
})(window);
