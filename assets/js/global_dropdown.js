"use strict";
(function() {
  function hasSelect2Lib() {
    try { return !!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2); } catch (e) { return false; }
  }
  function isEnhanceable(sel) {
    if (!sel || sel.tagName !== 'SELECT') return false;
    if (sel.multiple) return false;
    if (sel.classList.contains('select2-hidden-accessible')) return false;
    if (sel.closest('.select2-container')) return false;
    if (sel.className && (sel.className.indexOf('select2') !== -1 || sel.id === 'f_siswa' || sel.id === 'inp_siswa') && hasSelect2Lib()) return false;
    if (sel.dataset.gdsDone === '1') return false;
    if (sel.options.length === 0) return false;
    return true;
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
    wrap.appendChild(list);

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
        check.textContent = '✔';
        b.appendChild(span);
        b.appendChild(check);
        b.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          sel.value = o.value;
          refreshLabel();
          closeAll();
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
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
          }
        });
        list.appendChild(b);
      });
      if (count === 0) {
        var d = document.createElement('div');
        d.className = 'gds-empty';
        d.textContent = 'Tidak ada pilihan';
        list.appendChild(d);
      }
    }

    function closeAll(except) {
      document.querySelectorAll('.gds-wrap.open').forEach(function(w) {
        if (w !== except) w.classList.remove('open');
      });
    }

    function positionList() {
      var listEl = wrap.querySelector('.gds-list');
      if (!listEl) return;
      listEl.style.top = '';
      listEl.style.bottom = '';
      listEl.style.left = '';
      listEl.style.width = '';
      var inModal = !!sel.closest('.modal');
      if (inModal) {
        try {
          var r = btn.getBoundingClientRect();
          listEl.style.left = Math.max(8, r.left) + 'px';
          listEl.style.width = Math.max(160, r.width) + 'px';
          var spaceBelow = window.innerHeight - r.bottom;
          if (spaceBelow < 260) {
            listEl.style.top = 'auto';
            listEl.style.bottom = Math.max(8, window.innerHeight - r.top + 6) + 'px';
          } else {
            listEl.style.top = (r.bottom + 6) + 'px';
          }
        } catch (e2) {}
      }
    }
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      var wasOpen = wrap.classList.contains('open');
      closeAll(wrap);
      if (!wasOpen) {
        renderOptions('');
        wrap.classList.add('open');
        positionList();
      }
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
    refreshLabel();

    if (!document.body.dataset.gdsBound) {
      document.body.dataset.gdsBound = '1';
      document.addEventListener('click', function(e) {
        if (!e.target.closest || !e.target.closest('.gds-wrap')) {
          document.querySelectorAll('.gds-wrap.open').forEach(function(w) {
            w.classList.remove('open');
          });
        }
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          document.querySelectorAll('.gds-wrap.open').forEach(function(w) {
            w.classList.remove('open');
          });
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
  function enhanceAll(root) {
    (root || document).querySelectorAll('select.form-control, select.form-control-sm, .dataTables_length select, select[name$="_length"]').forEach(function(s) {
      if (isEnhanceable(s)) {
        try {
          buildOne(s);
          var w = s.closest ? s.closest('.gds-wrap') : null;
          if (w) applyInlineMode(s, w);
        } catch (e) {}
      }
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
  document.addEventListener('shown.bs.modal', function(e) {
    try { enhanceAll(e.target || document); } catch (err) {}
  });
  window.GDSRefresh = function(root) { enhanceAll(root || document); };
})();
