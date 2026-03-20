document.addEventListener("DOMContentLoaded", () => {
  const urlParams = new URLSearchParams(window.location.search);
  const ref = urlParams.get("ref");

  fetch("data/vehicles.json")
    .then(response => response.json())
    .then(data => {
      const vehicles = data.vehicles || data;
      const vehicle = vehicles.find(v => (v.ref_id || v.ref) === ref);

      if (!vehicle) {
        const titleEl = document.getElementById("vehicle-title");
        if (titleEl) titleEl.textContent = "Vehicle not found";
        return;
      }

      const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

      // タイトル・Ref ID
      set("vehicle-title", vehicle.display_name_en);
      set("ref-id", vehicle.ref_id || vehicle.ref || "-");
      set("breadcrumb-title", vehicle.display_name_en);

      // スペック（HTMLのIDに合わせる）
      set("spec-make", vehicle.make || "-");
      set("spec-model", vehicle.model || "-");
      set("spec-year", vehicle.year || "-");
      set("spec-body-type", vehicle.body_type || "-");
      set("spec-fuel-type", vehicle.fuel_type || "-");
      set("spec-transmission", vehicle.transmission || "-");
      set("spec-mileage", vehicle.mileage_km != null ? vehicle.mileage_km.toLocaleString() + " km" : "-");

      // 価格
      if (vehicle.price_low_usd != null) set("price-low", "$" + vehicle.price_low_usd.toLocaleString());
      if (vehicle.price_high_usd != null) set("price-high", "$" + vehicle.price_high_usd.toLocaleString());

      // 参照期間
      set("basis-from", vehicle.basis_from || "N/A");
      set("basis-to", vehicle.basis_to || "N/A");

      // 免責事項
      if (vehicle.disclaimer_short) set("vehicle-disclaimer", vehicle.disclaimer_short);

      // メイン画像
      const mainImage = document.getElementById("main-image");
      if (mainImage) {
        if (vehicle.gallery && vehicle.gallery.length > 0) {
          mainImage.src = vehicle.gallery[0];
          mainImage.alt = vehicle.display_name_en;
        } else {
          mainImage.style.display = "none";
        }
      }

      // サムネイル
      const thumbContainer = document.getElementById("thumbnail-gallery");
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

      // WhatsApp・RFQリンク
      const waBtn = document.getElementById("wa-button");
      if (waBtn) {
        const waText = encodeURIComponent("Hello, I am interested in: " + vehicle.display_name_en + " (Ref: " + (vehicle.ref_id || vehicle.ref) + ")");
        waBtn.onclick = () => window.open("https://wa.me/819076671825?text=" + waText, "_blank");
      }

      const rfqLink = document.getElementById("formal-rfq-link");
      if (rfqLink) rfqLink.href = "rfq.html?ref=" + (vehicle.ref_id || vehicle.ref);
    })
    .catch(err => console.error("Failed to load vehicle data:", err));
});
