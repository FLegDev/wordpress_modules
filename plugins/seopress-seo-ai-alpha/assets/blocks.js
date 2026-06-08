(function (blocks, element, components, blockEditor) {
  var el = element.createElement;
  var InspectorControls = blockEditor.InspectorControls;
  var TextControl = components.TextControl;
  var PanelBody = components.PanelBody;

  blocks.registerBlockType("vsspa/reading-slider", {
    title: "Poursuite de lecture",
    icon: "slides",
    category: "widgets",
    description: "Affiche le slider des articles recommandes par SEO Meta AI.",
    edit: function () {
      return el(
        "div",
        { className: "vsspa-block-placeholder" },
        el("strong", null, "Poursuite de lecture"),
        el("p", null, "Le slider sera rendu avec les recommandations generees pour cet article.")
      );
    },
    save: function () {
      return null;
    }
  });

  blocks.registerBlockType("vsspa/reading-popup", {
    title: "Popup poursuite de lecture",
    icon: "external",
    category: "widgets",
    description: "Insere un bouton qui ouvre les lectures recommandees dans un popup.",
    attributes: {
      buttonText: {
        type: "string",
        default: "Continuer la lecture"
      },
      title: {
        type: "string",
        default: "A lire aussi"
      }
    },
    edit: function (props) {
      return el(
        "div",
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: "Popup", initialOpen: true },
            el(TextControl, {
              label: "Texte du bouton",
              value: props.attributes.buttonText,
              onChange: function (value) {
                props.setAttributes({ buttonText: value });
              }
            }),
            el(TextControl, {
              label: "Titre du popup",
              value: props.attributes.title,
              onChange: function (value) {
                props.setAttributes({ title: value });
              }
            })
          )
        ),
        el(
          "div",
          { className: "vsspa-block-placeholder" },
          el("strong", null, "Popup poursuite de lecture"),
          el("p", null, props.attributes.buttonText || "Continuer la lecture")
        )
      );
    },
    save: function () {
      return null;
    }
  });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor);
