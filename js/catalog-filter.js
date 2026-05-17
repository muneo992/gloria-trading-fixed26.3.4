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

  const getRef = (v) => v.ref || v.ref_id || "";
  const getTitle = (v) => v.title || v.display_name_en || [v.year, v.make, v.model, v.grade].filter(Boolean).join(" ");
  const getImages = (v) => v.images || v.gallery || (v.image_main ? [v.image_main] : []);
  const getMileage = (v) => Number(v.mileage ?? v.mileage_km ?? 0);
  const getMileageDisplay = (v) => v.mileage_display || (getMileage(v) ? `${getMileage(v).toLocaleString()} km` : "-");
  const getBody = (v) => v.body || v.body_type || "-";
  const getFuel = (v) => v.fuel || v.fuel_type || "-";
  const getPrice = (v) => Number(v.price_usd ?? v.price_low_usd ?? 0);
  const getPriceDisplay = (v) => v.price_display || (getPrice(v) ? `USD ${getPrice(v).toLocaleString()}` : "Ask");
  const uniqueSorted = (items) => Array.from(new Set(items.filter(Boolean))).sort();

  fetch("data/vehicles.json")
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
    appendOptions(filterBody, uniqueSorted(vehicles.map(getBody)), "-- Any --");
    appendOptions(filterFuel, uniqueSorted(vehicles.map(getFuel)), "-- Any --");
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
      vehicleGrid.innerHTML = `<p class="text-muted">No vehicles match the selected filters.</p>`;
      resultsCount.textContent = "0 vehicles found";
      return;
    }

    filteredVehicles.forEach(v => {
      const images = getImages(v);
      const imageSrc = images.length > 0 ? images[0] : "";
      const hasImage = Boolean(imageSrc);
      const title = getTitle(v);
      const ref = getRef(v);
      const priceDisplay = getPriceDisplay(v);

      const card = document.createElement("div");
      card.className = "vehicle-card";

      card.innerHTML = `
        <div class="vehicle-image ${hasImage ? "" : "no-image"}">
          ${hasImage ? `<img src="${imageSrc}" alt="${title}">` : `<span>No Image</span>`}
        </div>
        <div class="vehicle-card-inner">
          <h3 class="vehicle-title">${title}</h3>
          <p class="ref-id">Ref ID: ${ref}</p>
          <p class="vehicle-meta">
            Year: ${v.year || "-"} | Mileage: ${getMileageDisplay(v)}
          </p>
          <p class="vehicle-meta">
            ${getBody(v)} | ${getFuel(v)} | ${v.transmission || "-"}
          </p>
          <p class="vehicle-drive">Right-Hand Drive (RHD) Only</p>
          <div class="vehicle-price-block">
            <p class="price-label">Reference Price (USD, FOB Japan)</p>
            <p class="price-range">${priceDisplay}</p>
            <p class="price-basis">Past transaction example</p>
          </div>
          <p class="vehicle-note">Not in stock. Past transaction example.</p>
          <a href="vehicle-detail.html?ref=${encodeURIComponent(ref)}" class="btn btn-primary btn-block">
            Request Quote for Similar
          </a>
        </div>
      `;

      vehicleGrid.appendChild(card);
    });

    resultsCount.textContent = `${filteredVehicles.length} vehicles found`;
  }

  function applyFilters() {
    filteredVehicles = vehicles.filter(v => {
      if (filterMake?.value && v.make !== filterMake.value) return false;
      if (filterModel?.value && v.model !== filterModel.value) return false;
      if (filterBody?.value && getBody(v) !== filterBody.value) return false;
      if (filterFuel?.value && getFuel(v) !== filterFuel.value) return false;
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
    if (sortValue === "price_asc") {
      filteredVehicles.sort((a, b) => getPrice(a) - getPrice(b));
    } else if (sortValue === "price_desc") {
      filteredVehicles.sort((a, b) => getPrice(b) - getPrice(a));
    } else if (sortValue === "year_desc") {
      filteredVehicles.sort((a, b) => Number(b.year || 0) - Number(a.year || 0));
    } else if (sortValue === "mileage_asc") {
      filteredVehicles.sort((a, b) => getMileage(a) - getMileage(b));
    }
  }

  filterMake?.addEventListener("change", () => {
    populateModelOptions(filterMake.value);
    if (filterModel) filterModel.value = "";
    applyFilters();
  });
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
