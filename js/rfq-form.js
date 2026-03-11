(function() {
    emailjs.init("emILuwEDogn_nhsEV");
})();

document.getElementById("emailSubmitBtn").addEventListener("click", function(e) {
    e.preventDefault();

    const params = {
        name: document.getElementById("name").value,
        email: document.getElementById("email").value,
        model: document.getElementById("model").value,
        year: document.getElementById("year").value,
        message: document.getElementById("message").value
    };

    emailjs.send("service_beq9yfr", "template_902jnuk", params)
        .then(function() {
            const msg = document.getElementById("status-message");
            msg.style.display = "block";
            msg.className = "status-message status-success";
            msg.innerText = "Your message has been sent successfully!";
        })
        .catch(function(error) {
            const msg = document.getElementById("status-message");
            msg.style.display = "block";
            msg.className = "status-message status-error";
            msg.innerText = "Failed to send. Please try again.";
            console.error(error);
        });
});
