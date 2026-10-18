(function (window, $) {
  "use strict";
  if (!$) return;

  // Balance reads are separate from the existing payment handlers: never debit,
  // retry a payment, or use a displayed balance as payment authorization.
  var icons = {
    "20": '<svg viewBox="0 0 24 24" fill="none"><path d="m3 9 2-5h14l2 5m-18 0v2a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2V9H3Zm2 5v6h14v-6M9 20v-5h6v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    "21": '<svg viewBox="0 0 24 24" fill="none"><path d="M20 8V5a2 2 0 0 0-2-2H6a3 3 0 0 0 0 6h14v10H6a3 3 0 0 1-3-3V6m17 7h-4v3h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'
  };
  var balanceCache = {}, balanceCacheAge = 30000, automaticChoice = false;
  function methodBlocked(method) {
    var cached = balanceCache[String(method)];
    return !!cached && cached.blocked === true;
  }
  function balanceCard($card, blocked) {
    var $description = $card.find(".sg-payment-card__content small, .sg-send-payment-option__description").first();
    if ($description.data("sgBalanceDescription") === undefined) $description.data("sgBalanceDescription", $description.text());
    setText($description, blocked ? "این روش در حال حاضر قابل استفاده نیست." : $description.data("sgBalanceDescription"));
    $card.attr("data-sg-balance-blocked", blocked ? "true" : "false");
  }
  function methodValue(value) { return String(value) === "21" ? "21" : "20"; }
  function setText($element, value) {
    // The send view is observed; replacing identical text would cause a loop.
    if ($element.text() !== value) $element.text(value);
  }
  function toggleVisible($element, visible) {
    if ($element.prop("hidden") !== !visible) $element.prop("hidden", !visible);
  }
  function formatToman(value) {
    var formatted = Math.floor(Number(value)).toLocaleString("fa-IR", { maximumFractionDigits: 0 }).replace(/٬/g, ",");
    return formatted + " تومان";
  }
  function finiteAmount(value) {
    if (typeof value !== "number" && (typeof value !== "string" || !/^-?\d+(?:\.\d+)?$/.test(value))) return null;
    var number = Number(value);
    return Number.isFinite(number) ? number : null;
  }
  function walletStatus() {
    return $("<div class='sg-wallet-status' data-sg-wallet-status role='status'>").append(
      $("<div>").append(
        $("<span class='sg-wallet-status__title'>").text("موجودی تراز غرفه"),
        $("<strong class='sg-wallet-status__amount'>").text("در حال دریافت…"),
        $("<small hidden>")
      ),
      $("<div class='sg-wallet-status__actions'>").append(
        $("<button type='button' class='sg-wallet-status__refresh' data-sg-balance-refresh>").append(
          $("<span class='dashicons dashicons-update' aria-hidden='true'>"), $("<span>").text("به‌روزرسانی موجودی")
        ),
        $("<button type='button' class='sg-wallet-status__retry' data-sg-balance-retry hidden>").text("تلاش دوباره"),
        $("<a data-sg-wallet-topup hidden>").attr({ href: "https://wallet.basalam.com/?from=vendor", target: "_blank", rel: "noopener noreferrer" }).text("شارژ کیف پول ↗")
      )
    );
  }
  function renderBalance($statuses, method) {
    var value = methodValue(method), cached = balanceCache[value];
    $statuses.each(function () {
      var $status = $(this), state = cached ? cached.state : "idle";
      $status.attr("data-sg-balance-method", value).attr("aria-busy", state === "loading" ? "true" : "false");
      $status.toggleClass("is-loading", state === "loading").toggleClass("is-unavailable", state === "error" || methodBlocked(value));
      setText($status.find(".sg-wallet-status__title"), value === "21" ? "موجودی کیف پول" : "موجودی تراز غرفه");
      setText($status.find(".sg-wallet-status__amount"), methodBlocked(value) ? "این روش در دسترس نیست" : state === "ready" ? formatToman(cached.balance) : (state === "error" ? "در دسترس نیست" : "در حال دریافت…"));
      setText($status.find("small"), state === "error" ? "دریافت موجودی انجام نشد. دوباره تلاش کنید." : "");
      $status.find("small").toggleClass("sg-feedback-toast-only", state === "error" && typeof window.woosalamNotify === "function");
      toggleVisible($status.find("small"), state === "error");
      toggleVisible($status.find("[data-sg-balance-retry]"), state === "error");
      var refreshing = ["20", "21"].some(function (method) {
        return balanceCache[method] && balanceCache[method].state === "loading";
      });
      $status.find("[data-sg-balance-refresh]").each(function () { if (this.disabled !== refreshing) this.disabled = refreshing; });
      var amount = finiteAmount($status.closest("[data-sg-payable-rial]").attr("data-sg-payable-rial"));
      toggleVisible($status.find("[data-sg-wallet-topup]"), value === "21" && state === "ready"
        && Number.isSafeInteger(amount) && amount >= 0 && cached.rial < amount);
    });
  }
  function requestBalance(method, force) {
    var value = methodValue(method), previous = balanceCache[value];
    if (previous && previous.state === "loading") return previous.completion.promise();
    if (!force && previous && (previous.state === "error" || Date.now() - previous.updated < balanceCacheAge)) return previous.completion.promise();
    // Preserve a blocked wallet until a fresh valid nonnegative response arrives.
    // Vendor debt is displayed as zero; selecting it never authorizes payment.
    var cached = { state: "loading", blocked: !!previous && previous.blocked === true, updated: Date.now(), completion: $.Deferred() };
    balanceCache[value] = cached;
    var timer = window.setTimeout(unavailable, 25000);
    function complete() {
      window.clearTimeout(timer);
      cached.completion.resolve();
    }
    function unavailable() {
      if (balanceCache[value] !== cached || cached.state !== "loading") return;
      cached.state = "error";
      cached.updated = Date.now();
      if (typeof window.woosalamNotify === "function") window.woosalamNotify(value === "21" ? "دریافت موجودی کیف پول انجام نشد؛ دوباره تلاش کنید." : "دریافت موجودی تراز غرفه انجام نشد؛ دوباره تلاش کنید.", "error");
      refresh(window.document);
      complete();
    }
    var config = window.basalam_ajax;
    if (!config || !config.ajaxurl || !config.nonce || typeof $.post !== "function") { unavailable(); return cached.completion.promise(); }
    try {
      $.post(config.ajaxurl, { action: "salam_gate_payment_balance", _wpnonce: config.nonce, method: Number(value) })
        .done(function (response) {
          var data = response && response.success === true && response.data;
          var rial = data && finiteAmount(data.balance_rial), toman = data && finiteAmount(data.balance_toman);
          if (!data || String(data.method) !== value || rial === null || toman === null || Math.abs(toman - rial / 10) > 0.001) { unavailable(); return; }
          if (balanceCache[value] !== cached || cached.state !== "loading") return;
          cached.state = "ready";
          cached.rial = rial;
          cached.balance = value === "20" ? Math.max(0, toman) : toman;
          cached.blocked = value === "21" && toman < 0;
          cached.updated = Date.now();
          // Always read the currently selected method. A late wallet response
          // cannot replace the vendor balance after the user changes cards.
          refresh(window.document);
          complete();
        })
        .fail(unavailable);
    } catch (error) { unavailable(); }
    return cached.completion.promise();
  }
  function showBalance($statuses, method) {
    requestBalance(method, false);
    renderBalance($statuses, method);
  }
  function pickerIsPresented($picker) {
    if (!$picker.closest("#salam-gate-parcel-modal, #sgp-checkout-modal").length) return true;
    // The order picker exists in the hidden form from page load. Only its
    // visible quote/payment step needs an account balance read.
    return !$picker.parents().addBack().filter(function () {
      // Loader wrappers are temporarily concealed precisely while these reads
      // finish. Actual hidden stages/methods/modal still suppress unnecessary reads.
      if ($(this).is("[data-sg-load-content], [data-sg-order-content]")) return false;
      return this.hidden || $(this).css("display") === "none";
    }).length;
  }
  function chooseHighest($owner, values, current, select) {
    if ($owner.data("sgPaymentManual") || automaticChoice) return current;
    if (["20", "21"].some(function (id) { return !balanceCache[id] || balanceCache[id].state === "loading"; })) return current;
    var candidates = values.filter(function (id) { return balanceCache[id] && balanceCache[id].state === "ready" && !methodBlocked(id); });
    candidates.sort(function (a, b) { return balanceCache[b].rial - balanceCache[a].rial; });
    if (!candidates.length || candidates[0] === current) return current;
    automaticChoice = true;
    try { select(candidates[0]); } finally { automaticChoice = false; }
    return candidates[0];
  }
  function resetChoice(scope) {
    $(scope).find("[data-sg-payment-picker], [data-send-pay-methods] input[type=radio]")
      .addBack("[data-sg-payment-picker]").removeData("sgPaymentManual");
  }
  function syncPicker(picker, forceBalance) {
    var $picker = $(picker), $select = $picker.find(".sg-payment-native-select").first();
    if (!$select.length) return;
    // jQuery.val() returns null for a selected option once it is disabled.
    // Read the native value so a negative wallet balance discovered during loading can
    // still switch to an available method when the form is unlocked.
    var value = String($select[0].value || ""), disabled = $select.prop("disabled");
    $select.find("option").each(function () {
      var blocked = methodBlocked(this.value);
      if (blocked) {
        if (!this.hasAttribute("data-sg-balance-disabled")) this.setAttribute("data-sg-balance-disabled", this.disabled ? "previous" : "balance");
        if (!this.disabled) this.disabled = true;
      } else if (this.getAttribute("data-sg-balance-disabled") === "balance") {
        this.disabled = false;
        this.removeAttribute("data-sg-balance-disabled");
      }
    });
    if (!disabled && (!value || methodBlocked(value))) {
      var $alternative = $select.find("option").filter(function () { return !this.disabled && !methodBlocked(this.value); }).first();
      var next = $alternative.length ? String($alternative.val()) : "";
      if (next !== value) $select.val(next).trigger("change");
      value = next;
    }
    if (!disabled && pickerIsPresented($picker)) value = chooseHighest($picker,
      $select.find("option:not(:disabled)").map(function () { return String(this.value); }).get(), value,
      function (next) { $select.val(next).trigger("change"); });
    $picker.find(".sg-payment-card").each(function () {
      var $card = $(this), $radio = $card.find("input[type=radio]");
      var checked = String($radio.val()) === value;
      var blocked = methodBlocked($radio.val()), cardDisabled = !!disabled || blocked;
      if ($radio.prop("checked") !== checked) $radio.prop("checked", checked);
      if ($radio.prop("disabled") !== cardDisabled) $radio.prop("disabled", cardDisabled);
      $card.toggleClass("is-selected", checked && !blocked).toggleClass("is-disabled", cardDisabled).attr("aria-disabled", String(cardDisabled));
      balanceCard($card, blocked);
    });
    $picker.addClass("sg-payment-ready");
    var $status = $picker.children("[data-sg-wallet-status]");
    if (pickerIsPresented($picker)) {
      requestBalance(value, !!forceBalance);
      requestBalance(value === "21" ? "20" : "21", !!forceBalance);
    }
    renderBalance($status, value || "20");
  }
  function decorateSend(scope) {
    $(scope).find("[data-send-pay-methods] .sg-send-payment-option").each(function () {
      var $card = $(this), $radio = $card.find("input[type=radio]");
      if (!icons[String($radio.val())]) return;
      if (!$card.hasClass("sg-payment-card")) {
        $card.addClass("sg-payment-card");
        $("<span class='sg-payment-card__icon' aria-hidden='true'>").html(icons[String($radio.val())]).insertAfter($radio);
        $card.find(".sg-send-payment-option__content").addClass("sg-payment-card__content");
        $card.append($("<span class='sg-payment-card__check' aria-hidden='true'>").text("✓"));
      }
      var blocked = methodBlocked($radio.val());
      if (!blocked && $card.attr("data-sg-balance-blocked") === "true" && $card.closest(".sg-send").attr("data-sg-financial-busy") !== "true") $radio.prop("disabled", false);
      if (blocked && !$radio.prop("disabled")) $radio.prop("disabled", true);
      $card.toggleClass("is-selected", $radio.prop("checked") && !blocked).toggleClass("is-disabled", $radio.prop("disabled"))
        .attr("aria-disabled", String(!!$radio.prop("disabled")));
      balanceCard($card, blocked);
    });
    $(scope).find("[data-send-pay-method]").each(function () {
      if (!$(this).children("[data-sg-wallet-status]").length) $(this).append(walletStatus());
    });
    // During final confirmation the method fieldset is hidden; show information
    // here too, before the user's explicit payment submission.
    $(scope).find("[data-send-confirm]").each(function () {
      if (!$(this).children("[data-sg-wallet-status]").length) {
        var $status = walletStatus(), $actions = $(this).children(".sg-send__payment-actions");
        if ($actions.length) $status.insertBefore($actions); else $(this).append($status);
      }
    });
    $(scope).find(".sg-send").addBack(".sg-send").each(function () {
      var $send = $(this), $radios = $send.find("[data-send-pay-methods] input[type=radio]");
      // No balance request or method consent before an authoritative quote has
      // created the payment controls. Selecting a method remains index.js's job.
      if (!$radios.length) return;
      var method = String($radios.filter(":checked").val() || "20");
      if ($send.attr("data-sg-financial-busy") !== "true" && !$radios.filter(":disabled").length
        && (!$send.attr("data-send-current-stage") || $send.attr("data-send-current-stage") === "2")) {
        method = chooseHighest($radios.first(), ["20", "21"], method, function (next) {
          $radios.prop("checked", false).filter("[value='" + next + "']").prop("checked", true).trigger("change");
        });
      }
      requestBalance("20", false); requestBalance("21", false);
      if (methodBlocked(method) && $send.attr("data-sg-financial-busy") !== "true") {
        var $alternative = $radios.filter(function () { return !this.disabled && !methodBlocked(this.value); }).first();
        $radios.prop("checked", false);
        if ($alternative.length) $alternative.prop("checked", true).trigger("change");
        method = $alternative.val() || "20";
      }
      showBalance($send.find("[data-sg-wallet-status]"), method);
    });
  }
  function refresh(scope) {
    var $scope = $(scope || window.document);
    $scope.find("[data-sg-payment-picker]").each(function () { syncPicker(this); });
    if ($scope.is("[data-sg-payment-picker]")) syncPicker($scope[0]);
    decorateSend($scope);
  }
  function refreshBalance(scope) {
    var $scope = $(scope || window.document);
    // Called explicitly when an order's payment step opens or the server has
    // rejected a payment definitively. Reuse an in-flight balance read, and
    // never turn a display refresh into another financial submission.
    $scope.find("[data-sg-payment-picker]").addBack("[data-sg-payment-picker]").each(function () {
      syncPicker(this, true);
    });
    $scope.find(".sg-send").addBack(".sg-send").each(function () {
      if (!$(this).find("[data-send-pay-methods] input[type=radio]").length) return;
      requestBalance("20", true); requestBalance("21", true);
    });
  }
  function whenReady(scope) {
    var pending = [];
    $(scope).find("[data-sg-payment-picker]").addBack("[data-sg-payment-picker]").each(function () {
      var $picker = $(this);
      if (!pickerIsPresented($picker)) return;
      var value = $picker.find(".sg-payment-native-select").val();
      pending.push(requestBalance(value, false));
      pending.push(requestBalance(String(value) === "21" ? "20" : "21", false));
    });
    $(scope).find(".sg-send").addBack(".sg-send").each(function () {
      if (!$(this).find("[data-send-pay-methods] input[type=radio]").length) return;
      pending.push(requestBalance("20", false), requestBalance("21", false));
    });
    return $.when.apply($, pending);
  }
  function init() {
    refresh(window.document);
    $(window.document).on("change.sgPayment", "[data-sg-payment-picker] .sg-payment-card input[type=radio]", function () {
      var $picker = $(this).closest("[data-sg-payment-picker]"), $select = $picker.find(".sg-payment-native-select");
      if ($select.prop("disabled") || this.disabled || methodBlocked(this.value)) { syncPicker($picker[0]); return; }
      if (!automaticChoice) $picker.data("sgPaymentManual", true);
      // Keep existing handlers and exact API method IDs20/21.
      $select.val(this.value).trigger("change");
      syncPicker($picker[0]);
    }).on("change.sgPayment", ".sg-payment-native-select", function () { syncPicker($(this).closest("[data-sg-payment-picker]")[0]); })
      .on("change.sgPayment", "[data-send-pay-methods] input[type=radio]", function () {
        if (!automaticChoice) $(this).closest("[data-send-pay-methods]").find("input[type=radio]").first().data("sgPaymentManual", true);
        refresh($(this).closest(".sg-send")[0]);
      })
      .on("click.sgPayment", "[data-sg-balance-refresh]", function () {
        if (this.disabled) return;
        requestBalance("20", true); requestBalance("21", true);
        refresh(window.document);
      })
      .on("click.sgPayment", "[data-sg-balance-retry]", function () {
        var $status = $(this).closest("[data-sg-wallet-status]");
        requestBalance($status.attr("data-sg-balance-method"), true);
        refresh(window.document);
      });
    if (window.MutationObserver) {
      $(".sg-send, .salam-gate-order-box, #salam-gate-parcel-modal, #sgp-checkout-modal").each(function () {
        var root = this;
        var observer = new window.MutationObserver(function (mutations) {
          if (mutations.some(function (mutation) { return mutation.type === "attributes" || mutation.addedNodes.length || mutation.removedNodes.length; })) refresh(root);
        });
        // Don't observe classes/styles changed by refresh itself.
        observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ["disabled", "checked", "selected"] });
      });
    }
  }
  window.SalamGatePaymentPresentation = { resetChoice: resetChoice, refresh: refresh, refreshBalance: refreshBalance, whenReady: whenReady,
    isMethodAvailable: function (method) { return !methodBlocked(method); } };
  $(init);
})(window, window.jQuery);
