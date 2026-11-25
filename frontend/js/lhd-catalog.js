"use strict";

const lhdFeed = "data/lhd-vehicle-feed.php";
const lhdStatus = document.getElementById("lhd-status");
const lhdVehicles = document.getElementById("lhd-vehicles");
function appendText(parent, tag, value) {
    const element = document.createElement(tag);
    element.textContent = value;
    parent.append(element);
    return element;
}

function lhdCard(vehicle) {
    const card = document.createElement("article");
    card.className = "vehicle-card";
    const name = `${vehicle.year} ${vehicle.make} ${vehicle.model}`;
    if (vehicle.has_image) {
        const frame = document.createElement("div");
        frame.className = "vehicle-image";
        const image = document.createElement("img");
        image.src = `${lhdFeed}?image=${encodeURIComponent(vehicle.ref)}`;
        image.alt = name;
        image.loading = "lazy";
        frame.append(image);
        card.append(frame);
    }
    const detail = document.createElement("div");
    detail.className = "vehicle-card-inner";
    card.append(detail);
    appendText(detail, "h3", name);
    appendText(detail, "p", `${vehicle.ref} · Subject to Availability`);
    if (vehicle.mileage !== "") {
        appendText(detail, "p", `${Number(vehicle.mileage).toLocaleString("en")} km`);
    }
    if (typeof vehicle.price === "string") {
        const amount = Number(vehicle.price).toLocaleString("en", {
            minimumFractionDigits: vehicle.price.split(".")[1]?.length ?? 0,
            maximumFractionDigits: 2,
        });
        appendText(detail, "p", `${vehicle.currency} ${amount}`);
    } else {
        appendText(detail, "p", "Price on request");
    }
    if (vehicle.trade_term) {
        const term = vehicle.trade_term === "CFR" ? "C&F (CFR)" : vehicle.trade_term;
        appendText(detail, "p", [term, vehicle.destination_port].filter(Boolean).join(" · "));
    }
    const params = new URLSearchParams({
        sourcing: "uae-lhd", ref: vehicle.ref, make: vehicle.make, model: vehicle.model, year: vehicle.year,
    });
    const link = appendText(detail, "a", "Request a Quotation");
    link.className = "btn btn-primary";
    link.href = `rfq.html?${params}`;
    return card;
}

fetch(lhdFeed, { cache: "no-store" })
    .then(response => {
        if (!response.ok) throw new Error("LHD feed unavailable");
        return response.json();
    })
    .then(({ vehicles }) => {
        lhdVehicles.replaceChildren(...vehicles.map(lhdCard));
        lhdStatus.textContent = vehicles.length
            ? ""
            : "No vehicles are currently listed. Request a quotation for your preferred vehicle.";
    })
    .catch(() => {
        lhdStatus.textContent = "Vehicle selections are temporarily unavailable. You can still request a quotation.";
    });
