(function () {
  "use strict";

  const root = document.documentElement;
  const themeSwitcher = document.getElementById("themeSwitcher");

  const isDark = () => root.classList.contains("dark");

  const setTheme = (dark) => {
    root.classList.toggle("dark", dark);
    localStorage.setItem("theme", dark ? "dark" : "light");
    if (window.ipMapLayer) {
      window.ipMapLayer.setUrl(tileUrl());
    }
  };

  if (themeSwitcher) {
    themeSwitcher.addEventListener("click", () => setTheme(!isDark()));
  }

  const toast = document.getElementById("toast");
  const showToast = (message) => {
    if (!toast) return;
    toast.textContent = message;
    toast.style.display = "block";
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => {
      toast.style.display = "none";
    }, 1600);
  };

  const copyText = async (value) => {
    try {
      await navigator.clipboard.writeText(value);
      showToast("Copied");
    } catch (e) {
      const field = document.createElement("textarea");
      field.value = value;
      document.body.appendChild(field);
      field.select();
      document.execCommand("copy");
      field.remove();
      showToast("Copied");
    }
  };

  document.querySelectorAll("[data-copy]").forEach((button) => {
    button.addEventListener("click", () => {
      copyText(button.getAttribute("data-copy") || "");
    });
  });

  function tileUrl() {
    return "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
  }

  if (typeof L === "undefined" || typeof mapData === "undefined" || mapData.lat == null || mapData.lng == null) {
    return;
  }

  const mapEl = document.getElementById("map");
  if (!mapEl) {
    return;
  }

  const accuracy = Number(mapData.accuracy) || 0;
  const zoom = accuracy >= 500 ? 4 : accuracy >= 100 ? 6 : 10;

  const map = L.map("map", {
    center: [mapData.lat, mapData.lng],
    zoom: zoom,
    scrollWheelZoom: false,
    zoomControl: true,
  });

  window.ipMapLayer = L.tileLayer(tileUrl(), {
    attribution: "&copy; OpenStreetMap",
  }).addTo(map);

  const marker = L.marker([mapData.lat, mapData.lng], {
    title: mapData.title,
    icon: L.divIcon({
      className: "",
      html: '<div class="ip-marker"></div>',
      iconSize: [16, 16],
      iconAnchor: [8, 8],
      popupAnchor: [0, -10],
    }),
  }).addTo(map);

  if (mapData.details) {
    marker.bindPopup(mapData.details);
  }

  window.setTimeout(() => {
    map.invalidateSize();
  }, 200);
})();
