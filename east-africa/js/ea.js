'use strict';
// EA data is deliberately independent from the West Africa admin and catalog.
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
  form.elements.model.value = (params.get('model') || '').slice(0,150);
  if (params.get('ref')) form.elements.additional.value = `Reference vehicle: ${params.get('ref').slice(0,100)}`;
  const labels = {name:'Name',country:'Country',model:'Vehicle / Model',whatsapp:'WhatsApp',year:'Year',mileage:'Maximum mileage (km)',fuel:'Fuel',budget:'Target Budget (USD)',quantity:'Quantity',destination:'Destination',basis:'FOB / CIF basis',additional:'Additional request'};
  function send(via) {
    for (const field of form.querySelectorAll('[required]')) {
      field.value = field.value.trim();
    }
    if (!form.reportValidity()) return;
    const lines = ['Hello Gloria Trading,', '', 'East Africa vehicle request'];
    for (const [key,label] of Object.entries(labels)) {
      const value = form.elements[key].value.trim();
      if (value) lines.push(`${label}: ${value}`);
    }
    const body = encodeURIComponent(lines.join('\n'));
    const url = via === 'email'
      ? `mailto:info@gloriatrading.com?subject=${encodeURIComponent('East Africa vehicle request')}&body=${body}`
      : `https://wa.me/819076671825?text=${body}`;
    // Same-tab navigation avoids popup blockers; no message is sent automatically.
    location.href = url;
    document.getElementById('request-status').textContent = 'Review your message in the app and press send there.';
  }
  form.addEventListener('submit', e => {e.preventDefault(); send('whatsapp');});
  document.getElementById('email-request').addEventListener('click', () => send('email'));
}
const grid = document.getElementById('vehicle-grid');
const detail = document.getElementById('vehicle-detail');
function element(tag,text,className) {
  const el=document.createElement(tag);
  if(text!==undefined) el.textContent=text;
  if(className) el.className=className;
  return el;
}
function link(text,url,className='btn btn-primary') {
  const el=element('a',text,className); el.href=url; return el;
}
// Retains the original field names, title fallback and gallery / number formatting.
function title(v) {return v.display_name_en || [v.year,v.make,v.model,v.grade].filter(Boolean).join(' ');}
function positive(value) {return value!==null && value!=='' && Number.isFinite(Number(value)) && Number(value)>0;}
function price(value) {return positive(value) ? `USD ${Number(value).toLocaleString('en-US')}` : 'Request a quotation';}
function mileage(v) {return positive(v.mileage_km) ? `${Number(v.mileage_km).toLocaleString('en-US')} km` : 'Not provided';}
function requestUrl(v) {return `request.html?${new URLSearchParams({model:[v.make,v.model].filter(Boolean).join(' '),ref:v.ref_id})}`;}
function photo(src,alt) {
  // Only local EA assets are permitted; never read West Africa data or remote URLs.
  if(typeof src!=='string' || !/^images\/[a-zA-Z0-9_./-]+\.(jpg|jpeg|png|webp)$/i.test(src) || src.includes('..')) return null;
  const image=element('img'); image.src=src; image.alt=alt; image.loading='lazy';
  image.addEventListener('error',()=>image.replaceWith(element('p','Photo unavailable. Request current photos.')));
  return image;
}
function prices(v) {
  const block=element('div',undefined,'price-lines');
  block.append(element('p',`FOB Japan: ${price(v.reference_price_usd)}`));
  block.append(element('p',`Estimated CIF Mombasa: ${price(v.estimated_cif_mombasa_usd)}`));
  if(v.price_as_of) block.append(element('p',`Price reference date: ${v.price_as_of}`));
  block.append(element('p','Reference prices only. Availability and final quotation must be confirmed.'));
  return block;
}
function notice(target,message) {
  const panel=element('div',undefined,'empty-state');
  panel.append(element('p',message),link('Request a Vehicle','request.html'));
  target.replaceChildren(panel);
}
if(grid || detail) {
  fetch('data/vehicles.json',{cache:'no-store'}).then(r=>{
    if(!r.ok) throw new Error('Vehicle data unavailable'); return r.json();
  }).then(data=>{
    const vehicles=Array.isArray(data)?data:data.vehicles;
    if(!Array.isArray(vehicles)) throw new Error('Invalid vehicle data');
    if(grid) {
      grid.replaceChildren();
      if(!vehicles.length) notice(grid,'Reference vehicles and prices for East Africa are being prepared. Tell us the model you need for a current quotation.');
      vehicles.forEach(v=>{
        const card=element('article',undefined,'vehicle-card');
        const img=photo((v.gallery||[])[0],title(v)); if(img) card.append(img);
        card.append(element('p','Reference vehicle · Not in stock','reference-label'),element('h2',title(v)),element('p',[v.year,v.fuel_type,v.transmission].filter(Boolean).join(' | ')),element('p',`Mileage: ${mileage(v)}`),prices(v),link('View Details',`vehicle-detail.html?ref=${encodeURIComponent(v.ref_id)}`));
        grid.append(card);
      });
    }
    if(detail) {
      const ref=new URLSearchParams(location.search).get('ref');
      const v=vehicles.find(v=>v.ref_id===ref);
      if(!v) {notice(detail,'This reference vehicle is not available. You can still request a vehicle by model.');return;}
      const url=`https://ea.gloriatrading.com/vehicle-detail.html?ref=${encodeURIComponent(v.ref_id)}`;
      document.title=`${title(v)} | Gloria Trading East Africa`;
      document.querySelector('link[rel="canonical"]').href=url;
      document.querySelector('meta[property="og:url"]').content=url;
      document.querySelector('meta[property="og:title"]').content=document.title;
      document.querySelector('h1').textContent=title(v);
      detail.replaceChildren(element('p',`Reference vehicle ${v.ref_id} · Not in stock`,'reference-label'));
      const gallery=element('div',undefined,'detail-gallery');
      (v.gallery||[]).forEach(src=>{const img=photo(src,title(v));if(img)gallery.append(img);});
      detail.append(gallery);
      const specs=element('dl',undefined,'specs');
      const values={'Make / Model':[v.make,v.model].filter(Boolean).join(' '),'Year':v.year,'Mileage':mileage(v),'Engine':positive(v.engine_cc)?`${Number(v.engine_cc).toLocaleString('en-US')} cc`:null,'Fuel':v.fuel_type,'Transmission':v.transmission,'Drive':v.drive,'Steering':v.steering};
      if(v.auction_grade) values['Auction Grade']=v.auction_grade;
      for(const [label,value] of Object.entries(values)) {const row=element('div');row.append(element('dt',label),element('dd',value||'Not provided'));specs.append(row);}
      detail.append(specs,prices(v));
      document.getElementById('similar-request').href=requestUrl(v);
    }
  }).catch(()=>notice(grid||detail,'Vehicle information could not be loaded. Please reload this page or send us your requirements directly.'));
}
