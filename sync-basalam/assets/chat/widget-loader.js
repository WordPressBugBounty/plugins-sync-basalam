(function () {
  const MIN_WIDGET_WIDTH = 1024;
  const currentScript = document.currentScript;

  const config = {
    apiBaseUrl: {
      core: "https://gateway.hamsalam.ir/core",
      conversation: "https://gateway.hamsalam.ir/conversation",
      auth: "https://gateway.hamsalam.ir/auth",
      review: "https://gateway.hamsalam.ir/review",
      story: "https://gateway.hamsalam.ir/story",
      userActivity: "https://gateway.hamsalam.ir/user-activity",
      featureFlag: "https://gateway.hamsalam.ir/feature-flag",
      orderProcessing: "https://gateway.hamsalam.ir/order-processing",
      uploadio: "https://gateway.hamsalam.ir/uploadio",
      automation: "https://gateway.hamsalam.ir/automation",
      voucher: "https://gateway.hamsalam.ir/voucher",
      notification: "https://gateway.hamsalam.ir/notification",
      appsApi: "https://gateway.hamsalam.ir/apps-api",
      chat: "https://gateway.hamsalam.ir/chat",
      orderTest2: "https://gateway.hamsalam.ir/order-test2",
    },
    forcedDeviceType: "mobile",
    buttonText: "Chat",
    buttonIcon: null,
    buttonSize: "60px",
    buttonColor: "#ff5c35",
    buttonTextColor: "#fff",
    clickOutsideClose: true,
    widgetStyle: {
      left : "20px",
      right: "auto",
      height: "630px",
    },
    ...window.dalanWidgetConfig,
  };

  for (const attr of currentScript.attributes) {
    if (attr.name === "src") continue;
    config[attr.name] = attr.value;
  }

  window.dalanWidgetConfig = config;

  const scriptSrc = currentScript.src;
  const basePath = scriptSrc.substring(0, scriptSrc.lastIndexOf("/") + 1);

  if (!config.buttonIcon) {
    config.buttonIcon = basePath + "../icons/chat.svg";
  }

  let assetsLoaded = false;
  let scriptLoaded = false;
  let widgetButton = null;

  function isDesktopViewport() {
    return window.innerWidth >= MIN_WIDGET_WIDTH;
  }

  function getMountFunction() {
    return (
      window.mountChatWidget ||
      (window.ChatWidget && window.ChatWidget.mountChatWidget)
    );
  }

  function getUnmountFunction() {
    return (
      window.unmountChatWidget ||
      (window.ChatWidget && window.ChatWidget.unmountChatWidget)
    );
  }

  function removeWidget() {
    const isOpen = !!document.querySelector("#dalan-chat-root");
    const unmount = getUnmountFunction();

    if (isOpen && unmount) {
      unmount();
    }

    if (widgetButton) {
      widgetButton.remove();
      widgetButton = null;
    }
  }

  function createWidgetButton() {
    if (!isDesktopViewport() || widgetButton || !scriptLoaded) {
      return;
    }

    const button = document.createElement("button");
    button.id = "dalan-chat-button";

    if (config.buttonIcon) {
      const icon = document.createElement("img");
      icon.src = config.buttonIcon;
      icon.alt = "Chat";
      Object.assign(icon.style, {
        width: "30px",
        height: "30px",
        display: "block",
      });
      button.appendChild(icon);
    } else {
      button.innerText = config.buttonText;
    }

    Object.assign(button.style, {
      position: "fixed",
      bottom: config.buttonBottom || "20px",
      left: config.buttonRight || "20px",
      borderRadius: "50%",
      width: config.buttonSize,
      height: config.buttonSize,
      fontSize: "24px",
      background: config.buttonColor,
      color: config.buttonTextColor,
      border: "none",
      cursor: "pointer",
      zIndex: 9999,
      display: "flex",
      alignItems: "center",
      justifyContent: "center",
    });

    button.onclick = () => {
      const isOpen = !!document.querySelector("#dalan-chat-root");
      const unmount = getUnmountFunction();
      const mount = getMountFunction();

      if (isOpen && unmount) {
        unmount();
      } else if (mount) {
        mount(window.dalanWidgetConfig);
      } else {
        console.error("mountChatWidget function not found!");
      }
    };

    document.body.appendChild(button);
    widgetButton = button;
  }

  function initWidget() {
    if (assetsLoaded) {
      createWidgetButton();
      return;
    }

    assetsLoaded = true;

    const cssLink = document.createElement("link");
    cssLink.rel = "stylesheet";
    cssLink.href = basePath + "dalan.css";
    document.head.appendChild(cssLink);

    const script = document.createElement("script");
    script.src = basePath + "chat-app-bundle.js";

    script.onerror = (error) => {
      console.error("Failed to load chat widget bundle:", error);
      console.error("Script source:", script.src);
    };

    script.onload = () => {
      scriptLoaded = true;
      createWidgetButton();
    };

    document.body.appendChild(script);
  }

  function updateWidgetVisibility() {
    if (!isDesktopViewport()) {
      removeWidget();
      return;
    }

    initWidget();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", updateWidgetVisibility);
  } else {
    updateWidgetVisibility();
  }

  if (window.matchMedia) {
    const desktopMediaQuery = window.matchMedia(
      `(min-width: ${MIN_WIDGET_WIDTH}px)`
    );

    if (desktopMediaQuery.addEventListener) {
      desktopMediaQuery.addEventListener("change", updateWidgetVisibility);
    } else if (desktopMediaQuery.addListener) {
      desktopMediaQuery.addListener(updateWidgetVisibility);
    }
  } else {
    window.addEventListener("resize", updateWidgetVisibility);
  }
})();
