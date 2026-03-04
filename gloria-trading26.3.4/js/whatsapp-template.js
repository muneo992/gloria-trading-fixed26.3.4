function sendWhatsAppRFQ() {
    const data = {
        name: document.getElementById("name").value,
        email: document.getElementById("email").value,
        model: document.getElementById("model").value,
        year: document.getElementById("year").value,
        message: document.getElementById("message").value
    };

    const text =
`RFQ Request
Model: ${data.model}
Year: ${data.year}

Name: ${data.name}
Email: ${data.email}

Message:
${data.message}`;

    const phone = "819076671825";
    const url = `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;

    window.open(url, "_blank");
}

document.getElementById("whatsappBtn").addEventListener("click", function() {
    sendWhatsAppRFQ();

    const msg = document.getElementById("status-message");
    msg.style.display = "block";
    msg.className = "status-message status-success";
    msg.innerText = "WhatsApp message window opened!";
});
