"""Exercise both independent PHP applications over HTTP using disposable local databases."""
import argparse
import base64
import html
import http.cookiejar
import os
from pathlib import Path
import re
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def hidden(body, name):
    found = re.search(r'name="' + re.escape(name) + r'" value="([^"]*)"', body)
    assert found, "Missing form field: " + name
    return html.unescape(found.group(1))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--php-api", default="php")
    parser.add_argument("--php-site", required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    temp_root = (root / "tmp").resolve()
    temp_root.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="portal-http-", dir=temp_root) as temp:
        directory = Path(temp).resolve()
        assert directory.is_relative_to(temp_root)
        api_port, site_port = free_port(), free_port()
        api_url, site_url = f"http://127.0.0.1:{api_port}", f"http://127.0.0.1:{site_port}"
        key = secrets.token_hex(32)
        db = directory / "management.sqlite"
        db.touch()
        api_env = dict(os.environ, APP_ENV="local", APP_DEBUG="false", APP_DEMO="true", DB_CONNECTION="sqlite", DB_DATABASE=str(db), DB_URL="",
                       APP_KEY="base64:" + base64.b64encode(secrets.token_bytes(32)).decode(), APP_CONFIG_CACHE=str(directory / "api-config.php"),
                       SESSION_DRIVER="array", CACHE_STORE="array", QUEUE_CONNECTION="sync", MAIL_MAILER="array", VINDI_ENABLED="false",
                       VINDI_ALLOW_CREATION="false", VINDI_CHECKOUT_ENABLED="false", SITE_API_ENABLED="true", SITE_API_KEY=key, SITE_PUBLIC_URL=site_url)
        flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
        php = [args.php_api, "-d", "extension=pdo_sqlite", "-d", "extension=sqlite3"]
        setup = subprocess.run(php + ["tests/portal-http-fixture.php"], cwd=root, env=api_env, capture_output=True, creationflags=flags, timeout=120)
        assert setup.returncode == 0, "Could not prepare isolated management database: " + setup.stderr.decode(errors="replace")
        cms = directory / "institutional.sqlite"
        with sqlite3.connect(cms) as connection:
            connection.executescript('''
                CREATE TABLE roles_access (path TEXT,roles TEXT,methods TEXT,host TEXT,ip TEXT,`order` INTEGER);
                CREATE TABLE institutional_type (id INTEGER,title TEXT,body TEXT);
                INSERT INTO institutional_type VALUES (4,'Mais cuidado para seu pet.','<p>Institucional de teste HTTP.</p>');
                CREATE TABLE planos_type (id INTEGER,title TEXT,url TEXT,enabled INTEGER);
            ''')
        connection.close()
        site_env = dict(os.environ, APP_ENV="local", APP_DEBUG="false", APP_SECRET=secrets.token_hex(32),
                        DATABASE_URL="sqlite:///" + cms.as_posix(), PORTAL_API_URL=api_url + "/api/site/v1", PORTAL_API_KEY=key)
        processes = []
        logs = []
        try:
            for label, command, env in [
                ("management", php + ["-S", f"127.0.0.1:{api_port}", "-t", "public", "server.php"], api_env),
                ("institutional", [args.php_site, "-S", f"127.0.0.1:{site_port}", "-t", "institucional/public_html", "institucional/public_html/index.php"], site_env),
            ]:
                log = open(directory / (label + ".log"), "wb")
                logs.append(log)
                processes.append(subprocess.Popen(command, cwd=root, env=env, stdout=log, stderr=log, creationflags=flags))
            for port in [api_port, site_port]:
                deadline = time.monotonic() + 45
                while True:
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.5):
                            break
                    except OSError:
                        assert time.monotonic() < deadline, "Local server did not start."
                        time.sleep(0.2)
            client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            def request(path, data=None):
                encoded = urllib.parse.urlencode(data).encode() if data is not None else None
                try:
                    response = client.open(urllib.request.Request(site_url + path, data=encoded), timeout=140)
                except urllib.error.HTTPError as error:
                    raise AssertionError(f"HTTP {error.code} at {path}: " + error.read().decode(errors="replace")[:500]) from error
                return response, response.read()
            _, content = request("/planos-saude/")
            page = content.decode()
            assert "/contratar/SITE-HTTP" in page and "99,00" in page
            assert key not in page and api_url not in page
            _, content = request("/contratar/SITE-HTTP")
            csrf = hidden(content.decode(), "_token")
            form = dict(_token=csrf, name="Tutor HTTP", document="52998224725", email="http@example.test", phone="18999990000",
                        street="Rua de Teste", number="10", neighborhood="Centro", city="Presidente Prudente", state="SP", zip="19000000",
                        password="HttpPassword@2026", password_confirmation="HttpPassword@2026", privacy_accepted="1",
                        pet_name="Lua HTTP", pet_species="dog", pet_birth_date="2020-01-01")
            response, content = request("/contratar/SITE-HTTP", form)
            page = content.decode()
            assert "/area-do-cliente/contratacoes/" in response.url and "Assinar contrato e continuar" in page
            proposal_path = urllib.parse.urlparse(response.url).path
            fingerprint = hidden(page, "fingerprint")
            csrf = hidden(page, "_token")
            response, pdf = request(proposal_path + "/conferencia.pdf")
            assert response.headers.get_content_type() == "application/pdf" and pdf.startswith(b"%PDF-")
            _, content = request(proposal_path + "/assinar", dict(_token=csrf, accepted="1", signer_name="Tutor HTTP", fingerprint=fingerprint))
            assert "Assinatura registrada." in content.decode()
            _, content = request(proposal_path + "/gerar-contrato", {"_token": csrf})
            page = content.decode()
            document_path = re.search(r'href="(/area-do-cliente/documentos/\d+)"', page)
            assert document_path, "Final PDF link was not created."
            _, pdf = request(document_path.group(1))
            assert pdf.startswith(b"%PDF-")
            _, content = request("/area-do-cliente")
            page = content.decode()
            assert "Lua HTTP" in page and "Meus pagamentos" in page and "Ver contrato PDF" in page
            assert "HttpPassword@2026" not in page
            csrf = hidden(page, "_token")
            _, content = request("/area-do-cliente/sair", {"_token": csrf})
            assert "Seu espaço de cuidado." in content.decode()
            response, _ = request("/area-do-cliente")
            assert response.url.endswith("/area-do-cliente/entrar")
            print("PASS: independent institutional and management apps, catalog, registration, PDF review, acceptance, final document, client dashboard and logout over HTTP.")
            print("No real emails or payment requests were sent. Disposable databases were used.")
        finally:
            for process in processes:
                process.terminate()
            for process in processes:
                try:
                    process.wait(timeout=15)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=15)
            for log in logs:
                log.close()


if __name__ == "__main__":
    main()
