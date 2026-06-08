(function () {
  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function qsa(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function updateProviderRows() {
    var provider = qs("#vgeo-provider");
    if (!provider) {
      return;
    }
    qsa(".vgeo-provider-row").forEach(function (row) {
      var showOpenAI = provider.value === "openai" && row.classList.contains("vgeo-provider-openai-row");
      var showAnthropic = provider.value === "anthropic" && row.classList.contains("vgeo-provider-anthropic-row");
      row.style.display = showOpenAI || showAnthropic ? "" : "none";
    });
  }

  function setStatus(row, message, type) {
    var status = qs(".vgeo-status", row);
    if (!status) {
      return;
    }
    status.textContent = message || "";
    status.className = "vgeo-status";
    if (type) {
      status.classList.add("is-" + type);
    }
  }

  function setBusy(row, busy) {
    qsa("button", row).forEach(function (button) {
      button.disabled = busy;
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
    formData.append("nonce", VGEO.nonce);
    appendFormData(formData, values || {});

    var response = await fetch(VGEO.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: formData
    });
    var json = await response.json();
    if (!json || !json.success) {
      throw new Error((json && json.data && json.data.message) || VGEO.i18n.error);
    }
    return json.data;
  }

  function arrayToLines(value) {
    return Array.isArray(value) ? value.join("\n") : "";
  }

  function faqToText(faq) {
    if (!Array.isArray(faq)) {
      return "";
    }
    return faq.map(function (item) {
      return "Q: " + (item.question || "") + "\nA: " + (item.answer || "");
    }).join("\n\n");
  }

  function fillBrief(row, brief) {
    qs(".vgeo-ai-summary", row).value = brief.ai_summary || "";
    qs(".vgeo-direct-answer", row).value = brief.direct_answer || "";
    qs(".vgeo-llms-description", row).value = brief.llms_description || "";
    qs(".vgeo-entities", row).value = Array.isArray(brief.entities) ? brief.entities.join(", ") : "";
    qs(".vgeo-improvements", row).value = arrayToLines(brief.content_improvements);
    qs(".vgeo-faq", row).value = faqToText(brief.faq);
    qs(".vgeo-schema", row).value = arrayToLines(brief.schema_recommendations);
    var score = qs(".vgeo-score", row);
    if (score) {
      score.textContent = (brief.score || 0) + "/100";
    }
    row.dataset.score = brief.score || 0;
    row.dataset.hasBrief = "1";
  }

  function collectBrief(row) {
    var scoreText = (qs(".vgeo-score", row) || {}).textContent || "0";
    return {
      post_id: row.dataset.postId,
      score: parseInt(scoreText, 10) || parseInt(row.dataset.score || "0", 10) || 0,
      ai_summary: qs(".vgeo-ai-summary", row).value,
      direct_answer: qs(".vgeo-direct-answer", row).value,
      llms_description: qs(".vgeo-llms-description", row).value,
      entities: qs(".vgeo-entities", row).value,
      content_improvements: qs(".vgeo-improvements", row).value,
      faq: qs(".vgeo-faq", row).value,
      schema_recommendations: qs(".vgeo-schema", row).value
    };
  }

  async function generateRow(row) {
    setStatus(row, VGEO.i18n.generating, "loading");
    setBusy(row, true);
    try {
      var data = await ajax("vgeo_generate", { post_id: row.dataset.postId });
      fillBrief(row, data.brief);
      setStatus(row, VGEO.i18n.done, "ok");
    } catch (error) {
      setStatus(row, error.message, "error");
      throw error;
    } finally {
      setBusy(row, false);
    }
  }

  async function saveRow(row) {
    setStatus(row, VGEO.i18n.saving, "loading");
    setBusy(row, true);
    try {
      var data = await ajax("vgeo_save", collectBrief(row));
      fillBrief(row, data.brief);
      setStatus(row, "Brief GEO enregistre", "ok");
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
        // Row-level status already contains the useful error.
      }
    }
  }

  async function previewLlms(full) {
    var output = qs(".vgeo-preview");
    if (!output) {
      return;
    }
    output.value = VGEO.i18n.previewing;
    try {
      var data = await ajax("vgeo_preview_llms", { full: full ? "1" : "" });
      output.value = data.content || "";
    } catch (error) {
      output.value = error.message;
    }
  }

  document.addEventListener("click", function (event) {
    var generate = event.target.closest(".vgeo-generate");
    if (generate) {
      event.preventDefault();
      generateRow(generate.closest(".vgeo-row"));
      return;
    }

    var save = event.target.closest(".vgeo-save");
    if (save) {
      event.preventDefault();
      saveRow(save.closest(".vgeo-row"));
      return;
    }

    if (event.target.id === "vgeo-batch-generate") {
      event.preventDefault();
      if (window.confirm(VGEO.i18n.confirmBatch)) {
        runRows(qsa(".vgeo-table .vgeo-row"), generateRow);
      }
      return;
    }

    if (event.target.id === "vgeo-preview-llms") {
      event.preventDefault();
      previewLlms(false);
      return;
    }

    if (event.target.id === "vgeo-preview-full") {
      event.preventDefault();
      previewLlms(true);
    }
  });

  document.addEventListener("change", function (event) {
    if (event.target && event.target.id === "vgeo-provider") {
      updateProviderRows();
    }
  });

  document.addEventListener("DOMContentLoaded", updateProviderRows);
})();
