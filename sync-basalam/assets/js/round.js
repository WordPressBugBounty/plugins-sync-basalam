document.addEventListener("DOMContentLoaded", function () {
  const input = document.querySelector("#percentage-input");
  const unit = document.querySelector(".percentage-unit");
  const checkbox = document.querySelector("#toggle-percentage");
  const hiddenInput = document.querySelector("#final-value");

  if (input && unit) {
    input.addEventListener("input", function (e) {
      let value = this.value.replace(/[^\d]/g, "");
      let numValue = parseInt(value) || 0;
      this.value = numValue.toLocaleString("en-US");
      unit.textContent = numValue <= 100 ? "درصد" : "تومان";
      if (hiddenInput) {
        hiddenInput.value = numValue;
      }
    });

    if (input.closest("form")) {
      input.closest("form").addEventListener("submit", function () {
        if (checkbox && checkbox.checked) {
          if (hiddenInput) hiddenInput.value = "-1";
        } else {
          if (hiddenInput)
            hiddenInput.value = input.value.replace(/[^\d]/g, "");
        }
      });
    }
  }

  if (checkbox && checkbox.checked) {
    if (input) input.disabled = true;
  }

  if (checkbox) {
    checkbox.addEventListener("change", function () {
      if (input) input.disabled = this.checked;
      if (hiddenInput) {
        if (this.checked) {
          hiddenInput.value = "-1";
          if (input) input.value = "";
        } else {
          hiddenInput.value = input ? input.value.replace(/[^\d]/g, "") : "";
        }
      }
    });
  }
});
