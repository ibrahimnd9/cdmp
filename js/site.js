document.addEventListener("DOMContentLoaded", function () {
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
});
