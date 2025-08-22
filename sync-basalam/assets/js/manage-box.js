document.addEventListener("DOMContentLoaded", function () {
  const buttons = document.querySelectorAll(".basalam-action-button[data-url]");

  buttons.forEach((button) => {
    button.addEventListener("click", function (event) {
      event.preventDefault();

      const dataAttributes = [...button.getAttributeNames()].filter((name) =>
        name.startsWith("data-")
      );

      const attributes = {};
      dataAttributes.forEach((attr) => {
        const cleanAttr = attr
          .replace("data-", "")
          .replace(/-/g, "_")
          .toLowerCase();
        attributes[cleanAttr] = button.getAttribute(attr);
      });

      const formData = new FormData();
      Object.keys(attributes).forEach((key) => {
        formData.append(key, attributes[key]);
      });

      const url = attributes["url"];

      if (confirm("آیا مطمئن هستید که می‌خواهید این عمل را انجام دهید؟")) {
        const originalText = this.innerText;
        this.innerText = "در حال پردازش...";
        this.disabled = true;

        fetch(url, {
          method: "POST",
          body: formData,
        })
          .then((response) => response.json())
          .then((data) => {
            if (data.success) {
              alert(data.data.message || "عملیات با موفقیت انجام شد.");
              location.reload();
            } else {
              alert(data.data?.message || "خطا در انجام عملیات.");
            }
          })
          .catch((error) => {
            alert("خطا در انجام عملیات: " + error);
          })
          .finally(() => {
            this.innerText = originalText;
            this.disabled = false;
          });
      }
    });
  });
});
