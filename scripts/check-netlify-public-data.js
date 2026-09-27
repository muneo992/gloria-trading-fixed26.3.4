'use strict';

const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { internalFieldNames, publicVehicleData } = require('./build-netlify-public-data');

const internal = internalFieldNames({
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

for (const field of [
  'quote_spec_data',
  'export_document_data',
  'vehicle_certificate_data',
  'quote_spec_files',
]) {
  assert.ok(internal.includes(field), `internal field must fail the build: ${field}`);
}
assert.ok(internal.some(name => name.endsWith('.gallery')));
assert.ok(!internal.join('\n').includes('Synthetic Customer'));
assert.ok(!internal.join('\n').includes('111'));

const clean = publicVehicleData({
  vehicles: [{
    ref_id: 'TEST-001',
    display_name_en: 'Synthetic Vehicle',
    reference_price_usd: 12345,
    gallery: ['images/vehicles/synthetic.jpg'],
  }],
});
assert.deepStrictEqual(internalFieldNames(clean), []);
assert.strictEqual(clean.vehicles[0].reference_price_usd, 12345);

const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'gloria-netlify-'));
const inputPath = path.join(directory, 'vehicles.json');
fs.writeFileSync(inputPath, `${JSON.stringify({
  vehicles: [{ ref_id: 'TEST-001', quote_spec_data: { auction_price_jpy: 111 } }],
}, null, 2)}\n`);
const outputPath = path.join(directory, 'out.json');
const { spawnSync } = require('child_process');
const failed = spawnSync(process.execPath, [
  path.join(__dirname, 'build-netlify-public-data.js'),
  inputPath,
  outputPath,
], { encoding: 'utf8' });
assert.notStrictEqual(failed.status, 0);
assert.match(failed.stderr, /internal fields are present: quote_spec_data/);
assert.ok(!failed.stderr.includes('111'));
assert.ok(!fs.existsSync(outputPath));

process.stdout.write('Netlify public data protection checks passed.\n');
