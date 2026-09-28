'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', 'south-africa');
const BASE = 'https://sa.gloriatrading.com/';
const FORBIDDEN = /preferential Import Permit|will be accepted by Uber|industry-leading|number one exporter|best quality|unmatched experience/i;

function walkHtml(dir) {
  return fs.readdirSync(dir).filter(name => name.endsWith('.html')).map(name => path.join(dir, name));
}

function hrefs(html) {
  const refs = [];
  const re = /\s(?:src|href)="([^"]+)"/g;
  let match;
  while ((match = re.exec(html))) refs.push(match[1]);
  return refs;
}

const pages = walkHtml(ROOT).filter(file => path.basename(file) !== 'site-suspended.html');
if (!pages.length) throw new Error('No South Africa HTML pages');
for (const file of pages) {
  const raw = fs.readFileSync(file, 'utf8');
  if ((raw.match(/<h1[\s>]/g) || []).length !== 1) throw new Error(`h1 count ${file}`);
  if (!raw.includes('rel="canonical"') || !raw.includes(BASE)) throw new Error(`canonical ${file}`);
  if (!raw.includes('sa.gloriatrading.com')) throw new Error(`canonical host ${file}`);
  if (FORBIDDEN.test(raw)) throw new Error(`forbidden claim ${file}`);
  if (!raw.includes('Import requirements must be confirmed before shipment')) {
    throw new Error(`import wording ${file}`);
  }
  const ids = [...raw.matchAll(/\sid="([^"]+)"/g)].map(m => m[1]);
  if (new Set(ids).size !== ids.length) throw new Error(`duplicate id ${file}`);
  for (const ref of hrefs(raw)) {
    if (/^[a-z]+:/i.test(ref) || ref.startsWith('//') || ref.startsWith('#')) continue;
    const target = path.resolve(path.dirname(file), decodeURIComponent(ref.split('#')[0] || '.'));
    const rel = path.relative(ROOT, target);
    if (rel.startsWith('..')) throw new Error(`escape ${file} ${ref}`);
    if (!fs.existsSync(target)) throw new Error(`missing ${file} ${ref}`);
  }
}

const vehicles = JSON.parse(fs.readFileSync(path.join(ROOT, 'data', 'vehicles.json'), 'utf8')).vehicles;
const ids = new Set();
for (const v of vehicles) {
  if (!v.ref_id || ids.has(v.ref_id) || !v.make || !v.model) throw new Error(`vehicle ${v.ref_id}`);
  ids.add(v.ref_id);
  if (v.listing_type !== 'sample' && v.listing_type !== 'available') throw new Error(`listing_type ${v.ref_id}`);
  if (v.status !== 'published' && v.status !== 'draft') throw new Error(`status ${v.ref_id}`);
  for (const key of ['year', 'mileage_km', 'battery', 'range', 'reference_price_usd', 'video_url']) {
    const value = v[key];
    if (!(value === null || value === '' || (Array.isArray(value) && value.length === 0))) {
      throw new Error(`invented field ${v.ref_id} ${key}`);
    }
  }
  for (const src of v.gallery || []) {
    if (!src.startsWith('images/') || src.includes('..')) throw new Error(src);
    if (!fs.existsSync(path.join(ROOT, src))) throw new Error(src);
  }
}

const js = fs.readFileSync(path.join(ROOT, 'js', 'sa.js'), 'utf8');
if (!js.includes('Example vehicle — not current stock')) throw new Error('sample wording');
if (!fs.existsSync(path.join(ROOT, 'admin', 'index.php'))) throw new Error('admin missing');
if (!fs.existsSync(path.join(ROOT, 'lib', 'admin-auth.php'))) throw new Error('admin auth missing');
if (fs.existsSync(path.join(ROOT, 'admin-password.hash')) || fs.existsSync(path.join(ROOT, 'lib', 'admin-password.hash'))) {
  throw new Error('password hash must not be in the public tree');
}

