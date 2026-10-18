(function (window, $) {
  "use strict";
  if (!$ || typeof basalam_ajax === "undefined") return;
  $(function () {
    var $host = $("[data-sg-order-list-host]"), $native = $("#sg-order-list-native-modal");
    if (!$host.length || !$("#salam-gate-parcel-modal").length) return;
    var busy = false, sequence = 0, opener = null, rowReads = Object.create(null), nativeResolving = false;
    var $details = $("#sg-order-preview-modal"), detailsRequest = null, detailsEpoch = 0, detailsOrder = null, detailsOpener = null;
    function sourceOrderStatus($action) {
      var $mark = $action.closest("tr").find(".column-order_status .order-status").first();
      if (!$mark.length) return;
      var original = $mark.data("sgOriginalStatus");
      if (!original) {
        original = { html: $mark.html(), classes: $mark.attr("class"), tip: $mark.attr("data-tip"), title: $mark.attr("title") };
        $mark.data("sgOriginalStatus", original);
      }
      var label = $action.attr("data-sg-source-order-label"), tone = $action.attr("data-sg-source-order-tone");
      if (!label || ["cancelled", "processing", "on-hold", "completed"].indexOf(tone) === -1) {
        $mark.html(original.html).attr("class", original.classes);
        ["data-tip", "title"].forEach(function (name) { var value = name === "title" ? original.title : original.tip; if (value == null) $mark.removeAttr(name); else $mark.attr(name, value); });
        return;
      }
      $mark.attr("class", ($mark.attr("class") || "").split(/\s+/).filter(function (name) { return !/^status-/.test(name); }).join(" "))
        .addClass("status-" + tone).empty().append($("<span>").text(label))
        .attr({ title: $action.attr("data-sg-source-order-description") || label,
          "data-tip": $action.attr("data-sg-source-order-description") || label });
    }
    $(".sg-order-list-action[data-order-id]").each(function () { sourceOrderStatus($(this)); });
    function notify(message) {
      if (window.woosalamNotify) window.woosalamNotify(message, "error");
    }
    function updateRow(id, html) {
      if (!Number.isSafeInteger(id) || typeof html !== "string" || !html.trim()) return;
      rowReads[id] = (rowReads[id] || 0) + 1;
      var $action = $(".sg-order-list-action[data-order-id]").filter(function () { return this.getAttribute("data-order-id") === String(id); });
      var $cell = $action.closest(".column-salam_gate_action, .column-salam_gate_shipping");
      var $statusCell = $cell.closest("tr").find(".column-salam_gate_shipping");
      // Keep the row button node (and keyboard focus) when only its label/state changes.
      var $content = $("<div>").html(html), $next = $content.find(".sg-order-list-action");
      if (!$cell.length || !$next.length) return;
      if ($action[0].tagName === $next[0].tagName) {
        ["class", "href", "title", "aria-label", "aria-disabled", "data-sg-order-list-open", "data-sg-order-list-mode", "data-sg-order-details-open", "data-sg-source-order-label", "data-sg-source-order-tone", "data-sg-source-order-description"].forEach(function (name) {
          var value = $next.attr(name);
          if (value == null) $action.removeAttr(name); else $action.attr(name, value);
        });
        $action.html($next.html());
      } else {
        var restoreFocus = document.activeElement === $action[0];
        if (opener === $action[0]) opener = $next[0];
        $action.replaceWith($next);
        if (restoreFocus) $next.trigger("focus");
      }
      var $statuses = $statusCell.find(".sg-order-shipping-statuses"), $nextStatuses = $content.find(".sg-order-shipping-statuses");
      if (!$statuses.length) $statuses = $statusCell.find(".sg-order-shipping");
      if (!$nextStatuses.length) $nextStatuses = $content.find(".sg-order-shipping");
      if ($nextStatuses.length) {
        if ($statuses.length) $statuses.replaceWith($nextStatuses);
        else $statusCell.append($nextStatuses);
      }
      sourceOrderStatus($cell.find(".sg-order-list-action[data-order-id]").first());
    }
    $(document).on("salamgate:order-list-row-updated", function (event, result) {
      if (result) updateRow(result.order_id, result.row_html);
    });
    $(document).on("salamgate:order-list-changed", function (event, id) {
      if (!Number.isSafeInteger(id) || id <= 0) return;
      var epoch = rowReads[id] = (rowReads[id] || 0) + 1;
      var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
      // Row reads survive closing/switching the form, but an older read can
      // never overwrite a newer result for this same row. They do not replay writes.
      request(basalam_ajax.ajaxurl, { action: "salam_gate_refresh_metabox", _wpnonce: basalam_ajax.nonce, order_id: id })
        .done(function (response) {
          var data = response && response.success === true ? response.data : null;
          if (rowReads[id] === epoch && data && data.order_id === id) updateRow(id, data.row_html);
        });
    });
    function closeDetails(restore) {
      ++detailsEpoch;
      var request = detailsRequest; detailsRequest = null;
      if (request && typeof request.abort === "function") request.abort();
      detailsOrder = null;
      $details.hide().attr("aria-hidden", "true");
      $details.find("[data-sg-preview-content]").empty();
      $details.find("[data-sg-preview-loading], [data-sg-preview-error]").prop("hidden", true);
      if (restore && detailsOpener && document.contains(detailsOpener)) detailsOpener.focus();
    }
    function loadDetails() {
      if (!detailsOrder) return;
      var id = detailsOrder, epoch = ++detailsEpoch;
      if (detailsRequest && typeof detailsRequest.abort === "function") detailsRequest.abort();
      $details.find("[data-sg-preview-content]").empty();
      $details.find("[data-sg-preview-error], [data-sg-preview-reason]").prop("hidden", true);
      $details.find("[data-sg-preview-loading]").prop("hidden", false);
      $details.find("[data-sg-preview-body]").attr("aria-busy", "true");
      function current() { return epoch === detailsEpoch && detailsOrder === id && $details.css("display") !== "none"; }
      function fail(response) {
        if (!current()) return;
        var message = response && response.data && response.data.message;
        message = typeof message === "string" ? message : "جزئیات سفارش دریافت نشد؛ دوباره تلاش کنید.";
        $details.find("[data-sg-preview-message]").text(message);
        $details.find("[data-sg-preview-error]").prop("hidden", false);
        notify(message);
      }
      var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
      detailsRequest = request(basalam_ajax.ajaxurl, { action: "salam_gate_order_preview", _wpnonce: basalam_ajax.nonce,
        order_id: id, view: "cancelled-order" });
      detailsRequest.done(function (response) {
        if (!current()) return;
        var data = response && response.success === true ? response.data : null, selection = data && data.selection;
        if (!selection || selection.order_id !== id || selection.view !== "cancelled-order"
          || ["website", "basalam"].indexOf(selection.market) === -1
          || data.shipping_allowed !== false || typeof data.title !== "string" || typeof data.html !== "string") {
          fail(response); return;
        }
        $details.find("#sg-order-preview-title").text(data.title);
        // Only the guarded, escaped local-order template populates this view.
        $details.find("[data-sg-preview-content]").html(data.html);
      }).fail(function (xhr, status) {
        if (status !== "abort") fail(xhr && xhr.responseJSON);
      }).always(function () {
        if (!current()) return;
        detailsRequest = null;
        $details.find("[data-sg-preview-loading]").prop("hidden", true);
        $details.find("[data-sg-preview-body]").attr("aria-busy", "false");
      });
    }
    $(document).on("click.sgOrderDetails", "[data-sg-order-details-open]", function (event) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.which > 1) return;
      if (!$details.length) return; // Preserve the ordinary order-page fallback.
      event.preventDefault();
      var id = Number($(this).attr("data-order-id"));
      if (!Number.isSafeInteger(id) || id <= 0 || busy || detailsOrder
        || $host.attr("data-sg-order-financial-busy") === "1"
        || $("#salam-gate-parcel-modal").css("display") !== "none" || $native.css("display") !== "none"
        || $("#sg-bulk-shipping-modal").prop("hidden") === false) return;
      detailsOrder = id; detailsOpener = this;
      $details.find("#sg-order-preview-title").text("جزئیات سفارش");
      $details.show().attr("aria-hidden", "false");
      loadDetails();
      $details.find("#sg-order-preview-title").trigger("focus");
    });
    $details.on("click", "[data-sg-preview-close]", function () { closeDetails(true); });
    $details.on("click", "[data-sg-preview-retry]", function () { loadDetails(); });
    $details.on("click", function (event) { if (event.target === this) closeDetails(true); });
    $details.on("keydown", function (event) {
      if (event.key === "Escape") { event.preventDefault(); closeDetails(true); }
    });
    $(document).on("click.sgOrderList", "[data-sg-order-list-open]", function (event) {
      // A modified click retains the ordinary order-page fallback link.
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.which > 1) return;
      event.preventDefault();
      var $button = $(this), id = Number($button.attr("data-order-id"));
      if (busy || detailsOrder || $host.attr("data-sg-order-financial-busy") === "1"
        || $("#salam-gate-parcel-modal").css("display") !== "none"
        || $native.css("display") !== "none" || $("#sg-bulk-shipping-modal").prop("hidden") === false
        || !Number.isSafeInteger(id) || id <= 0) return;
      busy = true; opener = this;
      var epoch = ++sequence;
      $button.attr({ "aria-busy": "true", "aria-disabled": "true" }).addClass("is-loading");
      function fail(response) {
        var message = response && response.data && response.data.message;
        notify(typeof message === "string" ? message : "اطلاعات ارسال دریافت نشد؛ دوباره تلاش کنید. ثبت یا پرداختی انجام نشده است.");
      }
      var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
      request(basalam_ajax.ajaxurl, { action: "salam_gate_refresh_metabox", _wpnonce: basalam_ajax.nonce, order_id: id })
        .done(function (response) {
          if (epoch !== sequence) return;
          var data = response && response.success === true ? response.data : null;
          if (!data || data.order_id !== id || !data.list_action
            || ["website", "basalam"].indexOf(data.list_action.market) === -1
            || typeof data.html !== "string" || !data.html.trim()) { fail(response); return; }
          updateRow(id, data.row_html);
          if (["edit", "payment", "native", "receipt"].indexOf(data.list_action.mode) === -1) {
            notify(data.list_action.description || "وضعیت این مرسوله را از صفحه سفارش بررسی کنید."); return;
          }
          var previous = $host.data("sgNativeParcelsController");
          if (previous) previous.dispose();
          $host.attr({ "data-order-id": String(id), "data-basalam-order": data.list_action.market === "basalam" ? "1" : "0" })
            .data("order-id", id).html(data.html);
          $(document).trigger("salamgate:list-order-open", [{ order_id: id, market: data.list_action.market,
            mode: data.list_action.mode, trigger: opener }]);
        }).fail(function (xhr) { if (epoch === sequence) fail(xhr && xhr.responseJSON); })
        .always(function () {
          if (epoch !== sequence) return;
          busy = false;
          $button.removeAttr("aria-busy aria-disabled").removeClass("is-loading");
        });
    });
    function closeNative(restore) {
      if ($host.attr("data-sg-order-financial-busy") === "1") return;
      if (nativeResolving) {
        // Closing discovery must never open a checkout from a late response.
        var controller = $host.data("sgNativeParcelsController");
        if (controller) controller.dispose();
      }
      nativeResolving = false;
      $native.hide();
      if (restore && opener && document.contains(opener)) opener.focus();
    }
    function nativeLoading(loading) {
      nativeResolving = loading;
      $native.find("[data-sg-list-native-loading]").prop("hidden", !loading);
      $native.find("[data-sg-list-native-content]").prop("hidden", loading);
      $native.find("#sg-order-list-native-title").text(loading ? "ارسال با سلام‌گیت" : "مرسوله‌های سفارش باسلام");
      $native.find("[role='dialog']").attr("aria-busy", String(loading));
    }
    $(document).on("salamgate:list-native-show", function () {
      var epoch = sequence, order = $host.attr("data-order-id");
      var controller = $host.data("sgNativeParcelsController");
      nativeLoading(true);
      $native.show();
      $native.find("[data-sg-list-native-close]").trigger("focus");
      // This explicit row click performs read-only discovery, not registration/payment.
      // Skip the chooser only when the entire returned list confirms one exact child.
      var started = controller && controller.openCheckout(function (singleId) {
        if (epoch !== sequence || $native.css("display") === "none"
          || $host.attr("data-order-id") !== order) return;
        nativeLoading(false);
        if (!Number.isSafeInteger(singleId) || singleId <= 0) return;
        var $button = $host.find("[data-sg-native-parcel-row]").filter(function () {
          return this.getAttribute("data-sg-native-parcel-row") === String(singleId);
        }).find(".salam-gate-open-modal");
        if ($button.length === 1 && !$button.prop("disabled")) $button.trigger("click");
      });
      if (!started) nativeLoading(false);
    }).on("salamgate:list-native-hide", function () { closeNative(false); });
    $native.on("click", "[data-sg-list-native-close]", function () { closeNative(true); });
    $native.on("click", function (event) { if (event.target === this) closeNative(true); });
    $native.on("keydown", function (event) {
      if (event.key === "Escape") { event.preventDefault(); closeNative(true); }
    });
    $(window).on("pagehide", function () { ++sequence; closeDetails(false); });
  });
})(window, window.jQuery);

