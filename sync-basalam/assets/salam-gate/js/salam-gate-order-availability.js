(function () {
  "use strict";
  var config = window.woosalamOrderAvailability;
  if (!config || !window.fetch || !window.AbortController) return;
  var controller = new AbortController();
  var interacted = false;
  function protectEdits() { interacted = true; }
  ["input", "change", "submit", "click", "pagehide"].forEach(function (event) {
    window.addEventListener(event, protectEdits, true);
  });
  var timer = window.setTimeout(function () { controller.abort(); }, 20000);
  var body = new URLSearchParams({ action: "salam_gate_order_availability", _wpnonce: config.nonce });
  window.fetch(config.url, { method: "POST", credentials: "same-origin", body: body, signal: controller.signal })
    .then(function (response) { return response.ok ? response.json() : null; })
    .then(function (result) {
      // A successful read populated the server cache. Reload once to render the
      // actual columns and metabox; never add shipping controls on the client.
      if (!interacted && result && result.success && result.data && result.data.available === true) window.location.reload();
    })
    .catch(function () { /* Unknown is not activated: leave the ordinary Woo screen untouched. */ })
    .finally(function () {
      window.clearTimeout(timer);
      ["input", "change", "submit", "click", "pagehide"].forEach(function (event) {
        window.removeEventListener(event, protectEdits, true);
      });
    });
}());
