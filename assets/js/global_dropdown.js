"use strict";
(function() {
  function hasSelect2Lib() {
    try { return !!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2); } catch (e) { return false; }
  }
  function isEnhanceable(sel) {
    if (!sel || sel.tagName !== 'SELECT') return false;
    if (sel.multiple) return false;
    if (sel.closest('.modal')) return false;
    if (sel.closest('#jurnalModal')) return false;
    if (sel.closest('#modalCatatan')) return false;
    if (sel.closest('#modalKomunikasi')) return false;
    if (sel.closest('#modalTL')) return false;
    if (sel.closest('#modalSoal')) return false;
    if (sel.closest('#modal-remedial')) return false;
    if (sel.closest('#modal-enrichment')) return false;
    if (sel.closest('#modalAnggotaProjek')) return false;
    if (sel.closest('#modalProjek')) return false;
    if (sel.closest('#modalDataProjek')) return false;
    if (sel.classList.contains('select2-hidden-accessible')) return false;
    if (sel.closest('.select2-container')) return false;
    if (sel.className && (sel.className.indexOf('select2') !== -1 || sel.id === 'f_siswa' || sel.id === 'inp_siswa') && hasSelect2Lib()) return false;
    if (sel.dataset.gdsDone === '1') return false;
    if (sel.options.length === 0) return false;
    return true;
  }

  function getOrCreateBackdrop() {
    var bd = document.getElementById('gdsBackdropMobile');
    if (!bd) {
      bd = document.createElement('div');
      bd.id = 'gdsBackdropMobile';
      bd.className = 'gds-mobile-backdrop';
      document.body.appendChild(bd);
      bd.addEventListener('click', function() {
        closeAll();
      });
    }
    return bd;
  }

  function closeAll(except) {
    document.querySelectorAll('.gds-wrap.open').forEach(function(w) {
      if (w !== except) w.classList.remove('open');
    });
    document.querySelectorAll('.gds-list.open').forEach(function(l) {
      if (!except || l._gdsWrap !== except) l.classList.remove('open');
    });
    var anyOpen = document.querySelectorAll('.gds-wrap.open, .gds-list.open').length > 0;
    var bd = document.getElementById('gdsBackdropMobile');
    if (bd && !anyOpen) {
      bd.classList.remove('active');
      document.body.style.overflow = '';
    }
  }

  function buildOne(sel) {
    sel.dataset.gdsDone = '1';
    sel.classList.add('gds-source');
    var wrap = document.createElement('div');
    wrap.className = 'gds-wrap';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'gds-btn';
    btn.innerHTML = '<span class="gds-label"></span><span class="gds-caret">▼</span>';
    wrap.appendChild(btn);

    var list = document.createElement('div');
    list.className = 'gds-list modern-notif-scroll';
    list.setAttribute('role', 'listbox');
    list._gdsWrap = wrap;
    wrap._gdsList = list;

    // Direct mount to body for zero z-index clipping/blur interference
    document.body.appendChild(list);

    function getFieldTitle() {
      var label = null;
      var fg = sel.closest('.form-group') || sel.closest('.col-md-3') || sel.closest('.col-md-4') || sel.closest('div');
      if (fg) label = fg.querySelector('label');
      if (label) return label.textContent.replace(/[*:]/g, '').trim();
      return sel.getAttribute('aria-label') || sel.getAttribute('placeholder') || 'Pilih Opsi';
    }

    function refreshLabel() {
      var opt = sel.options[sel.selectedIndex];
      if (!opt || String(opt.value) !== String(sel.value)) {
        for (var i = 0; i < sel.options.length; i++) {
          if (String(sel.options[i].value) === String(sel.value)) {
            opt = sel.options[i];
            sel.selectedIndex = i;
            break;
          }
        }
      }
      var label = btn.querySelector('.gds-label');
      if (label) {
        label.textContent = opt ? opt.textContent : '';
      }
      Array.prototype.forEach.call(list.querySelectorAll('.gds-option'), function(b) {
        b.classList.toggle('selected', String(b.dataset.value) === String(sel.value));
      });
    }

    function renderOptions(filter) {
      list.innerHTML = '';
      var isMobile = window.innerWidth <= 767.98;

      if (isMobile) {
        // Header Title + Close button
        var titleRow = document.createElement('div');
        titleRow.className = 'gds-sheet-title';
        var titleText = document.createElement('span');
        titleText.innerHTML = '<i class="fas fa-list-ul"></i> ' + getFieldTitle();
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'gds-sheet-close';
        closeBtn.innerHTML = '<i class="fas fa-times"></i>';
        closeBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          closeAll();
        });
        titleRow.appendChild(titleText);
        titleRow.appendChild(closeBtn);
        list.appendChild(titleRow);
      }

      var optionsWrap = list;
      if (isMobile) {
        optionsWrap = document.createElement('div');
        optionsWrap.className = 'gds-options-scroll';
        list.appendChild(optionsWrap);
      }

      var q = (filter || '').toLowerCase();
      var count = 0;
      Array.prototype.forEach.call(sel.options, function(o) {
        if (q && o.text.toLowerCase().indexOf(q) === -1) return;
        count++;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'gds-option' + (o.value === sel.value ? ' selected' : '');
        b.dataset.value = o.value;
        b.setAttribute('role', 'option');
        var span = document.createElement('span');
        span.textContent = o.text;
        var check = document.createElement('span');
        check.className = 'gds-check';
        check.innerHTML = '<i class="fas fa-check"></i>';
        b.appendChild(span);
        b.appendChild(check);
        b.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          var prevVal = sel.value;
          sel.value = o.value;
          refreshLabel();
          closeAll();
          if (prevVal !== o.value || sel.hasAttribute('onchange')) {
            var ev;
            try {
              ev = new Event('change', { bubbles: true });
            } catch (err) {
              ev = document.createEvent('Event');
              ev.initEvent('change', true, true);
            }
            sel.dispatchEvent(ev);
            var form = sel.closest('form');
            if (form && (sel.hasAttribute('onchange') || sel.dataset.autoSubmit === '1')) {
              // Allow close animation / backdrop dismiss to complete before submit
              setTimeout(function() {
                if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
              }, 60);
            }
          }
        });
        optionsWrap.appendChild(b);
      });
      if (count === 0) {
        var d = document.createElement('div');
        d.className = 'gds-empty';
        d.textContent = 'Tidak ada pilihan';
        optionsWrap.appendChild(d);
      }
    }

    function positionList() {
      if (!list) return;
      if (window.innerWidth <= 767.98) {
        // On mobile, bottom sheet handles positioning via CSS fixed bottom 0
        list.style.top = '';
        list.style.bottom = '';
        list.style.left = '';
        list.style.width = '';
        list.style.maxHeight = '';
        return;
      }
      list.style.top = '';
      list.style.bottom = '';
      list.style.left = '';
      list.style.width = '';
      try {
        var r = btn.getBoundingClientRect();
        list.style.left = Math.max(8, r.left) + 'px';
        list.style.width = Math.max(160, r.width) + 'px';
        var spaceBelow = window.innerHeight - r.bottom;
        var spaceAbove = r.top;
        if (spaceBelow < 220 && spaceAbove > spaceBelow) {
          list.style.top = 'auto';
          list.style.bottom = Math.max(8, window.innerHeight - r.top + 6) + 'px';
          list.style.maxHeight = Math.min(260, Math.max(140, spaceAbove - 20)) + 'px';
        } else {
          list.style.top = (r.bottom + 6) + 'px';
          list.style.bottom = 'auto';
          list.style.maxHeight = Math.min(260, Math.max(140, spaceBelow - 20)) + 'px';
        }
      } catch (e2) {}
    }

    btn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      // Toggle: tap tombol yang sama = tutup (sebelumnya harus tap di luar)
      if (wrap.classList.contains('open')) {
        closeAll();
        return;
      }
      closeAll(wrap);
      renderOptions('');
      wrap.classList.add('open');
      list.classList.add('open');
      if (window.innerWidth <= 767.98) {
        var bd = getOrCreateBackdrop();
        bd.classList.add('active');
        document.body.style.overflow = 'hidden';
      }
      positionList();
    });
    window.addEventListener('resize', function() {
      if (wrap.classList.contains('open')) positionList();
    });
    window.addEventListener('scroll', function() {
      if (wrap.classList.contains('open')) positionList();
    }, true);

    sel.addEventListener('change', refreshLabel);
    if (window.jQuery) {
      window.jQuery(sel).on('change', refreshLabel);
    }
    sel.gdsRefresh = function() {
      renderOptions();
      refreshLabel();
    };
    refreshLabel();

    // Bila select2 mengambil alih select yang sama (select2-hidden-accessible),
    // lepas UI gds supaya tidak ada dua dropdown bertumpuk untuk satu select.
    // Dipantau lewat MutationObserver (bukan setInterval) supaya deterministik dan
    // tidak terpengaruh throttle timer pada tab background.
    function detachGdsForSelect2() {
      var taken = false;
      try {
        taken = sel.classList.contains('select2-hidden-accessible')
             || (sel.nextElementSibling && sel.nextElementSibling.classList
                 && sel.nextElementSibling.classList.contains('select2-container'));
      } catch (err) { taken = false; }
      if (!taken) return false;

      try {
        var parent = wrap.parentNode;
        if (parent) {
          var s2 = wrap.querySelector('.select2-container');
          parent.insertBefore(sel, wrap);       // kembalikan select ke posisi asli
          if (s2) parent.insertBefore(s2, wrap); // UI select2 ikut dipindah keluar
          parent.removeChild(wrap);              // buang tombol/wrap gds
        }
      } catch (err) {}
      try { if (list.parentNode) list.parentNode.removeChild(list); } catch (err) {}

      sel.classList.remove('gds-source');
      sel.removeAttribute('data-gds-done');
      sel.gdsRefresh = null;
      sel.gdsDetachForSelect2 = null;
      if (window.jQuery) {
        try { window.jQuery(sel).off('change', refreshLabel); } catch (err) {}
      }
      try { sel.removeEventListener('change', refreshLabel); } catch (err) {}
      closeAll();
      return true;
    }
    sel.gdsDetachForSelect2 = detachGdsForSelect2;

    try {
      var s2Obs = new MutationObserver(function() {
        if (detachGdsForSelect2()) {
          try { s2Obs.disconnect(); } catch (err) {}
        }
      });
      s2Obs.observe(sel, { attributes: true, attributeFilter: ['class'] });
    } catch (err) {}
    detachGdsForSelect2(); // jaga-jaga bila select2 sudah init sebelum gds

    if (!document.body.dataset.gdsBound) {
      document.body.dataset.gdsBound = '1';
      document.addEventListener('click', function(e) {
        if (!e.target.closest || (!e.target.closest('.gds-wrap') && !e.target.closest('.gds-list'))) {
          closeAll();
        }
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          closeAll();
        }
      });
    }
  }

  function isDataTableLength(sel) {
    return sel.name && /_length$/.test(sel.name);
  }
  function applyInlineMode(sel, wrap) {
    try {
      var cs = window.getComputedStyle(sel);
      if (cs && cs.display === 'inline-block') wrap.classList.add('gds-inline');
    } catch (e) {}
    if (isDataTableLength(sel)) wrap.classList.add('gds-inline');
  }
  function processOne(s) {
    if (!s || s.tagName !== 'SELECT') return;
    if (s.dataset.gdsDone === '1') {
      if (typeof s.gdsRefresh === 'function') s.gdsRefresh();
      return;
    }
    if (isEnhanceable(s)) {
      try {
        buildOne(s);
        var w = s.closest ? s.closest('.gds-wrap') : null;
        if (w) applyInlineMode(s, w);
      } catch (e) {}
    }
  }

  function enhanceAll(root) {
    var el = document;
    if (root) {
      if (typeof root === 'string') {
        el = document.querySelector(root) || document;
      } else if (root.jquery) {
        el = root[0] || document;
      } else if (typeof root.querySelectorAll === 'function' || (root && root.tagName === 'SELECT')) {
        el = root;
      }
    }
    if (!el || (typeof el.querySelectorAll !== 'function' && el.tagName !== 'SELECT')) el = document;
    if (el.tagName === 'SELECT') {
      processOne(el);
      return;
    }
    el.querySelectorAll('select.form-control, select.form-control-sm, .dataTables_length select, select[name$="_length"]').forEach(function(s) {
      processOne(s);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { enhanceAll(document); });
  } else {
    enhanceAll(document);
  }
  try {
    var gdsObs = new MutationObserver(function(muts) {
      var need = false;
      muts.forEach(function(m) {
        (m.addedNodes || []).forEach(function(n) {
          if (!n) return;
          if (n.tagName === 'SELECT') need = true;
          else if (n.querySelectorAll) {
            if (n.querySelectorAll('select').length) need = true;
          }
        });
      });
      if (need) enhanceAll(document);
    });
    gdsObs.observe(document.documentElement, { childList: true, subtree: true });
  } catch (e) {}

  /* Auto-apply native mobile card styling to filter cards on mobile devices */
  function enhanceMobileFilters(root) {
    if (window.innerWidth > 768) return;
    var base = root || document;

    // 1. Explicit filter form IDs and class selectors
    var filterSelectors = [
      '#filterForm', '#formFilter', '#formFilterCatatan', '#filter-soal-form',
      'form[action*="filter"]', 'form[id*="filter"]', 'form[id*="Filter"]',
      'form[name*="filter"]', 'form[name*="Filter"]',
      'form[method="GET"]', 'form[method="get"]'
    ];

    var forms = base.querySelectorAll(filterSelectors.join(','));
    forms.forEach(function(form) {
      // Avoid modal forms or search bars inside nav
      if (form.closest('.modal') || form.closest('.navbar')) return;
      var card = form.closest('.card');
      if (card && !card.classList.contains('mobile-filter-app-card')) {
        // Only classify as filter card if it contains select elements or date inputs
        if (card.querySelector('select') || card.querySelector('input[type="date"]') || card.querySelector('input[type="month"]')) {
          card.classList.add('mobile-filter-app-card');
        }
      }
    });

    // 2. Target top filter cards with select filters (e.g., nilai_harian, rekap_nilai)
    var allCards = base.querySelectorAll('.section-body > .card, .section-body > .row:first-child .card, .section-body > form > .card');
    allCards.forEach(function(card) {
      if (card.classList.contains('mobile-filter-app-card') || card.closest('.modal')) return;
      var selects = card.querySelectorAll('select');
      var tables = card.querySelectorAll('table');
      // If card has selects (kelas/mapel/semester) and no heavy data tables, treat as filter card
      if (selects.length > 0 && tables.length === 0) {
        card.classList.add('mobile-filter-app-card');
      }
    });

    // 3. Ensure filter card header has icon if header exists
    var filterCards = base.querySelectorAll('.mobile-filter-app-card');
    filterCards.forEach(function(card) {
      var headerH = card.querySelector('.card-header h4, .card-header h5');
      if (headerH && !headerH.querySelector('i')) {
        var icon = document.createElement('i');
        icon.className = 'fas fa-sliders-h mr-1';
        headerH.insertBefore(icon, headerH.firstChild);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      enhanceMobileFilters(document);
    });
  } else {
    enhanceMobileFilters(document);
  }

  window.addEventListener('resize', function() {
    enhanceMobileFilters(document);
  });

  document.addEventListener('shown.bs.modal', function(e) {
    try { enhanceAll(e.target || document); } catch (err) {}
  });

  // Select2 mobile: panel sudah tampil center dialog, jadi TIDAK pakai backdrop overlay
  // (overlay gelap+blur ini yang bikin tampilan rusak, terutama saat select2 ada di dalam modal)
  if (window.jQuery) {
    window.jQuery(document).on('select2:open', function() {
      if (window.innerWidth <= 767.98) {
        var dp = document.querySelector('.select2-dropdown');
        if (dp) {
          dp.classList.add('select2-touch-shield');
          setTimeout(function() {
            dp.classList.remove('select2-touch-shield');
          }, 250);
        }
      }
    });
    window.jQuery(document).on('select2:close', function() {
      var anyGdsOpen = document.querySelectorAll('.gds-wrap.open').length > 0;
      if (anyGdsOpen) return;
      var bd = document.getElementById('gdsBackdropMobile');
      if (bd) bd.classList.remove('active');
      if (!document.querySelector('.modal.show')) {
        document.body.style.overflow = '';
      }
    });
  }

  window.GDSRefresh = function(root) {
    try {
      enhanceAll(root || document);
      enhanceMobileFilters(root || document);
    } catch (err) {}
  };
})();
