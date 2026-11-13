"""Bairros de Presidente Prudente a partir do cadastro de endereços do Censo 2022 (IBGE, CNEFE).

Cada endereço do distrito sede tem bairro (DSC_LOCALIDADE) e coordenada. O território fica dividido entre os
endereços (diagrama de Voronoi), recortado à área urbana (contorno do IBGE e loteamentos novos colados nele), e as partes
de cada bairro são unidas. Endereço com bairro trocado (longe do resto do bairro) não conta.
Nomes: com acento quando a mesma grafia, sem acento, aparece na lista de nomes (mapa anterior do OSM e cadastros).

Uso: python gerar-bairros-prudente-cnefe.py <3541406_PRESIDENTE_PRUDENTE.zip> <nomes.json> <contorno.json> <saida.json>
<contorno.json> é o mapa anterior (gerar-bairros-prudente.py): a união dele é a área urbana do distrito sede.
Fonte: https://ftp.ibge.gov.br/Cadastro_Nacional_de_Enderecos_para_Fins_Estatisticos/Censo_Demografico_2022/Arquivos_CNEFE/CSV/Municipio/35_SP/
"""
import csv
import io
import json
import re
import statistics
import sys
import unicodedata
import zipfile
from collections import defaultdict

import shapely
from shapely.geometry import MultiPoint, Point, mapping
from shapely.ops import unary_union

ABBREVIATIONS = {'jd': 'jardim', 'jdm': 'jardim', 'vl': 'vila', 'pq': 'parque', 'res': 'residencial', 'cj': 'conjunto', 'conj': 'conjunto',
                 'hab': 'habitacional', 'cond': 'condominio', 'sta': 'santa', 'sto': 'santo'}
ROMAN = {'i': '1', 'ii': '2', 'iii': '3', 'iv': '4', 'v': '5', 'vi': '6'}
SEDE = '354140605'


def full(name):
    name = unicodedata.normalize('NFKD', name).encode('ascii', 'ignore').decode().lower()
    return ' '.join(ABBREVIATIONS.get(w, ROMAN.get(w, w)) for w in re.sub(r'[.,;:/\-]+', ' ', name.replace('&', ' e ')).split())


TYPES = re.compile(r'^(parque residencial|conjunto habitacional|nucleo habitacional|condominio residencial|residencial|conjunto|nucleo|jardim|vila|parque|chacaras?|loteamento|condominio|bairro)\s+')


def same_bare(a, b):
    """Mesmo nome sem o tipo, e o tipo de um contido no do outro (a regra de app/Services/Reports/NeighborhoodNames.php)."""
    def split(name):
        types = []
        while (m := TYPES.match(name)):
            types.extend(m.group(1).split())
            name = name[m.end():]
        return set(types), name
    (ta, ba), (tb, bb) = split(a), split(b)
    # Tipos como palavras: "Conjunto" está contido em "Conjunto Habitacional".
    return ba != '' and ba == bb and (ta <= tb or tb <= ta)


def split_bare(name):
    while (m := TYPES.match(name)):
        name = name[m.end():]
    return name


def pretty(name):
    words = name.lower().split()
    out = []
    for i, w in enumerate(words):
        if i and w in ('de', 'da', 'do', 'das', 'dos', 'e'):
            out.append(w)
        elif w in ROMAN:
            out.append(w.upper())
        else:
            out.append(w[:1].upper() + w[1:])
    return ' '.join(out)


