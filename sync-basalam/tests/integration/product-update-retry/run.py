#!/usr/bin/env python3
"""Run real plugin HTTP requests against a local Postman failure simulation."""
import argparse
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[3]


def run(wordpress, store_container=None, report_dir=None):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with tempfile.TemporaryDirectory(prefix='woosalam-product-retry-') as temporary:
        log = Path(temporary) / 'simulation.ndjson'
        err = Path(temporary) / 'simulation.err'
        process = subprocess.Popen([
            'postman', 'simulation', 'run', str(ROOT / 'postman/simulations/product-update-retry.sim.yaml'),
            '--port', str(port), '--no-history', '--output', 'ndjson',
        ], cwd=ROOT, stdout=log.open('w'), stderr=err.open('w'), start_new_session=True)
        bridge = None
        try:
            deadline = time.monotonic() + 20
            while '"listening"' not in log.read_text():
                if process.poll() is not None or time.monotonic() > deadline:
                    raise RuntimeError(err.read_text() + log.read_text())
                time.sleep(0.1)
            if store_container:
                # Postman binds to loopback. Forward only this synthetic fixture
                # so WordPress in Docker can use the same dependency over HTTP.
                class Bridge(BaseHTTPRequestHandler):
                    def forward(self):
                        connection = http.client.HTTPConnection('127.0.0.1', port, timeout=10)
                        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
                        try:
                            connection.request(self.command, self.path, body, dict(self.headers))
                            response = connection.getresponse()
                            payload = response.read()
                            self.send_response(response.status)
                            self.send_header('Content-Type', response.getheader('Content-Type', 'application/json'))
                            self.send_header('Content-Length', str(len(payload)))
                            self.end_headers()
                            self.wfile.write(payload)
                        except (BrokenPipeError, ConnectionResetError):
                            pass
                        finally:
                            connection.close()
                    do_GET = do_PATCH = forward
                    def log_message(self, *args):
                        pass
                bridge = ThreadingHTTPServer(('0.0.0.0', 0), Bridge)
                threading.Thread(target=bridge.serve_forever, daemon=True).start()
                result = subprocess.run(['docker', 'exec', '-e',
                    f'PRODUCT_RETRY_SIMULATION_URL=http://host.docker.internal:{bridge.server_port}',
                    '-w', '/var/www/html/wp-content/plugins/sync-basalam', store_container,
                    'php', 'tests/integration/product-update-retry/store.php'],
                    text=True, capture_output=True, timeout=120)
            else:
                result = subprocess.run(['php', str(Path(__file__).with_name('check.php'))],
                    env=dict(os.environ, WORDPRESS_PATH=str(wordpress), SIMULATION_URL=f'http://127.0.0.1:{port}'),
                    text=True, capture_output=True, timeout=45)
            print(result.stdout, end='')
            if result.returncode:
                raise RuntimeError(result.stderr + log.read_text()[-4000:])
            connection = http.client.HTTPConnection('127.0.0.1', port)
            connection.request('GET', '/__requests', headers={'x-mock-slug': 'basalam'})
            requests = json.loads(connection.getresponse().read())
            connection.close()
            cases = {}
            for request in requests:
                cases.setdefault(request['test'], []).append(request)
            if store_container:
                report = json.loads(next(line for line in result.stdout.splitlines() if line.startswith('{')))
                assert report['store_scenarios'] == 52, report
                assert report['http_requests'] == len(requests), report
                for case in report['cases']:
                    matching = [calls for key, calls in cases.items() if key.endswith('-' + case['name'])]
                    assert sum(map(len, matching)) == case['requests'], case
                assert any('/variations/' in call['path'] for call in requests)
                assert any('/batch-updates' in call['path'] for call in requests)
                assert all(call['method'] == 'PATCH' for call in requests)
                if report_dir:
                    report_dir.mkdir(parents=True, exist_ok=True)
                    (report_dir / 'store-report.json').write_text(json.dumps(report, indent=2))
                    (report_dir / 'store-requests.json').write_text(json.dumps(requests, indent=2))
                print(json.dumps({'store_http_requests': len(requests), 'outgoing_requests': 'verified'}))
                return
            for name, calls in cases.items():
                expected = 1 if name.startswith('other-') or name == 'success' else 2
                assert len(calls) == expected, (name, calls)
                if expected == 1:
                    continue
                body = calls[1]['body']
                if name.startswith('variable-') or name == 'custom-variable':
                    assert body == {'variants': [{'primary_price': 25000, 'stock': 0,
                        'properties': [{'property': 'Color', 'value': 'Red'}]}]}, (name, body)
                elif name.startswith('batch-'):
                    assert body == {'data': [{'id': 123, 'primary_price': 25000, 'stock': 0}]}, body
                    assert calls[0]['body']['data'][0]['sku'] == 'BATCH-SKU', calls[0]
                    assert calls[1]['path'] == '/v1/vendors/1/products/batch-updates?continue_on_error=true', calls[1]
                else:
                    assert body == {'primary_price': 25000, 'stock': 0}, (name, body)
            assert len(cases) == 22, cases.keys()
            assert any('"request"' in line for line in log.read_text().splitlines()), 'No simulation requests recorded'
            print(json.dumps({'scenarios': len(cases), 'http_requests': len(requests), 'outgoing_payloads': 'verified'}))
        finally:
            if bridge:
                bridge.shutdown()
                bridge.server_close()
            try:
                os.killpg(process.pid, signal.SIGINT)
                process.wait(timeout=5)
            except (ProcessLookupError, subprocess.TimeoutExpired):
                pass


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--wordpress', type=Path, required=True, help='WordPress source only; no wp-config or database is loaded.')
    parser.add_argument('--store-container', help='Also load the actual local WordPress/WooCommerce database in this Docker container.')
    parser.add_argument('--report-dir', type=Path, help='Save synthetic store assertion and outgoing-request evidence here.')
    args = parser.parse_args()
    run(args.wordpress.resolve(), args.store_container, args.report_dir)