// Woo's top/bottom bulk selectors share one form (HPOS and legacy CPT).
// Intercept only our action: the official Salamgate action remains independent.
(function (window, $) {
  "use strict";
  if (!$) return;
  $(function () {
    var config = window.woosalamBulkShippingConfig || {}, action = config.orderAction || "salam_gate_create_parcels";
    function open(ids) {
      if (window.SalamGateBulkShipping) return window.SalamGateBulkShipping.open({ source: "orders", ids: ids });
      if (window.woosalamNotify) window.woosalamNotify("ارسال گروهی آماده نیست؛ صفحه را تازه‌سازی کنید.", "error");
      return false;
    }
    $(document).on("submit.sgBulkShipping", "form", function (event) {
      var $form = $(this), selected = $form.find("select[name='action']").val();
      if (!selected || selected === "-1") selected = $form.find("select[name='action2']").val();
      if (selected !== action) return;
      event.preventDefault();
      var ids = [];
      $form.find("input[name='id[]']:checked, input[name='post[]']:checked").each(function () {
        var id = Number(this.value);
        if (Number.isSafeInteger(id) && id > 0 && ids.indexOf(id) === -1) ids.push(id);
      });
      open(ids);
    });
    $(document).on("salamgate:bulk-item-changed", function (event, result) {
      if (result && result.source === "orders") $(document).trigger("salamgate:order-list-changed", [Number(result.id)]);
    });
    // Server-side fallback never registers parcels; it redirects to this review.
    var url = new URL(window.location.href), raw = url.searchParams.get("sg_bulk_orders");
    if (raw && /^(?:[1-9]\d*)(?:,[1-9]\d*)*$/.test(raw)) {
      var ids = raw.split(",").map(Number).filter(function (id) { return Number.isSafeInteger(id) && id > 0; });
      if (open(ids)) {
        url.searchParams.delete("sg_bulk_orders");
        window.history.replaceState({}, "", url.toString());
      }
    }
  });
})(window, window.jQuery);
