function getRFQValue(id) {
    const element = document.getElementById(id);
    return element ? element.value.trim() : "";
}

function formatRFQValue(value, fallback = "Not specified") {
    return value || fallback;
}

function sendWhatsAppRFQ() {
    const form = document.getElementById("rfq-form");
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const text =
`Dealer RFQ Request - Gloria Trading

Dealer / Buyer Information
Buyer Type: ${formatRFQValue(getRFQValue("buyer_type"))}
Company Name: ${formatRFQValue(getRFQValue("company_name"))}
Monthly Purchase Volume: ${formatRFQValue(getRFQValue("monthly_volume"))}
Target Resale Market: ${formatRFQValue(getRFQValue("target_resale_market"))}

Vehicle / Sourcing Request
Make: ${formatRFQValue(getRFQValue("make"))}
Model: ${formatRFQValue(getRFQValue("model"))}
Preferred Year Range: ${formatRFQValue(getRFQValue("year"))}
Preferred Mileage: ${formatRFQValue(getRFQValue("mileage"))}
Target Budget per Unit in USD: ${formatRFQValue(getRFQValue("budget"))}
Transmission: ${formatRFQValue(getRFQValue("transmission"), "No preference")}
Intended Use: ${formatRFQValue(getRFQValue("intended_use"))}
Purchase Timing: ${formatRFQValue(getRFQValue("purchase_timing"))}
Preferred Trade Term: ${formatRFQValue(getRFQValue("trade_term"))}
Need Auction Sheet: ${formatRFQValue(getRFQValue("auction_sheet"))}
Preferred Models List: ${formatRFQValue(getRFQValue("preferred_models"))}

Destination
Country: ${formatRFQValue(getRFQValue("country"))}
Port: ${formatRFQValue(getRFQValue("destination"))}

Contact Person
Name: ${formatRFQValue(getRFQValue("name"))}
Email: ${formatRFQValue(getRFQValue("email"))}
Phone / WhatsApp: ${formatRFQValue(getRFQValue("phone"))}

Additional Message
${formatRFQValue(getRFQValue("message"), "None")}`;

    const phone = "819076671825";
    const url = `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;

    window.open(url, "_blank");

    const msg = document.getElementById("status-message");
    msg.style.display = "block";
    msg.className = "status-message status-success";
    msg.innerText = "Dealer RFQ WhatsApp message window opened!";
}

document.getElementById("whatsappBtn").addEventListener("click", function() {
    sendWhatsAppRFQ();
});
