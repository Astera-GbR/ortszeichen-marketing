/**
 * ORTSZEICHEN Marketing Frontend: Discreet Social Proof Toast & Cart Heartbeat.
 *
 * Lightweight vanilla JavaScript (~3 KB).
 * Zero external tracking cookies, zero analytics bloat.
 * Frequency capped: max 1 toast per session, fully dismissible.
 */

(function () {
  'use strict';

  if (typeof window === 'undefined') {
    return;
  }

  // 1. Session Frequency Capping Check
  if (sessionStorage.getItem('oz_social_proof_dismissed')) {
    return;
  }

  // Check if current page is checkout or cart
  var path = window.location.pathname.toLowerCase();
  if (path.indexOf('kasse') !== -1 || path.indexOf('checkout') !== -1 ||
      path.indexOf('warenkorb') !== -1 || path.indexOf('cart') !== -1) {
    return;
  }

  var config = window.ozMarketingData || {
    restUrl: '/wp-json/ortszeichen-marketing/v1/',
    nonce: '',
    initialDelay: 45000,
    toastDuration: 6000
  };

  /**
   * Display social proof toast notification.
   */
  function showSocialProofToast(item) {
    if (!item || !item.message) {
      return;
    }

    var toast = document.createElement('aside');
    toast.className = 'oz-social-proof-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');

    toast.innerHTML = [
      '<div class="oz-social-proof-inner">',
      '  <span class="oz-social-proof-indicator" aria-hidden="true"></span>',
      '  <div class="oz-social-proof-body">',
      '    <p class="oz-social-proof-msg">' + escapeHtml(item.message) + '</p>',
      '    <span class="oz-social-proof-meta">' + escapeHtml(item.time_ago || 'Kürzlich') + ' &bull; Verifizierte Bestellung</span>',
      '  </div>',
      '  <button type="button" class="oz-social-proof-close" aria-label="Benachrichtigung schließen">&times;</button>',
      '</div>'
    ].join('');

    document.body.appendChild(toast);

    // Trigger entrance transition
    requestAnimationFrame(function () {
      toast.classList.add('oz-toast-visible');
    });

    function dismissToast() {
      sessionStorage.setItem('oz_social_proof_dismissed', '1');
      toast.classList.remove('oz-toast-visible');
      toast.classList.add('oz-toast-hidden');
      setTimeout(function () {
        if (toast.parentNode) {
          toast.parentNode.removeChild(toast);
        }
      }, 400);
    }

    var closeBtn = toast.querySelector('.oz-social-proof-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', dismissToast);
    }

    // Auto dismiss after duration
    setTimeout(function () {
      if (document.body.contains(toast)) {
        dismissToast();
      }
    }, config.toastDuration);
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /**
   * Fetch social proof payload from REST endpoint.
   */
  function fetchAndDisplay() {
    var url = config.restUrl + 'social-proof';
    fetch(url, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
        'X-WP-Nonce': config.nonce
      }
    })
      .then(function (res) {
        if (!res.ok) throw new Error('Status ' + res.status);
        return res.json();
      })
      .then(function (data) {
        if (data && data.success && Array.isArray(data.recent_purchases) && data.recent_purchases.length > 0) {
          // Select a recent order (first or random from authentic list)
          var randomIndex = Math.floor(Math.random() * Math.min(3, data.recent_purchases.length));
          showSocialProofToast(data.recent_purchases[randomIndex]);
        }
      })
      .catch(function () {
        // Silently ignore network errors to never degrade UX
      });
  }

  // Schedule after initial engagement or delay
  var timer = setTimeout(fetchAndDisplay, config.initialDelay);

  // Fast-track display on substantial engagement (scroll > 50%) but at least 15s after load
  var scrollEngaged = false;
  window.addEventListener('scroll', function onScroll() {
    if (scrollEngaged) return;
    var scrollPercent = (window.scrollY + window.innerHeight) / document.documentElement.scrollHeight;
    if (scrollPercent > 0.5) {
      scrollEngaged = true;
      window.removeEventListener('scroll', onScroll);
      clearTimeout(timer);
      setTimeout(fetchAndDisplay, 8000);
    }
  }, { passive: true });

})();
