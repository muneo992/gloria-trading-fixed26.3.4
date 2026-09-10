"""Validate the isolated EA public files without network or external packages."""
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit, unquote
import json
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1] / 'east-africa'
BASE = 'https://ea.gloriatrading.com/'
class Page(HTMLParser):
    def __init__(self):
        super().__init__(); self.refs=[]; self.canonical=[]; self.ids=[]; self.h1=0
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='h1': self.h1+=1
        if 'id' in attrs: self.ids.append(attrs['id'])
        for key in ('src','href'):
            if attrs.get(key): self.refs.append(attrs[key])
        if tag=='link' and attrs.get('rel')=='canonical': self.canonical.append(attrs['href'])

for path in ROOT.glob('*.html'):
    parser=Page(); parser.feed(path.read_text())
    assert parser.h1==1, path
    assert len(parser.ids)==len(set(parser.ids)), path
    assert len(parser.canonical)==1 and parser.canonical[0].startswith(BASE), path
    for ref in parser.refs:
        url=urlsplit(ref)
        if url.scheme or url.netloc: continue
        target=(path.parent/unquote(url.path)).resolve() if url.path else path
        assert target.is_relative_to(ROOT), (path,ref)
        assert target.exists(), (path,ref)
        if url.fragment:
            p=Page(); p.feed(target.read_text()); assert unquote(url.fragment) in p.ids, (path,ref)
vehicles=json.loads((ROOT/'data/vehicles.json').read_text())['vehicles']
ids=set()
for v in vehicles:
    assert v.get('ref_id') and v['ref_id'] not in ids
    ids.add(v['ref_id'])
    assert v.get('make') and v.get('model')
    for key in ('reference_price_usd','estimated_cif_mombasa_usd'):
        value=v.get(key)
        assert value is None or (isinstance(value,(int,float)) and not isinstance(value,bool) and value>0)
        if value is not None: assert v.get('price_as_of'), 'Dated price reference required'
    for src in v.get('gallery',[]):
        assert src.startswith('images/') and '..' not in src
        assert (ROOT/src).is_file(), src
for loc in ET.parse(ROOT/'sitemap.xml').iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc'):
    assert loc.text.startswith(BASE)
    assert (ROOT/(urlsplit(loc.text).path.lstrip('/') or 'index.html')).is_file()
print(f'EA checks passed: {len(list(ROOT.glob("*.html")))} pages, {len(vehicles)} published reference vehicles.')
