(function() {
    emailjs.init("emILuwEDogn_nhsEV");
})();

function rfqValue(id) {
    const element = document.getElementById(id);
    return element ? element.value.trim() : "";
}

function showStatus(message, type) {
    const msg = document.getElementById("status-message");
    msg.style.display = "block";
    msg.className = `status-message ${type}`;
    msg.innerText = message;
}

function buildRFQSummary() {
    return `Dealer / Buyer Information\n` +
        `Buyer Type: ${rfqValue("buyer_type") || "Not specified"}\n` +
        `Company Name: ${rfqValue("company_name") || "Not specified"}\n` +
        `Monthly Purchase Volume: ${rfqValue("monthly_volume") || "Not specified"}\n` +
        `Target Resale Market: ${rfqValue("target_resale_market") || "Not specified"}\n\n` +
        `Vehicle / Sourcing Request\n` +
        `Make: ${rfqValue("make") || "Not specified"}\n` +
        `Model: ${rfqValue("model") || "Not specified"}\n` +
        `Preferred Year Range: ${rfqValue("year") || "Not specified"}\n` +
        `Preferred Mileage: ${rfqValue("mileage") || "Not specified"}\n` +
        `Target Budget per Unit in USD: ${rfqValue("budget") || "Not specified"}\n` +
        `Transmission: ${rfqValue("transmission") || "No preference"}\n` +
        `Intended Use: ${rfqValue("intended_use") || "Not specified"}\n` +
        `Purchase Timing: ${rfqValue("purchase_timing") || "Not specified"}\n` +
        `Preferred Trade Term: ${rfqValue("trade_term") || "Not specified"}\n` +
        `Need Auction Sheet: ${rfqValue("auction_sheet") || "Not specified"}\n` +
        `Preferred Models List: ${rfqValue("preferred_models") || "Not specified"}\n\n` +
        `Destination\n` +
        `Country: ${rfqValue("country") || "Not specified"}\n` +
        `Port: ${rfqValue("destination") || "Not specified"}\n\n` +
        `Contact Person\n` +
        `Name: ${rfqValue("name") || "Not specified"}\n` +
        `Email: ${rfqValue("email") || "Not specified"}\n` +
        `Phone / WhatsApp: ${rfqValue("phone") || "Not specified"}\n\n` +
        `Additional Message\n` +
        `${rfqValue("message") || "None"}`;
}

function validateRFQForm() {
    const form = document.getElementById("rfq-form");
    if (!form.checkValidity()) {
        form.reportValidity();
        return false;
    }
    return true;
}

document.getElementById("emailSubmitBtn").addEventListener("click", function(e) {
    e.preventDefault();

    if (!validateRFQForm()) {
        return;
    }

    const params = {
        name: rfqValue("name"),
        email: rfqValue("email"),
        phone: rfqValue("phone"),
        make: rfqValue("make"),
        model: rfqValue("model"),
        year: rfqValue("year"),
        budget: rfqValue("budget"),
        buyer_type: rfqValue("buyer_type"),
        company_name: rfqValue("company_name"),
        monthly_volume: rfqValue("monthly_volume"),
        target_resale_market: rfqValue("target_resale_market"),
        trade_term: rfqValue("trade_term"),
        auction_sheet: rfqValue("auction_sheet"),
        preferred_models: rfqValue("preferred_models"),
        destination_country: rfqValue("country"),
        destination_port: rfqValue("destination"),
        intended_use: rfqValue("intended_use"),
        transmission: rfqValue("transmission"),
        purchase_timing: rfqValue("purchase_timing"),
        mileage: rfqValue("mileage"),
        message: buildRFQSummary()
    };

    emailjs.send("service_beq9yfr", "template_902jnuk", params)
        .then(function() {
            showStatus("Your dealer RFQ has been sent successfully. We will contact you shortly.", "status-success");
        })
        .catch(function(error) {
            showStatus("Failed to send. Please try WhatsApp or contact us directly.", "status-error");
            console.error(error);
        });
});

document.addEventListener("DOMContentLoaded", function() {
    const params = new URLSearchParams(window.location.search);
    const prefillMap = {
        make: "make",
        model: "model",
        year: "year"
    };

    Object.entries(prefillMap).forEach(([paramName, elementId]) => {
        const value = params.get(paramName);
        const element = document.getElementById(elementId);
        if (value && element && !element.value) {
            element.value = value;
        }
    });

    const ref = params.get("ref");
    const message = document.getElementById("message");
    if (ref && message && !message.value) {
        message.value = `I am interested in sourcing a similar vehicle to reference ${ref}. Please prepare a current dealer quote and advise availability from Japanese auctions.`;
    }
});
