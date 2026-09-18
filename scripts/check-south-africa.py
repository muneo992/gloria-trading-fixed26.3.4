"""Validate isolated South Africa public files without network or external packages."""
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit, unquote
import json
import re
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1] / 'south-africa'
BASE = 'https://sa.gloriatrading.com/'
FORBIDDEN = re.compile(
    r'preferential Import Permit|will be accepted by Uber|industry-leading|number one exporter|best quality|unmatched experience',
    re.I,
)

class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.refs = []
        self.canonical = []
        self.ids = []
        self.h1 = 0
        self.text = []
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'h1':
            self.h1 += 1
        if 'id' in attrs:
            self.ids.append(attrs['id'])
        for key in ('src', 'href'):
            if attrs.get(key):
                self.refs.append(attrs[key])
        if tag == 'link' and attrs.get('rel') == 'canonical':
            self.canonical.append(attrs['href'])
    def handle_data(self, data):
        self.text.append(data)

html_pages = list(ROOT.glob('*.html'))
assert html_pages, 'No South Africa HTML pages'
for path in html_pages:
    raw = path.read_text(encoding='utf-8')
    parser = Page()
    parser.feed(raw)
    assert parser.h1 == 1, path
    assert len(parser.ids) == len(set(parser.ids)), path
    assert len(parser.canonical) == 1 and parser.canonical[0].startswith(BASE), path
    assert 'sa.gloriatrading.com' in raw
    assert FORBIDDEN.search(raw) is None, (path, FORBIDDEN.search(raw))
    assert 'Import requirements must be confirmed before shipment' in raw, path
    for ref in parser.refs:
        url = urlsplit(ref)
        if url.scheme or url.netloc:
            continue
        target = (path.parent / unquote(url.path)).resolve() if url.path else path
        assert target.is_relative_to(ROOT), (path, ref)
        assert target.exists(), (path, ref)
        if url.fragment:
            p = Page()
            p.feed(target.read_text(encoding='utf-8'))
            assert unquote(url.fragment) in p.ids, (path, ref)

vehicles = json.loads((ROOT / 'data/vehicles.json').read_text(encoding='utf-8'))['vehicles']
ids = set()
for v in vehicles:
    assert v.get('ref_id') and v['ref_id'] not in ids
    ids.add(v['ref_id'])
    assert v.get('make') and v.get('model')
    assert v.get('listing_type') in ('sample', 'available')
    assert v.get('status') in ('published', 'draft')
    for key in ('year', 'mileage_km', 'battery', 'range', 'reference_price_usd', 'video_url'):
        value = v.get(key)
        assert value in (None, '', [])
    for src in v.get('gallery', []):
        assert src.startswith('images/') and '..' not in src
        assert (ROOT / src).is_file(), src

js = (ROOT / 'js/sa.js').read_text(encoding='utf-8')
assert 'wa.me/819076671825' in js
assert 'info@gloriatrading.com' in js
assert 'textContent' in js

for loc in ET.parse(ROOT / 'sitemap.xml').iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc'):
    assert loc.text.startswith(BASE)
    assert (ROOT / (urlsplit(loc.text).path.lstrip('/') or 'index.html')).is_file()

robots = (ROOT / 'robots.txt').read_text(encoding='utf-8')
assert 'https://sa.gloriatrading.com/sitemap.xml' in robots

print(f'SA checks passed: {len(html_pages)} pages, {len(vehicles)} example types.')
