document.addEventListener("DOMContentLoaded", () => {
  const urlParams = new URLSearchParams(window.location.search);
  const ref = urlParams.get("ref");

  const getTitle = (v) => v.display_name_en || [v.year, v.make, v.model, v.grade].filter(Boolean).join(" ");
  const getImages = (v) => Array.isArray(v.gallery) ? v.gallery : [];
  const getMileage = (v) => Number(v.mileage_km || 0);
  const getMileageDisplay = (v) => getMileage(v) ? `${getMileage(v).toLocaleString()} km` : "-";
  const getPrice = (v) => Number(v.reference_price_usd || 0);
  const getPriceDisplay = (v) => getPrice(v) ? `$${getPrice(v).toLocaleString()}` : "Ask";
  const DEFAULT_MARKETS = "Ghana, Nigeria, Benin, and Côte d'Ivoire";

  const formatResaleMarkets = (value) => {
    const raw = String(value || "").trim();
    if (!raw) {
      return `${DEFAULT_MARKETS}. Confirm import rules and destination requirements for each market.`;
    }
    const markets = raw
      .split(/\s*\/\s*|\s*,\s*/)
      .map((part) => part.trim())
      .filter(Boolean);
    const label = markets.length
      ? markets.length === 1
        ? markets[0]
        : `${markets.slice(0, -1).join(", ")}, and ${markets[markets.length - 1]}`
      : raw;
    return `${label}. Confirm import rules and destination requirements for each market.`;
  };

  const formatAuctionOrder = (v) => {
    const similar = String(v.similar_units || "").trim();
    const bulk = String(v.bulk_repeat_order || "").trim();
    if (similar && bulk) {
      return `${similar}. ${bulk} Similar units can be sourced from Japanese auctions based on target markets, budgets, and monthly volume.`;
    }
    if (similar) {
      return `${similar}. Similar units can be sourced from Japanese auctions based on target markets, budgets, and monthly volume.`;
    }
    if (bulk) {
      return `${bulk} Similar units can be sourced from Japanese auctions based on target markets, budgets, and monthly volume.`;
    }
    return "Similar units can be sourced from Japanese auctions based on target markets, budgets, and monthly volume.";
  };

  fetch(`data/vehicles.json?ts=${Date.now()}`, { cache: "no-store" })
    .then(response => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return response.json();
    })
    .then(data => {
      const vehicles = Array.isArray(data) ? data : (data.vehicles || []);
      const vehicle = vehicles.find(v => v.ref_id === ref);
      if (!vehicle) {
        const titleEl = document.getElementById("vehicle-title");
        if (titleEl) titleEl.textContent = "Vehicle not found";
        return;
      }

      const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val ?? "-"; };
      const title = getTitle(vehicle);
      const vehicleRef = vehicle.ref_id || "";

      set("vehicle-title", title);
      set("ref-id", vehicleRef);
      set("breadcrumb-title", title);
      set("spec-make", vehicle.make || "-");
      set("spec-model", [vehicle.model, vehicle.grade].filter(Boolean).join(" ") || vehicle.model || "-");
      set("spec-year", vehicle.year || "-");
      set("spec-engine", vehicle.engine_cc ? `${Number(vehicle.engine_cc).toLocaleString()} cc` : "-");
      set("spec-body-type", vehicle.body_type || "-");
      set("spec-fuel-type", vehicle.fuel_type || "-");
      set("spec-transmission", vehicle.transmission || "-");
      set("spec-mileage", getMileageDisplay(vehicle));
      set("spec-auction-grade", "To be confirmed before order");
      set("spec-repair-history", "To be confirmed from the latest auction sheet");
      set("reference-price", getPriceDisplay(vehicle));

      set("guidance-resale", formatResaleMarkets(vehicle.best_for_resale_in));
      set(
        "guidance-buyer-use",
        vehicle.typical_buyer_use || "Taxi, family, commercial, school, church, fleet, or delivery use depending on market demand."
      );
      set("guidance-auction-order", formatAuctionOrder(vehicle));

      const priceLabel = document.getElementById("reference-price-label");
      if (priceLabel) priceLabel.textContent = "FOB Price";

      const periodRow = document.getElementById("reference-period-row");
      if (periodRow) periodRow.style.display = "none";

      const mainImage = document.getElementById("main-image");
      const images = getImages(vehicle);
      if (mainImage) {
        if (images.length > 0) { mainImage.src = images[0]; mainImage.alt = title; }
        else { mainImage.style.display = "none"; }
      }

      const thumbContainer = document.getElementById("thumbnail-gallery");
      if (thumbContainer) {
        thumbContainer.innerHTML = "";
        images.forEach((src, i) => {
          const img = document.createElement("img");
          img.src = src;
          img.alt = `${title} photo ${i + 1}`;
          img.style.cssText = "width:80px;height:60px;object-fit:cover;cursor:pointer;border:2px solid transparent;border-radius:4px;";
          img.addEventListener("click", () => { if (mainImage) mainImage.src = src; });
          thumbContainer.appendChild(img);
        });
      }

      const waBtn = document.getElementById("wa-button");
      if (waBtn) {
        const waText = encodeURIComponent(`Hello Gloria Trading, I am interested in a similar vehicle: ${title} (Ref: ${vehicleRef}). Please prepare a current FOB, C&F, or CIF quote. My destination country/port, budget, preferred year range, mileage, and intended use are:`);
        waBtn.onclick = () => window.open("https://wa.me/819076671825?text=" + waText, "_blank");
      }
      const rfqLink = document.getElementById("formal-rfq-link");
      if (rfqLink) {
        const params = new URLSearchParams({
          ref: vehicleRef,
          make: vehicle.make || "",
          model: [vehicle.model, vehicle.grade].filter(Boolean).join(" ") || vehicle.model || "",
          year: vehicle.year || ""
        });
        rfqLink.href = "rfq.html?" + params.toString();
      }
    })
    .catch(err => {
      console.error("Failed to load vehicle data:", err);
      const titleEl = document.getElementById("vehicle-title");
      if (titleEl) titleEl.textContent = "Vehicle data could not be loaded";
    });
});
