document.addEventListener("DOMContentLoaded", () => {
  const urlParams = new URLSearchParams(window.location.search);
  const ref = urlParams.get("ref");

  fetch("data/vehicles.json")
    .then(response => response.json())
    .then(data => {
      // vehicles.json is { "vehicles": [...] }
      const vehicles = data.vehicles || data;
      const vehicle = vehicles.find(v => (v.ref_id || v.ref) === ref);
        const titleEl = document.getElementById("vehicle-title");
        if (titleEl) titleEl.textContent = "Vehicle not found";
        return;
      }

      const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

      set("vehicle-title", vehicle.display_name_en);
      set("vehicle-ref", vehicle.ref_id || vehicle.ref || "-");
      set("vehicle-year", vehicle.year || "-");
      set("vehicle-mileage", vehicle.mileage_km != null ? vehicle.mileage_km.toLocaleString() + " km" : "-");
      set("vehicle-fuel", vehicle.fuel_type || "-");
      set("vehicle-transmission", vehicle.transmission || "-");
      set("vehicle-body", vehicle.body_type || "-");
      set("vehicle-make", vehicle.make || "-");
      set("vehicle-model", vehicle.model || "-");

      if (vehicle.price_low_usd != null) set("price-low", "$" + vehicle.price_low_usd.toLocaleString());
      if (vehicle.price_high_usd != null) set("price-high", "$" + vehicle.price_high_usd.toLocaleString());

      const basisEl = document.getElementById("price-basis");
      if (basisEl) {
        if (vehicle.basis_from && vehicle.basis_to) {
          basisEl.textContent = vehicle.basis_from + " - " + vehicle.basis_to;
        } else if (vehicle.basis) {
          basisEl.textContent = vehicle.basis;
        }
      }

      if (vehicle.disclaimer_short) set("vehicle-disclaimer", vehicle.disclaimer_short);

      const mainImage = document.getElementById("main-image");
      if (mainImage) {
        if (vehicle.gallery && vehicle.gallery.length > 0) {
          mainImage.src = vehicle.gallery[0];
          mainImage.alt = vehicle.display_name_en;
        } else {
          mainImage.style.display = "none";
        }
      }

      const thumbContainer = document.getElementById("gallery-thumbs");
      if (thumbContainer && vehicle.gallery && vehicle.gallery.length > 1) {
        vehicle.gallery.forEach((src, i) => {
          const img = document.createElement("img");
          img.src = src;
          img.alt = vehicle.display_name_en + " photo " + (i + 1);
          img.style.cssText = "width:80px;height:60px;object-fit:cover;cursor:pointer;border:2px solid transparent;border-radius:4px;";
          img.addEventListener("click", () => { if (mainImage) mainImage.src = src; });
          thumbContainer.appendChild(img);
        });
      }

      const rfqLink = document.getElementById("rfq-link");
      if (rfqLink) rfqLink.href = "rfq.html?ref=" + (vehicle.ref_id || vehicle.ref);

      const waLink = document.getElementById("whatsapp-link");
      if (waLink) {
        const waText = encodeURIComponent("Hello, I am interested in: " + vehicle.display_name_en + " (Ref: " + (vehicle.ref_id || vehicle.ref) + ")");
        waLink.href = "https://wa.me/819076671825?text=" + waText;
      }
    })
    .catch(err => console.error("Failed to load vehicle data:", err));
});
