// =====================================================================
// Salam Gate — calculator view (part 5)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("calculator")) return;
  var $view = $("[data-view='calculator']");
  if (!$view.length) return;

  var provinces = [];
  var $senderProvince = $view.find("[data-calc-sender-province]");
  var $receiverProvince = $view.find("[data-calc-receiver-province]");
  var $senderCity = $view.find("[data-calc-sender-city]");
  var $receiverCity = $view.find("[data-calc-receiver-city]");
  var $box = $view.find("[data-calc-box]");
  var $boxState = $view.find("[data-calc-box-state]");
  var $retry = $view.find("[data-calc-retry]");
  var loadingTicket = null;
  var referencesReady = false;
  var $submitBtn = $view.find("[data-calc-submit]");
  var $submitLabel = $view.find("[data-calc-submit-label]");
  var $spinner = $view.find("[data-calc-spinner]");
  var $result = $view.find("[data-calc-result]");
  var calculationRevision = 0;

  function sgPost(action, data) {
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, data || {}));
  }

  function toEn(v) {
    return String(v == null ? "" : v)
      .replace(/[۰-۹]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48); })
      .replace(/[٠-٩]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48); })
      .replace(/[,٬]/g, "");
  }

  function isRial(value) {
    // Do not coerce missing, string, boolean or unsafe API amounts into a price.
    return typeof value === "number" && Number.isSafeInteger(value);
  }

  function amountText(rial, signed) {
    var prefix = signed && rial !== 0 ? (rial < 0 ? "− " : "+ ") : "";
    return prefix + window.SalamGateAmount.fromRial(Math.abs(rial));
  }

  function costRow(title, rial, kind) {
    return $("<div class='sg-calc-cost-row'>")
      .addClass(kind ? "sg-calc-cost-row--" + kind : "")
      .append($("<span class='sg-calc-cost-row__title'>").text(title),
        $("<bdi class='sg-calc-cost-row__amount'>").text(amountText(rial, kind !== "total")));
  }

  function costItems(section) {
    if (!$.isPlainObject(section) || !Array.isArray(section.items)) return [];
    return section.items.filter(function (item) {
      return $.isPlainObject(item) && isRial(item.amount)
        && typeof item.title === "string" && item.title.trim() !== "";
    });
  }

  function discountTitle(costs) {
    var percent = costs.discount_percent;
    var label = "تخفیف کرایه پستی";
    if (typeof percent === "number" && Number.isFinite(percent) && percent > 0 && percent <= 100) {
      label += " (" + percent.toLocaleString("fa-IR", { maximumFractionDigits: 3 }) + " درصد)";
    }
    return label;
  }

  function renderQuote(quote) {
    var $card = $("<section class='sg-calc-quote__item'>");
    var title = $.isPlainObject(quote) && typeof quote.service_name === "string" ? quote.service_name : "سرویس پستی";
    $card.append($("<h4 class='sg-calc-quote__service'>").text(title));
    if (!$.isPlainObject(quote) || (typeof quote.error === "string" && quote.error)) {
      return $card.addClass("sg-calc-quote__item--error")
        .append($("<p>").text(quote && typeof quote.error === "string" ? quote.error : "پاسخ این سرویس معتبر نیست؛ دوباره محاسبه کنید."));
    }
    var costs = $.isPlainObject(quote.costs) ? quote.costs : {};
    if (!isRial(costs.total) || costs.total < 0) {
      return $card.addClass("sg-calc-quote__item--error")
        .append($("<p>").text("مبلغ معتبر این سرویس دریافت نشد؛ دوباره محاسبه کنید."));
    }
    $card.append(costRow("قابل پرداخت", costs.total, "total"));
    var $breakdown = $("<div class='sg-calc-costs'>");
    if (isRial(costs.raw_cost) && costs.raw_cost >= 0) {
      $breakdown.append(costRow("کرایه پستی", costs.raw_cost, "fare"));
    }
    var discounts = costItems(costs.discount);
    if (discounts.length) {
      $.each(discounts, function (_, item) {
        var title = item.title;
        if (item.key === "provider_discount" && !/درصد|[%٪]/.test(title)) title = discountTitle(costs);
        $breakdown.append(costRow(title, item.amount, "discount"));
      });
    } else if ($.isPlainObject(costs.discount) && isRial(costs.discount.total) && costs.discount.total !== 0) {
      $breakdown.append(costRow(discountTitle(costs), costs.discount.total, "discount"));
    }
    var additional = $.isPlainObject(costs.additional) ? costs.additional : {};
    var items = costItems(additional);
    if (items.length) {
      var $details = $("<details class='sg-calc-cost-details'>");
      var $summary = $("<summary class='sg-calc-cost-row'>").append(
        $("<span class='sg-calc-cost-row__title'>").text("سایر هزینه‌های پستی")
      );
      if (isRial(additional.total)) {
        $summary.append($("<bdi class='sg-calc-cost-row__amount'>").text(amountText(additional.total, true)));
      }
      $details.append($summary);
      var $items = $("<div class='sg-calc-cost-details__items'>");
      $.each(items, function (_, item) { $items.append(costRow(item.title, item.amount, "additional")); });
      $breakdown.append($details.append($items));
    } else if (isRial(additional.total)) {
      $breakdown.append(costRow("سایر هزینه‌های پستی", additional.total, "additional"));
    }
    if ($breakdown.children().length) $card.append($breakdown);
    else $card.append($("<p class='sg-calc-quote__unavailable'>").text("ریز هزینه‌ها در پاسخ این سرویس موجود نیست."));
    return $card;
  }

  function resetCalculation() {
    calculationRevision += 1;
    $result.hide().empty().removeClass("sg-calc-result--error sg-feedback-toast-only");
    $submitBtn.prop("disabled", !referencesReady);
    $submitLabel.text("محاسبه هزینه ارسال");
    $spinner.hide();
  }

  function calculationError(message) {
    var floating = typeof window.woosalamNotify === "function";
    $result.text(message).addClass("sg-calc-result--error").toggleClass("sg-feedback-toast-only", floating).show();
    if (floating) window.woosalamNotify(message, "error");
  }

  // A response belongs only to the form values it was calculated for. Editing
  // any field hides its old price immediately, including during a slow request.
  $view.on("input change", "input, select", resetCalculation);

  function referenceError(message) {
    referencesReady = false;
    $submitBtn.prop("disabled", true);
    $retry.prop("hidden", false).prop("disabled", false);
    $boxState.addClass("sg-field__state--error").text(message)
      .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
    if (loadingTicket) loadingTicket.fail(message);
    else if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, "error");
  }

  function validReference(option) {
    return $.isPlainObject(option) && /^\d+$/.test(String(option.id))
      && Number.isSafeInteger(Number(option.id)) && Number(option.id) > 0
      && typeof option.title === "string" && option.title.trim() !== "";
  }

  function provinceById(id) {
    for (var i = 0; i < provinces.length; i += 1) {
      if (String(provinces[i].id) === String(id)) return provinces[i];
    }
    return null;
  }

  function fillCities($province, $city, side) {
    var province = provinceById($province.val());
    $city.empty().append($("<option>").val("").text(province ? "انتخاب شهر" : "ابتدا استان " + side + " را انتخاب کنید"));
    $.each(province ? province.children : [], function (_, city) {
      $city.append($("<option>").val(String(city.id)).text(city.title));
    });
    var available = !!province && province.children.length > 0;
    if (province && !available) $city.find("option").text("شهری برای این استان دریافت نشد");
    $city.prop("disabled", !available);
  }

  function selectedCityIsValid($province, $city) {
    var province = provinceById($province.val());
    if (!province || $city.prop("disabled")) return false;
    return province.children.some(function (city) { return String(city.id) === String($city.val()); });
  }

  $senderProvince.on("change", function () { fillCities($senderProvince, $senderCity, "فرستنده"); });
  $receiverProvince.on("change", function () { fillCities($receiverProvince, $receiverCity, "گیرنده"); });

  // One read-only bootstrap supplies actual city/packaging IDs. Opening this
  // estimator must never flush caches or issue an invalid provider query.
  function loadReferences() {
    loadingTicket = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "references", loadReferences);
    referencesReady = false;
    $submitBtn.prop("disabled", true);
    $retry.prop("hidden", true);
    provinces = [];
    $senderProvince.add($receiverProvince).empty().append($("<option>").val("").text("در حال دریافت استان‌ها…")).prop("disabled", true);
    fillCities($senderProvince, $senderCity, "فرستنده");
    fillCities($receiverProvince, $receiverCity, "گیرنده");
    $box.empty().append($("<option>").val("").text("در حال دریافت بسته‌بندی‌ها…")).prop("disabled", true);
    $boxState.removeClass("sg-field__state--error sg-feedback-toast-only").text("در حال دریافت اطلاعات…");
    sgPost("salam_gate_onboarding_bootstrap")
    .done(function (res) {
      if (res && res.success && res.data) {
        provinces = (Array.isArray(res.data.provinces) ? res.data.provinces : []).filter(validReference).map(function (province) {
          return { id: province.id, title: province.title, children: (Array.isArray(province.children) ? province.children : []).filter(validReference) };
        });

        $senderProvince.add($receiverProvince).empty().append($("<option>").val("").text("انتخاب استان"));
        $.each(provinces, function (_, province) {
          $senderProvince.add($receiverProvince).append($("<option>").val(String(province.id)).text(province.title));
        });
        var hasCities = provinces.some(function (province) { return province.children.length > 0; });
        $senderProvince.add($receiverProvince).prop("disabled", !hasCities);
        fillCities($senderProvince, $senderCity, "فرستنده");
        fillCities($receiverProvince, $receiverCity, "گیرنده");

        // بسته‌بندی از API زنده
        $box.empty().append($("<option>").val("").text("انتخاب بسته‌بندی"));
        $.each(res.data.box_types || [], function (i, box) {
          if (box && box.id > 0 && box.title) {
            $box.append($("<option>").val(String(box.id)).text(box.title));
          }
        });
        var hasBoxes = $box.find("option[value!='']").length > 0;
        $box.prop("disabled", !hasBoxes);
        if (!hasCities || !hasBoxes) {
          referenceError(!hasBoxes ? "نوع بسته‌بندی دریافت نشد؛ دریافت دوباره اطلاعات را بزنید." : "شهرها دریافت نشدند؛ دریافت دوباره اطلاعات را بزنید.");
          return;
        }
        referencesReady = true;
        $boxState.text("");
        $submitBtn.prop("disabled", false);
        if (loadingTicket) loadingTicket.ready();
        return;
      }
      referenceError((res && res.data && res.data.message) || "دریافت اطلاعات ناموفق بود؛ دوباره تلاش کنید.");
    })
    .fail(function () { referenceError("ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید."); });
  }
  $retry.on("click", loadReferences);
  loadReferences();

  $submitBtn.on("click", function () {
    if (!referencesReady || $submitBtn.prop("disabled")) return;
    var sender = $senderCity.val();
    var receiver = $receiverCity.val();
    var weightText = toEn($view.find("#calc-weight").val() || "");
    var weight = /^\d+$/.test(weightText) ? Number(weightText) : NaN;
    var valueText = toEn($view.find("#calc-value").val() || "");
    var valueToman = /^\d+$/.test(valueText) ? Number(valueText) : NaN;
    var boxType = $box.val();
    var serviceType = $view.find("[data-calc-service]").val();

    $result.hide().empty().removeClass("sg-feedback-toast-only");

    if (window.SalamGateValidation && !window.SalamGateValidation.validate($view)) return;
    if (!$senderProvince.val() || !$receiverProvince.val()) { calculationError("استان فرستنده و گیرنده را انتخاب کنید."); return; }
    if (!selectedCityIsValid($senderProvince, $senderCity) || !selectedCityIsValid($receiverProvince, $receiverCity)) { calculationError("شهر فرستنده و گیرنده را از استان انتخاب‌شده مشخص کنید."); return; }
    if (!Number.isSafeInteger(weight) || weight < 50 || weight > 30000) { calculationError("وزن مرسوله را به گرم و عدد صحیح، بین ۵۰ تا ۳۰٬۰۰۰ گرم وارد کنید."); return; }
    if (!Number.isSafeInteger(valueToman) || valueToman < 2000 || valueToman > 214748364) { calculationError("ارزش مرسوله را به تومان و عدد صحیح، بین ۲٬۰۰۰ تا ۲۱۴٬۷۴۸٬۳۶۴ تومان وارد کنید."); return; }
    if (!boxType) { calculationError("نوع بسته‌بندی را انتخاب کنید."); return; }

    $submitBtn.prop("disabled", true);
    $submitLabel.text("در حال محاسبه…");
    $spinner.show();
    var revision = ++calculationRevision;

    sgPost("salam_gate_calculate", {
      sender_city_id: sender,
      receiver_city_id: receiver,
      weight: String(weight),
      value_toman: String(valueToman),
      box_type_id: boxType,
      service_type: serviceType || ""
    })
      .done(function (res) {
        if (revision !== calculationRevision) return;
        if (res && res.success === true && $.isPlainObject(res.data) && Array.isArray(res.data.quotes)) {
          var quotes = res.data.quotes;
          if (!quotes.length) {
            $result.removeClass("sg-calc-result--error").addClass("sg-calc-result")
              .text("برای این مسیر/وزن نرخی در دسترس نیست (نبود پوشش).").show();
            return;
          }
          var $grid = $("<div class='sg-calc-quote'>");
          $.each(quotes, function (i, q) {
            $grid.append(renderQuote(q));
          });
          var $unavailable = $grid.find(".sg-calc-quote__item--error");
          if (typeof window.woosalamNotify === "function") {
            if ($unavailable.length) {
              var messages = $unavailable.find("p").map(function () { return $(this).text(); }).get().filter(function (value, index, all) { return all.indexOf(value) === index; });
              window.woosalamNotify(messages.join(" "), "error");
              $unavailable.find("p").addClass("sg-feedback-toast-only");
              $unavailable.append($("<span class='sg-calc-quote__unavailable'>").text("نرخ این سرویس در دسترس نیست."));
            } else window.woosalamNotify("هزینه ارسال محاسبه شد.", "success");
          }
          $result.removeClass("sg-calc-result--error").empty()
            .append($("<p class='sg-calc-disclaimer'>").text("نتیجه برآوردی است؛ هزینه نهایی هنگام ثبت مرسوله محاسبه می‌شود."))
            .append($grid)
            .show();
          return;
        }
        var message = (res && res.data && res.data.message) || "محاسبه ناموفق بود.";
        calculationError(message);
      })
      .fail(function () {
        if (revision !== calculationRevision) return;
        calculationError("ارتباط با سرور برقرار نشد.");
      })
      .always(function () {
        if (revision !== calculationRevision) return;
        $submitBtn.prop("disabled", !referencesReady);
        $submitLabel.text("محاسبه هزینه ارسال");
        $spinner.hide();
      });
  });
});
