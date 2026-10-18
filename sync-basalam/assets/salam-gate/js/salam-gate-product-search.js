/* Local WooCommerce suggestions. Manual entry and existing draft listeners remain intact. */
jQuery(function ($) {
  "use strict";

  var $form = $("[data-sg-send-form]");
  if (!$form.length || !window.basalam_ajax) return;
  var selector = ".sg-send-item-title";
  var sequence = 0;
  var inputSequence = 0;
  var timer = null;
  var request = null;
  var activeInput = null;
  var composing = false;
  var selecting = false;
  var choices = [];
  var currentChoice = -1;
  var $popup = $("<div class='sg-product-search' dir='rtl'>").attr({ id: "sg-product-search-popup", hidden: true });
  var $heading = $("<div class='sg-product-search__heading'>").append(
    $("<strong class='sg-product-search__header-title'>").text("محصولات فروشگاه شما"),
    $("<p class='sg-product-search__subtitle'>").text("یک محصول را انتخاب کنید یا عنوان دلخواه را بنویسید."));
  var $list = $("<ul class='sg-product-search__list'>").attr({ id: "sg-product-search-options", role: "listbox", "aria-label": "پیشنهاد محصولات فروشگاه" });
  var $status = $("<p class='sg-product-search__status'>").attr({ role: "status", "aria-live": "polite" });
  $popup.append($heading, $list, $status);
  $(document.body).append($popup);

  function enhance(input) {
    if (!input.id) input.id = "sg-send-product-title-" + (++inputSequence);
    $(input).attr({ role: "combobox", "aria-autocomplete": "list", "aria-controls": $list.attr("id"),
      "aria-expanded": "false", autocomplete: "off",
      placeholder: "جست‌وجوی محصول یا عنوان دلخواه" });
  }

  function cancel() {
    sequence += 1;
    window.clearTimeout(timer);
    timer = null;
    if (request && typeof request.abort === "function") request.abort();
    request = null;
  }

  function close() {
    cancel();
    if (activeInput) $(activeInput).attr("aria-expanded", "false").removeAttr("aria-activedescendant");
    $popup.attr("hidden", true);
    $list.empty();
    $status.empty();
    choices = [];
    currentChoice = -1;
    activeInput = null;
  }

  function position() {
    if (!activeInput || !document.documentElement.contains(activeInput) || activeInput.disabled) {
      close();
      return;
    }
    var bounds = activeInput.getBoundingClientRect();
    var viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    var width = Math.min(Math.max(bounds.width, 350), viewportWidth - 24);
    var left = Math.max(12, Math.min(bounds.right - width, viewportWidth - width - 12));
    var below = viewportHeight - bounds.bottom - 14;
    var above = bounds.top - 14;
    var openAbove = below < 230 && above > below;
    $popup.css({ width: width + "px", left: left + "px", top: openAbove ? "auto" : (bounds.bottom + 6) + "px",
      bottom: openAbove ? (viewportHeight - bounds.top + 6) + "px" : "auto" });
    $list.css("max-height", Math.max(80, Math.min(320, (openAbove ? above : below) - 90)) + "px");
  }

  function showStatus(message) {
    $list.empty();
    choices = [];
    currentChoice = -1;
    if (activeInput) $(activeInput).removeAttr("aria-activedescendant").attr("aria-expanded", "true");
    $status.removeClass("screen-reader-text").text(message).show();
    $popup.removeAttr("hidden");
    position();
  }

  function imageUrl(value) {
    if (typeof value !== "string" || !value) return "";
    try {
      var url = new URL(value, window.location.href);
      return ["http:", "https:"].indexOf(url.protocol) >= 0 ? url.href : "";
    } catch (error) { return ""; }
  }

  function render(products) {
    choices = products.filter(function (product) {
      return product && typeof product.title === "string" && product.title.trim();
    }).slice(0, 8);
    if (!choices.length) {
      showStatus("محصولی پیدا نشد؛ می‌توانید عنوان قلم را دستی وارد کنید.");
      return;
    }
    currentChoice = -1;
    $list.empty();
    $status.text(choices.length + " محصول پیدا شد.").addClass("screen-reader-text").show();
    choices.forEach(function (product, index) {
      var $option = $("<li class='sg-product-search__option'>").attr({
        id: "sg-product-search-choice-" + index, role: "option", "aria-selected": "false", "data-sg-product-choice": index });
      var image = imageUrl(product.image_url);
      var $image = $("<span class='sg-product-search__image'>").attr("aria-hidden", "true");
      if (image) $image.append($("<img>").attr({ src: image, alt: "", loading: "lazy" }).on("error", function () { $(this).remove(); $image.text("▣"); }));
      else $image.text("▣");
      var $details = $("<span class='sg-product-search__details'>").append(
        $("<strong class='sg-product-search__title'>").text(product.title),
        $("<span class='sg-product-search__weight'>").text(product.weight_label || "وزن ثبت نشده"));
      $option.append($image, $details,
        $("<span class='sg-product-search__price'>").text(product.price_label || "قیمت ثبت نشده"));
      $list.append($option);
    });
    $(activeInput).attr("aria-expanded", "true").removeAttr("aria-activedescendant");
    $popup.removeAttr("hidden");
    position();
  }

  function query(input) {
    if (selecting || input.disabled || composing) return;
    enhance(input);
    cancel();
    if (activeInput && activeInput !== input) $(activeInput).attr("aria-expanded", "false").removeAttr("aria-activedescendant");
    activeInput = input;
    var term = String($(input).val() || "").trim();
    if (!term) { close(); return; }
    if (term.length > 80) { showStatus("برای جست‌وجو حداکثر ۸۰ حرف وارد کنید؛ عنوان دستی همچنان مجاز است."); return; }
    var ticket = sequence;
    $status.removeClass("screen-reader-text");
    showStatus("در حال جست‌وجوی محصولات…");
    timer = window.setTimeout(function () {
      timer = null;
      request = $.ajax({ url: window.basalam_ajax.ajaxurl, method: "POST", dataType: "json", timeout: 10000,
        data: { action: "salam_gate_search_products", _wpnonce: window.basalam_ajax.nonce, query: term } });
      function current() {
        return ticket === sequence && activeInput === input && !input.disabled
          && document.documentElement.contains(input) && String($(input).val() || "").trim() === term;
      }
      request.done(function (response) {
        if (!current()) return;
        if (response && response.success && response.data && Array.isArray(response.data.products)) render(response.data.products);
        else {
          var message = response && response.data && response.data.message || "جست‌وجو انجام نشد؛ عنوان را دستی وارد کنید یا دوباره تلاش کنید.";
          showStatus(message);
          if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, "error");
        }
      }).fail(function (xhr, status) {
        if (status === "abort" || !current()) return;
        var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
          || "جست‌وجو انجام نشد؛ عنوان را دستی وارد کنید یا دوباره تلاش کنید.";
        showStatus(message);
        if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, "error");
      }).always(function () { if (ticket === sequence) request = null; });
    }, 180);
  }

  function highlight(index) {
    if (!choices.length || !activeInput) return;
    currentChoice = (index + choices.length) % choices.length;
    var $options = $list.find("[role='option']").attr("aria-selected", "false").removeClass("is-selected");
    var option = $options.eq(currentChoice).attr("aria-selected", "true").addClass("is-selected")[0];
    $(activeInput).attr("aria-activedescendant", option.id);
    if (typeof option.scrollIntoView === "function") option.scrollIntoView({ block: "nearest" });
  }

  function choose(index) {
    var product = choices[index];
    if (!product || !activeInput || activeInput.disabled || !document.documentElement.contains(activeInput)) return;
    var $title = $(activeInput);
    var $row = $title.closest("tr");
    selecting = true;
    $title.val(product.title).trigger("input").trigger("change");
    function fill(selector, value) {
      var number = typeof value === "number" && isFinite(value) && value >= 0 ? value : "";
      $row.find(selector).filter(":enabled").val(number).trigger("input").trigger("change");
    }
    fill(".sg-send-item-weight", product.weight_grams);
    fill(".sg-send-item-price", product.price_toman);
    selecting = false;
    close();
    $title.trigger("focus");
  }

  $form.find(selector).each(function () { enhance(this); });
  $form.on("focusin.sgProductSearch", selector, function () { enhance(this); })
    .on("input.sgProductSearch", selector, function () { query(this); })
    .on("compositionstart.sgProductSearch", selector, function () { composing = true; close(); })
    .on("compositionend.sgProductSearch", selector, function () { composing = false; query(this); })
    .on("keydown.sgProductSearch", selector, function (event) {
      if (activeInput !== this || $popup.attr("hidden") !== undefined) return;
      if (event.key === "Escape") { event.preventDefault(); event.stopPropagation(); close(); }
      else if (event.key === "ArrowDown" && choices.length) { event.preventDefault(); highlight(currentChoice + 1); }
      else if (event.key === "ArrowUp" && choices.length) { event.preventDefault(); highlight(currentChoice < 0 ? choices.length - 1 : currentChoice - 1); }
      else if (event.key === "Enter" && currentChoice >= 0) { event.preventDefault(); choose(currentChoice); }
      else if (event.key === "Tab") close();
    });
  $popup.on("mousedown", function (event) { event.preventDefault(); })
    .on("click", "[data-sg-product-choice]", function () { choose(Number($(this).attr("data-sg-product-choice"))); });
  $(document).on("mousedown.sgProductSearch focusin.sgProductSearch", function (event) {
    if (activeInput && event.target !== activeInput && !$popup[0].contains(event.target)) close();
  });
  $(window).on("resize.sgProductSearch scroll.sgProductSearch", function () {
    if (activeInput) position();
  });
  document.addEventListener("scroll", function () { if (activeInput) position(); }, true);
  var observer = null;
  if (window.MutationObserver) {
    observer = new MutationObserver(function (changes) {
      changes.forEach(function (change) {
        Array.prototype.forEach.call(change.addedNodes, function (node) {
          if (node.nodeType !== 1) return;
          if ($(node).is(selector)) enhance(node);
          $(node).find(selector).each(function () { enhance(this); });
        });
      });
      if (activeInput && (!document.documentElement.contains(activeInput) || activeInput.disabled)) close();
    });
    observer.observe($form[0], { childList: true, subtree: true, attributes: true, attributeFilter: ["disabled"] });
  }
  $(window).on("pagehide.sgProductSearch unload.sgProductSearch", function () {
    if (observer) observer.disconnect();
    close();
  });
});
