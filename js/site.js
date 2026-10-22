document.addEventListener("DOMContentLoaded", function () {
  /* ---------- Onglets (widgets .pc-widget) ---------- */
  document.querySelectorAll(".pc-widget").forEach(function (widget) {
    var tabs = widget.querySelectorAll(".pc-tab");
    var panels = widget.querySelectorAll(".pc-panel");
    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        tabs.forEach(function (t) { t.classList.remove("active"); });
        panels.forEach(function (p) { p.classList.remove("active"); });
        tab.classList.add("active");
        var target = widget.querySelector("#" + tab.getAttribute("data-target"));
        if (target) { target.classList.add("active"); }
      });
    });
  });

  /* ---------- Formulaires : envoi vers api/envoi.php ---------- */
  document.querySelectorAll("form[data-formulaire]").forEach(function (form) {
    var status = form.querySelector(".form-status");
    if (!status) {
      status = document.createElement("div");
      status.className = "form-status";
      status.setAttribute("role", "status");
      status.setAttribute("aria-live", "polite");
      form.appendChild(status);
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      status.className = "form-status";
      status.textContent = "";

      // Au moins une case cochée dans les groupes marqués data-required-group
      var groupes = form.querySelectorAll("[data-required-group]");
      for (var i = 0; i < groupes.length; i++) {
        if (!groupes[i].querySelector("input:checked")) {
          showStatus(status, false, groupes[i].getAttribute("data-required-group"));
          groupes[i].scrollIntoView({ behavior: "smooth", block: "center" });
          return;
        }
      }

      var data = new FormData(form);
      data.append("formulaire", form.getAttribute("data-formulaire"));
      data.append("origine", window.location.pathname);

      var btn = form.querySelector("[type=submit]");
      var btnHtml = btn ? btn.innerHTML : "";
      if (btn) { btn.disabled = true; btn.textContent = "Envoi en cours…"; }

      fetch(form.getAttribute("action") || "api/envoi.php", { method: "POST", body: data })
        .then(function (r) {
          return r.json().catch(function () {
            throw new Error("Réponse inattendue du serveur.");
          });
        })
        .then(function (res) {
          showStatus(status, !!res.ok, res.message);
          if (res.ok) {
            form.reset();
            form.dispatchEvent(new Event("change"));
          }
        })
        .catch(function () {
          showStatus(status, false, "L'envoi n'a pas abouti. Vérifiez votre connexion et réessayez, ou écrivez-nous à contact@cdmp.sn.");
        })
        .then(function () {
          if (btn) { btn.disabled = false; btn.innerHTML = btnHtml; }
        });
    });
  });

  function showStatus(el, ok, message) {
    el.className = "form-status " + (ok ? "is-success" : "is-error");
    el.textContent = message;
    el.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  /* ---------- Affichage du nom des fichiers choisis ---------- */
  document.querySelectorAll(".df-file input[type=file]").forEach(function (input) {
    var limit = input.closest(".df-file").querySelector(".file-limit");
    var initial = limit ? limit.textContent : "";
    input.addEventListener("change", function () {
      if (!limit) { return; }
      var noms = Array.prototype.map.call(input.files, function (f) { return f.name; });
      limit.textContent = noms.length ? "Sélectionné : " + noms.join(", ") : initial;
      input.closest(".df-file").classList.toggle("has-file", noms.length > 0);
    });
  });

  /* ---------- Pré-sélection du service (?service=...) ---------- */
  var params = new URLSearchParams(window.location.search);
  var service = params.get("service");
  if (service) {
    var box = document.querySelector('input[name="besoin[]"][data-service="' + service.replace(/[^a-z-]/g, "") + '"]');
    if (box) { box.checked = true; }
  }

  /* ---------- Champ conditionnel (data-show-if="nomDuChamp=valeur") ---------- */
  document.querySelectorAll("[data-show-if]").forEach(function (bloc) {
    var regle = bloc.getAttribute("data-show-if").split("=");
    var form = bloc.closest("form");
    var champ = form && form.querySelector('[name="' + regle[0] + '"]');
    if (!champ) { return; }
    var maj = function () { bloc.hidden = champ.value !== regle[1]; };
    champ.addEventListener("change", maj);
    form.addEventListener("change", maj);
    maj();
  });

  /* ---------- Ouverture de la modale PayKapital ---------- */
  var modal = document.getElementById("pk-modal");
  if (modal) {
    var dernierFocus = null;
    var ouvrir = function (e) {
      e.preventDefault();
      dernierFocus = document.activeElement;
      modal.hidden = false;
      document.body.classList.add("modal-open");
      var premier = modal.querySelector("select, input");
      if (premier) { premier.focus(); }
    };
    var fermer = function () {
      modal.hidden = true;
      document.body.classList.remove("modal-open");
      if (dernierFocus) { dernierFocus.focus(); }
    };
    document.querySelectorAll("[data-open-pk-form]").forEach(function (b) { b.addEventListener("click", ouvrir); });
    modal.querySelectorAll("[data-close-modal]").forEach(function (b) { b.addEventListener("click", fermer); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !modal.hidden) { fermer(); } });
    if (window.location.hash === "#rejoindre") { ouvrir(new Event("click")); }
  }

  /* ---------- Vidéos YouTube : chargement au clic, lecture sur la page ---------- */
  document.querySelectorAll(".video-card[data-youtube-id]").forEach(function (card) {
    var id = card.getAttribute("data-youtube-id").trim();
    var frame = card.querySelector(".video-frame");
    if (!id || !frame) { return; }
    card.classList.add("is-ready");
    frame.style.backgroundImage = "url(https://i.ytimg.com/vi/" + encodeURIComponent(id) + "/hqdefault.jpg)";
    var lancer = function () {
      var iframe = document.createElement("iframe");
      iframe.src = "https://www.youtube-nocookie.com/embed/" + encodeURIComponent(id) + "?autoplay=1&rel=0";
      iframe.title = card.querySelector("h3") ? card.querySelector("h3").textContent : "Vidéo PayKapital";
      iframe.allow = "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture";
      iframe.allowFullscreen = true;
      frame.innerHTML = "";
      frame.appendChild(iframe);
    };
    frame.addEventListener("click", lancer);
    frame.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") { e.preventDefault(); lancer(); }
    });
  });

  /* ---------- Schéma écosystème PayKapital (interactif) ---------- */
  var eco = document.querySelector(".eco-wrap");
  if (eco) {
    var nodes = eco.querySelectorAll(".eco-node");
    var links = eco.querySelectorAll(".eco-link");
    var items = eco.querySelectorAll(".eco-panel-item");
    var activer = function (key) {
      nodes.forEach(function (n) {
        var on = n.getAttribute("data-node") === key;
        n.classList.toggle("active", on);
        n.setAttribute("aria-pressed", on ? "true" : "false");
      });
      links.forEach(function (l) { l.classList.toggle("active", l.getAttribute("data-node") === key); });
      items.forEach(function (p) { p.hidden = p.getAttribute("data-node") !== key; });
    };
    nodes.forEach(function (n) {
      n.addEventListener("click", function () { activer(n.getAttribute("data-node")); });
      n.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); activer(n.getAttribute("data-node")); }
      });
    });
    activer(eco.getAttribute("data-default") || "entreprises");
  }
});
