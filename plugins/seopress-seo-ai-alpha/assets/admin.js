(function () {
  function updateProviderRows() {
    var provider = qs("#vsspa-provider");
    if (!provider) {
      return;
    }
    qsa(".vsspa-provider-row").forEach(function (row) {
      var showOpenAI = provider.value === "openai" && row.classList.contains("vsspa-provider-openai-row");
      var showAnthropic = provider.value === "anthropic" && row.classList.contains("vsspa-provider-anthropic-row");
      row.style.display = showOpenAI || showAnthropic ? "" : "none";
    });
  }

  function updateLanguageRows() {
    var mode = qs("#vsspa-language-mode");
    var manualRow = qs(".vsspa-language-manual-row");
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
    var status = qs(".vsspa-status", row);
    if (!status) {
      return;
    }
    status.textContent = message || "";
    status.className = "vsspa-status";
    if (type) {
      status.classList.add("is-" + type);
    }
  }

  function setBusy(row, busy) {
    qsa("button", row).forEach(function (button) {
      button.disabled = busy ||
        (button.classList.contains("vsspa-apply") && !row.dataset.hasSuggestion) ||
        (button.classList.contains("vsspa-apply-content") && !row.dataset.hasContentProposal);
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
    formData.append("nonce", VSSPA.nonce);
    appendFormData(formData, values || {});

    var response = await fetch(VSSPA.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: formData
    });
    var json = await response.json();
    if (!json || !json.success) {
      throw new Error((json && json.data && json.data.message) || VSSPA.i18n.error);
    }
    return json.data;
  }

  function fillSuggestion(row, suggestion, validation) {
    qs(".vsspa-title", row).value = suggestion.seo_title || "";
    qs(".vsspa-description", row).value = suggestion.meta_description || "";
    qs(".vsspa-focuskw", row).value = suggestion.focus_keyphrase || "";
    qs(".vsspa-secondary", row).value = (suggestion.secondary_keywords || []).join(", ");
    qs(".vsspa-og-title", row).value = suggestion.og_title || suggestion.seo_title || "";
    qs(".vsspa-og-description", row).value = suggestion.og_description || suggestion.meta_description || "";
    qs(".vsspa-article-suggestions", row).value = formatArticleSuggestions(suggestion);
    qs(".vsspa-reading-suggestions", row).value = formatReadingSuggestions(suggestion.reading_recommendations || []);
    if (qs(".vsspa-reading-json", row)) {
      qs(".vsspa-reading-json", row).value = JSON.stringify(suggestion.reading_recommendations || []);
    }

    var quality = qs(".vsspa-quality", row);
    var warnings = validation && validation.warnings ? validation.warnings.join(" · ") : "";
    var score = validation && typeof validation.score !== "undefined" ? validation.score : "";
    quality.textContent = "Score " + score + "/100 - " + warnings;
    quality.className = "vsspa-quality";
    if (score !== "" && score < 75) {
      quality.classList.add("is-warning");
    } else {
      quality.classList.add("is-ok");
    }

    row.dataset.hasSuggestion = "1";
    qs(".vsspa-apply", row).disabled = false;
  }

  function formatList(title, items) {
    if (!items || !items.length) {
      return "";
    }
    return title + "\n" + items.map(function (item) {
      return "- " + item;
    }).join("\n");
  }

  function formatArticleSuggestions(suggestion) {
    var blocks = [];
    if (suggestion.article_optimization_summary) {
      blocks.push("Synthese\n" + suggestion.article_optimization_summary);
    }
    [
      ["Contenu a ameliorer", suggestion.content_recommendations],
      ["Titres H2/H3", suggestion.heading_recommendations],
      ["Maillage interne", suggestion.internal_linking_recommendations],
      ["Manques a combler", suggestion.content_gap_recommendations],
      ["Lisibilite", suggestion.readability_recommendations]
    ].forEach(function (section) {
      var text = formatList(section[0], section[1]);
      if (text) {
        blocks.push(text);
      }
    });
    if (suggestion.notes) {
      blocks.push("Notes\n" + suggestion.notes);
    }
    return blocks.join("\n\n");
  }

  function formatReadingSuggestions(items) {
    if (!items || !items.length) {
      return "";
    }
    return items.map(function (item) {
      var lines = [];
      lines.push("#" + item.post_id + " - " + (item.title || ""));
      if (item.url) {
        lines.push("URL: " + item.url);
      }
      if (item.anchor_text) {
        lines.push("Ancre: " + item.anchor_text);
      }
      if (item.reason) {
        lines.push("Pourquoi: " + item.reason);
      }
      if (item.placement_hint) {
        lines.push("Placement: " + item.placement_hint);
      }
      return lines.join("\n");
    }).join("\n\n");
  }

  async function generateRow(row) {
    setStatus(row, VSSPA.i18n.generating, "loading");
    setBusy(row, true);
    try {
      var data = await ajax("vsspa_generate", {
        post_id: row.dataset.postId
      });
      fillSuggestion(row, data.suggestion, data.validation);
      setStatus(row, VSSPA.i18n.done, "ok");
    } catch (error) {
      setStatus(row, error.message, "error");
      throw error;
    } finally {
      setBusy(row, false);
    }
  }

  function fillContentRewrite(row, data) {
    var summary = qs(".vsspa-content-summary", row);
    var content = qs(".vsspa-optimized-content", row);
    if (summary) {
      summary.value = data.change_summary || "";
    }
    if (content) {
      content.value = data.optimized_content || "";
    }
    row.dataset.hasContentProposal = data.optimized_content ? "1" : "";
    var applyButton = qs(".vsspa-apply-content", row);
    if (applyButton) {
      applyButton.disabled = !data.optimized_content;
    }
  }

  async function rewriteContent(row) {
    setStatus(row, VSSPA.i18n.rewriting, "loading");
    setBusy(row, true);
    try {
      var data = await ajax("vsspa_rewrite_content", {
        post_id: row.dataset.postId
      });
      fillContentRewrite(row, data);
      setStatus(row, "Proposition de contenu prete", "ok");
    } catch (error) {
      setStatus(row, error.message, "error");
      throw error;
    } finally {
      setBusy(row, false);
    }
  }

  function replaceEditorContent(content) {
    if (window.wp && wp.blocks && wp.data && wp.data.dispatch) {
      try {
        var blocks = wp.blocks.rawHandler({ HTML: content });
        wp.data.dispatch("core/block-editor").resetBlocks(blocks);
        if (wp.data.dispatch("core/editor")) {
          wp.data.dispatch("core/editor").editPost({});
        }
        return true;
      } catch (error) {
        // Fall through to classic editor handling.
      }
    }

    if (window.tinymce && tinymce.get("content")) {
      tinymce.get("content").setContent(content);
      return true;
    }

    var textarea = document.getElementById("content");
    if (textarea) {
      textarea.value = content;
      textarea.dispatchEvent(new Event("change", { bubbles: true }));
      return true;
    }

    return false;
  }

  function applyContentProposal(row) {
    var content = qs(".vsspa-optimized-content", row);
    if (!content || !content.value.trim()) {
      setStatus(row, "Aucune proposition de contenu a transformer", "error");
      return;
    }
    if (!window.confirm(VSSPA.i18n.confirmContent)) {
      return;
    }
    if (replaceEditorContent(content.value)) {
      setStatus(row, "Contenu insere dans l'editeur. Relis puis clique sur Mettre a jour.", "ok");
    } else {
      setStatus(row, "Impossible de trouver l'editeur WordPress actif.", "error");
    }
  }

  function collectApplyData(row) {
    return {
      post_id: row.dataset.postId,
      seo_title: qs(".vsspa-title", row).value,
      meta_description: qs(".vsspa-description", row).value,
      focuskw: qs(".vsspa-focuskw", row).value,
      secondary_keywords: qs(".vsspa-secondary", row).value,
      og_title: qs(".vsspa-og-title", row).value,
      og_description: qs(".vsspa-og-description", row).value,
      article_suggestions: qs(".vsspa-article-suggestions", row).value,
      reading_suggestions: qs(".vsspa-reading-suggestions", row).value,
      reading_json: qs(".vsspa-reading-json", row) ? qs(".vsspa-reading-json", row).value : ""
    };
  }

  async function applyRow(row) {
    setStatus(row, VSSPA.i18n.applying, "loading");
    setBusy(row, true);
    try {
      await ajax("vsspa_apply", collectApplyData(row));
      setStatus(row, "Enregistre dans SEOPress", "ok");
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
    var generateButton = event.target.closest(".vsspa-generate");
    if (generateButton) {
      event.preventDefault();
      generateRow(generateButton.closest(".vsspa-row"));
      return;
    }

    var applyButton = event.target.closest(".vsspa-apply");
    if (applyButton) {
      event.preventDefault();
      applyRow(applyButton.closest(".vsspa-row"));
      return;
    }

    var rewriteButton = event.target.closest(".vsspa-rewrite-content");
    if (rewriteButton) {
      event.preventDefault();
      rewriteContent(rewriteButton.closest(".vsspa-row"));
      return;
    }

    var applyContentButton = event.target.closest(".vsspa-apply-content");
    if (applyContentButton) {
      event.preventDefault();
      applyContentProposal(applyContentButton.closest(".vsspa-row"));
      return;
    }

    if (event.target.id === "vsspa-batch-generate") {
      event.preventDefault();
      runRows(qsa(".vsspa-row"), generateRow);
      return;
    }

    if (event.target.id === "vsspa-batch-apply") {
      event.preventDefault();
      if (!window.confirm(VSSPA.i18n.confirmBatch)) {
        return;
      }
      var rows = qsa(".vsspa-row").filter(function (row) {
        return row.dataset.hasSuggestion === "1";
      });
      runRows(rows, applyRow);
    }
  });

  document.addEventListener("change", function (event) {
    if (event.target && event.target.id === "vsspa-provider") {
      updateProviderRows();
    }
    if (event.target && event.target.id === "vsspa-language-mode") {
      updateLanguageRows();
    }
  });

  document.addEventListener("DOMContentLoaded", function () {
    updateProviderRows();
    updateLanguageRows();
  });
})();
