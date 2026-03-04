// Form Validation
class FormValidator {
  static validateRFQ(formData) {
    if (!formData.year_from || !formData.year_to) {
      alert('Year range is required');
      return false;
    }

    if (parseInt(formData.year_from) > parseInt(formData.year_to)) {
      alert('Year From must be less than Year To');
      return false;
    }

    if (!formData.mileage_max) {
      alert('Max mileage is required');
      return false;
    }

    if (!formData.budget_from || !formData.budget_to) {
      alert('Budget range is required');
      return false;
    }

    if (parseInt(formData.budget_from) > parseInt(formData.budget_to)) {
      alert('Budget From must be less than Budget To');
      return false;
    }

    if (!formData.whatsapp_number) {
      alert('WhatsApp number is required');
      return false;
    }

    if (!formData.destination_country || !formData.destination_port) {
      alert('Destination is required');
      return false;
    }

    return true;
  }
}