const sitemap = fs.readFileSync(path.join(ROOT, 'sitemap.xml'), 'utf8');
const locs = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]);
if (!locs.length) throw new Error('empty sitemap');
for (const loc of locs) {
  if (!loc.startsWith(BASE)) throw new Error(loc);
  const file = loc.slice(BASE.length) || 'index.html';
  if (!fs.existsSync(path.join(ROOT, file))) throw new Error(loc);
}

const robots = fs.readFileSync(path.join(ROOT, 'robots.txt'), 'utf8');
if (!robots.includes('Disallow: /')) throw new Error('robots disallow');
if (/^Allow:/m.test(robots) || robots.includes('Sitemap:')) throw new Error('robots still publishes the site');

const suspension = fs.readFileSync(path.join(ROOT, 'site-suspended.html'), 'utf8');
for (const line of ['Gloria Trading', 'South Africa Site', 'This site is currently under review.', 'noindex, nofollow']) {
  if (!suspension.includes(line)) throw new Error(`suspension page missing ${line}`);
}
if ((suspension.match(/<h1[\s>]/g) || []).length !== 1) throw new Error('suspension h1');
if (/RHD|PHEV|\bEV\b|Uber|auction|import|WhatsApp|vehicle/i.test(suspension)) {
  throw new Error('suspension page contains sales wording');
}

const preserved = [
  'index.html', 'vehicles.html', 'vehicle-detail.html', 'about.html', 'contact.html',
  'request.html', 'how-to-buy.html', 'sitemap.xml', 'js/sa.js', 'data/vehicles.json',
  'data/vehicle-feed.php', 'admin/index.php', 'admin/edit.php', 'admin/bootstrap.php',
  'lib/admin-auth.php', 'lib/vehicle-store.php'
];
for (const file of preserved) {
  if (!fs.existsSync(path.join(ROOT, file))) throw new Error(`preserved file missing ${file}`);
}
const home = fs.readFileSync(path.join(ROOT, 'index.html'), 'utf8');
if (!home.includes('RHD EV')) throw new Error('original home page is no longer preserved');

const htaccess = fs.readFileSync(path.join(ROOT, '.htaccess'), 'utf8');
const suspensionRule = htaccess.indexOf('RewriteRule ^ /site-suspended.html [END]');
const feedRule = htaccess.indexOf('RewriteRule ^data/vehicles\\.json$ data/vehicle-feed.php [L]');
if (suspensionRule < 0 || !htaccess.includes('RewriteRule ^robots\\.txt$ - [END]')) {
  throw new Error('suspension rewrite missing');
}
if (!htaccess.includes('ErrorDocument 403 /site-suspended.html')) throw new Error('forbidden pages are not suspended');
if (!htaccess.includes('Header set X-Robots-Tag "noindex, nofollow"')) throw new Error('robots header missing');
if (!htaccess.includes('(?i)^(vehicles\\.json|vehicle-feed\\.php|vehicle-image\\.php)$')) {
  throw new Error('vehicle data files are not denied');
}
if (feedRule < 0 || suspensionRule > feedRule) throw new Error('vehicle feed is reachable during suspension');
const adminHtaccess = fs.readFileSync(path.join(ROOT, 'admin', '.htaccess'), 'utf8');
if (!adminHtaccess.includes('Require all denied')) throw new Error('admin is still granted');
const imageHtaccess = fs.readFileSync(path.join(ROOT, 'images', '.htaccess'), 'utf8');
const imageSuspension = imageHtaccess.indexOf('RewriteRule ^ /site-suspended.html [END]');
const imageFeed = imageHtaccess.indexOf('/data/vehicle-image.php');
if (imageSuspension < 0 || imageFeed < 0 || imageSuspension > imageFeed) {
  throw new Error('image delivery is reachable during suspension');
}

console.log(`SA checks passed: ${pages.length} preserved pages, ${vehicles.length} example types, public site suspended.`);
