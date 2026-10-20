/* Global behavior for Pembayaran Siswa pages inside the native SIMAD shell. */
(function () {
    'use strict';

    window.AppModal = {
        open: function (target) {
            var modal = typeof target === 'string' ? document.querySelector(target) : target;
            if (!modal) return;
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');
            modal.scrollTop = 0;
            modal.dispatchEvent(new CustomEvent('app:modal-open'));
        },
        close: function (target) {
            var modal = typeof target === 'string' ? document.querySelector(target) : target;
            if (!modal) return;
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
            modal.dispatchEvent(new CustomEvent('app:modal-close'));
            if (!document.querySelector('[data-tailwind-modal]:not(.hidden)')) {
                document.body.classList.remove('overflow-hidden');
            }
        }
    };

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-tailwind-modal-target]');
        if (trigger) {
            event.preventDefault();
            AppModal.open(trigger.getAttribute('data-tailwind-modal-target'));
            return;
        }

        var closeButton = event.target.closest('[data-tailwind-modal-close]');
        if (closeButton) {
            event.preventDefault();
            AppModal.close(closeButton.closest('[data-tailwind-modal]'));
            return;
        }

        if (event.target.matches('[data-tailwind-modal]')) {
            AppModal.close(event.target);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        var openModals = document.querySelectorAll('[data-tailwind-modal]:not(.hidden)');
        if (openModals.length) {
            AppModal.close(openModals[openModals.length - 1]);
        }
    });

    if (window.jQuery) {
        jQuery(window).on('load', function () {
            var $ = window.jQuery;
            if ($.fn.select2) {
                var initOne = function ($el) {
                    if ($el.hasClass('select2-hidden-accessible')) return;
                    if ($el.is('[data-no-select2]')) return;
                    if ($el.closest('.swal2-popup,.swal2-container').length) return;
                    if ($el.hasClass('swal2-select')) return;
                    if ($el.is('#id_kelas,#id_kelas_laporan')) return;

                    var isModal = $el.closest('[data-tailwind-modal]').length > 0;
                    var isDT = $el.closest('.dataTables_length').length > 0;
                    var width = $el.css('width');
                    var widthVal = isDT ? '84px' : (width && width !== '0px' && width !== 'auto' ? 'resolve' : '100%');
                    var opts = { width: widthVal, minimumResultsForSearch: 6 };
                    if (isModal) opts.dropdownParent = $el.closest('[data-tailwind-modal]');

                    try { $el.select2(opts); } catch (e) {}
                };

                $('select').each(function () { initOne($(this)); });
                $(document).on('app:modal-open', function (event) {
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
                $('table.app-data-table').each(function () {
                    if ($(this).closest('[data-tailwind-modal]').length) return;
                    if ($(this).hasClass('no-data-table')) return;
                    if ($.fn.DataTable.isDataTable(this)) return;

                    $(this).DataTable({
                        scrollX: $(this).attr('data-dt-scroll-x') === '1',
                        autoWidth: false
                    });
                });
            }
        });
    }
})();
