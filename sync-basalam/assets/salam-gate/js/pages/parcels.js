// =====================================================================
// Salam Gate — parcels list page (part 8 — escaped rendering, tabs, modals)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("parcels")) return;
  var $page = $(".salam-gate-parcels-page");
  if (!$page.length || !$("#sgp-config").length) return;

  var config = {};
  try {
    config = JSON.parse($("#sgp-config").text() || "{}");
  } catch (e) {
    config = {};
  }
  var filters = $.extend({ tab: "all", market_name: [], status: "", sort: "desc", cursor: "", search: "" }, config.filters || {});
  var cursorState = { next: "", prev: "" };
  var currentCursor = filters.cursor || "";
  var listRequest = null;
  var listSequence = 0;
  var listedParcels = Object.create(null);
  var selectedParcels = Object.create(null);
  var labelCandidates = Object.create(null), labelListRequest = null, labelListVersion = 0;
  var labelListCursor = "", labelListHasMore = true, labelListReady = false, labelListConnection = null;
  var labelListSeen = Object.create(null), labelListError = "";
  var $printModal = $("[data-sgp-print-modal-el]");
  var $printMenu = $page.find("[data-sgp-print-menu]");
  var defaultLabelTemplate = String($printModal.find("[name='sgp-template']:checked").val() || "invoice");
  var bulkPrintKind = "label";
  var searchResults = null, searchKey = "", searchTimer = null;
  var $filterModal = $(".sg-modal-overlay[data-sgp-filter-modal]");

  function sgPost(action, data) {
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, data || {}));
  }

  function esc(v) {
    return $("<span>").text(v == null ? "" : String(v)).html();
  }

  function fa(n) {
    return String(n).replace(/\d/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[+d]; });
  }

  function faThousands(n) {
    return window.SalamGateAmount.toman(Number(n), false);
  }

  function statusText(text) {
    $page.find(".sgp-label-status").text(text).removeClass("sg-feedback-actions sg-feedback-toast-only").attr("role", "status").toggle(!!text);
  }

  function readableMessage(text, fallback) {
    return typeof text === "string" && /[\u0600-\u06ff]/.test(text)
      ? text.replace(/بسالم/g, "باسلام") : fallback;
  }

  function listError($body, message, announce) {
    $page.find("[data-sgp-search-status]").prop("hidden", true);
    message = readableMessage(message, "فهرست مرسوله‌ها دریافت نشد؛ دوباره تلاش کنید.");
    var floating = typeof window.woosalamNotify === "function";
    $body.empty().append($("<tr>").append(
      $("<td colspan='8' class='sgp-empty sgp-state--error'>").attr("role", floating ? "group" : "status").append(
        $("<p>").text(message).toggleClass("sg-feedback-toast-only", floating),
        $("<button type='button' class='button-link' data-sgp-retry>").text("تلاش مجدد")
      )
    ));
    if (floating && announce !== false) window.woosalamNotify(message, "error");
    return message;
  }

  // ---------- رندر ردیف‌ها — همه مقادیر با text node / esc (بدون XSS) ----------
  function parcelCell(label) {
    return $("<td>").attr("data-label", label);
  }

  function rowNode(item) {
    var id = String(item.id);
    listedParcels[id] = item;
    var $tr = $("<tr>").attr("data-parcel-id", id);
    $tr.toggleClass("sgp-row--paid", item.payment_state === "paid");
    if (Number.isFinite(item.shipping_cost_toman)) $tr.attr("data-shipping-cost-toman", item.shipping_cost_toman);

    var $receiver = parcelCell("گیرنده").append($("<strong>").text(item.receiver_name || "—"));
    if (item.receiver_city) $receiver.append($("<small class='sgp-cell-note'>").text(item.receiver_city));

    var $tracking = parcelCell("کد رهگیری").addClass("sgp-tracking-cell");
    if (item.tracking_code) {
      var $trackingCode = $("<bdi dir='ltr'>").text(item.tracking_code);
      if (/^\d{24}$/.test(item.tracking_code)) $trackingCode.empty().append(
        document.createTextNode(item.tracking_code.slice(0, 12)), $("<wbr>"), document.createTextNode(item.tracking_code.slice(12))
      );
      $tracking.append(
        $("<button type='button' class='sgp-track' data-sgp-copy-tracking>")
          .attr({ "data-code": item.tracking_code, title: "کپی کد رهگیری", "aria-label": "کپی کد رهگیری " + item.tracking_code })
          .append($trackingCode, $("<span class='dashicons dashicons-admin-page' aria-hidden='true'>"))
      );
    } else {
      $tracking.append($("<span class='sgp-tracking-empty'>").text("—"));
    }

    var $status = $("<div class='sgp-status'>").append(
      $("<span class='salam-gate-badge'>").css("background-color", item.status_color || "#6b7280").text(paymentStatusLabel(item.status_label, item.payment_state) || "")
    );
    var paymentLabels = { unpaid: "در انتظار پرداخت", paid: "پرداخت شده", pending: "در حال بررسی", unknown: "در حال بررسی", refunded: "برگشت وجه", voided: "باطل شده", blocked: "تأیید نشده" };
    var paymentNote = typeof item.payment_label === "string" && item.payment_label.trim()
      ? item.payment_label.trim() : (paymentLabels[item.payment_state] || (item.can_resume_payment !== true ? "تأیید نشده" : ""));
    var paymentTone = { unpaid: "waiting", paid: "paid", pending: "review", unknown: "review", refunded: "blocked", voided: "blocked" }[item.payment_state] || "blocked";
    var paymentIcon = { waiting: "clock", paid: "yes-alt", review: "update", blocked: "info-outline" }[paymentTone];
    if (paymentNote) $status.append($("<small class='sgp-payment-reason'>")
      .attr({ "data-tone": paymentTone, "aria-label": "وضعیت پرداخت: " + paymentNote,
        title: typeof item.payment_message === "string" ? paymentStatusLabel(item.payment_message) : "" })
      .append($("<span class='dashicons' aria-hidden='true'>").addClass("dashicons-" + paymentIcon), $("<span>").text(paymentNote)));
    var $labelCell = parcelCell("وضعیت").append($status);
    $tr.append(
      parcelCell("مرسوله / بازار").append($("<strong class='sgp-parcel-id'>").text("#" + fa(id)), $("<small class='sgp-cell-note'>").text(item.market_label || "—")),
      $receiver,
      parcelCell("محتویات").text(item.contents || "—"),
      parcelCell("تاریخ").text(item.invoice_paid_at || item.updated_at ? dateText(item.invoice_paid_at || item.updated_at) : (item.created_at_display || "—")),
      $labelCell,
      parcelCell("روش ارسال").text(item.method_title || "—").append(Number.isFinite(item.shipping_cost_toman)
        ? $("<small class='sgp-method-price'>").text(window.SalamGateAmount.fromRial(item.shipping_cost_rial)) : $()),
      $tracking,
      parcelCell("عملیات").addClass("sgp-actions").append(
        $("<div class='sgp-actions__buttons'>").append(
          $("<button type='button' class='button button-small sgp-detail'>")
            .addClass("is-expanded")
            .attr({ "aria-label": "جزئیات مرسوله " + fa(id), title: "جزئیات مرسوله" })
            .append(
              $("<span class='dashicons dashicons-visibility' aria-hidden='true'>"),
              $("<span class='sgp-detail__text' aria-hidden='true'>").text("جزئیات")
            )
        )
      )
    );
    if (item.can_resume_payment === true || item.can_check_payment === true) {
      $tr.find(".sgp-actions__buttons").prepend($("<button type='button' class='sg-button sg-button--primary sgp-checkout'>")
        .attr("data-sgp-resume-payment", item.can_resume_payment === true ? "true" : "false")
        .text(item.can_resume_payment === true ? "ادامه پرداخت" : "بررسی وضعیت پرداخت"));
    }
    if (item.can_print_label === true) {
      $tr.find(".sgp-actions__buttons").prepend(
        $("<button type='button' class='button button-small sgp-row-label'>")
          .attr("aria-label", "چاپ برچسب پستی مرسوله " + fa(id))
          .append($("<span class='dashicons dashicons-printer' aria-hidden='true'>"), $("<span>").text("چاپ برچسب پستی"))
      );
    }

    return $tr;
  }

  // Clipboard API requires a secure context; LAN HTTP uses the click-bound fallback.
  $page.on("click", "[data-sgp-copy-tracking]", function () {
    var $button = $(this), code = $button.attr("data-code");
    if (!code || $button.data("copy-pending")) return;
    $button.data("copy-pending", true);
    var resetTimer = $button.data("copy-reset-timer");
    if (resetTimer) window.clearTimeout(resetTimer);
    function finish(copied) {
      $button.removeData("copy-pending").toggleClass("is-copied", copied)
        .attr("title", copied ? "کپی شد" : "کپی کد رهگیری");
      $button.find(".dashicons").toggleClass("dashicons-admin-page", !copied).toggleClass("dashicons-yes", copied);
      var message = copied ? "کد رهگیری کپی شد." : "کپی انجام نشد؛ کد رهگیری را انتخاب و دستی کپی کنید.";
      $page.find("[data-sgp-copy-status]").text(message);
      if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, copied ? "success" : "error");
      if (copied) $button.data("copy-reset-timer", window.setTimeout(function () {
        $button.removeClass("is-copied").attr("title", "کپی کد رهگیری").removeData("copy-reset-timer");
        $button.find(".dashicons").removeClass("dashicons-yes").addClass("dashicons-admin-page");
      }, 2000));
    }
    function fallback() {
      var active = document.activeElement;
      var $input = $("<textarea readonly>").val(code).css({ position: "fixed", top: "0", left: "-9999px" }).appendTo(document.body);
      var copied = false;
      try { $input[0].focus({ preventScroll: true }); $input[0].select(); copied = document.execCommand("copy") === true; } catch (error) { /* Report the actual result below. */ }
      $input.remove();
      if (active && document.contains(active)) active.focus({ preventScroll: true });
      finish(copied);
    }
    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      try { navigator.clipboard.writeText(code).then(function () { finish(true); }, fallback); }
      catch (error) { fallback(); }
    } else fallback();
  });

  function emptyRow() {
    var constrained = !!((filters.market_name || []).length || filters.status || currentCursor || filters.search);
    var $cell = $("<td colspan='8' class='sgp-empty'>");
    $cell.append(
      $("<div class='sgp-empty__icon' aria-hidden='true'>").append($("<span class='dashicons dashicons-archive'>")),
      $("<h3 class='sgp-empty__title'>").text(filters.search ? "نتیجه‌ای برای این جستجو پیدا نشد" : constrained ? "مرسوله‌ای با این شرایط پیدا نشد" : "هنوز مرسوله‌ای ثبت نکرده‌اید"),
      $("<p class='sgp-empty__text'>").text(filters.search ? "نام گیرنده، شماره سفارش یا نام یکی از اقلام را جستجو کنید." : constrained ? "فیلترهای فعال را از بالای فهرست تغییر دهید." : "برای شروع، اطلاعات اولین مرسوله فروشگاه را ثبت کنید.")
    );
    var $actions = $("<div class='sgp-empty__actions'>");
    if (constrained) {
      if (filters.search) $actions.append($("<button type='button' class='sg-button sg-button--secondary' data-sgp-search-clear>").text("پاک کردن جستجو"));
      $actions.append($("<button type='button' class='sg-button sg-button--secondary' data-sgp-filter-modal>").text("تغییر فیلتر بازار"));
      if (currentCursor) $actions.append($("<button type='button' class='sg-button sg-button--secondary' data-sgp-first-page>").text("بازگشت به صفحه اول"));
    } else {
      $actions.append($("<a class='sg-button sg-button--primary'>").attr("href", config.sendUrl || "admin.php?page=salam_gate&view=send").text("ثبت اولین مرسوله"));
    }
    return $("<tr class='sgp-empty-row'>").append($cell.append($actions));
  }

  function stateFromUrl() {
    var url = new URL(window.location.href);
    var tab = url.searchParams.get("sg_tab") || "all";
    if (["all", "pending", "new", "barcode"].indexOf(tab) === -1) tab = "all";
    var markets = [];
    var allowedMarkets = config.markets || [];
    url.searchParams.forEach(function (value, key) {
      if (!/^(sg_market|sg_markets|market_names)(\[\d*\])?$/.test(key)) return;
      value.split(",").forEach(function (market) {
        if (allowedMarkets.indexOf(market) !== -1 && markets.indexOf(market) === -1) markets.push(market);
      });
    });
    var rawStatus = tabStatus(tab) || url.searchParams.get("sg_status") || "";
    rawStatus = rawStatus.replace(/[۰-۹]/g, function (digit) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(digit); })
      .replace(/[٠-٩]/g, function (digit) { return "٠١٢٣٤٥٦٧٨٩".indexOf(digit); });
    var status = /^[0-9]+$/.test(rawStatus) ? String(Number(rawStatus)) : "";
    if (!Object.prototype.hasOwnProperty.call(config.statusLabels || {}, status)) status = "";
    return {
      tab: tab,
      market_name: markets,
      status: status,
      sort: url.searchParams.get("sg_sort") === "asc" ? "asc" : "desc",
      search: (url.searchParams.get("sg_search") || "").slice(0, 120),
      cursor: url.searchParams.get("sg_cursor") || ""
    };
  }

  function tabStatus(tab) {
    return { pending: "7", new: "8", barcode: "9" }[tab] || "";
  }

  function stateUrl(state, cursor) {
    var url = new URL(window.location.href);
    var oldMarketKeys = [];
    url.searchParams.forEach(function (value, key) {
      if (/^(sg_market|sg_markets|market_names)(\[.*\])?$/.test(key)) oldMarketKeys.push(key);
    });
    oldMarketKeys.forEach(function (key) { url.searchParams.delete(key); });
    (state.market_name || []).forEach(function (market) { url.searchParams.append("sg_market[]", market); });
    url.searchParams.set("sg_tab", state.tab || "all");
    url.searchParams.set("sg_sort", state.sort || "desc");
    if (state.search) url.searchParams.set("sg_search", state.search); else url.searchParams.delete("sg_search");
    if (state.tab === "all" && state.status) url.searchParams.set("sg_status", state.status);
    else url.searchParams.delete("sg_status");
    if (cursor) url.searchParams.set("sg_cursor", cursor);
    else url.searchParams.delete("sg_cursor");
    return url.toString();
  }

  function updateFilterControls() {
    if ($page.find("[data-sgp-search]").val() !== filters.search) $page.find("[data-sgp-search]").val(filters.search);
    $page.find(".sgp-search__clear").prop("hidden", !filters.search);
    var selectedMarket = (filters.market_name || [])[0] || "";
    $filterModal.find("input[name='sgp-market']").each(function () {
      $(this).prop("checked", this.value === selectedMarket);
    });
    var $filterButton = $page.find("button[data-sgp-filter-modal]");
    var marketLabel = filters.market_name.length ? filters.market_name.map(function (market) {
      return (config.marketLabels || {})[market] || market;
    }).join("، ") : "همه بازارها";
    $filterButton.find("[data-sgp-filter-label]").text(marketLabel);
    $filterButton.filter(".sgp-tool").toggleClass("is-active", filters.market_name.length > 0).attr({
      "aria-label": "فیلتر بازار: " + marketLabel,
      title: "فیلتر بازار: " + marketLabel,
      "data-sgp-tooltip": "فیلتر بازار: " + marketLabel
    });
    $page.find("[data-sgp-sort]").val(filters.sort);
    var sortLabel = "ترتیب نمایش: " + (filters.sort === "asc" ? "قدیمی‌ترین" : "جدیدترین");
    $page.find("[data-sgp-sort-control]").toggleClass("is-active", filters.sort === "asc")
      .attr({ "data-sort": filters.sort, title: sortLabel, "data-sgp-tooltip": sortLabel })
      .find(".sg-picker-trigger").attr("title", sortLabel);
    $page.find("[data-sgp-status]").val(filters.status);
    var $chips = $page.find("[data-sgp-filter-chips]").empty();
    (filters.market_name || []).forEach(function (market) {
      var label = (config.marketLabels || {})[market] || market;
      $chips.append($("<button type='button' class='sgp-filter-chip'>")
        .attr({ "data-sgp-remove-market": market, "aria-label": "حذف فیلتر بازار " + label })
        .append($("<span>").text("بازار: " + label), $("<span aria-hidden='true'>").text("×")));
    });
    if (filters.status) {
      var statusLabel = (config.statusLabels || {})[filters.status] || "وضعیت انتخاب‌شده";
      $chips.append($("<button type='button' class='sgp-filter-chip' data-sgp-remove-status aria-label='حذف فیلتر وضعیت'>")
        .append($("<span>").text("وضعیت: " + statusLabel), $("<span aria-hidden='true'>").text("×")));
    }
    var hasFilters = !!((filters.market_name || []).length || filters.status);
    $chips.prop("hidden", !hasFilters);
    $page.find("[data-sgp-tab]").each(function () {
      var tab = $(this).attr("data-sgp-tab");
      var current = tab === filters.tab;
      var tabState = $.extend({}, filters, { tab: tab, status: tabStatus(tab) });
      $(this).toggleClass("is-active", current).attr("href", stateUrl(tabState, ""));
      if (current) $(this).attr("aria-current", "page");
      else $(this).removeAttr("aria-current");
    });
  }

  function renderSearchPage(cursor, historyMode) {
    var offset = /^search:\d+$/.test(cursor || "") ? Number(cursor.slice(7)) : 0;
    if (offset >= searchResults.length) offset = Math.max(0, Math.floor((searchResults.length - 1) / 30) * 30);
    currentCursor = offset > 0 ? "search:" + offset : "";
    if (historyMode) window.history[historyMode === "push" ? "pushState" : "replaceState"]({}, "", stateUrl(filters, currentCursor));
    listedParcels = Object.create(null);
    var $body = $("#sgp-body").empty();
    if (!searchResults.length) $body.append(emptyRow());
    searchResults.slice(offset, offset + 30).forEach(function (item) { $body.append(rowNode(item)); });
    $page.find(".sgp-prev").attr("data-cursor", "search:" + Math.max(0, offset - 30)).prop("disabled", offset === 0);
    $page.find(".sgp-next").attr("data-cursor", "search:" + (offset + 30)).prop("disabled", offset + 30 >= searchResults.length);
    $page.find("[data-sgp-search-status]").prop("hidden", false).text(fa(searchResults.length) + " مرسوله پیدا شد");
    updateLabelActions();
  }

  function loadPage(cursor, historyMode) {
    currentCursor = cursor || "";
    filters.cursor = currentCursor;
    updateFilterControls();
    if (historyMode) {
      var url = stateUrl(filters, currentCursor);
      if (url !== window.location.href) window.history[historyMode === "replace" ? "replaceState" : "pushState"]({}, "", url);
    }
    var sequence = ++listSequence;
    var searching = !!filters.search;
    var listLoading = (!searching || $page.attr("data-sg-load-state") === "loading") && window.WoosalamGateLoading
      && window.WoosalamGateLoading.begin($page[0], "list", function () { loadPage(currentCursor, "replace"); });
    if (listRequest && typeof listRequest.abort === "function") listRequest.abort();
    listedParcels = Object.create(null);
    var $body = $("#sgp-body").html("<tr><td colspan='8' class='sgp-empty' role='status'>در حال دریافت مرسوله‌ها…</td></tr>");
    updateLabelActions();

    $page.find(".sgp-table-wrap").attr("aria-busy", "true");
    $page.find("[data-sgp-initial-error]").remove();
    $page.find(".sgp-next, .sgp-prev").prop("disabled", true);
    searchResults = null;
    var requestFilters = $.extend({}, filters), found = [], seen = Object.create(null), seenCursors = Object.create(null), searchConnection = null;
    searchKey = JSON.stringify([filters.search, filters.market_name, filters.status, filters.sort]);
    $page.find("[data-sgp-search-status]").prop("hidden", !searching).text(searching ? "در حال جستجو در مرسوله‌ها…" : "");

    function requestPage(providerCursor) {
      var request = sgPost("salam_gate_parcels_list", $.extend({}, requestFilters, { cursor: providerCursor }));
      listRequest = request;
      request
        .done(function (res) {
          if (sequence !== listSequence) return;
          if (!res || !res.success || !res.data || !Array.isArray(res.data.items)) {
            var error = listError($body, (res && res.data && res.data.message) || "فهرست مرسوله‌ها دریافت نشد؛ دوباره تلاش کنید.", !listLoading);
            if (listLoading) listLoading.fail(error);
            return;
          }
          var data = res.data || {};
          if (searching) {
            if (typeof data.connection_id === "string") {
              if (searchConnection !== null && searchConnection !== data.connection_id) {
                listError($body, "حساب متصل تغییر کرده است؛ فهرست را دوباره بررسی کنید.");
                if (listLoading) listLoading.fail("حساب متصل تغییر کرده است؛ فهرست را دوباره بررسی کنید.");
                return;
              }
              searchConnection = data.connection_id;
            }
            if (listLoading) { listLoading.ready(); listLoading = null; }
            data.items.forEach(function (item) {
              if (!seen[String(item.id)]) { seen[String(item.id)] = true; found.push(item); }
            });
            var next = typeof data.next_cursor === "string" ? data.next_cursor : "";
            if (next) {
              if (seenCursors[next]) {
                listError($body, "جستجو کامل نشد؛ فهرست را دوباره بررسی کنید.");
                return;
              }
              seenCursors[next] = true;
              $page.find("[data-sgp-search-status]").text("در حال جستجو… " + fa(found.length) + " نتیجه پیدا شد");
              requestPage(next);
              return;
            }
            searchResults = found;
            renderSearchPage(currentCursor, "replace");
            return;
          }
          cursorState = { next: data.next_cursor || "", prev: data.previous_cursor || "" };
          $body.empty();
          if (!(data.items || []).length) {
            $body.append(emptyRow());
          } else {
            $.each(data.items, function (i, item) { $body.append(rowNode(item)); });
          }

          $page.find(".sgp-next").attr("data-cursor", cursorState.next).prop("disabled", !cursorState.next);
          $page.find(".sgp-prev").attr("data-cursor", cursorState.prev).prop("disabled", !cursorState.prev);
          if (listLoading) listLoading.ready();
        })
        .fail(function (xhr, status) {
          if (sequence !== listSequence || status === "abort") return;
          var error = listError($body, "ارتباط برای دریافت مرسوله‌ها برقرار نشد؛ دوباره تلاش کنید.", !listLoading);
          if (listLoading) listLoading.fail(error);
        })
        .always(function () {
          if (sequence !== listSequence || listRequest !== request) return;
          listRequest = null;
          $page.find(".sgp-table-wrap").attr("aria-busy", "false");
          updateLabelActions();
        });
    }
    requestPage(searching ? "" : currentCursor);
  }

  $page.on("submit", "[data-sgp-search-form]", function (event) {
    event.preventDefault();
    window.clearTimeout(searchTimer);
    filters.search = String($page.find("[data-sgp-search]").val() || "").trim().slice(0, 120);
    loadPage("", "push");
  });
  $page.on("input", "[data-sgp-search]", function () {
    window.clearTimeout(searchTimer);
    var value = String(this.value || "").trim().slice(0, 120);
    $page.find(".sgp-search__clear").prop("hidden", !value);
    searchTimer = window.setTimeout(function () { filters.search = value; loadPage("", "replace"); }, 400);
  });
  $page.on("click", "[data-sgp-search-clear]", function () {
    window.clearTimeout(searchTimer); filters.search = "";
    $page.find("[data-sgp-search]").val("").trigger("focus"); loadPage("", "push");
  });

  $page.on("click", "[data-sgp-retry]", function () { loadPage(currentCursor, "replace"); });
  $(document).on("sgp:refresh", function () { loadPage(currentCursor, "replace"); });

  // A paid or newly prepared row changes eligibility even if the bulk dialog
  // stays open after a partial failure. Refresh behind it without paying again.
  // Switching browser tabs or focusing the address bar must not erase rows,
  // selections or payment actions. The checkout revalidates current server state.
  $page.on("click", ".sgp-checkout", function () {
    var item = listedParcels[String($(this).closest("tr").attr("data-parcel-id"))];
    if (item && (item.can_resume_payment === true || item.can_check_payment === true)) $(document).trigger("sgp:checkout", [item]);
  });

  function focusFilters() {
    var $next = $page.find("[data-sgp-filter-chips] .sgp-filter-chip").first();
    ($next.length ? $next : $page.find("button[data-sgp-filter-modal]")).trigger("focus");
  }

  $page.on("click", "[data-sgp-remove-market]", function () {
    var market = $(this).attr("data-sgp-remove-market");
    filters.market_name = (filters.market_name || []).filter(function (selected) { return selected !== market; });
    loadPage("", "push");
    focusFilters();
  });
  $page.on("click", "[data-sgp-remove-status]", function () {
    filters.tab = "all";
    filters.status = "";
    loadPage("", "push");
    focusFilters();
  });
  $page.on("click", "[data-sgp-first-page]", function () { loadPage("", "push"); });

  // تغییر فیلتر/مرتب‌سازی/تب بدون بارگذاری کامل؛ URL برای رفرش و عقب/جلو حفظ می‌شود.
  $page.on("click", ".sgp-next, .sgp-prev", function () {
    var cursor = $(this).attr("data-cursor");
    if (filters.search && searchResults && searchKey === JSON.stringify([filters.search, filters.market_name, filters.status, filters.sort])) {
      renderSearchPage(cursor, "push"); return;
    }
    if (cursor && !$(this).prop("disabled")) loadPage(cursor, "push");
  });

  $page.find("[data-sgp-sort]").on("change", function () {
    filters.sort = $(this).val() === "asc" ? "asc" : "desc";
    loadPage("", "push");
  });
  $page.on("change", "[data-sgp-status]", function () {
    var status = String($(this).val() || "");
    filters.status = Object.prototype.hasOwnProperty.call(config.statusLabels || {}, status) ? status : "";
    filters.tab = "all";
    loadPage("", "push");
  });

  $page.on("click", "[data-sgp-tab]", function (event) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.which > 1) return;
    event.preventDefault();
    filters.tab = $(this).attr("data-sgp-tab");
    filters.status = tabStatus(filters.tab);
    loadPage("", "push");
  });

  $(window).on("popstate.salamGateParcels", function () {
    filters = stateFromUrl();
    loadPage(filters.cursor);
  });

  // ---------- مودال فیلتر بازار ----------
  $page.on("click", "button[data-sgp-filter-modal]", function () {
    updateFilterControls();
    $filterModal.show();
  });
  $(document).on("click", "[data-sgp-filter-modal].sg-modal-overlay [data-close-modal]", function () {
    $(this).closest(".sg-modal-overlay").hide();
  });
  $(document).on("click", "[data-sgp-filter-modal].sg-modal-overlay", function (e) {
    if (e.target === this) $(this).hide();
  });

  $(document).on("click", "[data-sgp-filter-apply]", function () {
    var market = $filterModal.find("input[name='sgp-market']:checked").val() || "";
    filters.market_name = market ? [market] : [];
    $filterModal.hide();
    loadPage("", "push");
  });

  $(document).on("click", "[data-sgp-filter-clear]", function () {
    filters.market_name = [];
    $filterModal.hide();
    loadPage("", "push");
  });

  // ---------- برچسب پستی: وضعیت job + polling با request_id مستقل ----------
  var pollTimer = null;
  var activeJobId = 0;
  var jobSequence = 0;
  var labelRequestPending = false;
  var latestJobPending = false;
  var jobStorageKey = "salamGateLabelJob:" + window.location.origin + ":" + (config.userId || 0);

  function validJobId(value) {
    if (typeof value !== "number" && !(typeof value === "string" && /^[1-9]\d*$/.test(value))) return 0;
    var number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : 0;
  }

  function updateLabelActions() {
    $page.find(".sgp-row-label, [data-sgp-print-modal], [data-sgp-print-toggle]")
      .add($("[data-sgp-print-modal-el] [data-sgp-print-submit]"))
      .prop("disabled", labelRequestPending || latestJobPending);
    updateSelection();
  }

  function selectedIds() {
    return Object.keys(selectedParcels).filter(function (id) {
      return labelCandidates[id] && labelCandidates[id].can_print_label === true;
    });
  }

  function selectionPrintMode() {
    return $printModal.find("[name='sgp-print-mode']:checked").val() === "selection";
  }

  function updateSelection() {
    var ids = selectedIds();
    var pending = labelRequestPending || latestJobPending;
    $printModal.find("[data-sgp-selected-count]").text(fa(ids.length) + " مرسوله انتخاب شده");
    $printModal.find("[data-sgp-clear-selection]").prop("disabled", !ids.length || pending);
    $printModal.find("[data-sgp-print-submit]").prop("disabled", pending || (selectionPrintMode() && (!ids.length || !!labelListRequest)));
    $printModal.find("[name='sgp-print-mode'], [name='sgp-template'], [data-sgp-select]").prop("disabled", pending);
    $printModal.find("[data-sgp-label-list-more]").prop("hidden", !labelListHasMore)
      .prop("disabled", pending || !!labelListRequest).text(labelListError ? "تلاش دوباره" : "نمایش مرسوله‌های بیشتر");
    $printModal.find("[data-sgp-label-list-state]").text(labelListRequest ? "در حال دریافت مرسوله‌های قابل چاپ…"
      : labelListError || (labelListReady ? fa(Object.keys(labelCandidates).length) + " مرسوله قابل چاپ" : ""));
  }

  function renderLabelCandidates() {
    var $items = $printModal.find("[data-sgp-label-candidates]").empty();
    Object.keys(labelCandidates).forEach(function (id) {
      var item = labelCandidates[id];
      $items.append($("<label class='sgp-print-choice'>").append(
        $("<input type='checkbox' data-sgp-select>").attr({ value: id, "aria-label": "انتخاب مرسوله " + fa(id) + " برای چاپ" }).prop("checked", !!selectedParcels[id]),
        $("<span class='sgp-print-choice__content'>").append(
          $("<strong>").text(item.receiver_name || "گیرنده مرسوله"),
          $("<small>").text("سفارش " + fa(item.order_number || item.external_id || item.id) + (item.market_label ? " · " + item.market_label : "")),
          $("<span class='sgp-print-choice__contents'>").text(item.contents || "").attr("title", item.contents || "")
        ),
        $("<span class='sgp-print-choice__receipt'>").append(
          $("<small>").text("پرداخت شده"), $("<bdi dir='ltr'>").text(item.tracking_code || "")
        )
      ));
    });
    if (!$items.children().length && labelListReady && !labelListError) {
      $items.append($("<p class='sgp-print-picker__empty'>").text(labelListHasMore
        ? "در این بخش مرسوله قابل چاپی نیست؛ مرسوله‌های بیشتری را نمایش دهید." : "مرسوله‌ای با پرداخت تکمیل‌شده و کد رهگیری پیدا نشد."));
    }
    updateSelection();
  }

  function resetLabelSelection() {
    ++labelListVersion;
    if (labelListRequest && typeof labelListRequest.abort === "function") labelListRequest.abort();
    labelListRequest = null;
    selectedParcels = Object.create(null); labelCandidates = Object.create(null); labelListSeen = Object.create(null);
    labelListCursor = ""; labelListHasMore = true; labelListReady = false; labelListConnection = null; labelListError = "";
    $printModal.find("[data-sgp-label-candidates]").empty();
  }

  function loadLabelCandidates() {
    if (labelListRequest || !labelListHasMore || labelRequestPending || latestJobPending) return;
    var version = ++labelListVersion, cursor = labelListCursor;
    labelListError = "";
    var request = sgPost("salam_gate_parcels_list", { cursor: cursor, status: "", sort: "desc", market_name: filters.market_name || [], label_selection: "1" });
    labelListRequest = request;
    updateSelection();
    function failed(message) {
      labelListError = message;
      if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, "error");
      renderLabelCandidates();
    }
    request.done(function (res) {
      if (version !== labelListVersion) return;
      var data = res && res.success === true && res.data;
      if (!data || !Array.isArray(data.items) || !data.items.every(function (item) { return $.isPlainObject(item) && validJobId(item.id); })) {
        failed("فهرست مرسوله‌های قابل چاپ دریافت نشد؛ دوباره تلاش کنید."); return;
      }
      if (typeof data.connection_id === "string") {
        if (labelListConnection !== null && labelListConnection !== data.connection_id) {
          selectedParcels = Object.create(null); labelCandidates = Object.create(null);
          labelListCursor = ""; labelListConnection = null; labelListSeen = Object.create(null);
          failed("حساب متصل تغییر کرده است؛ فهرست را دوباره دریافت کنید."); return;
        }
        labelListConnection = data.connection_id;
      }
      labelListSeen[cursor] = true;
      data.items.forEach(function (item) {
        var id = String(item.id);
        if (item.can_print_label === true) labelCandidates[id] = item;
        else { delete labelCandidates[id]; delete selectedParcels[id]; }
      });
      labelListReady = true;
      var next = typeof data.next_cursor === "string" ? data.next_cursor : "";
      if (next && labelListSeen[next]) {
        labelListHasMore = false;
        failed("ادامه فهرست مرسوله‌ها تأیید نشد؛ پنجره چاپ را دوباره باز کنید."); return;
      }
      labelListCursor = next; labelListHasMore = !!next;
      renderLabelCandidates();
    }).fail(function (_, status) {
      if (version !== labelListVersion || status === "abort") return;
      failed("ارتباط برای دریافت مرسوله‌های قابل چاپ برقرار نشد؛ دوباره تلاش کنید.");
    }).always(function () {
      if (version !== labelListVersion || labelListRequest !== request) return;
      labelListRequest = null; updateSelection();
    });
  }

  $(document).on("change", "[data-sgp-print-modal-el] [data-sgp-select]", function () {
    var id = String(this.value);
    if (this.checked && labelCandidates[id] && labelCandidates[id].can_print_label === true) {
      if (!selectedParcels[id] && selectedIds().length >= 100) {
        this.checked = false;
        if (typeof window.woosalamNotify === "function") window.woosalamNotify("در هر درخواست چاپ حداکثر ۱۰۰ مرسوله را انتخاب کنید.", "error");
      } else selectedParcels[id] = true;
    }
    else delete selectedParcels[id];
    updateSelection();
  });
  $(document).on("click", "[data-sgp-print-modal-el] [data-sgp-clear-selection]", function () {
    var $firstSelected = $printModal.find("[data-sgp-select]:checked").first();
    selectedParcels = Object.create(null);
    $printModal.find("[data-sgp-select]").prop("checked", false);
    updateSelection();
    $firstSelected.trigger("focus");
  });
  $(document).on("click", "[data-sgp-label-list-more]", loadLabelCandidates);
  $(document).on("change", "[data-sgp-print-modal-el] [name='sgp-print-mode']", function () {
    var selection = selectionPrintMode();
    $printModal.find("[data-sgp-print-date-view]").prop("hidden", selection);
    $printModal.find("[data-sgp-print-selection-view]").prop("hidden", !selection);
    $printModal.find(".sgp-print-dialog").toggleClass("is-selection", selection);
    $("[data-sgp-print-notice]").hide().empty();
    if (selection && !labelListReady) loadLabelCandidates();
    updateSelection();
  });

  $page.on("click", ".sgp-row-label", function () {
    requestLabelForIds([String($(this).closest("tr").attr("data-parcel-id"))], defaultLabelTemplate);
  });

  function restoreLabelJob(previous) {
    // A failed/unknown PDF request can be regenerated safely; this does not
    // retry parcel registration or payment. Only known job IDs are resumable.
    activeJobId = previous.id;
    latestJobPending = previous.latest;
    try {
      if (latestJobPending) window.sessionStorage.setItem(jobStorageKey, "latest");
      else if (activeJobId) window.sessionStorage.setItem(jobStorageKey, String(activeJobId));
      else window.sessionStorage.removeItem(jobStorageKey);
    } catch (error) { /* Private mode. */ }
    updateLabelActions();
  }

  function rememberJob(value) {
    activeJobId = validJobId(value);
    if (activeJobId) {
      latestJobPending = false;
      try { window.sessionStorage.setItem(jobStorageKey, String(activeJobId)); } catch (error) { /* Private mode. */ }
    }
    updateLabelActions();
  }

  function markLatestJob() {
    activeJobId = 0;
    latestJobPending = true;
    // A transport interruption before request_id arrives must not restore an
    // older request after refresh. Only an explicit status GET can reconcile it.
    try { window.sessionStorage.setItem(jobStorageKey, "latest"); } catch (error) { /* Private mode. */ }
    updateLabelActions();
  }

  function recoverLabel(text, noAuto, kind) {
    var floating = kind !== "info" && typeof window.woosalamNotify === "function";
    var $status = $page.find(".sgp-label-status").empty().show().toggleClass("sg-feedback-actions", floating).attr("role", floating ? "group" : (kind === "info" ? "status" : "alert"));
    $status.append($("<span>").text(text).toggleClass("sg-feedback-toast-only", floating));
    if (floating) window.woosalamNotify(text, "error");
    if (activeJobId) {
      $status.append(document.createTextNode(" — درخواست #" + fa(activeJobId) + " "),
        $("<button type='button' class='button button-small' data-sgp-resume-label>").text("بررسی وضعیت فایل"));
    } else if (latestJobPending) {
      $status.append(document.createTextNode(noAuto
        ? " برای بررسی دوباره وضعیت، کمی بعد صفحه را تازه کنید."
        : " وضعیت آخرین فایل به‌صورت خودکار بررسی می‌شود."));
    }
  }

  function safeLabelUrl(value) {
    if (typeof value !== "string" || !/^https?:\/\//i.test(value)) return null;
    try {
      var url = new URL(value);
      return ["https:", "http:"].indexOf(url.protocol) !== -1 && !url.username && !url.password ? url.href : null;
    } catch (error) { return null; }
  }

  var reservedLabelWindow = null;
  function reserveLabelWindow() {
    // Reserve from the actual click before asynchronous PDF generation.
    closeReservedLabelWindow();
    try {
      reservedLabelWindow = window.open("about:blank", "_blank");
      if (reservedLabelWindow) {
        reservedLabelWindow.opener = null;
        reservedLabelWindow.document.title = "در حال دریافت فایل چاپ…";
      }
    } catch (_) { reservedLabelWindow = null; }
  }
  function closeReservedLabelWindow() {
    if (reservedLabelWindow) {
      try { reservedLabelWindow.close(); } catch (_) { /* Already closed. */ }
      reservedLabelWindow = null;
    }
  }
  function nativeLabelUrl(requestId) {
    if (!validJobId(requestId) || !window.basalam_ajax || !window.basalam_ajax.nonce) return null;
    try {
      var url = new URL(window.basalam_ajax.ajaxurl, window.location.href);
      if (url.origin !== window.location.origin || url.username || url.password) return null;
      url.search = ""; url.hash = "";
      url.searchParams.set("action", "salam_gate_label_preview");
      url.searchParams.set("_wpnonce", window.basalam_ajax.nonce);
      url.searchParams.set("request_id", String(requestId));
      return url.href;
    } catch (_) { return null; }
  }

  function readyLabel(result) {
    var url = safeLabelUrl(result.url) && nativeLabelUrl(result.request_id);
    // An API timestamp without an offset is not browser-local time. The
    // authenticated PDF read revalidates the job; don't guess its timezone.
    var explicitExpiry = typeof result.expire_at === "string" && /(?:Z|[+-]\d{2}:?\d{2})$/i.test(result.expire_at)
      ? Date.parse(result.expire_at) : NaN;
    if (!url || Number.isFinite(explicitExpiry) && explicitExpiry <= Date.now()) {
      closeReservedLabelWindow();
      recoverLabel("نشانی فایل چاپ معتبر نیست؛ وضعیت فایل را دوباره بررسی کنید.");
      return;
    }
    var floating = typeof window.woosalamNotify === "function";
    // Stream the original PDF to the native browser viewer, preserving embedded
    // fonts and its actual print/download controls instead of rendering canvas.
    $page.find(".sgp-label-status").empty().removeClass("sg-feedback-actions sg-feedback-toast-only")
      .attr("role", "status").text(floating ? "" : "فایل چاپ آماده است.").toggle(!floating);
    var target = reservedLabelWindow;
    reservedLabelWindow = null;
    try {
      if (!target || target.closed) target = window.open("about:blank", "_blank");
      if (!target) throw new Error("Popup blocked");
      target.opener = null;
      target.location.replace(url);
    } catch (_) {
      $page.find(".sgp-label-status").empty().append($("<a target='_blank' rel='noopener noreferrer'>")
        .attr("href", url).text("مشاهده فایل PDF چاپ")).show();
      if (floating) window.woosalamNotify("مرورگر بازشدن تب PDF را مسدود کرد؛ از لینک مشاهده PDF استفاده کنید.", "warning");
      return;
    }
    if (floating) window.woosalamNotify("فایل چاپ آماده است.", "success");
  }

  function labelResult(result, sequence) {
    if (sequence !== jobSequence) return;
    if (result.status === "not_found") {
      // JSON null is a valid latest-job read, not an invalid API response. An
      // absent job still cannot prove an interrupted create will never appear.
      stopPolling();
      closeReservedLabelWindow();
      recoverLabel("درخواست چاپ پیدا نشد؛ نتیجه درخواست قبلی هنوز تأیید نشده است. درخواست جدید ثبت نکنید.", true);
      return;
    }
    if (activeJobId && validJobId(result.request_id) && validJobId(result.request_id) !== activeJobId) {
      stopPolling(); closeReservedLabelWindow();
      recoverLabel("پاسخ فایل مربوط به این درخواست نیست؛ وضعیت همین فایل را دوباره بررسی کنید.");
      return;
    }
    if (validJobId(result.request_id)) rememberJob(result.request_id);
    if (result.status === "completed" && result.url) {
      readyLabel(result);
    } else if (result.status === "failed" || result.status === "expired") {
      closeReservedLabelWindow();
      stopPolling();
      recoverLabel(readableMessage(result.message, "ساخت فایل چاپ انجام نشد؛ می‌توانید درخواست جدید ثبت کنید."));
    } else if (!activeJobId) {
      closeReservedLabelWindow();
      // An unscoped latest request can belong to another selected parcel/tab.
      // Regenerating this PDF is safe; never replay shipping or payment here.
      restoreLabelJob({ id: 0, latest: false });
      recoverLabel("فایل چاپ تأیید نشد؛ دوباره درخواست چاپ را بزنید. ثبت مرسوله یا پرداخت دوباره انجام نمی‌شود.");
    } else {
      statusText("در حال آماده‌سازی فایل چاپ… (درخواست #" + fa(activeJobId) + ")");
      pollLabel(activeJobId, result.retry_after || 5, sequence);
    }
  }

  function stopPolling() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  }

  // خروج از صفحه polling را متوقف می‌کند (نتیجه با request_id قابل بازیابی می‌ماند)
  $(window).on("beforeunload pagehide", function () { stopPolling(); closeReservedLabelWindow(); });

  function pollLabel(requestId, retryAfter, sequence) {
    stopPolling();
    if (requestId) rememberJob(requestId);
    if (sequence == null) sequence = ++jobSequence;
    pollTimer = setTimeout(function () {
      if (sequence !== jobSequence) return;
      sgPost("salam_gate_label_poll", { request_id: requestId || 0 }).done(function (res) {
        if (sequence !== jobSequence) return;
        if (!res || !res.success) {
          closeReservedLabelWindow();
          recoverLabel(readableMessage(res && res.data && res.data.message, "پیگیری فایل متوقف شد؛ دوباره وضعیت همین فایل را بررسی کنید."));
          return;
        }
        labelResult(res.data || {}, sequence);
      }).fail(function () {
        if (sequence !== jobSequence) return;
        closeReservedLabelWindow();
        recoverLabel("ارتباط برقرار نشد؛ درخواست تولید جدید ثبت نکنید و وضعیت همین فایل را بررسی کنید.");
      });
    }, Math.min(60, Math.max(2, Number(retryAfter) || 5)) * 1000);
  }

  function bulkPrintTemplate() {
    return bulkPrintKind === "barcode" ? "barcode" : String($printModal.find("[name='sgp-template']:checked").val() || defaultLabelTemplate);
  }

  function updatePrintSubmitLabel() {
    $printModal.find("[data-sgp-print-submit-label]").text(bulkPrintKind === "barcode" ? "چاپ بارکدها" : "چاپ برچسب‌های پستی");
  }

  function requestLabelForIds(ids, template) {
    if (labelRequestPending || latestJobPending) return;
    ids = ids.filter(function (id) { return (listedParcels[id] && listedParcels[id].can_print_label === true)
      || (labelCandidates[id] && labelCandidates[id].can_print_label === true); });
    if (!ids.length) return;
    reserveLabelWindow();
    labelRequestPending = true;
    stopPolling();
    var previousJob = { id: activeJobId, latest: latestJobPending };
    markLatestJob();
    var sequence = ++jobSequence;
    statusText("در حال ثبت درخواست چاپ…");
    sgPost("salam_gate_bulk_label", { parcel_ids: ids, template: template })
      .done(function (res) {
        if (!res || !res.success) {
          closeReservedLabelWindow();
          restoreLabelJob(previousJob);
          recoverLabel(readableMessage(res && res.data && res.data.message,
            res && res.data && res.data.request_dispatched === false ? "این مرسوله هنوز آماده چاپ نیست؛ وضعیت پرداخت و کد رهگیری را بررسی کنید." : "فایل چاپ دریافت نشد؛ دوباره درخواست چاپ را بزنید. ثبت مرسوله یا پرداخت دوباره انجام نمی‌شود."));
          return;
        }
        $printModal.hide();
        labelResult(res.data || {}, sequence);
      })
      .fail(function () {
        closeReservedLabelWindow();
        restoreLabelJob(previousJob);
        recoverLabel("فایل چاپ دریافت نشد؛ دوباره درخواست چاپ را بزنید. ثبت مرسوله یا پرداخت دوباره انجام نمی‌شود.");
      }).always(function () {
        labelRequestPending = false;
        updateLabelActions();
      });
  }

  $page.on("click", "[data-sgp-resume-label]", function () {
    reserveLabelWindow();
    statusText("در حال بررسی وضعیت فایل…");
    pollLabel(activeJobId, 2);
  });

  // Disclosure menu: opening it never dispatches a PDF job or payment.
  function closePrintMenu(restoreFocus) {
    $printMenu.find("[data-sgp-print-options]").prop("hidden", true);
    $printMenu.find("[data-sgp-print-toggle]").attr("aria-expanded", "false");
    if (restoreFocus) $printMenu.find("[data-sgp-print-toggle]").trigger("focus");
  }
  $page.on("click", "[data-sgp-print-toggle]", function () {
    var open = $(this).attr("aria-expanded") !== "true";
    $(this).attr("aria-expanded", String(open));
    $printMenu.find("[data-sgp-print-options]").prop("hidden", !open);
  });
  $(document).on("click", function (event) {
    if (!$(event.target).closest("[data-sgp-print-menu]").length) closePrintMenu(false);
  });
  $printMenu.on("keydown", function (event) {
    if (event.key === "Escape") { event.preventDefault(); closePrintMenu(true); }
    if (event.key === "ArrowDown" && $(event.target).is("[data-sgp-print-toggle]")) {
      event.preventDefault();
      $printMenu.find("[data-sgp-print-toggle]").attr("aria-expanded", "true");
      $printMenu.find("[data-sgp-print-options]").prop("hidden", false).find("button").first().trigger("focus");
    }
  }).on("focusout", function (event) {
    if (!event.relatedTarget || !this.contains(event.relatedTarget)) closePrintMenu(false);
  });

  // ---------- Batch labels / barcodes by date or selection ----------
  $page.on("click", "[data-sgp-print-modal]", function () {
    if (labelRequestPending || latestJobPending) return;
    bulkPrintKind = $(this).attr("data-sgp-print-kind") === "barcode" ? "barcode" : "label";
    closePrintMenu(false);
    $printModal.find("#sgp-print-title").text(bulkPrintKind === "barcode" ? "چاپ گروهی بارکدها" : "چاپ گروهی برچسب‌های پستی");
    $printModal.find("[data-sgp-label-options]").prop("hidden", bulkPrintKind === "barcode");
    updatePrintSubmitLabel();
    // باز کردن مودال درخواست تولید نمی‌سازد — فقط فرم
    resetLabelSelection();
    $printModal.find("[name='sgp-print-mode'][value='date']").prop("checked", true);
    $printModal.find("[data-sgp-print-date-view]").prop("hidden", false);
    $printModal.find("[data-sgp-print-selection-view]").prop("hidden", true);
    $printModal.find(".sgp-print-dialog").removeClass("is-selection");
    $printModal.show(); updateSelection();
    $("[data-sgp-print-notice]").hide().empty();
  });

  $(document).on("click", "[data-sgp-print-modal-el] [data-close-modal]", function () {
    resetLabelSelection();
    $(this).closest(".sg-modal-overlay").hide();
  });
  $(document).on("click", "[data-sgp-print-modal-el]", function (e) {
    if (e.target === this) { resetLabelSelection(); $(this).hide(); }
  });

  $(document).on("click", "[data-sgp-print-submit]", function () {
    if (labelRequestPending || latestJobPending) return;
    if (selectionPrintMode()) {
      if (labelListRequest || !selectedIds().length) return;
      requestLabelForIds(selectedIds(), bulkPrintTemplate());
      return;
    }
    var $n = $("[data-sgp-print-notice]").hide().empty();
    function printError(message) {
      var floating = typeof window.woosalamNotify === "function";
      $n.text(message).toggleClass("sg-feedback-toast-only", floating).attr("role", floating ? "group" : "alert").show();
      if (floating) window.woosalamNotify(message, "error");
    }
    var from = String($("[data-sgp-from]").val() || "").trim();
    var to = String($("[data-sgp-to]").val() || "").trim();
    if (!from || !to) {
      printError("تاریخ شروع و پایان بازه چاپ را انتخاب کنید.");
      (!from ? $("[data-sgp-from]") : $("[data-sgp-to]")).trigger("focus");
      return;
    }
    labelRequestPending = true;
    stopPolling();
    var previousJob = { id: activeJobId, latest: latestJobPending };
    markLatestJob();
    var sequence = ++jobSequence;
    $(this).prop("disabled", true).find("[data-sgp-print-submit-label]").text("در حال ثبت…");
    var markets = filters.market_name && filters.market_name.length ? filters.market_name.slice() : [];
    var dateTemplate = bulkPrintTemplate();

    reserveLabelWindow();
    function dateStep(generate) {
      sgPost("salam_gate_bulk_label_by_date", { from_jalali: from, to_jalali: to, template: dateTemplate, market_names: markets, generate: generate ? "1" : "0" })
        .done(function (res) {
          if (sequence !== jobSequence) return;
          var data = res && res.data;
          if (res && res.success && data && (data.discovery_state === "scanning" || data.discovery_state === "verifying" || data.discovery_state === "ready")) {
            $printModal.find("[data-sgp-print-submit-label]").text("در حال بررسی فهرست… " + String(data.pages_checked || 0));
            // Only successful read checkpoints advance automatically. A failed/unknown generation never replays.
            window.setTimeout(function () { if (sequence === jobSequence) dateStep(data.discovery_state === "ready"); }, 100);
            return;
          }
          labelRequestPending = false;
          updatePrintSubmitLabel(); updateLabelActions();
          if (res && res.success && data) { $printModal.hide(); labelResult(data, sequence); return; }
          closeReservedLabelWindow();
          if (data && data.request_dispatched === true) {
            $printModal.hide();
            recoverLabel("نتیجه ساخت فایل مشخص نیست؛ برای بررسی آخرین درخواست صفحه را تازه کنید.", true);
          } else {
            restoreLabelJob(previousJob);
            printError(readableMessage(data && data.message, "بررسی چاپ کامل نشد؛ دوباره برای ادامه اقدام کنید."));
          }
        })
        .fail(function () {
          labelRequestPending = false;
          updatePrintSubmitLabel(); updateLabelActions();
          closeReservedLabelWindow();
          if (generate) {
            $printModal.hide();
            recoverLabel("نتیجه ساخت فایل دریافت نشد؛ برای بررسی آخرین درخواست صفحه را تازه کنید.", true);
          } else {
            restoreLabelJob(previousJob);
            printError("نتیجه بررسی دریافت نشد؛ با اقدام دوباره از وضعیت ذخیره‌شده ادامه می‌دهیم.");
          }
        });
    }
    dateStep(false);
  });

  // ---------- جزئیات + رهگیری ----------
  var $detail = $("#sgp-detail-modal");
  var detailSequence = 0;
  var detailRequest = null;
  var detailItem = null;
  var detailCancelBusy = false;

  function dateText(value) {
    if (typeof value !== "string" || !value) return "—";
    var date = new Date(value);
    return Number.isFinite(date.getTime()) ? date.toLocaleString("fa-IR", { timeZone: "Asia/Tehran", dateStyle: "short", timeStyle: "short" }) : "—";
  }

  function paymentStatusLabel(value, state) {
    return ["pending", "unknown"].indexOf(state) !== -1 ? "در حال بررسی پرداخت" : value;
  }

  function renderDetail(item) {
    $detail.find(".sgd-id").text(fa(item.id));
    $detail.find("[data-sgd-cancel]").prop("hidden", item.can_cancel !== true);
    $detail.find("[data-sgd-cancel-check]").prop("hidden", item.can_check_cancel !== true);
    var $info = $detail.find(".sgd-info").empty();
    var $summary = $("<div class='sgd-summary'>").append(
      $("<span class='sgd-market'>").text(item.market_label || "—"),
      $("<span class='sgd-status'>").attr("data-status", item.status_id).text(item.can_resume_payment ? "در انتظار پرداخت" : paymentStatusLabel(item.status_label, item.payment_state) || "—"));
    if (item.created_at_display) $summary.append($("<span class='sgd-date'>").text(fa(item.created_at_display)));
    $info.append($summary);
    function section(title, icon, rows) {
      var $section = $("<section class='sgd-section'>").append($("<h5>").append(
        $("<span aria-hidden='true' class='dashicons'>").addClass("dashicons-" + icon), document.createTextNode(title)));
      var $rows = $("<dl class='sgd-grid'>");
      rows.forEach(function (row) {
        if (row[1] == null || row[1] === "") return;
        $rows.append($("<div>").toggleClass("sgd-wide", row[2] === true).append($("<dt>").text(row[0]), $("<dd>").text(row[1])));
      });
      return $section.append($rows);
    }
    $info.append(section("گیرنده", "location", [
      ["نام", item.receiver_name], ["شهر", item.receiver_city], ["تلفن", item.receiver_mobile],
      ["کد پستی", item.receiver_postal_code], ["نشانی", item.receiver_address, true]
    ]));
    $info.append(section("اطلاعات ارسال", "archive", [
      ["شیوه ارسال", item.method_title], ["کد رهگیری", item.tracking_code],
      ["وزن", Number.isFinite(item.weight) ? faThousands(item.weight) + " گرم" : null],
      ["بسته‌بندی", item.box_type], ["نوع مرسوله", item.category_title],
      ["هزینه نهایی", Number.isFinite(item.shipping_cost_toman) ? window.SalamGateAmount.fromRial(item.shipping_cost_rial) : null],
      ["مرکز تحویل", item.delivery_node, true]
    ]));
    var items = Array.isArray(item.items) ? item.items : [];
    $detail.find("[data-sgd-items-panel]").prop("hidden", items.length === 0);
    $detail.find("[data-sgd-items-count]").text(fa(items.length) + " قلم");
    var $items = $detail.find(".sgd-items").empty();
    items.forEach(function (entry) {
      $items.append($("<div class='sg-wizard__review-row'>").append(
        $("<dt>").text(entry.title || "—"),
        $("<dd>").text("× " + (entry.quantity != null ? fa(entry.quantity) : "—"))
      ));
    });
  }

  function cancelDetailRequest() {
    ++detailSequence;
    if (detailRequest && typeof detailRequest.abort === "function") detailRequest.abort();
    detailRequest = null;
    if (window.WoosalamGateLoading) window.WoosalamGateLoading.begin($detail.find("[data-sg-load]")[0], "detail", function () { $detail.find("[data-sgd-refresh]").trigger("click"); }).ready();
    $detail.find("[data-sgd-refresh]").prop("disabled", false).text("به‌روزرسانی جزئیات");
  }

  function detailError(message, announce) {
    message = readableMessage(message, "جزئیات تازه دریافت نشد؛ اطلاعات قبلی حفظ شده است. دوباره تلاش کنید.");
    var floating = typeof window.woosalamNotify === "function";
    $detail.find("[data-sgd-error]").text(message).toggleClass("sg-feedback-toast-only", floating).attr("role", floating ? "group" : "alert").show();
    if (floating && announce !== false) window.woosalamNotify(message, "error");
    return message;
  }

  $page.on("click", ".sgp-detail", function () {
    if (detailCancelBusy) return;
    var item = listedParcels[String($(this).closest("tr").attr("data-parcel-id"))];
    if (!item) return;
    cancelDetailRequest();
    detailItem = $.extend({}, item);
    $detail.find("[data-sgd-error]").hide().empty();
    $detail.find("[data-sgd-cancel-review]").prop("hidden", true);
    renderDetail(detailItem);
    $detail.find("details").prop("open", false);
    $detail.show();
  });

  $(document).on("click", "#sgp-detail-modal [data-sgd-refresh]", function () {
    if (!detailItem || detailRequest || detailCancelBusy) return;
    var parcelId = detailItem.id;
    var sequence = ++detailSequence;
    var detailLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($detail.find("[data-sg-load]")[0], "detail", function () { $detail.find("[data-sgd-refresh]").trigger("click"); });
    $detail.find("[data-sgd-error]").hide().empty();
    $detail.find("[data-sgd-refresh]").prop("disabled", true).text("در حال به‌روزرسانی…");
    detailRequest = sgPost("salam_gate_parcel_detail", { parcel_id: parcelId })
      .done(function (res) {
        if (sequence !== detailSequence) return;
        if (!res || !res.success) {
          var error = detailError(res && res.data && res.data.message, !detailLoading);
          if (detailLoading) detailLoading.fail(error);
          return;
        }
        var data = res.data || {};
        var p = data.parcel || {};
        if (String(p.id) !== String(parcelId)) {
          detailError("جزئیات دریافتی متعلق به این مرسوله نیست؛ دوباره بررسی کنید.", !detailLoading);
          if (detailLoading) detailLoading.fail("جزئیات دریافتی متعلق به این مرسوله نیست؛ دوباره بررسی کنید.");
          return;
        }
        // Detail API intentionally omits list-only invoice/market/tracking fields.
        // Keep those from the last verified list snapshot rather than replacing them with null.
        ["receiver_name", "delivery_node", "weight", "value_toman", "box_type", "created_at_display", "status_label"].forEach(function (key) {
          if (p[key] != null) detailItem[key] = p[key];
        });
        var detail = data.detail || {};
        var receiver = (detail.shipping_info || {}).receiver || {};
        if (receiver.city && receiver.city.title) detailItem.receiver_city = receiver.city.title;
        if (receiver.phone != null) detailItem.receiver_mobile = receiver.phone;
        if (receiver.postal_code != null) detailItem.receiver_postal_code = receiver.postal_code;
        if (receiver.address != null) detailItem.receiver_address = receiver.address;
        if (Array.isArray(detail.items)) detailItem.items = detail.items;
        renderDetail(detailItem);

        if (detailLoading) detailLoading.ready();
      })
      .fail(function (xhr, status) {
        if (sequence !== detailSequence || status === "abort") return;
        detailError("ارتباط قطع شد؛ جزئیات قبلی حفظ شده است. دوباره تلاش کنید.", !detailLoading);
        if (detailLoading) detailLoading.fail("ارتباط قطع شد؛ جزئیات قبلی حفظ شده است. دوباره تلاش کنید.");
      }).always(function () {
        if (sequence !== detailSequence) return;
        detailRequest = null;
        $detail.find("[data-sgd-refresh]").prop("disabled", false).text("به‌روزرسانی جزئیات");
      });
  });

  function updateCancellationRow(item) {
    var id = String(item.id);
    delete selectedParcels[id];
    delete labelCandidates[id];
    if (Array.isArray(searchResults)) searchResults = searchResults.map(function (row) { return String(row.id) === id ? item : row; });
    var $row = $page.find("tr[data-parcel-id]").filter(function () { return $(this).attr("data-parcel-id") === id; });
    if ($row.length) $row.replaceWith(rowNode(item));
    updateLabelActions();
  }

  function cancelParcel(confirm) {
    if (!detailItem || detailCancelBusy || detailRequest) return;
    var id = detailItem.id;
    detailCancelBusy = true;
    $detail.attr("data-sg-financial-busy", "true").attr("aria-busy", "true");
    $detail.find("button").prop("disabled", true);
    $detail.find("[data-sgd-error]").hide().empty();
    $detail.find("[data-sgd-cancel-confirm]").text(confirm ? "در حال لغو…" : "تأیید لغو مرسوله");
    $detail.find("[data-sgd-cancel-check]").text(confirm ? "بررسی وضعیت لغو" : "در حال بررسی…");
    function uncertain(message) {
      if (!detailItem || detailItem.id !== id) return;
      detailItem.can_cancel = false; detailItem.can_check_cancel = true; detailItem.can_print_label = false;
      updateCancellationRow(detailItem); renderDetail(detailItem);
      detailError(message || "پاسخ کامل دریافت نشد؛ «بررسی وضعیت لغو» را بزنید. درخواست لغو خودکار تکرار نمی‌شود.");
    }
    sgPost("salam_gate_parcel_cancel", { parcel_id: id, confirm: confirm ? "1" : "0" })
      .done(function (res) {
        var data = res && res.success === true && res.data;
        if (!data || data.parcel_id !== id || !$.isPlainObject(data.parcel) || data.parcel.id !== id
            || ["cancelled", "review", "eligible", "retryable", "unavailable"].indexOf(data.state) === -1) {
          return uncertain(res && res.data && res.data.message);
        }
        if (data.state === "cancelled" && (data.parcel.status_id !== 44 || data.parcel.invoice_status !== 63 || data.parcel.can_print_label !== false)) return uncertain();
        detailItem = data.parcel;
        updateCancellationRow(detailItem); renderDetail(detailItem);
        $detail.find("[data-sgd-cancel-review]").prop("hidden", true);
        if (data.state === "cancelled" && data.order_synced !== false) {
          if (typeof window.woosalamNotify === "function") window.woosalamNotify(data.message, "success");
          else $detail.find("[data-sgd-error]").text(data.message).attr("role", "status").show();
          $(document).trigger("salamgate:parcel-cancelled", [id]);
        } else detailError(data.message);
      })
      .fail(function (xhr) { uncertain(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message); })
      .always(function () {
        detailCancelBusy = false;
        $detail.removeAttr("data-sg-financial-busy").attr("aria-busy", "false");
        $detail.find("button").prop("disabled", false);
        $detail.find("[data-sgd-cancel-confirm]").text("تأیید لغو مرسوله");
        $detail.find("[data-sgd-cancel-check]").text("بررسی وضعیت لغو");
        if (detailItem) renderDetail(detailItem);
        $detail.find("[data-sgd-cancel-review]").prop("hidden", true);
        if (detailItem) $detail.find(detailItem.can_check_cancel ? "[data-sgd-cancel-check]"
          : (detailItem.can_cancel ? "[data-sgd-cancel]" : "[data-sgd-refresh]")).trigger("focus");
      });
  }

  $(document).on("click", "#sgp-detail-modal [data-sgd-cancel]", function () {
    if (!detailItem || detailItem.can_cancel !== true || detailCancelBusy || detailRequest) return;
    $detail.find("[data-sgd-cancel-review]").prop("hidden", false);
    $detail.find("[data-sgd-cancel-back]").trigger("focus");
  });
  $(document).on("click", "#sgp-detail-modal [data-sgd-cancel-back]", function () {
    if (detailCancelBusy) return;
    $detail.find("[data-sgd-cancel-review]").prop("hidden", true);
    $detail.find("[data-sgd-cancel]").trigger("focus");
  });
  $(document).on("click", "#sgp-detail-modal [data-sgd-cancel-confirm]", function () {
    if (!detailItem || detailItem.can_cancel !== true || $detail.find("[data-sgd-cancel-review]").prop("hidden")) return;
    cancelParcel(true);
  });
  $(document).on("click", "#sgp-detail-modal [data-sgd-cancel-check]", function () { cancelParcel(false); });

  function closeDetail() { if (detailCancelBusy) return; cancelDetailRequest(); detailItem = null; $detail.hide(); }
  $(document).on("click", "#sgp-detail-modal [data-close-modal]", closeDetail);
  $(document).on("click", "#sgp-detail-modal", function (e) { if (e.target === this) closeDetail(); });
  $(document).on("keydown.sgpDetail", function (event) { if (event.key === "Escape" && detailItem) closeDetail(); });

  updateFilterControls();
  $page.find("[data-sgp-initial-error]").each(function () {
    var $state = $(this), message = $state.attr("data-sgp-initial-error");
    $state.attr("role", "status").prepend($("<p>").text(readableMessage(message, "فهرست مرسوله‌ها دریافت نشد؛ دوباره تلاش کنید.")));
  });
  // A previous page's PDF job must not create an unsolicited banner or open
  // an unrelated parcel's label. New explicit print actions own their exact job.
  try { window.sessionStorage.removeItem(jobStorageKey); } catch (error) { /* Private mode. */ }
  updateLabelActions();
  // فقط وقتی هیچ خروجی/خطای سروری نداریم، لیست اولیه را دریافت کن.
  if (config.deferredList === true || (!$("#sgp-body tr[data-parcel-id]").length && !$("#sgp-body .sgp-empty").length && !$page.find(".sgp-state--error").length)) {
    loadPage(currentCursor, "replace");
  }
});
