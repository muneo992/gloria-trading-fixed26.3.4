document.addEventListener("DOMContentLoaded", () => {
  const urlParams = new URLSearchParams(window.location.search);
  const ref = urlParams.get("ref");

  const getRef = (v) => v.ref || v.ref_id || "";
  const getTitle = (v) => v.title || v.display_name_en || [v.year, v.make, v.model, v.grade].filter(Boolean).join(" ");
  const getImages = (v) => v.images || v.gallery || (v.image_main ? [v.image_main] : []);
  const getMileage = (v) => Number(v.mileage ?? v.mileage_km ?? 0);
  const getMileageDisplay = (v) => v.mileage_display || (getMileage(v) ? `${getMileage(v).toLocaleString()} km` : "-");
  const getBody = (v) => v.body || v.body_type || "-";
  const getFuel = (v) => v.fuel || v.fuel_type || "-";
  const getPrice = (v) => Number(v.price_usd ?? v.price_low_usd ?? 0);
  const getPriceDisplayNumber = (v) => getPrice(v) ? getPrice(v).toLocaleString() : "Ask";

  fetch("data/vehicles.json")
    .then(response => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return response.json();
    })
    .then(data => {
      const vehicles = Array.isArray(data) ? data : (data.vehicles || []);
      const vehicle = vehicles.find(v => getRef(v) === ref);

      if (!vehicle) {
        const titleEl = document.getElementById("vehicle-title");
        if (titleEl) titleEl.textContent = "Vehicle not found";
        return;
      }

      const set = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val ?? "-";
      };

      const title = getTitle(vehicle);
      const vehicleRef = getRef(vehicle);

      set("vehicle-title", title);
      set("ref-id", vehicleRef);
      set("breadcrumb-title", title);

      set("spec-make", vehicle.make || "-");
      set("spec-model", [vehicle.model, vehicle.grade].filter(Boolean).join(" ") || vehicle.model || "-");
      set("spec-year", vehicle.year || "-");
      set("spec-body-type", getBody(vehicle));
      set("spec-fuel-type", getFuel(vehicle));
      set("spec-transmission", vehicle.transmission || "-");
      set("spec-mileage", getMileageDisplay(vehicle));

      set("price-low", getPriceDisplayNumber(vehicle));
      set("price-high", getPriceDisplayNumber(vehicle));
      set("basis-from", vehicle.auction_date || vehicle.basis_from || "Past transaction");
      set("basis-to", vehicle.auction_venue || vehicle.basis_to || "Reference only");

      const mainImage = document.getElementById("main-image");
      const images = getImages(vehicle);
      if (mainImage) {
        if (images.length > 0) {
          mainImage.src = images[0];
          mainImage.alt = title;
        } else {
          mainImage.style.display = "none";
        }
      }

      const thumbContainer = document.getElementById("thumbnail-gallery");
      if (thumbContainer) {
        thumbContainer.innerHTML = "";
        images.forEach((src, i) => {
          const img = document.createElement("img");
          img.src = src;
          img.alt = `${title} photo ${i + 1}`;
          img.style.cssText = "width:80px;height:60px;object-fit:cover;cursor:pointer;border:2px solid transparent;border-radius:4px;";
          img.addEventListener("click", () => {
            if (mainImage) mainImage.src = src;
          });
          thumbContainer.appendChild(img);
        });
      }

      const waBtn = document.getElementById("wa-button");
      if (waBtn) {
        const waText = encodeURIComponent(`Hello, I am interested in a similar vehicle: ${title} (Ref: ${vehicleRef})`);
        waBtn.onclick = () => window.open("https://wa.me/819076671825?text=" + waText, "_blank");
      }

      const rfqLink = document.getElementById("formal-rfq-link");
      if (rfqLink) rfqLink.href = "rfq.html?ref=" + encodeURIComponent(vehicleRef);
    })
    .catch(err => {
      console.error("Failed to load vehicle data:", err);
      const titleEl = document.getElementById("vehicle-title");
      if (titleEl) titleEl.textContent = "Vehicle data could not be loaded";
    });
});
