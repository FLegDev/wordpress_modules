(function () {
  function qsa(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  document.addEventListener("click", function (event) {
    var next = event.target.closest(".vsspa-slider-next");
    var prev = event.target.closest(".vsspa-slider-prev");
    if (next || prev) {
      event.preventDefault();
      var slider = event.target.closest(".vsspa-reading-slider");
      var track = slider ? slider.querySelector(".vsspa-reading-track") : null;
      if (!track) {
        return;
      }
      var amount = Math.max(240, Math.round(track.clientWidth * 0.8));
      track.scrollBy({
        left: next ? amount : -amount,
        behavior: "smooth"
      });
      return;
    }

    var opener = event.target.closest(".vsspa-popup-open");
    if (opener) {
      event.preventDefault();
      var target = document.getElementById(opener.getAttribute("data-vsspa-popup-target"));
      if (target) {
        target.hidden = false;
        document.documentElement.classList.add("vsspa-popup-is-open");
      }
      return;
    }

    if (event.target.closest("[data-vsspa-popup-close]")) {
      event.preventDefault();
      qsa(".vsspa-popup").forEach(function (popup) {
        popup.hidden = true;
      });
      document.documentElement.classList.remove("vsspa-popup-is-open");
    }
  });

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") {
      return;
    }
    qsa(".vsspa-popup").forEach(function (popup) {
      popup.hidden = true;
    });
    document.documentElement.classList.remove("vsspa-popup-is-open");
  });
})();
