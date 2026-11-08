(function (window) {
  "use strict";
  function digits(text) { return text.replace(/\d/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[+d]; }); }
  function fromRial(rial, suffix) {
    if (typeof rial !== "number" || !Number.isSafeInteger(rial)) return "—";
    var amount = Math.abs(rial), whole = String(Math.floor(amount / 10));
    var text = (rial < 0 ? "−" : "") + whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    if (amount % 10) text += "٫" + amount % 10;
    return digits(text) + (suffix === false ? "" : " تومان");
  }
  function toman(value, suffix) {
    var number = typeof value === "number" ? value : NaN;
    if (!Number.isFinite(number)) return "—";
    return fromRial(Math.round(number * 10), suffix);
  }
  window.SalamGateAmount = Object.freeze({ fromRial: fromRial, toman: toman });
})(window);
