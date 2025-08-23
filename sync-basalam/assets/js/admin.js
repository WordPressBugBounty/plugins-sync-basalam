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

  // Info Modal functionality
  const infoTriggers = document.querySelectorAll('.basalam-info-trigger');
  
  // Move all modals to body on page load
  const moveModalsToBody = () => {
    const allModals = document.querySelectorAll('.basalam-info-modal');
    allModals.forEach(modal => {
      if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
      }
    });
  };
  
  // Call it on page load
  moveModalsToBody();
  
  // Function to close all modals
  const closeAllModals = () => {
    const allModals = document.querySelectorAll('.basalam-info-modal');
    allModals.forEach(modal => {
      modal.style.display = 'none';
      modal.classList.remove('show');
    });
    document.body.style.overflow = 'auto';
  };
  
  // Function to open modal
  const openModal = (modalId) => {
    const modal = document.getElementById(modalId);
    if (modal) {
      closeAllModals(); // Close any open modals first
      
      // Move modal to body if it's not already there
      if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
      }
      
      modal.style.display = 'block';
      modal.classList.add('show');
      document.body.style.overflow = 'hidden';
      
      // Focus on modal for accessibility
      modal.focus();
    }
  };
  
  // Add click event to info triggers
  infoTriggers.forEach(trigger => {
    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      
      const modalId = trigger.getAttribute('data-modal-id');
      if (modalId) {
        openModal(modalId);
      }
    });
  });
  
  // Add event listeners to close buttons and overlays
  document.addEventListener('click', (e) => {
    // Close button functionality
    if (e.target.classList.contains('basalam-info-modal-close')) {
      e.preventDefault();
      closeAllModals();
    }
    
    // Overlay click functionality
    if (e.target.classList.contains('basalam-info-modal-overlay')) {
      closeAllModals();
    }
    
    // Click outside modal content
    if (e.target.classList.contains('basalam-info-modal')) {
      closeAllModals();
    }
  });
  
  // Close modal with Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeAllModals();
    }
  });
  
  // Prevent closing when clicking inside modal content
  document.addEventListener('click', (e) => {
    if (e.target.closest('.basalam-info-modal-content')) {
      e.stopPropagation();
    }
  });
});
