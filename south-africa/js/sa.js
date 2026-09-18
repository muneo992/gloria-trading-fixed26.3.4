'use strict';
const navToggle = document.getElementById('navToggle');
const navMenu = document.getElementById('navMenu');
navToggle.addEventListener('click', () => {
  const open = navMenu.classList.toggle('open');
  navToggle.setAttribute('aria-expanded', String(open));
  navToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && navMenu.classList.contains('open')) {
    navMenu.classList.remove('open');
    navToggle.setAttribute('aria-expanded', 'false');
    navToggle.setAttribute('aria-label', 'Open menu');
    navToggle.focus();
  }
});
const form = document.getElementById('request-form');
if (form) {
  const params = new URLSearchParams(location.search);
  if (params.get('model') && form.elements.model) {
    form.elements.model.value = params.get('model').slice(0, 150);
  }
  if (params.get('ref') && form.elements.additional) {
    const existing = form.elements.additional.value.trim();
    const note = `Example type: ${params.get('ref').slice(0, 100)}`;
    form.elements.additional.value = existing ? `${existing}\n${note}` : note;
  }
  if (params.get('powertrain') && form.elements.powertrain) {
    form.elements.powertrain.value = params.get('powertrain').slice(0, 40);
  }
  const labels = {
    name: 'Name',
    city: 'City / area',
    whatsapp: 'WhatsApp',
    model: 'Vehicle / model',
    year: 'Year range',
    mileage: 'Maximum mileage (km)',
    powertrain: 'EV or PHEV',
    doors: 'Body / doors',
    range: 'Driving range preference',
    intended_use: 'Intended use',
    budget: 'Target budget (USD)',
    additional: 'Additional request'
  };
  function send(via) {
    for (const field of form.querySelectorAll('[required]')) {
      field.value = field.value.trim();
    }
    if (!form.reportValidity()) return;
    const lines = ['Hello Gloria Trading,', '', 'South Africa vehicle request', 'RHD EV / PHEV from Japan'];
    for (const [key, label] of Object.entries(labels)) {
      const field = form.elements[key];
      if (!field) continue;
      const value = String(field.value || '').trim();
      if (value) lines.push(`${label}: ${value}`);
    }
    const body = encodeURIComponent(lines.join('\n'));
    const url = via === 'email'
      ? `mailto:info@gloriatrading.com?subject=${encodeURIComponent('South Africa vehicle request')}&body=${body}`
      : `https://wa.me/819076671825?text=${body}`;
    location.href = url;
    document.getElementById('request-status').textContent = 'Review your message in the app and press send there.';
  }
  form.addEventListener('submit', e => { e.preventDefault(); send('whatsapp'); });
  document.getElementById('email-request').addEventListener('click', () => send('email'));
}
const grid = document.getElementById('vehicle-grid');
const detail = document.getElementById('vehicle-detail');
function element(tag, text, className) {
  const el = document.createElement(tag);
  if (text !== undefined) el.textContent = text;
  if (className) el.className = className;
  return el;
}
function link(text, url, className = 'btn btn-primary') {
  const el = element('a', text, className);
  el.href = url;
  return el;
}
function title(v) {
  return v.display_name_en || [v.year, v.make, v.model].filter(Boolean).join(' ');
}
function provided(value) {
  return value !== null && value !== undefined && String(value).trim() !== '';
}
function positive(value) {
  return value !== null && value !== '' && Number.isFinite(Number(value)) && Number(value) > 0;
}
function price(value) {
  return positive(value) ? `USD ${Number(value).toLocaleString('en-US')}` : '';
}
function mileage(v) {
  return positive(v.mileage_km) ? `${Number(v.mileage_km).toLocaleString('en-US')} km` : '';
}
function requestUrl(v) {
  return `request.html?${new URLSearchParams({
    model: [v.make, v.model].filter(Boolean).join(' '),
    ref: v.ref_id,
    powertrain: v.powertrain || ''
  })}`;
}
function photo(src, alt) {
  if (typeof src !== 'string' || !/^images\/[a-zA-Z0-9_./-]+\.(jpg|jpeg|png|webp)$/i.test(src) || src.includes('..')) return null;
  const image = element('img');
  image.src = src;
  image.alt = alt;
  image.loading = 'lazy';
  image.addEventListener('error', () => image.replaceWith(element('p', 'Photo unavailable. Request current photos.')));
  return image;
}
function safeVideoUrl(src) {
  if (typeof src !== 'string') return null;
  try {
    const url = new URL(src);
    if (url.protocol !== 'https:') return null;
    const host = url.hostname.toLowerCase();
    const allowed = ['youtube.com', 'www.youtube.com', 'youtu.be', 'www.youtu.be', 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];
    return allowed.includes(host) ? url.href : null;
  } catch {
    return null;
  }
}
function isSample(v) {
  return v.listing_type !== 'available';
}
function listingLabel(v) {
  return isSample(v)
    ? 'Example vehicle — not current stock.'
    : 'Japanese auction candidate. Availability must be confirmed.';
}
function prices(v) {
  const block = element('div', undefined, 'price-lines');
  const amount = price(v.reference_price_usd);
  if (amount) {
    block.append(element('p', isSample(v) ? `Reference FOB Japan: ${amount}` : `FOB Japan: ${amount}`));
    if (v.price_as_of) block.append(element('p', `Price reference date: ${v.price_as_of}`));
    if (isSample(v)) block.append(element('p', 'Price and availability may change.'));
  } else {
    block.append(element('p', 'Request a quotation'));
  }
  block.append(element('p', 'Gloria Trading searches Japanese auctions for a matching vehicle. Import requirements must be confirmed before shipment.'));
  return block;
}
function notice(target, message) {
  const panel = element('div', undefined, 'empty-state');
  panel.append(element('p', message), link('Request a Vehicle', 'request.html'));
  target.replaceChildren(panel);
}
function specRows(v) {
  const rows = {
    'Make / Model': [v.make, v.model].filter(Boolean).join(' '),
    'Year': provided(v.year) ? v.year : null,
    'Mileage': mileage(v) || null,
    'Powertrain': provided(v.powertrain) ? v.powertrain : null,
    'Battery capacity': provided(v.battery) ? v.battery : null,
    'Driving range': provided(v.range) ? v.range : null,
    'Body': provided(v.body) ? v.body : null,
    'Doors': provided(v.doors) ? v.doors : null,
    'Transmission': provided(v.transmission) ? v.transmission : null,
    'Steering': provided(v.steering) ? v.steering : null
  };
  return rows;
}
function appendMeta(card, label, value) {
  if (!provided(value)) return;
  card.append(element('p', `${label}: ${value}`));
}
if (grid || detail) {
  fetch('data/vehicles.json', { cache: 'no-store' }).then(r => {
    if (!r.ok) throw new Error('Vehicle data unavailable');
    return r.json();
  }).then(data => {
    const vehicles = Array.isArray(data) ? data : data.vehicles;
    if (!Array.isArray(vehicles)) throw new Error('Invalid vehicle data');
    if (grid) {
      grid.replaceChildren();
      if (!vehicles.length) {
        notice(grid, 'Example vehicle types are being prepared. Send your requirements and Gloria Trading will search Japanese auctions.');
      }
      vehicles.forEach(v => {
        if (v.status && v.status !== 'published') return;
        const card = element('article', undefined, 'vehicle-card');
        const img = photo((v.gallery || [])[0], title(v));
        if (img) card.append(img);
        else card.append(element('p', 'Photos are provided when available for a specific vehicle.', 'no-photo'));
        card.append(element('p', listingLabel(v), 'reference-label'));
        if (isSample(v)) card.append(element('p', 'Price and availability may change.'));
        card.append(element('h2', title(v)));
        const summary = [v.powertrain, v.body, v.steering].filter(Boolean).join(' · ');
        if (summary) card.append(element('p', summary));
        appendMeta(card, 'Year', v.year);
        appendMeta(card, 'Mileage', mileage(v));
        appendMeta(card, 'Battery', v.battery);
        appendMeta(card, 'Driving range', v.range);
        card.append(prices(v), link('View details', `vehicle-detail.html?ref=${encodeURIComponent(v.ref_id)}`));
        grid.append(card);
      });
      if (!grid.childElementCount) {
        notice(grid, 'Published vehicles are being prepared. Send your requirements and Gloria Trading will search Japanese auctions.');
      }
    }
    if (detail) {
      const ref = new URLSearchParams(location.search).get('ref');
      const v = vehicles.find(item => item.ref_id === ref && (!item.status || item.status === 'published'));
      if (!v) {
        notice(detail, 'This vehicle is not available. You can still request a vehicle by model.');
        return;
      }
      const url = `https://sa.gloriatrading.com/vehicle-detail.html?ref=${encodeURIComponent(v.ref_id)}`;
      document.title = `${title(v)} | Gloria Trading South Africa`;
      document.querySelector('link[rel="canonical"]').href = url;
      document.querySelector('meta[property="og:url"]').content = url;
      document.querySelector('meta[property="og:title"]').content = document.title;
      document.querySelector('h1').textContent = title(v);
      detail.replaceChildren(element('p', `${listingLabel(v)} ${v.ref_id}`, 'reference-label'));
      if (isSample(v)) detail.append(element('p', 'Price and availability may change.'));
      const gallery = element('div', undefined, 'detail-gallery');
      (v.gallery || []).forEach(src => {
        const img = photo(src, title(v));
        if (img) gallery.append(img);
      });
      if (!gallery.childElementCount) {
        gallery.append(element('p', 'Photos are provided when available for a specific vehicle.', 'no-photo'));
      }
      detail.append(gallery);
      const specs = element('dl', undefined, 'specs');
      for (const [label, value] of Object.entries(specRows(v))) {
        if (!provided(value)) continue;
        const row = element('div');
        row.append(element('dt', label), element('dd', value));
        specs.append(row);
      }
      detail.append(specs, prices(v));
      if (provided(v.auction_condition)) detail.append(element('p', v.auction_condition));
      if (provided(v.notes)) detail.append(element('p', v.notes));
      const video = safeVideoUrl(v.video_url);
      if (video) {
        const videoBlock = element('p', undefined, 'video-note');
        videoBlock.append(link('Watch available video', video, 'btn btn-secondary'));
        detail.append(videoBlock);
      }
      document.getElementById('similar-request').href = requestUrl(v);
    }
  }).catch(() => notice(grid || detail, 'Vehicle information could not be loaded. Please reload this page or send us your requirements directly.'));
}
