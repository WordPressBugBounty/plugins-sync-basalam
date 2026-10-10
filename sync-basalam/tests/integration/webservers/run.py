#!/usr/bin/env python3
"""Real HTTP workers on disposable Apache, Nginx/FPM, Caddy/FPM and OLS."""
import argparse
import concurrent.futures
import json
import os
from pathlib import Path
import secrets
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

HERE = Path(__file__).resolve().parent
PROTECTED = """# BEGIN SyncBasalam Async
<IfModule LiteSpeed>
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_URI} /wp-admin/admin-ajax\\.php$
RewriteCond %{QUERY_STRING} (^|&)action=sync_basalam_run_jobs_async(&|$)
RewriteRule .* - [E=noabort:1]
</IfModule>
</IfModule>
# END SyncBasalam Async
"""
SERVERS = {
    'apache': (8141, 'apache', ['apache'], 'apache2handler'),
    'nginx': (8142, 'fpm', ['fpm', 'nginx'], 'fpm-fcgi'),
    'caddy': (8143, 'caddy-fpm', ['caddy-fpm', 'caddy'], 'fpm-fcgi'),
    'ols': (8144, 'ols', ['ols'], 'litespeed'),
}


class Matrix:
    def __init__(self, args):
        self.args = args
        self.site = args.site_dir.resolve()
        self.output = args.results.resolve()
        self.output.mkdir(parents=True, exist_ok=True)
        self.token = secrets.token_hex(32)
        self.env = dict(os.environ, MATRIX_TOKEN=self.token, MATRIX_SITE_DIR=str(self.site))
        self.command = ['docker', 'compose', '-f', str(HERE / 'compose.yml')]
        self.report = {'success': False, 'servers': [], 'source_commit': self.git_head()}
        self.assertions = 0

    def git_head(self):
        return subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=HERE, text=True).strip()

    def compose(self, *args, check=True):
        result = subprocess.run(self.command + list(args), env=self.env, text=True, capture_output=True)
        if check and result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result

    def http(self, fields, control=True, timeout=15):
        body = urllib.parse.urlencode(fields).encode()
        headers = {'X-Matrix-Token': self.token} if control else {}
        url = self.url if control else self.url + '?action=sync_basalam_run_jobs_async'
        request = urllib.request.Request(url, data=body, headers=headers)
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                return response.status, json.loads(response.read())
        except urllib.error.HTTPError as error:
            raw = error.read()
            try:
                data = json.loads(raw)
            except json.JSONDecodeError:
                data = {'body': raw.decode(errors='replace')[:1000]}
            return error.code, data

    def control(self, op='state', **fields):
        status, data = self.http(dict(action='sync_basalam_matrix_control', op=op, **fields))
        if status != 200 or not data.get('success'):
            raise RuntimeError(f'Control {op}: {status} {data}')
        self.last_state = data['data']
        return data['data']

    def worker(self, nonce=None):
        if nonce is None:
            nonce = self.control()['nonce']
        try:
            return self.http(dict(action='sync_basalam_run_jobs_async', nonce=nonce), False, 90)
        except (OSError, ValueError) as error:
            return 0, {'error': str(error)}

    def wait(self, predicate, seconds=20):
        deadline = time.monotonic() + seconds
        last = None
        while time.monotonic() < deadline:
            last = self.control()
            if predicate(last):
                return last
            time.sleep(.15)
        raise RuntimeError(f'Timed out waiting for worker: {last}')

    def check(self, condition, description):
        if not condition:
            raise AssertionError(description)
        self.assertions += 1

    def completed(self, key):
        state = self.wait(lambda s: s['events'].get(key, {}).get('returns') == 1 and s['lock_free'])
        self.check(not state['jobs'], f'{key}: success must delete the queue record')
        self.check(state['events'][key]['starts'] == 1, f'{key}: job executed exactly once')
        return state

    def ready(self):
        deadline = time.monotonic() + 45
        while time.monotonic() < deadline:
            try:
                return self.control()
            except (OSError, ValueError, RuntimeError):
                time.sleep(.3)
        raise RuntimeError(f'HTTP server did not become ready: {self.url}')

    def run_server(self, name):
        port, backend, services, expected_sapi = SERVERS[name]
        self.url = f'http://127.0.0.1:{port}/wp-admin/admin-ajax.php'
        self.compose('up', '-d', *services)
        state = self.ready()
        self.check(state['sapi'] == expected_sapi, f'{name}: actual web SAPI must be {expected_sapi}')
        result = {'server': name, 'runtime': {k: state[k] for k in ['sapi', 'php', 'server']}, 'scenarios': []}
        start_assertions = self.assertions
        self.report['servers'].append(result)
        scenario = lambda text: (result['scenarios'].append(text), print(f'{name}: PASS {text}', flush=True))

        self.control('reset')
        status, _ = self.worker('invalid-matrix-nonce')
        self.check(status == 403, f'{name}: invalid nonce must be rejected')
        status, body = self.worker()
        self.check(status == 200 and body['data']['processed'] == 0, 'Valid empty worker must return zero')
        scenario('real HTTP nonce rejection, acceptance and empty batch')

        self.control('reset')
        self.control('enqueue', key='success')
        status, body = self.worker()
        self.check(status == 200 and body['data']['processed'] == 1, 'Worker must execute one job')
        state = self.completed('success')
        self.check(state['notice'] is None, 'Healthy runtime must not alert')
        self.check(state['health']['last_returned_protected'] is (True if name == 'ols' else None), 'Protection must match the real runtime')
        self.check(state['autoload'] in ['off', 'no'], 'Health metadata must not autoload')
        result['healthy_evidence'] = state
        scenario('actual queue success, runtime protection, cleanup and non-autoloaded health')

        self.control('reset')
        self.control('enqueue', key='rejected', mode='reject')
        self.worker()
        state = self.control()
        self.check(state['jobs'][0]['status'] == 'failed' and '422' in state['jobs'][0]['error_message'], 'API rejection must be preserved')
        self.check(state['notice'] is None, 'API rejection must not produce a hosting alert')
        scenario('explicit API 422 remains separate from host interruption')

        self.control('reset')
        began = time.monotonic()
        self.control('enqueue', key='disconnected', seconds=3, dispatch=1)
        dispatch_seconds = time.monotonic() - began
        state = self.completed('disconnected')
        self.check(dispatch_seconds < 2.5, 'Nonblocking caller must return before the three-second job completes')
        self.check(state['events']['disconnected']['returned_at'] - state['events']['disconnected']['started_at'] >= 2.9, 'Background job must finish after client disconnection')
        self.check(state['notice'] is None, 'Completed background request must not alert')
        result['disconnect_evidence'] = {'caller_seconds': round(dispatch_seconds, 3), 'worker': state['events']['disconnected']}
        scenario('normal nonblocking WordPress loopback survives client disconnection')

        self.control('reset')
        job = self.control('enqueue', key='concurrent', seconds=3)['id']
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            futures = [pool.submit(self.worker) for _ in range(4)]
            self.wait(lambda s: not s['lock_free'] and s['events'].get('concurrent', {}).get('starts') == 1)
            self.control('age', id=job)
            html = self.control('notice')['html']
            self.check('اجرای همگام‌سازی نیمه‌کاره' not in html, 'Slow live worker must not be called interrupted')
            responses = [f.result() for f in futures]
        self.check(all(status == 200 for status, _ in responses), 'All concurrent requests must return HTTP 200')
        self.check(sorted(body['data']['processed'] for _, body in responses) == [0, 0, 0, 1], 'Only one runner may execute the job')
        self.completed('concurrent')
        scenario('four real HTTP workers, real advisory lock, no slow-worker false alarm')

        self.control('reset')
        job = self.control('enqueue', key='killed', seconds=60)['id']
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            future = pool.submit(self.worker)
            state = self.wait(lambda s: not s['lock_free'] and 'killed' in s['events'])
            pid = int(state['events']['killed']['pid'])
            self.check(pid > 1, 'Kill must target a PHP worker, never container init')
            self.compose('exec', '-T', backend, 'sh', '-c', 'kill -9 "$1"', 'matrix-kill', str(pid))
            future.result()
        self.wait(lambda s: s['lock_free'])
        self.control('age', id=job)
        html = self.control('notice')['html']
        state = self.control()
        self.check(state['notice']['class'] == 'notice-error' and state['notice']['job_id'] == job, 'A later HTTP admin request must detect the dead worker')
        self.check(str(job) in html and 'role="alert"' in html, 'Admin notice must identify the job')
        self.check(('noabort' in html) == (name == 'ols'), 'Hosting advice must match actual runtime')
        self.check(self.control('notice', role='anonymous')['html'] == '', 'Anonymous callers cannot see the admin notice')
        result['kill_evidence'] = {'pid': pid, 'health': state['health'], 'notice': state['notice']}
        scenario('SIGKILL of actual HTTP PHP worker, independent detection and admin permissions')

        self.compose('restart', backend)
        self.ready()
        self.check(self.control()['notice']['job_id'] == job, 'Interruption evidence must persist across server restart')
        self.control('retry', id=job)
        self.worker()
        state = self.control()
        self.check(not state['jobs'] and state['notice'] is None, 'Normal stale recovery must complete the job and clear warning')
        recovered = state['events']['killed']
        attempts = [entry['attempts'] for entry in recovered['history']]
        # A webserver may replay a failed upstream request. Each real execution
        # must be a distinct queue attempt; only the recovered attempt returns.
        self.check(recovered['starts'] >= 2 and recovered['returns'] == 1 and attempts == list(range(recovered['starts'])), 'Every recovery start must be a distinct queue attempt, with exactly one return')
        result['recovery_evidence'] = recovered
        scenario('restart persistence, real stale recovery and warning clearance')

        self.control('reset')
        self.control('lease')
        self.control('enqueue', key='orphaned-lease', dispatch=1)
        self.completed('orphaned-lease')
        scenario('legacy dispatch lock without expiration cannot block automatic execution')

        self.control('reset')
        job = self.control('enqueue', key='fatal', mode='fatal')['id']
        self.worker()
        state = self.wait(lambda s: s['lock_free'])
        self.check(state['jobs'][0]['status'] == 'processing', 'Fatal PHP error must leave observable interrupted job')
        self.control('age', id=job)
        self.control('notice')
        self.check(self.control()['notice']['job_id'] == job, 'Fatal PHP error must be detected on a later admin request')
        self.control('retry', id=job)
        self.worker()
        self.check(self.control()['notice'] is None, 'Successful retry after fatal must clear interruption')
        scenario('actual PHP fatal, later detection and successful recovery')

        if name in ['nginx', 'caddy']:
            self.control('reset')
            self.compose('exec', '-T', backend, 'sh', '-c', "printf '%s\\n' '[www]' 'request_terminate_timeout = 4s' > /usr/local/etc/php-fpm.d/zz-matrix-timeout.conf")
            self.compose('restart', backend)
            self.ready()
            job = self.control('enqueue', key='fpm-timeout', seconds=10)['id']
            status, _ = self.worker()
            self.check(status in [0, 502, 503, 504], 'FPM timeout must interrupt HTTP execution')
            state = self.wait(lambda s: s['lock_free'])
            self.check(state['events']['fpm-timeout']['returns'] == 0, 'FPM must actually terminate the worker before return')
            self.control('age', id=job)
            self.control('notice')
            self.check(self.control()['notice']['job_id'] == job, 'Resource timeout must produce an interruption alert')
            self.compose('exec', '-T', backend, 'rm', '/usr/local/etc/php-fpm.d/zz-matrix-timeout.conf')
            self.compose('restart', backend)
            self.ready()
            self.control('retry', id=job)
            self.worker()
            self.check(self.control()['notice'] is None, 'Recovery after FPM timeout must clear alert')
            scenario('real PHP-FPM request timeout, interruption alert and recovery')

        if name == 'ols':
            self.control('reset')
            (self.site / '.htaccess').write_text('')
            self.compose('restart', 'ols')
            self.ready()
            self.control('enqueue', key='unprotected')
            self.worker()
            state = self.completed('unprotected')
            self.check(state['notice']['class'] == 'notice-warning', 'Actual LSAPI without rule must report unverified protection')
            self.check(state['health']['last_returned_protected'] is False, 'Missing actual noabort flag must be recorded')
            result['unprotected_evidence'] = state
            scenario('actual LSAPI without htaccess protection warns even after fast completion')
            (self.site / '.htaccess').write_text(PROTECTED)
            self.compose('restart', 'ols')
            self.ready()
            self.control('enqueue', key='protection-restored', seconds=3, dispatch=1)
            state = self.completed('protection-restored')
            self.check(state['notice'] is None and state['health']['last_returned_protected'] is True, 'Restoring actual scoped rule must clear warning after real execution')
            scenario('restored scoped htaccess rule is observed in LSAPI and clears warning')

        self.control('reset')
        result['assertions'] = self.assertions - start_assertions
        result['success'] = True
        self.compose('stop', *services)

    def run(self):
        self.check((self.site / 'wp-load.php').is_file(), 'Supply a disposable WordPress core')
        self.check((self.site / 'wp-content/plugins/woocommerce/woocommerce.php').is_file(), 'Supply WooCommerce in the disposable site')
        if (self.site / 'wp-config.php').exists() and not (
            (self.site / '.sync-basalam-matrix').is_file() and (self.site / 'wp-config.php').stat().st_size == 0
        ):
            raise RuntimeError('Refusing a directory containing wp-config.php; use an unconfigured disposable copy.')
        (self.site / '.sync-basalam-matrix').write_text('Disposable sync-basalam HTTP worker test site\n')
        (self.site / 'wp-content/mu-plugins').mkdir(parents=True, exist_ok=True)
        (self.site / 'wp-config.php').touch()
        (self.site / 'wp-content/mu-plugins/matrix.php').touch()
        (self.site / 'wp-content/plugins/sync-basalam').mkdir(exist_ok=True)
        (self.site / '.htaccess').write_text(PROTECTED)
        try:
            self.compose('up', '-d', '--wait', 'db', 'apache')
            self.compose('exec', '-T', 'apache', 'php', '/matrix/install.php')
            self.compose('stop', 'apache')
            for name in self.args.servers:
                self.run_server(name)
            self.report['success'] = True
        except BaseException as error:
            self.report['error'] = str(error)
            self.report['last_state'] = getattr(self, 'last_state', None)
            raise
        finally:
            self.report['assertions'] = self.assertions
            self.output.joinpath('results.json').write_text(json.dumps(self.report, indent=2, ensure_ascii=False))
            self.output.joinpath('containers.log').write_text(self.compose('logs', '--no-color', check=False).stdout)
            images = subprocess.run(['docker', 'image', 'inspect', 'wordpress:php8.2-apache', 'wordpress:php8.2-fpm', 'nginx:1.29-alpine', 'caddy:2-alpine', 'litespeedtech/openlitespeed:1.9.3-lsphp83'], text=True, capture_output=True)
            if images.returncode == 0:
                self.output.joinpath('images.json').write_text(json.dumps([{k: image[k] for k in ['Id', 'RepoDigests', 'Architecture']} for image in json.loads(images.stdout)], indent=2))
            if not self.args.keep:
                self.compose('down', '-v', check=False)
        print(f'PASS {len(self.report["servers"])} webservers, {self.assertions} assertions', flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site-dir', type=Path, required=True)
    parser.add_argument('--results', type=Path, required=True)
    parser.add_argument('--servers', nargs='+', choices=SERVERS, default=list(SERVERS))
    parser.add_argument('--keep', action='store_true', help='Keep disposable containers after the run for debugging')
    Matrix(parser.parse_args()).run()
