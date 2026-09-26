'use strict';

const fs = require('fs');
const path = require('path');

const PUBLIC_SCALAR_FIELDS = [
  'ref_id',
  'display_name_en',
  'year',
  'make',
  'model',
  'grade',
  'body_type',
  'fuel_type',
  'transmission',
  'mileage_km',
  'engine_cc',
  'reference_price_usd',
  'best_for_resale_in',
  'typical_buyer_use',
  'similar_units',
  'bulk_repeat_order',
];

function publicVehicleRecord(record) {
  const source = record && typeof record === 'object' && !Array.isArray(record) ? record : {};
  const projected = {};

  for (const field of PUBLIC_SCALAR_FIELDS) {
    const value = source[field];
    if (
      Object.prototype.hasOwnProperty.call(source, field)
      && (value === null || ['string', 'number', 'boolean'].includes(typeof value))
    ) {
      projected[field] = value;
    }
  }

  projected.gallery = Array.isArray(source.gallery)
    ? source.gallery.filter(value => typeof value === 'string')
    : [];

  return projected;
}

function internalFieldNames(decoded) {
  const names = [];
  const root = decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : {};
  for (const key of Object.keys(root)) {
    if (key !== 'vehicles') names.push(key);
  }
  const vehicles = Array.isArray(root.vehicles) ? root.vehicles : [];
  vehicles.forEach((record, index) => {
    const source = record && typeof record === 'object' && !Array.isArray(record) ? record : {};
    for (const key of Object.keys(source)) {
      if (key === 'gallery') {
        const gallery = source.gallery;
        if (!Array.isArray(gallery) || gallery.some(value => typeof value !== 'string')) {
          names.push(`vehicles[${index}].gallery`);
        }
        continue;
      }
      if (!PUBLIC_SCALAR_FIELDS.includes(key)) names.push(key);
    }
  });
  return [...new Set(names)];
}

function publicVehicleData(decoded) {
  const vehicles = decoded && Array.isArray(decoded.vehicles) ? decoded.vehicles : [];
  return { vehicles: vehicles.map(publicVehicleRecord) };
}

function build(inputPath, outputPath) {
  const decoded = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
  const internalFields = internalFieldNames(decoded);
  if (internalFields.length > 0) {
    process.stderr.write(`Refusing to publish vehicle data because internal fields are present: ${internalFields.join(', ')}\n`);
    process.exitCode = 1;
    return;
  }
  const projected = publicVehicleData(decoded);
  const temporaryPath = `${outputPath}.public-${process.pid}.tmp`;

  fs.writeFileSync(
    temporaryPath,
    `${JSON.stringify(projected, null, 2)}\n`,
    { encoding: 'utf8', mode: 0o600 },
  );
  fs.renameSync(temporaryPath, outputPath);
  process.stdout.write(`Netlify public vehicle feed prepared: ${projected.vehicles.length} records.\n`);
}

if (require.main === module) {
  const repositoryRoot = path.resolve(__dirname, '..');
  const inputPath = path.resolve(process.argv[2] || path.join(repositoryRoot, 'frontend/data/vehicles.json'));
  const outputPath = path.resolve(process.argv[3] || inputPath);
  build(inputPath, outputPath);
}

module.exports = { PUBLIC_SCALAR_FIELDS, internalFieldNames, publicVehicleData };
