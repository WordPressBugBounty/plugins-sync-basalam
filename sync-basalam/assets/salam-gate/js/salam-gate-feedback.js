(function (window, document) {
  "use strict";

  var recent = new Map();
  function gateScope() {
    return document.querySelector('.salam-gate-page, .salam-gate-order-box, [data-sg-feedback-notice]');
  }

  // Reuse WooSalam's existing toast component; never interpret API messages as HTML.
  window.woosalamNotify = function (message, kind) {
    var text = message == null ? "" : String(message).trim();
    if (!text) return false;
    var type = ["success", "error", "warning", "info"].indexOf(kind) >= 0 ? kind : "info";
    var inGate = !!gateScope();
    if (inGate) {
      document.body.classList.add('woosalam-sg-feedback');
      // A failed request can reach both its field/operation handler and loader.
      // Announce that one outcome once; a later explicit retry is a new outcome.
      var now = Date.now(), key = type + "\n" + text;
      recent.forEach(function (timestamp, item) { if (now - timestamp >= 1000) recent.delete(item); });
      if (recent.has(key)) return true;
      recent.set(key, now);
    }
    if (window.BasalamToast && typeof window.BasalamToast[type] === "function") {
      window.BasalamToast[type](text, inGate ? {
        duration: type === "success" ? 4500 : Math.min(12000, Math.max(6500, text.length * 50))
      } : undefined);
      return true;
    }

    // A missing base asset must not make operational feedback disappear.
    var fallback = document.getElementById("woosalam-notification-fallback");
    if (!fallback) {
      fallback = document.createElement("div");
      fallback.id = "woosalam-notification-fallback";
      fallback.setAttribute("aria-live", "polite");
      document.body.appendChild(fallback);
    }
    fallback.className = "notice notice-" + (type === "info" ? "info" : type);
    fallback.setAttribute("role", type === "error" ? "alert" : "status");
    fallback.textContent = text;
    return true;
  };
  function enableGateFeedback() {
    if (!gateScope()) return;
    document.body.classList.add('woosalam-sg-feedback');
    // Only server notices owned by SalamGate are promoted. Other WordPress
    // notices, descriptions and account-status labels retain their behavior.
    document.querySelectorAll('[data-sg-feedback-notice]').forEach(function (notice) {
      if (notice.hidden || notice.style.display === 'none') return;
      var message = notice.getAttribute('data-sg-feedback-message') || notice.textContent.trim();
      if (!message || notice.getAttribute('data-sg-feedback-announced') === '1') return;
      notice.setAttribute('data-sg-feedback-announced', '1');
      window.woosalamNotify(message, notice.getAttribute('data-sg-feedback-kind') || 'error');
      notice.classList.add('sg-feedback-toast-only');
      notice.setAttribute('role', 'group');
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enableGateFeedback);
  else enableGateFeedback();
})(window, document);
