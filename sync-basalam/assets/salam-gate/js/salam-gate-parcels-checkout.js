(function (window, $) {
  "use strict";
  if (!$) return;
  $(function () {
    var $modal = $("#sgp-checkout-modal");
    if (!$modal.length) return;
    var identity = null, snapshot = null, selected = null, busy = false, financial = false, epoch = 0;
    var unknown = false, unknownPayments = Object.create(null), readRequest = null;
    var requiredWeight = null, confirmedWeight = null;
    var readLoading = null;
    var config = {};
    try { config = JSON.parse($("#sgp-config").text() || "{}"); } catch (ignore) { /* No identity cache. */ }
    function storageKey() { return "sgParcelPayment:" + window.location.origin + ":" + (config.userId || 0) + ":" + identity.market + ":" + identity.parcel_id; }
    function rememberUnknown(value) {
      unknown = value;
      unknownPayments[storageKey()] = value;
      try {
        if (value) window.sessionStorage.setItem(storageKey(), "unknown");
        else window.sessionStorage.removeItem(storageKey());
      } catch (ignore) { /* The server also maintains the financial outcome. */ }
    }
    function amount(value) { return typeof value === "number" && Number.isSafeInteger(value) && value >= 0; }
    function money(rial) { return Math.floor(rial / 10).toLocaleString("fa-IR", { maximumFractionDigits: 0 }) + " تومان"; }
    function message(data, fallback) {
      var value = data && data.message;
      if (data && (["pay_unknown", "unknown"].indexOf(data.code) !== -1 || ["pending", "unknown"].indexOf(data.payment_state) !== -1)
        || typeof value === "string" && /پرداخت/.test(value) && /نامعلوم|نامشخص|مشخص نیست|قطعی نیست/.test(value)) {
        return "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.";
      }
      return typeof value === "string" && /[\u0600-\u06ff]/.test(value) ? value.replace(/بسالم/g, "باسلام") : fallback;
    }
    function feedback(text, kind, announce) {
      var type = kind || "info", floating = !!text && (type === "error" || type === "success") && typeof window.woosalamNotify === "function";
      $modal.find("[data-sgpc-feedback]").text(text || "").attr("data-kind", type).attr("role", type === "error" ? "alert" : "status")
        .toggleClass("sg-feedback-toast-only", floating);
      if (floating && announce !== false) window.woosalamNotify(text, type);
    }
    function post(action, data) {
      return $.ajax({ url: window.basalam_ajax.ajaxurl, type: "POST", dataType: "json", timeout: action === "salam_gate_parcel_checkout_pay" ? 90000 : 45000,
        data: $.extend({ action: action, _wpnonce: window.basalam_ajax.nonce }, identity, data || {}) });
    }
    function validQuote(quote) {
      return quote && !quote.error && typeof quote.hash_id === "string" && quote.hash_id.length > 0 && quote.hash_id.length <= 256 && amount(quote.total_rial) && typeof quote.selected === "boolean";
    }
    function nativeWeight(data) {
      var review = data && data.weight_confirmation;
      return review && review.requires_confirmation === true && Number.isSafeInteger(review.weight_grams)
        && review.weight_grams >= 50 && review.weight_grams <= 30000 ? review.weight_grams : null;
    }
    function revokeWeight() {
      requiredWeight = null; confirmedWeight = null;
      $modal.find("[data-sgpc-weight-review]").remove();
    }
    function validSnapshot(data) {
      var valid = data && Number(data.parcel_id) === identity.parcel_id && data.market === identity.market
        && ["unpaid", "paid", "pending", "unknown", "blocked"].indexOf(data.payment_state) !== -1
        && typeof data.can_pay === "boolean" && Array.isArray(data.allowed_methods)
        && data.allowed_methods.every(function (value) { return Number.isSafeInteger(value) && [19, 20, 21].indexOf(value) !== -1; })
        && data.quotes && Number(data.quotes.parcel_id) === identity.parcel_id && Array.isArray(data.quotes.quotes);
      if (!valid) return false;
      // A native unpaid parcel must expose an authoritative weight for explicit
      // review. Missing or malformed consent data cannot authorize money.
      if (identity.market === "basalam" && data.payment_state === "unpaid" && data.can_pay === true && nativeWeight(data) === null) return false;
      var hashes = Object.create(null), count = 0;
      return data.quotes.quotes.every(function (quote) {
        if (quote && quote.error && quote.selected === false) return true;
        if (!validQuote(quote) || hashes[quote.hash_id]) return false;
        hashes[quote.hash_id] = true;
        if (quote.selected) count++;
        return count <= 1;
      });
    }
    function method() { return identity && identity.market === "basalam" ? 19 : Number($modal.find(".sgpc-method").val()); }
    function allowed() {
      return !!(snapshot && snapshot.payment_state === "unpaid" && snapshot.can_pay === true && !unknown
        && snapshot.allowed_methods.indexOf(method()) !== -1 && validQuote(selected)
        && (identity.market !== "basalam" || (requiredWeight !== null && confirmedWeight === requiredWeight)));
    }
    function controls() {
      $modal.attr("data-sg-payable-rial", validQuote(selected) ? selected.total_rial : "");
      var editable = !busy && snapshot && snapshot.can_pay === true && snapshot.payment_state === "unpaid" && !unknown;
      $modal.attr("data-sg-financial-busy", financial ? "true" : "false");
      $modal.find("[data-sgpc-close]").prop("disabled", financial);
      $modal.find("[data-sgpc-recheck]").prop("disabled", busy);
      $modal.find("[data-sgpc-quotes] input, .sgpc-method").prop("disabled", !editable);
      $modal.find("[data-sgpc-confirm-weight]").prop("disabled", !editable || requiredWeight === null)
        .prop("checked", requiredWeight !== null && confirmedWeight === requiredWeight);
      $modal.find("[data-sgpc-weight-review]").toggleClass("is-confirmed", requiredWeight !== null && confirmedWeight === requiredWeight);
      $modal.find("[data-sgpc-pay]").prop("disabled", busy || !allowed()).text(busy ? "در حال بررسی…"
        : unknown || (snapshot && ["pending", "unknown"].indexOf(snapshot.payment_state) !== -1) ? "در حال بررسی"
          : identity && identity.market === "basalam" ? "تأیید و پرداخت از مبلغ سفارش" : "تأیید و پرداخت هزینه ارسال");
      $modal.find("[data-sgpc-total]").text(selected && validQuote(selected) ? "مبلغ قابل پرداخت: " + money(selected.total_rial) : "");
      if (window.SalamGatePaymentPresentation) window.SalamGatePaymentPresentation.refresh($modal);
    }
    function render(data, preferred, approvedWeight) {
      snapshot = data;
      selected = null;
      revokeWeight();
      if (identity.market === "basalam" && data.payment_state === "unpaid" && data.can_pay === true) {
        requiredWeight = nativeWeight(data);
        confirmedWeight = approvedWeight === requiredWeight ? approvedWeight : null;
        var $weightReview = $("<section class='sg-order-weight-confirmation' data-sgpc-weight-review>");
        $weightReview.append($("<p>").text("وزن بستهٔ انتخاب‌شده با بسته‌بندی: " + requiredWeight.toLocaleString("fa-IR") + " گرم"));
        $weightReview.append($("<label>").append($("<input type='checkbox' data-sgpc-confirm-weight>"),
          $("<span>").text("وزن این بسته را بررسی کرده‌ام و تأیید می‌کنم.")));
        $weightReview.insertBefore($modal.find("[data-sgpc-quotes]").first());
      }
      // Only an owner/attempt-verified failure from the server can release an
      // uncertain browser payment. An ordinary (possibly stale) unpaid read
      // must never allow another charge.
      if (data.payment_failure_confirmed === true && data.can_pay === true && data.payment_state === "unpaid") rememberUnknown(false);
      var $summary = $modal.find("[data-sgpc-summary]").empty(), parcel = data.parcel || {};
      var statusLabel = unknown || ["pending", "unknown"].indexOf(data.payment_state) !== -1 ? "در حال بررسی پرداخت" : parcel.status_label;
      [["گیرنده", parcel.receiver_name], ["بازار سفارش", parcel.market_label], ["وضعیت", statusLabel]].forEach(function (row) {
        if (row[1]) $summary.append($("<div class='sg-wizard__review-row'>").append($("<dt>").text(row[0]), $("<dd>").text(row[1])));
      });
      var quotes = data.quotes.quotes.filter(validQuote), $options = $modal.find("[data-sgpc-quote-options]").empty();
      selected = quotes.find(function (quote) { return quote.hash_id === preferred; }) || quotes.find(function (quote) { return quote.selected === true; }) || null;
      quotes.forEach(function (quote, index) {
        var $radio = $("<input type='radio' name='sgpc-quote'>").val(index).prop("checked", quote === selected).data("quote", quote);
        var serviceName = quote.service_name || quote.service_type_label || "روش ارسال";
        var $card = $("<div class='sgp-quote'>").append($("<label class='sgp-quote__choice'>")
          .append($radio, $("<strong>").text(serviceName), $("<b>").text(money(quote.total_rial))));
        var $costs = $("<dl class='sgp-quote__costs'>");
        function addCost(title, value) {
          $costs.append($("<div>").append($("<dt>").text(title), $("<dd>").text(Math.floor(value).toLocaleString("fa-IR") + " تومان")));
        }
        var fare = quote.fare_cost_toman != null ? quote.fare_cost_toman : quote.raw_cost_toman;
        if (typeof fare === "number" && Number.isFinite(fare) && fare >= 0) {
          addCost("کرایه پستی", fare);
        }
        (Array.isArray(quote.cost_rows) ? quote.cost_rows : []).forEach(function (row) {
          if (row && typeof row.title === "string" && typeof row.amount_toman === "number" && Number.isFinite(row.amount_toman)) addCost(row.title, row.amount_toman);
        });
        if ($costs.children().length) {
          $card.append($("<details class='sgp-quote__details' name='sgpc-price-details'>").append(
            $("<summary>").attr("aria-label", "جزئیات قیمت " + serviceName).text("جزئیات قیمت"), $costs));
        }
        $options.append($card);
      });
      $modal.find("[data-sgpc-quotes]").prop("hidden", !data.can_pay || !quotes.length);
      $modal.find("[data-sgpc-methods]").prop("hidden", !data.can_pay || identity.market === "basalam");
      $modal.find("[data-sgpc-basalam]").prop("hidden", !data.can_pay || identity.market !== "basalam");
      var text = message(data, identity.market === "basalam"
        ? "وزن این بسته و هزینه ارسال را بررسی کنید؛ پس از تأیید وزن می‌توانید پرداخت از مبلغ سفارش را ادامه دهید."
        : "روش ارسال و شیوه پرداخت را انتخاب و مبلغ را تأیید کنید.");
      if (data.payment_state === "paid") {
        rememberUnknown(false);
        text = parcel.tracking_code ? "هزینه ارسال پرداخت شده است. کد رهگیری: " + parcel.tracking_code : "پرداخت ثبت شده است؛ کد رهگیری هنوز صادر نشده. دوباره پرداخت نکنید.";
      } else if (unknown || data.payment_state === "unknown" || data.payment_state === "pending") {
        rememberUnknown(true);
        text = "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.";
      } else if (data.can_pay && !quotes.length) {
        text = "نرخ قابل پرداختی دریافت نشد. کمی بعد وضعیت و نرخ‌ها را دوباره بررسی کنید.";
      }
      var kind = data.payment_state === "paid" ? "success" : (data.can_pay && !quotes.length ? "error" : "info");
      feedback(text, kind);
      if (kind === "info" && data.can_pay && !unknown && data.quotes.quotes.some(function (quote) { return !!quote.error; })
        && typeof window.woosalamNotify === "function") {
        window.woosalamNotify("برای برخی شیوه‌های ارسال نرخ دریافت نشد؛ روش‌های قابل استفاده نمایش داده شده‌اند.", "error");
      }
      controls();
    }
    function read() {
      if (!identity || busy) return;
      var seq = ++epoch;
      readLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($modal.find("[data-sg-load]")[0], "checkout", read);
      var loading = readLoading;
      var waitingBalance = false, requestFinished = false;
      snapshot = null; selected = null; revokeWeight(); busy = true; financial = false; controls();
      feedback("در حال دریافت وضعیت و نرخ‌های همین مرسوله…");
      readRequest = post("salam_gate_parcel_checkout").done(function (response) {
        if (seq !== epoch) return;
        if (response && response.success === true && validSnapshot(response.data)) {
          render(response.data);
          var presentation = window.SalamGatePaymentPresentation;
          var ready = function () {
            if (seq !== epoch) return;
            waitingBalance = false;
            if (loading) loading.ready();
            if (requestFinished) busy = false;
            controls();
            if (presentation) presentation.refresh($modal);
          };
          // A settled unavailable balance is explicit information, not a stuck
          // spinner. Financial authorization remains entirely server-side.
          if (presentation && typeof presentation.whenReady === "function") {
            waitingBalance = true;
            presentation.whenReady($modal).then(ready, ready);
          }
          else ready();
        } else {
          var error = message(response && response.data, "اطلاعات مرسوله دریافت نشد. پرداختی ارسال نشده؛ دوباره وضعیت را بررسی کنید.");
          feedback(error, "error", !loading);
          if (loading) loading.fail(error);
        }
      }).fail(function () {
        if (seq === epoch) {
          var error = "ارتباط برای دریافت اطلاعات برقرار نشد. پرداختی ارسال نشده؛ دوباره بررسی کنید.";
          feedback(error, "error", !loading);
          if (loading) loading.fail(error);
        }
      }).always(function () {
        if (seq !== epoch) return;
        requestFinished = true;
        readRequest = null; busy = waitingBalance; controls();
      });
    }
    $(document).on("sgp:checkout", function (event, item) {
      if (busy || !item || !Number.isSafeInteger(Number(item.id)) || Number(item.id) <= 0 || typeof item.market !== "string") return;
      if (window.SalamGatePaymentPresentation && window.SalamGatePaymentPresentation.resetChoice) window.SalamGatePaymentPresentation.resetChoice($modal[0]);
      identity = { parcel_id: Number(item.id), market: item.market };
      $modal.attr("data-market", identity.market);
      revokeWeight();
      unknown = unknownPayments[storageKey()] === true;
      try { unknown = unknown || window.sessionStorage.getItem(storageKey()) === "unknown"; } catch (ignore) { /* Per-page and server guards remain. */ }
      $modal.find("[data-sgpc-id]").text(String(identity.parcel_id).replace(/\d/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[+d]; }));
      $modal.find("[data-sgpc-summary], [data-sgpc-quote-options]").empty();
      $modal.find("[data-sgpc-quotes], [data-sgpc-methods], [data-sgpc-basalam]").prop("hidden", true);
      $modal.show(); read();
    });
    function close() {
      if (financial) return;
      ++epoch; busy = false; financial = false; snapshot = null; selected = null; revokeWeight();
      if (readLoading) readLoading.ready();
      readLoading = null;
      if (readRequest && typeof readRequest.abort === "function") readRequest.abort();
      readRequest = null; controls(); $modal.hide();
    }
    $modal.on("click", "[data-sgpc-close]", close);
    $modal.on("click", function (event) { if (event.target === this) close(); });
    $modal.on("click", "[data-sgpc-recheck]", read);
    $modal.on("change", "[data-sgpc-quotes] input", function () {
      if (busy) return;
      selected = $(this).data("quote"); controls();
    });
    $modal.on("change", ".sgpc-method", controls);
    $modal.on("change", "[data-sgpc-confirm-weight]", function () {
      if (!busy && snapshot && snapshot.payment_state === "unpaid" && snapshot.can_pay === true && !unknown) {
        confirmedWeight = this.checked && requiredWeight !== null ? requiredWeight : null;
      }
      controls();
    });
    $modal.on("click", "[data-sgpc-pay]", function () {
      if (busy || !allowed()) return;
      var pin = { hash: selected.hash_id, total: selected.total_rial, method: method(), weight: confirmedWeight }, seq = ++epoch;
      busy = true; financial = true; controls(); feedback("در حال تأیید نرخ و مبلغ انتخاب‌شده…");
      function finish() { if (seq === epoch) { busy = false; financial = false; controls(); } }
      function block(text) { snapshot = null; selected = null; revokeWeight(); feedback(text, "error"); finish(); }
      var consent = identity.market === "basalam" ? { weight_confirmed: 1, confirmed_weight_grams: pin.weight } : {};
      post("salam_gate_parcel_checkout_select", $.extend({ hash: pin.hash }, consent)).done(function (response) {
        if (seq !== epoch) return;
        var data = response && response.success === true && response.data;
        if (!validSnapshot(data)) { block(message(response && response.data, "نرخ تأیید نشد. پرداختی ارسال نشده؛ دوباره وضعیت را بررسی کنید.")); return; }
        render(data, pin.hash, pin.weight);
        if (identity.market === "basalam" && data.payment_state === "unpaid" && data.can_pay === true && requiredWeight !== pin.weight) {
          feedback("وزن بسته تغییر کرده است؛ پرداخت انجام نشد. وزن تازه را بررسی و دوباره تأیید کنید.", "error"); finish(); return;
        }
        var confirmed = data.quotes.quotes.filter(function (quote) { return validQuote(quote) && quote.selected === true; });
        if (!allowed() || data.allowed_methods.indexOf(pin.method) === -1 || confirmed.length !== 1 || confirmed[0].hash_id !== pin.hash || confirmed[0].total_rial !== pin.total) {
          feedback("نرخ یا مبلغ تغییر کرده است؛ پرداخت انجام نشد. مبلغ تازه را بررسی و دوباره تأیید کنید.", "error"); finish(); return;
        }
        rememberUnknown(true);
        feedback("در حال پرداخت هزینه ارسال؛ لطفاً این پنجره را باز نگه دارید.");
        post("salam_gate_parcel_checkout_pay", $.extend({ method: pin.method, expected_hash: pin.hash, expected_total_rial: pin.total }, consent)).done(function (result) {
          if (seq !== epoch) return;
          var receipt = result && result.data;
          if (result && result.success === true && receipt && Number(receipt.parcel_id) === identity.parcel_id
            && typeof receipt.confirmed === "boolean" && typeof receipt.tracking_code === "string"
            && Number.isSafeInteger(receipt.invoice_id) && receipt.invoice_id >= 0 && (receipt.confirmed || receipt.invoice_id > 0)) {
            // Financial success can precede barcode issuance. Never invite a retry.
            snapshot = null; selected = null; revokeWeight();
            feedback(receipt.confirmed && receipt.tracking_code ? "پرداخت هزینه ارسال انجام شد. کد رهگیری: " + receipt.tracking_code : "پرداخت ثبت شده است؛ صدور کد رهگیری هنوز تأیید نشده. دوباره پرداخت نکنید.", "success");
            $(document).trigger("sgp:refresh");
          } else {
            var code = receipt && receipt.code, kind = receipt && receipt.kind;
            var definite = ["quote_changed", "preflight_unavailable", "preflight_locked"].indexOf(code) !== -1
              || (!code && ["validation", "unauthenticated", "forbidden", "not_found", "rate_limited"].indexOf(kind) !== -1);
            if (definite) rememberUnknown(false);
            if (!definite || ["quote_changed", "preflight_unavailable", "preflight_locked"].indexOf(code) !== -1) { snapshot = null; selected = null; revokeWeight(); }
            feedback(definite ? message(receipt, "پرداخت انجام نشد؛ روش پرداخت را تغییر دهید یا دوباره وضعیت را بررسی کنید.")
              : "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", "error");
            if (definite && window.SalamGatePaymentPresentation) window.SalamGatePaymentPresentation.refreshBalance($modal);
          }
        }).fail(function () {
          if (seq !== epoch) return;
          snapshot = null; selected = null; revokeWeight();
          feedback("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", "error");
        }).always(finish);
      }).fail(function () { if (seq === epoch) block("تأیید نرخ انجام نشد؛ درخواست پرداخت ارسال نشده. دوباره وضعیت را بررسی کنید."); });
    });
  });
})(window, window.jQuery);
