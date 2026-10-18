/**
 * SalamGate selection and Jalali date presentation. Native controls remain the
 * source of truth for existing serializers, validation and change handlers.
 */
(function (window, document) {
  "use strict";
  var scopeSelector = ".salam-gate-page, .salam-gate-order-box, #salam-gate-parcel-modal";
  var records = new Map(), observedRoots = new WeakSet();
  var queued = false, active = null, serial = 0, initialized = false;
  var months = ["فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"];
  var weekdays = ["ش", "ی", "د", "س", "چ", "پ", "ج"];
  var arrow = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg>';
  var calendarIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3v4m8-4v4M4 10h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/></svg>';
  var dayMs = 86400000;

  function fa(value) { return String(value).replace(/\d/g, function (digit) { return "۰۱۲۳۴۵۶۷۸۹"[Number(digit)]; }); }
  function en(value) { return String(value).replace(/[۰-۹٠-٩]/g, function (digit) { return String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit) >= 0 ? "۰۱۲۳۴۵۶۷۸۹".indexOf(digit) : "٠١٢٣٤٥٦٧٨٩".indexOf(digit)); }); }
  function normalize(value) { return en(value).replace(/[يى]/g, "ی").replace(/ك/g, "ک").replace(/[\u200c\u200e\u200f\u064b-\u065f]/g, "").toLowerCase().trim(); }
  function div(a, b) { return Math.trunc(a / b); }

  // Same cycle and March boundary as Utils/JalaliDate.php. Date math uses UTC,
  // so browser time zones and daylight saving cannot shift the selected day.
  function jalCal(year) {
    var breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
    if (!Number.isInteger(year) || year < 1 || year >= 3178) return null;
    var gy = year + 621, leapJ = -14, jp = breaks[0], jump = 0, n;
    for (var i = 1; i < breaks.length; i += 1) {
      jump = breaks[i] - jp;
      if (year < breaks[i]) break;
      leapJ += div(jump, 33) * 8 + div(jump % 33, 4);
      jp = breaks[i];
    }
    n = year - jp;
    leapJ += div(n, 33) * 8 + div(n % 33 + 3, 4);
    if (jump % 33 === 4 && jump - n === 4) leapJ += 1;
    var march = 20 + leapJ - (div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150);
    if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
    var leap = ((n + 1) % 33 - 1) % 4;
    return { gy: gy, march: march, leap: leap === -1 ? 4 : leap };
  }
  function monthLength(year, month) {
    var result = jalCal(year);
    if (!result || !Number.isInteger(month) || month < 1 || month > 12) return 0;
    return month <= 6 ? 31 : (month <= 11 ? 30 : (result.leap === 0 ? 30 : 29));
  }
  function validDate(date) { return !!date && date.day >= 1 && Number.isInteger(date.day) && date.day <= monthLength(date.year, date.month); }
  function parseDate(value) {
    var match = en(value).trim().match(/^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/);
    if (!match) return null;
    var date = { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) };
    return validDate(date) ? date : null;
  }
  function dateTimestamp(date) {
    if (!validDate(date)) return null;
    var cal = jalCal(date.year), offset = date.month <= 7 ? (date.month - 1) * 31 : 186 + (date.month - 7) * 30;
    return Date.UTC(cal.gy, 2, cal.march) + (offset + date.day - 1) * dayMs;
  }
  function fromGregorian(year, month, day) {
    var timestamp = Date.UTC(year, month - 1, day), jy = year - 621, cal = jalCal(jy);
    if (!cal) return null;
    if (timestamp < Date.UTC(cal.gy, 2, cal.march)) { jy -= 1; cal = jalCal(jy); }
    if (!cal) return null;
    var offset = Math.round((timestamp - Date.UTC(cal.gy, 2, cal.march)) / dayMs);
    return { year: jy, month: offset < 186 ? 1 + div(offset, 31) : 7 + div(offset - 186, 30), day: offset < 186 ? offset % 31 + 1 : (offset - 186) % 30 + 1 };
  }
  function formatDate(date) { return fa(String(date.year).padStart(4, "0") + "/" + String(date.month).padStart(2, "0") + "/" + String(date.day).padStart(2, "0")); }
  function today() {
    var now = new Date();
    return fromGregorian(now.getFullYear(), now.getMonth() + 1, now.getDate());
  }
  function isShown(element) {
    if (!element || !element.isConnected) return false;
    for (var parent = element; parent && parent.nodeType === 1; parent = parent.parentElement) {
      if (parent.hidden || parent.hasAttribute("inert")) return false;
      var style = window.getComputedStyle(parent);
      if (style.display === "none" || style.visibility === "hidden") return false;
    }
    return true;
  }
  function element(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function button(className, text) { var node = element("button", className, text); node.type = "button"; return node; }
  function setText(node, text) { if (node.textContent !== text) node.textContent = text; }
  function attribute(node, name, value) { if (node.getAttribute(name) !== value) node.setAttribute(name, value); }
  function labelFor(control) {
    var labels = control.labels && Array.prototype.slice.call(control.labels);
    var text = labels && labels.map(function (label) {
      // Wrapping labels also contain the native options and their visual adapter.
      // Read only the field caption, without changing the live control or label.
      var caption = label.cloneNode(true);
      caption.querySelectorAll("select, input, textarea, button, .sg-picker-control, .sg-field__hint, .sg-field__error, .sgb-field-error, [aria-hidden='true']")
        .forEach(function (node) { node.remove(); });
      return caption.textContent;
    }).join(" ").replace(/\*/g, "").replace(/\s+/g, " ").trim();
    return control.getAttribute("aria-label") || text || "انتخاب گزینه";
  }
  function events(control) {
    control.dispatchEvent(new window.Event("input", { bubbles: true }));
    control.dispatchEvent(new window.Event("change", { bubbles: true }));
  }

  function closePicker() {
    if (!active) return;
    var previous = active;
    active = null;
    previous.record.trigger.setAttribute("aria-expanded", "false");
    previous.overlay.remove();
    document.removeEventListener("keydown", pickerKeyboard, true);
    document.removeEventListener("focusin", containFocus, true);
    if (isShown(previous.record.trigger) && !previous.record.trigger.disabled) previous.record.trigger.focus({ preventScroll: true });
  }
  function dialogFocusables() {
    if (!active) return [];
    return Array.prototype.filter.call(active.dialog.querySelectorAll("button, input, [tabindex]"), function (node) { return !node.disabled && node.tabIndex >= 0 && isShown(node); });
  }
  function containFocus(event) {
    if (!active || active.dialog.contains(event.target)) return;
    var target = active.dialog.querySelector("[data-sg-picker-search]") || dialogFocusables()[0] || active.dialog;
    target.focus({ preventScroll: true });
  }
  function pickerKeyboard(event) {
    if (!active) return;
    if (event.key === "Escape") {
      event.preventDefault(); event.stopImmediatePropagation(); closePicker(); return;
    }
    if (event.key !== "Tab") return;
    var nodes = dialogFocusables(), first = nodes[0] || active.dialog, last = nodes[nodes.length - 1] || active.dialog;
    if (!active.dialog.contains(document.activeElement) || (!event.shiftKey && document.activeElement === last) || (event.shiftKey && (document.activeElement === first || document.activeElement === active.dialog))) {
      event.preventDefault(); (event.shiftKey ? last : first).focus();
    }
  }
  function makeDialog(record, title) {
    closePicker();
    var overlay = element("div", "sg-picker-overlay"), dialog = element("section", "sg-picker-dialog"), header = element("div", "sg-picker-dialog__header");
    var heading = element("h3", "sg-picker-dialog__title", title), close = button("sg-picker-dialog__close", "×");
    heading.id = "sg-picker-title-" + (++serial);
    dialog.setAttribute("role", "dialog"); dialog.setAttribute("aria-modal", "true"); dialog.setAttribute("aria-labelledby", heading.id); dialog.tabIndex = -1;
    close.setAttribute("aria-label", "بستن");
    close.addEventListener("click", closePicker);
    header.append(heading, close); dialog.append(header); overlay.append(dialog);
    overlay.addEventListener("click", function (event) { if (event.target === overlay) closePicker(); });
    active = { record: record, overlay: overlay, dialog: dialog };
    record.trigger.setAttribute("aria-expanded", "true");
    document.body.append(overlay);
    document.addEventListener("keydown", pickerKeyboard, true);
    document.addEventListener("focusin", containFocus, true);
    return active;
  }
  function schedule() {
    if (queued) return;
    queued = true;
    Promise.resolve().then(function () { queued = false; refresh(); });
  }
  function propertyState(select) {
    return select.selectedIndex + ":" + select.value + (select.multiple ? ":" + Array.prototype.map.call(select.selectedOptions, function (option) { return option.index; }).join(",") : "");
  }
  function syncSelect(record) {
    var select = record.control;
    record.propertyState = propertyState(select);
    var selected = Array.prototype.filter.call(select.options, function (option) { return option.selected; });
    var text = selected.map(function (option) { return option.textContent; }).join("، ") || "انتخاب کنید";
    setText(record.text, text);
    if (record.trigger.disabled !== select.disabled) record.trigger.disabled = select.disabled;
    if (record.wrapper.hidden !== select.hidden) record.wrapper.hidden = select.hidden;
    attribute(record.trigger, "aria-label", labelFor(select) + ": " + text);
    attribute(record.trigger, "aria-invalid", select.getAttribute("aria-invalid") || "false");
    attribute(record.trigger, "aria-required", select.required ? "true" : "false");
    var describedBy = select.getAttribute("aria-describedby");
    if (describedBy) attribute(record.trigger, "aria-describedby", describedBy);
    else record.trigger.removeAttribute("aria-describedby");
    if (active && active.record === record) {
      if (select.disabled || !isShown(record.wrapper)) { closePicker(); return; }
      active.renderOptions();
    }
  }
  function openSelect(record) {
    if (record.control.disabled || !isShown(record.wrapper)) return;
    syncSelect(record);
    var view = makeDialog(record, labelFor(record.control)), searchWrap = element("div", "sg-picker-search"), search = element("input", "sg-picker-search__input"), list = element("div", "sg-picker-options");
    search.type = "search"; search.placeholder = "جست‌وجو در گزینه‌ها…"; search.autocomplete = "off";
    search.setAttribute("aria-label", "جست‌وجو در " + labelFor(record.control)); search.setAttribute("data-sg-picker-search", "");
    list.id = "sg-picker-options-" + serial;
    list.setAttribute("role", "listbox"); list.setAttribute("aria-label", labelFor(record.control));
    search.setAttribute("aria-controls", list.id);
    if (record.control.multiple) list.setAttribute("aria-multiselectable", "true");
    var searchIcon = element("span", "sg-picker-search__icon");
    searchIcon.setAttribute("aria-hidden", "true");
    searchIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="none"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg>';
    searchWrap.append(searchIcon, search); view.dialog.append(searchWrap, list);
    view.renderOptions = function () {
      var query = normalize(search.value), options = Array.prototype.slice.call(record.control.options), focusValue = document.activeElement && document.activeElement.getAttribute("data-sg-option-index");
      var signature = query + "|" + options.map(function (option) { return [option.value, option.textContent, option.disabled, option.selected, option.hidden, option.parentElement.disabled].join(":"); }).join("|");
      if (view.signature === signature) return;
      view.signature = signature;
      list.replaceChildren();
      var shown = 0, previousGroup = null;
      options.forEach(function (option, index) {
        if (option.hidden || (query && normalize(option.textContent).indexOf(query) === -1)) return;
        var group = option.parentElement.tagName === "OPTGROUP" ? option.parentElement : null;
        if (group && group !== previousGroup) list.append(element("div", "sg-picker-options__group", group.label));
        previousGroup = group;
        var choice = button("sg-picker-option"), text = element("span", "sg-picker-option__text", option.textContent), mark = element("span", "sg-picker-option__check", "✓");
        choice.setAttribute("role", "option"); choice.setAttribute("aria-selected", option.selected ? "true" : "false"); choice.setAttribute("data-sg-option-index", String(index)); choice.tabIndex = -1;
        choice.disabled = option.disabled || !!(group && group.disabled); mark.setAttribute("aria-hidden", "true");
        choice.append(text, mark);
        choice.addEventListener("click", function () {
          if (record.control.disabled || choice.disabled || !record.control.isConnected) return;
          if (record.control.multiple) { option.selected = !option.selected; events(record.control); syncSelect(record); }
          else {
            var changed = record.control.selectedIndex !== index;
            record.control.selectedIndex = index;
            closePicker(); syncSelect(record);
            if (changed) events(record.control);
          }
        });
        choice.addEventListener("focus", function () { list.querySelectorAll("button").forEach(function (node) { node.tabIndex = node === choice ? 0 : -1; }); });
        list.append(choice); shown += 1;
      });
      var tabChoice = list.querySelector('button[aria-selected="true"]:not(:disabled)') || list.querySelector("button:not(:disabled)");
      if (tabChoice) tabChoice.tabIndex = 0;
      if (!shown) { var empty = element("p", "sg-picker-options__empty", "گزینه‌ای با این عبارت پیدا نشد."); empty.setAttribute("role", "status"); list.append(empty); }
      if (focusValue !== null) {
        var replacement = list.querySelector('[data-sg-option-index="' + focusValue + '"]');
        (replacement && !replacement.disabled ? replacement : search).focus({ preventScroll: true });
      }
    };
    search.addEventListener("input", view.renderOptions);
    view.dialog.addEventListener("keydown", function (event) {
      if (["ArrowDown", "ArrowUp", "Home", "End"].indexOf(event.key) === -1) return;
      if (event.target === search && event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
      var choices = Array.prototype.slice.call(list.querySelectorAll("button:not(:disabled)"));
      if (!choices.length) return;
      var current = choices.indexOf(document.activeElement), index;
      if (event.key === "Home") index = 0;
      else if (event.key === "End") index = choices.length - 1;
      else index = current < 0 ? (event.key === "ArrowUp" ? choices.length - 1 : 0) : (current + (event.key === "ArrowUp" ? -1 : 1) + choices.length) % choices.length;
      event.preventDefault(); choices[index].focus();
    });
    if (record.control.multiple) { var done = button("sg-picker-primary sg-picker-done", "تأیید انتخاب‌ها"); done.addEventListener("click", closePicker); view.dialog.append(done); }
    view.renderOptions(); search.focus({ preventScroll: true });
  }

  function enhanceSelect(select) {
    if (records.has(select) || select.matches(".sg-payment-native-select, [data-sg-picker-skip]") || select.closest("[data-sg-payment-picker], .sg-product-search")) return;
    var wrapper = element("div", "sg-picker-control"), trigger = button("sg-picker-trigger"), text = element("span", "sg-picker-trigger__text");
    trigger.append(text); var icon = element("span", "sg-picker-trigger__icon"); icon.innerHTML = arrow; trigger.append(icon);
    trigger.setAttribute("aria-haspopup", "dialog"); trigger.setAttribute("aria-expanded", "false");
    select.parentNode.insertBefore(wrapper, select); wrapper.append(select, trigger);
    select.classList.add("sg-picker-native"); select.tabIndex = -1;
    // Its labelled button is now the accessible presentation. Keep the native
    // select for submission/validation without exposing a duplicate AX control.
    select.setAttribute("aria-hidden", "true");
    var record = { control: select, wrapper: wrapper, trigger: trigger, text: text, type: "select" };
    records.set(select, record);
    trigger.addEventListener("click", function () { openSelect(record); });
    trigger.addEventListener("keydown", function (event) { if (event.key === "ArrowDown" || event.key === "ArrowUp") { event.preventDefault(); openSelect(record); } });
    select.addEventListener("focus", function () { trigger.focus(); });
    select.addEventListener("change", function () { syncSelect(record); });
    select.addEventListener("invalid", function () { trigger.setAttribute("aria-invalid", "true"); trigger.focus(); });
    syncSelect(record);
  }
  function shiftMonth(date, amount) {
    var total = date.year * 12 + date.month - 1 + amount, result = { year: div(total, 12), month: total % 12 + 1, day: date.day };
    if (result.year < 1 || result.year >= 3178) return date;
    result.day = Math.min(date.day, monthLength(result.year, result.month));
    return result;
  }
  function dateByDays(date, amount) { var next = new Date(dateTimestamp(date) + amount * dayMs); return fromGregorian(next.getUTCFullYear(), next.getUTCMonth() + 1, next.getUTCDate()); }
  function openCalendar(record) {
    if (record.control.disabled || record.control.readOnly || !isShown(record.wrapper)) return;
    var selected = parseDate(record.control.value), current = selected || today();
    var view = makeDialog(record, "انتخاب " + labelFor(record.control)), calendar = element("div", "sg-calendar"), nav = element("div", "sg-calendar__nav");
    var previous = button("sg-calendar__nav-button", "‹"), title = button("sg-calendar__heading"), next = button("sg-calendar__nav-button", "›"), content = element("div", "sg-calendar__content"), footer = element("div", "sg-calendar__footer");
    previous.setAttribute("aria-label", "ماه قبل"); next.setAttribute("aria-label", "ماه بعد"); title.setAttribute("aria-label", "انتخاب ماه و سال");
    nav.append(previous, title, next); calendar.append(nav, content, footer); view.dialog.append(calendar);
    var todayButton = button("sg-calendar__today", "امروز"), clearButton = button("sg-calendar__clear", "پاک‌کردن تاریخ");
    footer.append(todayButton, clearButton);
    var monthView = false;
    function commit(date) {
      if (!validDate(date) || record.control.disabled || record.control.readOnly) return;
      record.control.value = formatDate(date); record.control.setCustomValidity(""); record.control.removeAttribute("aria-invalid");
      closePicker(); events(record.control);
    }
    function draw(focusDay) {
      setText(title, months[current.month - 1] + " " + fa(current.year));
      previous.disabled = current.year <= 1 && current.month === 1; next.disabled = current.year >= 3177 && current.month === 12;
      content.replaceChildren();
      if (monthView) {
        var yearRow = element("div", "sg-calendar__year"), yearLabel = element("label", "", "سال"), yearInput = element("input", "sg-calendar__year-input"), yearSet = button("sg-calendar__year-set", "نمایش");
        yearInput.type = "text"; yearInput.inputMode = "numeric"; yearInput.value = fa(current.year); yearInput.id = "sg-calendar-year-" + serial; yearLabel.htmlFor = yearInput.id;
        function setYear() { var value = Number(en(yearInput.value)); if (Number.isInteger(value) && value >= 1 && value <= 3177) { current.year = value; current.day = Math.min(current.day, monthLength(value, current.month)); draw(); } else { yearInput.setAttribute("aria-invalid", "true"); yearInput.focus(); } }
        yearSet.addEventListener("click", setYear); yearInput.addEventListener("keydown", function (event) { if (event.key === "Enter") { event.preventDefault(); setYear(); } });
        yearRow.append(yearLabel, yearInput, yearSet); content.append(yearRow);
        var monthGrid = element("div", "sg-calendar__months");
        months.forEach(function (name, index) { var month = button("sg-calendar__month", name); month.setAttribute("aria-pressed", current.month === index + 1 ? "true" : "false"); month.addEventListener("click", function () { current.month = index + 1; current.day = Math.min(current.day, monthLength(current.year, current.month)); monthView = false; draw(true); }); monthGrid.append(month); });
        content.append(monthGrid); return;
      }
      var grid = element("div", "sg-calendar__grid"); grid.setAttribute("role", "grid"); grid.setAttribute("aria-label", months[current.month - 1] + " " + fa(current.year));
      var headerRow = element("div", "sg-calendar__week"); headerRow.setAttribute("role", "row");
      weekdays.forEach(function (name) { var day = element("span", "sg-calendar__weekday", name); day.setAttribute("role", "columnheader"); headerRow.append(day); }); grid.append(headerRow);
      var start = (new Date(dateTimestamp({ year: current.year, month: current.month, day: 1 })).getUTCDay() + 1) % 7;
      var length = monthLength(current.year, current.month), row;
      for (var cell = 0; cell < Math.ceil((start + length) / 7) * 7; cell += 1) {
        if (cell % 7 === 0) { row = element("div", "sg-calendar__week"); row.setAttribute("role", "row"); grid.append(row); }
        var dayNumber = cell - start + 1;
        if (dayNumber < 1 || dayNumber > length) { var blank = element("span", "sg-calendar__blank"); blank.setAttribute("role", "gridcell"); row.append(blank); continue; }
        var date = { year: current.year, month: current.month, day: dayNumber }, dayButton = button("sg-calendar__day", fa(dayNumber));
        dayButton.setAttribute("role", "gridcell"); dayButton.setAttribute("data-sg-calendar-day", String(dayNumber)); dayButton.setAttribute("aria-label", fa(dayNumber) + " " + months[current.month - 1] + " " + fa(current.year));
        dayButton.tabIndex = dayNumber === current.day ? 0 : -1;
        dayButton.setAttribute("aria-selected", selected && dateTimestamp(date) === dateTimestamp(selected) ? "true" : "false");
        if (dateTimestamp(date) === dateTimestamp(today())) dayButton.setAttribute("aria-current", "date");
        (function (choice, value) { choice.addEventListener("click", function () { commit(value); }); }(dayButton, date));
        dayButton.addEventListener("focus", function () { current.day = Number(this.getAttribute("data-sg-calendar-day")); grid.querySelectorAll("button").forEach(function (day) { day.tabIndex = day === document.activeElement ? 0 : -1; }); });
        row.append(dayButton);
      }
      grid.addEventListener("keydown", function (event) {
        if (!event.target.hasAttribute("data-sg-calendar-day")) return;
        var date = { year: current.year, month: current.month, day: Number(event.target.getAttribute("data-sg-calendar-day")) }, key = event.key, shifted = null;
        if (key === "ArrowRight") shifted = dateByDays(date, -1);
        if (key === "ArrowLeft") shifted = dateByDays(date, 1);
        if (key === "ArrowUp") shifted = dateByDays(date, -7);
        if (key === "ArrowDown") shifted = dateByDays(date, 7);
        if (key === "Home") shifted = dateByDays(date, -((new Date(dateTimestamp(date)).getUTCDay() + 1) % 7));
        if (key === "End") shifted = dateByDays(date, 6 - ((new Date(dateTimestamp(date)).getUTCDay() + 1) % 7));
        if (key === "PageUp") shifted = shiftMonth(date, event.shiftKey ? -12 : -1);
        if (key === "PageDown") shifted = shiftMonth(date, event.shiftKey ? 12 : 1);
        if (!shifted || !validDate(shifted)) return;
        event.preventDefault(); current = shifted; draw(true);
      });
      content.append(grid);
      if (focusDay) content.querySelector('[data-sg-calendar-day="' + current.day + '"]').focus({ preventScroll: true });
    }
    previous.addEventListener("click", function () { current = shiftMonth(current, -1); monthView = false; draw(); });
    next.addEventListener("click", function () { current = shiftMonth(current, 1); monthView = false; draw(); });
    title.addEventListener("click", function () { monthView = !monthView; draw(); });
    todayButton.addEventListener("click", function () { commit(today()); });
    clearButton.addEventListener("click", function () { record.control.value = ""; record.control.setCustomValidity(""); record.control.removeAttribute("aria-invalid"); closePicker(); events(record.control); });
    draw(); content.querySelector('[data-sg-calendar-day="' + current.day + '"]').focus({ preventScroll: true });
  }
  function enhanceDate(input) {
    if (records.has(input)) return;
    var wrapper = element("div", "sg-date-control"), trigger = button("sg-date-trigger"); trigger.innerHTML = calendarIcon;
    trigger.setAttribute("aria-label", "انتخاب " + labelFor(input) + " از تقویم"); trigger.setAttribute("aria-haspopup", "dialog"); trigger.setAttribute("aria-expanded", "false");
    input.parentNode.insertBefore(wrapper, input); wrapper.append(input, trigger);
    var record = { control: input, trigger: trigger, wrapper: wrapper, type: "date" }; records.set(input, record);
    trigger.addEventListener("click", function () { openCalendar(record); }); input.addEventListener("click", function () { openCalendar(record); });
    input.addEventListener("keydown", function (event) { if (event.key === "ArrowDown") { event.preventDefault(); openCalendar(record); } });
    input.addEventListener("input", function () { input.setCustomValidity(""); input.removeAttribute("aria-invalid"); });
    input.addEventListener("change", function () { var valid = input.value.trim() === "" || !!parseDate(input.value); input.setCustomValidity(valid ? "" : "تاریخ معتبر به صورت سال/ماه/روز وارد کنید."); if (!valid) input.setAttribute("aria-invalid", "true"); });
  }
  function refresh() {
    document.querySelectorAll(scopeSelector).forEach(function (root) {
      root.querySelectorAll("select").forEach(enhanceSelect);
      root.querySelectorAll("[data-sgp-from], [data-sgp-to], [data-sg-jalali-date]").forEach(enhanceDate);
      if (!observedRoots.has(root)) {
        observedRoots.add(root);
        new window.MutationObserver(schedule).observe(root, { childList: true, subtree: true, attributes: true, characterData: true, attributeFilter: ["disabled", "readonly", "selected", "hidden", "aria-invalid", "aria-describedby", "required", "style", "class", "label", "value"] });
      }
    });
    records.forEach(function (record, control) {
      if (!control.isConnected || !control.closest(scopeSelector)) { if (active && active.record === record) closePicker(); records.delete(control); return; }
      if (record.type === "select") syncSelect(record);
      else {
        var disabled = control.disabled || control.readOnly;
        if (record.trigger.disabled !== disabled) record.trigger.disabled = disabled;
        if (active && active.record === record && (disabled || !isShown(record.wrapper))) closePicker();
      }
    });
  }
  function init() {
    if (initialized) return;
    initialized = true; refresh();
    document.addEventListener("reset", function (event) { if (event.target.closest(scopeSelector)) window.setTimeout(refresh, 0); }, true);
    new window.MutationObserver(function (changes) {
      if (changes.some(function (change) { return Array.prototype.some.call(change.addedNodes, function (node) { return node.nodeType === 1 && (node.matches(scopeSelector) || node.querySelector(scopeSelector)); }); })) schedule();
    }).observe(document.body, { childList: true, subtree: true });
    // HTMLSelectElement value/selectedIndex setters and jQuery .val() do not
    // dispatch events or DOM mutations. A small scoped snapshot preserves those
    // native contracts while keeping their visible counterparts in sync.
    window.setInterval(function () {
      if (document.visibilityState === "hidden") return;
      records.forEach(function (record) {
        if (record.type === "select" && record.control.isConnected && record.propertyState !== propertyState(record.control)) syncSelect(record);
      });
    }, 250);
  }
  window.SalamGatePickers = { refresh: refresh, close: closePicker, calendar: { parse: parseDate, monthLength: monthLength, fromGregorian: fromGregorian, timestamp: dateTimestamp } };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
}(window, document));
