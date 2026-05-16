document.addEventListener("DOMContentLoaded", function () {
  const groups = document.querySelectorAll(
    ".basalam-form-group, .basalam-form-group-full"
  );

  groups.forEach(function (group) {
    const checkbox = group.querySelector(".toggle-percentage");
    if (!checkbox) {
      return;
    }

    const input =
      group.querySelector('[data-role="increase-price-input"]') ||
      group.querySelector(
        'input.percentage-input[type="text"][name="sync_basalam_settings[increase_price_value]"]'
      );
    const unit = group.querySelector(".percentage-unit");
    const hiddenInput =
      group.querySelector('[data-role="increase-price-hidden"]') ||
      group.querySelector(
        'input[type="hidden"][name="sync_basalam_settings[increase_price_value]"]'
      );

    if (!input || !unit || !hiddenInput) {
      return;
    }

    const normalizeInput = function () {
      const rawValue = input.value.replace(/[^\d]/g, "");
      const numberValue = parseInt(rawValue, 10) || 0;

      input.value = rawValue === "" ? "" : numberValue.toLocaleString("en-US");
      unit.textContent = numberValue <= 100 ? "درصد" : "تومان";

      return rawValue === "" ? "" : String(numberValue);
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
        updateHiddenValue("-1");
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
        updateHiddenValue("-1");
        unit.textContent = "درصد";
        return;
      }

      syncHiddenInput();
    });

    const form = group.closest("form");
    if (form && !form.dataset.increasePriceBound) {
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
            toggleGroup.querySelector('[data-role="increase-price-input"]') ||
            toggleGroup.querySelector(
              'input.percentage-input[type="text"][name="sync_basalam_settings[increase_price_value]"]'
            );
          const toggleHidden =
            toggleGroup.querySelector('[data-role="increase-price-hidden"]') ||
            toggleGroup.querySelector(
              'input[type="hidden"][name="sync_basalam_settings[increase_price_value]"]'
            );

          if (!toggleInput || !toggleHidden) {
            return;
          }

          if (toggle.checked) {
            toggleHidden.value = "-1";
            return;
          }

          const rawValue = toggleInput.value.replace(/[^\d]/g, "");
          let numberValue = parseInt(rawValue, 10) || 0;

          if (numberValue > 35 && numberValue <= 100) {
            clampedNotice = true;
            numberValue = 35;
            toggleInput.value = numberValue.toLocaleString("en-US");
            const unitEl = toggleGroup.querySelector(".percentage-unit");
            if (unitEl) {
              unitEl.textContent = "درصد";
            }
          }

          toggleHidden.value = rawValue === "" ? "" : String(numberValue);
        });

        if (clampedNotice) {
          BasalamToast.info(
            "مقدار افزایش قیمت در حالت درصد نمی‌تواند بیشتر از ۳۵٪ باشد. مقدار وارد شده به ۳۵ درصد تغییر داده شد و ذخیره می‌شود."
          );
        }
      });

      form.dataset.increasePriceBound = "1";
    }
  });
});
