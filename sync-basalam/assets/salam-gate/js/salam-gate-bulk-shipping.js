(function (window, document, $) {
  "use strict";
  if (!$) return;

  $(function () {
    var $modal = $("#sg-bulk-shipping-modal");
    if (!$modal.length) return;
    var rows = [], references = {}, balances = {}, batch = null, source = "", epoch = 0, selectionIds = [];
    var stage = "review", busy = "", stopRequested = false, trigger = null, openingNonce = "", paymentSelection = [], batchHalted = false;
    var maxItems = Math.min(20, Number((window.woosalamBulkShippingConfig || {}).maxItems) || 20);
    var blocked = Object.create(null);
    var preparationTimers = [], preparationFailed = false;
    var selectionQueue = [], selectionRunning = false, paymentMethodManual = false;
    var returnNeedsRefresh = false, refreshCheckpoint = null;
    function own(value) { return $.isPlainObject(value); }
    function integer(value, min, max) { return Number.isSafeInteger(value) && value >= min && value <= max; }
    function positive(value) { return integer(value, 1, Number.MAX_SAFE_INTEGER); }
    function native(row) { return row && row.market === "basalam"; }
    function rowKey(row) { return row.item_key || source + ":" + row.id; }
    function rowAt(element) {
      var key = $(element).closest("[data-sgb-key]").attr("data-sgb-key");
      return rows.find(function (row) { return rowKey(row) === key; });
    }
    function nativeIdentity(row) {
      return positive(row.basalam_order_id) && positive(row.basalam_parcel_id)
        && (source !== "orders" || row.order_id === row.id)
        && row.item_key === (source === "orders" ? "orders:" + row.id + ":basalam:" + row.basalam_parcel_id : "parcels:" + row.id);
    }
    function raw(value) {
      return String(value == null ? "" : value).replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); })
        .replace(/[٠-٩]/g, function (d) { return "٠١٢٣٤٥٦٧٨٩".indexOf(d); }).trim();
    }
    function number(value) { var text = raw(value); return /^\d+$/.test(text) ? Number(text) : NaN; }
    function fa(value) { return Number.isFinite(Number(value)) ? Number(value).toLocaleString("fa-IR") : "—"; }
    function money(rial) { return Math.floor(rial / 10).toLocaleString("fa-IR", { maximumFractionDigits: 0 }) + " تومان"; }
    function cityPath(value) {
      return typeof value === "string" ? value : own(value) ? [value.province, value.city].filter(function (title) { return typeof title === "string" && title; }).join(" — ") : "";
    }
    function storageKey(row) {
      return "sgBulkReview:v2:" + window.location.origin + ":" + (batch && batch.connection_id || "")
        + ":" + (batch && batch.user_id || 0) + ":" + source + ":" + row.id + ":" + (row.parcel_id || 0)
        + (native(row) ? ":native:" + row.basalam_parcel_id : "");
    }
    function remembered(row) {
      var key = storageKey(row);
      try { return blocked[key] === true || window.sessionStorage.getItem(key) === "review"; }
      catch (_) { return blocked[key] === true; }
    }
    function remember(row, value) {
      var key = storageKey(row); blocked[key] = value;
      try { if (value) window.sessionStorage.setItem(key, "review"); else window.sessionStorage.removeItem(key); } catch (_) { /* Server financial guards remain authoritative. */ }
    }
    function feedback(text, kind) {
      $modal.find("[data-sgb-feedback]").text(text || "").attr("data-kind", kind || "info")
        .attr("role", kind === "error" ? "alert" : "status").prop("hidden", !text);
    }
    function notify(text, kind) { if (typeof window.woosalamNotify === "function") window.woosalamNotify(text, kind || "info"); }
    function errorMessage(data, fallback) {
      if (data && (["pay_unknown", "create_unknown", "update_unknown", "quote_unknown"].indexOf(data.code) !== -1
        || ["pending", "unknown"].indexOf(data.payment_state) !== -1)) {
        return "تأیید این مرسوله هنوز دریافت نشده؛ وضعیت را در فهرست بررسی کنید و دوباره ثبت یا پرداخت نکنید.";
      }
      var text = data && typeof data.message === "string" && /[\u0600-\u06ff]/.test(data.message)
        ? data.message.replace(/بسالم/g, "باسلام") : fallback;
      if (data && (Array.isArray(data.errors) || own(data.errors))) {
        var details = Object.keys(data.errors).map(function (key) {
          var value = data.errors[key], detail = typeof value === "string" ? value : own(value) && value.message;
          return typeof detail === "string" && /[\u0600-\u06ff]/.test(detail) ? detail.replace(/بسالم/g, "باسلام") : "";
        }).filter(function (detail, index, list) { return detail && detail !== text && list.indexOf(detail) === index; });
        if (details.length) text += " " + details.slice(0, 4).join("؛ ");
      }
      return text;
    }
    function financialError(data) {
      return !own(data) || ["pay_unknown", "create_unknown", "update_unknown", "quote_unknown", "create_recovered", "already_paid", "parcel_replaced"].indexOf(data.code) !== -1
        || ["pending", "unknown", "paid"].indexOf(data.payment_state) !== -1;
    }
    function definite(data) {
      return own(data) && !financialError(data) && typeof data.message === "string"
        && (data.kind === "validation" || ["validation", "quote_changed", "quote_check_failed", "quote_selection_required", "weight_confirmation_required", "preflight_locked"].indexOf(data.code) !== -1);
    }
    function funds(data) {
      return own(data) && (/insufficient|balance|wallet/.test(String(data.code || ""))
        || /موجودی|اعتبار|تراز|کیف پول/.test(String(data.message || "")) && /کافی|کمبود|ناکافی|کسری/.test(String(data.message || "")));
    }
    function sessionError(data) {
      return own(data) && (["auth", "forbidden"].indexOf(data.kind) !== -1
        || /batch|session|connection|expired|nonce|auth/.test(String(data.code || "")));
    }
    function recoverableFunds(data, pin) {
      return own(data) && data.code === "insufficient_funds" && data.kind === "validation"
        && data.payment_state === "unpaid" && data.safe_to_retry === true && data.no_charge_confirmed === true
        && data.requires_confirmation === true && data.recovery === "review_payment_method"
        && Number(data.payment_method) === pin.method && [20, 21].includes(pin.method)
        && data.request_dispatched === true && data.parcel_id === pin.parcel;
    }
    function request(action, data, readOnly) {
      var config = window.basalam_ajax;
      var deferred = $.Deferred();
      if (!config || !config.ajaxurl || !config.nonce || (openingNonce && config.nonce !== openingNonce)) {
        deferred.reject({ data: { code: "session_changed", kind: "auth", request_dispatched: false, message: "اتصال یا نشست تغییر کرده است؛ پنجره را ببندید و صفحه را تازه کنید. درخواست تازه‌ای ارسال نشد." }, transport: false });
        return deferred.promise();
      }
      var dispatchedEpoch = epoch;
      $.ajax({ url: config.ajaxurl, type: "POST", dataType: "json", timeout: action === "salam_gate_bulk_item" ? 180000 : 45000,
        data: $.extend({ action: action, _wpnonce: config.nonce }, data || {}) })
        .done(function (response) {
          if (dispatchedEpoch !== epoch) { deferred.reject({ stale: true }); return; }
          if (!own(response) || response.success !== true || !own(response.data)) {
            deferred.reject({ data: own(response) && own(response.data) ? response.data : null, transport: false }); return;
          }
          if (batch && response.data.connection_id !== undefined && response.data.connection_id !== batch.connection_id) {
            deferred.reject({ data: { code: "connection_changed", message: "حساب متصل تغییر کرده است؛ روند متوقف شد. صفحه را تازه کنید." }, transport: false }); return;
          }
          deferred.resolve(response.data);
        }).fail(function (xhr) {
          if (dispatchedEpoch !== epoch) { deferred.reject({ stale: true }); return; }
          var data = xhr && own(xhr.responseJSON) && own(xhr.responseJSON.data) ? xhr.responseJSON.data : null;
          deferred.reject({ data: data, transport: !data, readOnly: !!readOnly });
        });
      return deferred.promise();
    }
    function item(row, operation, fields) {
      var identity = { batch_id: batch.batch_id, id: row.id, item_key: rowKey(row), operation: operation };
      if (native(row)) { identity.basalam_parcel_id = row.basalam_parcel_id; identity.basalam_order_id = row.basalam_order_id; }
      return request("salam_gate_bulk_item", $.extend(identity, fields || {}), operation === "prepare");
    }
    function selectedRows() { return rows.filter(function (row) { return row.checked && !row.excluded && row.state !== "paid"; }); }
    function chosen(row) { return (row.quotes || []).find(function (quote) { return quote.hash_id === row.hash; }); }
    function validQuote(quote) {
      return own(quote) && !quote.error && typeof quote.hash_id === "string" && quote.hash_id.length > 0 && quote.hash_id.length <= 256
        && integer(quote.total_rial, 0, Number.MAX_SAFE_INTEGER) && typeof quote.selected === "boolean";
    }
    function validQuotes(data, parcelId) {
      if (!own(data) || data.parcel_id !== parcelId || !Array.isArray(data.quotes)) return null;
      var seen = Object.create(null), selected = 0, usable = [];
      for (var i = 0; i < data.quotes.length; i++) {
        var quote = data.quotes[i];
        if (own(quote) && quote.error && quote.selected === false) continue;
        if (!validQuote(quote) || seen[quote.hash_id] || (quote.selected && ++selected > 1)) return null;
        seen[quote.hash_id] = true; usable.push(quote);
      }
      return usable;
    }
    function total(filter) {
      var sum = 0;
      selectedRows().forEach(function (row) { var quote = chosen(row); if ((!filter || filter(row)) && validQuote(quote) && row.state === "quoted") sum += quote.total_rial; });
      return integer(sum, 0, Number.MAX_SAFE_INTEGER) ? sum : null;
    }
    function ready() {
      var list = selectedRows();
      return !selectionRunning && !selectionQueue.length && list.length > 0 && list.every(function (row) {
        return row.state === "quoted" && validQuote(chosen(row)) && row.confirmedWeight === row.draft.weight && !remembered(row)
          && (!native(row) || row.quoteVerified === row.hash && nativeIdentity(row));
      });
    }
    function method() { return Number($modal.find("[name=sgb-payment]:checked").val()); }
    function balanceCovers(methodId, amount) {
      var balance = balances[methodId];
      return [20, 21].includes(methodId) && amount !== null && !!balance
        && balance.state === "ready" && balance.rial >= amount;
    }
    function stageTo(next) {
      stage = next; $modal.toggleClass("is-quotes", next === "quotes").toggleClass("is-results", next === "results");
      $modal.find("[data-sgb-step]").each(function () {
        var current = this.getAttribute("data-sgb-step") === next;
        $(this).toggleClass("is-current", current).attr("aria-current", current ? "step" : null);
      });
      $modal.find("[data-sgb-common]").prop("hidden", next !== "review" || source !== "orders" || !rows.some(function (row) { return !native(row); }));
      $modal.find("[data-sgb-exclusions]").prop("hidden", next !== "review" || !$modal.find("[data-sgb-excluded-list] li").length);
      $modal.find("[data-sgb-summary]").prop("hidden", next !== "quotes");
      $modal.find("[data-sgb-quote], [data-sgb-quote-hint]").prop("hidden", next !== "review");
      $modal.find("[data-sgb-back], [data-sgb-pay]").prop("hidden", next !== "quotes");
      $modal.find("[data-sgb-done]").prop("hidden", next !== "results");
      $modal.find("[data-sgb-refresh]").prop("hidden", next === "results");
      $modal.find("[data-sgb-stage-hint]").text(next === "review"
        ? source === "orders" ? "وزن نهایی با بسته‌بندی را برای هر مرسوله بررسی و تأیید کنید." : "اطلاعات ذخیره‌شده را بررسی کنید؛ ویرایش هر مرسوله از مسیر جداگانه انجام می‌شود."
        : next === "quotes" ? "" : "نتیجه هر مرسوله جداگانه نمایش داده شده است.").prop("hidden", next === "quotes");
      controls();
    }
    function controls() {
      var list = selectedRows(), hasInputs = list.length > 0 && list.every(function (row) { return row.draft && row.state !== "blocked" && row.confirmedWeight === row.draft.weight; });
      $modal.attr("aria-busy", busy ? "true" : "false").attr("data-sg-financial-busy", busy === "pay" ? "true" : "false");
      $modal.find("[data-sgb-close], [data-sgb-back], [data-sgb-done]").prop("disabled", !!busy && busy !== "bootstrap" || selectionRunning);
      $modal.find("[data-sgb-refresh]").prop("disabled", !!busy || selectionRunning || !!selectionQueue.length).attr("aria-busy", String(busy === "bootstrap"));
      if (batchHalted) $modal.find("[data-sgb-back]").prop("disabled", true);
      $modal.find("[data-sgb-quote]").prop("disabled", !!busy || batchHalted || returnNeedsRefresh || !hasInputs);
      $modal.find("[data-sgb-consent], [name=sgb-payment], [data-sgb-box-all], [data-sgb-category-all]").prop("disabled", !!busy || batchHalted || returnNeedsRefresh);
      rows.forEach(function (row) {
        if (!row.$el) return;
        var locked = !!busy || batchHalted || returnNeedsRefresh || stage !== "review" || row.state === "blocked" || row.state === "paid";
        row.$el.find("[data-sgb-field]").prop("disabled", locked || source === "parcels");
        row.$el.find("[data-sgb-row-select], [data-sgb-confirm-weight]").prop("disabled", !!busy || batchHalted || returnNeedsRefresh || row.state === "blocked" || row.state === "paid" || stage === "results" || stage === "quotes" && row.state !== "quoted");
        var confirmInQuotes = stage === "quotes" && row.needsWeightReview && !busy && !batchHalted && !returnNeedsRefresh && row.state !== "blocked";
        row.$el.find("[data-sgb-confirm-weight]").prop("disabled", locked && !confirmInQuotes).prop("checked", row.confirmedWeight === (row.draft && row.draft.weight));
        row.$el.find("[data-sgb-quote-choice]").prop("disabled", !!busy || batchHalted || returnNeedsRefresh || stage !== "quotes" || row.state !== "quoted");
        row.$el.toggleClass("is-skipped", !row.checked).toggleClass("is-paid", row.state === "paid").toggleClass("is-blocked", row.state === "blocked").toggleClass("is-error", row.state === "error");
        row.$el.toggleClass("needs-weight-review", !!row.needsWeightReview);
      });
      summary();
    }
    function selectDefaultMethod() {
      if (paymentMethodManual || busy === "pay" || batchHalted) return;
      if ([20, 21].some(function (id) { return !balances[id] || balances[id].state === "loading"; })) return;
      var choices = [20, 21].filter(function (id) { return balances[id].state === "ready"; });
      choices.sort(function (a, b) { return balances[b].rial - balances[a].rial || a - b; });
      if (!choices.length || method() === choices[0]) return;
      $modal.find("[name=sgb-payment]").prop("checked", false).filter("[value='" + choices[0] + "']").prop("checked", true);
      $modal.find("[data-sgb-consent]").prop("checked", false);
    }
    function summary() {
      var sum = total(), selectedMethod = method(), balance = balances[selectedMethod];
      var websiteRows = selectedRows().filter(function (row) { return !native(row); });
      var nativeRows = selectedRows().filter(native), nativeSum = total(native), websiteSum = total(function (row) { return !native(row); });
      $modal.find("[data-sgb-website-funding]").prop("hidden", !websiteRows.length);
      $modal.find("[data-sgb-native-funding]").prop("hidden", !nativeRows.length);
      $modal.find("[data-sgb-native-total]").text(ready() && nativeSum !== null ? money(nativeSum) : "—");
      $modal.find("[data-sgb-website-total]").text(ready() && websiteSum !== null ? money(websiteSum) : "—");
      $modal.find("[data-sgb-count]").text(fa(stage === "results" ? paymentSelection.length : selectedRows().length) + " مرسوله انتخاب‌شده");
      $modal.find("[data-sgb-total]").text(ready() && sum !== null ? money(sum) : "—");
      [20, 21].forEach(function (id) {
        var record = balances[id], $input = $modal.find("[name=sgb-payment][value='" + id + "']"), $card = $input.closest(".sgb-payment-card");
        // A short balance limits payment, not selecting a method or topping it up.
        $card.toggleClass("is-selected", selectedMethod === id).toggleClass("is-disabled", $input.prop("disabled"));
        $modal.find("[data-sgb-balance='" + id + "']").text(record && record.state === "ready" ? "موجودی: " + money(Math.max(0, record.rial))
          : record && record.state === "loading" ? "در حال دریافت…" : "موجودی در دسترس نیست");
      });
      var refreshing = [20, 21].some(function (id) { return balances[id] && balances[id].state === "loading"; });
      $modal.find("[data-sgb-balances]").prop("disabled", !!busy || refreshing).attr("aria-busy", String(refreshing))
        .toggleClass("is-refreshing", refreshing).find("[data-sgb-balances-label]").text(refreshing ? "در حال به‌روزرسانی…" : "تازه‌سازی موجودی");
      var shortage = websiteRows.length && balance && balance.state === "ready" && websiteSum !== null && balance.rial < websiteSum;
      $modal.find("[data-sgb-shortfall]").text(shortage ? selectedMethod === 20
        ? "موجودی تراز غرفه برای پرداخت کافی نیست. کیف پول باسلام را انتخاب کنید."
        : "موجودی کیف پول برای پرداخت کافی نیست. برای ادامه، کیف پول را شارژ کنید." : "").prop("hidden", !shortage);
      $modal.find("[data-sgb-topup]").prop("hidden", !(shortage && selectedMethod === 21));
      $modal.find("[data-sgb-pay]").prop("disabled", !!busy || batchHalted || returnNeedsRefresh || !ready() || sum === null || websiteRows.length && !balanceCovers(selectedMethod, websiteSum) || !$modal.find("[data-sgb-consent]").prop("checked"))
        .text(returnNeedsRefresh ? "ابتدا سفارش‌ها را به‌روزرسانی کنید" : batchHalted ? "ابتدا وضعیت مرسوله‌ها را بررسی کنید" : "تأیید و پرداخت " + fa(selectedRows().length) + " مرسوله");
      $modal.find("[data-sgb-footer-note]").text(batchHalted ? "این انتخاب متوقف شده است؛ پس از بررسی وضعیت، پنجره را ببندید و دوباره انتخاب کنید."
        : busy === "pay" ? "تا پایان بررسی، این پنجره را باز نگه دارید؛ درخواست‌ها تکرار نمی‌شوند."
        : stage === "results" ? "مرسوله‌های پرداخت‌شده را دوباره پرداخت نکنید." : "");
      $modal.find("[data-sgb-footer-note]").prop("hidden", !$modal.find("[data-sgb-footer-note]").text());
    }
    function readBalances() {
      var completion = $.Deferred(), remaining = 2, sequence = epoch;
      [20, 21].forEach(function (id) {
        balances[id] = { state: "loading" }; summary();
        request("salam_gate_payment_balance", { method: id }, true).done(function (data) {
          if (sequence !== epoch) return;
          var rial = typeof data.balance_rial === "number" ? data.balance_rial : /^-?\d+$/.test(String(data.balance_rial)) ? Number(data.balance_rial) : NaN;
          var toman = Number(data.balance_toman);
          balances[id] = Number(data.method) === id && Number.isSafeInteger(rial) && Number.isFinite(toman) && Math.abs(toman - rial / 10) < .001
            ? { state: "ready", rial: id === 20 ? Math.max(0, rial) : rial } : { state: "error" };
        }).fail(function () { if (sequence === epoch) balances[id] = { state: "error" }; }).always(function () {
          if (--remaining === 0) { if (sequence === epoch) selectDefaultMethod(); completion.resolve(); }
          if (sequence === epoch) summary();
        });
      });
      return completion.promise();
    }
    function optionList($select, list, selected, placeholder) {
      $select.empty().append($("<option>").val("").text(placeholder || "انتخاب کنید"));
      (Array.isArray(list) ? list : []).forEach(function (value) {
        if (own(value) && positive(Number(value.id))) $select.append($("<option>").val(value.id).text(value.title || value.name || "گزینه " + value.id));
      });
      if (positive(Number(selected)) && !$select.find("option").filter(function () { return String(this.value) === String(selected); }).length) {
        $select.append($("<option>").val(selected).text("گزینه ذخیره‌شده " + selected));
      }
      $select.val(positive(Number(selected)) ? selected : "");
    }
    function draftFor(data, row) {
      if (source === "orders") {
        var payload = data.payload, display = payload && payload._display || {}, parcel = payload && payload.parcel, packaging = payload && payload.packaging;
        if (!own(payload) || !own(parcel) || !own(packaging) || !own(payload.receiver) || !Array.isArray(payload.items)) return null;
        if (native(row) && (!nativeIdentity(row) || display.market !== "basalam" || display.external_id !== row.basalam_parcel_id
          || typeof display.shipping_blocked_reason === "string" && display.shipping_blocked_reason.trim())) return null;
        return { weight: Number(parcel.weight), value_toman: display.value_toman != null ? display.value_toman : Number(parcel.value) / 10,
          box_type_id: Number(packaging.box_type_id), category_id: Number(parcel.category_id), provider_shop_id: Number(parcel.provider_shop_id),
          is_liquid: !!parcel.is_liquid, is_fragile: !!parcel.is_fragile, dimension_length: packaging.dimensions && packaging.dimensions.length || "",
          dimension_width: packaging.dimensions && packaging.dimensions.width || "", dimension_height: packaging.dimensions && packaging.dimensions.height || "",
          receiver: $.extend({}, payload.receiver), items: payload.items.slice(), contents: payload.contents || display.contents || "",
          weight_note: display.weight_confirmation && display.weight_confirmation.message || "وزن پیشنهادی را با وزن واقعی بسته بررسی کنید.",
          default_weight: !!(display.weight_confirmation && display.weight_confirmation.has_missing_item_weights), city_path: cityPath(display.city_path) };
      }
      var detail = data.detail, address = detail && detail.shipping_info && detail.shipping_info.receiver;
      if (!own(detail) || !own(address) || !Array.isArray(detail.items)) return null;
      return { weight: Number(detail.weight), value_toman: Number(detail.value) / 10, box_type_id: detail.box_type && Number(detail.box_type.id),
        category_id: Number(detail.category_id || data.parcel && data.parcel.category_id),
        receiver: { name: address.full_name, mobile: address.phone, address: address.address, postal_code: address.postal_code,
          city_id: address.city && address.city.id || address.city_id }, items: detail.items.slice(),
        box_title: detail.box_type && detail.box_type.title, category_title: detail.category && detail.category.title,
        dimension_length: detail.dimensions && detail.dimensions.length || "", dimension_width: detail.dimensions && detail.dimensions.width || "", dimension_height: detail.dimensions && detail.dimensions.height || "",
        weight_note: "وزن ذخیره‌شده بسته را بررسی کنید؛ برای اصلاح اطلاعات، ابتدا همین مرسوله را جداگانه ویرایش کنید.", city_path: address.city && address.city.title || "" };
    }
    function correctionLink(row, text) {
      var editUrl = new URL("admin.php", new URL(".", window.location.href));
      if (source === "orders") { editUrl.searchParams.set("page", "wc-orders"); editUrl.searchParams.set("action", "edit"); editUrl.searchParams.set("id", row.id); }
      else editUrl.searchParams.set("page", "salam_gate_parcels");
      return $("<a data-sgb-edit-order>").attr({ href: editUrl.href, target: "_blank", rel: "noopener noreferrer" }).text(text.replace(/\s*↗$/, ""));
    }
    function clearReturnState() {
      try {
        var state = window.history.state;
        if (!own(state) || !state.woosalamBulkReturn) return;
        state = $.extend({}, state); delete state.woosalamBulkReturn;
        window.history.replaceState(state, "");
      } catch (_) { /* History may be unavailable in restricted browser modes. */ }
    }
    function selectionState() {
      // History stores UI choices only. Recipient data, items, account tokens,
      // payment consent and financial authorization are never persisted here.
      return { version: 1, at: Date.now(), source: source, ids: selectionIds.slice(),
        connection_id: batch.connection_id, user_id: batch.user_id, stage: stage === "quotes" ? "quotes" : "review",
        method: method(), manualMethod: paymentMethodManual, scroll: Math.round($modal.find(".sgb-body").scrollTop()), rows: rows.map(function (row) {
          var fields = {};
          ["weight", "value_toman", "box_type_id", "category_id", "is_liquid", "is_fragile", "dimension_length", "dimension_width", "dimension_height"].forEach(function (key) {
            var value = row.draft && row.draft[key];
            if (typeof value === "boolean" || typeof value === "number" && Number.isFinite(value) || typeof value === "string" && value.length <= 40) fields[key] = value;
          });
          return { key: rowKey(row), id: row.id, parcel_id: row.parcel_id, market: row.market, checked: row.checked,
            state: row.state, fields: fields, confirmedWeight: row.confirmedWeight, hash: row.hash,
            verified: row.quoteVerified === row.hash, quotes: (row.quotes || []).filter(validQuote).slice(0, 30).map(function (quote) {
              var saved = {};
              ["hash_id", "total_rial", "base_cost_toman", "selected", "service_name", "service_type_label", "cost_rows", "discount_rows", "discount_percent"].forEach(function (key) { if (quote[key] !== undefined) saved[key] = quote[key]; });
              return saved;
            }) };
        }) };
    }
    function saveReturnState() {
      if (!batch || busy || selectionRunning || selectionQueue.length) return false;
      var state = selectionState();
      try {
        if (JSON.stringify(state).length > 180000) { state.stage = "review"; state.rows = []; }
        window.history.replaceState($.extend({}, own(window.history.state) ? window.history.state : {}, { woosalamBulkReturn: state }), "");
        return true;
      } catch (_) { return false; }
    }
    function returnedNotice() {
      $modal.find("[data-sgb-consent]").prop("checked", false);
      feedback("پس از ذخیره اصلاحات در تب سفارش، برای ادامه پرداخت «به‌روزرسانی سفارش‌ها» را بزنید.", "warning");
      controls();
    }
    function applyReturnState(saved) {
      returnNeedsRefresh = true;
      rows.forEach(function (row) {
        var previous = saved.rows.find(function (value) { return own(value) && value.key === rowKey(row) && value.id === row.id && value.market === row.market; });
        if (!previous || !row.draft || row.state === "blocked" || remembered(row)) return;
        if (own(previous.fields)) Object.keys(previous.fields).forEach(function (key) {
          if (!Object.prototype.hasOwnProperty.call(row.draft, key) || !/^(weight|value_toman|box_type_id|category_id|is_liquid|is_fragile|dimension_(length|width|height))$/.test(key)) return;
          var value = previous.fields[key];
          if (typeof value === "boolean" || typeof value === "number" && Number.isFinite(value) || typeof value === "string" && value.length <= 40) row.draft[key] = value;
        });
        row.$el.find("[data-sgb-field]").each(function () { $(this).val(row.draft[this.getAttribute("data-sgb-field")]); });
        row.confirmedWeight = previous.confirmedWeight === row.draft.weight ? previous.confirmedWeight : null;
        row.$el.find(".sgb-weight-confirm span").text("وزن نهایی " + fa(row.draft.weight) + " گرم را بررسی و تأیید می‌کنم.");
        if (previous.state === "quoted" && previous.parcel_id === row.parcel_id && Array.isArray(previous.quotes)
          && previous.quotes.length <= 30 && previous.quotes.some(function (quote) { return validQuote(quote) && quote.hash_id === previous.hash; })
          && showQuotes(row, { parcel_id: row.parcel_id, quotes: previous.quotes })) {
          row.hash = previous.hash; row.quoteVerified = previous.verified === true ? row.hash : null;
          row.$el.find("[data-sgb-quote-choice]").val(row.hash); quoteSummary(row);
          rowStatus(row, "نیاز به به‌روزرسانی", "warning");
        } else if (["error", "blocked"].includes(previous.state)) {
          rowError(row, "این مرسوله نیاز به بررسی و اصلاح دارد؛ پس از ذخیره، سفارش‌ها را به‌روزرسانی کنید.", previous.state === "blocked", false);
        }
        row.checked = previous.checked === true && row.state !== "blocked";
        row.$el.find("[data-sgb-row-select]").prop("checked", row.checked);
        refreshPackageSummary(row);
      });
      if ([20, 21].includes(saved.method)) {
        $modal.find("[name=sgb-payment]").prop("checked", false).filter("[value='" + saved.method + "']").prop("checked", true);
        paymentMethodManual = true;
      }
      stageTo(saved.stage === "quotes" ? "quotes" : "review"); returnedNotice();
      $modal.find(".sgb-body").scrollTop(integer(saved.scroll, 0, 100000) ? saved.scroll : 0);
    }
    function restoreReturnState() {
      var saved;
      try { saved = window.history.state && window.history.state.woosalamBulkReturn; } catch (_) { return; }
      if (!saved) return;
      if (!own(saved) || saved.version !== 1 || !["orders", "parcels"].includes(saved.source)
        || !Array.isArray(saved.ids) || !saved.ids.length || saved.ids.length > maxItems || !saved.ids.every(positive)
        || !Array.isArray(saved.rows) || saved.rows.length > maxItems || typeof saved.connection_id !== "string" || !positive(saved.user_id)
        || !Number.isFinite(saved.at) || Date.now() - saved.at < 0 || Date.now() - saved.at > 1800000) { clearReturnState(); return; }
      if (!$modal.prop("hidden")) { if (!busy) { returnNeedsRefresh = true; returnedNotice(); } return; }
      open({ source: saved.source, ids: saved.ids, returnState: saved });
    }
    function rowStatus(row, text, kind, correction) {
      if (row.$el) row.$el.find("[data-sgb-row-status]").empty().append(correction ? correctionLink(row, text) : document.createTextNode(text)).attr("data-kind", kind || "info");
    }
    function rowError(row, text, isBlocked, rememberOutcome) {
      row.message = text; row.state = isBlocked ? "blocked" : "error";
      if (isBlocked) { if (rememberOutcome !== false) remember(row, true); row.checked = false; row.$el.find("[data-sgb-row-select]").prop("checked", false); }
      var $error = row.$el.find("[data-sgb-row-error]").empty().append($("<span class='sgb-error-message'>").text(text)).prop("hidden", !text);
      if (text && positive(row.id)) {
        $error.append(correctionLink(row, source === "orders" ? "بررسی / اصلاح سفارش ↗" : "بررسی در فهرست مرسوله‌ها ↗"));
        $modal.find("[data-sgb-edit-hint]").prop("hidden", false);
      }
      rowStatus(row, isBlocked ? "نیاز به بررسی وضعیت" : "نیاز به بررسی و اصلاح", isBlocked ? "warning" : "error", !!text && positive(row.id)); controls();
    }
    function renderRow(row) {
      var draft = row.draft, $row = $("<article class='sgb-row'>").attr({ "data-sgb-id": row.id, "data-sgb-key": rowKey(row), "data-sgb-market": row.market });
      row.$el = $row;
      var $checked = $("<input type='checkbox' data-sgb-row-select>").prop("checked", row.checked);
      var $head = $("<div class='sgb-row-head'>").append($("<label class='sgb-row-check'>").append($checked, $("<span>").text(row.label)),
        $("<div class='sgb-row-receiver'>").append($("<strong>").text(draft && draft.receiver.name || row.receiver_name || "گیرنده نامشخص"),
          $("<small>").text(draft && draft.city_path || row.contents || "")), $("<span class='sgb-status' data-sgb-row-status>").text("در انتظار بررسی"));
      $row.append($head);
      if (native(row)) $row.append($("<div class='sgb-origin-note'>").append($("<span>").text("باسلام"),
        $("<small>").text("هزینه از مبلغ سفارش " + fa(row.basalam_order_id) + " کسر می‌شود.")));
      if (draft) {
        var $fields = $("<div class='sgb-row-fields'>");
        [["weight", "وزن با بسته‌بندی (گرم)", 50, 30000], ["value_toman", "ارزش مرسوله (تومان)", 2000, native(row) ? 500000000 : 1000000000]].forEach(function (field) {
          $fields.append($("<label>").append($("<span>").text(field[1]), $("<input type='number' inputmode='numeric'>").attr({ "data-sgb-field": field[0], min: field[2], max: field[3], step: native(row) && field[0] === "value_toman" ? .1 : 1, "aria-label": field[1] + " " + row.label }).val(draft[field[0]]),
            $("<small class='sgb-field-error' hidden>").attr("data-sgb-field-error", field[0])));
        });
        [["box_type_id", "بسته‌بندی", references.box_types, draft.box_title], ["category_id", "نوع مرسوله", references.categories, draft.category_title]].forEach(function (field) {
          var $select = $("<select>").attr({ "data-sgb-field": field[0], "aria-label": field[1] + " " + row.label });
          optionList($select, field[2], draft[field[0]], field[3] || "انتخاب کنید");
          if (source === "parcels" && field[3]) $select.find("option:selected").text(field[3]);
          $fields.append($("<label>").append($("<span>").text(field[1]), $select, $("<small class='sgb-field-error' hidden>").attr("data-sgb-field-error", field[0])));
        });
        $row.append($fields,
          $("<label class='sgb-weight-confirm'>").append($("<input type='checkbox' data-sgb-confirm-weight>"), $("<span>").text("وزن نهایی " + fa(draft.weight) + " گرم را بررسی و تأیید می‌کنم.")));
        var $details = $("<details class='sgb-row-details'>").append($("<summary>").text("اطلاعات گیرنده و اقلام")), $receiver = $("<dl>");
        [["weight", "وزن بسته"], ["value_toman", "ارزش مرسوله"], ["box_type_id", "بسته‌بندی"], ["category_id", "نوع مرسوله"]].forEach(function (field) {
          $receiver.append($("<div>").attr("data-sgb-package-summary", field[0]).append($("<dt>").text(field[1]), $("<dd>")));
        });
        [["موبایل", draft.receiver.mobile], ["کد پستی", draft.receiver.postal_code], ["نشانی", draft.receiver.address]].forEach(function (field) {
          $receiver.append($("<div>").append($("<dt>").text(field[0]), $("<dd>").text(field[1] || "—")));
        });
        var $items = $("<ul>"); draft.items.forEach(function (value) {
          if (own(value)) $items.append($("<li>").text((value.title || "کالا") + " × " + fa(value.quantity || 1)));
        });
        if (Number(draft.box_type_id) === 37) [["طول", draft.dimension_length], ["عرض", draft.dimension_width], ["ارتفاع", draft.dimension_height]].forEach(function (dimension) {
          $receiver.append($("<div>").append($("<dt>").text(dimension[0] + " بسته (سانتی‌متر)"), $("<dd>").text(dimension[1] ? fa(dimension[1]) : "ثبت نشده")));
        });
        $row.append($details.append($receiver, $items));
      }
      $row.append($("<div class='sgb-row-error' data-sgb-row-error role='status' hidden>"), $("<div class='sgb-row-quotes' data-sgb-row-quotes hidden>"));
      $modal.find("[data-sgb-rows]").append($row);
      refreshPackageSummary(row);
      if (!draft || row.state === "blocked") rowError(row, row.message || "اطلاعات این مرسوله دریافت نشد؛ آن را جداگانه بررسی کنید.", true, false);
    }
    function refreshPackageSummary(row) {
      if (!row.draft || !row.$el) return;
      var values = { weight: fa(row.draft.weight) + " گرم", value_toman: money(Number(row.draft.value_toman) * 10) };
      ["box_type_id", "category_id"].forEach(function (key) {
        values[key] = row.$el.find("[data-sgb-field='" + key + "'] option:selected").text() || "—";
      });
      Object.keys(values).forEach(function (key) { row.$el.find("[data-sgb-package-summary='" + key + "'] dd").text(values[key]); });
    }
    function validation(row, display) {
      var errors = {}, draft = row.draft;
      if (!draft) return false;
      if (!integer(draft.weight, 50, 30000)) errors.weight = "وزن باید بین ۵۰ تا ۳۰٬۰۰۰ گرم باشد.";
      if (source === "orders") {
        var value = raw(draft.value_toman), maxValue = native(row) ? 500000000 : 1000000000;
        if (!(native(row) ? /^\d+(?:\.\d)?$/.test(value) && Number(value) >= 2000 && Number(value) <= maxValue : integer(number(value), 2000, maxValue)))
          errors.value_toman = native(row) ? "ارزش مرسوله باید بین ۲٬۰۰۰ تا ۵۰۰٬۰۰۰٬۰۰۰ تومان باشد." : "ارزش مرسوله باید بین ۲٬۰۰۰ تا ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد.";
      }
      if (source === "orders" && !positive(Number(draft.box_type_id))) errors.box_type_id = "بسته‌بندی را انتخاب کنید.";
      if (source === "orders" && !positive(Number(draft.category_id))) errors.category_id = "نوع مرسوله را انتخاب کنید.";
      if (source === "orders" && Number(draft.box_type_id) === 37 && !["length", "width", "height"].every(function (key) {
        var dimension = number(draft["dimension_" + key]); return native(row) ? integer(dimension, 10, 100) : positive(dimension);
      })) errors.box_type_id = "ابعاد این بسته معتبر نیست؛ آن را از ارسال تکی تکمیل کنید.";
      if (display) {
        row.$el.find("[data-sgb-field]").each(function () {
          var name = this.getAttribute("data-sgb-field"), text = errors[name] || "";
          $(this).attr("aria-invalid", text ? "true" : "false"); row.$el.find("[data-sgb-field-error='" + name + "']").text(text).prop("hidden", !text);
        });
      }
      return Object.keys(errors).length === 0;
    }
    function collect(row) {
      var draft = row.draft, receiver = draft.receiver;
      return { weight: String(draft.weight), weight_confirmed: "1", confirmed_weight_grams: String(row.confirmedWeight),
        value_toman: String(draft.value_toman), box_type_id: String(draft.box_type_id), category_id: String(draft.category_id),
        name: receiver.name || "", mobile: receiver.mobile || "", address: receiver.address || "", postal_code: receiver.postal_code || "", city_id: receiver.city_id || "",
        is_liquid: draft.is_liquid ? "1" : "", is_fragile: draft.is_fragile ? "1" : "", contents: draft.contents || "",
        dimension_length: draft.dimension_length, dimension_width: draft.dimension_width, dimension_height: draft.dimension_height };
    }
    function canonicalSame(row, canonical) {
      if (!own(canonical)) return false;
      var draft = row.draft;
      return ["weight", "value_toman", "box_type_id", "category_id"].every(function (key) { return Number(canonical[key]) === Number(draft[key]); })
        && !!canonical.is_liquid === !!draft.is_liquid && !!canonical.is_fragile === !!draft.is_fragile
        && (Number(draft.box_type_id) !== 37 || ["length", "width", "height"].every(function (key) { return Number(canonical["dimension_" + key]) === Number(draft["dimension_" + key]); }))
        && own(canonical.receiver) && ["name", "mobile", "address", "postal_code", "city_id"].every(function (key) {
          return String(canonical.receiver[key] == null ? "" : canonical.receiver[key]).trim() === String(draft.receiver[key] == null ? "" : draft.receiver[key]).trim();
        }) && Array.isArray(canonical.items) && canonical.items.length === draft.items.length
        && canonical.items.every(function (value, index) {
          var item = draft.items[index];
          return own(value) && own(item) && value.title === item.title && Number(value.quantity) === Number(item.quantity)
            && Number(value.net_weight) === Number(item.net_weight)
            && Number(value.price_toman == null ? value.price / 10 : value.price_toman) === Number(item.price_toman == null ? item.price / 10 : item.price_toman);
        });
    }
    function showQuotes(row, rates) {
      var quotes = validQuotes(rates, row.parcel_id);
      if (!quotes || !quotes.length) {
        rowError(row, Array.isArray(rates && rates.errors) && rates.errors.length && typeof rates.errors[0] === "string" ? rates.errors[0] : "نرخ قابل استفاده‌ای دریافت نشد؛ این مرسوله را جداگانه بررسی کنید.", false);
        return false;
      }
      row.quotes = quotes;
      row.hash = quotes.some(function (quote) { return quote.hash_id === row.preferredHash; }) ? row.preferredHash
        : quotes.slice().sort(function (a, b) { return a.total_rial - b.total_rial; })[0].hash_id;
      row.preferredHash = null;
      row.quoteVerified = null;
      row.state = "quoted"; row.message = "";
      var $select = $("<select data-sgb-quote-choice>").attr("aria-label", "روش ارسال " + row.label);
      quotes.forEach(function (quote) {
        var fare = typeof quote.base_cost_toman === "number" && Number.isFinite(quote.base_cost_toman) && quote.base_cost_toman >= 0 ? money(quote.base_cost_toman * 10) : "کرایه در جزئیات";
        $select.append($("<option>").val(quote.hash_id).text((quote.service_name || quote.service_type_label || "روش ارسال") + " — " + fare));
      });
      $select.val(row.hash);
      row.$el.find("[data-sgb-row-error]").empty().prop("hidden", true);
      row.$el.find("[data-sgb-row-quotes]").empty().prop("hidden", false).append($("<label>").text("روش ارسال"), $select,
        $("<div class='sgb-quote-summary'>").append($("<span>").text(native(row) ? "کسر از مبلغ همین سفارش" : "مبلغ نهایی قابل پرداخت"), $("<strong data-sgb-row-total>")),
        $("<p class='sgb-selection-status' data-sgb-selection-status role='status' hidden>"),
        $("<details class='sgb-quote-costs'>").append($("<summary>").text("جزئیات قیمت"), $("<dl data-sgb-row-costs>")));
      refreshPackageSummary(row); quoteSummary(row); rowStatus(row, "آماده پرداخت", "success"); return true;
    }
    function quoteSummary(row) {
      var quote = chosen(row), $costs = row.$el.find("[data-sgb-row-costs]").empty();
      row.$el.find("[data-sgb-row-total]").text(validQuote(quote) ? money(quote.total_rial) : "—");
      if (!validQuote(quote)) return;
      if (typeof quote.base_cost_toman === "number" && Number.isFinite(quote.base_cost_toman)) $costs.append($("<div>").append($("<dt>").text("کرایه پستی"), $("<dd>").text(money(quote.base_cost_toman * 10))));
      (Array.isArray(quote.cost_rows) ? quote.cost_rows : []).forEach(function (cost) {
        if (own(cost) && typeof cost.title === "string" && Number.isFinite(cost.amount_toman)) $costs.append($("<div>").append($("<dt>").text(cost.title), $("<dd>").text(money(cost.amount_toman * 10))));
      });
    }
    function acceptNativeSelection(row, data, hash) {
      var quotes = validQuotes(data && data.quotes, row.parcel_id);
      var approved = quotes && quotes.filter(function (quote) { return quote.selected; });
      if (!nativeIdentity(row) || !own(data) || data.parcel_id !== row.parcel_id
        || data.market !== undefined && data.market !== row.market
        || source === "parcels" && (data.can_pay !== true || data.payment_state !== "unpaid"
          || !Array.isArray(data.allowed_methods) || data.allowed_methods.length !== 1 || data.allowed_methods[0] !== 19)
        || !approved || approved.length !== 1 || approved[0].hash_id !== hash
        || source === "orders" && data.parcel_payload !== undefined && !canonicalSame(row, data.parcel_payload)) return false;
      row.quotes = quotes; row.quoteVerified = hash;
      row.$el.find("[data-sgb-quote-choice] option").each(function () {
        var current = quotes.find(function (quote) { return quote.hash_id === this.value; }, this);
        if (!current) { $(this).remove(); return; }
        $(this).text((current.service_name || current.service_type_label || "روش ارسال") + " — "
          + (Number.isFinite(current.base_cost_toman) ? money(current.base_cost_toman * 10) : "کرایه در جزئیات"));
      });
      quoteSummary(row); rowStatus(row, "آماده کسر از سفارش", "success"); return true;
    }
    function selectNative(row) {
      var sequence = epoch, hash = row.hash;
      row.quoteVerified = null;
      row.$el.find("[data-sgb-selection-status]").text("در حال تأیید هزینه همین سفارش…").prop("hidden", false);
      return item(row, "select", { hash: hash, weight_confirmed: "1", confirmed_weight_grams: row.confirmedWeight })
        .done(function (data) {
          if (sequence !== epoch || row.hash !== hash) return;
          if (!acceptNativeSelection(row, data, hash)) {
            rowError(row, "هزینه یا ارتباط این سفارش تأیید نشد؛ آن را جداگانه بررسی کنید. پرداختی انجام نشد.", false);
          }
        }).fail(function (failure) {
          if (sequence !== epoch || row.hash !== hash || failure.stale) return;
          if (operationFailure(row, failure, "select")) batchHalted = true;
        }).always(function () {
          if (sequence === epoch && row.hash === hash) row.$el.find("[data-sgb-selection-status]").prop("hidden", true);
        });
    }
    function queueNativeSelection(row) {
      row.quoteVerified = null;
      if (!selectionQueue.includes(row)) selectionQueue.push(row);
      function next() {
        if (selectionRunning || busy || batchHalted || !selectionQueue.length) { controls(); return; }
        var current = selectionQueue.shift(), sequence = epoch;
        if (current.state !== "quoted") { next(); return; }
        selectionRunning = true; controls();
        selectNative(current).always(function () {
          if (sequence !== epoch) return;
          selectionRunning = false; controls(); next();
        });
      }
      next(); controls();
    }
    function changed(row) {
      row.quotes = []; row.hash = null; row.quoteVerified = null; row.confirmedWeight = null; row.state = "review";
      refreshPackageSummary(row);
      row.$el.find("[data-sgb-row-quotes]").empty().prop("hidden", true);
      row.$el.find("[data-sgb-confirm-weight]").prop("checked", false);
      row.$el.find(".sgb-weight-confirm span").text("وزن نهایی " + fa(row.draft.weight) + " گرم را بررسی و تأیید می‌کنم.");
      rowStatus(row, "نیاز به تأیید وزن"); $modal.find("[data-sgb-consent]").prop("checked", false); validation(row, true); controls();
    }
    function operationFailure(row, failure, operation) {
      var data = failure && failure.data, uncertain = !failure || failure.transport || financialError(data);
      var auth = sessionError(data), short = funds(data);
      var text = errorMessage(data, operation === "pay"
        ? "تأیید پرداخت دریافت نشد؛ وضعیت این مرسوله را بررسی کنید و دوباره پرداخت نکنید."
        : operation === "prepare" ? "اطلاعات این مرسوله دریافت نشد؛ آن را جداگانه بررسی کنید."
          : "نتیجه این درخواست تأیید نشد؛ وضعیت مرسوله را در فهرست بررسی کنید و درخواست را تکرار نکنید.");
      // Never promote a generic transport failure to a definite financial failure.
      var explicitlyNotDispatched = data && data.request_dispatched === false && !financialError(data);
      var terminal = operation === "prepare" ? false : operation === "pay" ? !explicitlyNotDispatched
        : uncertain || (!definite(data) && !short);
      if (explicitlyNotDispatched) remember(row, false);
      rowError(row, text, terminal, !explicitlyNotDispatched);
      if (operation !== "prepare") event(row);
      return terminal || auth || short;
    }
    function clearPreparationTimers() {
      preparationTimers.forEach(function (timer) { window.clearTimeout(timer); }); preparationTimers = [];
    }
    function laterPreparation(delay, action) {
      var sequence = epoch;
      preparationTimers.push(window.setTimeout(function () { if (sequence === epoch) action(); }, Math.max(0, delay)));
    }
    function preparationTask(name, state, text) {
      var $task = $modal.find("[data-sgb-task='" + name + "']");
      $task.attr("data-state", state).find("[data-sgb-task-status]").text(text);
    }
    function numberPreparationTasks() {
      var taskIndex = 0;
      $modal.find("[data-sgb-task]").each(function () { if (!this.hidden) $(this).find(".sgb-task-number").text(fa(++taskIndex)); });
    }
    function preparationPanel(show) {
      $modal.find(".sgb-dialog").toggleClass("is-preparing", show);
      $modal.find("[data-sgb-preparation-panel]").prop("hidden", !show);
      $modal.find(".sgb-body, .sgb-footer, .sgb-header").prop("inert", show);
      if (show) $modal.find(".sgb-preparation-dialog").trigger("focus");
    }
    function beginPreparation(count) {
      clearPreparationTimers(); preparationFailed = false;
      preparationTask("rates", "active", "در حال دریافت نرخ‌ها…");
      preparationTask("discount", "waiting", "در انتظار نرخ‌ها"); preparationTask("done", "waiting", "در انتظار");
      $modal.find("[data-sgb-preparation-title]").text("آماده‌سازی ارسال");
      $modal.find("[data-sgb-preparation-caption]").text("دریافت نرخ " + fa(count) + " مرسوله");
      $modal.find("[data-sgb-discount-label]").text("در حال اعمال تخفیف ویژه سلام‌گیت");
      $modal.find("[data-sgb-funding-task]").prop("hidden", !selectedRows().some(native));
      preparationTask("funding", "waiting", "در انتظار نرخ تأییدشده");
      numberPreparationTasks();
      $modal.find("[data-sgb-preparation-panel]").attr({ "data-state": "working", "data-phase": "checklist" });
      $modal.find("[data-sgb-checklist]").prop("hidden", false);
      $modal.find("[data-sgb-preparation-error], [data-sgb-preparation-dismiss]").prop("hidden", true);
      preparationPanel(true);
    }
    function finishPreparation(list, stopped) {
      var usable = list.filter(function (row) { return row.state === "quoted" && validQuote(chosen(row)) && (!native(row) || row.quoteVerified === row.hash); });
      if (stopped) batchHalted = true;
      if (!usable.length || stopped) {
        preparationFailed = true;
        var message = stopped ? "دریافت نرخ متوقف شد؛ وضعیت موارد مشخص‌شده را بررسی کنید." : "نرخ قابل استفاده‌ای برای مرسوله‌های انتخاب‌شده دریافت نشد؛ موارد مشخص‌شده را بررسی کنید.";
        laterPreparation(0, function () {
          busy = ""; $modal.find("[data-sgb-preparation-panel]").attr({ "data-state": "error", "data-phase": "checklist" });
          $modal.find("[data-sgb-checklist]").prop("hidden", false);
          preparationTask("rates", "error", "نیاز به بررسی"); preparationTask("discount", "waiting", "تأیید نشد");
          preparationTask("done", "waiting", "تکمیل نشده");
          preparationTask("funding", "waiting", "تأیید نشد؛ پرداختی انجام نشده");
          $modal.find("[data-sgb-preparation-error]").text(message).prop("hidden", false);
          $modal.find("[data-sgb-preparation-dismiss]").text("بررسی مرسوله‌ها").prop("hidden", false).trigger("focus");
          feedback(message, "warning"); controls();
        });
        return;
      }
      // Continue with verified rates only. Keep unsuccessful rows visible for
      // review, but never include them in the total or the payment consent.
      var unavailable = list.filter(function (row) { return !usable.includes(row); });
      unavailable.forEach(function (row) {
        row.checked = false; row.$el.find("[data-sgb-row-select]").prop("checked", false);
      });
      $modal.find("[data-sgb-consent]").prop("checked", false);
      $modal.find("[data-sgb-funding-task]").prop("hidden", !usable.some(native));
      numberPreparationTasks();
      var notice = unavailable.length ? fa(unavailable.length) + " مرسوله بدون نرخ تأییدشده از پرداخت گروهی کنار گذاشته شد؛ نرخ "
        + fa(usable.length) + " مرسوله آماده است." : "";
      if (rows.some(function (row) { return row.needsWeightReview; })) notice += (notice ? " " : "") + "وزن تغییرکرده را در همین مرحله تأیید کنید تا نرخ آن دریافت شود.";
      var discount = usable.some(function (row) { return row.quotes.some(function (quote) {
        return Number(quote.discount_percent) > 0 || Array.isArray(quote.discount_rows) && quote.discount_rows.some(function (cost) { return own(cost) && Number(cost.amount_toman) < 0; });
      }); });
      $modal.find("[data-sgb-preparation-panel]").attr("data-phase", "checklist");
      $modal.find("[data-sgb-checklist]").prop("hidden", false);
      $modal.find("[data-sgb-preparation-caption]").text("آماده‌سازی نرخ‌های دریافتی سلام‌گیت");
      preparationTask("rates", "complete", unavailable.length ? "نرخ " + fa(usable.length) + " از " + fa(list.length) + " مرسوله آماده است" : "قیمت‌ها آماده‌اند");
      preparationTask("funding", "complete", "هزینه هر مرسوله از سفارش خودش؛ کسر وجه پس از تأیید شما");
      // Complete all applicable checks from the rates already received.
      preparationTask("discount", "complete", discount ? "تخفیف در نرخ لحاظ شد" : "تخفیفی در پاسخ اعلام نشده");
      $modal.find("[data-sgb-discount-label]").text("بررسی تخفیف سلام‌گیت");
      preparationTask("done", "complete", "انجام شد"); $modal.find("[data-sgb-preparation-panel]").attr("data-state", "complete");
      $modal.find("[data-sgb-preparation-title]").text("نرخ‌ها آماده‌اند");
      busy = ""; feedback(notice, "warning"); stageTo("quotes");
      $modal.find(".sgb-body").scrollTop(0);
      // Let the browser paint every green check before dismissing the panel.
      // Rates/totals are ready immediately; this is one short completion view.
      laterPreparation(650, function () {
        preparationPanel(false);
        var firstChoice = $modal.find("[data-sgb-quote-choice]")[0];
        if (firstChoice) firstChoice.focus({ preventScroll: true });
      });
    }
    function progress(index, count, text) {
      $modal.find("[data-sgb-progress]").prop("hidden", false);
      $modal.find("[data-sgb-progress-text]").text(text + " · " + fa(index) + " از " + fa(count));
      $modal.find("[data-sgb-progress-bar]").attr("max", count || 1).val(index);
      $modal.find("[data-sgb-stop]").prop("hidden", busy !== "pay").prop("disabled", stopRequested);
    }
    function event(row) {
      $(document).trigger("salamgate:bulk-item-changed", [{ source: source, id: row.id, item_key: rowKey(row),
        basalam_parcel_id: row.basalam_parcel_id || null, parcel_id: row.parcel_id, state: row.state }]);
    }
    function result(stopped) {
      busy = ""; stopRequested = !!stopped; stageTo("results"); $modal.find("[data-sgb-progress]").prop("hidden", true);
      var paid = rows.filter(function (row) { return row.state === "paid"; }), unfinished = rows.filter(function (row) { return paymentSelection.includes(rowKey(row)) && row.state !== "paid"; });
      $modal.find("[data-sgb-results]").prop("hidden", false);
      $modal.find("[data-sgb-result-title]").text(paid.length ? fa(paid.length) + " مرسوله پرداخت و آماده ارسال شد" : "ارسال تکمیل نشد؛ وضعیت موارد زیر را بررسی کنید");
      $modal.find("[data-sgb-result-text]").text(unfinished.length ? fa(unfinished.length) + " مرسوله تکمیل نشد؛ دلیل هر مورد در کارت آن نوشته شده است. پرداخت‌های موفق محفوظ‌اند؛ ادامه را از فهرست مرسوله‌ها انجام دهید."
        : "کد رهگیری و چاپ لیبل مرسوله‌های پرداخت‌شده از فهرست مرسوله‌ها در دسترس است.");
      var detail = { source: source, batch_id: batch.batch_id, paid_ids: paid.map(function (row) { return row.id; }), parcel_ids: paid.map(function (row) { return row.parcel_id; }),
        results: rows.map(function (row) { return { id: row.id, item_key: rowKey(row), basalam_parcel_id: row.basalam_parcel_id || null,
          parcel_id: row.parcel_id, state: row.state, message: row.message || "" }; }), stopped: !!stopped };
      $(document).trigger("salamgate:bulk-complete", [detail]);
      notify(paid.length ? "پرداخت " + fa(paid.length) + " مرسوله تأیید شد." : "روند ارسال متوقف شد؛ وضعیت مرسوله‌ها را بررسی کنید.", paid.length ? "success" : "warning");
    }
    function prepareQuotes(options) {
      var refreshing = own(options) && options.refresh === true;
      if (busy || batchHalted || returnNeedsRefresh || stage !== (refreshing ? "quotes" : "review")) return;
      var list = refreshing ? options.rows : selectedRows();
      if (!list.length || !list.every(function (row) { return validation(row, true) && row.confirmedWeight === row.draft.weight && row.state !== "blocked"; })) {
        feedback("اطلاعات و وزن همه بسته‌های انتخاب‌شده را بررسی و تأیید کنید.", "error"); return;
      }
      busy = "quotes"; stopRequested = false; feedback(""); controls(); beginPreparation(list.length);
      var sequence = epoch, index = 0, stopped = false;
      function next() {
        if (sequence !== epoch) return;
        if (stopped || index >= list.length) {
          finishPreparation(list, stopped); return;
        }
        var row = list[index++]; rowStatus(row, "در حال دریافت نرخ…");
        var progressText = "دریافت نرخ " + fa(index) + " از " + fa(list.length) + " مرسوله";
        $modal.find("[data-sgb-preparation-caption]").text(progressText);
        preparationTask("rates", "active", progressText);
        var selectedNative = null;
        item(row, source === "orders" ? "upsert" : "quotes", source === "orders" ? collect(row) : {})
          .done(function (data) {
            if (!positive(data.parcel_id) || row.parcel_id && data.parcel_id !== row.parcel_id && !(data.replaces_parcel_id === row.parcel_id && data.requires_quote_selection === true)
              || source === "parcels" && data.market !== row.market) {
              stopped = operationFailure(row, {}, "upsert"); return;
            }
            row.parcel_id = data.parcel_id; event(row);
            if (source === "parcels" && (data.can_pay !== true || data.payment_state !== "unpaid")) {
              stopped = operationFailure(row, { data: data }, "quotes"); return;
            }
            if (source === "orders" && data.parcel_payload !== undefined && !canonicalSame(row, data.parcel_payload)) {
              rowError(row, "اطلاعات بسته روی سرور تغییر کرده است؛ این مرسوله را جداگانه بررسی کنید. پرداختی انجام نشد.", false); return;
            }
            if (showQuotes(row, data.quotes) && native(row)) {
              preparationTask("funding", "active", "تأیید هزینه سفارش " + fa(row.basalam_order_id));
              selectedNative = selectNative(row).fail(function () { stopped = batchHalted || row.state === "blocked"; });
            }
          }).fail(function (failure) { if (!failure.stale) stopped = operationFailure(row, failure, source === "orders" ? "upsert" : "quotes"); })
          .always(function () { if (selectedNative) selectedNative.always(next); else next(); });
      }
      next();
    }
    function paymentRecovery(row, data) {
      remember(row, false); row.state = "quoted"; row.checked = true;
      row.message = typeof data.message === "string" && /[\u0600-\u06ff]/.test(data.message)
        ? data.message.replace(/بسالم/g, "باسلام") : "موجودی کافی نیست؛ روش پرداخت را تغییر دهید.";
      row.$el.find("[data-sgb-row-select]").prop("checked", true);
      // The batch-level message plus this row's badge identify the shortage;
      // do not repeat the same paragraph in every pricing card.
      row.$el.find("[data-sgb-row-error]").empty().prop("hidden", true);
      rowStatus(row, "موجودی ناکافی", "warning");
      $modal.find("[data-sgb-consent]").prop("checked", false);
      busy = ""; stopRequested = false; $modal.find("[data-sgb-progress]").prop("hidden", true); stageTo("quotes");
      var paid = rows.filter(function (value) { return value.state === "paid"; }).length;
      feedback(row.label + ": " + row.message + (paid ? " " + fa(paid) + " مرسوله قبلاً پرداخت شده و از ادامه گروه کنار گذاشته شد." : ""), "warning");
      event(row); readBalances();
      $modal.find(".sgb-body").scrollTop(0);
      $modal.find("[name=sgb-payment]:checked").trigger("focus");
    }
    function pay() {
      if (busy || batchHalted || returnNeedsRefresh || stage !== "quotes" || !ready() || !$modal.find("[data-sgb-consent]").prop("checked")) return;
      var sum = total(), websiteSum = total(function (row) { return !native(row); });
      var hasWebsite = selectedRows().some(function (row) { return !native(row); });
      if (sum === null || hasWebsite && !balanceCovers(method(), websiteSum)) return;
      // Consent is immutable for the whole sequence; changed fields/amounts can never authorize a charge.
      var consent = selectedRows().map(function (row) { var quote = chosen(row); return { row: row, parcel: row.parcel_id, hash: quote.hash_id, total: quote.total_rial,
        method: native(row) ? 19 : method(), weight: row.confirmedWeight, key: rowKey(row), invoice: row.basalam_order_id, native: row.basalam_parcel_id }; });
      paymentSelection = paymentSelection.concat(consent.map(function (pin) { return pin.key; })).filter(function (id, i, ids) { return ids.indexOf(id) === i; });
      var sequence = epoch, index = 0, stopped = false, recovering = false;
      busy = "pay"; stopRequested = false; feedback(""); controls();
      function next() {
        if (sequence !== epoch || recovering) return;
        if (stopped || stopRequested || index >= consent.length) { result(stopped || stopRequested); return; }
        var pin = consent[index++], row = pin.row;
        rowStatus(row, "در حال تأیید نرخ…"); progress(index, consent.length, "پرداخت مرسوله‌ها");
        item(row, "select", { hash: pin.hash, weight_confirmed: "1", confirmed_weight_grams: pin.weight }).done(function (data) {
          var quotes = validQuotes(data.quotes, pin.parcel), approved = quotes && quotes.filter(function (quote) { return quote.selected === true; });
          if (data.parcel_id !== pin.parcel || source === "parcels" && (data.market !== row.market || data.can_pay !== true || data.payment_state !== "unpaid"
            || !Array.isArray(data.allowed_methods) || data.allowed_methods.indexOf(pin.method) === -1)
            || native(row) && (!nativeIdentity(row) || rowKey(row) !== pin.key || row.basalam_order_id !== pin.invoice || row.basalam_parcel_id !== pin.native)
            || !approved || approved.length !== 1 || approved[0].hash_id !== pin.hash || approved[0].total_rial !== pin.total) {
            rowError(row, "روش یا مبلغ ارسال تغییر کرده است؛ پرداخت انجام نشد. نرخ تازه را جداگانه بررسی کنید.", false); stopped = true; next(); return;
          }
          if (source === "orders" && data.parcel_payload !== undefined && !canonicalSame(row, data.parcel_payload)) {
            rowError(row, "اطلاعات بسته روی سرور تغییر کرده است؛ پرداخت انجام نشد. مرسوله را جداگانه بررسی کنید.", false); stopped = true; next(); return;
          }
          if (row.draft.weight !== pin.weight || row.confirmedWeight !== pin.weight || stopRequested) { stopped = true; next(); return; }
          remember(row, true); rowStatus(row, "در حال پرداخت…");
          item(row, "pay", { method: pin.method, expected_hash: pin.hash, expected_total_rial: pin.total,
            weight_confirmed: "1", confirmed_weight_grams: pin.weight }).done(function (receipt) {
            if (receipt.parcel_id !== pin.parcel || typeof receipt.confirmed !== "boolean" || !integer(receipt.status_id, 0, 12)
              || receipt.confirmed && ![8, 9, 10, 11, 12].includes(receipt.status_id)) {
              stopped = operationFailure(row, {}, "pay"); return;
            }
            if (!receipt.confirmed) { stopped = true; rowError(row, "پرداخت ثبت شده و تأیید نهایی در حال بررسی است؛ دوباره پرداخت نکنید.", true); return; }
            remember(row, false); row.state = "paid"; row.checked = false; row.$el.find("[data-sgb-row-select]").prop("checked", false);
            row.message = "پرداخت هزینه ارسال تأیید شد."; rowStatus(row, "پرداخت شد؛ آماده ارسال", "success");
            row.$el.find("[data-sgb-row-error]").empty().prop("hidden", true);
            if (typeof receipt.tracking_code === "string" && receipt.tracking_code) row.$el.find("[data-sgb-row-quotes]").append($("<p>").text("کد رهگیری: " + receipt.tracking_code));
            event(row);
          }).fail(function (failure) {
            if (failure.stale) return;
            if (!failure.transport && recoverableFunds(failure.data, pin)) {
              recovering = true; paymentRecovery(row, failure.data); return;
            }
            // Stop the sequence on any failed payment. No retry is scheduled.
            operationFailure(row, failure, "pay"); stopped = true;
          }).always(next);
        }).fail(function (failure) {
          if (failure.stale) return; operationFailure(row, failure, "select"); stopped = true; next();
        });
      }
      next();
    }
    function close() {
      if (busy && busy !== "bootstrap" || selectionRunning) return false;
      ++epoch; clearPreparationTimers(); preparationPanel(false); preparationFailed = false;
      clearReturnState(); returnNeedsRefresh = false; refreshCheckpoint = null;
      busy = ""; batch = null; rows = []; selectionQueue = []; selectionRunning = false; $modal.prop("hidden", true); document.body.classList.remove("sgb-modal-open");
      if (trigger && document.contains(trigger)) $(trigger).trigger("focus"); return true;
    }
    function open(options) {
      if (busy || !$modal.prop("hidden") || !own(options) || ["orders", "parcels"].indexOf(options.source) === -1 || !Array.isArray(options.ids)) return false;
      var individual = $("#salam-gate-parcel-modal, #sg-order-list-native-modal, #sg-order-preview-modal, #sgp-checkout-modal").filter(function () {
        return !this.hidden && $(this).css("display") !== "none" || this.getAttribute("data-sg-financial-busy") === "true";
      });
      if (individual.length) { notify("ابتدا پنجره فعلی مرسوله را ببندید و منتظر پایان عملیات آن بمانید.", "error"); return false; }
      var ids = options.ids.map(number).filter(positive).filter(function (id, index, list) { return list.indexOf(id) === index; });
      if (!ids.length || ids.length > maxItems) { notify("بین ۱ تا " + fa(maxItems) + " مورد را برای ارسال گروهی انتخاب کنید.", "error"); return false; }
      source = options.source; trigger = document.activeElement; openingNonce = window.basalam_ajax && window.basalam_ajax.nonce || "";
      selectionIds = ids.slice();
      var sequence = ++epoch; rows = []; batch = null; balances = {}; references = {}; paymentSelection = []; batchHalted = false; busy = "bootstrap";
      selectionQueue = []; selectionRunning = false; paymentMethodManual = false; returnNeedsRefresh = false;
      refreshCheckpoint = options.refreshState || null;
      clearPreparationTimers(); preparationPanel(false); preparationFailed = false;
      $modal.prop("hidden", false).find("[data-sgb-loading]").prop("hidden", false);
      $modal.find("[data-sgb-content], [data-sgb-results], [data-sgb-progress], [data-sgb-exclusions], [data-sgb-edit-hint]").prop("hidden", true);
      $modal.find("[data-sgb-rows], [data-sgb-excluded-list]").empty(); $modal.find("[data-sgb-consent]").prop("checked", false);
      $modal.find("[data-sgb-stop]").text("توقف پس از این مرسوله");
      $modal.find("[name=sgb-payment][value='20']").prop("checked", true); feedback(""); stageTo(options.refreshState ? "quotes" : "review");
      if (options.refreshState) {
        beginPreparation(options.refreshState.rows.length);
        preparationTask("rates", "active", "بررسی وضعیت و اطلاعات تازه سفارش‌ها…");
      }
      document.body.classList.add("sgb-modal-open"); $modal.find("[data-sgb-close]").trigger("focus");
      var bootstrap = request("salam_gate_bulk_bootstrap", { source: source, ids: ids }, true);
      var refs = request("salam_gate_send_bootstrap", {}, true).done(function (data) { references = data; });
      // Balances authorize payment only; a slow wallet must not block review.
      readBalances();
      $.when(bootstrap, refs).done(function (data) {
        if (sequence !== epoch) return;
        if (!own(data) || typeof data.batch_id !== "string" || !data.batch_id || typeof data.connection_id !== "string" || !data.connection_id
          || !positive(data.user_id) || data.source !== source || !Array.isArray(data.rows)) {
          failOpen("پاسخ آماده‌سازی معتبر نیست؛ صفحه را تازه کنید. هیچ ثبت یا پرداختی انجام نشد."); return;
        }
        var seen = Object.create(null), represented = Object.create(null);
        if (data.rows.length < ids.length || data.rows.length > maxItems || !data.rows.every(function (row) {
          if (!own(row) || !positive(row.id) || !ids.includes(row.id) || typeof row.eligible !== "boolean") return false;
          // Legacy unsupported Basalam rows are retained as exclusions, never
          // silently upgraded to a native financial identity.
          var key = row.item_key || source + ":" + row.id;
          if (typeof key !== "string" || seen[key] || native(row) && row.eligible && !nativeIdentity(row)
            || !native(row) && key !== source + ":" + row.id) return false;
          seen[key] = true; represented[row.id] = true; return true;
        }) || !ids.every(function (id) { return represented[id]; })) {
          failOpen("شناسه‌های پاسخ با انتخاب شما تطبیق ندارند؛ صفحه را تازه کنید. هیچ پرداختی انجام نشد."); return;
        }
        batch = data;
        var previousContext = options.returnState || options.refreshState;
        if (previousContext && (data.connection_id !== previousContext.connection_id || data.user_id !== previousContext.user_id)) {
          failOpen("حساب یا کاربر متصل تغییر کرده است؛ سفارش‌ها را دوباره انتخاب کنید. هیچ ثبت یا پرداختی انجام نشد."); return;
        }
        var excluded = data.rows.filter(function (row) { return !row.eligible; });
        excluded.forEach(function (row) { $modal.find("[data-sgb-excluded-list]").append($("<li>").text((row.label || "#" + row.id) + ": " + (row.message || "این مورد قابل ارسال نیست."))); });
        $modal.find("[data-sgb-exclusions]").prop("hidden", !excluded.length);
        $modal.find("[data-sgb-excluded-count]").text(fa(excluded.length) + " مورد قابل ارسال نیست — مشاهده دلیل");
        rows = data.rows.filter(function (row) { return row.eligible; }).map(function (row) {
          return $.extend({}, row, { checked: true, state: "review", confirmedWeight: null, label: row.label || "#" + row.id, quotes: [], hash: null, quoteVerified: null });
        });
        $modal.find("[data-sgb-common]").prop("hidden", source !== "orders" || !rows.some(function (row) { return !native(row); }));
        optionList($modal.find("[data-sgb-box-all]"), references.box_types, null, "بدون تغییر");
        optionList($modal.find("[data-sgb-category-all]"), references.categories, null, "بدون تغییر");
        var index = 0, active = 0, completed = 0;
        function preparedAll() {
          rows.forEach(renderRow);
          busy = ""; $modal.find("[data-sgb-loading]").prop("hidden", true); $modal.find("[data-sgb-content]").prop("hidden", false); controls();
          if (!rows.length) feedback("هیچ مرسوله قابل ارسالی در انتخاب شما نیست؛ دلایل بالا را بررسی کنید.", "warning");
          else if (options.returnState) applyReturnState(options.returnState);
          else if (options.refreshState) { prepareRefreshedQuotes(options.refreshState); return; }
          else if (options.refreshed) {
            if (rows.some(function (row) { return row.state === "blocked" || row.state === "error"; })) notify("وضعیت سفارش‌ها بررسی شد؛ موارد نیازمند اصلاح را بررسی کنید.", "warning");
            else notify("اطلاعات سفارش‌ها به‌روز شد؛ وزن بسته‌ها را دوباره تأیید کنید و نرخ جدید بگیرید.", "success");
          }
          if (options.refreshState) preparationPanel(false);
        }
        function prepareNext() {
          if (sequence !== epoch) return;
          // Only read-only preparation may overlap. Upsert, quote selection and
          // payment keep the server's sequential batch lease and stop rules.
          while (active < 3 && index < rows.length) prepareRow(rows[index++]);
        }
        function prepareRow(row) {
          active++;
          item(row, "prepare", options.refreshed ? { refresh_order_receiver: "1" } : {}).done(function (prepared) {
            row.draft = draftFor(prepared, row); row.parcel_id = prepared.parcel_id || row.parcel_id || prepared.detail && prepared.detail.id || null;
            if (prepared.existing && prepared.existing.paid === true || prepared.row && prepared.row.eligible === false) {
              row.state = "blocked"; row.message = "این مرسوله قبلاً پرداخت شده یا دیگر قابل پرداخت نیست؛ وضعیت را از فهرست بررسی کنید.";
            }
            if (remembered(row) && prepared.payment_failure_confirmed !== true) {
              row.state = "blocked"; row.message = "تأیید درخواست قبلی هنوز بررسی نشده است؛ از فهرست وضعیت را بررسی کنید و دوباره پرداخت نکنید.";
            } else if (prepared.payment_failure_confirmed === true) remember(row, false);
          }).fail(function (failure) { if (sequence === epoch) { row.draft = null; row.message = errorMessage(failure.data, "اطلاعات این مورد دریافت نشد؛ آن را جداگانه بررسی کنید."); } })
            .always(function () {
              if (sequence !== epoch) return;
              active--; completed++;
              if (completed === rows.length) preparedAll(); else prepareNext();
            });
        }
        if (rows.length) prepareNext(); else preparedAll();
      }).fail(function (failure) { if (sequence === epoch) failOpen(errorMessage(failure && failure.data, "دریافت اطلاعات انجام نشد؛ صفحه را تازه کنید و دوباره تلاش کنید. هیچ ثبت یا پرداختی انجام نشد.")); });
      return true;
    }
    function failOpen(text) {
      busy = ""; preparationPanel(false); $modal.find("[data-sgb-loading]").prop("hidden", true); $modal.find("[data-sgb-quote], [data-sgb-quote-hint]").prop("hidden", true); feedback(text, "error");
      if (stage === "quotes") $modal.find("[data-sgb-content]").prop("hidden", false);
      controls();
    }
    function prepareRefreshedQuotes(saved) {
      rows.forEach(function (row) {
        if (!row.draft || row.state === "blocked" || remembered(row)) return;
        var previous = saved.rows.find(function (value) { return value.key === rowKey(row) && value.id === row.id && value.market === row.market; });
        var samePackage = previous && previous.fields && ["weight", "box_type_id", "dimension_length", "dimension_width", "dimension_height"].every(function (key) {
          return Number(previous.fields[key] || 0) === Number(row.draft[key] || 0);
        });
        row.confirmedWeight = samePackage && previous.confirmedWeight === row.draft.weight ? row.draft.weight : null;
        row.checked = !previous || previous.checked || ["error", "blocked"].includes(previous.state);
        row.preferredHash = previous && previous.hash;
        if (!validation(row, true)) {
          row.checked = false; row.$el.find("[data-sgb-row-select]").prop("checked", false);
          rowError(row, "اطلاعات این بسته معتبر نیست؛ آن را اصلاح کنید و دوباره به‌روزرسانی کنید.", false); return;
        }
        if (row.confirmedWeight === null) {
          row.needsWeightReview = true; row.checked = false;
          rowStatus(row, "نیاز به تأیید وزن جدید", "warning");
        }
        row.$el.find("[data-sgb-row-select]").prop("checked", row.checked);
      });
      if (saved.manualMethod && [20, 21].includes(saved.method)) {
        paymentMethodManual = true;
        $modal.find("[name=sgb-payment]").prop("checked", false).filter("[value='" + saved.method + "']").prop("checked", true);
      }
      stageTo("quotes");
      var reviewed = rows.filter(function (row) { return row.draft && row.state !== "blocked" && row.confirmedWeight === row.draft.weight && validation(row, false); });
      if (reviewed.length) prepareQuotes({ refresh: true, rows: reviewed });
      else {
        preparationPanel(false);
        var message = rows.some(function (row) { return row.needsWeightReview; }) ? "وزن تازه بسته را در همین مرحله تأیید کنید تا نرخ ارسال دریافت شود." : "مرسوله قابل آماده‌سازی نیست؛ وضعیت موارد مشخص‌شده را بررسی کنید.";
        feedback(message, "warning"); notify(message, "warning");
        controls();
      }
    }
    function refreshSelection() {
      if (busy || selectionRunning || selectionQueue.length || $modal.prop("hidden") || !selectionIds.length) return;
      // A second-step refresh explicitly reruns preparation/rates after fresh
      // eligibility and weight checks, without authorizing any payment.
      var options = { source: source, ids: selectionIds.slice(), refreshed: true };
      if (stage === "quotes") options.refreshState = batch && rows.length ? selectionState() : refreshCheckpoint;
      if (close()) open(options);
    }
    $modal.on("click", "[data-sgb-close], [data-sgb-done]", close).on("click", function (event) { if (event.target === this) close(); });
    $modal.on("click", "[data-sgb-quote]", prepareQuotes).on("click", "[data-sgb-pay]", pay);
    $modal.on("click", "[data-sgb-preparation-dismiss]", function () {
      if (busy || !preparationFailed) return;
      preparationFailed = false; preparationPanel(false); stageTo("quotes");
      $modal.find(".sgb-body").scrollTop(0);
      ($modal.find("[data-sgb-back]").is(":enabled") ? $modal.find("[data-sgb-back]") : $modal.find("[data-sgb-close]")).trigger("focus");
    });
    $modal.on("click", "[data-sgb-back]", function () {
      if (busy || batchHalted || returnNeedsRefresh || selectionRunning) return; $modal.find("[data-sgb-consent]").prop("checked", false); stageTo("review"); feedback("");
    });
    $modal.on("click", "[data-sgb-balances]", function () { if (!busy && !this.disabled) readBalances(); });
    $modal.on("click", "[data-sgb-refresh]", refreshSelection);
    $modal.on("click auxclick", "[data-sgb-edit-order]", function (event) {
      if (event.button > 1) return;
      if (busy || selectionRunning || selectionQueue.length) { event.preventDefault(); return; }
      saveReturnState();
      returnNeedsRefresh = true; returnedNotice();
    });
    $modal.on("click", "[data-sgb-stop]", function () { if (busy === "pay") { stopRequested = true; $(this).prop("disabled", true).text("پس از بررسی همین مرسوله متوقف می‌شود"); } });
    $modal.on("change", "[name=sgb-payment], [data-sgb-consent]", function () { if (!busy) summary(); });
    $modal.on("change", "[name=sgb-payment]", function () { paymentMethodManual = true; if (!busy) $modal.find("[data-sgb-consent]").prop("checked", false); summary(); });
    $modal.on("input change", "[data-sgb-field]", function () {
      if (busy || batchHalted || returnNeedsRefresh || stage !== "review" || source !== "orders") return;
      var row = rowAt(this);
      if (!row || row.state === "blocked") return;
      var key = this.getAttribute("data-sgb-field"); row.draft[key] = key === "value_toman" ? raw(this.value) : number(this.value); changed(row);
    });
    $modal.on("change", "[data-sgb-confirm-weight], [data-sgb-row-select]", function () {
      if (busy || batchHalted || returnNeedsRefresh) return;
      var row = rowAt(this);
      if (!row || row.state === "blocked") return;
      if ($(this).is("[data-sgb-confirm-weight]")) {
        row.confirmedWeight = this.checked && validation(row, true) ? row.draft.weight : null;
        $(this).prop("checked", row.confirmedWeight !== null).closest(".sgb-weight-confirm").toggleClass("is-confirmed", row.confirmedWeight !== null);
        rowStatus(row, row.confirmedWeight !== null ? "اطلاعات تأیید شد" : "نیاز به تأیید وزن");
        if (stage === "quotes" && row.needsWeightReview && row.confirmedWeight !== null) {
          row.needsWeightReview = false; row.checked = true; refreshSelection(); return;
        }
      } else row.checked = this.checked;
      $modal.find("[data-sgb-consent]").prop("checked", false); controls();
    });
    $modal.on("change", "[data-sgb-box-all], [data-sgb-category-all]", function () {
      if (busy || batchHalted || returnNeedsRefresh || source !== "orders" || stage !== "review" || !this.value) return;
      var key = $(this).is("[data-sgb-box-all]") ? "box_type_id" : "category_id", value = number(this.value);
      selectedRows().forEach(function (row) { if (row.state !== "blocked" && !native(row)) { row.draft[key] = value; row.$el.find("[data-sgb-field='" + key + "']").val(value); changed(row); } });
    });
    $modal.on("change", "[data-sgb-quote-choice]", function () {
      if (busy || batchHalted || returnNeedsRefresh || stage !== "quotes") return;
      var row = rowAt(this);
      if (!row || row.state !== "quoted" || !row.quotes.some(function (quote) { return quote.hash_id === String(this.value); }, this)) return;
      row.hash = this.value; quoteSummary(row); $modal.find("[data-sgb-consent]").prop("checked", false);
      if (native(row)) queueNativeSelection(row); controls();
    });
    $(document).on("keydown.salamGateBulk", function (event) {
      if ($modal.prop("hidden")) return;
      if (event.key === "Escape") { event.preventDefault(); close(); }
      if (event.key === "Tab") {
        var $scope = $modal.find("[data-sgb-preparation-panel]").prop("hidden") === false ? $modal.find("[data-sgb-preparation-panel]") : $modal;
        var $focusable = $scope.find("button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled),summary").filter(function () {
          return !$(this).parents().addBack().filter(function () { return this.hidden || $(this).css("display") === "none"; }).length;
        });
        var first = $focusable[0], last = $focusable[$focusable.length - 1];
        if (!first) { event.preventDefault(); $modal.find(".sgb-preparation-dialog").trigger("focus"); return; }
        if (event.shiftKey && event.target === first || !event.shiftKey && event.target === last) { event.preventDefault(); $(event.shiftKey ? last : first).trigger("focus"); }
      }
    });
    $(window).on("beforeunload.salamGateBulk", function (event) {
      if (busy && busy !== "bootstrap" || selectionRunning) { event.preventDefault(); if (event.originalEvent) event.originalEvent.returnValue = ""; }
    });
    window.SalamGateBulkShipping = { open: open, close: close, isOpen: function () { return !$modal.prop("hidden"); } };
    $(window).on("pageshow.salamGateBulk", restoreReturnState);
    restoreReturnState();
    $(document).trigger("salamgate:bulk-ready");
  });
})(window, document, window.jQuery);
