/** Read-only, explicit discovery of native Basalam parcels for one Woo order. */
(function (window, $) {
  "use strict";
  if (!$) return;

  function positiveId(value) {
    return typeof value === "number" && Number.isSafeInteger(value) && value > 0;
  }
  function readable(value, fallback) {
    return typeof value === "string" && value.trim() ? value : fallback;
  }
  function localStatusTitle(value) {
    var title = readable(value, "");
    return /پرداخت/.test(title) && /نامعلوم|نامشخص|مشخص نیست|قطعی نیست/.test(title) ? "در حال بررسی پرداخت" : title;
  }
  function errorMessage(response) {
    var value = response && response.success === false && response.data && response.data.message;
    return typeof value === "string" && value.trim() && value.length <= 2000 && /[\u0600-\u06ff]/.test(value)
      && !/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(value) ? value.replace(/بسالم/g, "باسلام") : null;
  }
  function persian(value) { return String(value).replace(/\d/g, function (digit) { return "۰۱۲۳۴۵۶۷۸۹"[Number(digit)]; }); }
  function normalizePage(data) {
    if (!$.isPlainObject(data) || !Array.isArray(data.parcels) || data.window_days !== 90
      || typeof data.list_exhausted !== "boolean") return null;
    var cursor = data.next_cursor == null ? "" : data.next_cursor;
    if (typeof cursor !== "string" || cursor.length > 4096 || /[\x00-\x1f\x7f]/.test(cursor)) return null;
    if (data.list_exhausted && cursor) return null;
    var parcels = [];
    for (var i = 0; i < data.parcels.length; i++) {
      var row = data.parcels[i];
      if (!$.isPlainObject(row) || !positiveId(row.basalam_parcel_id) || !Array.isArray(row.titles)
        || !row.titles.every(function (title) { return typeof title === "string"; })
        || !Number.isSafeInteger(row.items_count) || row.items_count < 0
        || !Number.isSafeInteger(row.quantity) || row.quantity < 0 || typeof row.eligible !== "boolean"
        || (row.has_local != null && typeof row.has_local !== "boolean")) return null;
      parcels.push({ id: row.basalam_parcel_id, titles: row.titles.slice(0, 20), items: row.items_count,
        quantity: row.quantity, status: readable(row.status_title, "وضعیت نامشخص"), eligible: row.eligible,
        reason: readable(row.blocked_reason, "این مرسوله در حال حاضر قابل ارسال نیست."),
        local: row.has_local === true, localStatus: localStatusTitle(row.local_status_title),
        tracking: readable(row.tracking_code, "") });
    }
    return { parcels: parcels, cursor: cursor, exhausted: data.list_exhausted };
  }

  function mount(box) {
    var $box = $(box), $section = $box.find("[data-sg-native-parcels]").first();
    var previous = $box.data("sgNativeParcelsController");
    if (previous && previous.section === $section[0]) return previous;
    if (previous) previous.dispose();
    var order = String($box.attr("data-order-id") || "");
    if ($box.attr("data-basalam-order") !== "1" || !$section.length || !/^[1-9]\d*$/.test(order)
      || !positiveId(Number(order))) { $box.removeData("sgNativeParcelsController"); return null; }
    var $rows = $section.find("[data-sg-native-parcels-rows]").first();
    var $load = $section.find("[data-sg-native-parcels-load]").first();
    var $more = $section.find("[data-sg-native-parcels-more]").first();
    if (!$rows.length || !$load.length || !$more.length) return null;
    // A restored BFCache document must not display detached controller data as
    // a fresh actionable result. Remounting is still read-only until a click.
    $rows.empty();
    $section.addClass("sg-native-parcels");
    var $limit = $section.find("[data-sg-native-parcels-limit]").first();
    if (!$limit.length) $limit = $("<p class='sg-native-parcels__limit' data-sg-native-parcels-limit>").prependTo($section);
    $limit.text("هر مرسولهٔ باسلام را جداگانه انتخاب کنید. برای بررسی ادامهٔ فهرست، موارد بیشتر را دریافت کنید.");
    var $message = $section.find("[data-sg-native-parcels-message]").first();
    if (!$message.length) $message = $("<p class='sg-native-parcels__message' data-sg-native-parcels-message role='status' aria-live='polite'>").insertBefore($rows);
    var entries = Object.create(null), seenCursors = Object.create(null), ids = [], next = "", loaded = false;
    var busy = false, disposed = false, sequence = 0, pending = null, failed = false, failedCursor = "";

    function current(epoch) {
      return !disposed && epoch === sequence && document.contains($section[0])
        && String($box.attr("data-order-id") || "") === order;
    }
    function financialBusy() { return $box.attr("data-sg-order-financial-busy") === "1"; }
    function controls() {
      $section.attr("aria-busy", String(busy)).toggleClass("is-loading", busy);
      $load.prop("disabled", busy || financialBusy()).text(failed ? "تلاش دوباره" : loaded ? "تازه‌سازی مرسوله‌ها" : "دریافت مرسوله‌های باسلام");
      $more.prop("hidden", !loaded || !next || failed).prop("disabled", busy || financialBusy()).text("دریافت موارد بیشتر");
      $rows.prop("hidden", busy && !loaded);
    }
    function renderRows() {
      $rows.empty();
      ids.forEach(function (id) {
        var parcel = entries[id], receipt = !parcel.eligible && parcel.local;
        var $row = $("<article class='sg-native-parcel'>").attr("data-sg-native-parcel-row", id);
        var $header = $("<div class='sg-native-parcel__header'>").appendTo($row);
        $header.append($("<strong class='sg-native-parcel__number'>").text("مرسوله " + persian(id)),
          $("<span class='sg-native-parcel__status'>").text(parcel.status));
        var $titles = $("<ul class='sg-native-parcel__titles'>").appendTo($row);
        parcel.titles.forEach(function (title) { $("<li>").text(title).appendTo($titles); });
        if (!parcel.titles.length) $("<li>").text("عنوان کالا دریافت نشد").appendTo($titles);
        $("<p class='sg-native-parcel__quantity'>").text(persian(parcel.items) + " قلم · " + persian(parcel.quantity) + " عدد کالا").appendTo($row);
        if (parcel.localStatus) $("<p class='sg-native-parcel__local'>").text("وضعیت در ووسلام: " + parcel.localStatus).appendTo($row);
        if (parcel.tracking) $("<p class='sg-native-parcel__tracking'>").append($("<span>").text("کد رهگیری: "), $("<bdi dir='ltr'>").text(parcel.tracking)).appendTo($row);
        if (!parcel.eligible) $("<p class='sg-native-parcel__reason'>").text(parcel.reason).appendTo($row);
        $("<button type='button' class='sg-native-parcel__action salam-gate-open-modal'>")
          .attr({ "data-basalam-parcel-id": id, "data-sg-native-has-local": parcel.local ? "1" : "0",
            "data-sg-native-receipt": receipt ? "1" : "0" })
          .attr("data-sg-native-blocked", !parcel.eligible && !receipt ? "1" : "0")
          .prop("disabled", financialBusy() || (!parcel.eligible && !receipt))
          .text(receipt ? "مشاهده مرسوله" : parcel.local ? "ادامهٔ ارسال با سلام‌گیت" : "ارسال با سلام‌گیت").appendTo($row);
        $rows.append($row);
      });
      $(document).trigger("salamgate:order-parcels-rendered", [$box[0]]);
    }
    function fail(epoch, cursor, message) {
      if (!current(epoch)) return;
      failed = true; failedCursor = cursor;
      $message.text(message || "مرسوله‌های این سفارش دریافت نشد؛ دوباره تلاش کنید. هیچ ثبت یا پرداختی انجام نشده است.").prop("hidden", false).addClass("has-error");
    }
    function read(cursor, reset, whenReady) {
      if (busy || financialBusy() || disposed) return false;
      var epoch = ++sequence, requestedCursor = cursor || "", singleId = null;
      busy = true; failed = false;
      $message.text("در حال دریافت اطلاعات…").prop("hidden", false).removeClass("has-error");
      controls();
      var data = { _wpnonce: basalam_ajax.nonce, action: "salam_gate_order_parcels", order_id: order };
      if (requestedCursor) data.cursor = requestedCursor;
      var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
      pending = request(basalam_ajax.ajaxurl, data).done(function (response) {
        if (!current(epoch)) return;
        if (response && response.success === false) { fail(epoch, requestedCursor, errorMessage(response)); return; }
        var page = response && response.success === true ? normalizePage(response.data) : null;
        if (!page || (requestedCursor && page.cursor === requestedCursor) || (!reset && page.cursor && seenCursors[page.cursor])) {
          fail(epoch, requestedCursor, "پاسخ مرسوله‌ها کامل یا معتبر نبود؛ دوباره تلاش کنید. هیچ ثبت یا پرداختی انجام نشده است."); return;
        }
        if (reset) { entries = Object.create(null); seenCursors = Object.create(null); ids = []; }
        seenCursors[requestedCursor] = true;
        page.parcels.forEach(function (row) { if (!entries[row.id]) ids.push(row.id); entries[row.id] = row; });
        loaded = true; next = page.cursor; failed = false;
        // A partial page (even one row) cannot establish a single native parcel.
        if (reset && !requestedCursor && page.exhausted && !next && page.parcels.length === 1 && ids.length === 1) singleId = ids[0];
        renderRows();
        $message.removeClass("has-error").text(!ids.length
          ? next ? "در این بخش مرسوله‌ای پیدا نشد؛ برای ادامهٔ بررسی، مرسوله‌های بعدی را دریافت کنید."
            : "در موارد دریافت‌شده، مرسوله‌ای مرتبط با این سفارش پیدا نشد."
          : next ? "مرسوله‌های دریافت‌شده نمایش داده شدند. برای ادامهٔ بررسی، دریافت موارد بیشتر را بزنید."
            : "مرسوله‌های دریافت‌شده نمایش داده شدند؛ هر مرسوله جداگانه ارسال یا پیگیری می‌شود.").prop("hidden", false);
      }).fail(function (xhr) { fail(epoch, requestedCursor, errorMessage(xhr && xhr.responseJSON)); }).always(function () {
        if (!current(epoch)) return;
        busy = false; pending = null; controls();
        if (typeof whenReady === "function") whenReady(singleId);
      });
      return true;
    }
    $section.on("click.sgNativeParcels", "[data-sg-native-parcels-load]", function () {
      read(failed ? failedCursor : "", failed ? failedCursor === "" : true);
    });
    $section.on("click.sgNativeParcels", "[data-sg-native-parcels-more]", function () { if (next && !failed) read(next, false); });
    $message.text("برای مشاهده و انتخاب مرسوله‌های این سفارش، «دریافت مرسوله‌های باسلام» را بزنید.").prop("hidden", false);
    controls();
    var controller = { section: $section[0], syncBusy: controls,
      openCheckout: function (whenReady) { return read("", true, whenReady); }, dispose: function () {
      disposed = true; ++sequence; $section.off(".sgNativeParcels");
      if (pending && typeof pending.abort === "function") pending.abort();
      pending = null;
      $section.removeClass("is-loading").attr("aria-busy", "false");
      if ($box.data("sgNativeParcelsController") === controller) $box.removeData("sgNativeParcelsController");
    } };
    $box.data("sgNativeParcelsController", controller);
    return controller;
  }
  window.SalamGateOrderParcels = { mount: mount, syncBusy: function (box) {
    var controller = $(box).data("sgNativeParcelsController"); if (controller) controller.syncBusy();
  } };
  $(function () { $(".salam-gate-order-box[data-basalam-order='1']").each(function () { mount(this); }); });
  $(document).on("salamgate:order-box-refreshed", function (event, box) { if (box) mount(box); });
  $(window).on("pagehide", function () {
    $(".salam-gate-order-box").each(function () { var controller = $(this).data("sgNativeParcelsController"); if (controller) controller.dispose(); });
  });
  $(window).on("pageshow", function (event) {
    if ((event.originalEvent && event.originalEvent.persisted) || event.persisted === true) {
      $(".salam-gate-order-box[data-basalam-order='1']").each(function () { mount(this); });
    }
  });
})(window, window.jQuery);
