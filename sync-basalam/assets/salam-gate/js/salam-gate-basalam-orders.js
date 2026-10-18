(function (window, $) {
  "use strict";
  if (!$) return;
  $(function () {
    var $picker = $("[data-sg-basalam-orders]").first();
    if (!$picker.length) return;
    var active = false, loading = false, selecting = false, epoch = 0, readEpoch = 0;
    var readRequest = null, readTicket = null, pages = [null], pageIndex = 0, nextCursor = null, query = "";
    var pendingImport = null, selected = null, retryPage = null, market = "";
    var lastReadAt = 0;
    var $preview = $("#sg-order-preview-modal"), previewRequest = null, previewEpoch = 0;
    var previewSelection = null, previewOpener = null, previewReady = false;
    function websiteSource() { return market === "website"; }

    function positiveId(value) {
      if (typeof value !== "number" && (typeof value !== "string" || !/^[1-9]\d*$/.test(value))) return null;
      var number = Number(value);
      return Number.isSafeInteger(number) && number > 0 ? number : null;
    }
    function digits(value) {
      return String(value || "").replace(/[۰-۹]/g, function (digit) { return String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)); })
        .replace(/[٠-٩]/g, function (digit) { return String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)); }).trim();
    }
    function fa(value) { return String(value).replace(/\d/g, function (digit) { return "۰۱۲۳۴۵۶۷۸۹"[Number(digit)]; }); }
    function text(value, fallback) { return typeof value === "string" && value.trim() ? value.trim() : fallback; }
    function message(data, fallback) {
      return data && typeof data.message === "string" && /[\u0600-\u06ff]/.test(data.message) ? data.message.replace(/بسالم/g, "باسلام") : fallback;
    }
    function feedback(value, kind, retry) {
      var floating = !!value && (kind === "error" || kind === "success") && typeof window.woosalamNotify === "function";
      $picker.find("[data-sg-bo-feedback]").prop("hidden", !value).attr("data-kind", kind || "info")
        .attr("role", floating && retry ? "group" : (kind === "error" ? "alert" : "status"))
        .toggleClass("sg-feedback-actions", floating && !!retry).toggleClass("sg-feedback-toast-only", floating && !retry);
      $picker.find("[data-sg-bo-message]").text(value || "").toggleClass("sg-feedback-toast-only", floating);
      $picker.find("[data-sg-bo-retry]").prop("hidden", !retry);
      if (floating && !(retry && window.WoosalamGateLoading)) window.woosalamNotify(value, kind);
    }
    function actionError(value) {
      feedback(value, "error", false);
    }
    function beginLoading(retry) {
      if (window.WoosalamGateLoading) return window.WoosalamGateLoading.begin($picker[0], "orders", retry);
      // The shared loader is normally available before ready callbacks. Keep
      // component coherence in legacy script-loading environments as well.
      $picker.attr("data-sg-load-state", "loading");
      $picker.children("[data-sg-load-content]").prop("hidden", true);
      $picker.children("[data-sg-load-indicator]").prop("hidden", false);
      function ready() {
        $picker.attr("data-sg-load-state", "ready");
        $picker.children("[data-sg-load-content]").prop("hidden", false);
        $picker.children("[data-sg-load-indicator]").prop("hidden", true);
      }
      return { ready: ready, fail: function () { ready(); } };
    }
    function isExactQuery(value) { return /^[0-9]+$/.test(value); }
    function controls() {
      var busy = loading || selecting;
      $picker.attr("aria-busy", busy ? "true" : "false");
      $picker.find("[data-sg-bo-search], [data-sg-bo-search-submit], [data-sg-bo-refresh], [data-sg-bo-retry]").prop("disabled", busy || !!pendingImport);
      $picker.find("[data-sg-bo-choice]").each(function () {
        var order = $(this).data("sg-order");
        $(this).prop("disabled", busy || !!pendingImport || !order || !order.requires_import || !order.eligible || !!order.local_order_id);
      });
      $picker.find("[data-sg-bo-prev]").prop("disabled", busy || !!pendingImport || pageIndex === 0);
      $picker.find("[data-sg-bo-next]").prop("disabled", busy || !!pendingImport || !nextCursor);
      $picker.find("[data-sg-bo-import-confirm], [data-sg-bo-import-cancel], [data-sg-bo-open-selected]").prop("disabled", selecting || loading);
    }
    function post(action, data) {
      var config = window.basalam_ajax;
      if (!config || typeof config.ajaxurl !== "string" || !config.ajaxurl || typeof config.nonce !== "string" || !config.nonce) return null;
      return $.ajax({ url: config.ajaxurl, type: "POST", dataType: "json", timeout: 45000,
        data: $.extend({ action: action, _wpnonce: config.nonce }, data || {}) });
    }
    function abortRead() {
      ++readEpoch;
      var old = readRequest;
      if (readTicket) readTicket.ready();
      readTicket = null;
      readRequest = null; loading = false;
      if (old && typeof old.abort === "function") old.abort();
    }
    function clearImport() {
      pendingImport = null;
      $picker.find("[data-sg-bo-import]").prop("hidden", true);
      $picker.find("[data-sg-bo-import-order]").text("");
    }
    function clearSelection() {
      selected = null;
      $picker.find("[data-sg-bo-selected]").prop("hidden", true);
      $picker.find("[data-sg-bo-selected-message]").text("");
      $picker.find("[data-sg-bo-order-link]").removeAttr("href");
    }
    function validCursor(value) { return value === null || (typeof value === "string" && value.length > 0 && value.length <= 2048 && !/[\x00-\x1f\x7f]/.test(value)); }
    function normalizeList(data) {
      if (!data || !Array.isArray(data.orders) || data.orders.length > 100 || !validCursor(data.next_cursor)) return null;
      var seen = Object.create(null), orders = [];
      for (var index = 0; index < data.orders.length; index++) {
        var item = data.orders[index], orderId = item && positiveId(item.order_id), parcelId = item && positiveId(item.parcel_id);
        if (!orderId || (!websiteSource() && (!parcelId || typeof item.requires_import !== "boolean")) || typeof item.eligible !== "boolean"
          || !Number.isSafeInteger(item.items_count) || item.items_count < 0
          || typeof item.title !== "string" || typeof item.status_title !== "string" || typeof item.recipient_name !== "string"
          || (item.blocked_reason != null && typeof item.blocked_reason !== "string")) return null;
        var key = orderId + ":" + parcelId;
        if (seen[key]) return null;
        seen[key] = true;
        var localId = positiveId(item.local_order_id);
        // Native invoice and parcel IDs are never Woo order IDs. A link needs
        // an explicit server-verified local mapping and a matching edit URL.
        var orderUrl = localId && (!websiteSource() || localId === orderId) ? safeOrderUrl(item.order_url, localId) : null;
        orders.push({ order_id: orderId, parcel_id: parcelId, title: item.title, status_title: item.status_title,
          recipient_name: item.recipient_name, items_count: item.items_count, eligible: item.eligible,
          requires_import: !websiteSource() && item.requires_import, blocked_reason: item.blocked_reason || "",
          local_order_id: localId, order_url: orderUrl, order_number: text(item.order_number, String(orderId)),
          shipping_title: text(item.shipping_title, "ارسال نشده"),
          shipping_tone: /^(pending|waiting|ready|sent|delivered|returned|cancelled|review|partial)$/.test(item.shipping_tone) ? item.shipping_tone : "pending" });
      }
      return { orders: orders, next_cursor: data.next_cursor };
    }
    function renderOrders(orders) {
      var $list = $picker.find("[data-sg-bo-list]").empty();
      orders.forEach(function (order) {
        var $card = $("<article class='sg-basalam-order' role='listitem'>").attr("data-eligible", order.eligible ? "true" : "false");
        $card.append($("<div class='sg-basalam-order__identity'>").append(
          $("<h5 class='sg-basalam-order__number'>").text("سفارش " + fa(order.order_number)),
          $("<span class='sg-basalam-order__status'>").text(text(order.status_title, "وضعیت نامشخص"))));
        var $details = $("<dl class='sg-basalam-order__details'>");
        [["گیرنده", text(order.recipient_name, "نام گیرنده ثبت نشده")], ["تعداد اقلام", fa(order.items_count)]].forEach(function (row) {
          $details.append($("<div>").append($("<dt>").text(row[0]), $("<dd>").text(row[1])));
        });
        $details.append($("<div class='sg-basalam-order__shipping'>").append(
          $("<dt>").text("وضعیت مرسوله"),
          $("<dd>").append($("<span class='sg-basalam-order__shipment'>").attr("data-tone", order.shipping_tone).text(order.shipping_title))));
        $card.append($details);
        var $action = $("<div class='sg-basalam-order__action'>"), $button;
        if (order.order_url) {
          // Viewing an existing order is read-only, including paid/cancelled
          // orders. Financial eligibility is checked inside its own workflow.
          $button = $("<button type='button' class='sg-button sg-button--secondary' data-sg-bo-view>")
            .attr({ "aria-haspopup": "dialog", "aria-controls": "sg-order-preview-modal" });
        } else {
          $button = $("<button type='button' class='sg-button sg-button--secondary' data-sg-bo-choice>");
          var note = order.requires_import && order.eligible ? "نیاز به افزودن به ووکامرس" : "سفارش متناظر در دسترس نیست";
          $action.append($("<span class='sg-basalam-order__import'>").text(note));
          if (!order.eligible || !order.requires_import || order.local_order_id) $button.attr("title", text(order.blocked_reason, "ارتباط این سفارش با ووکامرس را بررسی کنید."));
        }
        var detailsOnly = !order.eligible || ["sent", "delivered", "returned", "cancelled"].indexOf(order.shipping_tone) !== -1;
        var actionLabel = detailsOnly ? "جزئیات" : "ارسال مرسوله";
        $button.append($("<span>").text(actionLabel),
          $("<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='M19 12H5m6-6-6 6 6 6'/></svg>"))
          .attr("aria-label", actionLabel + " سفارش " + fa(order.order_number)).data("sg-order", order);
        $card.append($action.append($button));
        $list.append($card);
      });
    }
    function loadPage(cursor, history, index, background) {
      if (!active || selecting) return;
      abortRead();
      if (!background) { clearImport(); clearSelection(); }
      var seq = ++readEpoch, generation = epoch;
      retryPage = { cursor: cursor, pages: history.slice(), index: index };
      loading = true;
      // A quiet read may fail; retain the displayed page's navigation until
      // a successful response replaces it.
      if (!background) nextCursor = null;
      var ticket = background ? { ready: function () {}, fail: function () {} } : beginLoading(function () {
        if (!loading && !selecting && retryPage) loadPage(retryPage.cursor, retryPage.pages, retryPage.index);
      });
      readTicket = ticket;
      if (!background) {
        $picker.find("[data-sg-bo-list]").empty();
        $picker.find("[data-sg-bo-empty], [data-sg-bo-pagination]").prop("hidden", true);
        feedback(websiteSource() ? "در حال دریافت سفارش‌های ووکامرس…"
          : (query && !isExactQuery(query) ? "در حال بررسی نام گیرنده و محصول در سفارش‌های غرفه…" : "در حال دریافت سفارش‌های همین غرفه…"), "info", false);
      }
      controls();
      var request = post(websiteSource() ? "salam_gate_website_orders" : "salam_gate_basalam_orders", $.extend(query ? { search: query } : {}, cursor ? { cursor: cursor } : {}));
      if (!request) { loading = false; var error = "اعتبار این صفحه در دسترس نیست؛ صفحه را تازه کنید و دوباره سفارش‌ها را ببینید."; if (!background) feedback(error, "error", true); ticket.fail(error); controls(); return; }
      readRequest = request;
      request.done(function (response) {
        if (!active || seq !== readEpoch || generation !== epoch) return;
        var data = response && response.success === true && normalizeList(response.data);
        if (!data || (isExactQuery(query) && data.orders.some(function (order) { return order.order_id !== Number(query); }))
          || (data.next_cursor && history.slice(0, index + 1).indexOf(data.next_cursor) !== -1)) {
          var error = message(response && response.data, "اطلاعات سفارش‌ها دریافت نشد؛ دوباره تلاش کنید.");
          if (!background) feedback(error, "error", true); ticket.fail(error); return;
        }
        lastReadAt = Date.now();
        pages = history.slice(); pageIndex = index; nextCursor = data.next_cursor; retryPage = null;
        var focusedOrder = background && $(window.document.activeElement).is("[data-sg-bo-choice], [data-sg-bo-view]")
          ? $(window.document.activeElement).data("sg-order") : null;
        renderOrders(data.orders); feedback("", "info", false);
        if (focusedOrder) $picker.find("[data-sg-bo-choice], [data-sg-bo-view]").filter(function () {
          var current = $(this).data("sg-order");
          return current && current.order_id === focusedOrder.order_id && current.parcel_id === focusedOrder.parcel_id;
        }).first().trigger("focus");
        $picker.find("[data-sg-bo-empty]").prop("hidden", data.orders.length !== 0);
        var nameSearch = query && !isExactQuery(query);
        $picker.find("[data-sg-bo-empty-message]").text(websiteSource()
          ? (query ? "سفارشی با این جست‌وجو در ووکامرس پیدا نشد." : "هنوز سفارش مستقلی در ووکامرس ثبت نشده است.") : nameSearch
          ? (nextCursor ? "نتیجه‌ای در این بخش پیدا نشد؛ با «ادامه جست‌وجو» سفارش‌های بعدی را ببینید."
            : "در سفارش‌های اخیر نتیجه‌ای پیدا نشد. می‌توانید شماره کامل سفارش را جست‌وجو کنید.")
          : (query ? "سفارشی با این شماره در غرفه متصل پیدا نشد. شماره سفارش را بررسی کنید." : "در این صفحه سفارشی برای غرفه متصل وجود ندارد."));
        $picker.find("[data-sg-bo-page]").text((nameSearch ? "بخش جست‌وجو " : "صفحه ") + fa(pageIndex + 1));
        $picker.find("[data-sg-bo-next]").text(nameSearch && !websiteSource() ? "ادامه جست‌وجو" : "صفحه بعد");
        $picker.find("[data-sg-bo-prev]").text(nameSearch ? "نتایج قبلی" : "صفحه قبل");
        if (!websiteSource() && nameSearch && nextCursor && data.orders.length) feedback("برای دیدن نتایج بعدی، «ادامه جست‌وجو» را بزنید.", "info", false);
        $picker.find("[data-sg-bo-pagination]").prop("hidden", pageIndex === 0 && !nextCursor);
        ticket.ready();
      }).fail(function (xhr, status) {
        if (!active || seq !== readEpoch || generation !== epoch || status === "abort") return;
        var error = message(xhr && xhr.responseJSON && xhr.responseJSON.data, "ارتباط برای دریافت سفارش‌ها برقرار نشد؛ دوباره تلاش کنید.");
        if (!background) feedback(error, "error", true); ticket.fail(error);
      }).always(function () {
        if (seq !== readEpoch || generation !== epoch) return;
        readRequest = null; readTicket = null; loading = false; controls();
      });
    }
    function safeOrderUrl(value, orderId) {
      if (typeof value !== "string" || !value || /[\x00-\x20\x7f]/.test(value)) return null;
      try {
        var url = new window.URL(value, window.location.href);
        if (url.origin !== window.location.origin || url.username || url.password || url.hash || !/^https?:$/.test(url.protocol)) return null;
        var adminPath = window.location.pathname.slice(0, window.location.pathname.lastIndexOf("/") + 1);
        if (url.searchParams.getAll("action").length !== 1) return null;
        var keys = Array.from(url.searchParams.keys());
        var classic = url.pathname === adminPath + "post.php" && url.searchParams.get("action") === "edit" && positiveId(url.searchParams.get("post")) === orderId
          && keys.length === 2 && url.searchParams.getAll("post").length === 1;
        var hpos = url.pathname === adminPath + "admin.php" && url.searchParams.get("page") === "wc-orders"
          && url.searchParams.get("action") === "edit" && positiveId(url.searchParams.get("id")) === orderId
          && keys.length === 3 && url.searchParams.getAll("id").length === 1 && url.searchParams.getAll("page").length === 1;
        return classic || hpos ? url.href : null;
      } catch (ignore) { return null; }
    }
    function closePreview(restoreFocus) {
      ++previewEpoch;
      var request = previewRequest;
      previewRequest = null; previewReady = false;
      $preview.hide().attr("aria-hidden", "true");
      $preview.find("[data-sg-preview-ship]").prop("hidden", true).prop("disabled", true);
      if (request && typeof request.abort === "function") request.abort();
      if (restoreFocus && previewOpener && window.document.contains(previewOpener)) previewOpener.focus();
    }
    function loadPreview() {
      if (!active || !previewSelection || !$preview.length) return;
      var selection = $.extend({}, previewSelection), seq = ++previewEpoch, generation = epoch;
      var previous = previewRequest;
      previewRequest = null; previewReady = false;
      if (previous && typeof previous.abort === "function") previous.abort();
      $preview.find("[data-sg-preview-content]").empty();
      $preview.find("[data-sg-preview-body]").attr("aria-busy", "true");
      $preview.find("[data-sg-preview-loading]").prop("hidden", false);
      $preview.find("[data-sg-preview-error], [data-sg-preview-reason], [data-sg-preview-ship]").prop("hidden", true);
      $preview.find("[data-sg-preview-ship]").prop("disabled", true);
      function current() { return active && generation === epoch && seq === previewEpoch && $preview.css("display") !== "none"; }
      function fail(value) {
        if (!current()) return;
        $preview.find("[data-sg-preview-message]").text(value);
        $preview.find("[data-sg-preview-error]").prop("hidden", false);
        if (typeof window.woosalamNotify === "function") window.woosalamNotify(value, "error");
      }
      function complete() {
        if (!current()) return;
        previewRequest = null;
        $preview.find("[data-sg-preview-body]").attr("aria-busy", "false");
        $preview.find("[data-sg-preview-loading]").prop("hidden", true);
      }
      var request = post("salam_gate_order_preview", selection);
      if (!request) { fail("اعتبار این صفحه در دسترس نیست؛ صفحه را تازه کنید."); complete(); return; }
      previewRequest = request;
      request.done(function (response) {
        if (!current()) return;
        var data = response && response.data, identity = data && data.selection;
        if (!response || response.success !== true || !identity
          || positiveId(identity.order_id) !== selection.order_id || identity.market !== selection.market
          || (positiveId(identity.basalam_order_id) || null) !== (selection.basalam_order_id || null)
          || (positiveId(identity.basalam_parcel_id) || null) !== (selection.basalam_parcel_id || null)
          || typeof data.title !== "string" || typeof data.html !== "string" || typeof data.shipping_allowed !== "boolean") {
          fail(message(data, "جزئیات سفارش تأیید نشد؛ دوباره تلاش کنید.")); return;
        }
        $preview.find("#sg-order-preview-title").text(data.title);
        // Only this authenticated, escaped server template may populate the view.
        $preview.find("[data-sg-preview-content]").html(data.html);
        $preview.find("[data-sg-preview-reason]").text(text(data.shipping_reason, "")).prop("hidden", !data.shipping_reason);
        previewReady = data.shipping_allowed;
        $preview.find("[data-sg-preview-ship]").prop("hidden", !previewReady).prop("disabled", !previewReady);
      }).fail(function (xhr, status) {
        if (status !== "abort") fail(message(xhr && xhr.responseJSON && xhr.responseJSON.data, "ارتباط برای دریافت جزئیات سفارش برقرار نشد؛ دوباره تلاش کنید."));
      }).always(complete);
    }
    function openPreview(selection, trigger) {
      if (!$preview.length || !active || selecting || pendingImport
        || $(".salam-gate-modal-overlay:visible, .sg-modal-overlay:visible").length
        || $(".salam-gate-order-box[data-sg-order-financial-busy='1']").length) return;
      // Opening a visible row takes priority over a quiet list refresh; its
      // preview still verifies the identity on the server before checkout.
      if (loading) { abortRead(); controls(); }
      previewSelection = $.extend({}, selection);
      previewOpener = trigger;
      $preview.find("#sg-order-preview-title").text("جزئیات سفارش");
      $preview.show().attr("aria-hidden", "false");
      loadPreview();
    }
    function openSelected(trigger) {
      if (!active || !selected || selecting || loading) return;
      var selection = $.extend({}, selected);
      delete selection.order_url;
      openPreview(selection, trigger);
    }
    function selectOrder(order, trigger, importConfirmed) {
      if (!active || loading || selecting || !order || !order.requires_import || !order.eligible || !!order.local_order_id) return;
      clearSelection(); feedback("در حال بررسی سفارش انتخاب‌شده…", "info", false);
      selecting = true; controls();
      // Selection can import only after separate consent; it must never be
      // retried automatically by a read-only loader.
      var ticket = beginLoading();
      var generation = epoch, source = market;
      var selectionData = websiteSource() ? { order_id: order.order_id } : $.extend({
        basalam_order_id: order.order_id, basalam_parcel_id: order.parcel_id
      }, importConfirmed ? { import_confirmed: "1" } : {});
      var request = post(websiteSource() ? "salam_gate_website_order_select" : "salam_gate_basalam_order_select", selectionData);
      if (!request) { selecting = false; ticket.ready(); actionError("اعتبار این صفحه در دسترس نیست؛ صفحه را تازه کنید و دوباره سفارش را انتخاب کنید."); controls(); return; }
      request.done(function (response) {
        if (!active || generation !== epoch) return;
        var data = response && response.data;
        if (source === "basalam" && response && response.success === false && data && data.code === "import_confirmation_required" && !importConfirmed) {
          feedback("", "info", false);
          pendingImport = { order: order, trigger: trigger };
          $picker.find("[data-sg-bo-import-order]").text("سفارش باسلام " + fa(order.order_id) + " هنوز در سفارشات ووکامرس نیست.");
          $picker.find("[data-sg-bo-import]").prop("hidden", false);
          return;
        }
        var wooId = data && positiveId(data.order_id), orderUrl = wooId && safeOrderUrl(data.order_url, wooId);
        var validIdentity = source === "website"
          ? data && data.market === "website" && wooId === order.order_id
          : data && positiveId(data.basalam_order_id) === order.order_id && positiveId(data.basalam_parcel_id) === order.parcel_id
            && typeof data.imported === "boolean" && (!data.imported || importConfirmed);
        if (!response || response.success !== true || !wooId || !orderUrl || !validIdentity) {
          if (importConfirmed) clearImport();
          actionError(message(data, "سفارش برای ادامه ارسال آماده نشد. فهرست را تازه کنید و دوباره وضعیت همین سفارش را بررسی کنید.")); return;
        }
        clearImport();
        selected = { order_id: wooId, order_url: orderUrl, market: source };
        if (source === "basalam") {
          selected.basalam_order_id = order.order_id;
          selected.basalam_parcel_id = order.parcel_id;
        }
        feedback("", "info", false);
        // An explicitly approved import opens its matching order preview.
        // Viewing never obtains quotes, registers a parcel, or pays anything.
        selecting = false; controls(); openSelected(trigger);
      }).fail(function (xhr, status) {
        if (!active || generation !== epoch || status === "abort") return;
        if (importConfirmed) clearImport();
        actionError(message(xhr && xhr.responseJSON && xhr.responseJSON.data, importConfirmed
          ? "پاسخ افزودن سفارش دریافت نشد. فهرست را تازه کنید و وضعیت همان سفارش را بررسی کنید؛ هیچ پرداختی از این بخش ارسال نشده است."
          : "ارتباط برای بررسی سفارش برقرار نشد؛ دوباره وضعیت همان سفارش را بررسی کنید."));
      }).always(function () {
        ticket.ready();
        selecting = false;
        if (active) {
          controls();
          if (generation === epoch && pendingImport) $picker.find("[data-sg-bo-import-confirm]").trigger("focus");
          // Switching away invalidates UI ownership, but must not abort an
          // explicitly approved import and suggest it could not have happened.
          if (generation !== epoch && !$picker.find("[data-sg-bo-list]").children().length && !loading) loadPage(null, [null], 0);
        }
      });
    }
    function activate(value) {
      var source = value === "website" || value === "basalam" ? value : "";
      if (source === market) return;
      closePreview(false); previewSelection = null;
      market = source; active = !!source; ++epoch; abortRead(); clearImport(); clearSelection();
      lastReadAt = 0;
      pages = [null]; pageIndex = 0; nextCursor = null; query = ""; retryPage = null;
      $picker.find("[data-sg-bo-search]").val("").attr("aria-invalid", "false");
      $picker.find("[data-sg-bo-search-error]").prop("hidden", true).text("");
      $picker.find("[data-sg-bo-list]").empty();
      $picker.find("[data-sg-bo-refresh]").prop("hidden", websiteSource());
      $picker.find("[data-sg-bo-empty], [data-sg-bo-pagination]").prop("hidden", true);
      var sourceTitle = websiteSource() ? "ووکامرس" : "باسلام";
      $picker.find("#sg-basalam-orders-title").text("انتخاب سفارش " + sourceTitle);
      $picker.find("[data-sg-bo-list]").attr("aria-label", "سفارش‌های " + sourceTitle);
      $picker.find("[data-sg-bo-pagination]").attr("aria-label", "صفحه‌بندی سفارش‌های " + sourceTitle);
      $picker.find("[data-sg-bo-orders-link]").attr("href", websiteSource() ? $picker.attr("data-website-orders-url") : "https://vendor.basalam.com/orders")
        .text(websiteSource() ? "مشاهده سفارش‌های ووکامرس" : "مشاهده سفارش‌ها در باسلام ↗")
        .attr("target", websiteSource() ? "_self" : "_blank");
      $picker.prop("hidden", !active); feedback("", "info", false); controls();
      if (active) {
        if (selecting) feedback("در حال پایان بررسی سفارش قبلی؛ کمی صبر کنید…", "info", false);
        else loadPage(null, [null], 0);
      }
    }
    $(window.document).on("salamgate:order-source", function (event, source) { activate(source); });
    $picker.on("click", "[data-sg-bo-search-submit]", function () {
      if (!active || loading || selecting || pendingImport) return;
      var value = digits($picker.find("[data-sg-bo-search]").val());
      if (value && ((isExactQuery(value) && !positiveId(value)) || Array.from(value).length > 120 || /[\x00-\x1f\x7f]/.test(value))) {
        $picker.find("[data-sg-bo-search]").attr("aria-invalid", "true");
        var invalidMessage = isExactQuery(value) ? "شماره کامل و معتبر سفارش را وارد کنید." : "نام گیرنده یا محصول را حداکثر در ۱۲۰ نویسه و بدون خط جدید وارد کنید.";
        $picker.find("[data-sg-bo-search-error]").text(invalidMessage).prop("hidden", false)
          .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
        if (typeof window.woosalamNotify === "function") window.woosalamNotify(invalidMessage, "error");
        return;
      }
      $picker.find("[data-sg-bo-search]").val(value).attr("aria-invalid", "false");
      $picker.find("[data-sg-bo-search-error]").prop("hidden", true).text("");
      query = value; loadPage(null, [null], 0);
    });
    $picker.on("keydown", "[data-sg-bo-search]", function (event) {
      if (event.key === "Enter") { event.preventDefault(); $picker.find("[data-sg-bo-search-submit]").trigger("click"); }
    });
    $picker.on("input", "[data-sg-bo-search]", function () {
      $(this).attr("aria-invalid", "false"); $picker.find("[data-sg-bo-search-error]").prop("hidden", true).text("");
    });
    $picker.on("click", "[data-sg-bo-refresh]", function () { if (!loading && !selecting && !pendingImport) loadPage(null, [null], 0); });
    $picker.on("click", "[data-sg-bo-retry]", function () {
      if (!loading && !selecting && retryPage) loadPage(retryPage.cursor, retryPage.pages, retryPage.index);
    });
    $picker.on("click", "[data-sg-bo-prev]", function () { if (pageIndex > 0 && !loading && !selecting && !pendingImport) loadPage(pages[pageIndex - 1], pages.slice(), pageIndex - 1); });
    $picker.on("click", "[data-sg-bo-next]", function () {
      if (!nextCursor || loading || selecting || pendingImport) return;
      var history = pages.slice(0, pageIndex + 1); history.push(nextCursor); loadPage(nextCursor, history, pageIndex + 1);
    });
    $picker.on("click", "[data-sg-bo-choice]", function () {
      if (pendingImport) return;
      selectOrder($(this).data("sg-order"), this, false);
    });
    $picker.on("click", "[data-sg-bo-view]", function () {
      var order = $(this).data("sg-order");
      if (!order || !order.order_url || !order.local_order_id) return;
      var selection = { order_id: order.local_order_id, market: market };
      if (!websiteSource()) {
        selection.basalam_order_id = order.order_id;
        selection.basalam_parcel_id = order.parcel_id;
      }
      openPreview(selection, this);
    });
    $preview.on("click", "[data-sg-preview-close]", function () { closePreview(true); });
    $preview.on("click", function (event) { if (event.target === this) closePreview(true); });
    $preview.on("click", "[data-sg-preview-retry]", loadPreview);
    $preview.on("click", "[data-sg-preview-ship]", function () {
      if (!previewReady || !previewSelection || previewRequest || !active) return;
      var selection = $.extend({}, previewSelection, { trigger: previewOpener }), generation = epoch;
      closePreview(false);
      // Let the shared focus manager close this dialog before opening checkout.
      window.setTimeout(function () {
        if (!active || generation !== epoch) return;
        if (selection.trigger && window.document.contains(selection.trigger)) selection.trigger.focus();
        $(window.document).trigger("salamgate:menu-order-open", [selection]);
      }, 0);
    });
    $picker.on("click", "[data-sg-bo-import-confirm]", function () {
      if (!pendingImport || selecting || loading) return;
      selectOrder(pendingImport.order, pendingImport.trigger, true);
    });
    $picker.on("click", "[data-sg-bo-import-cancel]", function () {
      if (!selecting) {
        var trigger = pendingImport && pendingImport.trigger;
        clearImport(); feedback("", "info", false); controls();
        if (trigger) $(trigger).trigger("focus");
      }
    });
    $picker.on("click", "[data-sg-bo-open-selected]", function () { openSelected(this); });
    function refreshWebsiteIfNeeded() {
      if (!active || !websiteSource() || loading || selecting || pendingImport
        || window.document.visibilityState === "hidden" || Date.now() - lastReadAt < 30000
        || !$picker.is(':visible')
        || $(".sg-modal-overlay:visible, .salam-gate-modal-overlay:visible, [role='dialog'][aria-modal='true']:visible").length
        || $(".salam-gate-order-box[data-sg-order-financial-busy='1']").length) return;
      loadPage(pages[pageIndex], pages.slice(), pageIndex, true);
    }
    var refreshTimer = window.setInterval(refreshWebsiteIfNeeded, 30000);
    $(window).on('pageshow', function (event) {
      if (event.originalEvent && event.originalEvent.persisted) {
        window.clearInterval(refreshTimer);
        refreshTimer = window.setInterval(refreshWebsiteIfNeeded, 30000);
        activate($("[data-view='send']").attr("data-send-order-source") || "");
      }
    });
    $(window).on("focus", refreshWebsiteIfNeeded);
    $(window.document).on("visibilitychange", refreshWebsiteIfNeeded)
      .on("salamgate:order-list-changed", function () {
        if (websiteSource()) lastReadAt = 0;
      });
    $(window).on("pagehide", function () { window.clearInterval(refreshTimer); activate(""); });
    // The source controller may already have emitted its initial event before
    // this ready callback ran. Read only the checked source, never a saved order.
    activate($("[data-view='send']").attr("data-send-order-source") || "");
  });
})(window, window.jQuery);
