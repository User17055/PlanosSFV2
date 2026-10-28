"""Public neighborhood reference points; no geocoding of customer addresses."""
import urllib.request,urllib.parse,json,pathlib,gzip,sys
base=pathlib.Path(__file__).resolve().parents[1]
code=sys.argv[1] if len(sys.argv)>1 else '3541406'
if not code.isdigit() or len(code)!=7: raise ValueError('Código IBGE inválido')
state=code[:2]
states={'35':'SP'}
mesh=json.loads((base/'public/assets/maps'/f'{states[state]}.json').read_text())
feature=next(f for f in mesh['features'] if f['properties']['codarea']==code)
rings=feature['geometry']['coordinates'] if feature['geometry']['type']=='Polygon' else [r for p in feature['geometry']['coordinates'] for r in p]
points=[p for r in rings for p in r]
west,south,east,north=min(p[0] for p in points),min(p[1] for p in points),max(p[0] for p in points),max(p[1] for p in points)
query=f'[out:json][timeout:20];nwr["place"~"^(suburb|quarter|neighbourhood)$"]({south},{west},{north},{east});out center;'
payload=urllib.parse.urlencode({'data':query}).encode()
request=urllib.request.Request('https://overpass.private.coffee/api/interpreter?'+payload.decode(),headers={'User-Agent':'SaoFranciscoLocalAnalytics/1.0'})
with urllib.request.urlopen(request,timeout=30) as response: raw=response.read()
if raw[:2]==b'\x1f\x8b':raw=gzip.decompress(raw)
data=json.loads(raw)
def inside(point,ring):
    x,y=point; result=False; j=len(ring)-1
    for i in range(len(ring)):
        xi,yi=ring[i];xj,yj=ring[j]
        if (yi>y)!=(yj>y) and x<(xj-xi)*(y-yi)/(yj-yi)+xi:result=not result
        j=i
    return result
features=[]
for node in data['elements']:
    name=node.get('tags',{}).get('name'); center=node.get('center',node)
    if not name or 'lon' not in center:continue
    point=[center['lon'],center['lat']]
    if not any(inside(point,r) for r in rings):continue
    features.append({'type':'Feature','properties':{'name':name},'geometry':{'type':'Point','coordinates':point}})
output=base/'public/assets/maps/neighborhoods';output.mkdir(exist_ok=True)
(output/f'{code}-points.json').write_text(json.dumps({'type':'FeatureCollection','source':'OpenStreetMap contributors','city_code':code,'features':features},ensure_ascii=False,separators=(',',':')),encoding='utf-8')
print('Referências públicas de bairros:',len(features))
