(function () {
  function updateProviderRows() {
    var provider = qs("#vsmya-provider");
    if (!provider) {
      return;
    }
    qsa(".vsmya-provider-row").forEach(function (row) {
      var showOpenAI = provider.value === "openai" && row.classList.contains("vsmya-provider-openai-row");
      var showAnthropic = provider.value === "anthropic" && row.classList.contains("vsmya-provider-anthropic-row");
      row.style.display = showOpenAI || showAnthropic ? "" : "none";
    });
  }

  function updateLanguageRows() {
    var mode = qs("#vsmya-language-mode");
    var manualRow = qs(".vsmya-language-manual-row");
    if (!mode || !manualRow) {
      return;
    }
    manualRow.style.display = mode.value === "manual" ? "" : "none";
  }

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function qsa(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function setStatus(row, message, type) {
    var status = qs(".vsmya-status", row);
    if (!status) {
      return;
    }
    status.textContent = message || "";
    status.className = "vsmya-status";
    if (type) {
      status.classList.add("is-" + type);
    }
  }

  function setBusy(row, busy) {
    qsa("button", row).forEach(function (button) {
      button.disabled = busy || (button.classList.contains("vsmya-apply") && !row.dataset.hasSuggestion);
    });
  }

  function appendFormData(formData, values) {
    Object.keys(values).forEach(function (key) {
      formData.append(key, values[key] == null ? "" : values[key]);
    });
  }

  async function ajax(action, values) {
    var formData = new FormData();
    formData.append("action", action);
    formData.append("nonce", VSMYA.nonce);
    appendFormData(formData, values || {});

    var response = await fetch(VSMYA.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: formData
    });
    var json = await response.json();
    if (!json || !json.success) {
      throw new Error((json && json.data && json.data.message) || VSMYA.i18n.error);
    }
    return json.data;
  }

  function fillSuggestion(row, suggestion, validation) {
    qs(".vsmya-title", row).value = suggestion.seo_title || "";
    qs(".vsmya-description", row).value = suggestion.meta_description || "";
    qs(".vsmya-focuskw", row).value = suggestion.focus_keyphrase || "";
    qs(".vsmya-secondary", row).value = (suggestion.secondary_keywords || []).join(", ");
    qs(".vsmya-og-title", row).value = suggestion.og_title || suggestion.seo_title || "";
    qs(".vsmya-og-description", row).value = suggestion.og_description || suggestion.meta_description || "";

    var quality = qs(".vsmya-quality", row);
    var warnings = validation && validation.warnings ? validation.warnings.join(" · ") : "";
    var score = validation && typeof validation.score !== "undefined" ? validation.score : "";
    quality.textContent = "Score " + score + "/100 - " + warnings;
    quality.className = "vsmya-quality";
    if (score !== "" && score < 75) {
      quality.classList.add("is-warning");
    } else {
      quality.classList.add("is-ok");
    }

    row.dataset.hasSuggestion = "1";
    qs(".vsmya-apply", row).disabled = false;
  }

  async function generateRow(row) {
    setStatus(row, VSMYA.i18n.generating, "loading");
    setBusy(row, true);
    try {
      var data = await ajax("vsmya_generate", {
        post_id: row.dataset.postId
      });
      fillSuggestion(row, data.suggestion, data.validation);
      setStatus(row, VSMYA.i18n.done, "ok");
    } catch (error) {
      setStatus(row, error.message, "error");
      throw error;
    } finally {
      setBusy(row, false);
    }
  }

  function collectApplyData(row) {
    return {
      post_id: row.dataset.postId,
      seo_title: qs(".vsmya-title", row).value,
      meta_description: qs(".vsmya-description", row).value,
      focuskw: qs(".vsmya-focuskw", row).value,
      secondary_keywords: qs(".vsmya-secondary", row).value,
      og_title: qs(".vsmya-og-title", row).value,
      og_description: qs(".vsmya-og-description", row).value
    };
  }

  async function applyRow(row) {
    setStatus(row, VSMYA.i18n.applying, "loading");
    setBusy(row, true);
    try {
      await ajax("vsmya_apply", collectApplyData(row));
      setStatus(row, "Enregistre dans Yoast", "ok");
    } catch (error) {
      setStatus(row, error.message, "error");
      throw error;
    } finally {
      setBusy(row, false);
    }
  }

  async function runRows(rows, action) {
    for (var index = 0; index < rows.length; index += 1) {
      try {
        await action(rows[index]);
      } catch (error) {
        // Keep the batch moving; row status already contains the useful message.
      }
    }
  }

  document.addEventListener("click", function (event) {
    var generateButton = event.target.closest(".vsmya-generate");
    if (generateButton) {
      event.preventDefault();
      generateRow(generateButton.closest(".vsmya-row"));
      return;
    }

    var applyButton = event.target.closest(".vsmya-apply");
    if (applyButton) {
      event.preventDefault();
      applyRow(applyButton.closest(".vsmya-row"));
      return;
    }

    if (event.target.id === "vsmya-batch-generate") {
      event.preventDefault();
      runRows(qsa(".vsmya-row"), generateRow);
      return;
    }

    if (event.target.id === "vsmya-batch-apply") {
      event.preventDefault();
      if (!window.confirm(VSMYA.i18n.confirmBatch)) {
        return;
      }
      var rows = qsa(".vsmya-row").filter(function (row) {
        return row.dataset.hasSuggestion === "1";
      });
      runRows(rows, applyRow);
    }
  });

  document.addEventListener("change", function (event) {
    if (event.target && event.target.id === "vsmya-provider") {
      updateProviderRows();
    }
    if (event.target && event.target.id === "vsmya-language-mode") {
      updateLanguageRows();
    }
  });

  document.addEventListener("DOMContentLoaded", function () {
    updateProviderRows();
    updateLanguageRows();
  });
})();
