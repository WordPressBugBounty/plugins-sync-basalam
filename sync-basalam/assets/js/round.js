document.addEventListener("DOMContentLoaded", function () {
  const COMMISSION_VALUE = "commission";
  // Range in which a value is read as a percentage; outside it the value is a fixed Toman amount
  const PERCENT_RANGE_MIN = -100;
  const PERCENT_RANGE_MAX = 100;
  // Maximum allowed increase and decrease percentage
  const MAX_PERCENT = 35;
  const MIN_PERCENT = -35;

  // Parses the raw input text into an integer, keeping a leading minus sign
  const parseValue = function (rawText) {
    const isNegative = /^\s*-/.test(rawText);
    const digits = rawText.replace(/[^\d]/g, "");

    if (digits === "") {
      return { raw: isNegative ? "-" : "", value: 0, empty: true };
    }

    const value = (isNegative ? -1 : 1) * parseInt(digits, 10);

    return { raw: digits, value: value, empty: false };
  };

  const formatValue = function (value) {
    return value.toLocaleString("en-US");
  };

  const isPercent = function (value) {
    return value >= PERCENT_RANGE_MIN && value <= PERCENT_RANGE_MAX;
  };

  const groups = document.querySelectorAll(
    ".basalam-form-group, .basalam-form-group-full"
  );

  groups.forEach(function (group) {
    const checkbox = group.querySelector(".toggle-percentage");
    if (!checkbox) {
      return;
    }

    const input =
      group.querySelector('[data-role="price-change-input"]') ||
      group.querySelector(
        'input.percentage-input[type="text"][name="sync_basalam_settings[price_change_value]"]'
      );
    const unit = group.querySelector(".percentage-unit");
    const hiddenInput =
      group.querySelector('[data-role="price-change-hidden"]') ||
      group.querySelector(
        'input[type="hidden"][name="sync_basalam_settings[price_change_value]"]'
      );

    if (!input || !unit || !hiddenInput) {
      return;
    }

    const normalizeInput = function () {
      const parsed = parseValue(input.value);

      if (parsed.empty) {
        input.value = parsed.raw;
        unit.textContent = "درصد";

        return "";
      }

      input.value = formatValue(parsed.value);
      unit.textContent = isPercent(parsed.value) ? "درصد" : "تومان";

      return String(parsed.value);
    };

    const updateHiddenValue = function (value) {
      if (hiddenInput.value === String(value)) {
        return;
      }

      hiddenInput.value = String(value);
      hiddenInput.dispatchEvent(new Event("input", { bubbles: true }));
      hiddenInput.dispatchEvent(new Event("change", { bubbles: true }));
    };

    const syncHiddenInput = function () {
      if (checkbox.checked) {
        updateHiddenValue(COMMISSION_VALUE);
        return;
      }

      updateHiddenValue(normalizeInput());
    };

    input.disabled = checkbox.checked;
    syncHiddenInput();

    input.addEventListener("input", function () {
      if (!checkbox.checked) {
        syncHiddenInput();
      }
    });

    checkbox.addEventListener("change", function () {
      input.disabled = checkbox.checked;

      if (checkbox.checked) {
        input.value = "";
        updateHiddenValue(COMMISSION_VALUE);
        unit.textContent = "درصد";
        return;
      }

      syncHiddenInput();
    });

    const form = group.closest("form");
    if (form && !form.dataset.priceChangeBound) {
      form.addEventListener("submit", function () {
        const toggles = form.querySelectorAll(".toggle-percentage");
        let clampedNotice = false;

        toggles.forEach(function (toggle) {
          const toggleGroup =
            toggle.closest(".basalam-form-group") ||
            toggle.closest(".basalam-form-group-full");

          if (!toggleGroup) {
            return;
          }

          const toggleInput =
            toggleGroup.querySelector('[data-role="price-change-input"]') ||
            toggleGroup.querySelector(
              'input.percentage-input[type="text"][name="sync_basalam_settings[price_change_value]"]'
            );
          const toggleHidden =
            toggleGroup.querySelector('[data-role="price-change-hidden"]') ||
            toggleGroup.querySelector(
              'input[type="hidden"][name="sync_basalam_settings[price_change_value]"]'
            );

          if (!toggleInput || !toggleHidden) {
            return;
          }

          if (toggle.checked) {
            toggleHidden.value = COMMISSION_VALUE;
            return;
          }

          const parsed = parseValue(toggleInput.value);

          if (parsed.empty) {
            toggleHidden.value = "";
            return;
          }

          let numberValue = parsed.value;

          if (isPercent(numberValue) && (numberValue > MAX_PERCENT || numberValue < MIN_PERCENT)) {
            clampedNotice = true;
            numberValue = numberValue > MAX_PERCENT ? MAX_PERCENT : MIN_PERCENT;
            toggleInput.value = formatValue(numberValue);
            const unitEl = toggleGroup.querySelector(".percentage-unit");
            if (unitEl) {
              unitEl.textContent = "درصد";
            }
          }

          toggleHidden.value = String(numberValue);
        });

        if (clampedNotice) {
          BasalamToast.info(
            "مقدار تغییر قیمت در حالت درصد نمی‌تواند بیشتر از ۳۵٪ افزایش یا ۳۵٪ کاهش باشد. مقدار وارد شده به سقف مجاز تغییر داده شد و ذخیره می‌شود."
          );
        }
      });

      form.dataset.priceChangeBound = "1";
    }
  });
});
