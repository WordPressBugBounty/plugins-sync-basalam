/** Original Basalam state. A cached Woo or postal status never authorizes shipping. */
(function (window, $) {
  "use strict";
  if (!$) return;

  function stateFor(value, box) {
    var order = Number($(box).attr("data-order-id"));
    var invoice = Number($(box).find("[data-sg-order-source-status]").first().attr("data-sg-basalam-order-id"));
    return $.isPlainObject(value) && Number.isSafeInteger(value.basalam_order_id) && value.basalam_order_id > 0
      && Number.isSafeInteger(value.vendor_id) && value.vendor_id > 0
      && typeof value.cancelled === "boolean" && typeof value.shipping_allowed === "boolean"
      && typeof value.status_title === "string" && value.status_title.length <= 400
      && (value.blocked_reason === null || typeof value.blocked_reason === "string")
      && Number.isSafeInteger(value.checked_at) && value.checked_at > 0
      && Number.isSafeInteger(order) && order > 0 && invoice === value.basalam_order_id ? value : null;
  }
  function apply(box, state, message, busy) {
    var $box = $(box), $section = $box.find("[data-sg-order-source-status]").first();
    if (!$section.length) return;
    state = stateFor(state, box);
    var blocked = busy || !state || !state.shipping_allowed;
    $box.attr("data-sg-source-blocked", blocked ? "1" : "0");
    $section.toggleClass("is-loading", !!busy).toggleClass("is-cancelled", !!state && state.cancelled)
      .toggleClass("is-unavailable", !busy && !state).attr("aria-busy", String(!!busy));
    $section.find("[data-sg-order-source-title]").text(busy ? "در حال بررسی…" : state ? state.status_title : "وضعیت دریافت نشد");
    $section.find("[data-sg-order-source-message]").text(message || (state && state.blocked_reason)
      || (state ? "وضعیت اصلی بررسی شد؛ هر مرسوله پیش از ثبت و پرداخت، دوباره جداگانه بررسی می‌شود."
        : "برای جلوگیری از ارسال سفارش لغوشده، تا بررسی وضعیت باسلام ثبت و پرداخت تازه متوقف است."));
    $section.find("[data-sg-order-source-refresh]").prop("disabled", !!busy || $box.attr("data-sg-order-financial-busy") === "1");
    if (blocked) {
      $box.find(".salam-gate-open-modal:not([data-sg-native-receipt='1']), .salam-gate-pay").prop("disabled", true);
    }
    $(document).trigger("salamgate:source-state-updated", [box, state]);
  }

  function mount(box) {
    var $box = $(box), $section = $box.find("[data-sg-order-source-status]").first();
    if ($box.attr("data-basalam-order") !== "1" || !$section.length) return;
    var order = String($box.attr("data-order-id") || "");
    if (!/^[1-9]\d*$/.test(order) || !Number.isSafeInteger(Number(order))) return;
    var old = $box.data("sgSourceStateController");
    if (old && old.section === $section[0] && old.order === order) return;
    if (old) old.dispose();
    var pending = null, sequence = 0, disposed = false;
    function current(epoch) { return !disposed && epoch === sequence && document.contains($section[0])
      && String($box.attr("data-order-id")) === order; }
    function read() {
      if (pending || disposed || $box.attr("data-sg-order-financial-busy") === "1") return;
      var epoch = ++sequence;
      apply(box, null, "وضعیت فعلی سفارش مستقیماً از باسلام دریافت می‌شود…", true);
      var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
      pending = request(basalam_ajax.ajaxurl, { action: "salam_gate_basalam_order_state", order_id: order, _wpnonce: basalam_ajax.nonce })
        .done(function (response) {
          if (!current(epoch)) return;
          var state = response && response.success === true && response.data ? stateFor(response.data.source_state, box) : null;
          var error = response && response.success === false && response.data && typeof response.data.message === "string"
            ? response.data.message : "";
          apply(box, state, state ? "" : error, false);
        }).fail(function () { if (current(epoch)) apply(box, null, "وضعیت سفارش باسلام دریافت نشد؛ اتصال را بررسی کنید و دوباره دکمه بررسی وضعیت را بزنید.", false); })
        .always(function () { if (current(epoch)) pending = null; });
    }
    $section.on("click.sgSourceState", "[data-sg-order-source-refresh]", read);
    var controller = { section: $section[0], order: order, dispose: function () {
      disposed = true; ++sequence; $section.off(".sgSourceState");
      if (pending && typeof pending.abort === "function") pending.abort();
      pending = null; $box.removeData("sgSourceStateController");
    } };
    $box.data("sgSourceStateController", controller);
    read();
  }
  window.SalamGateOrderSourceStatus = { mount: mount };
  $(function () { $(".salam-gate-order-box[data-basalam-order='1']").each(function () { mount(this); }); });
  $(document).on("salamgate:order-box-refreshed", function (event, box) { if (box) mount(box); });
  document.addEventListener("click", function (event) {
    var button = event.target && event.target.closest ? event.target.closest(".salam-gate-open-modal, .salam-gate-pay") : null;
    if (!button || button.getAttribute("data-sg-native-receipt") === "1") return;
    var box = button.closest(".salam-gate-order-box[data-basalam-order='1']");
    if (box && box.getAttribute("data-sg-source-blocked") === "1") {
      event.preventDefault(); event.stopImmediatePropagation();
      var refresh = box.querySelector("[data-sg-order-source-refresh]"); if (refresh) refresh.focus();
    }
  }, true);
  $(window).on("pagehide", function () { $(".salam-gate-order-box").each(function () {
    var controller = $(this).data("sgSourceStateController"); if (controller) controller.dispose();
  }); });
  $(window).on("pageshow", function (event) {
    if ((event.originalEvent && event.originalEvent.persisted) || event.persisted === true) {
      $(".salam-gate-order-box[data-basalam-order='1']").each(function () { mount(this); });
    }
  });
})(window, window.jQuery);
