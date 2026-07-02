document.addEventListener("DOMContentLoaded", () => {
  const vehicleGrid = document.getElementById("vehicle-grid");
  const resultsCount = document.getElementById("results-count");

  const filterMake = document.getElementById("filter-make");
  const filterModel = document.getElementById("filter-model");
  const filterBody = document.getElementById("filter-body");
  const filterFuel = document.getElementById("filter-fuel");
  const filterTransmission = document.getElementById("filter-transmission");
  const filterYearFrom = document.getElementById("filter-year-from");
  const filterYearTo = document.getElementById("filter-year-to");
  const filterMileage = document.getElementById("filter-mileage");
  const sortSelect = document.getElementById("sort-select");
  const resetFiltersBtn = document.getElementById("reset-filters");

  let vehicles = [];
  let filteredVehicles = [];
  let makeModelMap = {};

  const getTitle = (v) => v.display_name_en || [v.year, v.make, v.model, v.grade].filter(Boolean).join(" ");
  const getImages = (v) => Array.isArray(v.gallery) ? v.gallery : [];
  const getMileage = (v) => Number(v.mileage_km || 0);
  const getMileageDisplay = (v) => getMileage(v) ? `${getMileage(v).toLocaleString()} km` : "-";
  const getPrice = (v) => Number(v.reference_price_usd || 0);
  const getPriceDisplay = (v) => getPrice(v) ? `USD ${getPrice(v).toLocaleString()}` : "Ask";
  const uniqueSorted = (items) => Array.from(new Set(items.filter(Boolean))).sort();

  if (resultsCount) resultsCount.textContent = "Loading dealer reference vehicles...";
  if (vehicleGrid) vehicleGrid.innerHTML = `<p class="text-muted">Loading dealer reference vehicles...</p>`;

  fetch(`/data/vehicles.json?ts=${Date.now()}`, { cache: "no-store" })
    .then(response => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return response.json();
    })
    .then(data => {
      vehicles = Array.isArray(data) ? data : (data.vehicles || []);
      filteredVehicles = [...vehicles];
      buildMakeModelMap();
      populateStaticFilters();
      populateModelOptions("");
      applySorting();
      renderVehicles();
    })
    .catch(error => {
      console.error("Failed to load vehicle catalog:", error);
      if (resultsCount) resultsCount.textContent = "Vehicle data could not be loaded";
      if (vehicleGrid) vehicleGrid.innerHTML = `<p class="text-muted">Vehicle data could not be loaded. Please try again later.</p>`;
    });

  function appendOptions(select, values, placeholderText = "-- Any --") {
    if (!select) return;
    select.innerHTML = "";
    const optAll = document.createElement("option");
    optAll.value = "";
    optAll.textContent = placeholderText;
    select.appendChild(optAll);
    values.forEach(value => {
      const opt = document.createElement("option");
      opt.value = value;
      opt.textContent = value;
      select.appendChild(opt);
    });
  }

  function populateStaticFilters() {
    appendOptions(filterMake, uniqueSorted(vehicles.map(v => v.make)), "-- Any --");
    appendOptions(filterBody, uniqueSorted(vehicles.map(v => v.body_type)), "-- Any --");
    appendOptions(filterFuel, uniqueSorted(vehicles.map(v => v.fuel_type)), "-- Any --");
    appendOptions(filterTransmission, uniqueSorted(vehicles.map(v => v.transmission)), "-- Any --");
  }

  function buildMakeModelMap() {
    makeModelMap = {};
    vehicles.forEach(v => {
      const make = v.make?.trim();
      const model = v.model?.trim();
      if (!make || !model) return;
      if (!makeModelMap[make]) makeModelMap[make] = new Set();
      makeModelMap[make].add(model);
    });
  }

  function populateModelOptions(make) {
    if (!filterModel) return;
    const models = make && makeModelMap[make]
      ? Array.from(makeModelMap[make]).sort()
      : uniqueSorted(vehicles.map(v => v.model));
    appendOptions(filterModel, models, make ? "All Models" : "-- Any --");
  }

  function renderVehicles() {
    if (!vehicleGrid || !resultsCount) return;
    vehicleGrid.innerHTML = "";
    if (filteredVehicles.length === 0) {
      vehicleGrid.innerHTML = `<p class="text-muted">No reference vehicles match your current filters. Please reset filters or submit a Dealer RFQ for auction sourcing.</p>`;
      resultsCount.textContent = "No matching reference vehicles";
      return;
    }

    filteredVehicles.forEach(v => {
      const images = getImages(v);
      const imageSrc = images.length > 0 ? images[0] : "";
      const hasImage = Boolean(imageSrc);
      const title = getTitle(v);
      const ref = v.ref_id || "";
      const priceDisplay = getPriceDisplay(v);
      const modelName = v.model || "this model";
      const resaleMarkets = v.resale_markets || "Ghana / Nigeria / Benin / Cote d'Ivoire";
      const typicalBuyerUse = v.typical_buyer_use || "Retail resale, private use, or fleet demand";

      const card = document.createElement("div");
      card.className = "vehicle-card";
      card.innerHTML = `
        <div class="vehicle-image ${hasImage ? "" : "no-image"}">
          ${hasImage ? `<img src="${imageSrc}" alt="${title}">` : `<span>No Image</span>`}
        </div>
        <div class="vehicle-card-inner">
          <h3 class="vehicle-title">${title}</h3>
          <p class="ref-id">Ref ID: ${ref}</p>
          <p class="vehicle-meta">Year: ${v.year || "-"} | Mileage: ${getMileageDisplay(v)}</p>
          <p class="vehicle-meta">${v.body_type || "-"} | ${v.fuel_type || "-"} | ${v.transmission || "-"}</p>
          <p class="vehicle-drive">Right-Hand Drive (RHD) Only</p>
          <div class="vehicle-price-block">
            <p class="price-label">Dealer Reference Price (USD, FOB Japan)</p>
            <p class="price-range">${priceDisplay}</p>
            <p class="price-basis">Past transaction example for resale planning</p>
          </div>
          <table class="spec-table" style="font-size: 0.82rem; margin: 0.9rem 0;">
            <tr><td class="spec-label">Best for resale in</td><td class="spec-value">${resaleMarkets}</td></tr>
            <tr><td class="spec-label">Typical buyer use</td><td class="spec-value">${typicalBuyerUse}</td></tr>
            <tr><td class="spec-label">Similar units</td><td class="spec-value">Auction sourcing available for ${modelName}</td></tr>
            <tr><td class="spec-label">Bulk / repeat order</td><td class="spec-value">Dealer RFQ supported</td></tr>
          </table>
          <p class="vehicle-note">Not in stock. Similar units can be sourced from Japanese auctions for dealer, importer, or fleet orders.</p>
          <a href="vehicle-detail.html?ref=${encodeURIComponent(ref)}" class="btn btn-primary btn-block">Request Dealer Quote for Similar</a>
        </div>`;
      vehicleGrid.appendChild(card);
    });
    resultsCount.textContent = `${filteredVehicles.length} vehicles found`;
  }

  function applyFilters() {
    filteredVehicles = vehicles.filter(v => {
      if (filterMake?.value && v.make !== filterMake.value) return false;
      if (filterModel?.value && v.model !== filterModel.value) return false;
      if (filterBody?.value && v.body_type !== filterBody.value) return false;
      if (filterFuel?.value && v.fuel_type !== filterFuel.value) return false;
      if (filterTransmission?.value && v.transmission !== filterTransmission.value) return false;
      if (filterYearFrom?.value && Number(v.year) < Number(filterYearFrom.value)) return false;
      if (filterYearTo?.value && Number(v.year) > Number(filterYearTo.value)) return false;
      if (filterMileage?.value && getMileage(v) > Number(filterMileage.value)) return false;
      return true;
    });
    applySorting();
    renderVehicles();
  }

  function applySorting() {
    const sortValue = sortSelect?.value || "";
    if (sortValue === "price_asc") filteredVehicles.sort((a, b) => getPrice(a) - getPrice(b));
    else if (sortValue === "price_desc") filteredVehicles.sort((a, b) => getPrice(b) - getPrice(a));
    else if (sortValue === "year_desc") filteredVehicles.sort((a, b) => Number(b.year || 0) - Number(a.year || 0));
    else if (sortValue === "mileage_asc") filteredVehicles.sort((a, b) => getMileage(a) - getMileage(b));
  }

  filterMake?.addEventListener("change", () => { populateModelOptions(filterMake.value); if (filterModel) filterModel.value = ""; applyFilters(); });
  filterModel?.addEventListener("change", applyFilters);
  filterBody?.addEventListener("change", applyFilters);
  filterFuel?.addEventListener("change", applyFilters);
  filterTransmission?.addEventListener("change", applyFilters);
  filterYearFrom?.addEventListener("change", applyFilters);
  filterYearTo?.addEventListener("change", applyFilters);
  filterMileage?.addEventListener("input", applyFilters);
  sortSelect?.addEventListener("change", applyFilters);
  resetFiltersBtn?.addEventListener("click", () => {
    if (filterMake) filterMake.value = "";
    if (filterBody) filterBody.value = "";
    if (filterFuel) filterFuel.value = "";
    if (filterTransmission) filterTransmission.value = "";
    if (filterYearFrom) filterYearFrom.value = "";
    if (filterYearTo) filterYearTo.value = "";
    if (filterMileage) filterMileage.value = "";
    if (sortSelect) sortSelect.value = "";
    populateModelOptions("");
    if (filterModel) filterModel.value = "";
    filteredVehicles = [...vehicles];
    renderVehicles();
  });
});
