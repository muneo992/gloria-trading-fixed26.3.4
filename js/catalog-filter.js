document.addEventListener("DOMContentLoaded", () => {
  const vehicleGrid = document.getElementById("vehicle-grid");
  const resultsCount = document.getElementById("results-count");

  const filterMake = document.getElementById("filter-make");
  const filterModel = document.getElementById("filter-model"); // ← 追加
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
  let makeModelMap = {}; // ← Make → Model 対応表

  fetch("data/vehicles.json")
    .then(response => response.json())
    .then(data => {
      vehicles = data.vehicles;
      filteredVehicles = vehicles;

      buildMakeModelMap(); // ← 追加
      populateModelOptions(""); // 初期状態は All Models

      renderVehicles();
    });

  // -----------------------------
  // Make → Model 対応表を作成
  // -----------------------------
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

  // -----------------------------
  // Model セレクトを更新
  // -----------------------------
  function populateModelOptions(make) {
    filterModel.innerHTML = "";

    const optAll = document.createElement("option");
    optAll.value = "";
    optAll.textContent = "All Models";
    filterModel.appendChild(optAll);

    if (!make || !makeModelMap[make]) return;

    const models = Array.from(makeModelMap[make]).sort();
    models.forEach(m => {
      const opt = document.createElement("option");
      opt.value = m;
      opt.textContent = m;
      filterModel.appendChild(opt);
    });
  }

  // -----------------------------
  // 車両カード描画（あなたのコードそのまま）
  // -----------------------------
  function renderVehicles() {
    vehicleGrid.innerHTML = "";

    filteredVehicles.forEach(v => {
      const hasImage = v.gallery && v.gallery.length > 0;
      const imageSrc = hasImage ? v.gallery[0] : "";

      const card = document.createElement("div");
      card.className = "vehicle-card";

      card.innerHTML = `       
        <div class="vehicle-image ${hasImage ? "" : "no-image"}">
    ${ hasImage ? `<img src="${imageSrc}" alt="${v.display_name_en}">` : `<span>No Image</span>` }
  </div>

  <div class="vehicle-card-inner">

    <h3 class="vehicle-title">${v.display_name_en}</h3>

    <p class="ref-id">Ref ID: ${v.ref_id}</p>

    <p class="vehicle-meta">
      Year: ${v.year} | Mileage: ${v.mileage_km.toLocaleString()} km
    </p>

    <p class="vehicle-drive">Right-Hand Drive (RHD) Only</p>

    <div class="vehicle-price-block">
      <p class="price-label">Est. Price Range (USD)</p>
      <p class="price-range">$${v.price_low_usd.toLocaleString()} – $${v.price_high_usd.toLocaleString()}</p>
      <p class="price-basis">Basis: ${v.basis_from} – ${v.basis_to}</p>
    </div>

    <p class="vehicle-note">Not in stock. Past transaction example.</p>

    <a href="vehicle-detail.html?ref=${v.ref_id}" class="btn btn-primary btn-block">
      Request Quote for Similar
    </a>

  </div>
   `;


      vehicleGrid.appendChild(card);
    });

    resultsCount.textContent = `${filteredVehicles.length} vehicles found`;
  }

  // -----------------------------
  // フィルタ適用（Model 条件を追加）
  // -----------------------------
  function applyFilters() {
    filteredVehicles = vehicles.filter(v => {
      if (filterMake.value && v.make !== filterMake.value) return false;
      if (filterModel.value && v.model !== filterModel.value) return false; // ← 追加
      if (filterBody.value && v.body_type !== filterBody.value) return false;
      if (filterFuel.value && v.fuel_type !== filterFuel.value) return false;
      if (filterTransmission.value && v.transmission !== filterTransmission.value) return false;

      if (filterYearFrom.value && Number(v.year) < Number(filterYearFrom.value)) return false;
      if (filterYearTo.value && Number(v.year) > Number(filterYearTo.value)) return false;

      if (filterMileage.value && v.mileage_km > Number(filterMileage.value)) return false;

      return true;
    });

    applySorting();
    renderVehicles();
  }

  // -----------------------------
  // ソート（あなたのコードそのまま）
  // -----------------------------
  function applySorting() {
    const sortValue = sortSelect.value;

    if (sortValue === "price_asc") {
      filteredVehicles.sort((a, b) => a.price_low_usd - b.price_low_usd);
    } else if (sortValue === "price_desc") {
      filteredVehicles.sort((a, b) => b.price_high_usd - a.price_high_usd);
    } else if (sortValue === "year_desc") {
      filteredVehicles.sort((a, b) => Number(b.year) - Number(a.year));
    } else if (sortValue === "mileage_asc") {
      filteredVehicles.sort((a, b) => a.mileage_km - b.mileage_km);
    }
  }

  // -----------------------------
  // イベント
  // -----------------------------
  filterMake.addEventListener("change", () => {
    populateModelOptions(filterMake.value); // ← Make 変更時に Model 更新
    applyFilters();
  });

  filterModel.addEventListener("change", applyFilters); // ← 追加

  filterBody.addEventListener("change", applyFilters);
  filterFuel.addEventListener("change", applyFilters);
  filterTransmission.addEventListener("change", applyFilters);
  filterYearFrom.addEventListener("change", applyFilters);
  filterYearTo.addEventListener("change", applyFilters);
  filterMileage.addEventListener("input", applyFilters);
  sortSelect.addEventListener("change", applyFilters);

  resetFiltersBtn.addEventListener("click", () => {
    filterMake.value = "";
    filterModel.value = ""; // ← 追加
    filterBody.value = "";
    filterFuel.value = "";
    filterTransmission.value = "";
    filterYearFrom.value = "";
    filterYearTo.value = "";
    filterMileage.value = "";
    sortSelect.value = "";

    populateModelOptions(""); // Model をリセット

    filteredVehicles = vehicles;
    renderVehicles();
  });
});


