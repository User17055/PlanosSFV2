"""Restrict public map geometry to IBGE's Immediate Geographic Region of Prudente."""
import json,urllib.request,gzip,pathlib
root=pathlib.Path(__file__).resolve().parents[1]/'public/assets/maps'
def fetch(url):
    with urllib.request.urlopen(url,timeout=25) as response: raw=response.read()
    if raw[:2]==b'\x1f\x8b':raw=gzip.decompress(raw)
    return json.loads(raw)
city=fetch('https://servicodados.ibge.gov.br/api/v1/localidades/municipios/3541406')
region=city['regiao-imediata']
url=f"https://servicodados.ibge.gov.br/api/v1/localidades/regioes-imediatas/{region['id']}/municipios"
cities=fetch(url)
codes={str(c['id']) for c in cities}
mesh=json.loads((root/'SP.json').read_text(encoding='utf-8'))
features=[f for f in mesh['features'] if str(f['properties']['codarea']) in codes]
assert len(features)==len(codes) and '3541406' in codes
catalog={str(c['id']):{'name':c['nome'],'state':'35'} for c in cities}
data={'type':'FeatureCollection','region':{'id':region['id'],'name':region['nome'],'definition':'Região Geográfica Imediata','source':url,'cities':catalog},'features':features}
(root/'presidente-prudente.json').write_text(json.dumps(data,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
(root/'municipios-prudente.json').write_text(json.dumps(catalog,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
print('Região:',region['id'],region['nome'],'Municípios:',len(cities))
print(', '.join(c['nome'] for c in cities))
