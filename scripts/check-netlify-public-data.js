'use strict';

const assert = require('assert');
const { publicVehicleData } = require('./build-netlify-public-data');

// Synthetic fixture only. No operational vehicle, customer, or cost data is read.
const projected = publicVehicleData({
  vehicles: [{
    ref_id: 'TEST-001',
    display_name_en: 'Synthetic Vehicle',
    make: 'Example',
    model: 'Fixture',
    reference_price_usd: 12345,
    gallery: ['images/vehicles/synthetic.jpg', { invalid: true }],
    quote_spec_data: {
      purchase_price_usd: 111,
      profit_usd: 222,
      customer_name: 'Synthetic Customer',
    },
    export_document_data: { consignee_name: 'Synthetic Consignee' },
    vehicle_certificate_data: { owner_name: 'Synthetic Owner' },
    quote_spec_files: ['uploads/quotes/synthetic.pdf'],
  }],
});

assert.strictEqual(projected.vehicles.length, 1);
assert.strictEqual(projected.vehicles[0].ref_id, 'TEST-001');
assert.deepStrictEqual(projected.vehicles[0].gallery, ['images/vehicles/synthetic.jpg']);

for (const field of [
  'quote_spec_data',
  'export_document_data',
  'vehicle_certificate_data',
  'quote_spec_files',
  'quote_image_files',
  'vehicle_certificate_files',
]) {
  assert.ok(!Object.prototype.hasOwnProperty.call(projected.vehicles[0], field));
}

process.stdout.write('Netlify public data protection checks passed.\n');
