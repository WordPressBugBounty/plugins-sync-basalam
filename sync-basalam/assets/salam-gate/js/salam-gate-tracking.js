/* The postal website owns tracking; this form never calls SalamGate's API. */
jQuery(function ($) {
  "use strict";
  var $root = $("[data-sg-tracking]");
  if (!$root.length) return;
  var $form = $root.find("[data-sg-tracking-form]"), $input = $root.find("[data-sg-tracking-code]");

  function code(value) {
    return String(value || "").replace(/[۰-۹]/g, function (digit) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(digit); })
      .replace(/[٠-٩]/g, function (digit) { return "٠١٢٣٤٥٦٧٨٩".indexOf(digit); })
      .replace(/[\s\u200e\u200f\u202a-\u202e\u2066-\u2069]+/g, "");
  }
  $form.on("submit", function (event) {
    event.preventDefault();
    var trackingCode = code($input.val());
    $input.removeAttr("aria-invalid");
    $root.find("[data-sg-tracking-live]").text("");
    if (!/^[0-9]{24}$/.test(trackingCode)) {
      var message = "کد رهگیری باید شامل ۲۴ رقم باشد.";
      $input.attr("aria-invalid", "true").trigger("focus");
      $root.find("[data-sg-tracking-live]").text(message);
      if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, "error");
      return;
    }
    // A native GET navigates in the same tab, works without an OAuth connection
    // or AJAX nonce, and cannot be blocked as an asynchronous popup.
    $input.val(trackingCode).attr("name", "id");
    $form.attr({ action: "https://tracking.post.ir/", method: "get", target: "_self" });
    window.HTMLFormElement.prototype.submit.call($form[0]);
  });
  $input.on("input", function () {
    $input.removeAttr("aria-invalid");
    $root.find("[data-sg-tracking-live]").text("");
  });
  // Old receipt links may prefill the code, but never send it to a third party
  // merely on page load or when navigating back. The user submits explicitly.
  try {
    var initialCode = code(new URL(window.location.href).searchParams.get("tracking_code"));
    if (/^[0-9]{1,64}$/.test(initialCode)) $input.val(initialCode);
  } catch (ignored) {}
});
