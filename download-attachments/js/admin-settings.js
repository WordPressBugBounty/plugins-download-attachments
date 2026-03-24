(function(){"use strict";const getSettingsPrefix = () => {
  const settingsWrapper = document.querySelector("[data-settings-prefix]");
  if (!settingsWrapper) {
    return "";
  }
  return settingsWrapper.dataset.settingsPrefix || "";
};
const initConditionals = () => {
  const prefix = getSettingsPrefix();
  if (!prefix) {
    return;
  }
  const conditionAttr = `data-${prefix}-conditional`;
  const actionAttr = `data-${prefix}-conditional-action`;
  const hiddenClass = `${prefix}-hidden`;
  const allFields = [];
  const controllerToFields = /* @__PURE__ */ new Map();
  const supportedAnimations = ["fade", "slide"];
  const parseConditions = (field) => {
    const raw = field.getAttribute(conditionAttr);
    if (!raw) {
      return [];
    }
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) {
        return parsed;
      }
      if (parsed && typeof parsed === "object") {
        return [parsed];
      }
    } catch (e) {
      return [];
    }
    return [];
  };
  const parseAction = (field) => {
    const raw = field.getAttribute(actionAttr);
    if (!raw) {
      return "show";
    }
    if (["show", "hide", "enable", "disable"].includes(raw)) {
      return raw;
    }
    return "show";
  };
  const parseAnimation = (field) => {
    const raw = field.getAttribute(`data-${prefix}-animation`);
    if (raw && supportedAnimations.includes(raw)) {
      return raw;
    }
    return "";
  };
  const isFormControl = (element) => {
    if (!element || !element.matches) {
      return false;
    }
    return element.matches("input, select, textarea");
  };
  const getControllerValue = (controllerId) => {
    const element = document.getElementById(controllerId);
    if (isFormControl(element)) {
      if (element.type === "checkbox") {
        return element.checked ? element.value : "";
      }
      if (element.type === "radio") {
        return element.checked ? element.value : "";
      }
      return element.value;
    }
    const radios = document.querySelectorAll(`input[type="radio"][id^="${controllerId}-"]`);
    if (radios.length > 0) {
      for (const radio of radios) {
        if (radio.checked) {
          return radio.value;
        }
      }
      return null;
    }
    const checkboxes = document.querySelectorAll(`input[type="checkbox"][id^="${controllerId}-"]`);
    if (checkboxes.length > 0) {
      const values = [];
      checkboxes.forEach((checkbox) => {
        if (checkbox.checked) {
          values.push(checkbox.value);
        }
      });
      return values;
    }
    return null;
  };
  const bindControllerEvents = (controllerId, handler) => {
    const element = document.getElementById(controllerId);
    if (isFormControl(element)) {
      element.addEventListener("change", handler);
      element.addEventListener("input", handler);
      return;
    }
    const radios = document.querySelectorAll(`input[type="radio"][id^="${controllerId}-"]`);
    radios.forEach((radio) => {
      radio.addEventListener("change", handler);
    });
    const checkboxes = document.querySelectorAll(`input[type="checkbox"][id^="${controllerId}-"]`);
    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener("change", handler);
    });
  };
  const setVisible = (fieldObj, visible, animate = true) => {
    const { row } = fieldObj;
    const animation = fieldObj.animation;
    if (!animation || !animate) {
      row.classList.toggle(hiddenClass, !visible);
      row.hidden = !visible;
      return;
    }
    const animationInClass = `${prefix}-anim-in`;
    const animationOutClass = `${prefix}-anim-out`;
    const animationClass = `${prefix}-anim-${animation}`;
    if (row._daAnimHandler) {
      row.removeEventListener("animationend", row._daAnimHandler);
      row._daAnimHandler = null;
    }
    row.classList.remove(animationInClass, animationOutClass, animationClass);
    if (visible) {
      row.hidden = false;
      row.classList.remove(hiddenClass);
      row.classList.add(animationInClass, animationClass);
      row._daAnimHandler = () => {
        row.classList.remove(animationInClass, animationOutClass, animationClass);
        row.removeEventListener("animationend", row._daAnimHandler);
        row._daAnimHandler = null;
      };
      row.addEventListener("animationend", row._daAnimHandler, { once: true });
      return;
    }
    row.classList.remove(hiddenClass);
    row.classList.add(animationOutClass, animationClass);
    row._daAnimHandler = () => {
      row.classList.remove(animationInClass, animationOutClass, animationClass);
      row.classList.add(hiddenClass);
      row.hidden = true;
      row.removeEventListener("animationend", row._daAnimHandler);
      row._daAnimHandler = null;
    };
    row.addEventListener("animationend", row._daAnimHandler, { once: true });
  };
  const setDisabled = (fieldObj, disabled) => {
    const inputs = fieldObj.el.querySelectorAll("input, select, textarea, button");
    inputs.forEach((input) => {
      if (disabled) {
        if (!input.disabled) {
          input.dataset.daCondDisabled = "true";
          input.disabled = true;
        }
      } else if (input.dataset.daCondDisabled === "true") {
        input.disabled = false;
        delete input.dataset.daCondDisabled;
      }
    });
  };
  document.querySelectorAll(`[${conditionAttr}]`).forEach((fieldEl) => {
    const conditions = parseConditions(fieldEl);
    if (!conditions.length) {
      return;
    }
    const row = fieldEl.closest("tr") || fieldEl;
    const fieldObj = {
      el: fieldEl,
      row,
      conditions,
      action: parseAction(fieldEl),
      animation: parseAnimation(fieldEl)
    };
    allFields.push(fieldObj);
    conditions.forEach((condition) => {
      if (!controllerToFields.has(condition.field)) {
        controllerToFields.set(condition.field, []);
      }
      const list = controllerToFields.get(condition.field);
      if (!list.includes(fieldObj)) {
        list.push(fieldObj);
      }
    });
  });
  const isConditionMet = (fieldObj) => {
    return fieldObj.conditions.every((condition) => {
      const currentValue = getControllerValue(condition.field);
      if (currentValue === null) {
        return false;
      }
      switch (condition.operator) {
        case "is":
          return currentValue === condition.value;
        case "isnot":
          return currentValue !== condition.value;
        case "contains":
          return (typeof currentValue === "string" || Array.isArray(currentValue)) && currentValue.includes(condition.value);
        case "containsnot":
          return (typeof currentValue === "string" || Array.isArray(currentValue)) && !currentValue.includes(condition.value);
        default:
          return false;
      }
    });
  };
  const boundControllers = /* @__PURE__ */ new Set();
  controllerToFields.forEach((fields, controllerId) => {
    if (boundControllers.has(controllerId)) {
      return;
    }
    const handler = () => {
      fields.forEach((fieldObj) => {
        const conditionMet = isConditionMet(fieldObj);
        if (fieldObj.action === "enable" || fieldObj.action === "disable") {
          const shouldDisable = fieldObj.action === "disable" ? conditionMet : !conditionMet;
          setDisabled(fieldObj, shouldDisable);
          return;
        }
        const shouldShow = fieldObj.action === "show" ? conditionMet : !conditionMet;
        setVisible(fieldObj, shouldShow);
      });
    };
    bindControllerEvents(controllerId, handler);
    boundControllers.add(controllerId);
  });
  allFields.forEach((fieldObj) => {
    const conditionMet = isConditionMet(fieldObj);
    if (fieldObj.action === "enable" || fieldObj.action === "disable") {
      const shouldDisable = fieldObj.action === "disable" ? conditionMet : !conditionMet;
      setDisabled(fieldObj, shouldDisable);
      return;
    }
    const shouldShow = fieldObj.action === "show" ? conditionMet : !conditionMet;
    setVisible(fieldObj, shouldShow, false);
  });
};
document.addEventListener("DOMContentLoaded", initConditionals);
document.addEventListener("wpAjaxContentLoaded", initConditionals);
const settingsData = typeof daArgsSettings !== "undefined" ? daArgsSettings : {};
let settingsInitialized = false;
const normalizeDownloadLink = (value) => {
  if (!value) {
    return "";
  }
  let normalized = String(value).trim();
  if (!normalized) {
    return "";
  }
  normalized = normalized.replace(/[^a-z0-9\-_\s]/gi, "");
  normalized = normalized.replace(/[\s]/gi, "-");
  return normalized;
};
const initResetButtons = () => {
  const resetSettingsMessage = settingsData.resetToDefaults || "";
  const resetDownloadsMessage = settingsData.resetDownloadsToDefaults || "";
  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }
    if (target.matches("input#reset_da_general, input#reset_da_display, input#reset_da_admin")) {
      if (!confirm(resetSettingsMessage)) {
        event.preventDefault();
      }
      return;
    }
    if (target.matches("input#reset_da_downloads")) {
      if (!confirm(resetDownloadsMessage)) {
        event.preventDefault();
      }
    }
  });
};
const initDownloadLinkPreview = () => {
  const input = document.getElementById("da-general-download-link") || document.getElementById("da_general_download_link");
  if (!input) {
    return;
  }
  const field = input.closest(".da-field") || input.closest("tr") || input.parentElement;
  const preview = field ? field.querySelector("[data-da-download-link-preview]") : document.querySelector("[data-da-download-link-preview]");
  if (!preview) {
    return;
  }
  const defaultValue = normalizeDownloadLink(settingsData.defaultDownloadLink || "download-attachment") || "download-attachment";
  const updatePreview = () => {
    const normalized = normalizeDownloadLink(input.value);
    preview.textContent = normalized || defaultValue;
  };
  input.addEventListener("input", updatePreview);
  input.addEventListener("change", updatePreview);
  input.addEventListener("blur", updatePreview);
  updatePreview();
};
const initAdminSettings = () => {
  if (settingsInitialized) {
    return;
  }
  settingsInitialized = true;
  initResetButtons();
  initDownloadLinkPreview();
};
document.addEventListener("DOMContentLoaded", initAdminSettings);
document.addEventListener("wpAjaxContentLoaded", initAdminSettings);
})();