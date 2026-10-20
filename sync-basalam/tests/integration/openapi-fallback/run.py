#!/usr/bin/env python3
"""Real WP_Http/cURL over TLS, backed by a local Postman simulation."""
import argparse
from contextlib import contextmanager
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import signal
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import uuid
from jsonschema import Draft202012Validator

ROOT = Path(__file__).resolve().parents[3]


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


@contextmanager
def database():
    name = 'woosalam-openapi-db-' + uuid.uuid4().hex[:8]
    subprocess.run(['docker', 'run', '--rm', '-d', '--name', name,
                    '-p', '127.0.0.1::3306', '-e', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1',
                    '-e', 'MARIADB_DATABASE=fallback_test', 'mariadb:10.6'],
                   check=True, stdout=subprocess.DEVNULL)
    try:
        deadline = time.monotonic() + 45
        while subprocess.run(['docker', 'exec', name, 'mariadb-admin', '--protocol=tcp', '-h127.0.0.1', 'ping', '--silent'],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
            if time.monotonic() > deadline:
                raise RuntimeError('Isolated MariaDB did not become ready')
            time.sleep(0.3)
        port = subprocess.check_output(['docker', 'port', name, '3306'], text=True).strip().split(':')[-1]
        yield port
    finally:
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, check=True)


