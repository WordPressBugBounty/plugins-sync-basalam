(function (window, document) {
  "use strict";
  var selectors = ".salam-gate-page input[name='value_toman'], .salam-gate-page .sg-send-item-price, .salam-gate-page #calc-value, #salam-gate-parcel-modal .sg-f-value, #salam-gate-parcel-modal .sg-f-proposed-price";
  function raw(value) {
    return String(value == null ? "" : value)
      .replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); })
      .replace(/[٠-٩]/g, function (d) { return "٠١٢٣٤٥٦٧٨٩".indexOf(d); })
      .replace(/[,٬]/g, "").replace(/٫/g, ".");
  }
  function format(value) {
    var text = raw(value);
    // Preserve invalid input for the existing validators instead of changing its value.
    if (!/^-?\d*(?:\.\d+)?$/.test(text) || text === "" || text === "-") return String(value == null ? "" : value);
    var parts = text.split(".");
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    return parts.join("٫").replace(/\d/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[+d]; });
  }
  function present(input) {
    if (input.getAttribute("data-sg-money") === "ready") return;
    input.setAttribute("data-sg-money", "ready");
    input.type = "text";
    input.setAttribute("inputmode", "numeric");
    var descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "value");
    // Scope the setter to these inputs only. jQuery/native value assignments from
    // draft restoration and product selection receive the same presentation.
    Object.defineProperty(input, "value", {
      configurable: true,
      get: function () { return descriptor.get.call(this); },
      set: function (value) { descriptor.set.call(this, format(value)); }
    });
    input.value = descriptor.get.call(input);
  }
  function scan() { document.querySelectorAll(selectors).forEach(present); }
  document.addEventListener("input", function (event) {
    var input = event.target;
    if (!input.matches || !input.matches(selectors)) return;
    present(input);
    var cursor = input.selectionStart;
    var digitsBefore = cursor == null ? 0 : raw(input.value.slice(0, cursor)).length;
    input.value = input.value;
    if (cursor == null) return;
    var position = 0, count = 0;
    while (position < input.value.length && count < digitsBefore) {
      if (!/[,٬]/.test(input.value[position])) count += 1;
      position += 1;
    }
    input.setSelectionRange(position, position);
  }, true);
  window.SalamGateMoney = { raw: raw, format: format, refresh: scan };
  function init() {
    if (!document.querySelector(".salam-gate-page, .salam-gate-order-box, #salam-gate-parcel-modal")) return;
    scan();
    new MutationObserver(function (changes) {
      if (changes.some(function (change) { return change.addedNodes.length; })) scan();
    }).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})(window, document);
