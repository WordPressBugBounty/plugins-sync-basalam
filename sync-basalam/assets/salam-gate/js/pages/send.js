// =====================================================================
// Salam Gate — standalone send page (part 6)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("send")) return;
  var $view = $("[data-view='send']");
  if (!$view.length) return;

  var data = { shops: [], categories: [], boxTypes: [], provinces: [], markets: [] };
  var selectedMarket = "";
  var announcedBasalamSource = null;
  var announcedOrderSource = null;
  var receiverData = null;
  var submitting = false;
  var bootstrapReady = false;
  var bootstrapRequest = null;
  var bootstrapEdits = { form: {}, receiver: {}, items: null, market: null };
  var bootstrapDraftRestored = false;
  var creationUnresolved = false;
  var draftFrozen = false;
  var draftStore = window.WoosalamSendDraft;
  var initialDraft = draftStore ? draftStore.load() : null;
  var creationAttempt = draftStore && draftStore.loadCreation ? draftStore.loadCreation() : null;
  var websiteEntryMode = (initialDraft && initialDraft.website_entry_mode === "manual")
    || (initialDraft && initialDraft.market === "website" && initialDraft.parcel)
    || (creationAttempt && creationAttempt.market === "website") ? "manual" : "orders";
  var creationStatusBusy = false;
  var sendStage = 1;
  var parcelMarket = "";
  var informationDirty = false;
  var informationFingerprint = "";
  var requireQuoteSelection = false;
  var adminUrlBase = basalam_ajax.ajaxurl.replace("admin-ajax.php", "");

  var $form = $view.find("[data-sg-send-form]");
  var $notice = $view.find("[data-send-notice]");
  var $quotesDiv = $view.find("[data-send-quotes]");
  var $submitBtn = $view.find("[data-send-submit]");

  function sgPost(action, postData) {
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, postData || {}));
  }

  // Resolve the durable submission identity silently. Page loading is not a
  // user action to announce, and an unknown result must never start a new create.
  function checkCreationStatus() {
    if (creationStatusBusy || !creationAttempt || creationAttempt.invalid) return;
    creationStatusBusy = true;
    var recoveryLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "recovery", checkCreationStatus);
    sgPost("salam_gate_send_creation_status", { creation_id: creationAttempt.id })
      .done(function (res) {
        var result = (res && res.data) || {};
        if (res && res.success && result.creation_id === creationAttempt.id
          && result.creation_state === "created" && result.market === creationAttempt.market
          && Number.isSafeInteger(result.parcel_id) && result.parcel_id > 0) {
          if (!draftStore.markCreation(creationAttempt.id, "created", result.parcel_id)) return;
          creationAttempt = draftStore.loadCreation();
          if (!creationAttempt || creationAttempt.invalid || creationAttempt.state !== "created") return;
          if (initialDraft && initialDraft.creation_unresolved && !initialDraft.parcel) {
            // Section navigation still has input. Recover only this exact prior submission.
            initialDraft.parcel = { id: result.parcel_id, market: result.market };
            initialDraft.creation_unresolved = false;
            if (bootstrapReady) restoreInitialDraft();
          } else {
            // Reload has cleared the previous recipient/input. Only a server-
            // confirmed identity may be retired; creating from this fresh form
            // still requires complete information and an explicit submission.
            if (!draftStore.clearCreation(creationAttempt.id)) return;
            creationAttempt = null;
            creationUnresolved = false;
            if (!initialDraft) {
              selectedMarket = "";
              renderMarkets();
            }
            unlockParcelInputs();
            goToSendStage(1, false);
            saveDraft();
          }
        } else {
          if (draftStore && creationAttempt) draftStore.markCreation(creationAttempt.id, "unknown");
        }
      })
      .fail(function () {
        if (draftStore && creationAttempt) draftStore.markCreation(creationAttempt.id, "unknown");
      })
      .always(function () {
        // Keep an unresolved result locked without an endless loading overlay.
        if (recoveryLoading) recoveryLoading.ready();
        creationStatusBusy = false;
        updateSendStage();
      });
  }

  function toEn(v) {
    return String(v == null ? "" : v)
      .replace(/[۰-۹]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48); })
      .replace(/[٠-٩]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48); })
      .replace(/[,٬]/g, "");
  }

  function faNum(n) { return String(n).replace(/\d/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[+d]; }); }
  function faThousands(n) { return window.SalamGateAmount.toman(Number(n), false); }

  // Completion feedback is announced once at the operation boundary. Loading
  // and instructions remain inline; recovery controls stay available below.
  function operationFeedback($target, message, kind) {
    var toast = typeof window.woosalamNotify === "function";
    var actions = $target.find("a, button").length > 0;
    $target.removeClass("sg-feedback-actions sg-feedback-toast-only");
    if (kind && toast) {
      $target.addClass(actions ? "sg-feedback-actions" : "sg-feedback-toast-only").attr("role", "group");
      window.woosalamNotify(message, kind);
    } else $target.attr("role", kind === "error" ? "alert" : "status");
  }

  function confirmationFeedback(message, kind, announce) {
    var $target = $view.find("[data-send-confirm-notice]").empty().show();
    $target.append($("<p>").text(message));
    if (kind) $target.append($("<a class='sg-button sg-button--secondary'>")
      .attr("href", adminUrlBase + "admin.php?page=salam_gate_parcels").text("بررسی در فهرست مرسوله‌ها"));
    if (announce === false && typeof window.woosalamNotify === "function") {
      $target.removeClass("sg-feedback-actions sg-feedback-toast-only")
        .addClass(kind ? "sg-feedback-actions" : "sg-feedback-toast-only").attr("role", "group");
    } else operationFeedback($target, message, kind);
  }

  function notice(text, ok) {
    var kind = ok === "warning" ? "warning" : (ok ? "success" : "error");
    if (typeof window.woosalamNotify === "function") {
      $notice.hide().empty();
      window.woosalamNotify(text, kind);
      return;
    }
    $notice.text(text || "").toggleClass("sg-wizard__notice--ok", kind === "success")
      .toggleClass("sg-wizard__notice--warning", kind === "warning").attr("role", kind === "error" ? "alert" : "status").show();
  }

  function sendMarketLabel(market) {
    return { basalam: "باسلام", website: "ووکامرس", instagram: "اینستاگرام", telegram: "تلگرام", eitaa: "ایتا",
      bale: "بله", digikala: "دیجی‌کالا", mixin: "میکسین", other: "سایر" }[market] || "سایر بسترها";
  }

  function senderPresentation(shop) {
    var address = shop.address || {};
    var identity = String(shop.identifier || shop.id || "");
    var name = typeof address.full_name === "string" && address.full_name.trim()
      ? address.full_name.trim() : "فرستنده ثبت‌شده";
    var city = address.city && typeof address.city.title === "string" ? address.city.title : "";
    var center = shop.provider_node && typeof shop.provider_node.title === "string" ? shop.provider_node.title : "";
    var parts = [name];
    if (city) parts.push(city);
    if (center) parts.push("مرکز تحویل: " + center);
    return { name: name, identity: identity, city: city, center: center,
      address: typeof address.address === "string" ? address.address : "", label: parts.join(" — ") };
  }

  function navigationLocked() {
    return submitting || creationUnresolved || quoteBusy || paying || paymentDone || paymentUnresolved;
  }

  function canReview() {
    return !orderPickerMarket() && lastParcelId && !informationDirty && !requireQuoteSelection && validQuote(selectedQuote)
      && (selectedPayMethod === 20 || selectedPayMethod === 21)
      && (!window.SalamGatePaymentPresentation || window.SalamGatePaymentPresentation.isMethodAvailable(selectedPayMethod))
      && !quoteBusy && !submitting
      && !creationUnresolved && !paymentDone && !paymentUnresolved;
  }

  function hasWebsiteSubmission() {
    return !!((lastParcelId && parcelMarket === "website")
      || (creationAttempt && creationAttempt.market === "website")
      || (initialDraft && initialDraft.market === "website" && initialDraft.parcel));
  }

  function orderPickerMarket() {
    if (selectedMarket === "basalam") return "basalam";
    // Manual website parcels use the existing durable direct-send identity;
    // choosing a Woo order continues through the separate, locked-items flow.
    return selectedMarket === "website" && websiteEntryMode !== "manual" && !hasWebsiteSubmission() ? "website" : "";
  }

  function updateSendStage() {
    var pickerMarket = orderPickerMarket();
    var orderSource = !!pickerMarket;
    var basalamSource = pickerMarket === "basalam";
    var $websiteModes = $view.find("[data-send-website-modes]");
    $websiteModes.prop("hidden", selectedMarket !== "website").toggle(selectedMarket === "website");
    var manualWebsite = websiteEntryMode === "manual" || hasWebsiteSubmission();
    $websiteModes.find("[data-send-website-mode]").each(function () {
      var selected = $(this).attr("data-send-website-mode") === (manualWebsite ? "manual" : "orders");
      $(this).attr("aria-pressed", String(selected)).toggleClass("is-selected", selected)
        .prop("disabled", navigationLocked() || sendStage !== 1 || hasWebsiteSubmission());
    });
    if (orderSource) sendStage = 1;
    $view.attr("data-send-order-source", pickerMarket).attr("data-send-current-stage", sendStage);
    $view.find("[data-send-direct-fields]").prop("hidden", orderSource).toggle(!orderSource);
    $view.find(".sg-send__steps").prop("hidden", orderSource).toggle(!orderSource);
    $view.find("[data-send-main-action]").toggle(!orderSource && sendStage === 1);
    if (!orderSource) $view.find("[data-sg-menu-order-host]").prop("hidden", true).hide();
    if (announcedBasalamSource !== basalamSource) {
      announcedBasalamSource = basalamSource;
      $(document).trigger("salamgate:basalam-source", [basalamSource]);
    }
    if (announcedOrderSource !== pickerMarket) {
      announcedOrderSource = pickerMarket;
      $view.find("[data-sg-menu-order-host]").prop("hidden", true).hide();
      $(document).trigger("salamgate:order-source", [pickerMarket]);
    }
    $submitBtn.prop("disabled", orderSource || !bootstrapReady || navigationLocked());
    $view.find("[data-send-stage-panel]").each(function () {
      var shown = Number($(this).attr("data-send-stage-panel")) === sendStage;
      $(this).prop("hidden", !shown).toggle(shown);
    });
    $view.find("[data-send-stage]").each(function () {
      var number = Number($(this).attr("data-send-stage"));
      var active = number === sendStage;
      var accessible = number === 1 || (number === 2 && lastParcelId && !informationDirty) || (number === 3 && canReview());
      $(this).toggleClass("is-active", active).toggleClass("is-done", number < sendStage || paymentDone);
      $(this).find("[data-send-go-stage]").prop("disabled", navigationLocked() || !accessible)
        .attr("aria-disabled", navigationLocked() || !accessible ? "true" : "false");
      if (active) $(this).add($(this).find("[data-send-go-stage]")).attr("aria-current", "step");
      else $(this).add($(this).find("[data-send-go-stage]")).removeAttr("aria-current");
    });
    $view.find("[data-send-review-next]").prop("disabled", !canReview() || navigationLocked());
    $view.find("[data-send-back-to-information]").prop("disabled", navigationLocked());
    $view.find(".sg-send__payment-actions, .sg-send__payment-help, [data-send-review]").toggle(!paymentDone && !paymentUnresolved);
    $view.find("[data-send-finance-recovery]").toggle(sendStage === 3 && (paymentDone || paymentUnresolved) && !$view.find("[data-send-result]").children().length);
    $view.find("[data-send-recheck-payment]").toggle(paymentUnresolved && !paymentDone)
      .prop("disabled", submitting || paying || quoteBusy || creationUnresolved);
    $view.find("[data-send-edit-notice]").toggle(sendStage === 1 && informationDirty);
    syncMarketChoices(false);
  }

  function goToSendStage(stage, focus) {
    sendStage = stage;
    if (stage === 1) {
      if (!navigationLocked()) unlockParcelInputs();
      $view.find("[data-send-main-action]").show();
      $submitBtn.prop("disabled", !bootstrapReady || navigationLocked()).text("مرحله بعد");
    }
    renderConfirm();
    updateSendStage();
    if (focus) $view.find("[data-send-stage-panel='" + stage + "']").find("h2, h3, h4").first().attr("tabindex", "-1").trigger("focus");
  }

  $view.on("click", "[data-send-go-stage]", function () {
    if (navigationLocked()) return;
    var stage = Number($(this).attr("data-send-go-stage"));
    if (stage === 1 || (stage === 2 && lastParcelId && !informationDirty) || (stage === 3 && canReview())) goToSendStage(stage, true);
  });
  $view.on("click", "[data-send-review-next]", function () {
    if (!navigationLocked() && canReview()) goToSendStage(3, true);
  });
  $view.on("click", "[data-send-back-to-information]", function () {
    if (!navigationLocked() && sendStage === 2) goToSendStage(1, true);
  });

  function fingerprintInformation() {
    var fields = [];
    $form.find("input, select, textarea").each(function () {
      if (!this.name) return;
      fields.push([this.name, this.type === "checkbox" || this.type === "radio" ? this.checked : $(this).val()]);
    });
    var items = $view.find("[data-send-items] tr").map(function () {
      var $row = $(this);
      return [[
        $row.find(".sg-send-item-title").val() || "",
        toEn($row.find(".sg-send-item-qty").val()),
        toEn($row.find(".sg-send-item-weight").val()),
        toEn($row.find(".sg-send-item-price").val())
      ]];
    }).get();
    return JSON.stringify([selectedMarket, fields, receiverData, items]);
  }

  function informationChanged() {
    if (orderPickerMarket() || !bootstrapReady || navigationLocked() || sendStage !== 1) return;
    updateItemPlaceholderRequirements();
    var fingerprint = fingerprintInformation();
    if (fingerprint === informationFingerprint) return;
    informationFingerprint = fingerprint;
    if (!lastParcelId) return;
    var firstChange = !informationDirty;
    informationDirty = true;
    clearPaymentFeedback();
    requireQuoteSelection = true;
    quoteSequence += 1;
    selectedQuote = null;
    selectedPayMethod = 0;
    $quotesDiv.hide().empty();
    $view.find("[data-send-confirm], [data-send-pay-method], [data-send-quote-notice], [data-send-pay-status]").hide();
    $view.find("[data-send-pay-methods], [data-send-review]").empty();
    if (firstChange) notice("اطلاعات تغییر کرد؛ با ذخیره تغییرات، روش ارسال و پرداخت را دوباره انتخاب کنید.", "warning");
    goToSendStage(1, false);
    saveDraft();
  }

  // ---------- مبدأ سفارش ----------
  function syncMarketChoices() {
    var $wrap = $view.find("[data-send-markets]");
    var locked = navigationLocked() || sendStage !== 1;
    $wrap.find("input[name='market']").each(function () {
      var selected = this.value === selectedMarket;
      $(this).prop("checked", selected).prop("disabled", locked)
        .closest("label").toggleClass("is-selected", selected).toggleClass("is-disabled", locked);
    });
  }

  function renderMarkets() {
    var $wrap = $view.find("[data-send-markets]").empty();
    // A saved draft or unresolved submission retains its actual API identity.
    if (!selectedMarket) {
      var previousMarket = initialDraft && initialDraft.market;
      if (!previousMarket && creationAttempt && !creationAttempt.invalid) previousMarket = creationAttempt.market;
      if (data.markets.indexOf(previousMarket) !== -1) selectedMarket = previousMarket;
      else if (data.markets.indexOf("website") !== -1) selectedMarket = "website";
    }
    var markets = ["website", "basalam"].filter(function (market) { return data.markets.indexOf(market) !== -1; })
      .concat(data.markets.filter(function (market) { return market !== "website" && market !== "basalam"; }));
    $.each(markets, function (i, market) {
      $("<label class='sg-radio sg-radio--market'>").append(
        $("<input>").attr({ type: "radio", name: "market", value: market, "aria-describedby": "sg-send-market-error" }),
        $("<span class='sg-send__market-name'>").text(sendMarketLabel(market))
      ).appendTo($wrap);
    });
    syncMarketChoices();
  }

  $form.on("change", "input[name='market']", function () {
    var requestedMarket = $(this).val();
    if (navigationLocked()) {
      $form.find("input[name='market']").prop("checked", false).filter(function () { return $(this).val() === selectedMarket; }).prop("checked", true);
      return;
    }
    // Preserve the independent direct-order draft, never overwrite it with a
    // Basalam customer or replay a direct create using the Basalam identity.
    if ((requestedMarket === "basalam" || requestedMarket === "website") && !orderPickerMarket()) saveDraft();
    selectedMarket = $(this).val();
    $view.find("[data-error-for='market']").text("").hide();
    $form.find("input[name='market']").removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
    updateSendStage();
  });

  $view.on("click", "[data-send-website-mode]", function () {
    var mode = $(this).attr("data-send-website-mode");
    if (selectedMarket !== "website" || ["manual", "orders"].indexOf(mode) === -1
      || navigationLocked() || sendStage !== 1 || hasWebsiteSubmission()) return;
    websiteEntryMode = mode;
    updateSendStage();
    saveDraft();
  });

  // Recipient fields stay inline; the legacy draft buffer key is preserved.
  var $receiverFields = $view.find("[data-send-receiver-fields]");

  function fillReceiverProvinces() {
    var $p = $receiverFields.find("#src-province").empty().append($("<option>").val("").text("انتخاب استان"));
    $.each(data.provinces, function (i, prov) { $p.append($("<option>").val(prov.id).text(prov.title)); });
  }

  function fillReceiverCities(provinceId, cityId) {
    var $c = $receiverFields.find("#src-city").empty().append($("<option>").val("").text("انتخاب شهر"));
    $.each(data.provinces, function (i, prov) {
      if (String(prov.id) === String(provinceId)) {
        $.each(prov.children || [], function (j, city) { $c.append($("<option>").val(city.id).text(city.title)); });
      }
    });
    if (cityId) $c.val(cityId);
    $c.prop("disabled", !provinceId || navigationLocked());
  }

  function readReceiverFields() {
    var r = captureReceiverModal();
    r.name = $.trim(r.name);
    r.mobile = toEn(r.mobile);
    r.address = $.trim(r.address);
    r.postal_code = toEn(r.postal_code).replace(/\D/g, "");
    r.province_title = "";
    r.city_title = "";
    $.each(data.provinces, function (i, prov) {
      if (String(prov.id) === String(r.province_id)) {
        r.province_title = prov.title;
        $.each(prov.children || [], function (j, city) {
          if (String(city.id) === String(r.city_id)) r.city_title = city.title;
        });
      }
    });
    return r;
  }

  function validateReceiver() {
    receiverData = readReceiverFields();
    clearReceiverErrors();
    var r = receiverData;
    var problems = [];
    function receiverError(field, message) {
      problems.push(message);
      $receiverFields.find("[data-receiver-error-for='" + field + "']").text(message).show();
      $receiverFields.find("#src-" + field).attr("aria-invalid", "true").addClass("sg-field__input--invalid");
    }
    if (r.name.length < 3) receiverError("name", "نام و نام خانوادگی را با حداقل ۳ حرف وارد کنید.");
    if (!r.province_id) receiverError("province", "استان گیرنده را انتخاب کنید.");
    if (!r.city_id || !r.city_title) receiverError("city", "شهر گیرنده را انتخاب کنید.");
    if (r.address.length < 10) receiverError("address", "نشانی کامل را با حداقل ۱۰ حرف وارد کنید.");
    if (!/^\d{10}$/.test(r.postal_code)) receiverError("postal", "کد پستی باید ۱۰ رقم باشد.");
    if (!/^09\d{9}$/.test(r.mobile)) receiverError("mobile", "شماره موبایل ۱۱ رقمی با ۰۹ شروع می‌شود.");
    if (problems.length) {
      $view.find("[data-error-for='receiver']").text("اطلاعات گیرنده را کامل کنید.").show();
      $receiverFields.find("[aria-invalid='true']").first().trigger("focus");
      notice(problems[0], false);
      return false;
    }
    $view.find("[data-error-for='receiver']").text("").hide();
    return true;
  }

  $receiverFields.on("change", "#src-province", function () {
    if (navigationLocked() || sendStage !== 1) return;
    // Changing province invalidates the old city rather than silently retaining it.
    fillReceiverCities($(this).val(), "");
  });
  $receiverFields.on("input change", "input, select, textarea", function () {
    if (navigationLocked() || sendStage !== 1) return;
    receiverData = readReceiverFields();
    $receiverFields.find("[data-addr-count]").text(faNum($receiverFields.find("#src-address").val().length));
    var field = this.id.replace("src-", "");
    $(this).removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
    $receiverFields.find("[data-receiver-error-for='" + field + "']").text("").hide();
    informationChanged();
  });

  // ---------- فرستنده ----------
  function renderSenders() {
    var shop = data.shops.filter(function (item) { return item.address && item.address.is_default === true; })[0] || data.shops[0];
    $view.find("[data-send-sender]").val(shop ? String(shop.id) : "");
    renderSenderSummary();
  }

  function renderSenderSummary() {
    var selectedId = String($view.find("[data-send-sender]").val() || "");
    var shop = data.shops.filter(function (item) { return String(item.id) === selectedId; })[0];
    var $summary = $view.find("[data-send-sender-summary]").empty();
    if (!shop) { $summary.append($("<p class='sg-field__hint'>").text("مشخصات فرستنده فعال در دسترس نیست؛ اتصال حساب را در تنظیمات بررسی کنید.")); return; }
    var info = senderPresentation(shop);
    $summary.append($("<strong>").text(info.name));
    if (info.city) $summary.append($("<p class='sg-card__detail'>").text("شهر: " + info.city));
    if (info.center) $summary.append($("<p class='sg-card__detail'>").text("مرکز تحویل: " + info.center));
    if (info.address) $summary.append($("<p class='sg-card__detail'>").text(info.address));
  }
  $form.on("change", "[data-send-sender]", renderSenderSummary);

  // ---------- بسته‌بندی ----------
  function renderBoxTypes() {
    var $sel = $view.find("[data-send-box]").empty().append($("<option>").val("").text("انتخاب بسته‌بندی"));
    $.each(data.boxTypes, function (i, box) {
      $sel.append($("<option>").val(box.id).text(box.title));
    });
  }

  $form.on("change", "[data-send-box]", function () {
    var is37 = $(this).val() === "37";
    $form.find("[data-send-out-of-size]").prop("checked", is37);
    if (!is37 && $(this).val()) $(this).data("sg-normal-box", $(this).val());
    $view.find("[data-send-dims]").toggle(is37);
    if (!is37) {
      $form.find("[name='dimension_length'], [name='dimension_width'], [name='dimension_height']").val("");
    }
  });
  $form.on("change", "[data-send-out-of-size]", function () {
    var $box = $view.find("[data-send-box]");
    if ($(this).is(":checked")) {
      if ($box.val() !== "37") $box.data("sg-normal-box", $box.val());
      $box.val("37");
    } else $box.val($box.data("sg-normal-box") || "");
    $box.trigger("change");
  });

  // ---------- دسته‌بندی ----------
  function renderCategories() {
    var $sel = $view.find("[data-send-category]").empty().append($("<option>").val("").text("انتخاب نوع مرسوله"));
    $.each(data.categories, function (i, cat) {
      $sel.append($("<option>").val(cat.id).text(cat.title));
    });
  }

  // ---------- اقلام ----------
  var itemRowSequence = 0;
  function itemCell(label, key, type, minimum, value, placeholder) {
    var errorId = "sg-send-item-" + itemRowSequence + "-" + key + "-error";
    var $input = $("<input>").attr({ type: type, class: "sg-send-item-" + key,
      "aria-label": label, "aria-describedby": errorId, "data-sg-validate": "", "data-sg-label": label });
    if (minimum !== null) $input.attr("min", minimum);
    if (type === "number") $input.attr({ step: "1", "data-sg-integer": "1" });
    if (key === "qty" || key === "weight") $input.attr("max", "2147483647");
    if (key !== "price") $input.prop("required", true);
    if (key === "weight") $input.attr({ "data-sg-label": "وزن خالص", "data-sg-unit": "گرم" });
    if (key === "price") $input.attr({ "data-sg-label": "قیمت قلم", "data-sg-unit": "تومان" });
    if (placeholder) $input.attr("placeholder", placeholder);
    return $("<td>").attr("data-label", label).append($input.val(value == null ? "" : value),
      $("<p class='sg-field__error'>").attr({ id: errorId, "data-send-item-error": key }));
  }
  function addItemRow(title, qty, netWeight, priceToman) {
    var $tbody = $view.find("[data-send-items]");
    itemRowSequence += 1;
    var $row = $("<tr>").append(
      itemCell("عنوان", "title", "text", null, title || "", "عنوان قلم"),
      itemCell("تعداد", "qty", "number", "1", qty == null ? 1 : qty),
      itemCell("وزن خالص (گرم)", "weight", "number", "1", netWeight),
      itemCell("قیمت (تومان)", "price", "number", "1000", priceToman),
      $("<td data-label='عملیات'>").append($("<button>").attr({ type: "button", class: "button button-small", "aria-label": "حذف قلم" }).text("✕").on("click", function () { if (navigationLocked() || sendStage !== 1) return;
        var title = $.trim($row.find(".sg-send-item-title").val() || "");
        var opener = this;
        var $dialog = $view.find("[data-send-remove-modal]");
        $dialog.find("[data-send-remove-message]").text(title ? "قلم «" + title + "» از مرسوله حذف شود؟" : "این قلم از مرسوله حذف شود؟");
        $dialog.find("[data-send-remove-hint]").text($row[0] === $tbody.children("tr").first()[0]
          ? "اطلاعات این قلم پاک می‌شود و سطر برای ورود قلم جدید باقی می‌ماند."
          : "این قلم از اطلاعات مرسوله حذف می‌شود.");
        pendingItemRemoval = { row: $row, opener: opener };
        $dialog.show(); }))
    );
    $tbody.append($row);
    updateItemPlaceholderRequirements();
  }


  var pendingItemRemoval = null;
  function closeItemRemoval() {
    $view.find("[data-send-remove-modal]").hide();
    pendingItemRemoval = null;
  }
  $view.on("click", "[data-send-remove-modal] [data-close-modal]", closeItemRemoval);
  $view.on("click", "[data-send-remove-modal]", function (event) {
    if (event.target === this) closeItemRemoval();
  });
  $view.on("click", "[data-send-remove-confirm]", function () {
    var pending = pendingItemRemoval;
    closeItemRemoval();
    if (!pending || navigationLocked() || sendStage !== 1 || !pending.row[0].isConnected) return;
    var firstRow = $view.find("[data-send-items] tr").first()[0] === pending.row[0];
    var $next;
    if (firstRow) {
      // The first row is the permanent entry slot. Reset its values in place
      // so product-search/accessibility hooks survive and a new item can be
      // entered immediately without adding a replacement row.
      pending.row.find("input").val("").removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
      pending.row.find(".sg-send-item-qty").val(1);
      pending.row.find("[data-send-item-error]").empty().hide();
      $next = pending.row.find(".sg-send-item-title");
      updateItemPlaceholderRequirements();
      $next.trigger("input");
      if (window.SalamGateValidation) window.SalamGateValidation.reset(pending.row);
    } else {
      $next = pending.row.next().find(".sg-send-item-title");
      if (!$next.length) $next = pending.row.prev().find(".sg-send-item-title");
      pending.row.remove();
    }
    informationChanged();
    rememberBootstrapItems();
    saveDraft();
    ($next.length ? $next : $view.find("[data-send-add-item]")).trigger("focus");
  });

  function emptyFirstItemRow($row) {
    return $row[0] === $view.find("[data-send-items] tr").first()[0]
      && !$.trim($row.find(".sg-send-item-title").val() || "")
      && toEn($row.find(".sg-send-item-qty").val()) === "1"
      && !toEn($row.find(".sg-send-item-weight").val())
      && !toEn($row.find(".sg-send-item-price").val());
  }

  function updateItemPlaceholderRequirements() {
    var $first = $view.find("[data-send-items] tr").first();
    // An untouched/cleared entry slot does not prevent sending the populated
    // items below it. Partial input must still be completed or cleared.
    var placeholder = $first.length && emptyFirstItemRow($first) && collectItems().length > 0;
    $first.find(".sg-send-item-title, .sg-send-item-qty, .sg-send-item-weight").prop("required", !placeholder);
  }

  $view.find("[data-send-add-item]").on("click", function () {
    if (navigationLocked() || sendStage !== 1) return;
    addItemRow("", 1, "", "");
    informationChanged();
    rememberBootstrapItems();
    saveDraft();
  });

  function collectItems() {
    var items = [];
    $view.find("[data-send-items] tr").each(function () {
      var title = $.trim($(this).find(".sg-send-item-title").val() || "");
      var qty = parseInt(toEn($(this).find(".sg-send-item-qty").val() || "1"), 10);
      var netW = parseInt(toEn($(this).find(".sg-send-item-weight").val() || "0"), 10);
      var priceText = toEn($(this).find(".sg-send-item-price").val() || "");
      var price = priceText === "" ? "" : Number(priceText);
      if (title && qty >= 1 && netW >= 1) {
        items.push({ title: title, quantity: qty, net_weight: netW, price_toman: price });
      }
    });
    return items;
  }

  // Only this tab's short-lived draft is saved; no OAuth/payment credential is stored.
  function captureReceiverModal() {
    return {
      name: $receiverFields.find("#src-name").val() || "",
      mobile: $receiverFields.find("#src-mobile").val() || "",
      address: $receiverFields.find("#src-address").val() || "",
      postal_code: $receiverFields.find("#src-postal").val() || "",
      city_id: $receiverFields.find("#src-city").val() || "",
      province_id: $receiverFields.find("#src-province").val() || ""
    };
  }

  function captureDraftItems() {
    return $view.find("[data-send-items] tr").map(function () {
      return { title: $(this).find(".sg-send-item-title").val() || "",
        quantity: $(this).find(".sg-send-item-qty").val() || "",
        net_weight: $(this).find(".sg-send-item-weight").val() || "",
        price_toman: $(this).find(".sg-send-item-price").val() || "" };
    }).get();
  }

  function rememberBootstrapItems() {
    if (!bootstrapReady) bootstrapEdits.items = captureDraftItems();
  }

  function restoreInitialDraft() {
    if (!initialDraft) return;
    // A slow reference lookup must not drop the identity of a previously saved
    // parcel merely because the user typed a field while waiting. Merge only
    // touched input; untouched receiver/item data still comes from that draft.
    var restored = $.extend(true, {}, initialDraft);
    var changed = Object.keys(bootstrapEdits.form).length > 0 || Object.keys(bootstrapEdits.receiver).length > 0
      || bootstrapEdits.items !== null || bootstrapEdits.market !== null;
    restored.form = $.extend({}, restored.form || {}, bootstrapEdits.form);
    restored.receiverModal = $.extend({}, restored.receiverModal || restored.receiver || {}, bootstrapEdits.receiver);
    if (bootstrapEdits.items !== null) restored.items = bootstrapEdits.items;
    if (bootstrapEdits.market !== null) restored.market = bootstrapEdits.market;
    if (restored.parcel && changed) restored.information_dirty = true;
    initialDraft = restored;
    bootstrapEdits = { form: {}, receiver: {}, items: null, market: null };
    restoreDraft(restored);
  }

  function saveDraft() {
    if ((orderPickerMarket() && selectedMarket !== "website") || !draftStore || !bootstrapReady || draftFrozen) return;
    var fields = {};
    $.each(["provider_shop_id", "weight", "value_toman", "box_type_id", "category_id", "dimension_length", "dimension_width", "dimension_height"], function (_, name) {
      fields[name] = $form.find('[name="' + name + '"]').val() || "";
    });
    fields.is_liquid = $form.find("[name='is_liquid']").is(":checked");
    fields.is_fragile = $form.find("[name='is_fragile']").is(":checked");
    draftStore.save({ market: selectedMarket, website_entry_mode: websiteEntryMode, form: fields, receiver: receiverData,
      receiverModal: captureReceiverModal(), items: captureDraftItems(),
      parcel: lastParcelId ? { id: lastParcelId, market: parcelMarket } : null,
      information_dirty: informationDirty,
      creation_unresolved: creationUnresolved });
  }

  function restoreDraft(snapshot) {
    if (!snapshot) return;
    if (data.markets.indexOf(snapshot.market) !== -1) {
      selectedMarket = snapshot.market;
      $form.find("input[name='market']").filter(function () { return $(this).val() === selectedMarket; }).prop("checked", true);
    }
    syncMarketChoices(true);
    $.each(snapshot.form || {}, function (name, value) {
      var $field = $form.find('[name="' + name + '"]');
      if ($field.is(":checkbox")) $field.prop("checked", value === true);
      else $field.val(value);
    });
    $view.find("[data-send-box]").trigger("change");
    var savedSender = (snapshot.form || {}).provider_shop_id;
    var senderExists = data.shops.some(function (shop) { return String(shop.id) === String(savedSender); });
    var missingParcelSender = !!snapshot.parcel && !senderExists;
    // Rates for an existing parcel must never be presented as rates for a
    // replacement sender. Preserve its identity and block until it is available.
    if (missingParcelSender) $view.find("[data-send-sender]").val(savedSender || "");
    else if (!senderExists) renderSenders();
    renderSenderSummary();
    receiverData = snapshot.receiver || null;
    var modal = snapshot.receiverModal || receiverData || {};
    $receiverFields.find("#src-province").val(modal.province_id || "");
    fillReceiverCities(modal.province_id, modal.city_id);
    $.each({ name: "#src-name", mobile: "#src-mobile", address: "#src-address", postal_code: "#src-postal" }, function (name, selector) {
      $receiverFields.find(selector).val(modal[name] || "");
    });
    receiverData = readReceiverFields();
    $receiverFields.find("[data-addr-count]").text(faNum((modal.address || "").length));
    $view.find("[data-send-items]").empty();
    $.each(snapshot.items || [], function (_, item) { addItemRow(item.title, item.quantity, item.net_weight, item.price_toman); });
    if (!$view.find("[data-send-items] tr").length) addItemRow("", 1, "", "");
    updateItemPlaceholderRequirements();
    creationUnresolved = snapshot.creation_unresolved === true;
    informationDirty = snapshot.information_dirty === true || missingParcelSender;
    requireQuoteSelection = informationDirty;
    if (snapshot.parcel) {
      lastParcelId = Number(snapshot.parcel.id) || 0;
      parcelMarket = snapshot.parcel.market;
    }
    if (creationUnresolved) lockParcelInputs();
    else if (lastParcelId) unlockParcelInputs();
    if (lastParcelId && creationUnresolved) refreshQuotes(true);
    else if (lastParcelId && !informationDirty) refreshQuotes();
    else if (lastParcelId && informationDirty && !creationUnresolved) {
      notice(missingParcelSender ? "فرستنده این مرسوله در دسترس نیست؛ وضعیت فرستنده را در تنظیمات بررسی کنید."
        : "تغییرات ذخیره‌نشده بازیابی شد؛ برای انتخاب روش ارسال تازه، تغییرات را ذخیره کنید.", missingParcelSender ? false : "warning");
      goToSendStage(1, false);
    }
  }

  function lockParcelInputs() {
    $form.find("input, select, textarea, button").prop("disabled", true);
    $receiverFields.find("input, select, textarea").prop("disabled", true);
  }

  function unlockParcelInputs() {
    $form.find("input, select, textarea, button").prop("disabled", false);
    $receiverFields.find("input, select, textarea").prop("disabled", false);
    // Item changes are saved as a guarded replacement of the unpaid draft.
    $view.find("[data-send-items] input, [data-send-items] button, [data-send-add-item]").prop("disabled", false);
    $view.find("[data-send-edit-note]").toggle(!!lastParcelId);
    $receiverFields.find("#src-city").prop("disabled", !$receiverFields.find("#src-province").val());
  }

  $form.on("input change", "input, select, textarea", informationChanged);
  $form.on("input change", "input, select, textarea", function () {
    if (bootstrapReady) return;
    var $field = $(this);
    if ($field.closest("[data-send-items]").length) { rememberBootstrapItems(); return; }
    var receiverField = { "src-name": "name", "src-mobile": "mobile", "src-address": "address",
      "src-postal": "postal_code", "src-province": "province_id", "src-city": "city_id" }[this.id];
    if (receiverField) {
      bootstrapEdits.receiver[receiverField] = $field.val() || "";
      if (receiverField === "province_id") bootstrapEdits.receiver.city_id = $receiverFields.find("#src-city").val() || "";
    } else if (this.name === "market") bootstrapEdits.market = selectedMarket;
    else if (["provider_shop_id", "weight", "value_toman", "box_type_id", "category_id", "dimension_length",
      "dimension_width", "dimension_height", "is_liquid", "is_fragile"].indexOf(this.name) !== -1) {
      bootstrapEdits.form[this.name] = this.type === "checkbox" ? this.checked : ($field.val() || "");
    }
  });
  $form.on("input change", "input, select, textarea", function () { saveDraft(); });
  window.addEventListener("pagehide", saveDraft);

  // ---------- Bootstrap ----------
  var $bootstrapStatus = $("<div class='sg-state__message' data-send-bootstrap-status role='status'>");
  var sendBootstrapLoading = null;
  var $bootstrapMessage = $("<p>").appendTo($bootstrapStatus);
  var $bootstrapRetry = $("<button class='sg-btn sg-btn--secondary' type='button' data-send-bootstrap-retry>")
    .text("تلاش دوباره").appendTo($bootstrapStatus).hide();
  $form.prepend($bootstrapStatus);

  function bootstrapErrorMessage(response) {
    var messages = [];
    $.each(["shops", "categories", "box_types", "provinces"], function (_, resource) {
      var message = response && response.errors && response.errors[resource];
      if (typeof message === "string" && /[\u0600-\u06ff]/.test(message) && messages.indexOf(message) === -1) messages.push(message);
    });
    if (!messages.length && response && typeof response.message === "string" && /[\u0600-\u06ff]/.test(response.message)) messages.push(response.message);
    return (messages.length ? messages.join(" ") : "اطلاعات لازم برای ثبت مرسوله دریافت نشد.")
      + " اطلاعات واردشده حفظ می‌شود؛ پس از برطرف‌شدن مشکل، تلاش دوباره را بزنید.";
  }

  function bootstrapFailed(message) {
    bootstrapReady = false;
    $bootstrapStatus.attr("role", "alert").show();
    $bootstrapMessage.text(message || "اطلاعات لازم برای ثبت مرسوله دریافت نشد؛ دوباره تلاش کنید. اطلاعات واردشده حفظ می‌شود.");
    $bootstrapRetry.show();
    operationFeedback($bootstrapStatus, $bootstrapMessage.text(), "error");
    if (sendBootstrapLoading) sendBootstrapLoading.ready();
    updateSendStage();
  }

  function loadSendBootstrap() {
    // This read-only retry is also safe while a previous creation is unresolved.
    if (bootstrapRequest || submitting || quoteBusy || paying || paymentDone || paymentUnresolved) return;
    sendBootstrapLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "bootstrap", loadSendBootstrap);
    bootstrapReady = false;
    $bootstrapStatus.removeClass("sg-feedback-actions sg-feedback-toast-only").attr("role", "status").show();
    $bootstrapMessage.text("در حال دریافت اطلاعات…");
    $bootstrapRetry.prop("disabled", true).hide();
    updateSendStage();
    bootstrapRequest = sgPost("salam_gate_send_bootstrap");
    bootstrapRequest.done(function (res) {
      if (!res || !res.success || !res.data) {
        bootstrapFailed(bootstrapErrorMessage(res && res.data));
        return;
      }
      // Refresh only references. Recipient text, parcel items and entered values
      // remain in the form even when the first attempt was only partly successful.
      var senderId = $view.find("[data-send-sender]").val();
      var boxId = $view.find("[data-send-box]").val();
      var categoryId = $view.find("[data-send-category]").val();
      var provinceId = $receiverFields.find("#src-province").val();
      var cityId = $receiverFields.find("#src-city").val();
      data.shops = Array.isArray(res.data.shops) ? res.data.shops : [];
      data.categories = Array.isArray(res.data.categories) ? res.data.categories : [];
      data.boxTypes = Array.isArray(res.data.box_types) ? res.data.box_types : [];
      data.provinces = Array.isArray(res.data.provinces) ? res.data.provinces : [];
      data.markets = Array.isArray(res.data.markets) ? res.data.markets : [];
      renderMarkets();
      if (data.markets.indexOf(selectedMarket) !== -1) {
        $form.find("input[name='market']").filter(function () { return $(this).val() === selectedMarket; }).prop("checked", true);
      }
      renderSenders();
      if (data.shops.some(function (shop) { return String(shop.id) === String(senderId); })) {
        $view.find("[data-send-sender]").val(senderId);
        renderSenderSummary();
      }
      renderBoxTypes();
      if (boxId) $view.find("[data-send-box]").val(boxId);
      renderCategories();
      if (categoryId) $view.find("[data-send-category]").val(categoryId);
      fillReceiverProvinces();
      if (provinceId) $receiverFields.find("#src-province").val(provinceId);
      fillReceiverCities($receiverFields.find("#src-province").val(), cityId);
      if (!$view.find("[data-send-items] tr").length) addItemRow("", 1, "", "");

      var hasReceiverCity = data.provinces.some(function (province) {
        return province && Array.isArray(province.children) && province.children.some(function (city) {
          return city && (typeof city.id === "number" || (typeof city.id === "string" && /^[1-9][0-9]*$/.test(city.id)))
            && Number.isSafeInteger(Number(city.id)) && Number(city.id) > 0
            && typeof city.title === "string" && city.title.trim() !== "";
        });
      });
      bootstrapReady = res.data.ready !== false && data.shops.length > 0 && data.categories.length > 0
        && data.boxTypes.length > 0 && hasReceiverCity && data.markets.length > 0;
      if (!bootstrapReady) {
        bootstrapFailed(bootstrapErrorMessage(res.data));
        return;
      }
      if (!bootstrapDraftRestored) {
        bootstrapDraftRestored = true;
        restoreInitialDraft();
      }
      $bootstrapStatus.hide();
      informationFingerprint = fingerprintInformation();
      updateSendStage();
      saveDraft();
      if (sendBootstrapLoading) sendBootstrapLoading.ready();
    }).fail(function () {
      bootstrapFailed("دریافت اطلاعات لازم انجام نشد؛ دوباره تلاش کنید. اطلاعات واردشده حفظ می‌شود.");
    }).always(function () {
      bootstrapRequest = null;
      $bootstrapRetry.prop("disabled", false);
    });
  }
  $bootstrapRetry.on("click", loadSendBootstrap);
  loadSendBootstrap();

  // ---------- Submit ----------
  $submitBtn.on("click", function () {
    if (orderPickerMarket() || !bootstrapReady || navigationLocked() || sendStage !== 1) return;
    if (lastParcelId && !informationDirty) {
      goToSendStage(2, true);
      if (!$quotesDiv.children().length) refreshQuotes();
      return;
    }

    // اعتبارسنجی
    clearErrors();
    updateItemPlaceholderRequirements();
    if (window.SalamGateValidation && !window.SalamGateValidation.validate($form)) return;

    if (!selectedMarket) { setErr("market", "مبدأ سفارش را انتخاب کنید."); return; }
    if (!validateReceiver()) return;
    if (!data.shops.some(function (shop) { return String(shop.id) === String($view.find("[data-send-sender]").val()); })) { setErr("sender", "مشخصات فرستنده فعال در دسترس نیست؛ تنظیمات اتصال را بررسی کنید."); return; }
    var weight = Number(toEn($form.find("[name='weight']").val() || ""));
    if (!Number.isSafeInteger(weight) || weight < 50) { setErr("weight", "وزن باید عدد صحیح و حداقل ۵۰ گرم باشد."); return; }
    if (weight > 30000) { setErr("weight", "وزن حداکثر ۳۰٬۰۰۰ گرم است."); return; }
    var valueText = toEn($form.find("[name='value_toman']").val() || "");
    if (!/^\d+$/.test(valueText) || !Number.isSafeInteger(Number(valueText)) || Number(valueText) < 2000 || Number(valueText) > 1000000000) {
      setErr("value", "ارزش مرسوله باید بین ۲٬۰۰۰ و ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد."); return;
    }
    if (!$view.find("[data-send-box]").val()) { setErr("box", "بسته‌بندی را انتخاب کنید."); return; }
    if (!$view.find("[data-send-category]").val()) { setErr("category", "نوع مرسوله را انتخاب کنید."); return; }
    if ($view.find("[data-send-box]").val() === "37") {
      var invalidDimension = ["length", "width", "height"].filter(function (dimension) {
        var value = Number(toEn($form.find("[name='dimension_" + dimension + "']").val() || ""));
        return !Number.isSafeInteger(value) || value < 1 || value > 2147483647;
      })[0];
      if (invalidDimension) { setErr("dimension_" + invalidDimension, "این بُعد بسته را به سانتی‌متر و بیشتر از صفر وارد کنید."); return; }
    }

    if (!validateItemRows()) return;
    var items = collectItems();
    if (!items.length) { setErr("items", "حداقل یک قلم وارد کنید."); return; }

    if (!lastParcelId) {
      creationAttempt = draftStore && draftStore.beginCreation ? draftStore.beginCreation(selectedMarket) : null;
      if (!creationAttempt || creationAttempt.invalid || creationAttempt.state !== "pending") {
        notice("شناسه امن ثبت مرسوله ذخیره نشد؛ اجازه ذخیره‌سازی مرورگر را بررسی کنید و صفحه را دوباره باز کنید.", false);
        return;
      }
    }

    submitting = true;
    $submitBtn.prop("disabled", true).text("در حال ثبت و دریافت نرخ…");
    $notice.hide();
    $quotesDiv.hide().empty();

    var payload = {
      market: selectedMarket,
      provider_shop_id: $view.find("[data-send-sender]").val(),
      weight: String(weight),
      value_toman: toEn($form.find("[name='value_toman']").val()),
      box_type_id: $view.find("[data-send-box]").val(),
      category_id: $view.find("[data-send-category]").val(),
      is_liquid: $form.find("[name='is_liquid']").is(":checked") ? "1" : "",
      is_fragile: $form.find("[name='is_fragile']").is(":checked") ? "1" : "",
      name: receiverData.name,
      mobile: receiverData.mobile,
      address: receiverData.address,
      postal_code: receiverData.postal_code,
      city_id: receiverData.city_id,
      items: items
    };

    if ($view.find("[data-send-box]").val() === "37") {
      payload.dimension_length = $form.find("[name='dimension_length']").val();
      payload.dimension_width = $form.find("[name='dimension_width']").val();
      payload.dimension_height = $form.find("[name='dimension_height']").val();
    }

    var updating = !!lastParcelId;
    var dispatchParcelId = lastParcelId;
    if (updating) {
      payload.parcel_id = lastParcelId;
      payload.original_market = parcelMarket;
    } else {
      payload.creation_id = creationAttempt.id;
    }
    // Persist only an unresolved-create marker before dispatch so navigation
    // while the request is in flight cannot silently submit a second parcel.
    creationUnresolved = true;
    saveDraft();
    var dispatchMarket = selectedMarket;
    lockParcelInputs();
    updateSendStage();
    sgPost("salam_gate_send_upsert", payload)
      .done(function (res) {
        if (res && res.success && res.data) {
          var confirmedId = Number(res.data.parcel_id) || 0;
          var replacementConfirmed = updating && Number(res.data.replaces_parcel_id) === dispatchParcelId
            && confirmedId !== dispatchParcelId && res.data.requires_quote_selection === true;
          if (!Number.isSafeInteger(confirmedId) || confirmedId <= 0 || (updating && confirmedId !== dispatchParcelId && !replacementConfirmed) || res.data.market !== dispatchMarket) {
            lastParcelId = dispatchParcelId;
            creationUnresolved = true;
            lockParcelInputs();
            saveDraft();
            notice("شناسه ثبت تأیید نشد؛ از فهرست مرسوله‌ها بررسی کنید و دوباره ثبت نکنید.", false);
            return;
          }
          lastParcelId = confirmedId;
          if (!updating && creationAttempt && draftStore) draftStore.markCreation(creationAttempt.id, "created", confirmedId);
          creationUnresolved = false;
          informationDirty = false;
          parcelMarket = dispatchMarket;
          selectedMarket = dispatchMarket;
          informationFingerprint = fingerprintInformation();
          requireQuoteSelection = updating;
          selectedPayMethod = 0;
          lockParcelInputs();
          saveDraft();
          notice(updating ? "تغییرات اطلاعات و اقلام ذخیره شد؛ روش ارسال و پرداخت را دوباره انتخاب کنید."
            : "مرسوله ثبت شد (شناسه " + faNum(res.data.parcel_id) + ")؛ نرخ‌های ارسال در ادامه.", true);
          renderQuotes(res.data.quotes);
          goToSendStage(2, true);
          return;
        }

        var d = (res && res.data) || {};
        if (updating && d.code === "parcel_replaced") {
          creationUnresolved = true;
          lockParcelInputs();
          saveDraft();
          submitting = false;
          refreshQuotes(true);
          return;
        }
        if (updating && (d.code === "pay_unknown" || d.code === "already_paid")) {
          creationUnresolved = false;
          paymentDone = d.code === "already_paid";
          paymentUnresolved = !paymentDone;
          draftFrozen = true;
          if (draftStore) draftStore.clearAll();
          lockParcelInputs();
          confirmationFeedback(paymentDone ? d.message || "پرداخت این مرسوله ثبت شده است؛ رسید را در فهرست مرسوله‌ها بررسی کنید."
            : "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", paymentDone ? "success" : "warning");
          goToSendStage(3, true);
          return;
        }
        if (d.errors) {
          $.each(d.errors, function (key, err) {
            setErr(err && typeof err === "object" ? (err.location || key) : key,
              err && typeof err === "object" ? err.message : err, false, false);
          });
          window.setTimeout(function () {
            $view.find("[aria-invalid='true']:visible").not(":disabled").first().trigger("focus");
          }, 0);
        }
        if (d.code === "create_unknown" || d.code === "update_unknown") {
          if (!updating && creationAttempt && draftStore) draftStore.markCreation(creationAttempt.id, "unknown");
          creationUnresolved = true;
          lockParcelInputs();
          saveDraft();
          notice("نتیجه ثبت یا ویرایش نامعلوم است؛ درخواست را تکرار نکنید و وضعیت را در فهرست مرسوله‌ها بررسی کنید.", false);
          $submitBtn.prop("disabled", true).text("نامعلوم — بررسی وضعیت");
          submitting = false;
          return;
        }
        notice(d.message || "ثبت ناموفق بود.", false);
        if (!updating && creationAttempt && draftStore) {
          var savedAttempt = draftStore.loadCreation();
          if (savedAttempt && savedAttempt.state !== "created") draftStore.clearCreation(creationAttempt.id);
          creationAttempt = null;
        }
        creationUnresolved = false;
        unlockParcelInputs();
        saveDraft();
        $submitBtn.prop("disabled", false).text("مرحله بعد");
      })
      .fail(function () {
        if (!updating && creationAttempt && draftStore) draftStore.markCreation(creationAttempt.id, "unknown");
        creationUnresolved = true;
        lockParcelInputs();
        saveDraft();
        notice("ارتباط با سرور برقرار نشد؛ نتیجه ممکن است نامعلوم باشد.", false);
        $submitBtn.prop("disabled", true).text("نامعلوم");
      })
      .always(function () { submitting = false; updateSendStage(); });
  });

  // ---------- نرخ‌ها ----------
  function renderQuotes(quotesData) {
    var quotes = (quotesData && quotesData.quotes) || [];
    selectedQuote = null;
    selectedPayMethod = 0;
    $view.find("[data-send-confirm], [data-send-pay-method]").hide();
    $view.find("[data-send-main-action]").hide();
    if (!quotes.length) {
      $quotesDiv.empty().append($("<p class='sg-card__detail'>").text("نرخی برای این مرسوله یافت نشد.")).show();
      quoteProblem("دریافت نرخ کامل نشد؛ همان مرسوله را بررسی کنید، نیازی به ثبت دوباره نیست.");
      return;
    }

    var selected = null;
    var $list = $("<div class='sg-quotes-cards'>");
    $.each(quotes, function (i, q) {
      var disabled = !validQuote(q);
      if (q.selected && !disabled && !requireQuoteSelection) selected = selected === null ? q : false;
      var $card = $("<button type='button'>")
        .addClass("sg-quote-card")
        .toggleClass("is-disabled", disabled)
        .toggleClass("is-selected", !!q.selected && !disabled && !requireQuoteSelection)
        .prop("disabled", disabled)
        .attr("aria-pressed", q.selected && !disabled && !requireQuoteSelection ? "true" : "false")
        .data("hash", q.hash_id);

      if (!disabled) {
        $card.on("click", function () {
          if (!quoteBusy && !paying && !paymentDone && !paymentUnresolved) selectQuote(q.hash_id);
        });
      }

      $card.append(
        $('<div class="sg-quote-card__header">').append(
          $('<span class="sg-quote-card__name">').text(q.service_name || q.service_type_label || "سرویس"),
          q.discount_percent ? $('<span class="sg-quote-card__discount">').text(q.discount_percent + "٪ تخفیف") : $()
        )
      );

      // Display the service's discounted fare here, not its payable invoice total.
      // Missing fare data stays unavailable; never label the total as a fare.
      var fare = Number.isFinite(q.base_cost_toman) && q.base_cost_toman >= 0 ? q.base_cost_toman : null;
      $card.append($('<small class="sg-quote-card__fare-label">').text("کرایه پستی بعد از تخفیف"),
        $('<div class="sg-quote-card__price">').text(fare !== null ? faThousands(fare) + " تومان" : "—"));
      if (q.error) { $card.append($('<div class="sg-quote-card__error">').text(q.error)); }

      $list.append($card);
    });

    $quotesDiv.empty().append($("<h4 class='sg-send__shipping-title'>").append(
      $("<span class='dashicons dashicons-car' aria-hidden='true'>"), "شیوه ارسال"
    )).append($list, $("<p class='sg-field__hint'>").text("مبلغ نهایی با بیمه، مالیات و سایر هزینه‌ها در مرحله تأیید نمایش داده می‌شود.")).show();
    if (selected && !paymentDone && !paymentUnresolved) showPayStep(selected);
    else $view.find("[data-send-quote-notice]").hide().empty();
  }

  // ---------- پارت ۷: روش پرداخت + تأیید + پرداخت ----------

  var selectedQuote = null;
  var selectedPayMethod = 0;
  var lastParcelId = 0;
  var paying = false;
  var quoteBusy = false;
  var quoteSequence = 0;
  var paymentDone = false;
  var paymentUnresolved = false;

  function validQuote(q) {
    return q && typeof q.hash_id === "string" && q.hash_id !== "" && !q.error
      && typeof q.total_rial === "number" && Number.isSafeInteger(q.total_rial) && q.total_rial >= 0
      && q.total_toman != null && Number.isFinite(Number(q.total_toman));
  }

  function quoteProblem(message, allowRetry, kind) {
    if (kind === undefined) kind = "error";
    var $target = $view.find("[data-send-quote-notice]").empty().show();
    $target.append($("<p>").text(message));
    if (allowRetry !== false && lastParcelId && !paymentDone && !paymentUnresolved) {
      $target.append($("<button type='button' class='sg-button sg-button--secondary' data-send-refresh-quotes>").text("دریافت دوباره نرخ‌ها"));
    }
    $target.append($("<a class='sg-button sg-button--secondary'>")
      .attr("href", adminUrlBase + "admin.php?page=salam_gate_parcels").text("بررسی در فهرست مرسوله‌ها"));
    operationFeedback($target, message, kind);
  }

  function setPaymentStatus(message, kind) {
    var $target = $view.find("[data-send-pay-status]").removeClass("is-warning is-error is-success")
      .addClass(kind ? "is-" + kind : "").text(message || "").toggle(!!message);
    operationFeedback($target, message, message ? kind : "");
  }

  function clearPaymentFeedback() {
    setPaymentStatus("");
    $view.find("[data-send-wallet-topup]").remove();
    $view.find("[data-send-confirm-notice]").hide().empty().removeClass("sg-feedback-actions sg-feedback-toast-only").attr("role", "alert");
  }

  function showPaymentError(response, method) {
    // A definitive failure has one alert; do not repeat it in the status region.
    setPaymentStatus("");
    $view.find("[data-send-wallet-topup]").remove();
    var $error = $view.find("[data-send-confirm-notice]").empty();
    var message = String(response.message || "پرداخت ناموفق بود.");
    var paymentReview = ["pay_unknown", "unknown"].indexOf(response.code) !== -1
      || ["pending", "unknown"].indexOf(response.payment_state) !== -1
      || response.operation && response.operation.state === "unknown";
    if (paymentReview) message = "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.";
    $error.append($("<p>").text(message));
    // The payment API reserves this validation location for wallet insufficiency.
    // Do not infer a top-up action from translated/generic balance or auth errors.
    var walletInsufficient = method === 21 && response.kind === "validation"
      && Array.isArray(response.errors) && response.errors.some(function (error) {
        return error && error.location === "user_wallet";
      });
    if (walletInsufficient) {
      $view.find(".sg-send__payment-actions").prepend($("<a class='sg-button sg-button--secondary sg-payment-topup' data-send-wallet-topup>")
        .attr({ href: "https://wallet.basalam.com/?from=vendor", target: "_blank", rel: "noopener noreferrer" })
        .append($("<span class='dashicons dashicons-money-alt' aria-hidden='true'>"), $("<span>").text("شارژ کیف پول")));
    }
    if (paymentReview) $error.append($("<a class='sg-button sg-button--secondary' data-send-payment-review>")
      .attr("href", adminUrlBase + "admin.php?page=salam_gate_parcels").text("بررسی وضعیت مرسوله"));
    $error.show();
    if (typeof window.woosalamNotify === "function") {
      $error.attr("role", "group").toggleClass("sg-feedback-actions", paymentReview).toggleClass("sg-feedback-toast-only", !paymentReview);
      window.woosalamNotify(message, "error");
    }
  }

  function setControlsBusy(busy) {
    $quotesDiv.find("button.sg-quote-card:not(.is-disabled)").prop("disabled", busy);
    $view.attr("data-sg-financial-busy", String(!!busy));
    $view.find("input[name='send_pay_method'], [data-send-back-to-quotes]").prop("disabled", busy);
    $view.find("[data-send-pay]").prop("disabled", busy || !canReview());
    updateSendStage();
    if (window.SalamGatePaymentPresentation) window.SalamGatePaymentPresentation.refresh($view[0]);
  }

  function adoptCanonicalParcel(response) {
    var id = Number(response.parcel_id);
    var replacement = id !== lastParcelId;
    if (!Number.isSafeInteger(id) || id <= 0
        || (replacement && (Number(response.replaces_parcel_id) !== lastParcelId || response.requires_quote_selection !== true))
        || data.markets.indexOf(response.market) === -1) return false;
    var selectionRequired = requireQuoteSelection || response.requires_quote_selection === true;
    if (!response.payment_state && !(response.result && response.result.confirmed)) {
      var payload = response.parcel_payload || response.replacement_payload;
      if (!$.isPlainObject(payload) || !$.isPlainObject(payload.receiver)
          || !Array.isArray(payload.items) || !payload.items.length) return false;
      if (!["provider_shop_id", "category_id", "weight", "box_type_id"].every(function (key) {
        return Number.isSafeInteger(Number(payload[key])) && Number(payload[key]) > 0;
      }) || !Number.isSafeInteger(Number(payload.value_toman)) || Number(payload.value_toman) < 0) return false;
      var receiver = payload.receiver, province = "";
      $.each(data.provinces, function (_, item) {
        if ((item.children || []).some(function (city) { return String(city.id) === String(receiver.city_id); })) province = String(item.id);
      });
      if (!province || !["name", "mobile", "address", "postal_code"].every(function (key) { return typeof receiver[key] === "string"; })
          || !payload.items.every(function (item) { return $.isPlainObject(item) && typeof item.title === "string"
            && Number.isSafeInteger(Number(item.quantity)) && Number(item.quantity) > 0
            && Number.isSafeInteger(Number(item.net_weight)) && Number(item.net_weight) > 0
            && Number.isSafeInteger(Number(item.price_toman)) && Number(item.price_toman) >= 0; })) return false;
      var form = {};
      $.each(["provider_shop_id", "weight", "value_toman", "box_type_id", "category_id", "dimension_length", "dimension_width", "dimension_height", "is_liquid", "is_fragile"], function (_, key) { form[key] = payload[key]; });
      restoreDraft({ market: response.market, form: form, receiver: receiver,
        receiverModal: $.extend({}, receiver, { province_id: province }), items: payload.items,
        parcel: { id: id, market: response.market }, information_dirty: false, creation_unresolved: false });
    } else {
      lastParcelId = id;
      parcelMarket = response.market;
      selectedMarket = response.market;
    }
    requireQuoteSelection = selectionRequired || informationDirty;
    informationFingerprint = fingerprintInformation();
    saveDraft();
    lockParcelInputs();
    return true;
  }

  function refreshQuotes(recoverUpdate) {
    recoverUpdate = recoverUpdate === true;
    if (orderPickerMarket() || !lastParcelId || (informationDirty && !recoverUpdate) || quoteBusy || paying || submitting || paymentDone || paymentUnresolved) return;
    clearPaymentFeedback();
    var sequence = ++quoteSequence;
    quoteBusy = true;
    var quotesLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "quotes", function () { refreshQuotes(recoverUpdate === true); });
    selectedQuote = null;
    selectedPayMethod = 0;
    goToSendStage(2, false);
    $view.find("[data-send-confirm], [data-send-pay-method]").hide();
    $view.find("[data-send-quote-notice]").hide().empty();
    setControlsBusy(true);
    sgPost("salam_gate_send_quotes", { parcel_id: lastParcelId, market: parcelMarket })
      .done(function (res) {
        if (sequence !== quoteSequence) return;
        if (res && res.success && res.data && Number(res.data.parcel_id) !== lastParcelId && res.data.replaces_parcel_id) {
          if (!adoptCanonicalParcel(res.data)) {
            creationUnresolved = true;
            lockParcelInputs();
            saveDraft();
            quoteProblem("اطلاعات پیش‌نویس جایگزین تأیید نشد؛ ثبت یا پرداخت دوباره انجام ندهید.", false);
            return;
          }
        }
        if (res && res.success && res.data && Number(res.data.parcel_id) === lastParcelId && res.data.market === parcelMarket) {
          if (recoverUpdate && !res.data.payment_state && !res.data.result && res.data.requires_quote_selection !== true) {
            // No confirmed edit marker: preserve the user's unsaved information
            // and never use rates for the older unchanged fields.
            creationUnresolved = false;
            informationDirty = true;
            requireQuoteSelection = true;
            quoteBusy = false;
            unlockParcelInputs();
            goToSendStage(1, false);
            saveDraft();
            notice("ویرایش قبلی تأیید نشده؛ تغییرات را ذخیره و روش ارسال را دوباره انتخاب کنید.", "warning");
            return;
          }
          var canonical = res.data.parcel_payload || res.data.replacement_payload;
          if (!res.data.payment_state && !res.data.result && (canonical || recoverUpdate)) {
            if (!adoptCanonicalParcel(res.data)) {
              creationUnresolved = true;
              informationDirty = true;
              requireQuoteSelection = true;
              lockParcelInputs();
              saveDraft();
              quoteProblem("اطلاعات ذخیره‌شده مرسوله تأیید نشد؛ ثبت یا پرداخت دوباره انجام ندهید.", false);
              return;
            }
          }
          if (recoverUpdate) {
            creationUnresolved = false;
            informationFingerprint = fingerprintInformation();
            saveDraft();
          }
          requireQuoteSelection = requireQuoteSelection || res.data.requires_quote_selection === true;
          if (res.data.result && res.data.result.confirmed) {
            showPayResult(res.data.result);
            return;
          }
          if (res.data.payment_state) {
            paymentDone = res.data.payment_state === "paid";
            paymentUnresolved = !paymentDone;
            $quotesDiv.hide();
            sendStage = 3;
            $view.find("[data-send-confirm]").show();
            confirmationFeedback(paymentDone ? "این مرسوله قبلاً پرداخت شده است؛ رسید را در فهرست مرسوله‌ها ببینید."
              : "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", paymentDone ? "success" : "warning", false);
            quoteProblem(paymentDone ? "پرداخت این مرسوله قبلاً ثبت شده است؛ وضعیت و رسید را در فهرست مرسوله‌ها ببینید."
              : "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", false, paymentDone ? "success" : "warning");
            return;
          }
          $view.find("[data-send-quote-notice]").hide().empty();
          if (informationDirty) quoteProblem("فرستنده این پیش‌نویس در دسترس نیست؛ تنظیمات فرستنده را بررسی کنید.", false);
          else renderQuotes(res.data.quotes);
        } else quoteProblem((res && res.data && res.data.message) || "نرخ‌های این مرسوله تأیید نشدند.");
      })
      .fail(function () { if (sequence === quoteSequence) quoteProblem("دریافت نرخ ناموفق بود؛ پرداختی ارسال نشد."); })
      .always(function () {
        if (sequence !== quoteSequence) return;
        quoteBusy = false;
        setControlsBusy(false);
        renderConfirm();
        // Failure/unknown states are rendered explicitly by quoteProblem above;
        // reveal them only after this read has fully settled, never while pending.
        if (quotesLoading) quotesLoading.ready();
      });
  }

  $view.on("click", "[data-send-refresh-quotes]", refreshQuotes);

  $view.on("click", "[data-send-recheck-payment]", function () {
    if (!lastParcelId || !paymentUnresolved || paymentDone || quoteBusy || paying || submitting || creationUnresolved) return;
    var sequence = ++quoteSequence;
    quoteBusy = true;
    selectedQuote = null;
    selectedPayMethod = 0;
    confirmationFeedback("در حال بررسی نتیجه پرداخت قبلی؛ درخواست پرداخت تازه‌ای ارسال نمی‌شود…", "");
    setControlsBusy(true);
    sgPost("salam_gate_send_quotes", { parcel_id: lastParcelId, market: parcelMarket })
      .done(function (res) {
        if (sequence !== quoteSequence) return;
        var response = res && res.success === true && res.data;
        if (!response || Number(response.parcel_id) !== lastParcelId || response.market !== parcelMarket) {
          confirmationFeedback("وضعیت همین مرسوله تأیید نشد؛ دوباره پرداخت نکنید و کمی بعد بررسی کنید.", "error");
          return;
        }
        if (response.result && response.result.confirmed === true) { showPayResult(response.result); return; }
        if (response.payment_state === "paid") {
          paymentDone = true;
          paymentUnresolved = false;
          confirmationFeedback("پرداخت قبلی ثبت شده است؛ کد رهگیری و رسید را در فهرست مرسوله‌ها ببینید. دوباره پرداخت نکنید.", "success");
          return;
        }
        var hashes = Object.create(null), selectedCount = 0;
        var envelope = response.quotes;
        var ratesValid = envelope && Number(envelope.parcel_id) === lastParcelId && Array.isArray(envelope.quotes)
          && envelope.quotes.every(function (q) {
            if (q && q.error && q.selected === false) return true;
            if (!validQuote(q) || typeof q.selected !== "boolean" || hashes[q.hash_id]) return false;
            hashes[q.hash_id] = true;
            if (q.selected) selectedCount++;
            return selectedCount <= 1;
          });
        // The standalone payable contract uses the empty payment_state. Only
        // the server's owner/attempt-correlated failure proof may release it.
        if (response.payment_failure_confirmed !== true || response.payment_state !== "" || !ratesValid
            || !adoptCanonicalParcel(response) || informationDirty) {
          confirmationFeedback("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", "warning");
          return;
        }
        paymentUnresolved = false;
        requireQuoteSelection = true;
        clearPaymentFeedback();
        goToSendStage(2, false);
        renderQuotes(envelope);
        setPaymentStatus("ناموفق‌بودن پرداخت قبلی تأیید شد. روش ارسال و شیوه پرداخت را دوباره انتخاب کنید.", "warning");
        saveDraft();
      })
      .fail(function () {
        if (sequence === quoteSequence) confirmationFeedback("وضعیت پرداخت دریافت نشد؛ دوباره پرداخت نکنید و کمی بعد بررسی کنید.", "error");
      })
      .always(function () {
        if (sequence !== quoteSequence) return;
        quoteBusy = false;
        setControlsBusy(paymentDone || paymentUnresolved);
        renderConfirm();
      });
  });

  function selectQuote(hash) {
    if (orderPickerMarket() || !lastParcelId || informationDirty || creationUnresolved || submitting || quoteBusy || paying || paymentDone || paymentUnresolved || sendStage !== 2) return;
    clearPaymentFeedback();
    var sequence = ++quoteSequence;
    quoteBusy = true;
    selectedQuote = null;
    selectedPayMethod = 0;
    $view.find("[data-send-confirm], [data-send-pay-method]").hide();
    $view.find("[data-send-quote-notice]").hide().empty();
    var selectionLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "quote-selection", refreshQuotes);
    setControlsBusy(true);
    sgPost("salam_gate_send_select_quote", { parcel_id: lastParcelId, market: selectedMarket, hash: hash })
      .done(function (res) {
        if (sequence !== quoteSequence) return;
        var envelope = res && res.success && res.data && Number(res.data.parcel_id) === lastParcelId && res.data.market === parcelMarket ? res.data.quotes : null;
        var matches = envelope && Array.isArray(envelope.quotes) ? envelope.quotes.filter(function (q) { return q.selected && q.hash_id === hash && validQuote(q); }) : [];
        if (matches.length !== 1) {
          quoteProblem((res && res.data && res.data.message) || "انتخاب نرخ تأیید نشد؛ قبل از پرداخت دوباره نرخ بگیرید.");
          return;
        }
        if (res.data.parcel_payload && (!adoptCanonicalParcel(res.data) || informationDirty)) {
          informationDirty = true;
          requireQuoteSelection = true;
          saveDraft();
          quoteProblem("اطلاعات یا فرستنده این مرسوله تأیید نشد؛ تنظیمات را بررسی کنید و دوباره نرخ بگیرید.", false);
          return;
        }
        requireQuoteSelection = false;
        $view.find("[data-send-quote-notice]").hide().empty();
        renderQuotes(envelope);
        setPaymentStatus("");
      })
      .fail(function () { if (sequence === quoteSequence) quoteProblem("پاسخ انتخاب نرخ مشخص نیست؛ ابتدا نرخ‌ها را دوباره بررسی کنید. پرداختی ارسال نشد."); })
      .always(function () {
        if (sequence !== quoteSequence) return;
        quoteBusy = false;
        setControlsBusy(false);
        renderConfirm();
        if (selectionLoading) selectionLoading.ready();
      });
  }

  function showPayStep(quote) {
    if (orderPickerMarket()) return;
    selectedQuote = quote;
    if (validQuote(quote) && selectedPayMethod !== 20 && selectedPayMethod !== 21) selectedPayMethod = 20;

    // روش پرداخت: فقط بازارهای غیر باسلام → 20/21
    var $wrap = $view.find("[data-send-pay-methods]").empty();
    var methods = [[20, "تراز غرفه‌دار", "برداشت از موجودی تراز غرفهٔ متصل در باسلام"],
      [21, "کیف پول", "برداشت از موجودی کیف پول حساب متصل در باسلام"]];
    $.each(methods, function (i, m) {
      var id = "sgpm-" + m[0];
      $wrap.append(
        $("<label class='sg-send-payment-option'>").toggleClass("is-selected", selectedPayMethod === m[0]).append(
          $("<input>").attr({ type: "radio", name: "send_pay_method", value: m[0], id: id }).prop("checked", selectedPayMethod === m[0]).data("title", m[1]),
          $("<span class='sg-send-payment-option__content'>").append(
            $("<span class='sg-send-payment-option__label'>").text(m[1]),
            $("<span class='sg-send-payment-option__description'>").text(m[2])
          )
        )
      );
    });
    $view.find("[data-send-pay-method]").show();
    if (window.SalamGatePaymentPresentation) {
      var presentation = window.SalamGatePaymentPresentation;
      presentation.refresh($view[0]);
      if (typeof presentation.whenReady === "function") {
        var balanceLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "balances", function () {
          presentation.refreshBalance($view[0]); showPayStep(selectedQuote);
        });
        presentation.whenReady($view[0]).always(function () { if (balanceLoading) balanceLoading.ready(); });
      }
    }


    renderConfirm();
  }

  $view.on("change", "input[name='send_pay_method']", function () {
    if (navigationLocked() || informationDirty || requireQuoteSelection || sendStage !== 2 || !validQuote(selectedQuote)) return;
    clearPaymentFeedback();
    var method = Number($(this).val() || 0);
    selectedPayMethod = method === 20 || method === 21 ? method : 0;
    $view.find(".sg-send-payment-option").each(function () { $(this).toggleClass("is-selected", $(this).find("input").is(":checked")); });
    $view.find("[data-error-for='pay_method']").text("").hide();
    renderConfirm();
  });

  function renderConfirm() {
    $view.attr("data-sg-payable-rial", validQuote(selectedQuote) ? selectedQuote.total_rial : "");
    updateSendStage();
    if (!canReview()) {
      $view.find("[data-send-confirm]").toggle(sendStage === 3 && (paymentDone || paymentUnresolved) && !$view.find("[data-send-result]").children().length);
      if (paymentUnresolved) $view.find("[data-send-pay]").text("در حال بررسی");
      return;
    }

    var toman = selectedQuote.total_rial / 10;
    var discount = selectedQuote.discount_percent;

    var payTitle = "";
    $view.find("input[name='send_pay_method']").each(function () {
      if (Number($(this).val()) === selectedPayMethod) payTitle = $(this).data("title") || "";
    });

    var $review = $view.find("[data-send-review]").empty();
    var rows = [
      ["سرویس ارسال", selectedQuote.service_name || "—"],
      ["مبلغ نهایی قابل پرداخت", toman != null ? window.SalamGateAmount.fromRial(selectedQuote.total_rial) : "—"],
      ["تخفیف", discount ? discount + "٪" : "—"],
      ["روش پرداخت", payTitle || "—"],
      ["گیرنده", receiverData ? receiverData.name : "—"],
      ["مقصد", receiverData ? (receiverData.city_title + "، " + receiverData.province_title) : "—"]
    ];
    $.each(rows, function (i, row) {
      $review.append($("<div class='sg-wizard__review-row'>").append($("<dt>").text(row[0]), $("<dd>").text(row[1])));
    });

    $view.find("[data-send-confirm]").toggle(sendStage === 3);
    if (!paying) $view.find("[data-send-pay]").prop("disabled", false).text("تأیید و پرداخت " + window.SalamGateAmount.fromRial(selectedQuote.total_rial));
  }

  $view.find("[data-send-back-to-quotes]").on("click", function () {
    if (navigationLocked()) return;
    clearPaymentFeedback();
    goToSendStage(2, true);
  });

  $view.find("[data-send-pay]").on("click", function () {
    if (navigationLocked() || sendStage !== 3 || !canReview()) return;
    if (!lastParcelId) return;

    paying = true;
    var dispatchedPayMethod = selectedPayMethod;
    clearPaymentFeedback();
    var $btn = $(this).prop("disabled", true).text("در حال پرداخت…");
    setControlsBusy(true);
    setPaymentStatus("درخواست پرداخت ارسال شد؛ تا مشخص‌شدن نتیجه دوباره پرداخت نکنید.");

    sgPost("salam_gate_send_pay", {
      parcel_id: lastParcelId,
      market: selectedMarket,
      method: String(dispatchedPayMethod),
      expected_hash: selectedQuote.hash_id,
      expected_total_rial: String(selectedQuote.total_rial)
    })
      .done(function (res) {
        if (res && res.success && res.data) {
          showPayResult(res.data);
          return;
        }

        var dd = (res && res.data) || {};
        var code = dd.code || "";
        setPaymentStatus("");

        if (code === "quote_changed") {
          selectedQuote = null;
          quoteProblem(dd.message || "نرخ تغییر کرده؛ دوباره نرخ بگیرید و مبلغ تازه را تأیید کنید.");
          selectedPayMethod = 0;
          goToSendStage(2, false);
        } else if (code === "already_paid") {
          paymentDone = true;
          quoteProblem("این مرسوله قبلاً پرداخت شده است؛ رسید و وضعیت را در فهرست مرسوله‌ها ببینید.", false, "success");
          confirmationFeedback("این مرسوله قبلاً پرداخت شده است؛ رسید و وضعیت را در فهرست مرسوله‌ها ببینید.", "success", false);
          $view.find("[data-send-pay-method]").hide();
          draftFrozen = true;
          if (draftStore) draftStore.clearAll();
        } else if (code === "pay_unknown") {
          paymentUnresolved = true;
          confirmationFeedback("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", "warning", false);
          $btn.prop("disabled", true).text("در حال بررسی");
          quoteProblem("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", false, "warning");
          paying = false;
          return;
        } else {
          showPaymentError(dd, dispatchedPayMethod);
        }
      })
      .fail(function () {
        setPaymentStatus("");
        paymentUnresolved = true;
        confirmationFeedback("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", "error", false);
        $btn.prop("disabled", true).text("در حال بررسی");
        quoteProblem("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", false);
      })
      .always(function () {
        paying = false;
        setControlsBusy(paymentDone || paymentUnresolved);
        renderConfirm();
      });
  });

  function showPayResult(d) {
    clearPaymentFeedback();
    paymentDone = true;
    sendStage = 3;
    draftFrozen = true;
    if (creationAttempt && draftStore && draftStore.clearCreation) draftStore.clearCreation(creationAttempt.id);
    if (draftStore) draftStore.clearAll();
    var confirmed = d && d.confirmed && d.tracking_code;
    notice(confirmed ? "پرداخت مرسوله با موفقیت انجام شد." : "پرداخت ثبت شد؛ صدور رسید در حال بررسی است. دوباره پرداخت نکنید.", confirmed ? true : "warning");
    var $card = $("<div class='sg-card'>").toggleClass("sg-card--success", !!confirmed).append(
      $("<h4>").text(confirmed ? "پرداخت موفق ✓" : "پرداخت ثبت شد؛ صدور رسید در حال بررسی است"),
      $("<p class='sg-card__text'>").text(confirmed ? "کد رهگیری: " + d.tracking_code : "دوباره پرداخت نکنید؛ وضعیت و کد رهگیری را در فهرست مرسوله‌ها بررسی کنید.")
    );
    if (Number.isSafeInteger(d.final_total_rial) && d.final_total_rial >= 0) $card.append($("<p class='sg-card__detail'>").text("هزینه نهایی ارسال: " + window.SalamGateAmount.fromRial(d.final_total_rial)));
    if (confirmed) $card.append($("<p class='sg-card__detail'>").text("مرکز تحویل: " + (d.delivery_node || "—")));
    $card.append($("<a class='sg-button sg-button--primary'>").attr("href", adminUrlBase + "admin.php?page=salam_gate_parcels").text("فهرست مرسوله‌ها"));
    $view.find("[data-send-result]").empty().append($card).show();
    $view.find("[data-send-confirm], [data-send-pay-method], [data-send-quote-notice]").hide();
    $quotesDiv.hide();
    setControlsBusy(true);
    $view.find("[data-send-result] h4").attr("tabindex", "-1").trigger("focus");
  }

  window.addEventListener("pageshow", function (event) {
    if (event.persisted && draftStore) {
      // Do not let this cached form overwrite the newer snapshot on pagehide.
      draftFrozen = true;
      if (!draftStore.isCurrent()) {
        // A reload/account change invalidated this cached document's recipient.
        window.location.reload();
      } else {
        // A newer send document may have created/paid this parcel. Normal
        // navigation rehydrates the latest draft without treating Back as reload.
        window.location.replace(window.location.href);
      }
    }
  });

  if (creationAttempt && (!initialDraft || !initialDraft.parcel)) {
    creationUnresolved = true;
    lockParcelInputs();
    updateSendStage();
    if (!creationAttempt.invalid) checkCreationStatus();
  }

  // ---------- errors ----------
  function sendFieldName(location) {
    var path = Array.isArray(location) ? location.join(".") : String(location || "");
    if (/(^|\.)items(?:\.|\[|$)/.test(path)) return "items";
    var name = path.split(".").pop();
    var aliases = { provider_shop_id: "sender", box_type_id: "box", category_id: "category", value_toman: "value",
      name: "receiver", mobile: "receiver", address: "receiver", postal_code: "receiver", city_id: "receiver" };
    name = aliases[name] || name;
    return ["market", "receiver", "sender", "weight", "value", "box", "category", "items", "dimension_length", "dimension_width", "dimension_height", "pay_method"].indexOf(name) !== -1 ? name : "";
  }

  function setErr(location, msg, focus, announce) {
    var name = sendFieldName(location);
    var messages = { market: "مبدأ سفارش را انتخاب کنید.", receiver: "اطلاعات گیرنده را بررسی کنید.", sender: "مشخصات فرستنده فعال را در تنظیمات بررسی کنید.",
      weight: "وزن مرسوله را به گرم وارد کنید.", value: "ارزش مرسوله را به تومان وارد کنید.", box: "نوع بسته‌بندی را انتخاب کنید.",
      category: "نوع مرسوله را انتخاب کنید.", items: "اطلاعات اقلام مرسوله را بررسی کنید.",
      dimension_length: "طول بسته را به سانتی‌متر وارد کنید.", dimension_width: "عرض بسته را به سانتی‌متر وارد کنید.",
      dimension_height: "ارتفاع بسته را به سانتی‌متر وارد کنید.", pay_method: "روش پرداخت را انتخاب کنید." };
    var message = typeof msg === "string" && /[\u0600-\u06ff]/.test(msg) ? msg : (messages[name] || "اطلاعات مرسوله را بررسی کنید.");
    var path = Array.isArray(location) ? location.join(".") : String(location || "");
    var receiverField = name === "receiver" ? ({ name: "name", mobile: "mobile", address: "address", postal_code: "postal", city_id: "city" })[path.split(".").pop()] : "";
    var $err = receiverField ? $receiverFields.find("[data-receiver-error-for='" + receiverField + "']")
      : (name ? $view.find("[data-error-for='" + name + "']") : $());
    if ($err.length) $err.text(message).show();
    var controls = { market: "input[name='market']", receiver: "#src-name", sender: "[data-send-sender]",
      weight: "[name='weight']", value: "[name='value_toman']", box: "[data-send-box]", category: "[data-send-category]",
      dimension_length: "[name='dimension_length']", dimension_width: "[name='dimension_width']", dimension_height: "[name='dimension_height']",
      pay_method: "input[name='send_pay_method']" };
    var $control = receiverField ? $receiverFields.find("#src-" + receiverField)
      : (name === "items" ? $view.find("[data-send-items] [aria-invalid='true']").first() : $view.find(controls[name] || ".sg-send-no-control").first());
    if (name === "items" && !$control.length) $control = $view.find(".sg-send-item-title, [data-send-add-item]").first();
    $control.attr("aria-invalid", "true").addClass("sg-field__input--invalid");
    if (name === "receiver" && !receiverField) $control.attr("aria-describedby", "src-name-error sg-send-receiver-error");
    if (focus !== false) $control.trigger("focus");
    if (announce !== false) notice(message, false);
  }
  function clearErrors() {
    $view.find("[data-error-for], [data-send-item-error], [data-receiver-error-for]").text("").hide();
    $view.find("[aria-invalid]").removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
  }

  function clearReceiverErrors() {
    $receiverFields.find("[data-receiver-error-for]").text("").hide();
    $receiverFields.find("[aria-invalid]").removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
  }

  function validateItemRows() {
    var invalid = false;
    var hasItems = collectItems().length > 0;
    $view.find("[data-send-items] tr").each(function () {
      var $row = $(this);
      if (hasItems && emptyFirstItemRow($row)) return;
      var errors = {};
      if (!$.trim($row.find(".sg-send-item-title").val() || "")) errors.title = "عنوان قلم را وارد کنید.";
      $.each(["qty", "weight", "price"], function (_, key) {
        var raw = toEn($row.find(".sg-send-item-" + key).val() || "");
        var number = Number(raw);
        if (key === "price" && !raw) return;
        if (!raw || !/^\d+$/.test(raw) || !Number.isSafeInteger(number) || number < (key === "price" ? 1000 : 1) || (key !== "price" && number > 2147483647)) {
          errors[key] = key === "qty" ? "تعداد باید عدد صحیح و حداقل ۱ باشد."
            : (key === "weight" ? "وزن خالص باید عدد صحیح و حداقل ۱ گرم باشد." : "قیمت هر قلم باید عدد صحیح و حداقل ۱٬۰۰۰ تومان باشد.");
        }
      });
      $.each(errors, function (key, message) {
        invalid = true;
        $row.find("[data-send-item-error='" + key + "']").text(message).show();
        $row.find(".sg-send-item-" + key).attr("aria-invalid", "true").addClass("sg-field__input--invalid");
      });
    });
    if (invalid) setErr("items", "اطلاعات مشخص‌شده در اقلام مرسوله را اصلاح کنید.");
    return !invalid;
  }

  $form.on("input change", "input, select, textarea", function () {
    var $control = $(this).removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
    String($control.attr("aria-describedby") || "").split(/\s+/).forEach(function (id) {
      var error = document.getElementById(id);
      if (error && error.classList.contains("sg-field__error")) $(error).text("").hide();
    });
  });

  updateSendStage();

});
