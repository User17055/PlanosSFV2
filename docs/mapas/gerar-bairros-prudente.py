"""Divisão aproximada dos bairros de Presidente Prudente.

Formato da cidade: união dos setores urbanos do distrito sede (IBGE, Censo 2022).
Bairros: os do OpenStreetMap. Onde o OSM tem o contorno do bairro, vale o contorno; nos demais,
cada pedaço da área urbana fica com o bairro (ponto) mais próximo (diagrama de Voronoi).
Uso: python gerar-bairros-prudente.py <setores.shp sem extensão> <osm.json> <saida.json>
"""
import json
import sys
import unicodedata

import shapefile
from shapely.geometry import MultiPoint, Point, Polygon, LineString, mapping, shape
from shapely.ops import polygonize, unary_union, voronoi_diagram

setores_path, osm_path, out_path = sys.argv[1], sys.argv[2], sys.argv[3]
norm = lambda s: ' '.join(unicodedata.normalize('NFD', s).encode('ascii', 'ignore').decode().upper().split())

# 1) Área urbana do distrito sede.
reader = shapefile.Reader(setores_path, encoding='utf-8')
fields = [f[0] for f in reader.fields[1:]]
col = {name: fields.index(name) for name in ('CD_MUN', 'SITUACAO', 'NM_DIST')}
urban_parts = []
for index, record in enumerate(reader.iterRecords()):
    if record[col['CD_MUN']] == '3541406' and record[col['SITUACAO']] == 'Urbana' and record[col['NM_DIST']] == 'Presidente Prudente':
        urban_parts.append(shape(reader.shape(index).__geo_interface__))
urban = unary_union(urban_parts).buffer(0.0003).buffer(-0.0003)  # fecha frestas entre setores

# 2) Bairros do OpenStreetMap: contorno quando existe, senão um ponto.
data = json.load(open(osm_path, encoding='utf-8'))
sites, real = {}, {}
for element in sorted(data['elements'], key=lambda e: e['type'] != 'node'):
    tags = element.get('tags', {})
    name = tags.get('name', '').strip()
    if not name or tags.get('admin_level') == '9':
        continue
    key = norm(name)
    polygon = None
    if element['type'] == 'way' and len(element.get('geometry', [])) >= 4:
        coords = [(p['lon'], p['lat']) for p in element['geometry']]
        if coords[0] == coords[-1]:
            polygon = Polygon(coords)
    elif element['type'] == 'relation':
        lines = [LineString([(p['lon'], p['lat']) for p in m['geometry']]) for m in element.get('members', [])
                 if m.get('role') in ('outer', '') and len(m.get('geometry', [])) >= 2]
        pieces = list(polygonize(unary_union(lines))) if lines else []
        polygon = unary_union(pieces) if pieces else None
    if polygon is not None and polygon.is_valid and polygon.area > 0:
        real.setdefault(key, (name, polygon))
        sites.setdefault(key, (name, polygon.representative_point()))
    elif element['type'] == 'node':
        sites.setdefault(key, (name, Point(element['lon'], element['lat'])))

# Só bairros dentro (ou colados) da área urbana.
near = urban.buffer(0.004)
sites = {k: v for k, v in sites.items() if near.contains(v[1])}
real = {k: (n, p.intersection(urban)) for k, (n, p) in real.items() if k in sites}
real = {k: v for k, v in real.items() if not v[1].is_empty}
real_union = unary_union([p for _, p in real.values()]) if real else None

# 3) Voronoi dos pontos, recortado pela área urbana; contornos reais têm prioridade.
cells = voronoi_diagram(MultiPoint([p for _, p in sites.values()]), envelope=urban.envelope.buffer(0.05))
features = []
for key, (name, point) in sites.items():
    if key in real:
        geom = real[key][1]
    else:
        cell = next((c for c in cells.geoms if c.contains(point)), None)
        if cell is None:
            continue
        geom = cell.intersection(urban)
        if real_union is not None:
            geom = geom.difference(real_union)
    geom = geom.simplify(0.00012, preserve_topology=True)
    if geom.is_empty or geom.area < 2e-7:
        continue
    features.append({'type': 'Feature', 'properties': {'name': name, 'contorno_osm': key in real}, 'geometry': mapping(geom)})

def rounded(value):
    if isinstance(value, float):
        return round(value, 5)
    if isinstance(value, (list, tuple)):
        return [rounded(v) for v in value]
    if isinstance(value, dict):
        return {k: rounded(v) for k, v in value.items()}
    return value

features.sort(key=lambda f: norm(f['properties']['name']))
result = {'type': 'FeatureCollection', 'approximate': True, 'city': 'Presidente Prudente',
          'source': 'Divisão aproximada: área urbana do IBGE (Censo 2022) e bairros do OpenStreetMap (© colaboradores do OpenStreetMap, ODbL)',
          'features': rounded(features)}
json.dump(result, open(out_path, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
names = {norm(f['properties']['name']) for f in features}
print(f'{len(features)} bairros ({sum(f["properties"]["contorno_osm"] for f in features)} com contorno do OSM); área urbana com {len(getattr(urban, "geoms", [urban]))} parte(s)')
print('Vila Furquim:', 'VILA FURQUIM' in names, '| Jardim Itaipú:', 'JARDIM ITAIPU' in names)
