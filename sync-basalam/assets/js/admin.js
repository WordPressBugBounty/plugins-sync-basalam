document.addEventListener("DOMContentLoaded", function () {
  const modalConfig = [
    {
      key: "add",
      modalId: "basalamAddProductsModal",
      openBtnId: "basalamOpenAddProductsModal",
      formId: "basalamAddProductsForm",
      action: "create_products_to_basalam",
    },
    {
      key: "update",
      modalId: "BasalamUpdateProductsModal",
      openBtnId: "BasalamOpenUpdateProductsModal",
      formId: "BasalamUpdateProductsForm",
      action: "update_products_in_basalam",
    },
    {
      key: "connect",
      modalId: "BasalamConnectProductsModal",
      openBtnId: "BasalamOpenConnectProductsModal",
      formId: "BasalamConnectProductsForm",
      action: "connect_products_with_basalam",
    },
  ];

  const closeModal = () => {
    document.querySelectorAll(".basalam-modal").forEach((modal) => {
      modal.style.display = "none";
    });
    document.body.style.overflow = "auto";
  };

  modalConfig.forEach(({ modalId, openBtnId, formId, action }) => {
    const modal = document.getElementById(modalId);
    const openBtn = document.getElementById(openBtnId);
    const form = document.getElementById(formId);

    if (openBtn && modal) {
      openBtn.addEventListener("click", () => {
        modal.style.display = "block";
        document.body.style.overflow = "hidden";
      });
    }

    if (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();

        if (action === "create_products_to_basalam") {
          const confirmModal = document.getElementById("BasalamConfirmModal");
          confirmModal.style.display = "block";
          document.body.style.overflow = "hidden";

          const confirmButton = document.getElementById("confirmSubmitBtn");

          const newConfirmBtn = confirmButton.cloneNode(true);
          confirmButton.parentNode.replaceChild(newConfirmBtn, confirmButton);

          newConfirmBtn.addEventListener("click", function () {
            confirmModal.style.display = "none";

            submitToServer(form, action);
          });
        } else {
          submitToServer(form, action);
        }
      });
    }
  });

  function submitToServer(form, action) {
    const formData = new FormData(form);
    formData.append("action", action);

    const submitButton = form.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    submitButton.innerHTML =
      '<span class="dashicons dashicons-update"></span> در حال ارسال...';

    fetch(ajaxurl, {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        closeModal();
        if (data.success) {
          alert(data.data?.message || "عملیات با موفقیت آغاز شد.");
        } else {
          alert(data.data?.message || "خطایی رخ داده است.");
        }
        location.reload();
      })
      .catch((error) => {
        console.error("Error:", error);
        alert("خطایی در ارسال رخ داده است.");
      })
      .finally(() => {
        submitButton.disabled = false;
        submitButton.innerHTML =
          '<span class="dashicons dashicons-plus-alt"></span> انجام شد';
      });
  }

  document.querySelectorAll(".basalam-modal-close").forEach((btn) => {
    btn.addEventListener("click", closeModal);
  });

  window.addEventListener("click", (event) => {
    if (event.target.classList.contains("basalam-modal")) {
      closeModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeModal();
    }
  });

  // Custom field toggle
  const select = document.getElementById("basalam-sync-type");
  if (select) {
    const toggleFields = (val) => {
      document.getElementById("Basalam-custom-fields").style.display =
        val === "custom" ? "block" : "none";
    };
    toggleFields(select.value);
    select.addEventListener("change", (e) => toggleFields(e.target.value));
  }
});
