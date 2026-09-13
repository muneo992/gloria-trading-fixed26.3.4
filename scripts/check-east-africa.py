"""Validate the isolated EA public files without network or external packages."""
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit, unquote
import hashlib
import json
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1] / 'east-africa'
BASE = 'https://ea.gloriatrading.com/'
EA_PBX_001_IMAGES = [
    ('images/EA-PBX-001/EA-PBX-001-01.jpg', 'b193e992d00490811cd03607036d4b185e321727a7ef4ad495fe4fd1904d62a4'),
    ('images/EA-PBX-001/EA-PBX-001-02.jpg', 'e837541e030add73d48997522d6105a961279a4d5f820c13bbf0d712bc075a55'),
    ('images/EA-PBX-001/EA-PBX-001-03.jpg', 'b3720eb4bd5441ffdace9434c94f9d243ae05f6a3311e147b7ce067a9f926a8a'),
    ('images/EA-PBX-001/EA-PBX-001-04.jpg', '54f6e6c86bd0fa8ecd776c3b7e134c32da0601d69eb579aedb8803537d459e03'),
    ('images/EA-PBX-001/EA-PBX-001-05.jpg', '5ade0285a2d1695fa1cdcb1ac6351741a1ebf90e8b2fad9a7a494e975c0bb7a5'),
    ('images/EA-PBX-001/EA-PBX-001-06.jpg', '72e43dccd59daf908e1430ea0b24d387387183d202c6550bfe7851143243a926'),
    ('images/EA-PBX-001/EA-PBX-001-07.jpg', 'ec98055b43f4952f8b09d23c8ec6f0e517c51f7b826a1c9b4ec703c9b6a420ac'),
    ('images/EA-PBX-001/EA-PBX-001-08.jpg', '5fddab8e3a59da46cf8c02ca0d0586ddb1cc5ef06b836896dc5e0a0a2b2c7036'),
]
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
ea_pbx_001=next(v for v in vehicles if v['ref_id']=='EA-PBX-001')
assert ea_pbx_001.get('gallery')==[path for path,_ in EA_PBX_001_IMAGES]
for relative,expected_hash in EA_PBX_001_IMAGES:
    assert hashlib.sha256((ROOT/relative).read_bytes()).hexdigest()==expected_hash, relative
for loc in ET.parse(ROOT/'sitemap.xml').iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc'):
    assert loc.text.startswith(BASE)
    assert (ROOT/(urlsplit(loc.text).path.lstrip('/') or 'index.html')).is_file()
print(f'EA checks passed: {len(list(ROOT.glob("*.html")))} pages, {len(vehicles)} published reference vehicles.')