def main(cnefe_zip, names_path, outline_path, out_path):
    nice = {}
    for name in json.load(open(names_path, encoding='utf-8')):
        key = full(name)
        # Prefere a grafia com acento e com maiúsculas normais.
        score = (any(ord(c) > 127 for c in name), name != name.upper() and name != name.lower())
        if key not in nice or score > nice[key][1]:
            nice[key] = (name.strip(), score)

    points = defaultdict(set)
    archive = zipfile.ZipFile(cnefe_zip)
    rows = csv.reader(io.TextIOWrapper(archive.open(archive.namelist()[0]), encoding='utf-8', errors='replace'), delimiter=';')
    header = next(rows)
    col = {k: header.index(k) for k in ('COD_DISTRITO', 'DSC_LOCALIDADE', 'LATITUDE', 'LONGITUDE', 'NV_GEO_COORD')}
    for row in rows:
        if row[col['COD_DISTRITO']] != SEDE or row[col['NV_GEO_COORD']] not in ('1', '2') or not row[col['DSC_LOCALIDADE']].strip():
            continue
        points[row[col['DSC_LOCALIDADE']].strip()].add((round(float(row[col['LONGITUDE']]), 4), round(float(row[col['LATITUDE']]), 4)))

    # Endereço a mais de ~1,5 km do meio do próprio bairro é bairro trocado no cadastro.
    sites, labels = [], []
    for name, pts in points.items():
        cx = statistics.median(p[0] for p in pts)
        cy = statistics.median(p[1] for p in pts)
        for p in pts:
            if (p[0] - cx) ** 2 + (p[1] - cy) ** 2 <= 0.015 ** 2:
                sites.append(p)
                labels.append(name)

    # Um ponto por coordenada: o primeiro bairro que aparece fica com ela.
    seen, uniq, uniq_labels = set(), [], []
    for p, name in zip(sites, labels):
        if p not in seen:
            seen.add(p)
            uniq.append(p)
            uniq_labels.append(name)

    # Área urbana: o contorno do mapa anterior (setores urbanos do IBGE) mais os loteamentos novos colados nele.
    urban = shapely.union_all([shapely.geometry.shape(f['geometry']) for f in json.load(open(outline_path, encoding='utf-8'))['features']], grid_size=1e-6)
    urban = urban.buffer(0.0004).buffer(-0.0004)
    near = urban.buffer(0.012)
    keep = [i for i, p in enumerate(uniq) if near.contains(Point(p))]
    uniq, uniq_labels = [uniq[i] for i in keep], [uniq_labels[i] for i in keep]
    built = shapely.union_all([urban, shapely.union_all(shapely.buffer(shapely.points(uniq), 0.0012, quad_segs=3))], grid_size=1e-6)
    built = shapely.Polygon(max(shapely.get_parts(built.buffer(0.0008).buffer(-0.0008)), key=lambda g: g.area).exterior)  # um contorno só, sem buracos
    cells = shapely.voronoi_polygons(MultiPoint(uniq), extend_to=built.envelope.buffer(0.01), ordered=True)
    parts = defaultdict(list)
    for name, cell in zip(uniq_labels, shapely.get_parts(cells)):
        parts[name].append(cell)

    features = []
    for name, cells_of_name in parts.items():
        # Grade fixa de ~10 cm: a união das células do Voronoi sem ela esbarra em erro de precisão.
        area = shapely.union_all(shapely.make_valid(cells_of_name), grid_size=1e-6)
        area = shapely.intersection(area, built, grid_size=1e-6).buffer(0.00005).buffer(-0.00005).simplify(0.00012, preserve_topology=True)
        if area.is_empty:
            continue
        area = shapely.set_precision(area, 0.00001)
        display = nice.get(full(name), (pretty(name),))[0]
        features.append({'type': 'Feature', 'properties': {'name': display}, 'geometry': mapping(area)})
    # Condomínios e loteamentos que o IBGE não separa (Central Park, Damha...) mas o mapa anterior tinha: entram como ponto.
    ibge = [full(f['properties']['name']) for f in features]
    for old in json.load(open(outline_path, encoding='utf-8'))['features']:
        key = full(old['properties']['name'])
        if not any(split_bare(key) == split_bare(other) for other in ibge):
            spot = shapely.geometry.shape(old['geometry']).representative_point()
            features.append({'type': 'Feature', 'properties': {'name': old['properties']['name'], 'kind': 'ponto'},
                             'geometry': mapping(shapely.set_precision(spot, 0.00001))})
    features.sort(key=lambda f: (f['geometry']['type'] == 'Point', f['properties']['name']))
    json.dump({'type': 'FeatureCollection', 'source': 'IBGE, CNEFE do Censo 2022 (endereços do distrito sede)', 'approximate': False, 'features': features},
              open(out_path, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
    print(len(features), 'bairros', len(uniq), 'pontos')


if __name__ == '__main__':
    main(*sys.argv[1:5])
