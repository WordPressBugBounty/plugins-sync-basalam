document.addEventListener("DOMContentLoaded", function () {
  const button = document.getElementById(
    "sync_basalam_refresh_vendor_status_btn"
  );

  if (!button) return;

  const showToast = (type, message) => {
    if (window.BasalamToast && typeof window.BasalamToast[type] === "function") {
      window.BasalamToast[type](message);
      return;
    }

    window.alert(message);
  };

  button.addEventListener("click", function () {
    if (button.disabled) return;

    const originalText = button.textContent;
    const formData = new FormData();
    formData.append("action", "sync_basalam_refresh_vendor_status");
    formData.append("_wpnonce", button.dataset.nonce || "");

    button.disabled = true;
    button.textContent = "در حال بررسی...";

    fetch(window.ajaxurl, {
      method: "POST",
      credentials: "same-origin",
      body: formData,
    })
      .then((response) => response.json())
      .then((response) => {
        const data = response.data || {};

        if (!response.success) {
          throw new Error(data.message || "بررسی وضعیت غرفه ناموفق بود.");
        }

        const isActive = data.state && data.state.mode === "active";
        showToast(
          isActive ? "success" : "warning",
          data.message ||
            (isActive
              ? "غرفه فعال شد."
              : "غرفه هنوز در باسلام غیرفعال است.")
        );

        window.setTimeout(function () {
          window.location.reload();
        }, 600);
      })
      .catch((error) => {
        showToast("error", error.message || "خطا در ارتباط با سرور.");
      })
      .finally(() => {
        button.disabled = false;
        button.textContent = originalText;
      });
  });
});
