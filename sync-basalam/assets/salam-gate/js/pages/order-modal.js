// =====================================================================
// Salam Gate — order parcel modal (order screens and the real Basalam menu picker)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("order-modal")) return;
  var $box = $(".salam-gate-order-box");
  if (!$box.length) return;

  var orderId = String($box.data("order-id") || "");
  var $modal = $("#salam-gate-parcel-modal");
  var orderModalTrigger = null;
  var menuOrderHost = $box.is("[data-sg-menu-order-host]");
  var listOrderHost = $box.is("[data-sg-order-list-host]");
  var listOrderIdentity = null;
  var menuOrderIdentity = null, orderTerminalLocks = Object.create(null);
  if ((!orderId && !menuOrderHost && !listOrderHost) || !$modal.length) return;
  var orderParcelId = null, orderMarket = "website", orderEpoch = 0;
  var selectedBasalamParcelId = null, orderReadOnly = false;
  var orderBusy = "", orderLocked = false, orderFormReady = false;
  var orderSelectedQuote = null, orderQuotes = null, orderTerminalMessage = "";
  var orderStage = "form", orderInformationDirty = false, orderFingerprint = "", orderFilling = false;
  var orderItemsEditable = false, orderItemWeightsEditable = false, orderPendingRemoval = null, orderDisplay = {}, orderReadonlyItems = [];
  var orderConfirmedWeight = "";
  var orderLoadError = "", orderOpenMode = "edit";
  var orderSuspended = null;
  var orderSuspendedReviews = Object.create(null);

  var tree = [], referenceBoxes = [], referenceRequest = null;

  function sgLoadReferences(context) {
    if (tree.length && referenceBoxes.length) return $.Deferred().resolve().promise();
    if (referenceRequest) return referenceRequest;
    var ready = $.Deferred();
    referenceRequest = ready.promise();
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    request(basalam_ajax.ajaxurl, { action: "salam_gate_parcel_references", _wpnonce: basalam_ajax.nonce })
      .done(function (response) {
        var data = response && response.success === true ? response.data : null;
        // Preserve each successful dataset independently across partial failures.
        if (data && Array.isArray(data.provinces) && data.provinces.length) tree = data.provinces;
        if (data && Array.isArray(data.box_types) && data.box_types.length) {
          referenceBoxes = data.box_types;
          var $select = $modal.find(".sg-f-box"), selected = $select.val();
          $select.empty();
          referenceBoxes.forEach(function (box) { $("<option>").val(box.id).text(box.title).appendTo($select); });
          if (selected) $select.val(selected);
        }
        referenceRequest = null;
        if (tree.length && referenceBoxes.length) ready.resolve();
        else ready.reject();
      })
      .fail(function () { referenceRequest = null; ready.reject(); });
    return ready.promise();
  }

  function sgOrderPost(action, data) {
    // Pin the actual native parcel at dispatch; caller form data cannot replace it.
    var identity = sgOrderContext();
    var payload = $.extend({}, data || {}, { _wpnonce: basalam_ajax.nonce, action: action, order_id: identity.order });
    if (identity.native !== null) payload.basalam_parcel_id = identity.native;
    else delete payload.basalam_parcel_id;
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, payload);
  }

  function sgOrderContext() {
    return { order: orderId, native: selectedBasalamParcelId, epoch: orderEpoch };
  }
  function sgCurrentOrderContext(context) {
    return context.order === orderId && context.native === selectedBasalamParcelId && context.epoch === orderEpoch;
  }
  function sgOrderKey(order, native) { return String(order) + ":" + (native === null ? "website" : String(native)); }
  function sgFinancialBusy() { return ["save", "select", "pay"].indexOf(orderBusy) !== -1; }
  function sgRememberTerminal() {
    if (orderLocked) orderTerminalLocks[sgOrderKey(orderId, selectedBasalamParcelId)] = orderTerminalMessage
      || "وضعیت این مرسوله را بررسی کنید؛ ثبت یا پرداخت مجدد انجام ندهید.";
  }
  function sgSelectNativeParcel(order, native) {
    if (sgFinancialBusy()) return false;
    var changed = String(order) !== orderId || native !== selectedBasalamParcelId;
    if (changed) {
      sgRememberTerminal();
      sgPauseLabelJob(sgOrderContext());
      orderId = String(order); selectedBasalamParcelId = native;
      ++orderEpoch; clearTimeout(sgLabelPollTimer);
      orderBusy = ""; orderParcelId = null; orderReadOnly = false;
      orderLocked = false; orderTerminalMessage = "";
      orderMarket = native !== null ? "basalam" : "website";
      orderFormReady = false; orderSelectedQuote = null; orderQuotes = null;
      orderConfirmedWeight = ""; orderDisplay = {}; orderReadonlyItems = [];
      $modal.find(".sg-f-name, .sg-f-mobile, .sg-f-address, .sg-f-postal, .sg-f-value, .sg-f-weight").val("");
      $modal.find(".sg-f-items, .sg-quotes-cards, [data-sg-order-review-summary]").empty();
      $modal.find("[data-sg-order-receipt-note]").remove();
    }
    var remembered = orderTerminalLocks[sgOrderKey(orderId, selectedBasalamParcelId)];
    if (remembered) { orderLocked = true; orderTerminalMessage = remembered; }
    return true;
  }

  $(document).on("salamgate:menu-order-open", function (event, selection) {
    var source = $("[data-send-markets] input[name='market']:checked").val();
    if (!menuOrderHost || orderBusy || $modal.css("display") !== "none"
      || !$.isPlainObject(selection) || !Number.isSafeInteger(selection.order_id) || selection.order_id <= 0) return;
    var native = null;
    if (source === "basalam") {
      if (selection.market && selection.market !== "basalam"
        || !Number.isSafeInteger(selection.basalam_order_id) || selection.basalam_order_id <= 0
        || !Number.isSafeInteger(selection.basalam_parcel_id) || selection.basalam_parcel_id <= 0) return;
      native = selection.basalam_parcel_id;
    } else if (source !== "website" || selection.market !== "website"
      || selection.basalam_order_id != null || selection.basalam_parcel_id != null) return;
    var nextId = String(selection.order_id);
    if (!sgSelectNativeParcel(nextId, native)) return;
    menuOrderIdentity = { market: source, order: nextId, invoice: selection.basalam_order_id || null, parcel: native };
    orderModalTrigger = selection.trigger && selection.trigger.nodeType === 1 ? selection.trigger : null;
    $box.attr("data-order-id", orderId).data("order-id", orderId).empty().prop("hidden", false).show();
    if (orderTerminalLocks[sgOrderKey(orderId, selectedBasalamParcelId)]) {
      orderLocked = true;
      orderTerminalMessage = orderTerminalLocks[sgOrderKey(orderId, selectedBasalamParcelId)];
      sgShowSidebarTerminal();
      sgRefreshMetabox();
      return;
    }
    sgOpenModal("edit");
    sgRefreshMetabox();
  });

  // The list adapter gets its mode/market from the guarded, freshly read order.
  // A Basalam parent first discovers exact native children; a confirmed single
  // child opens directly, while multiple/incomplete results retain the chooser.
  $(document).on("salamgate:list-order-open", function (event, selection) {
    if (!listOrderHost || orderBusy || $modal.css("display") !== "none"
      || !$.isPlainObject(selection) || !Number.isSafeInteger(selection.order_id) || selection.order_id <= 0
      || ["website", "basalam"].indexOf(selection.market) === -1
      || ["edit", "payment", "native", "receipt"].indexOf(selection.mode) === -1
      || (selection.market === "basalam" && selection.mode !== "native")
      || (selection.market === "website" && selection.mode === "native")) return;
    if (!sgSelectNativeParcel(String(selection.order_id), null)) return;
    listOrderIdentity = { order: orderId, market: selection.market };
    orderMarket = selection.market;
    orderModalTrigger = selection.trigger && selection.trigger.nodeType === 1 ? selection.trigger : null;
    if (selection.mode === "native") {
      if (window.SalamGateOrderParcels) window.SalamGateOrderParcels.mount($box[0]);
      if (window.SalamGateOrderSourceStatus) window.SalamGateOrderSourceStatus.mount($box[0]);
      sgUpdateControls();
      $(document).trigger("salamgate:list-native-show");
    } else if (orderLocked && selection.mode !== "receipt") {
      sgOrderNotify(orderTerminalMessage || "وضعیت پرداخت قبلی را از صفحه سفارش بررسی کنید.");
    } else sgOpenModal(selection.mode);
  });

  function sgOrderNotify(message, kind) {
    if (typeof window.woosalamNotify === "function") {
      window.woosalamNotify(message, kind || "error");
    } else {
      sgBanner(message);
    }
  }

  function sgReceiverEditor(open) {
    var expanded = !orderReadOnly && !!open;
    $modal.find(".sg-order-receiver-fields").toggle(expanded);
    $modal.find(".sg-order-receiver-summary").prop("hidden", expanded).toggle(!expanded);
    $modal.find(".sg-order-receiver-edit").prop("hidden", orderReadOnly).toggle(!orderReadOnly)
      .attr("aria-expanded", String(expanded)).text(sgReceiverButtonLabel(expanded));
  }

  function sgReceiverButtonLabel(expanded) {
    if (orderReadOnly) return "";
    return expanded ? "بستن ویرایش گیرنده" : (sgReceiverComplete() ? "ویرایش گیرنده" : "تکمیل اطلاعات گیرنده");
  }

  function sgReceiverComplete() {
    return $.trim($modal.find(".sg-f-name").val() || "").length >= 3
      && /^09\d{9}$/.test(sgRawMoney($modal.find(".sg-f-mobile").val()).trim())
      && /^\d{10}$/.test(sgRawMoney($modal.find(".sg-f-postal").val()).trim())
      && $.trim($modal.find(".sg-f-address").val() || "").length >= 10
      && !!$modal.find(".sg-f-city").val();
  }

  function sgReceiverSummary() {
    $.each(["name", "mobile", "address", "postal"], function (_, field) {
      $modal.find('[data-sg-order-summary="' + field + '"]').text($modal.find(".sg-f-" + field).val() || "نیاز به تکمیل");
    });
    var province = $modal.find(".sg-f-province").val();
    var city = $modal.find(".sg-f-city").val() ? $modal.find(".sg-f-city option:selected").text() : "";
    $modal.find('[data-sg-order-summary="destination"]').text([province, city].filter(Boolean).join(" — ") || "نیاز به تکمیل");
  }

  function sgClearPaymentError() {
    $modal.find("[data-sg-order-wallet-topup]").remove();
    $modal.find("[data-sg-order-modal-payment-feedback]").hide().empty().removeClass("sg-feedback-actions sg-feedback-toast-only");
  }

  function sgShowSidebarTerminal() {
    if (!orderTerminalMessage) return;
    var $feedback = $box.find("[data-sg-order-payment-feedback]");
    if (!$feedback.length) $feedback = $("<div class='sg-order-payment-feedback' data-sg-order-payment-feedback role='alert'>").appendTo($box);
    $feedback.empty().append($("<p>").text(orderTerminalMessage)).show()
      .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function")
      .attr("role", typeof window.woosalamNotify === "function" ? "group" : "alert");
    if (orderSuspendedReviews[sgOrderKey(orderId, selectedBasalamParcelId)]) {
      $feedback.append($("<button type='button' class='button' data-sg-order-box-read-retry>").text("بررسی وضعیت مرسوله"))
        .removeClass("sg-feedback-toast-only").toggleClass("sg-feedback-actions", typeof window.woosalamNotify === "function");
    }
  }

  function sgPaymentError(data, dispatchedMethod, durable, kind) {
    $modal.find("[data-sg-order-wallet-topup]").remove();
    var feedbackStage = orderStage === "form" ? "form" : orderStage === "confirm" ? "confirm" : "quotes";
    var $feedback = $modal.find("[data-sg-order-modal-payment-feedback]").prependTo($modal.find('[data-stage="' + feedbackStage + '"]'));
    var message = String(data.message || "خطا در پرداخت مرسوله.").replace(/بسالم/g, "باسلام");
    var paymentReview = ["pay_unknown", "unknown"].indexOf(data.code) !== -1 || ["pending", "unknown"].indexOf(data.payment_state) !== -1
      || data.operation && data.operation.state === "unknown";
    if (paymentReview) message = "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.";
    $feedback.empty().append($("<p>").text(message));
    var walletInsufficient = data.code !== "pay_unknown" && data.code !== "already_paid"
      && dispatchedMethod === "21" && data.kind === "validation"
      && Array.isArray(data.errors) && data.errors.some(function (error) { return error && error.location === "user_wallet"; });
    if (walletInsufficient) {
      $modal.find('[data-stage="confirm"] .sg-order-form-actions').prepend($("<a class='basalam-secondary-button sg-payment-topup' data-sg-order-wallet-topup>").attr({
        href: "https://wallet.basalam.com/?from=vendor", target: "_blank", rel: "noopener noreferrer"
      }).append($("<span class='dashicons dashicons-money-alt' aria-hidden='true'>"), $("<span>").text("شارژ کیف پول")));
    }
    if (paymentReview) $feedback.append($("<a class='sg-order-payment-review'>").attr({
      href: "admin.php?page=salam_gate_parcels", target: "_blank", rel: "noopener noreferrer"
    }).text("بررسی وضعیت مرسوله"));
    $feedback.show();
    if (typeof window.woosalamNotify === "function") {
      $feedback.attr("role", "group").removeAttr("tabindex")
        .toggleClass("sg-feedback-actions", paymentReview).toggleClass("sg-feedback-toast-only", !paymentReview);
      sgOrderNotify(message, kind || "error");
    } else $feedback.attr("tabindex", "-1").attr("role", "alert").trigger("focus");
    if (durable) {
      orderTerminalMessage = message;
      if (paymentReview) $modal.find(".salam-gate-select-quote").text("در حال بررسی");
    }
  }

  function sgRefreshBalances(force) {
    if (!orderFormReady || orderMarket !== "website") return;
    var presentation = window.SalamGatePaymentPresentation;
    if (!presentation) return;
    if (force && typeof presentation.refreshBalance === "function") presentation.refreshBalance($modal[0]);
    else if (typeof presentation.refresh === "function") presentation.refresh($modal[0]);
    if (typeof presentation.whenReady === "function") return presentation.whenReady($modal[0]);
  }

  function sgValidQuote(quote) {
    return $.isPlainObject(quote) && typeof quote.hash_id === "string" && quote.hash_id.trim() !== ""
      && !quote.error && Number.isSafeInteger(quote.total_rial) && quote.total_rial >= 0;
  }

  function sgCurrentWeight() {
    return sgRawMoney($modal.find(".sg-f-weight").val()).trim();
  }

  function sgWeightConfirmed() {
    var weight = sgCurrentWeight(), number = Number(weight);
    return weight !== "" && Number.isSafeInteger(number) && number >= 50 && number <= 30000 && orderConfirmedWeight === weight;
  }

  function sgWeightReview() {
    var weight = Number(sgCurrentWeight()), review = orderDisplay.weight_confirmation || {};
    var label = Number.isSafeInteger(weight) && weight > 0
      ? "وزن بسته با بسته‌بندی (" + weight.toLocaleString("fa-IR").replace(/٬/g, ",") + " گرم) را بررسی کرده‌ام و تأیید می‌کنم."
      : "وزن بسته با بسته‌بندی را وارد و تأیید کنید.";
    $modal.find("[data-sg-order-weight-confirm-label]").text(label);
    $modal.find("[data-sg-order-confirm-weight]").prop("checked", sgWeightConfirmed());
    var message = typeof review.message === "string" && review.message.trim() ? review.message
      : review.has_missing_item_weights ? "وزن بعضی کالاها ثبت نشده است؛ وزن پیشنهادی را با وزن واقعی بسته بررسی و در صورت نیاز ویرایش کنید."
        : "وزن نهایی باید شامل کالاها و بسته‌بندی باشد؛ پیش از ارسال آن را بررسی کنید.";
    if (message === "وزن ذخیره‌شده مرسوله نمایش داده شده است؛ آن را با وزن واقعی بسته بررسی و در صورت نیاز ویرایش کنید.") message = "";
    $modal.find("[data-sg-order-weight-message]").text(orderReadOnly ? "" : message).prop("hidden", orderReadOnly || !message);
    $modal.find("[data-sg-order-weight-confirmation]").toggleClass("is-confirmed", sgWeightConfirmed());
  }

  function sgRevokeWeightConfirmation() {
    orderConfirmedWeight = "";
    $modal.find("[data-sg-order-weight-error]").text("").prop("hidden", true);
    sgWeightReview();
  }

  function sgRequireWeightConfirmation() {
    if (sgWeightConfirmed()) return true;
    var $stage = $modal.find('[data-stage="' + orderStage + '"]');
    var message = "وزن بسته را بررسی کنید و برای ادامه تأیید وزن را بزنید.";
    $stage.find("[data-sg-order-weight-error]").text(message).prop("hidden", false)
      .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
    sgOrderNotify(message, "error");
    $stage.find("[data-sg-order-confirm-weight]").trigger("focus");
    return false;
  }

  function sgUpdateControls() {
    $modal.attr("data-sg-payable-rial", sgValidQuote(orderSelectedQuote) ? orderSelectedQuote.total_rial : "");
    var locked = !!orderBusy || orderLocked;
    sgRememberTerminal();
    sgWeightReview();
    $modal.toggleClass("is-receipt", orderReadOnly);
    if (orderReadOnly) sgReceiverEditor(false);
    else $modal.find(".sg-order-receiver-edit").prop("hidden", false).show();
    $modal.find(".sg-order-modal-heading .basalam-modal-title").text(orderReadOnly ? "جزئیات مرسوله" : "ارسال با سلام‌گیت");
    $modal.find("[data-sg-order-weight-confirmation]").prop("hidden", orderReadOnly);
    $modal.find('[data-stage="form"] > .sg-order-form-actions').toggle(!orderReadOnly);
    $modal.find(".salam-gate-get-rates").prop("disabled", locked || !orderFormReady || !sgWeightConfirmed())
      .text(orderBusy === "save" ? "در حال دریافت نرخ‌های ارسال…" : "مرحله بعد");
    $modal.find(".salam-gate-select-quote").prop("disabled", locked || !sgValidQuote(orderSelectedQuote) || !sgWeightConfirmed())
      .text(orderBusy === "pay" ? "در حال پرداخت…" : (orderBusy === "select" ? "در حال تأیید روش ارسال…"
        : orderLocked && orderTerminalMessage.indexOf("تأیید پرداخت هنوز دریافت نشده") !== -1 ? "در حال بررسی"
          : orderMarket === "basalam" ? "پرداخت از مبلغ سفارش" : "تأیید و پرداخت هزینه ارسال"));
    $modal.find("[data-sg-order-review]").prop("disabled", locked || orderInformationDirty || !sgValidQuote(orderSelectedQuote));
    $modal.find(".sg-pay-method, .sg-payment-card input, .salam-gate-back-form, [data-sg-order-back-quotes]").prop("disabled", locked);
    $modal.find("[data-sg-order-confirm-weight]").prop("disabled", locked);
    $modal.find("[data-stage='form'] input, [data-stage='form'] textarea, [data-stage='form'] select").prop("disabled", locked);
    // Receipt controls are read-only provider actions, not editable shipping
    // fields. Keep the allowed label-template selector usable in a receipt.
    $modal.find("[data-sg-order-receipt-note] .salam-gate-label-template").prop("disabled", !!orderBusy);
    $modal.find(".sg-f-value").prop("readOnly", false)
      .attr({ min: "2000", max: orderMarket === "basalam" ? "500000000" : "1000000000",
        step: orderMarket === "basalam" ? "0.1" : "1", "data-sg-integer": orderMarket === "basalam" ? "0" : "1" });
    if (orderMarket === "basalam") $modal.find(".sg-f-value").attr("data-sg-decimal-places", "1");
    else $modal.find(".sg-f-value").removeAttr("data-sg-decimal-places");
    $modal.find(".sg-f-value-label").text(orderMarket === "basalam" ? "ارزش اعلامی مرسوله (تومان)" : "ارزش مرسوله (تومان)");
    $modal.find(".sg-dims-inputs input").attr({ min: orderMarket === "basalam" ? "10" : "1", max: orderMarket === "basalam" ? "100" : "2147483647" });
    $modal.find("[data-sg-order-value-hint]").prop("hidden", orderMarket !== "basalam");
    $modal.find(".sg-order-receiver-edit").prop("hidden", orderReadOnly).prop("disabled", !!orderBusy || (locked && !orderReadOnly))
      .text(sgReceiverButtonLabel($modal.find(".sg-order-receiver-edit").attr("aria-expanded") === "true"));
    $modal.find(".sg-f-non-standard").prop("disabled", locked || orderMarket === "basalam")
      .closest(".basalam-form-row").toggle(orderMarket === "website");
    if (orderMarket === "basalam") $modal.find(".sg-proposed-price-row").hide();
    $modal.find(".sg-f-items input, .sg-f-items button, [data-sg-order-add-item]").prop("disabled", locked || !orderItemsEditable);
    if (!orderItemsEditable && orderItemWeightsEditable) $modal.find(".sg-item-weight").prop("disabled", locked);
    $modal.find("[data-sg-order-add-item]").toggle(orderItemsEditable);
    $modal.find("[data-sg-order-step]").each(function () {
      var number = Number($(this).attr("data-sg-order-step"));
      var active = number === ({ form: 1, quotes: 2, confirm: 3 }[orderStage]);
      var available = number === 1 || (number === 2 && orderQuotes && !orderInformationDirty) || (number === 3 && sgValidQuote(orderSelectedQuote) && !orderInformationDirty);
      $(this).toggleClass("is-active", active).toggleClass("is-done", number < ({ form: 1, quotes: 2, confirm: 3 }[orderStage]));
      $(this).find("button").prop("disabled", locked || !available).attr("aria-current", active ? "step" : "false");
    });
    $modal.find(".sg-quote-card").attr("aria-disabled", String(locked));
    $modal.find(".salam-gate-modal__close").prop("disabled", ["save", "select", "pay"].indexOf(orderBusy) !== -1);
    $modal.find(".salam-gate-get-rates, [data-sg-order-step]").toggle(!orderReadOnly);
    $box.attr("data-sg-order-financial-busy", sgFinancialBusy() ? "1" : "0");
    $modal.attr("data-sg-financial-busy", sgFinancialBusy() ? "true" : "false");
    if (window.SalamGateOrderParcels) window.SalamGateOrderParcels.syncBusy($box[0]);
    $box.find(".salam-gate-pay, .salam-gate-open-modal").each(function () {
      var $button = $(this), rawNative = $button.attr("data-basalam-parcel-id");
      var native = rawNative && /^[1-9]\d*$/.test(rawNative) && Number.isSafeInteger(Number(rawNative)) ? Number(rawNative) : null;
      var receipt = $button.attr("data-sg-native-receipt") === "1";
      var sourceBlocked = $box.attr("data-basalam-order") === "1" && $box.attr("data-sg-source-blocked") === "1" && !receipt;
      if (native === null) { $button.prop("disabled", locked || sourceBlocked); return; }
      var blocked = $button.attr("data-sg-native-blocked") === "1";
      $button.prop("disabled", sgFinancialBusy() || sourceBlocked || blocked || (!receipt && !!orderTerminalLocks[sgOrderKey(orderId, native)]));
    });
    $modal.find("[data-sg-order-selected-service]").text(orderSelectedQuote
      ? (orderSelectedQuote.service_name || orderSelectedQuote.service_type_label || "سرویس پستی") : "شیوه ارسال را انتخاب کنید");
    var selectedFare = sgDiscountedFare(orderSelectedQuote);
    $modal.find("[data-sg-order-selected-cost]").text(selectedFare !== null ? sgCostMoney(selectedFare) : "—");
    $modal.find(".sg-payment-method-fieldset").toggle(orderMarket === "website" && sgValidQuote(orderSelectedQuote));
    $modal.find("[data-sg-order-basalam-funding]").prop("hidden", orderMarket !== "basalam" || !sgValidQuote(orderSelectedQuote));
    $modal.find("[data-sg-order-confirm-payment-hint]").text(orderMarket === "basalam"
      ? "مشخصات، وزن و هزینه ارسال را بررسی کنید. با تأیید نهایی، هزینه از مبلغی که مشتری برای همین سفارش باسلام پرداخت کرده کسر می‌شود؛ نیازی به پرداخت در درگاه بانکی نیست."
      : "مشخصات و مبلغ را بررسی کنید. با تأیید نهایی، هزینه ارسال از روش انتخاب‌شده کسر می‌شود.");
    $modal.find("[data-sg-order-cost-breakdown]").each(function () { sgRenderCostBreakdown($(this), orderSelectedQuote); });
    sgRenderOrderLoading();
    sgRefreshBalances(false);
  }

  $(document).on("salamgate:order-parcels-rendered", function (event, box) {
    if (box === $box[0]) sgUpdateControls();
  });

  function sgRenderOrderLoading() {
    var loading = !!orderBusy || !orderFormReady;
    var messages = { prepare: "در حال دریافت اطلاعات…", quotes: "در حال دریافت اطلاعات…", balances: "در حال دریافت اطلاعات…",
      save: "در حال ثبت مرسوله…", select: "در حال تأیید روش ارسال…",
      pay: "در حال پرداخت؛ دوباره پرداخت نکنید." };
    $modal.find("[data-sg-order-content]").prop("hidden", loading);
    $modal.find("[data-sg-order-loading]").prop("hidden", !loading).toggleClass("has-error", !!orderLoadError);
    $modal.find("[data-sg-order-loading-message]").text(orderLoadError || messages[orderBusy] || messages.prepare)
      .toggleClass("sg-feedback-toast-only", !!orderLoadError && typeof window.woosalamNotify === "function");
    $modal.find("[data-sg-order-loading-retry]").prop("hidden", !orderLoadError || !!orderBusy || (orderLocked && !orderReadOnly));
    $modal.find(".salam-gate-modal").attr("aria-busy", String(!!orderBusy));
  }

  function sgOrderPrepareFailed(message) {
    orderLoadError = message;
    sgSetBusy("");
    $modal.find("[data-sg-order-loading-message]").toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
    sgOrderNotify(message, "error");
  }

  function sgSetBusy(value) {
    orderBusy = value;
    sgUpdateControls();
  }

  function sgBanner(message) {
    if (typeof window.woosalamNotify === "function") {
      $modal.find(".salam-gate-modal__banner").hide().empty();
      window.woosalamNotify(message, "error");
      return;
    }
    $modal.find(".salam-gate-modal__banner").text(message).show();
  }

  function sgClearNotices() {
    $modal.find(".salam-gate-modal__banner, .salam-gate-modal__warn").hide().empty();
  }

  function sgFillProvinces(selected) {
    var $select = $modal.find(".sg-f-province").empty();
    $select.append($("<option>").val("").text("انتخاب استان"));
    $.each(tree, function (i, province) {
      $select.append($("<option>").val(province.title).text(province.title));
    });
    if (selected) $select.val(selected);
  }

  function sgFillCities(provinceTitle, selectedCityId) {
    var $select = $modal.find(".sg-f-city").empty();
    $select.append($("<option>").val("").text("انتخاب شهر"));
    var cities = [];
    $.each(tree, function (i, province) {
      if (province.title === provinceTitle) cities = province.children || [];
    });
    $.each(cities, function (i, city) {
      $select.append($("<option>").val(String(city.id)).text(city.title));
    });
    if (selectedCityId) $select.val(String(selectedCityId));
  }

  function sgStage(name) {
    orderStage = name;
    $modal.toggleClass("is-confirm-stage", name === "confirm");
    $modal.find(".salam-gate-modal__stage").hide();
    $modal.find('[data-stage="' + name + '"]').show();
    sgUpdateControls();
  }

  function sgFillForm(payload, canonicalSnapshot) {
    var previousConfirmedWeight = canonicalSnapshot ? orderConfirmedWeight : "";
    orderFilling = true;
    if (window.SalamGateValidation) window.SalamGateValidation.reset($modal);
    var receiver = payload.receiver || {};
    var parcel = payload.parcel || {};
    var display = payload._display || {};
    $modal.find("[data-sg-order-source-note]").remove();
    if (typeof display.shipping_blocked_reason === "string" && display.shipping_blocked_reason.trim()) {
      $("<p class='sg-order-modal-source-note' data-sg-order-source-note role='status'>")
        .text(display.shipping_blocked_reason + " این پنجره فقط برای مشاهده اطلاعات قبلی است.")
        .prependTo($modal.find('[data-stage="form"]'));
    }
    orderDisplay = $.extend(true, {}, display);
    var customerShippingMethod = typeof display.customer_shipping_method === "string" ? display.customer_shipping_method.trim() : "";
    $modal.find("[data-sg-order-customer-shipping-method]").text(customerShippingMethod);
    $modal.find("[data-sg-order-shipping-preference]").prop("hidden", !customerShippingMethod);
    orderMarket = display.market === "website" ? "website" : "basalam";
    // The order is the source of its items. Only recipient and parcel details
    // belong to this shipping form, including when an unpaid draft is reopened.
    orderItemsEditable = false;
    orderItemWeightsEditable = false;
    orderReadonlyItems = (payload.items || []).map(function (item) { return $.extend({}, item); });
    var $sender = $modal.find("[data-sg-order-sender]").empty(), sender = display.sender || {};
    $.each([["نام فرستنده", sender.name], ["شهر", sender.city], ["موبایل", sender.mobile],
      ["کد پستی", sender.postal_code], ["نشانی", sender.address], ["مرکز تحویل", sender.center]], function (_, row) {
      if (row[0] === "مرکز تحویل" && !row[1]) return;
      $sender.append($("<div>").toggleClass("sg-order-sender-details__wide", row[0] === "نشانی" || row[0] === "مرکز تحویل")
        .append($("<dt>").text(row[0]), $("<dd>").text(row[1] || "—")));
    });
    $modal.find("[data-sg-order-value-hint]").text(display.goods_value_needs_review === true
      ? "ارزش پیشنهادی فقط مربوط به اقلام این بسته است؛ پیش از ادامه بررسی کنید."
      : "ارزش اعلامی مرسوله برای بیمه و هزینه ارسال است؛ مقدار پیش‌فرض با تخفیف کالای غرفه محاسبه می‌شود و قابل ویرایش است. قیمت کالاهای سفارش تغییر نمی‌کند.");
    $modal.find("[data-sg-order-receiver-hint]").text(orderMarket === "basalam"
      ? "مشخصات گیرندهٔ بستهٔ انتخاب‌شده در باسلام؛ در صورت نیاز آدرس گیرنده را ویرایش کنید."
      : "مشخصات گیرنده سفارش را بررسی کنید.");
    var $category = $modal.find(".sg-f-category").empty();
    $.each(display.categories || [], function (_, category) { $category.append($("<option>").val(category.id).text(category.title)); });
    var categoryId = payload.category_id || parcel.category_id;
    if (categoryId && !$category.find("option").filter(function () { return String(this.value) === String(categoryId); }).length) $category.append($("<option>").val(categoryId).text(display.category_title || "نوع مرسوله ثبت‌شده"));
    if (categoryId) $category.val(String(categoryId));

    $modal.find(".sg-f-name").val(receiver.name || "");
    $modal.find(".sg-f-mobile").val(receiver.mobile || "");
    $modal.find(".sg-f-address").val(receiver.address || "");
    $modal.find(".sg-f-postal").val(receiver.postal_code || "");
    $modal.find(".sg-f-weight").val(parcel.weight || (display.weight_confirmation && display.weight_confirmation.weight_grams) || "");
    orderConfirmedWeight = previousConfirmedWeight === sgCurrentWeight() ? previousConfirmedWeight : "";
    $modal.find(".sg-f-value").val(display.value_toman != null ? display.value_toman : "");
    if (canonicalSnapshot && $modal.find(".sg-f-non-standard").is(":checked")) {
      $modal.find(".sg-f-proposed-price").val(display.value_toman != null ? display.value_toman : "");
    } else if (!canonicalSnapshot) {
      $modal.find(".sg-f-non-standard").prop("checked", false).trigger("change");
    }
    $modal.find(".sg-f-liquid").prop("checked", !!parcel.is_liquid);
    $modal.find(".sg-f-fragile").prop("checked", !!parcel.is_fragile);

    // محتویات مرسوله از عنوان اقلام
    var contents = "";
    var titles = [];
    $.each(payload.items || [], function (i, item) { if (item.title) titles.push(item.title); });
    if (titles.length) contents = titles.join("، ");
    $modal.find(".sg-f-contents").val(contents);

    $modal
      .find(".sg-f-box")
      .removeData("sg-normal-box")
      .val(String((payload.packaging && payload.packaging.box_type_id) || ""))
      .trigger("change");

    var dimensions = (payload.packaging && payload.packaging.dimensions) || {};
    $modal.find(".sg-f-dim-length").val(dimensions.length || "");
    $modal.find(".sg-f-dim-width").val(dimensions.width || "");
    $modal.find(".sg-f-dim-height").val(dimensions.height || "");

    var $items = $modal.find(".sg-f-items").empty();
    $modal.find(".sg-items-locked-note").remove();
    $.each(payload.items || [], function (_, item) { sgAddOrderItem(item, display.product_links || {}); });
    if (!$items.children().length && orderItemsEditable) sgAddOrderItem({ quantity: 1 });
    if (!orderItemsEditable) $("<p class='sg-items-locked-note'>").text(orderMarket === "basalam"
      ? "فقط اقلام بستهٔ انتخاب‌شده در باسلام نمایش داده می‌شوند و در فرم ارسال قابل تغییر نیستند."
      : "اقلام از سفارش فروشگاه خوانده می‌شوند و در فرم ارسال قابل تغییر نیستند.").insertAfter($items.closest("table"));

    var cityPath = display.city_path || null;
    sgFillProvinces(cityPath ? cityPath.province : null);
    sgFillCities(cityPath ? cityPath.province : null, receiver.city_id || null);
    sgReceiverSummary();
    sgReceiverEditor(false);

    // روش پرداخت فقط برای بازار وب‌سایت
    var isWebsite = display.market === "website";
    orderMarket = isWebsite ? "website" : "basalam";
    $modal.find(".sg-payment-method-fieldset").toggle(!!isWebsite);
    orderInformationDirty = false;
    orderFilling = false;
    orderFingerprint = sgInformationFingerprint();
    $modal.find("[data-sg-order-edit-warning]").prop("hidden", true);
    sgUpdateControls();
  }

  var orderItemSequence = 0;
  function sgAddOrderItem(item, links) {
    var itemId = Object.prototype.hasOwnProperty.call(item, "_order_item_id") ? item._order_item_id : (item.external_id || 0);
    var $row = $("<tr>").data("item-id", itemId || 0);
    function itemInput(label, key, value, type, minimum) {
      var id = "sg-order-item-" + (++orderItemSequence) + "-" + key;
      var $input = $("<input>").attr({ id: id, type: type || "text", form: "", "aria-label": label,
        "data-sg-validate": "", "data-sg-label": label }).addClass("sg-order-item-" + key);
      if (type === "number") $input.attr({ min: minimum, step: "1", max: key === "price" ? "1000000000" : "2147483647", "data-sg-integer": "1" });
      if (key !== "price") $input.prop("required", true);
      if (key === "title") $input.addClass("sg-send-item-title").attr("placeholder", "عنوان قلم");
      if (key === "quantity") $input.addClass("sg-send-item-qty");
      if (key === "weight") $input.addClass("sg-item-weight sg-send-item-weight").attr("data-sg-unit", "گرم").data("item-id", $row.data("item-id"));
      if (key === "price") $input.addClass("sg-send-item-price").attr("data-sg-unit", "تومان");
      return $("<td>").attr("data-label", label).append($input.val(value == null ? "" : value).prop("disabled", !orderItemsEditable && !(key === "weight" && orderItemWeightsEditable)));
    }
    var price = item.price_toman != null ? item.price_toman : (Number.isFinite(item.price) ? item.price / 10 : "");
    var $title = itemInput("عنوان", "title", item.title), productUrl = (links || {})[String(item._order_item_id || "")];
    if (!orderItemsEditable) $title = $("<td data-label='عنوان'>").text(item.title || "—");
    if (typeof productUrl === "string") {
      try {
        var url = new URL(productUrl);
        if (["http:", "https:"].indexOf(url.protocol) !== -1 && !url.username && !url.password && url.origin === window.location.origin) {
          var $link = $("<a class='sg-order-product-link'>").attr({ href: url.href, target: "_blank", rel: "noopener noreferrer" }).text(orderItemsEditable ? "مشاهده محصول ↗" : item.title);
          if (!orderItemsEditable) $title.empty();
          $title.append($link);
        }
      } catch (error) { /* Product title remains usable when no safe editor URL exists. */ }
    }
    $row.append($title, itemInput("تعداد", "quantity", item.quantity == null ? 1 : item.quantity, "number", 1),
      itemInput("وزن خالص (گرم)", "weight", item.net_weight, "number", 1), itemInput("قیمت (تومان)", "price", price, "number", 1000),
      $("<td data-label='عملیات'>").append($("<button type='button' data-sg-order-remove-item aria-label='حذف قلم'>").text("×").prop("disabled", !orderItemsEditable).toggle(orderItemsEditable)));
    $modal.find(".sg-f-items").append($row);
    sgItemPlaceholderRequirements();
    return $row;
  }

  function sgEmptyFirstItem($row) {
    return $row[0] === $modal.find(".sg-f-items tr").first()[0] && !$.trim($row.find(".sg-order-item-title").val() || "")
      && sgRawMoney($row.find(".sg-order-item-quantity").val()) === "1" && !sgRawMoney($row.find(".sg-order-item-weight").val()) && !sgRawMoney($row.find(".sg-order-item-price").val());
  }
  function sgOrderItems() {
    return $modal.find(".sg-f-items tr").map(function () {
      var $row = $(this), title = $.trim($row.find(".sg-order-item-title").val() || "");
      if (!title && sgEmptyFirstItem($row)) return null;
      return { title: title, quantity: sgRawMoney($row.find(".sg-order-item-quantity").val()), net_weight: sgRawMoney($row.find(".sg-order-item-weight").val()),
        price_toman: sgRawMoney($row.find(".sg-order-item-price").val()), _order_item_id: Number($row.data("item-id")) || 0 };
    }).get();
  }
  function sgItemPlaceholderRequirements() {
    if (!orderItemsEditable) return;
    var $first = $modal.find(".sg-f-items tr").first();
    var otherComplete = $modal.find(".sg-f-items tr").slice(1).toArray().some(function (row) {
      return $.trim($(row).find(".sg-order-item-title").val() || "") && Number($(row).find(".sg-order-item-quantity").val()) >= 1 && Number($(row).find(".sg-order-item-weight").val()) >= 1;
    });
    $first.find(".sg-order-item-title, .sg-order-item-quantity, .sg-order-item-weight").prop("required", !(sgEmptyFirstItem($first) && otherComplete));
  }
  function sgInformationFingerprint() {
    var information = sgCollectForm();
    delete information.pay_method;
    delete information.weight_confirmed;
    delete information.confirmed_weight_grams;
    return JSON.stringify(information);
  }
  function sgInformationChanged() {
    if (orderFilling || orderBusy || orderLocked || orderStage !== "form") return;
    sgItemPlaceholderRequirements();
    var current = sgInformationFingerprint();
    if (current === orderFingerprint) return;
    orderFingerprint = current;
    if (orderParcelId) {
      orderInformationDirty = true;
      orderSelectedQuote = null;
      orderQuotes = null;
      sgClearPaymentError();
      $modal.find(".sg-quotes-cards, [data-sg-order-review-summary]").empty();
      sgUpdateControls();
    }
  }

  function sgRawMoney(value) {
    return String(value == null ? "" : value).replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); })
      .replace(/[٠-٩]/g, function (d) { return "٠١٢٣٤٥٦٧٨٩".indexOf(d); }).replace(/[,٬]/g, "");
  }

  function sgCanonicalOrderPayload(flat) {
    if (!$.isPlainObject(flat) || !$.isPlainObject(flat.receiver) || !Array.isArray(flat.items) || !flat.items.length) return null;
    if (!flat.items.every(function (item) { return $.isPlainObject(item) && typeof item.title === "string" && item.title.trim(); })) return null;
    return { receiver: flat.receiver, category_id: flat.category_id,
      parcel: { weight: flat.weight, is_liquid: !!flat.is_liquid, is_fragile: !!flat.is_fragile },
      packaging: { box_type_id: flat.box_type_id, dimensions: { length: flat.dimension_length, width: flat.dimension_width, height: flat.dimension_height } },
      items: flat.items, _items_editable: orderItemsEditable,
      _display: $.extend(true, {}, orderDisplay, { value_toman: flat.value_toman,
        city_path: { province: (tree.filter(function (province) { return (province.children || []).some(function (city) { return String(city.id) === String(flat.receiver.city_id); }); })[0] || {}).title || "" } }) };
  }

  function sgCanonicalMatchesReview(flat) {
    var form = sgCollectForm();
    function same(a, b) { return String(a == null ? "" : a).trim() === String(b == null ? "" : b).trim(); }
    if (!["weight", "box_type_id", "category_id"].every(function (key) { return same(form[key], flat[key]); })
      || !same(form.value_toman, flat.value_toman)
      || !["name", "mobile", "address", "postal_code", "city_id"].every(function (key) { return same(form[key], flat.receiver && flat.receiver[key]); })
      || !!form.is_liquid !== !!flat.is_liquid || !!form.is_fragile !== !!flat.is_fragile) return false;
    if (String(form.box_type_id) === "37" && !["length", "width", "height"].every(function (dimension) { return same(form["dimension_" + dimension], flat["dimension_" + dimension]); })) return false;
    var items = orderItemsEditable ? sgOrderItems() : orderReadonlyItems;
    return items.length === flat.items.length && items.every(function (item, index) {
      var remote = flat.items[index];
      return ["title", "quantity", "net_weight"].every(function (key) { return same(item[key], remote[key]); })
        && (item.price_toman == null && item.price == null
          || same(item.price_toman != null ? item.price_toman : Number(item.price) / 10, remote.price_toman));
    });
  }

  function sgCollectForm() {
    var itemWeights = {};
    if (orderItemWeightsEditable && !orderItemsEditable) $modal.find(".sg-item-weight").each(function () {
      itemWeights[String($(this).data("item-id"))] = sgRawMoney($(this).val());
    });

    var nonStandard = $modal.find(".sg-f-non-standard").is(":checked");
    var valueToman = nonStandard
      ? $modal.find(".sg-f-proposed-price").val()
      : $modal.find(".sg-f-value").val();

    var result = {
      weight: sgRawMoney($modal.find(".sg-f-weight").val()),
      weight_confirmed: sgWeightConfirmed() ? "1" : "",
      confirmed_weight_grams: sgWeightConfirmed() ? orderConfirmedWeight : "",
      value_toman: sgRawMoney(valueToman),
      contents: $modal.find(".sg-f-contents").val(),
      is_non_standard: nonStandard ? "1" : "",
      proposed_price: nonStandard ? sgRawMoney($modal.find(".sg-f-proposed-price").val()) : "",
      pay_method: $modal.find(".sg-pay-method").val() || "",
      box_type_id: $modal.find(".sg-f-box").val(),
      dimension_length: sgRawMoney($modal.find(".sg-f-dim-length").val()),
      dimension_width: sgRawMoney($modal.find(".sg-f-dim-width").val()),
      dimension_height: sgRawMoney($modal.find(".sg-f-dim-height").val()),
      is_liquid: $modal.find(".sg-f-liquid").is(":checked") ? "1" : "",
      is_fragile: $modal.find(".sg-f-fragile").is(":checked") ? "1" : "",
      name: $modal.find(".sg-f-name").val(),
      mobile: $modal.find(".sg-f-mobile").val(),
      address: $modal.find(".sg-f-address").val(),
      postal_code: $modal.find(".sg-f-postal").val(),
      city_id: $modal.find(".sg-f-city").val() || "",
      item_net_weights: orderItemsEditable ? {} : itemWeights,
      category_id: $modal.find(".sg-f-category").val() || ""
    };
    if (orderMarket === "basalam") {
      delete result.is_non_standard;
      delete result.proposed_price;
    }
    if (orderItemsEditable) result.items = sgOrderItems();
    return result;
  }

  function sgRenderQuotes(quotesData, requireNewChoice) {
    sgClearNotices();
    sgClearPaymentError();
    orderSelectedQuote = null;
    orderQuotes = null;
    var $list = $modal.find(".sg-quotes-cards").empty();
    sgStage("quotes");
    if (!$.isPlainObject(quotesData) || quotesData.parcel_id !== orderParcelId || !Array.isArray(quotesData.quotes)) {
      sgUpdateControls();
      sgPaymentError({ message: "نرخ معتبر برای این مرسوله دریافت نشد؛ به مرحله قبل برگردید و نرخ‌ها را دوباره بگیرید." });
      return false;
    }
    orderQuotes = quotesData;
    orderInformationDirty = false;
    $modal.find("[data-sg-order-edit-warning]").prop("hidden", true);
    orderFingerprint = sgInformationFingerprint();
    var quotes = quotesData.quotes;
    var selected = quotes.filter(function (quote) { return sgValidQuote(quote) && quote.selected === true; });
    if (!requireNewChoice) {
      // Original Basalam orders use the service's exact selection, not a guessed postal default.
      // Website's existing local Pishtaz default is still reviewed before any select/pay request.
      orderSelectedQuote = orderMarket === "basalam" ? (selected.length === 1 ? selected[0] : null)
        : quotes.find(function (quote) {
          return sgValidQuote(quote) && (Number(quote.service_type_id) === 1
            || (!quote.service_type_id && /پیشتاز/.test(quote.service_name || quote.service_type_label || "")));
        }) || (selected.length === 1 ? selected[0] : null);
    }

    var quoteErrors = (Array.isArray(quotesData.errors) ? quotesData.errors : []).filter(function (message, index, all) {
      return typeof message === "string" && message.trim() !== "" && all.indexOf(message) === index;
    });
    // A replaced selection can accompany fresh valid rates. It is not a failed
    // quote/card: the current rates are reviewed and verified again before pay.
    // Genuine provider failures still need feedback when no usable rate exists.
    if (quoteErrors.length && !quotes.some(sgValidQuote)) {
      sgPaymentError({ message: quoteErrors.join(" ") });
    }

    if (!quotes.length) {
      $list.append($("<p>").addClass("basalam-p").text("نرخی برای این مرسوله یافت نشد."));
    }

    $.each(quotes, function (i, quote) {
      if (!$.isPlainObject(quote)) return;
      var disabled = !sgValidQuote(quote);
      var $card = $("<div>")
        .addClass("sg-quote-card")
        .toggleClass("is-disabled", disabled)
        .toggleClass("is-selected", quote === orderSelectedQuote)
        .attr({ role: "button", tabindex: disabled ? "-1" : "0", "aria-pressed": String(quote === orderSelectedQuote) })
        .data("hash", quote.hash_id);

      if (!disabled) {
        $card.on("click", function () {
          if (orderBusy || orderLocked) return;
          sgClearPaymentError();
          orderSelectedQuote = quote;
          $list.find(".sg-quote-card").removeClass("is-selected").attr("aria-pressed", "false");
          $(this).addClass("is-selected").attr("aria-pressed", "true");
          sgUpdateControls();
        }).on("keydown", function (event) {
          if (event.key === "Enter" || event.key === " ") { event.preventDefault(); $(this).trigger("click"); }
        });
      }

      $card.append(
        $('<div class="sg-quote-card__header">').append(
          $('<span class="sg-quote-card__name">').text(quote.service_name || quote.service_type_label || "سرویس"),
          quote.discount_percent
            ? $('<span class="sg-quote-card__discount">').text(quote.discount_percent + "٪ تخفیف")
            : $()
        )
      );

      var fare = sgDiscountedFare(quote);
      $card.append($('<small class="sg-quote-card__fare-label">').text("کرایه پستی بعد از تخفیف"),
        $('<div class="sg-quote-card__price">').text(fare !== null ? sgCostMoney(fare) : "—"));

      if (quote.error) {
        $card.append($('<div class="sg-quote-card__error">').text(quote.error));
      }

      $list.append($card);
    });

    sgUpdateControls();
    var balanceRead = sgRefreshBalances(true), balanceContext = sgOrderContext();
    if (balanceRead && typeof balanceRead.always === "function" && balanceRead.state() === "pending") {
      sgSetBusy("balances");
      balanceRead.always(function () {
        if (sgCurrentOrderContext(balanceContext) && orderBusy === "balances") sgSetBusy("");
      });
    }
    return true;
  }

  function sgCostMoney(amount) {
    return window.SalamGateAmount.toman(amount);
  }

  function sgDiscountedFare(quote) {
    return quote && Number.isFinite(quote.base_cost_toman) && quote.base_cost_toman >= 0 ? quote.base_cost_toman : null;
  }

  function sgCostRows(value) {
    return (Array.isArray(value) ? value : []).filter(function (row) {
      return $.isPlainObject(row) && typeof row.title === "string" && row.title.trim()
        && Number.isFinite(row.amount_toman);
    });
  }

  function sgRenderCostBreakdown($section, quote) {
    if (!sgValidQuote(quote)) {
      $section.empty().removeData("quote").prop("hidden", true);
      return;
    }
    // Avoid rebuilding open cost details or live regions on unrelated input/consent changes.
    if ($section.data("quote") === quote) return;
    $section.empty().data("quote", quote).prop("hidden", false);
    var fareOnly = $section.closest('[data-stage="quotes"]').length > 0;
    $section.append($("<h4 class='sg-order-section-title'>").text(fareOnly ? "جزئیات کرایه پستی" : "جزئیات هزینه ارسال"),
      $("<p class='sg-order-cost-service'>").text(quote.service_name || quote.service_type_label || "سرویس پستی"));
    var $rows = $("<dl class='sg-order-cost-rows'>").appendTo($section);
    function row($list, title, amount, name) {
      $list.append($("<div class='sg-order-cost-row'>").attr("data-sg-order-cost-row", name || "item")
        .toggleClass("is-discount", amount < 0).append($("<dt>").text(title), $("<dd>").text(sgCostMoney(amount))));
    }
    var fare = quote.fare_cost_toman != null ? quote.fare_cost_toman
      : quote.raw_cost_toman != null ? quote.raw_cost_toman : quote.base_cost_toman;
    if (Number.isFinite(fare) && fare >= 0) row($rows, "کرایه پستی", fare, "base");
    var hasSections = Array.isArray(quote.discount_rows) || Array.isArray(quote.additional_rows);
    $.each(sgCostRows(quote.discount_rows), function (_, item) { row($rows, item.title, item.amount_toman, "discount"); });
    if (fareOnly) {
      var discountedFare = sgDiscountedFare(quote);
      if (discountedFare !== null) row($rows, "کرایه پستی بعد از تخفیف", discountedFare, "discounted-fare");
      $section.append($("<p class='sg-order-helper'>").text("مبلغ نهایی با بیمه، مالیات و سایر هزینه‌ها در مرحله تأیید نمایش داده می‌شود."));
      return;
    }
    var additional = sgCostRows(quote.additional_rows);
    if (additional.length || (Number.isFinite(quote.additional_total_toman) && quote.additional_total_toman !== 0)) {
      var $details = $("<details class='sg-order-cost-details'>").appendTo($section);
      var $summary = $("<summary>").append($("<span>").text("سایر هزینه‌ها"));
      // Group totals are supplied by the service; never reconstruct a payable amount from line items.
      if (Number.isFinite(quote.additional_total_toman)) $summary.append($("<strong>").text(sgCostMoney(quote.additional_total_toman)));
      $details.append($summary);
      var $additionalRows = $("<dl class='sg-order-cost-rows'>").appendTo($details);
      $.each(additional, function (_, item) { row($additionalRows, item.title, item.amount_toman, "additional"); });
    }
    if (!hasSections) $.each(sgCostRows(quote.cost_rows), function (_, item) { row($rows, item.title, item.amount_toman, "legacy"); });
    $section.append($("<div class='sg-order-cost-payable' data-sg-order-cost-payable>").append(
      $("<span>").text("مبلغ نهایی قابل پرداخت"), $("<strong>").text(window.SalamGateAmount.fromRial(quote.total_rial))));
  }

  function sgLoadOrderQuotes(epoch, requireNewChoice) {
    var context = sgOrderContext();
    if (epoch !== context.epoch) return;
    orderSelectedQuote = null;
    sgStage("quotes");
    $modal.find(".sg-quotes-cards").empty().append($("<p>").text("در حال دریافت نرخ‌های ارسال…"));
    sgSetBusy("quotes");
    function quoteFailure(response) {
      if (!sgCurrentOrderContext(context)) return;
      var data = response && response.success === false && $.isPlainObject(response.data) ? response.data : {};
      var terminal = ["pay_unknown", "already_paid", "update_unknown"].indexOf(data.code) !== -1;
      if (terminal) orderLocked = true;
      sgSetBusy("");
      var error = { code: data.code, message: data.message || "دریافت نرخ‌های ارسال انجام نشد؛ دوباره تلاش کنید." };
      if (terminal) sgPaymentError(error, "", true);
      else sgInvalidateQuote(error);
    }
    sgOrderPost("salam_gate_get_quotes").done(function (res) {
      if (!sgCurrentOrderContext(context)) return;
      sgSetBusy("");
      if (!res || res.success !== true || !res.data || res.data.parcel_id !== orderParcelId) {
        quoteFailure(res);
        return;
      }
      sgRenderQuotes(res.data.quotes, requireNewChoice);
    }).fail(function (xhr) {
      quoteFailure(xhr && xhr.responseJSON);
    });
  }

  function sgOpenModal(mode) {
    if (orderBusy || (orderLocked && mode !== "receipt")) return;
    var epoch = ++orderEpoch;
    var context = sgOrderContext();
    if (window.SalamGatePaymentPresentation && window.SalamGatePaymentPresentation.resetChoice) window.SalamGatePaymentPresentation.resetChoice($modal[0]);
    orderReadOnly = mode === "receipt";
    if (orderReadOnly) orderLocked = true;
    orderOpenMode = mode;
    orderLoadError = "";
    orderFormReady = false;
    orderSelectedQuote = null;
    orderQuotes = null;
    orderInformationDirty = false;
    sgRevokeWeightConfirmation();
    sgClearNotices();
    sgClearPaymentError();
    $modal.find("[data-sg-order-customer-shipping-method]").empty();
    $modal.find("[data-sg-order-shipping-preference]").prop("hidden", true);
    $modal.find("[data-sg-order-receipt-note]").remove();
    sgStage("form");
    sgSetBusy("prepare");
    $modal.show();
    $modal.find(".salam-gate-modal__close").trigger("focus");

    sgLoadReferences(context).done(function () {
      if (!sgCurrentOrderContext(context)) return;
    sgOrderPost("salam_gate_prepare_parcel")
      .done(function (res) {
        if (!sgCurrentOrderContext(context)) return;
        sgSetBusy("");
        if (!res || res.success !== true || !$.isPlainObject(res.data) || !$.isPlainObject(res.data.payload)) {
          sgOrderPrepareFailed((res && res.data && res.data.message) || "اطلاعات ارسال دریافت نشد؛ دوباره تلاش کنید.");
          return;
        }
        var display = res.data.payload._display;
        var invalidNative = selectedBasalamParcelId !== null && (!$.isPlainObject(display)
          || display.market !== "basalam" || !Number.isSafeInteger(display.external_id)
          || display.external_id !== selectedBasalamParcelId);
        var invalidMenu = menuOrderHost && (!menuOrderIdentity || menuOrderIdentity.order !== orderId
          || menuOrderIdentity.parcel !== selectedBasalamParcelId || !$.isPlainObject(display)
          || display.market !== menuOrderIdentity.market);
        var invalidList = listOrderHost && (!listOrderIdentity || listOrderIdentity.order !== orderId
          || !$.isPlainObject(display) || display.market !== listOrderIdentity.market);
        if (invalidNative || invalidMenu || invalidList) {
          orderFormReady = false;
          orderLocked = true;
          orderLoadError = "ارتباط این مرسوله با سفارش انتخاب‌شده تأیید نشد؛ از جزئیات سفارش بررسی کنید. ارسال و پرداخت انجام نشد.";
          sgStage("form");
          sgPaymentError({ message: "ارتباط این مرسوله با سفارش انتخاب‌شده تأیید نشد؛ از جزئیات سفارش بررسی کنید. ارسال و پرداخت انجام نشد." }, "", true);
          sgUpdateControls();
          return;
        }
        orderParcelId = Number.isSafeInteger(res.data.parcel_id) && res.data.parcel_id > 0 ? res.data.parcel_id : null;
        if (display && typeof display.shipping_blocked_reason === "string" && display.shipping_blocked_reason.trim()) {
          orderReadOnly = true;
          orderLocked = true;
          orderOpenMode = "receipt";
        }
        sgFillForm(res.data.payload || {});
        orderFormReady = true;
        if (orderReadOnly) {
          sgStage("form");
          sgRenderNativeReceipt(res.data.existing);
        } else if (res.data.existing && res.data.existing.paid) {
          orderLocked = true;
          orderReadOnly = true;
          orderOpenMode = "receipt";
          sgStage("form");
          sgRenderNativeReceipt(res.data.existing);
        } else if (mode === "payment" && orderParcelId && res.data.existing
          && res.data.existing.parcel_id === orderParcelId && res.data.existing.status_id === 7) {
          // Every entry point reviews information/weight before loading rates.
          // Preparing an existing draft does not register or pay it again.
          sgStage("form");
        } else if (mode === "payment") {
          orderLocked = true;
          sgStage("form");
          sgPaymentError({ message: "وضعیت مرسوله قبلی برای ادامه پرداخت تأیید نشد؛ آن را از صفحه سفارش بررسی کنید. ثبت یا پرداخت دوباره انجام نشد." }, "", true);
        }
        sgUpdateControls();
      })
      .fail(function () {
        if (!sgCurrentOrderContext(context)) return;
        sgOrderPrepareFailed("اطلاعات ارسال دریافت نشد؛ اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.");
      });
    }).fail(function () {
      if (!sgCurrentOrderContext(context)) return;
      sgOrderPrepareFailed("اطلاعات بسته‌بندی و شهرها کامل دریافت نشد؛ دوباره تلاش کنید.");
    });
  }

  $modal.on("click", "[data-sg-order-loading-retry]", function () {
    if (!orderBusy && (!orderLocked || orderReadOnly)) sgOpenModal(orderOpenMode);
  });

  function sgCompactSourceStatus(state) {
    if (state && typeof state.cancelled === "boolean") $box.attr("data-sg-source-cancelled", state.cancelled ? "1" : "0");
    else if ($box.find("[data-sg-order-source-status].is-cancelled").length) $box.attr("data-sg-source-cancelled", "1");
    $box.find("[data-sg-order-source-status].is-cancelled [data-sg-order-source-message]")
      .text("این سفارش در باسلام لغو شده است؛ ثبت مرسوله و پرداخت تازه برای آن امکان‌پذیر نیست.");
  }
  sgCompactSourceStatus();
  $(document).on("salamgate:source-state-updated", function (event, box, state) {
    if (box === $box[0]) { sgCompactSourceStatus(state); sgUpdateControls(); }
  });

  function sgRenderNativeReceipt(existing) {
    var receipt = $.isPlainObject(existing) ? existing : {};
    var $receipt = $("<section class='sg-order-native-receipt' data-sg-order-receipt-note>").prependTo($modal.find('[data-stage="form"]'));
    var $header = $("<div class='sg-order-receipt-header'>").appendTo($receipt);
    if (typeof receipt.status_title === "string" && receipt.status_title.trim()) {
      $("<div class='sg-order-receipt-state'>").append(
        $("<span class='sg-order-receipt-icon dashicons dashicons-archive' aria-hidden='true'>"),
        $("<div>").append($("<span class='sg-order-receipt-caption'>").text("وضعیت ارسال"), $("<strong>").text(receipt.status_title))
      ).appendTo($header);
    }
    if (receipt.paid !== true) return;
    $("<span class='sg-order-receipt-paid'>").append($("<span class='dashicons dashicons-yes-alt' aria-hidden='true'>"), "پرداخت شده").appendTo($header);
    var $toolbar = $("<div class='sg-order-receipt-toolbar'>").appendTo($receipt);
    var tracking = typeof receipt.tracking_code === "string" ? receipt.tracking_code.trim() : "";
    if (tracking) {
      var $trackingCode = $("<bdi class='sg-order-receipt-code' dir='ltr'>").text(tracking);
      $("<div class='salam-gate-tracking sg-order-receipt-tracking'>").append(
        $("<span class='sg-order-receipt-caption'>").text("کد رهگیری"),
        $("<div class='sg-order-receipt-tracking-row'>").append(
          $trackingCode,
          $("<button type='button' class='button salam-gate-copy'>").attr({ "data-code": tracking, "aria-label": "کپی کد رهگیری" }).append(
            $("<span class='dashicons dashicons-admin-page' aria-hidden='true'>"), $("<span class='sg-order-copy-label'>").text("کپی")
          )
        )
      ).appendTo($toolbar);
    }
    var $actions = $("<div class='sg-order-receipt-actions'>").appendTo($toolbar);
    if (tracking && receipt.can_print_label === true) {
      var $labels = $("<div class='salam-gate-label-row'>").appendTo($actions);
      var $template = $("<select class='salam-gate-label-template' aria-label='قالب برچسب مرسوله'>").appendTo($labels);
      $.each({ invoice: "فاکتور کامل", invoice_new: "فاکتور کامل (طراحی جدید)", summary: "لیبل خلاصه", label_10x15: "لیبل ۱۰×۱۵" }, function (value, title) {
        $("<option>").val(value).text(title).appendTo($template);
      });
      $("<button type='button' class='button salam-gate-get-label'>").text("چاپ برچسب پستی").appendTo($labels);
    }
    $("<p class='salam-gate-label-status sg-order-helper' role='status'>").hide().appendTo($receipt);
  }

  $box.on("click", ".salam-gate-open-modal", function () {
    var $button = $(this);
    if ($button.prop("disabled") || sgFinancialBusy()) return;
    var rawNative = $button.attr("data-basalam-parcel-id");
    if (rawNative != null) {
      if (!/^[1-9]\d*$/.test(rawNative) || !Number.isSafeInteger(Number(rawNative)) || !sgSelectNativeParcel(orderId, Number(rawNative))) return;
    }
    if (!listOrderHost) orderModalTrigger = this;
    // Basalam always starts with package details/weight, even for a saved draft.
    // The existing prepare/save guards still reuse its exact unpaid parcel.
    var mode = $button.attr("data-sg-native-receipt") === "1" ? "receipt"
      : orderMarket === "website" && listOrderHost && $button.attr("data-sg-native-has-local") === "1" ? "payment" : "edit";
    if (listOrderHost) $(document).trigger("salamgate:list-native-hide");
    sgOpenModal(mode);
  });

  $box.on("click", ".salam-gate-pay", function () {
    if ($(this).prop("disabled")) return;
    orderModalTrigger = this;
    sgOpenModal("payment");
  });

  $modal.on("click", ".sg-order-receiver-edit", function () {
    var open = $(this).attr("aria-expanded") !== "true";
    sgReceiverSummary();
    sgReceiverEditor(open);
    if (open) $modal.find(".sg-f-name").trigger("focus");
  });

  $modal.on("input change", ".sg-order-receiver-fields input, .sg-order-receiver-fields textarea, .sg-f-city", sgReceiverSummary);
  $modal.on("input change", ".sg-f-weight", function () {
    if (orderFilling || orderBusy || orderLocked) return;
    sgRevokeWeightConfirmation();
    sgUpdateControls();
  });
  $modal.on("change", "[data-sg-order-confirm-weight]", function () {
    if (orderFilling || orderBusy || orderLocked) return;
    orderConfirmedWeight = $(this).is(":checked") ? sgCurrentWeight() : "";
    $modal.find("[data-sg-order-weight-error]").text("").prop("hidden", true);
    sgUpdateControls();
  });
  $modal.on("input change", "[data-stage='form'] input, [data-stage='form'] select, [data-stage='form'] textarea", sgInformationChanged);

  function sgReviewOrder() {
    if (orderBusy || orderLocked || orderInformationDirty || !sgValidQuote(orderSelectedQuote)) return;
    var method = orderMarket === "website" ? String($modal.find(".sg-pay-method").val() || "") : "19";
    if (orderMarket === "website" && ["20", "21"].indexOf(method) === -1) return;
    var $review = $modal.find("[data-sg-order-review-summary]").empty();
    function group(title, icon, rows, kind) {
      var $group = $("<section class='sg-order-review-group'>").attr("data-review-group", kind);
      $group.append($("<h5>").append($("<span class='dashicons' aria-hidden='true'>").addClass("dashicons-" + icon), document.createTextNode(title)));
      var $fields = $("<dl class='sg-order-review-fields'>");
      rows.forEach(function (row) {
        $fields.append($("<div>").toggleClass("is-wide", row[2] === true).append($("<dt>").text(row[0]), $("<dd>").text(row[1] || "—")));
      });
      $review.append($group.append($fields));
    }
    var packaging = $modal.find(".sg-f-box option:selected").text();
    if ($modal.find(".sg-f-box").val() === "37") {
      packaging += " · " + [["طول", "length"], ["عرض", "width"], ["ارتفاع", "height"]].map(function (dimension) {
        return dimension[0] + " " + $modal.find(".sg-f-dim-" + dimension[1]).val();
      }).join("، ") + " سانتی‌متر";
    }
    var count = orderItemsEditable ? sgOrderItems().length : $modal.find(".sg-f-items tr").length;
    var contents = [$modal.find(".sg-f-category option:selected").text(), Number(count).toLocaleString("fa-IR") + " قلم"].filter(Boolean).join(" · ");
    var packageRows = [["سرویس ارسال", orderSelectedQuote.service_name || "سرویس پستی"],
      ["روش پرداخت", { 19: "کسر از مبلغ همین سفارش باسلام", 20: "تراز غرفه‌دار", 21: "کیف پول" }[method]],
      ["وزن با بسته‌بندی", Number(sgCurrentWeight()).toLocaleString("fa-IR") + " گرم"],
      ["ارزش اعلامی", Number.isFinite(Number(sgRawMoney($modal.find(".sg-f-value").val())))
        ? sgCostMoney(Number(sgRawMoney($modal.find(".sg-f-value").val()))) : "—"],
      ["بسته‌بندی", packaging], ["محتویات", contents]];
    var flags = [];
    if ($modal.find(".sg-f-liquid").is(":checked")) flags.push("مایعات");
    if ($modal.find(".sg-f-fragile").is(":checked")) flags.push("شکستنی");
    if (flags.length) packageRows.push(["ویژگی‌های بسته", flags.join(" · "), true]);
    group("مرسوله و پرداخت", "archive", packageRows, "parcel");
    group("گیرنده", "download", [["نام", $modal.find(".sg-f-name").val()],
      ["مقصد", [$modal.find(".sg-f-province").val(), $modal.find(".sg-f-city option:selected").text()].filter(Boolean).join(" — ")],
      ["موبایل", $modal.find(".sg-f-mobile").val()], ["کد پستی", $modal.find(".sg-f-postal").val()],
      ["نشانی", $modal.find(".sg-f-address").val(), true]], "receiver");
    var sender = orderDisplay.sender || {};
    group("فرستنده", "upload", [["نام", sender.name], ["شهر", sender.city],
      ["موبایل", sender.mobile], ["کد پستی", sender.postal_code], ["نشانی", sender.address, true]], "sender");
    sgClearPaymentError();
    sgStage("confirm");
    $modal.find("#sg-order-confirm-title").trigger("focus");
  }
  $modal.on("click", "[data-sg-order-review]", sgReviewOrder);
  $modal.on("click", "[data-sg-order-back-quotes]", function () {
    if (orderBusy || orderLocked) return;
    sgClearPaymentError(); sgStage("quotes");
  });
  $modal.on("click", "[data-sg-order-go-step]", function () {
    if (orderBusy || orderLocked || $(this).prop("disabled")) return;
    var number = Number($(this).attr("data-sg-order-go-step"));
    if (number === 1) { sgClearPaymentError(); sgStage("form"); }
    else if (number === 2 && orderQuotes && !orderInformationDirty) { sgClearPaymentError(); sgStage("quotes"); }
    else if (number === 3) sgReviewOrder();
  });
  $modal.on("click", "[data-sg-order-add-item]", function () {
    if (orderBusy || orderLocked || orderStage !== "form" || !orderItemsEditable) return;
    var $row = sgAddOrderItem({ quantity: 1 });
    sgRevokeWeightConfirmation();
    if (window.SalamGateMoney) window.SalamGateMoney.refresh();
    sgInformationChanged(); $row.find(".sg-order-item-title").trigger("focus");
  });
  function sgCloseOrderRemoval() {
    var pending = orderPendingRemoval;
    orderPendingRemoval = null;
    $modal.find("[data-sg-order-remove-modal]").hide();
    if (pending && document.contains(pending.opener)) $(pending.opener).trigger("focus");
  }
  $modal.on("click", "[data-sg-order-remove-item]", function () {
    if (orderBusy || orderLocked || orderStage !== "form" || !orderItemsEditable) return;
    var $row = $(this).closest("tr"), title = $.trim($row.find(".sg-order-item-title").val() || "");
    orderPendingRemoval = { row: $row, opener: this };
    $modal.find("[data-sg-order-remove-message]").text(title ? "قلم «" + title + "» از مرسوله حذف شود؟" : "این قلم از مرسوله حذف شود؟");
    $modal.find("[data-sg-order-remove-hint]").text($row[0] === $modal.find(".sg-f-items tr").first()[0]
      ? "اطلاعات قلم پاک می‌شود و سطر برای ورود قلم جدید باقی می‌ماند." : "این سطر از اقلام مرسوله حذف می‌شود.");
    $modal.find("[data-sg-order-remove-modal]").show().find("[data-sg-order-remove-cancel]").trigger("focus");
  });
  $modal.on("click", "[data-sg-order-remove-cancel]", sgCloseOrderRemoval);
  $modal.on("click", "[data-sg-order-remove-modal]", function (event) { if (event.target === this) sgCloseOrderRemoval(); });
  $modal.on("keydown", "[data-sg-order-remove-modal]", function (event) {
    if (event.key === "Escape") { event.preventDefault(); event.stopImmediatePropagation(); sgCloseOrderRemoval(); return; }
    if (event.key !== "Tab") return;
    var $buttons = $(this).find("button"), first = $buttons[0], last = $buttons[$buttons.length - 1];
    if ((!event.shiftKey && event.target === last) || (event.shiftKey && event.target === first)) {
      event.preventDefault(); event.stopPropagation(); $(event.shiftKey ? last : first).trigger("focus");
    }
  });
  $modal.on("click", "[data-sg-order-remove-confirm]", function () {
    var pending = orderPendingRemoval; sgCloseOrderRemoval();
    if (!pending || orderBusy || orderLocked || orderStage !== "form" || !orderItemsEditable || !document.contains(pending.row[0])) return;
    sgRevokeWeightConfirmation();
    var $next;
    if (pending.row[0] === $modal.find(".sg-f-items tr").first()[0]) {
      pending.row.find("input").val(""); pending.row.find(".sg-order-item-quantity").val(1);
      pending.row.data("item-id", 0).find(".sg-order-product-link").remove();
      $next = pending.row.find(".sg-order-item-title");
      sgItemPlaceholderRequirements(); $next.trigger("input");
      if (window.SalamGateValidation) window.SalamGateValidation.reset(pending.row);
    } else {
      $next = pending.row.prev().find(".sg-order-item-title"); pending.row.remove();
    }
    sgInformationChanged(); if ($next.length) $next.trigger("focus");
  });

  function sgCloseModal() {
    if (["save", "select", "pay"].indexOf(orderBusy) !== -1) return;
    ++orderEpoch;
    sgSetBusy("");
    $modal.hide();
    sgShowSidebarTerminal();
    if (listOrderHost && orderLocked) $(document).trigger("salamgate:order-list-changed", [Number(orderId)]);
    if (orderModalTrigger && document.contains(orderModalTrigger)) $(orderModalTrigger).trigger("focus");
  }

  $modal.on("click", ".salam-gate-modal__close", sgCloseModal);

  $modal.on("click", function (event) {
    if (event.target === this) sgCloseModal();
  });

  $(document).on("keydown.salamGateOrder", function (event) {
    if (event.key === "Escape" && $modal.css("display") !== "none") {
      if (orderPendingRemoval) { event.preventDefault(); event.stopImmediatePropagation(); sgCloseOrderRemoval(); }
      else sgCloseModal();
    }
  });

  $modal.on("change", ".sg-f-province", function () {
    sgFillCities($(this).val(), null);
    sgReceiverSummary();
  });

  $modal.on("change", ".sg-f-box", function () {
    var needsDimensions = $(this).val() === "37";
    $modal.find("[data-sg-order-out-of-size]").prop("checked", needsDimensions);
    if (!needsDimensions && $(this).val()) $(this).data("sg-normal-box", $(this).val());
    $modal.find(".sg-dims-row").toggle(needsDimensions);
  });
  $modal.on("change", "[data-sg-order-out-of-size]", function () {
    var $box = $modal.find(".sg-f-box");
    if ($(this).is(":checked")) {
      if ($box.val() !== "37") $box.data("sg-normal-box", $box.val());
      $box.val("37");
    } else {
      var normal = $box.data("sg-normal-box");
      if (!normal || !$box.find("option[value='" + normal + "']").length) normal = $box.find("option").filter(function () { return this.value && this.value !== "37"; }).first().val() || "";
      $box.val(normal);
    }
    $box.trigger("change");
  });

  // سوییچ سفارش غیراستاندارد → نمایش/مخفی قیمت پیشنهادی
  $modal.on("change", ".sg-f-non-standard", function () {
    var checked = $(this).is(":checked");
    $modal.find(".sg-proposed-price-row").toggle(checked);
    if (checked) {
      $modal.find(".sg-f-value").closest(".basalam-form-group").hide();
      $modal.find(".sg-f-value-label").text("ارزش مرسوله (تومان)");
    } else {
      $modal.find(".sg-f-value").closest(".basalam-form-group").show();
      $modal.find(".sg-f-proposed-price").val("");
    }
  });

  $modal.on("click", ".salam-gate-get-rates", function () {
    if (orderBusy || orderLocked || !orderFormReady) return;
    sgClearNotices();
    sgClearPaymentError();

    var mobile = sgRawMoney($modal.find(".sg-f-mobile").val()).trim();
    var postal = sgRawMoney($modal.find(".sg-f-postal").val()).trim();
    var cityId = String($modal.find(".sg-f-city").val() || "");

    var receiverError = !cityId ? ["استان و شهر گیرنده را انتخاب کنید.", ".sg-f-province"]
      : !/^09\d{9}$/.test(mobile) ? ["شماره موبایل گیرنده معتبر نیست.", ".sg-f-mobile"]
        : !/^\d{10}$/.test(postal) ? ["کد پستی باید دقیقاً ۱۰ رقم باشد.", ".sg-f-postal"]
          : $.trim($modal.find(".sg-f-name").val() || "").length < 3 ? ["نام گیرنده باید حداقل ۳ کاراکتر باشد.", ".sg-f-name"]
            : $.trim($modal.find(".sg-f-address").val() || "").length < 10 ? ["آدرس پستی باید حداقل ۱۰ کاراکتر باشد.", ".sg-f-address"] : null;
    if (receiverError) {
      sgReceiverEditor(true);
      $modal.find(receiverError[1]).trigger("focus");
      return sgBanner(receiverError[0]);
    }
    sgItemPlaceholderRequirements();
    if (window.SalamGateValidation && !window.SalamGateValidation.validate($modal)) return;
    if (orderItemsEditable && !sgOrderItems().length) return sgBanner("حداقل یک قلم مرسوله را وارد کنید.");
    if (!sgRequireWeightConfirmation()) return;

    var epoch = orderEpoch;
    var context = sgOrderContext();
    var dispatchParcelId = orderParcelId;
    orderSelectedQuote = null;
    sgSetBusy("save");

    function saveFailure(response) {
      if (!sgCurrentOrderContext(context)) return;
      var data = response && response.success === false && $.isPlainObject(response.data) ? response.data : null;
      var terminal = data && ["pay_unknown", "already_paid", "update_unknown", "create_recovered"].indexOf(data.code) !== -1;
      var definite = data && !terminal && data.code !== "create_unknown" && typeof data.message === "string"
        && (!data.kind || data.kind === "validation" || data.kind === "auth" || data.kind === "forbidden");
      orderLocked = !definite;
      sgSetBusy("");
      if (listOrderHost && orderLocked) $(document).trigger("salamgate:order-list-changed", [Number(orderId)]);
      if (definite) sgOrderNotify(data.message);
      else {
        sgStage("quotes");
        $modal.find(".sg-quotes-cards").empty();
        sgPaymentError({ code: data && data.code, message: terminal && data.message ? data.message : "نتیجه ثبت مرسوله مشخص نیست؛ از فهرست مرسولات بررسی کنید و دوباره ثبت نکنید." }, "", true);
      }
    }

    sgOrderPost("salam_gate_upsert_parcel", sgCollectForm())
      .done(function (res) {
        if (!sgCurrentOrderContext(context)) return;
        if (!res || res.success !== true || !$.isPlainObject(res.data)
          || !Number.isSafeInteger(res.data.parcel_id) || res.data.parcel_id <= 0
          || (dispatchParcelId && res.data.parcel_id !== dispatchParcelId
            && !(res.data.replaces_parcel_id === dispatchParcelId && res.data.requires_quote_selection === true))) {
          return saveFailure(res);
        }
        var canonical = sgCanonicalOrderPayload(res.data.parcel_payload);
        if (Object.prototype.hasOwnProperty.call(res.data, "parcel_payload") && !canonical) return saveFailure(res);
        orderParcelId = res.data.parcel_id;
        if (canonical) sgFillForm(canonical, true);
        // The remote update DTO cannot edit item weights once a parcel exists.
        if (!orderItemsEditable) orderItemWeightsEditable = false;
        sgSetBusy("");
        sgRenderQuotes(res.data.quotes);
        if (listOrderHost) $(document).trigger("salamgate:order-list-changed", [Number(orderId)]);
        if (Array.isArray(res.data.warnings) && res.data.warnings.length) {
          sgOrderNotify(res.data.warnings.join(" — "), "warning");
        }
      })
      .fail(function (xhr) {
        saveFailure(xhr && xhr.responseJSON);
      });
  });

  $modal.on("click", ".salam-gate-back-form", function () {
    if (orderBusy || orderLocked) return;
    sgClearPaymentError();
    sgStage("form");
    sgUpdateControls();
  });

  $modal.on("change", ".sg-pay-method", function () {
    if (orderBusy || orderLocked) return;
    sgClearPaymentError();
    sgUpdateControls();
  });

  $modal.on("click", ".salam-gate-reload-quotes", function () {
    if (orderBusy || orderLocked || !orderParcelId) return;
    sgClearPaymentError();
    sgLoadOrderQuotes(orderEpoch, true);
  });

  function sgInvalidateQuote(data) {
    sgStage("quotes");
    orderSelectedQuote = null;
    if (data.quotes) sgRenderQuotes(data.quotes, true);
    else {
      orderQuotes = null;
      $modal.find(".sg-quotes-cards").empty().append($("<p>").text("برای ادامه، نرخ‌های ارسال را دوباره دریافت کنید."));
    }
    sgUpdateControls();
    sgPaymentError(data);
    $modal.find("[data-sg-order-modal-payment-feedback]").append(
      $("<button type='button' class='button salam-gate-reload-quotes'>").text("دریافت دوباره نرخ‌ها")
    ).removeClass("sg-feedback-toast-only").toggleClass("sg-feedback-actions", typeof window.woosalamNotify === "function");
  }

  $modal.on("click", ".salam-gate-select-quote", function () {
    if (orderBusy || orderLocked || orderStage !== "confirm" || orderInformationDirty || !orderParcelId || !sgValidQuote(orderSelectedQuote)) return;
    if (!sgRequireWeightConfirmation()) return;
    var method = orderMarket === "website" ? String($modal.find(".sg-pay-method").val() || "") : "19";
    if (orderMarket === "website" && ["20", "21"].indexOf(method) === -1) {
      return sgPaymentError({ message: "روش پرداخت را انتخاب کنید." });
    }
    // Consent is pinned before any async call. Never silently accept a changed rate.
    var consent = { parcelId: orderParcelId, hash: orderSelectedQuote.hash_id, total: orderSelectedQuote.total_rial, method: method, weight: orderConfirmedWeight };
    var epoch = orderEpoch;
    var context = sgOrderContext();
    sgClearPaymentError();
    sgSetBusy("select");

    function selectionFailure(response) {
      if (!sgCurrentOrderContext(context)) return;
      var data = response && response.success === false && $.isPlainObject(response.data) ? response.data : {};
      var terminal = ["pay_unknown", "already_paid", "update_unknown"].indexOf(data.code) !== -1;
      if (terminal) orderLocked = true;
      sgSetBusy("");
      sgPaymentError({ code: data.code, message: data.message || "تأیید روش ارسال انجام نشد؛ پرداختی درخواست نشد. دوباره تلاش کنید." }, "", terminal);
    }

    function paymentFailure(response) {
      if (!sgCurrentOrderContext(context)) return;
      var trusted = $.isPlainObject(response) && response.success === false && $.isPlainObject(response.data);
      var data = trusted ? response.data : {};
      var terminal = ["pay_unknown", "already_paid", "update_unknown"].indexOf(data.code) !== -1;
      var definitive = trusted && !terminal && data.kind === "validation"
        && typeof data.message === "string" && Array.isArray(data.errors)
        && data.errors.every(function (error) {
          return $.isPlainObject(error) && typeof error.location === "string" && typeof error.message === "string";
        });
      orderLocked = !definitive;
      sgSetBusy("");
      if (definitive && data.code === "weight_confirmation_required") {
        var currentWeight = data.current_weight_grams;
        var hasCurrentWeight = Number.isSafeInteger(currentWeight) && currentWeight >= 50 && currentWeight <= 30000;
        if (hasCurrentWeight) {
          $modal.find(".sg-f-weight").val(currentWeight);
          orderDisplay.weight_confirmation = $.extend({}, orderDisplay.weight_confirmation || {}, {
            weight_grams: currentWeight, source: "saved",
            message: "وزن فعلی مرسوله از سرور دریافت شد؛ آن را با وزن واقعی بسته بررسی و دوباره تأیید کنید."
          });
          if (window.SalamGateValidation) window.SalamGateValidation.reset($modal.find(".sg-f-weight"));
        }
        sgRevokeWeightConfirmation();
        orderSelectedQuote = null;
        orderQuotes = null;
        orderInformationDirty = true;
        $modal.find(".sg-quotes-cards, [data-sg-order-review-summary]").empty();
        sgClearPaymentError();
        sgStage("form");
        var weightMessage = hasCurrentWeight
          ? "وزن مرسوله تغییر کرده است و پرداخت انجام نشد. وزن تازه را بررسی و تأیید کنید، سپس نرخ ارسال را دوباره بگیرید."
          : "وزن فعلی مرسوله تأیید نشد و پرداخت انجام نشد. وزن واقعی بسته را بررسی و وارد کنید؛ برای دریافت اطلاعات تازه می‌توانید پنجره را دوباره باز کنید.";
        $modal.find("[data-sg-order-edit-warning]").prop("hidden", false).text(weightMessage)
          .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
        sgOrderNotify(weightMessage, "warning");
        $modal.find(".sg-f-weight").trigger("focus");
        return;
      }
      if (definitive && (data.code === "quote_changed" || data.code === "quote_check_failed" || data.code === "quote_selection_required")) {
        sgInvalidateQuote(data);
      } else {
        sgPaymentError(definitive || (terminal && typeof data.message === "string" && data.message.trim()) ? data : {
          message: "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید."
        }, definitive ? consent.method : "", !definitive);
      }
      if (definitive) sgRefreshBalances(true);
    }

    sgOrderPost("salam_gate_select_quote", { hash: consent.hash })
      .done(function (res) {
        if (!sgCurrentOrderContext(context)) return;
        if (!res || res.success !== true || !$.isPlainObject(res.data)) return selectionFailure(res);
        var rates = res.data.quotes;
        var validParcel = res.data.parcel_id === consent.parcelId && $.isPlainObject(rates)
          && rates.parcel_id === consent.parcelId && Array.isArray(rates.quotes);
        var selected = validParcel ? rates.quotes.filter(function (q) { return $.isPlainObject(q) && q.selected === true; }) : [];
        var matching = validParcel ? rates.quotes.filter(function (q) { return q && q.hash_id === consent.hash; }) : [];
        if (!validParcel || selected.length !== 1 || matching.length !== 1 || !sgValidQuote(selected[0])
          || selected[0].hash_id !== consent.hash || selected[0].total_rial !== consent.total) {
          sgSetBusy("");
          sgInvalidateQuote({ message: "نرخ یا روش ارسال تغییر کرده است؛ مبلغ را دوباره بررسی و تأیید کنید. پرداختی درخواست نشد.", quotes: validParcel ? rates : null });
          return;
        }
        var canonical = sgCanonicalOrderPayload(res.data.parcel_payload);
        if (Object.prototype.hasOwnProperty.call(res.data, "parcel_payload") && !canonical) {
          sgSetBusy("");
          sgInvalidateQuote({ message: "اطلاعات تأییدشده مرسوله کامل نیست؛ اطلاعات و روش ارسال را دوباره بررسی کنید. پرداختی درخواست نشد." });
          return;
        }
        if (canonical && !sgCanonicalMatchesReview(res.data.parcel_payload)) {
          sgFillForm(canonical, true);
          orderSelectedQuote = null;
          orderQuotes = null;
          orderInformationDirty = true;
          sgSetBusy(""); sgStage("form");
          var changedMessage = "اطلاعات مرسوله روی سرور تغییر کرده است؛ اطلاعات تازه را بررسی و روش ارسال و پرداخت را دوباره انتخاب کنید.";
          $modal.find("[data-sg-order-edit-warning]").prop("hidden", false).text(changedMessage)
            .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
          sgOrderNotify(changedMessage, "warning");
          return;
        }
        if (!sgWeightConfirmed() || sgCurrentWeight() !== consent.weight) {
          sgSetBusy(""); sgStage("confirm"); sgRequireWeightConfirmation();
          return;
        }
        sgSetBusy("pay");
        sgOrderPost("salam_gate_pay_parcel", {
          method: consent.method, expected_hash: consent.hash, expected_total_rial: consent.total,
          weight_confirmed: "1", confirmed_weight_grams: consent.weight
        }).done(function (payment) {
          if (!sgCurrentOrderContext(context)) return;
          var data = $.isPlainObject(payment) && payment.success === true && $.isPlainObject(payment.data) ? payment.data : null;
          var verified = data && data.parcel_id === consent.parcelId && typeof data.confirmed === "boolean"
            && (data.confirmed ? [8, 9, 10, 11, 12].indexOf(data.status_id) !== -1 : data.status_id === 0);
          if (!verified) return paymentFailure(payment);
          orderLocked = true;
          sgSetBusy("");
          if (data.confirmed) {
            sgCloseModal();
            sgOrderNotify("پرداخت هزینه ارسال با موفقیت ثبت شد.", "success");
          } else {
            sgPaymentError({ message: "پرداخت ثبت شده است و تأیید نهایی ارسال در حال بررسی است؛ دوباره پرداخت نکنید." }, "", true, "warning");
          }
          sgRefreshMetabox();
        }).fail(function (xhr) { paymentFailure(xhr && xhr.responseJSON); });
      })
      .fail(function (xhr) { selectionFailure(xhr && xhr.responseJSON); });
  });

  // ---------- پارت ۳: پرداخت، لیبل، پیگیری ----------

  var metaboxReadSequence = 0;
  function sgReleaseMetaboxLoading(sequence) {
    // This spinner belongs to the root read, not to the modal's selection epoch.
    // A closed/switched modal must not strand it or clear a newer root read.
    if (sequence != null && sequence !== metaboxReadSequence) return;
    $box.removeClass("sg-order-box-loading").attr("aria-busy", "false");
    $box.find("[data-sg-order-box-loader]").remove();
  }
  function sgRefreshMetabox() {
    var context = sgOrderContext();
    var readSequence = ++metaboxReadSequence;
    $box.addClass("sg-order-box-loading").attr("aria-busy", "true");
    $box.find("[data-sg-order-box-loader], [data-sg-order-box-read-error], [data-sg-order-box-read-retry]").remove();
    $("<p class='sg-order-box-loader' data-sg-order-box-loader role='status'>").text("در حال دریافت اطلاعات…").appendTo($box);
    function readFailed() {
      if (readSequence !== metaboxReadSequence || !sgCurrentOrderContext(context)) return;
      $("<div class='sg-order-helper' data-sg-order-box-read-error role='status'>").append(
        $("<p>").text("وضعیت تازه دریافت نشد؛ اطلاعات قبلی نمایش داده می‌شود."),
        $("<button type='button' class='button' data-sg-order-box-read-retry>").text("دریافت دوباره وضعیت")
      ).toggleClass("sg-feedback-actions", typeof window.woosalamNotify === "function").appendTo($box);
      sgOrderNotify("وضعیت تازه دریافت نشد؛ اطلاعات قبلی نمایش داده می‌شود.", "error");
    }
    return sgOrderPost("salam_gate_refresh_metabox").done(function (res) {
      if (readSequence !== metaboxReadSequence || !sgCurrentOrderContext(context)) return;
      if (res && res.success && res.data && typeof res.data.html === "string" && res.data.html) {
        var $wrapper = $("<div>").html(res.data.html), $root = $wrapper.find(".salam-gate-order-box").first();
        if ($root.length) {
          if (String($root.attr("data-order-id") || "") !== context.order) { readFailed(); return; }
          $box.attr("data-basalam-order", $root.attr("data-basalam-order") || "0").html($root.html());
        } else $box.html(res.data.html);
        if (menuOrderHost) $box.find('.salam-gate-summary').remove();
        $(document).trigger("salamgate:order-box-refreshed", [$box[0]]);
        if (listOrderHost) $(document).trigger("salamgate:order-list-row-updated", [res.data]);
        sgUpdateControls();
        sgShowSidebarTerminal();
      } else readFailed();
    }).fail(readFailed).always(function () {
      sgReleaseMetaboxLoading(readSequence);
    });
  }
  $box.on("click", "[data-sg-order-box-read-retry]", function () { if (!orderBusy) sgRefreshMetabox(); });

  // Never contact SalamGate merely by opening a Woo order, even for legacy
  // parcels without a saved status. Let the merchant request reconciliation.
  if (!menuOrderHost && $box.attr("data-basalam-order") !== "1" && $box.find("[data-sg-order-status-missing]").length) {
    $("<button type='button' class='button' data-sg-order-box-read-retry>")
      .text("دریافت وضعیت مرسوله").appendTo($box);
  }

  $box.add($modal).on("click", ".salam-gate-copy", function () {
    var $button = $(this), code = String($button.attr("data-code") || "");
    if ($button.prop("disabled")) return;
    if (!code) return;
    var active = document.activeElement;
    $button.prop("disabled", true);
    function finish(copied) {
      $button.prop("disabled", false).toggleClass("is-copied", copied);
      if ($button.find(".sg-order-copy-label").length) $button.find(".sg-order-copy-label").text(copied ? "کپی شد" : "کپی");
      else if (copied) $button.text("کپی شد ✓");
      sgOrderNotify(copied ? "کد رهگیری کپی شد." : "کد رهگیری کپی نشد؛ کد را انتخاب و دستی کپی کنید.", copied ? "success" : "error");
    }
    function fallback() {
      var copied = false;
      var $input = $("<textarea readonly>").val(code).css({ position: "fixed", top: "0", left: "-9999px" }).appendTo(document.body);
      try { $input[0].focus({ preventScroll: true }); $input[0].select(); copied = document.execCommand("copy") === true; } catch (_) { /* Report the actual result. */ }
      $input.remove();
      finish(copied);
      if (active && document.contains(active)) active.focus({ preventScroll: true });
    }
    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") navigator.clipboard.writeText(code).then(function () { finish(true); }, fallback);
      else fallback();
    } catch (error) { fallback(); }
  });


  var sgLabelPollTimer = null;
  var sgLabelJobs = Object.create(null);

  function sgLabelContext(template) {
    return $.extend(sgOrderContext(), { labelTemplate: template, labelNonce: String(basalam_ajax.nonce || "") });
  }
  function sgCurrentLabelContext(context, $scope) {
    return sgCurrentOrderContext(context) && context.labelNonce === String(basalam_ajax.nonce || "")
      && !!$scope && String($scope.find(".salam-gate-label-template").val() || "") === context.labelTemplate;
  }
  function sgLabelJob(context) {
    var orderKey = sgOrderKey(context.order, context.native);
    var key = JSON.stringify([orderKey, context.labelTemplate, context.labelNonce]);
    return sgLabelJobs[key] || (sgLabelJobs[key] = { orderKey: orderKey, labelTemplate: context.labelTemplate,
      labelNonce: context.labelNonce, pending: false, unresolved: false,
      requestId: 0, scope: null, version: 0, fileUrl: "", fileExpires: 0 });
  }
  function sgLabelButtons(job) {
    if (!job.scope || job.labelNonce !== String(basalam_ajax.nonce || "")
      || String(job.scope.find(".salam-gate-label-template").val() || "") !== job.labelTemplate) return;
    job.scope.find(".salam-gate-get-label").prop("disabled", job.pending)
      .text(job.pending ? "در حال آماده‌سازی…" : job.unresolved ? "بررسی وضعیت برچسب" : "چاپ برچسب پستی");
  }
  function sgPauseLabelJob(context) {
    if (!sgLabelJobs) return;
    Object.keys(sgLabelJobs).forEach(function (key) {
      var job = sgLabelJobs[key];
      if (job.orderKey !== sgOrderKey(context.order, context.native)) return;
      job.pending = false;
      if (!job.requestId) job.unresolved = false;
      sgLabelButtons(job);
    });
  }

  function sgShowLabelFile(url, $scope) {
    $scope = $scope || $box;
    var fileUrl;
    try {
      fileUrl = new URL(url);
      if (["https:", "http:"].indexOf(fileUrl.protocol) === -1 || fileUrl.username || fileUrl.password) throw new Error("Invalid label URL");
    } catch (error) {
      $scope.find(".salam-gate-label-status").hide().empty();
      sgOrderNotify("آدرس فایل برچسب معتبر نیست؛ دوباره وضعیت را بررسی کنید.");
      return "";
    }
    $scope.find(".salam-gate-label-status").hide().empty();
    try {
      // A same-tab navigation is not blocked as an asynchronous popup. Open
      // only the URL returned for this exact parcel and completed label job.
      if (!window.open(fileUrl.href, "_self")) throw new Error("Label navigation was blocked");
    } catch (error) {
      // Preserve access if a browser extension prevents the navigation.
      $scope.find(".salam-gate-label-status").prop("hidden", false).empty().append(
        $("<a class='sg-order-label-file'>").attr({ href: fileUrl.href, target: "_self" }).text("مشاهده برچسب پستی")
      ).show();
      sgOrderNotify("بازکردن خودکار برچسب انجام نشد؛ از «مشاهده برچسب پستی» استفاده کنید.", "warning");
    }
    return fileUrl.href;
  }
  $(window).on("pagehide", function () {
    sgPauseLabelJob(sgOrderContext());
    // A cached document can return after the account or receipt changed.
    // Retain queued job recovery, but reverify completed files after returning.
    Object.keys(sgLabelJobs).forEach(function (key) { sgLabelJobs[key].fileUrl = ""; sgLabelJobs[key].fileExpires = 0; });
    orderSuspended = { busy: orderBusy, financial: sgFinancialBusy() };
    if (orderSuspended.financial) {
      // Do not abort or replay a write: the server can finish it while this
      // document is cached. Remember the uncertainty for this native parcel.
      orderLocked = true;
      orderSuspendedReviews[sgOrderKey(orderId, selectedBasalamParcelId)] = true;
      orderTerminalMessage = orderTerminalMessage || (orderBusy === "pay"
        ? "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید."
        : "نتیجه ثبت یا انتخاب روش ارسال قبلی هنوز مشخص نیست؛ آن را تکرار نکنید. وضعیت مرسوله را بررسی کنید.");
      sgRememberTerminal();
    }
    ++orderEpoch; clearTimeout(sgLabelPollTimer);
    ++metaboxReadSequence; sgReleaseMetaboxLoading();
  });
  $(window).on("pageshow", function (event) {
    if (!orderSuspended || !((event.originalEvent && event.originalEvent.persisted) || event.persisted === true)) return;
    var suspended = orderSuspended;
    orderSuspended = null;
    orderBusy = "";
    $modal.find(".salam-gate-get-label").prop("disabled", false).text("دریافت برچسب پستی");
    $modal.find(".salam-gate-track-list").empty().hide();
    $modal.find(".salam-gate-label-status").each(function () {
      if (!$(this).find(".sg-order-label-file").length) $(this).empty().hide();
    });
    if (suspended.financial) {
      orderSelectedQuote = null; orderQuotes = null;
      sgRevokeWeightConfirmation();
      orderLoadError = "";
      sgStage("quotes");
      sgPaymentError({ message: orderTerminalMessage }, "", true, "warning");
      sgShowSidebarTerminal();
    } else if (suspended.busy === "prepare") {
      orderFormReady = false;
      orderLoadError = "برای دریافت اطلاعات تازهٔ مرسوله، «تلاش دوباره» را بزنید. هیچ ثبت یا پرداختی تکرار نشده است.";
      sgRevokeWeightConfirmation();
    } else if (suspended.busy) {
      sgRevokeWeightConfirmation();
      sgInvalidateQuote({ message: "اطلاعات قبلی ممکن است تازه نباشد؛ برای ادامه، نرخ‌های ارسال را دوباره دریافت و بررسی کنید. پرداختی ارسال نشده است." });
    }
    sgUpdateControls();
  });

  function sgLabelPoll(requestId, retryAfter, context, $scope) {
    context = context || sgLabelContext("");
    $scope = $scope || $box;
    clearTimeout(sgLabelPollTimer);

    sgLabelPollTimer = setTimeout(function () {
      if (!sgCurrentLabelContext(context, $scope)) {
        sgRememberDetachedLabel(null, sgLabelJob(context));
        return;
      }
      sgOrderPost("salam_gate_label_status", { request_id: requestId }).done(function (res) {
        if (!sgCurrentLabelContext(context, $scope)) {
          sgRememberDetachedLabel(res && res.success === true ? res.data : null, sgLabelJob(context));
          return;
        }
        if (!res || !res.success) {
          sgLabelReadFailed(context, $scope, (res && res.data && res.data.message) || "وضعیت برچسب دریافت نشد؛ وضعیت همین درخواست را دوباره بررسی کنید.");
          return;
        }
        sgAcceptLabelResult(res.data || {}, context, $scope);
      }).fail(function () {
        if (!sgCurrentLabelContext(context, $scope)) { sgRememberDetachedLabel(null, sgLabelJob(context)); return; }
        sgLabelReadFailed(context, $scope, "ارتباط قطع شد؛ برای دریافت فایل، «بررسی وضعیت برچسب» را بزنید.");
      });
    }, Math.min(60, Math.max(2, Number(retryAfter) || 5)) * 1000);
  }

  function sgLabelReadFailed(context, $scope, message) {
    var job = sgLabelJob(context);
    job.pending = false;
    job.scope = $scope;
    sgLabelButtons(job);
    $scope.find(".salam-gate-label-status").hide().empty();
    sgOrderNotify(message);
  }
  function sgAcceptLabelResult(result, context, $scope) {
    var job = sgLabelJob(context);
    job.scope = $scope;
    if (!Number.isSafeInteger(result.request_id) || result.request_id <= 0
      || (job.requestId > 0 && job.requestId !== result.request_id)) {
      job.unresolved = job.requestId > 0;
      sgLabelReadFailed(context, $scope, "پاسخ برچسب متعلق به این درخواست نیست؛ وضعیت همین درخواست را بررسی کنید.");
      return;
    }
    job.requestId = result.request_id;
    if (result.status === "completed" && typeof result.url === "string" && result.url) {
      job.pending = false; job.unresolved = false;
      sgLabelButtons(job);
      var fileExpires = Date.parse(result.expire_at || "");
      if (Number.isFinite(fileExpires) && fileExpires <= Date.now()) {
        sgOrderNotify("مهلت دریافت فایل قبلی تمام شده است؛ دوباره برچسب را درخواست کنید.", "warning");
        return;
      }
      job.fileUrl = sgShowLabelFile(result.url, $scope);
      // Limit reuse when the provider does not publish its expiration time.
      job.fileExpires = Number.isFinite(fileExpires) ? fileExpires : Date.now() + 5 * 60 * 1000;
    } else if (result.status === "failed" || result.status === "expired") {
      job.pending = false; job.unresolved = false;
      sgLabelButtons(job);
      $scope.find(".salam-gate-label-status").hide().empty();
      sgOrderNotify(result.message || "ساخت برچسب انجام نشد؛ می‌توانید دوباره درخواست بدهید.", result.status === "expired" ? "warning" : "error");
    } else if (result.status === "requested" && job.requestId > 0) {
      job.pending = true; job.unresolved = true;
      sgLabelButtons(job);
      $scope.find(".salam-gate-label-status").prop("hidden", false).text("در حال آماده‌سازی برچسب پستی…").show();
      sgLabelPoll(job.requestId, result.retry_after || 5, context, $scope);
    } else {
      job.unresolved = job.requestId > 0;
      sgLabelReadFailed(context, $scope, "تأیید آماده‌شدن برچسب دریافت نشد؛ وضعیت همین درخواست را دوباره بررسی کنید.");
    }
  }
  function sgRememberDetachedLabel(result, job) {
    // A known job still belongs to the original child when the user switches
    // cards. Retain its ID, but never paint its file into the new selection.
    job.pending = false;
    var resultId = result && Number.isSafeInteger(result.request_id) && result.request_id > 0 ? result.request_id : 0;
    if (!job.requestId) job.requestId = resultId;
    job.unresolved = job.requestId > 0 && (!result || resultId !== job.requestId
      || (result.status !== "failed" && result.status !== "expired"));
    sgLabelButtons(job);
  }

  $box.add($modal).on("change", ".salam-gate-label-template", function () {
    var $scope = $(this).closest("[data-sg-order-label-scope]");
    if (!$scope.length) $scope = $(this).closest("#salam-gate-parcel-modal").length ? $modal : $box;
    var context = sgLabelContext(String($(this).val() || ""));
    Object.keys(sgLabelJobs).forEach(function (key) {
      var previous = sgLabelJobs[key];
      // Preserve a queued job for its original template without leaving the
      // new template's print button disabled. Its late callback cannot open it.
      if (previous.scope && previous.scope[0] === $scope[0] && previous.labelTemplate !== context.labelTemplate
        && previous.requestId > 0) previous.pending = false;
    });
    var job = sgLabelJob(context);
    job.scope = $scope;
    sgLabelButtons(job);
    $scope.find(".salam-gate-label-status").hide().empty();
  });

  $box.add($modal).on("click", ".salam-gate-get-label", function () {
    if ($(this).prop("disabled") || sgFinancialBusy()) return;
    var nativeId = $(this).attr("data-basalam-parcel-id");
    if (nativeId != null && (!/^[1-9]\d*$/.test(nativeId) || !Number.isSafeInteger(Number(nativeId))
      || !sgSelectNativeParcel(orderId, Number(nativeId)))) return;
    var $scope = $(this).closest("[data-sg-order-label-scope]");
    if (!$scope.length) $scope = $(this).closest("#salam-gate-parcel-modal").length ? $modal : $box;
    var template = String($scope.find(".salam-gate-label-template").val() || "");
    var context = sgLabelContext(template);
    var job = sgLabelJob(context);
    if (job.pending) return;
    if (job.fileUrl && job.fileExpires > Date.now()) {
      sgShowLabelFile(job.fileUrl, $scope);
      return;
    }
    job.fileUrl = ""; job.fileExpires = 0;
    job.scope = $scope; job.pending = true;
    sgLabelButtons(job);
    if (job.unresolved) {
      sgLabelPoll(job.requestId, 2, context, $scope);
      return;
    }
    job.requestId = 0;
    job.unresolved = true;
    var labelVersion = ++job.version;

    sgOrderPost("salam_gate_get_label", { template: template })
      .done(function (res) {
        if (job.version !== labelVersion) return;
        if (!sgCurrentLabelContext(context, $scope)) {
          sgRememberDetachedLabel(res && res.success === true ? res.data : null, job);
          return;
        }
        if (!res || !res.success) {
          // Label generation only creates a PDF; unlike shipping/payment it can
          // safely be requested again. Never attach an unrelated GET-latest
          // file to this single parcel when its job ID was not received.
          job.unresolved = false;
          sgLabelReadFailed(context, $scope, (res && res.data && res.data.message) || "تأیید ساخت برچسب دریافت نشد؛ وضعیت درخواست را بررسی کنید.");
          return;
        }
        sgAcceptLabelResult(res.data || {}, context, $scope);
      })
      .fail(function () {
        if (job.version !== labelVersion) return;
        job.unresolved = false;
        if (!sgCurrentLabelContext(context, $scope)) { sgRememberDetachedLabel(null, job); return; }
        sgLabelReadFailed(context, $scope, "فایل برچسب دریافت نشد؛ دوباره «چاپ برچسب پستی» را بزنید. ثبت مرسوله یا پرداخت دوباره انجام نمی‌شود.");
      });
  });
});
