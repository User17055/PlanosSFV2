"""Public boundaries only: no customer information is sent to these services."""
import json, urllib.request, pathlib, re, gzip
root=pathlib.Path(__file__).resolve().parents[1]/'public/assets/maps'
root.mkdir(parents=True,exist_ok=True)
def fetch(url):
    with urllib.request.urlopen(url,timeout=45) as r:
        raw=r.read()
        if raw[:2]==b'\x1f\x8b': raw=gzip.decompress(raw)
        return json.loads(raw)
national=fetch('https://servicodados.ibge.gov.br/api/v3/malhas/paises/BR?intrarregiao=UF&qualidade=minima&formato=application/vnd.geo+json')
(root/'brasil.json').write_text(json.dumps(national,separators=(',',':')),encoding='utf-8')
municipal=fetch('https://servicodados.ibge.gov.br/api/v3/malhas/estados/SP?intrarregiao=municipio&qualidade=minima&formato=application/vnd.geo+json')
(root/'SP.json').write_text(json.dumps(municipal,separators=(',',':')),encoding='utf-8')
locations=fetch('https://servicodados.ibge.gov.br/api/v1/localidades/municipios?orderBy=nome')
catalog={str(x['id']):{'name':x['nome'],'state':str(x['id'])[:2]} for x in locations}
(root/'municipios.json').write_text(json.dumps(catalog,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
(root/'SOURCE.md').write_text('Geometrias simplificadas: IBGE API de Malhas v3. Nomes: API de Localidades v1.\nhttps://servicodados.ibge.gov.br/api/docs/malhas?versao=3\nhttps://servicodados.ibge.gov.br/api/docs/localidades\nNenhum endereço de cliente é enviado ao IBGE.\n',encoding='utf-8')
print('Brasil:',len(national['features']),'UFs; São Paulo:',len(municipal['features']),'municípios; catálogo:',len(catalog))
import runpy
runpy.run_path(str(pathlib.Path(__file__).with_name('prepare-prudente-region.py')),run_name='__main__')