def run(wordpress, database_port):
    with tempfile.TemporaryDirectory(prefix='woosalam-openapi-fallback-') as temporary:
        work = Path(temporary)
        simulation_port = free_port()
        log = work / 'simulation.ndjson'
        err = work / 'simulation.err'
        process = subprocess.Popen([
            'postman', 'simulation', 'run',
            str(ROOT / 'postman/simulations/basalam-connection.sim.yaml'),
            '--port', str(simulation_port), '--no-history', '--output', 'ndjson',
        ], cwd=ROOT, stdout=log.open('w'), stderr=err.open('w'), start_new_session=True)
        servers = []
        sni = []
        try:
            deadline = time.monotonic() + 20
            while '"listening"' not in log.read_text():
                if process.poll() is not None or time.monotonic() > deadline:
                    raise RuntimeError('Simulation did not start: ' + err.read_text() + log.read_text())
                time.sleep(0.1)

            for name in ['openapi.basalam.com', 'wrong.test']:
                subprocess.run([
                    'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                    '-days', '1', '-subj', '/CN=' + name,
                    '-addext', 'subjectAltName=DNS:' + name + (',DNS:core.basalam.com,DNS:order-processing.basalam.com,DNS:webhook.basalam.com' if name == 'openapi.basalam.com' else ''),
                    '-keyout', str(work / (name + '.key')),
                    '-out', str(work / (name + '.crt')),
                ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            ca = work / 'ca.pem'
            ca.write_bytes(b''.join((work / (name + '.crt')).read_bytes() for name in ['openapi.basalam.com', 'wrong.test']))

            class Bridge(BaseHTTPRequestHandler):
                protocol_version = 'HTTP/1.1'

                def handle(self):
                    try:
                        super().handle()
                    except ConnectionResetError:
                        pass  # Expected when the client rejects the TLS name.

                def handle_request(self):
                    payload = self.rfile.read(int(self.headers.get('Content-Length', 0)))
                    connection = http.client.HTTPConnection('127.0.0.1', simulation_port, timeout=5)
                    headers = dict(self.headers)
                    headers['x-mock-slug'] = 'basalam'
                    try:
                        connection.request(self.command, self.path, payload, headers)
                        response = connection.getresponse()
                        body = response.read()
                        if '__disconnect=1' in self.path:
                            self.close_connection = True
                            return
                        self.send_response(response.status)
                        if response.getheader('Location'):
                            self.send_header('Location', response.getheader('Location'))
                        self.send_header('Content-Type', 'application/json')
                        self.send_header('Content-Length', str(len(body)))
                        self.end_headers()
                        self.wfile.write(body)
                    except (BrokenPipeError, ConnectionResetError, http.client.RemoteDisconnected):
                        self.close_connection = True
                    finally:
                        connection.close()

                do_GET = do_POST = do_PATCH = do_PUT = do_DELETE = handle_request

                def log_message(self, *args):
                    pass

            for name in ['openapi.basalam.com', 'wrong.test']:
                server = ThreadingHTTPServer(('127.0.0.1', 0), Bridge)
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                context.load_cert_chain(work / (name + '.crt'), work / (name + '.key'))
                context.set_servername_callback(lambda sock, hostname, ctx: sni.append(hostname))
                server.socket = context.wrap_socket(server.socket, server_side=True)
                threading.Thread(target=server.serve_forever, daemon=True).start()
                servers.append(server)

            env = dict(os.environ, WORDPRESS_PATH=str(wordpress), TEST_CA=str(ca),
                       TLS_PORT=str(servers[0].server_port), BAD_TLS_PORT=str(servers[1].server_port),
                       CLOSED_PORT=str(free_port()), TEST_DB_PORT=str(database_port))
            result = subprocess.run(['php', str(Path(__file__).with_name('check.php'))], env=env, text=True, capture_output=True)
            print(result.stdout, end='')
            if result.returncode:
                raise RuntimeError(result.stderr + '\nSimulation log:\n' + log.read_text()[-4000:])

            result = subprocess.run(['php', str(Path(__file__).with_name('policy.php'))], env=env, text=True, capture_output=True)
            print(result.stdout, end='')
            if result.returncode:
                raise RuntimeError(result.stderr)

            # Inspect what the real dependency received, independently of PHP's
            # response assertions, including the write that must arrive once.
            connection = http.client.HTTPConnection('127.0.0.1', simulation_port)
            connection.request('GET', '/__requests', headers={'x-mock-slug': 'basalam'})
            requests = json.loads(connection.getresponse().read())
            connection.close()
            updates = [r for r in requests if r['method'] == 'PATCH'
                       and isinstance(r.get('body'), dict)
                       and r['body'].get('primary_price') in [12300, 45600]]
            creates = [r for r in requests if r['method'] == 'POST' and r['path'] == '/v4/vendors/1/products']
            assert len(updates) == 2, updates
            assert all(r['method'] == 'PATCH' for r in updates)
            assert updates[0]['path'] == '/v4/products/123?source=sync&origin=openapi.basalam.com'
            assert updates[0]['body'] == {'primary_price': 12300, 'stock': 2}, updates[0]
            assert updates[1]['path'] == '/v4/products/123'
            assert updates[1]['body'] == {'primary_price': 45600, 'stock': 3}, updates[1]
            assert all(r['headers']['host'] == 'core.basalam.com' for r in updates)
            assert len(creates) == 1 and creates[0]['method'] == 'POST', creates
            redirects = [r for r in requests if '__redirect=1' in r['path'] and r['method'] == 'POST'
                         and isinstance(r.get('body'), dict) and 'phase' in r['body']]
            assert len(redirects) == 2 and all(r['method'] == 'POST' for r in redirects), redirects
            assert {r['body']['phase'] for r in redirects} == {'primary', 'fallback'}, redirects
            assert {r['headers']['host'] for r in redirects} == {'openapi.basalam.com', 'core.basalam.com'}
            batch_contract = json.loads((ROOT / 'postman/contracts/basalam-core-fallback.openapi.json').read_text())
            batch_operation = batch_contract['paths']['/v4/vendors/{vendor_id}/products']['patch']
            batch_request_schema = batch_operation['requestBody']['content']['application/json']['schema']
            batch_response_schema = batch_operation['responses']['202']['content']['application/json']['schema']
            batch_validator = Draft202012Validator({**batch_request_schema, 'components': batch_contract['components']})
            batch_response_validator = Draft202012Validator({**batch_response_schema, 'components': batch_contract['components']})
            batch_payload = {'data': [
                {'id': 123, 'primary_price': 0, 'stock': 0, 'sku': 'SIMPLE-123'},
                {'id': 124, 'sku': 'VARIABLE-124', 'variants': [
                    {'id': 321, 'primary_price': 23400, 'stock': 3},
                    {'id': 322, 'primary_price': 0, 'stock': 0},
                ]},
            ]}
            batches = [r for r in requests if r['method'] == 'PATCH' and
                       r['path'].split('?')[0] in ['/v4/vendors/1/products', '/v1/vendors/1/products/batch-updates']]
            assert batches and all(r['body'] == batch_payload for r in batches), batches
            assert not any(r['path'].startswith('/v3/vendors/1/products') for r in batches), batches
            assert sum(r['path'] == '/v4/vendors/1/products?continue_on_error=true&source=batch' for r in batches) == 2
            assert sum('__partial=1' in r['path'] for r in batches) == 1
            for request in batches:
                batch_validator.validate(request['body'])
                assert 'continue_on_error=true' in request['path']
                expected_host = 'core.basalam.com' if request['path'].startswith('/v4/') else 'openapi.basalam.com'
                assert request['headers']['host'] == expected_host, request
            # Validate success and partial results against the provider's array
            # response, including the per-item error fields the bulk job reads.
            batch_schema_checks = 0
            for partial in ['', '&__partial=1']:
                connection = http.client.HTTPConnection('127.0.0.1', simulation_port)
                connection.request('PATCH', '/v4/vendors/1/products?continue_on_error=true' + partial,
                                   json.dumps(batch_payload), headers={'x-mock-slug': 'basalam', 'Host': 'core.basalam.com',
                                                                      'Content-Type': 'application/json'})
                response = connection.getresponse()
                assert response.status == 202
                batch_response_validator.validate(json.loads(response.read()))
                connection.close()
                batch_schema_checks += 1
            orders = [r for r in requests if r['headers']['host'] == 'order-processing.basalam.com']
            assert orders and all(r['method'] == 'GET' for r in orders), orders
            pages = [r for r in orders if r['path'].startswith('/v3/vendor-parcels?') and '__' not in r['path']]
            from urllib.parse import urlsplit, parse_qs
            queries = [parse_qs(urlsplit(r['path']).query) for r in pages]
            assert any(q.get('cursor') == ['opaque+/=?&%'] for q in queries), queries
            assert all(q.get('per_page') == ['10'] and q.get('created_at[gte]') for q in queries), queries
            sticky = [r for r in requests if '__sticky=' in r['path']]
            assert len(sticky) >= 20, sticky
            direct = [r for r in sticky if '__sticky=direct' in r['path']]
            assert {r['headers']['host'] for r in direct} == {
                'core.basalam.com', 'order-processing.basalam.com', 'webhook.basalam.com'}, direct
            assert not any(r['headers']['host'] == 'openapi.basalam.com' for r in direct), direct
            product = next(r for r in direct if r['method'] == 'PATCH')
            assert product['body'] == {'primary_price': 78900, 'stock': 0}, product
            # Check the synthetic nonempty/empty response against the copied
            # provider schema, rather than only mirroring importer expectations.
            contract = json.loads((ROOT / 'postman/contracts/basalam-orders-fallback.openapi.json').read_text())
            validator = Draft202012Validator({'$ref': '#/components/schemas/ParcelsResponse',
                                             'components': contract['components']})
            schema_checks = 0
            for path in ['/v3/vendor-parcels?per_page=10', '/v3/vendor-parcels?cursor=opaque', '/v3/vendor-parcels?__empty=1']:
                connection = http.client.HTTPConnection('127.0.0.1', simulation_port)
                connection.request('GET', path, headers={'x-mock-slug': 'basalam', 'Host': 'order-processing.basalam.com'})
                validator.validate(json.loads(connection.getresponse().read()))
                connection.close()
                schema_checks += 1
            webhook_contract = json.loads((ROOT / 'postman/contracts/basalam-webhooks-fallback.openapi.json').read_text())
            webhook_requests = [r for r in requests if r['path'].startswith('/v1/webhooks')]
            webhook_writes = [r for r in webhook_requests if '__' not in r['path'] and r['method'] in ['POST', 'PATCH', 'DELETE']]
            assert {method: sum(r['method'] == method for r in webhook_writes) for method in ['POST', 'PATCH', 'DELETE']} == {
                'POST': 5, 'PATCH': 4, 'DELETE': 2,
            }, webhook_writes
            assert all(r['headers']['host'] == 'webhook.basalam.com' for r in webhook_writes)
            for request in webhook_writes:
                if request['method'] == 'DELETE':
                    # DeleteApiService's existing empty array body is unchanged.
                    assert request['body'] == [], request
                    continue
                path = '/v1/webhooks' if request['method'] == 'POST' else '/v1/webhooks/{webhook_id}'
                operation = webhook_contract['paths'][path][request['method'].lower()]
                schema = operation['requestBody']['content']['application/json']['schema']
                Draft202012Validator({**schema, 'components': webhook_contract['components']}).validate(request['body'])
                assert request['body']['request_headers'] == '{"token":"local-webhook-token"}'
                assert request['body']['request_method'] == 'POST'
                assert request['body']['url'] in [
                    'https://store.test/wp-json/sync-basalam/v1/order-manager',
                    'https://another-store.test/wp-json/sync-basalam/v1/order-manager']
                if request['method'] == 'POST':
                    assert request['body']['register_me'] is True
                else:
                    assert 'register_me' not in request['body']
            assert sum(r['body'].get('url', '').startswith('https://another-store.test/')
                       for r in webhook_writes if isinstance(r.get('body'), dict)) == 2
            filtered_list = [r for r in webhook_requests if r['path'] == '/v1/webhooks?event_ids=3%2C5%2C7&service_id=1']
            assert len(filtered_list) == 1 and filtered_list[0]['headers']['host'] == 'webhook.basalam.com'
            # Validate all four response shapes independently against the live
            # provider spec; only synthetic subscriptions in the local fixture
            # are mutated by these checks.
            webhook_schema_checks = 0
            schema_id = None
            webhook_payload = {'event_ids': [3, 5, 7], 'request_method': 'POST',
                               'request_headers': '{"token":"local-webhook-token"}',
                               'url': 'https://store.test/wp-json/sync-basalam/v1/order-manager',
                               'is_active': True, 'register_me': True}
            for method in ['GET', 'POST', 'PATCH', 'DELETE']:
                path = '/v1/webhooks' if method in ['GET', 'POST'] else '/v1/webhooks/{webhook_id}'
                operation = webhook_contract['paths'][path][method.lower()]
                actual_path = path.replace('{webhook_id}', str(schema_id))
                payload = dict(webhook_payload)
                if method == 'PATCH': payload.pop('register_me')
                connection = http.client.HTTPConnection('127.0.0.1', simulation_port)
                connection.request(method, actual_path, json.dumps(payload) if method in ['POST', 'PATCH'] else None,
                                   headers={'x-mock-slug': 'basalam', 'Host': 'webhook.basalam.com', 'Content-Type': 'application/json'})
                response = connection.getresponse()
                assert response.status == 200
                body = json.loads(response.read())
                schema = operation['responses']['200']['content']['application/json']['schema']
                Draft202012Validator({**schema, 'components': webhook_contract['components']}).validate(body)
                if method == 'POST': schema_id = body['id']
                connection.close()
                webhook_schema_checks += 1
            assert {r['headers']['host'] for r in requests} == {'openapi.basalam.com', 'core.basalam.com', 'order-processing.basalam.com', 'webhook.basalam.com'}
            assert all(r['headers']['authorization'] == 'Bearer local-test-token' for r in requests)
            assert sni and set(sni) == {'openapi.basalam.com', 'core.basalam.com', 'order-processing.basalam.com', 'webhook.basalam.com'}, sni
            events = [json.loads(line) for line in log.read_text().splitlines() if line.startswith('{')]
            assert any(event.get('type') == 'request' or event.get('event') == 'request' for event in events), events
            print(json.dumps({'simulation_requests': len(requests), 'product_updates': len(updates),
                              'product_creates': len(creates), 'order_requests': len(orders),
                              'batch_requests': len(batches), 'batch_provider_schema_checks': batch_schema_checks,
                              'webhook_requests': len(webhook_requests), 'webhook_writes': len(webhook_writes),
                              'webhook_provider_schema_checks': webhook_schema_checks,
                              'provider_schema_checks': schema_checks,
                              'service_host_and_sni_verified': True}))
        finally:
            for server in servers:
                server.shutdown()
                server.server_close()
            os.killpg(process.pid, signal.SIGINT)
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGTERM)
                process.wait(timeout=5)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--wordpress', type=Path, required=True, help='WordPress source; no store config is used. Requires Docker/MariaDB 10.6.')
    args = parser.parse_args()
    with database() as database_port:
        run(args.wordpress.resolve(), database_port)
