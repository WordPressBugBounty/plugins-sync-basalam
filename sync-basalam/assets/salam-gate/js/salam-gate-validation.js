(function (window, document, $) {
  "use strict";

  // Only explicitly annotated SalamGate fields participate. Limits remain owned
  // by the server/templates; this module never invents a minimum or alters input.
  var selector = "input[data-sg-validate], textarea[data-sg-validate], select[data-sg-validate]";
  var states = new WeakMap(), nextId = 0;

  function raw(value) {
    if (window.SalamGateMoney && typeof window.SalamGateMoney.raw === "function") {
      return window.SalamGateMoney.raw(value).trim();
    }
    return String(value == null ? "" : value)
      .replace(/[۰-۹]/g, function (digit) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(digit); })
      .replace(/[٠-٩]/g, function (digit) { return "٠١٢٣٤٥٦٧٨٩".indexOf(digit); })
      .replace(/[,٬]/g, "").replace(/٫/g, ".").trim();
  }

  function stateFor(field) {
    if (!states.has(field)) states.set(field, { dirty: false, message: null, labels: [], previousInvalid: null, marked: false });
    return states.get(field);
  }

  function fields(scope) {
    var element = scope && scope.jquery ? scope[0] : scope;
    if (typeof element === "string") element = document.querySelector(element);
    element = element || document;
    var result = element.matches && element.matches(selector) ? [element] : [];
    if (element.querySelectorAll) result = result.concat(Array.prototype.slice.call(element.querySelectorAll(selector)));
    return result;
  }

  function active(field) {
    if (field.disabled || field.type === "hidden" || field.closest("fieldset[disabled]")) return false;
    for (var node = field; node && node.nodeType === 1; node = node.parentElement) {
      if (node.hidden || node.getAttribute("aria-hidden") === "true") return false;
      var style = window.getComputedStyle(node);
      if (style.display === "none" || style.visibility === "hidden" || style.visibility === "collapse") return false;
    }
    return true;
  }

  function limit(field, name) {
    var value = field.getAttribute("data-sg-" + name);
    if (value == null) value = field.getAttribute(name);
    if (value == null || raw(value) === "") return null;
    var parsed = Number(raw(value));
    return Number.isFinite(parsed) ? parsed : null;
  }

  function numberLabel(value) { return value.toLocaleString("fa-IR"); }

  // DOM writes, even when the value stays the same, notify other modules'
  // observers. Keep rendering idempotent so dialog/field observers can settle.
  function setAttribute(element, name, value) {
    if (element.getAttribute(name) === value) return;
    if (value === null) element.removeAttribute(name);
    else element.setAttribute(name, value);
  }

  function setClass(element, name, enabled) {
    if (element.classList.contains(name) !== enabled) element.classList.toggle(name, enabled);
  }

  function messageFor(field) {
    var value = String(field.value == null ? "" : field.value).trim();
    var label = field.getAttribute("data-sg-label") || "این فیلد";
    var required = field.required || field.getAttribute("data-sg-required") === "1";
    // A browser may expose malformed type=number input as value="" while
    // retaining the user's partial text in its editor. This is not optional blank.
    if (field.validity && field.validity.badInput) return "برای " + label + " یک عدد معتبر وارد کنید.";
    if (value === "") return required ? "وارد کردن " + label + " الزامی است." : "";

    var minimum = limit(field, "min"), maximum = limit(field, "max");
    var integer = field.getAttribute("data-sg-integer") === "1" || field.getAttribute("step") === "1";
    var numeric = field.type === "number" || minimum != null || maximum != null || integer;
    if (numeric) {
      var normalized = raw(value);
      if (!/^-?(?:\d+(?:\.\d+)?|\.\d+)$/.test(normalized) || !Number.isFinite(Number(normalized))) {
        return "برای " + label + " یک عدد معتبر وارد کنید.";
      }
      var number = Number(normalized);
      if (integer && !Number.isSafeInteger(number)) return label + " باید عدد صحیح باشد.";
      var decimalPlaces = field.getAttribute("data-sg-decimal-places");
      if (decimalPlaces != null && /^\d+$/.test(decimalPlaces)
        && (normalized.split(".")[1] || "").length > Number(decimalPlaces)) {
        return label + " باید حداکثر " + numberLabel(Number(decimalPlaces)) + " رقم اعشار داشته باشد.";
      }
      var unit = field.getAttribute("data-sg-unit");
      var suffix = unit ? " " + unit : "";
      if (minimum != null && number < minimum) return field.getAttribute("data-sg-min-message") || label + " باید حداقل " + numberLabel(minimum) + suffix + " باشد.";
      if (maximum != null && number > maximum) return label + " باید حداکثر " + numberLabel(maximum) + suffix + " باشد.";
      // step is a spinner increment, not a price denomination. In particular,
      // monetary step=1000 must not reject an otherwise valid 1250-toman value.
    }

    var length = Array.from(value).length;
    var exact = limit(field, "length");
    var minLength = limit(field, "minlength"), maxLength = limit(field, "maxlength");
    if (exact != null && length !== exact) return label + " باید " + numberLabel(exact) + " کاراکتر باشد.";
    if (minLength != null && length < minLength) return label + " باید حداقل " + numberLabel(minLength) + " کاراکتر باشد.";
    if (maxLength != null && length > maxLength) return label + " باید حداکثر " + numberLabel(maxLength) + " کاراکتر باشد.";
    return "";
  }

  function clear(field, state) {
    setClass(field, "sg-validation-invalid", false);
    state.labels.forEach(function (label) { setClass(label, "sg-validation-label-invalid", false); });
    state.labels = [];
    if (state.message) {
      var describedBy = (field.getAttribute("aria-describedby") || "").split(/\s+/).filter(function (id) { return id && id !== state.message.id; });
      setAttribute(field, "aria-describedby", describedBy.length ? describedBy.join(" ") : null);
      state.message.remove();
      state.message = null;
    }
    if (state.marked && field.getAttribute("aria-invalid") === "true") {
      setAttribute(field, "aria-invalid", state.previousInvalid);
    }
    state.marked = false;
  }

  function show(field, state, message) {
    if (!state.marked) {
      state.previousInvalid = field.getAttribute("aria-invalid");
      state.marked = true;
    }
    setClass(field, "sg-validation-invalid", true);
    setAttribute(field, "aria-invalid", "true");
    state.labels = Array.prototype.slice.call(field.labels || []);
    state.labels.forEach(function (label) { setClass(label, "sg-validation-label-invalid", true); });
    if (!state.message || !state.message.isConnected) {
      var messageNode = document.createElement("span");
      do { messageNode.id = "sg-live-validation-" + (++nextId); } while (document.getElementById(messageNode.id));
      messageNode.className = "sg-validation-message";
      messageNode.setAttribute("data-sg-validation-message", "");
      messageNode.setAttribute("aria-live", "polite");
      messageNode.setAttribute("aria-atomic", "true");
      var anchor = field.closest("[data-sg-validation-control]") || field;
      anchor.insertAdjacentElement("afterend", messageNode);
      state.message = messageNode;
    }
    if (state.message.textContent !== message) state.message.textContent = message;
    setClass(state.message, "sg-validation-limit", message === field.getAttribute("data-sg-min-message"));
    var ids = (field.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
    if (ids.indexOf(state.message.id) === -1) ids.push(state.message.id);
    setAttribute(field, "aria-describedby", ids.join(" "));
  }

  function check(field, markDirty) {
    var state = stateFor(field);
    if (!active(field)) { clear(field, state); return true; }
    if (markDirty) state.dirty = true;
    if (!state.dirty) return true;
    var message = messageFor(field);
    if (message) show(field, state, message);
    else clear(field, state);
    return !message;
  }

  window.SalamGateValidation = {
    validate: function (scope) {
      var firstInvalid = null;
      fields(scope).forEach(function (field) { if (!check(field, true) && !firstInvalid) firstInvalid = field; });
      if (firstInvalid) {
        for (var parent = firstInvalid.parentElement; parent; parent = parent.parentElement) {
          if (parent.tagName === "DETAILS") parent.open = true;
        }
        firstInvalid.focus();
        if (typeof window.woosalamNotify === "function") window.woosalamNotify(messageFor(firstInvalid), "error");
      }
      return !firstInvalid;
    },
    refresh: function (scope) {
      fields(scope).forEach(function (field) { if (states.has(field)) check(field, false); });
    },
    reset: function (scope) {
      fields(scope).forEach(function (field) {
        if (!states.has(field)) return;
        var state = states.get(field);
        clear(field, state);
        state.dirty = false;
      });
    }
  };

  $(function () {
    $(document).on("input.sgLiveValidation change.sgLiveValidation", selector, function () { check(this, true); });
    // Conditional dimensions, proposed prices and modal stages change visibility
    // independently of typing. Clear their owned errors when they become inactive.
    new MutationObserver(function (changes) {
      var scopes = [];
      changes.forEach(function (change) {
        // Ignore no-op writes from other components and only inspect fields
        // inside the control/container whose visibility or disabled state changed.
        if (change.oldValue === change.target.getAttribute(change.attributeName)) return;
        if (scopes.indexOf(change.target) === -1) scopes.push(change.target);
      });
      scopes.filter(function (scope) {
        return !scopes.some(function (parent) { return parent !== scope && parent.contains(scope); });
      }).forEach(function (scope) { window.SalamGateValidation.refresh(scope); });
    }).observe(document.body, {
      attributes: true, attributeOldValue: true, subtree: true, attributeFilter: ["disabled", "hidden", "style", "aria-hidden"]
    });
  });
})(window, document, jQuery);
