(function () {
  'use strict';

  function qsAll(sel) {
    return Array.prototype.slice.call(document.querySelectorAll(sel));
  }

  function show(el) {
    if (!el) return;
    el.style.display = 'flex';
  }

  function hide(el) {
    if (!el) return;
    el.style.display = 'none';
  }

  function getPopupId(el) {
    var id = (el && el.id) ? el.id : '';
    var m = id.match(/atora-popup-(\d+)/);
    return m ? m[1] : null;
  }

  function storageKey(id) {
    return 'atora_popup_seen_' + String(id);
  }

  function alreadySeen(id, frequency) {
    if (!id) return false;
    try {
      if (frequency === 'always') return false;
      if (frequency === 'session') return sessionStorage.getItem(storageKey(id)) === '1';
      // default: local
      return localStorage.getItem(storageKey(id)) === '1';
    } catch (e) {
      return false;
    }
  }

  function markSeen(id, frequency) {
    if (!id) return;
    try {
      if (frequency === 'session') {
        sessionStorage.setItem(storageKey(id), '1');
        return;
      }
      localStorage.setItem(storageKey(id), '1');
    } catch (e) {
      // ignore
    }
  }

  function attachBackgroundClose(el) {
    el.addEventListener('click', function (e) {
      if (e.target === el) {
        var id = getPopupId(el);
        window.atoraClosePopup(id);
      }
    });
  }

  window.atoraClosePopup = function (popupId) {
    var el = document.getElementById('atora-popup-' + String(popupId));
    hide(el);
  };

  function schedulePopup(el) {
    var id = getPopupId(el);
    if (!id) return;

    var trigger = el.getAttribute('data-trigger') || 'delay';
    var frequency = el.getAttribute('data-frequency') || 'local';
    var delay = parseInt(el.getAttribute('data-delay') || '0', 10);
    var scrollPercent = parseInt(el.getAttribute('data-scroll') || '50', 10);

    if (alreadySeen(id, frequency)) return;

    function openOnce() {
      if (alreadySeen(id, frequency)) return;
      show(el);
      markSeen(id, frequency);
    }

    attachBackgroundClose(el);

    if (trigger === 'scroll') {
      var onScroll = function () {
        var doc = document.documentElement;
        var total = (doc.scrollHeight - doc.clientHeight);
        if (total <= 0) return;
        var pct = Math.round((window.scrollY / total) * 100);
        if (pct >= scrollPercent) {
          window.removeEventListener('scroll', onScroll);
          openOnce();
        }
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      return;
    }

    // default: delay (or any other value)
    window.setTimeout(openOnce, Math.max(0, delay) * 1000);
  }

  document.addEventListener('DOMContentLoaded', function () {
    qsAll('.atora-popup').forEach(schedulePopup);
  });
})();

