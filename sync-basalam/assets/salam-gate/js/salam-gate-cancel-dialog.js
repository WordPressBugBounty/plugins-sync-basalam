(function (window, document) {
  "use strict";

  function init() {
    var detail = document.getElementById("sgp-detail-modal");
    var review = detail && detail.querySelector("[data-sgd-cancel-review]");
    if (!review || detail.querySelector("[data-sg-cancel-dialog]")) return;

    // Keep the existing confirmation buttons and guarded cancellation handler.
    // This component presents their review in the browser's modal top layer.
    var dialog = document.createElement("dialog");
    dialog.className = "sg-parcel-cancel-dialog";
    dialog.setAttribute("data-sg-cancel-dialog", "");
    dialog.setAttribute("role", "alertdialog");
    dialog.setAttribute("aria-modal", "true");
    dialog.setAttribute("aria-labelledby", "sgd-cancel-title");
    var message = review.querySelector("p");
    if (message) {
      message.id = "sgd-cancel-description";
      dialog.setAttribute("aria-describedby", message.id);
    }
    var closeButton = document.createElement("button");
    closeButton.type = "button";
    closeButton.className = "sg-parcel-cancel-dialog__close";
    closeButton.setAttribute("aria-label", "بستن تأیید لغو مرسوله");
    closeButton.innerHTML = '<span aria-hidden="true">×</span>';
    review.parentNode.insertBefore(dialog, review);
    dialog.append(closeButton, review);

    function busy() { return detail.getAttribute("data-sg-financial-busy") === "true"; }
    function abandon() {
      if (busy()) return;
      var back = review.querySelector("[data-sgd-cancel-back]");
      if (back && !back.disabled) back.click();
    }
    function restoreFocus() {
      if (detail.style.display === "none" || detail.hidden || busy()) return;
      var target = ["[data-sgd-cancel]", "[data-sgd-cancel-check]", "[data-sgd-refresh]"].map(function (selector) {
        return detail.querySelector(selector);
      }).find(function (button) { return button && !button.hidden && !button.disabled; });
      if (target && !target.disabled) target.focus({ preventScroll: true });
    }
    function sync() {
      if (review.hidden || detail.hidden || detail.style.display === "none") {
        if (!review.hidden) review.hidden = true;
        if (dialog.open) {
          if (typeof dialog.close === "function") dialog.close();
          else { dialog.removeAttribute("open"); restoreFocus(); }
        }
        return;
      }
      if (dialog.open) return;
      if (typeof dialog.showModal === "function") dialog.showModal();
      else dialog.setAttribute("open", "");
      var back = review.querySelector("[data-sgd-cancel-back]");
      if (back) back.focus({ preventScroll: true });
    }
    closeButton.addEventListener("click", abandon);
    dialog.addEventListener("cancel", function (event) {
      event.preventDefault();
      abandon();
    });
    dialog.addEventListener("close", function () {
      review.hidden = true;
      restoreFocus();
    });
    dialog.addEventListener("click", function (event) {
      if (event.target !== dialog) return;
      var bounds = dialog.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) abandon();
    });
    dialog.addEventListener("keydown", function (event) {
      if (!dialog.open) return;
      if (event.key === "Escape") {
        event.preventDefault(); event.stopPropagation(); abandon();
        return;
      }
      if (event.key !== "Tab") return;
      var buttons = Array.from(dialog.querySelectorAll("button:not(:disabled)")).filter(function (button) { return !button.hidden; });
      if (!buttons.length) { event.preventDefault(); return; }
      var first = buttons[0], last = buttons[buttons.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    var observer = new window.MutationObserver(sync);
    observer.observe(review, { attributes: true, attributeFilter: ["hidden"] });
    observer.observe(detail, { attributes: true, attributeFilter: ["hidden", "style"] });
    sync();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init, { once: true });
  else init();
})(window, document);
