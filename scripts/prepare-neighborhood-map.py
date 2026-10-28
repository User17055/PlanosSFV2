"""Convert the IBGE public GeoPackage to small per-city GeoJSONs, without dependencies."""
import pathlib,urllib.request,sqlite3,gzip,json,struct,sys
state=sys.argv[1] if len(sys.argv)>1 else 'SP'
if state not in 'AC AL AM AP BA CE DF ES GO MA MG MS MT PA PB PE PI PR RJ RN RO RR RS SC SE SP TO'.split(): raise ValueError('UF inválida')
base=pathlib.Path(__file__).resolve().parents[1]
url=f'https://geoftp.ibge.gov.br/organizacao_do_territorio/malhas_territoriais/malhas_de_setores_censitarios__divisoes_intramunicipais/censo_2022/bairros/gpkg/UF/{state}/{state}_bairros_CD2022.gpkg'
target=base/'tmp'/f'{state}-bairros.gpkg'
if not target.exists():
    with urllib.request.urlopen(url,timeout=40) as response: raw=response.read()
    if raw[:2]==b'\x1f\x8b': raw=gzip.decompress(raw)
    target.write_bytes(raw)
db=sqlite3.connect(target)
table=db.execute('select table_name from gpkg_contents where data_type="features"').fetchone()[0]
if not all(c.isalnum() or c=='_' for c in table): raise ValueError('Tabela inválida')
print('Tabela:',table)
print('Colunas:',[(r[1],r[2]) for r in db.execute(f'pragma table_info("{table}")')])
print('Geometria:',db.execute('select * from gpkg_geometry_columns').fetchall())

def read_geometry(blob):
    # GeoPackage header followed by standard OGC WKB. EPSG 4674 uses lon/lat degrees.
    envelope=(blob[3]>>1)&7
    offset=8+{0:0,1:32,2:48,3:48,4:64}[envelope]
    endian='<' if blob[offset]==1 else '>'
    position=offset+1
    kind=struct.unpack_from(endian+'I',blob,position)[0]; position+=4
    if kind!=3: raise ValueError(f'Geometria inesperada: {kind}')
    count=struct.unpack_from(endian+'I',blob,position)[0]; position+=4
    rings=[]
    for _ in range(count):
        size=struct.unpack_from(endian+'I',blob,position)[0]; position+=4
        ring=[]
        for _ in range(size):
            x,y=struct.unpack_from(endian+'dd',blob,position); position+=16
            ring.append([round(x,6),round(y,6)])
        rings.append(ring)
    return {'type':'Polygon','coordinates':rings}

municipal={}
for code,city,name,identifier,geometry in db.execute(f'SELECT CD_MUN,NM_MUN,NM_BAIRRO,CD_BAIRRO,geom FROM "{table}"'):
    municipal.setdefault(code,{'type':'FeatureCollection','source':'IBGE Censo 2022','city':city,'features':[]})['features'].append({'type':'Feature','properties':{'name':name,'code':identifier},'geometry':read_geometry(geometry)})
output=base/'public/assets/maps/neighborhoods';output.mkdir(exist_ok=True)
for code,geo in municipal.items(): (output/f'{code}.json').write_text(json.dumps(geo,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
print(len(municipal),'municípios preparados; Presidente Prudente:',len(municipal.get('3541406',{}).get('features',[])),'bairros oficiais nesta base')
