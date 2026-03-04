// Main Application
console.log('Reference Vehicle Marketplace - Loaded');

// Simple format functions
function formatCurrency(amount) {
  return '$' + amount.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function formatNumber(number) {
  return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
