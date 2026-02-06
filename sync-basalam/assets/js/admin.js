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

        if (modalId === "BasalamUpdateProductsModal") {
          const selectionDiv = document.getElementById("update-type-selection");
          const quickConfirm = document.getElementById("quick-update-confirm");
          const fullConfirm = document.getElementById("full-update-confirm");

          if (selectionDiv) selectionDiv.style.display = "block";
          if (quickConfirm) quickConfirm.style.display = "none";
          if (fullConfirm) fullConfirm.style.display = "none";
        }
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

  setTimeout(() => {
    const quickUpdateBtn = document.getElementById("quick-update-btn");
    const fullUpdateBtn = document.getElementById("full-update-btn");

    if (quickUpdateBtn) {
      quickUpdateBtn.addEventListener("click", (e) => {
        e.preventDefault();

        const formData = new FormData();
        formData.append("update_type", "quick");
        formData.append("action", "update_products_in_basalam");
        formData.append(
          "_wpnonce",
          document.querySelector(
            '#BasalamUpdateProductsModal input[name="_wpnonce"]'
          )?.value || ""
        );

        submitUpdateRequest(formData, "quick");
      });
    }

    if (fullUpdateBtn) {
      fullUpdateBtn.addEventListener("click", (e) => {
        e.preventDefault();

        const formData = new FormData();
        formData.append("update_type", "full");
        formData.append("action", "update_products_in_basalam");
        formData.append(
          "_wpnonce",
          document.querySelector(
            '#BasalamUpdateProductsModal input[name="_wpnonce"]'
          )?.value || ""
        );

        submitUpdateRequest(formData, "full");
      });
    }
  }, 100);

  function submitUpdateRequest(formData, updateType) {
    const quickBtn = document.getElementById("quick-update-btn");
    const fullBtn = document.getElementById("full-update-btn");

    if (quickBtn) quickBtn.disabled = true;
    if (fullBtn) fullBtn.disabled = true;

    const clickedBtn = updateType === "quick" ? quickBtn : fullBtn;
    if (clickedBtn) {
      const originalText = clickedBtn.innerHTML;
      clickedBtn.innerHTML =
        '<span class="basalam-loading-text">در حال ارسال...</span>';

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

          if (quickBtn) quickBtn.disabled = false;
          if (fullBtn) fullBtn.disabled = false;
          if (clickedBtn) clickedBtn.innerHTML = originalText;
        });
    }
  }

  function submitToServer(form, action, updateType = null) {
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

  const select = document.getElementById("basalam-sync-type");
  if (select) {
    const toggleFields = (val) => {
      document.getElementById("Basalam-custom-fields").style.display =
        val === "custom" ? "block" : "none";
    };
    toggleFields(select.value);
    select.addEventListener("change", (e) => toggleFields(e.target.value));
  }

  const infoTriggers = document.querySelectorAll(".basalam-info-trigger");

  const moveModalsToBody = () => {
    const allModals = document.querySelectorAll(".basalam-info-modal");
    allModals.forEach((modal) => {
      if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
      }
    });
  };

  moveModalsToBody();

  const closeAllModals = () => {
    const allModals = document.querySelectorAll(".basalam-info-modal");
    allModals.forEach((modal) => {
      modal.style.display = "none";
      modal.classList.remove("show");
    });
    document.body.style.overflow = "auto";
  };

  const openModal = (modalId) => {
    const modal = document.getElementById(modalId);
    if (modal) {
      if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
      }

      modal.style.display = "block";
      modal.classList.add("show");
      document.body.style.overflow = "hidden";

      modal.focus();
    }
  };

  infoTriggers.forEach((trigger) => {
    trigger.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();

      const modalId = trigger.getAttribute("data-modal-id");
      if (modalId) {
        openModal(modalId);
      }
    });
  });

  document.addEventListener("click", (e) => {
    if (e.target.classList.contains("basalam-info-modal-close")) {
      e.preventDefault();
      closeAllModals();
    }

    if (e.target.classList.contains("basalam-info-modal-overlay")) {
      closeAllModals();
    }

    if (e.target.classList.contains("basalam-info-modal")) {
      closeAllModals();
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      closeAllModals();
    }
  });

  document.addEventListener("click", (e) => {
    if (e.target.closest(".basalam-info-modal-content")) {
      e.stopPropagation();
    }
  });

  const tasksAutoToggle = document.querySelector(".basalam-tasks-auto-toggle");
  const tasksManualContainer = document.querySelector(
    ".basalam-tasks-manual-container"
  );
  const tasksManualInput = document.querySelector(
    ".basalam-tasks-manual-input"
  );
  const tasksAutoHidden = document.querySelector(".basalam-tasks-auto-hidden");

  const toggleTasksManualInput = () => {
    if (tasksAutoToggle && tasksManualInput) {
      const isAuto = tasksAutoToggle.checked;

      if (isAuto) {
        tasksManualInput.disabled = true;
        if (tasksAutoHidden) tasksAutoHidden.disabled = true;
      } else {
        tasksManualInput.disabled = false;
        if (tasksAutoHidden) tasksAutoHidden.disabled = false;
      }
    }
  };

  if (tasksAutoToggle) {
    tasksAutoToggle.addEventListener("change", toggleTasksManualInput);
  }

  // Attribute suffix toggle handler
  const attributeSuffixToggle = document.querySelector(
    ".basalam-attribute-suffix-toggle"
  );
  const attributeSuffixPriority = document.querySelector(
    ".basalam-attribute-suffix-priority"
  );
  const attributeSuffixContainer = document.querySelector(
    ".basalam-attribute-suffix-container"
  );
  const attributeSuffixHidden = document.querySelector(
    ".basalam-attribute-suffix-hidden"
  );

  const toggleAttributeSuffixPriority = () => {
    if (attributeSuffixToggle) {
      const isEnabled = attributeSuffixToggle.checked;

      if (attributeSuffixPriority) {
        attributeSuffixPriority.disabled = !isEnabled;
      }

      if (attributeSuffixContainer) {
        attributeSuffixContainer.style.display = isEnabled ? "block" : "none";
      }

      if (attributeSuffixHidden) {
        attributeSuffixHidden.disabled = isEnabled;
      }
    }
  };

  if (attributeSuffixToggle) {
    attributeSuffixToggle.addEventListener(
      "change",
      toggleAttributeSuffixPriority
    );
    // Initialize on page load
    toggleAttributeSuffixPriority();
  }

  var modal = document.getElementById("sync_basalam_support_modal");
  var btn = document.getElementById("sync_basalam_support_btn");
  var cancelBtn = document.getElementById("sync_basalam_cancel_btn");

  if (btn) {
    btn.addEventListener("click", function () {
      if (modal) {
        modal.style.display = "flex";
      }
    });
  }

  if (cancelBtn) {
    cancelBtn.addEventListener("click", function () {
      if (modal) {
        modal.style.display = "none";
      }
    });
  }

  if (modal) {
    modal.addEventListener("click", function (e) {
      if (e.target === modal) {
        modal.style.display = "none";
      }
    });
  }
});
