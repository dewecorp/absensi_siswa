/* Global behavior for Pembayaran Siswa pages inside the native SIMAD shell. */
(function () {
    'use strict';

    if (!window.jQuery) return;

    // Bootstrap backdrop lives on <body>. A modal inside .section (Stisla gives
    // .section z-index: 1) is trapped in that stacking context and ends up
    // behind the backdrop. Move modals to <body> before Bootstrap opens them.
    jQuery(function ($) {
        var detachModal = function (modal) {
            if (modal && modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
        };

        $('.modal').each(function () {
            detachModal(this);
        });

        $(document).on('show.bs.modal', '.modal', function () {
            detachModal(this);
        });
    });

    jQuery(window).on('load', function () {
        var $ = window.jQuery;

        if ($.fn.select2) {
            var initOne = function ($el) {
                if ($el.hasClass('select2-hidden-accessible')) return;
                if ($el.is('[data-no-select2]')) return;
                if ($el.closest('.swal2-popup,.swal2-container').length) return;
                if ($el.hasClass('swal2-select')) return;
                if ($el.is('#id_kelas,#id_kelas_laporan')) return;
                // DataTables controls its own length select; Select2 breaks its options.
                if ($el.closest('.dataTables_wrapper').length) return;

                var $modal = $el.closest('.modal');
                var width = $el.css('width');
                var widthVal = (width && width !== '0px' && width !== 'auto') ? 'resolve' : '100%';
                var opts = { width: widthVal, minimumResultsForSearch: 6 };
                if ($modal.length) opts.dropdownParent = $modal;

                try { $el.select2(opts); } catch (e) {}
            };

            $('select').each(function () { initOne($(this)); });
            $(document).on('shown.bs.modal', function (event) {
                $(event.target).find('select').each(function () { initOne($(this)); });
            });
            $(document).on('draw.dt', function () {
                $('select').each(function () { initOne($(this)); });
            });

            try {
                new MutationObserver(function (mutations) {
                    mutations.forEach(function (mutation) {
                        $(mutation.addedNodes).find('select').addBack('select').each(function () {
                            initOne($(this));
                        });
                    });
                }).observe(document.body, { childList: true, subtree: true });
            } catch (e) {}
        }

        if ($.fn.DataTable) {
            $('table.pembayaran-table').each(function () {
                if ($(this).closest('.modal').length) return;
                if ($(this).hasClass('no-data-table')) return;
                if ($.fn.DataTable.isDataTable(this)) return;

                $(this).DataTable({
                    scrollX: true,
                    scrollCollapse: true,
                    autoWidth: false
                });
            });
        }
    });
})();
