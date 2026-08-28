(function () {
  "use strict";

  const pageConfig = window.pageConfig || {};

  const isIpv4 = (value) => /^\d{1,3}(?:\.\d{1,3}){3}$/.test(value);

  const fetchIpv4 = async () => {
    try {
      const res = await fetch("https://api.ipify.org?format=json", { cache: "no-store" });
      if (res.ok) {
        const data = await res.json();
        if (data && isIpv4(String(data.ip || ""))) {
          return String(data.ip);
        }
      }
    } catch (e) {}

    try {
      const res = await fetch("https://ipv4.icanhazip.com/", { cache: "no-store" });
      if (res.ok) {
        const text = (await res.text()).trim();
        if (isIpv4(text)) {
          return text;
        }
      }
    } catch (e) {}

    return null;
  };

  if (pageConfig.detectIpv4) {
    fetchIpv4().then((ip) => {
      const status = document.getElementById("ipv4-status");
      if (!ip) {
        if (status) {
          status.textContent = "IPv4 was not available on this connection. Showing IPv6.";
        }
        return;
      }
      const url = new URL(window.location.href);
      url.searchParams.set("ip", ip);
      url.searchParams.set("self", "1");
      window.location.replace(url.pathname + url.search);
    });
  }

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
