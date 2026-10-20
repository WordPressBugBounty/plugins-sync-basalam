#!/usr/bin/env python3
"""Real HTTP workers on disposable Apache, Nginx/FPM, Caddy/FPM and OLS."""
import argparse
import concurrent.futures
import hashlib
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
ORIGINAL = '# preserved matrix configuration\r\n'
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
        source = HERE.parents[2]
        self.report = {
            'success': False, 'servers': [], 'source_commit': self.git_head(),
            'source_files_sha256': {
                name: hashlib.sha256((source / name).read_bytes()).hexdigest()
                for name in ['JobsRunner.php', 'sync-basalam.php',
                             'includes/Services/AsyncWorkerEnvironment.php',
                             'includes/Services/AsyncWorkerHealth.php',
                             'includes/Services/AsyncWorkerProtection.php']
            },
        }
        self.assertions = 0

    def git_head(self):
        return subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=HERE, text=True).strip()

    def compose(self, *args, check=True):
        result = subprocess.run(self.command + list(args), env=self.env, text=True, capture_output=True)
        if check and result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result

    def write_htaccess(self, contents):
        # Mirror the installer's atomic replacement. Truncating a bind-mounted
        # file can expose incomplete rules to concurrent Apache reads.
        temporary = self.site / '.htaccess.matrix-tmp'
        with temporary.open('wb') as stream:
            stream.write(contents)
            stream.flush()
            os.fsync(stream.fileno())
        temporary.replace(self.site / '.htaccess')

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
        last_error = None
        while time.monotonic() < deadline:
            try:
                return self.control()
            except (OSError, ValueError, RuntimeError) as error:
                last_error = str(error)
                time.sleep(.3)
        raise RuntimeError(f'HTTP server did not become ready: {self.url}: {last_error}')

    def reload_rules(self):
        # Recreate the disposable OLS runtime so killed LSAPI workers cannot
        # leave stale Unix sockets/PID files across repeated configuration tests.
        self.compose('up', '-d', '--force-recreate', 'ols')
        self.ready()

    def verify_rule_repair(self, result, scenario):
        self.control('reset')
        self.write_htaccess(b'')
        self.reload_rules()
        self.control('enqueue', key='unprotected')
        self.worker()
        state = self.completed('unprotected')
        self.check(state['notice']['class'] == 'notice-warning', 'Actual LSAPI without rule must record unverified protection internally')
        self.check(state['health']['last_returned_protected'] is False, 'Missing actual noabort flag must be recorded')
        self.check(self.control('notice')['html'] == '', 'Missing protection must not display an admin alert')
        result['unprotected_evidence'] = state
        scenario('actual LSAPI without htaccess protection records an internal diagnosis even after fast completion')
        self.control('protect', force=1)
        self.check(b'E=noabort:1' in (self.site / '.htaccess').read_bytes(), 'Administrator setup must repair a removed rule')
        self.reload_rules()
        self.control('enqueue', key='protection-restored', seconds=3, dispatch=1)
        state = self.completed('protection-restored')
        self.check(state['notice'] is None and state['health']['last_returned_protected'] is True, 'Automatic rule repair must be verified by real execution')
        result['restored_evidence'] = state
        scenario('automatic rule repair is observed in real LSAPI and clears internal missing-protection evidence')

    def finish_server(self, result, start_assertions, services):
        self.control('reset')
        result['assertions'] = self.assertions - start_assertions
        result['success'] = True
        self.compose('stop', *services)

    def run_server(self, name):
        port, backend, services, expected_sapi = SERVERS[name]
        self.write_htaccess(ORIGINAL.encode())
        self.url = f'http://127.0.0.1:{port}/wp-admin/admin-ajax.php'
        self.compose('up', '-d', *services)
        state = self.ready()
        self.check(state['sapi'] == expected_sapi, f'{name}: actual web SAPI must be {expected_sapi}')
        result = {'server': name, 'runtime': {k: state[k] for k in ['sapi', 'php', 'server']}, 'scenarios': []}
        start_assertions = self.assertions
        self.report['servers'].append(result)
        scenario = lambda text: (result['scenarios'].append(text), print(f'{name}: PASS {text}', flush=True))

        self.control('reset')
        before = (self.site / '.htaccess').read_bytes()
        self.check(self.control('protect', role='anonymous')['html'] == '', 'Anonymous protection check must be silent')
        self.check((self.site / '.htaccess').read_bytes() == before, 'Anonymous requests cannot write server configuration')
        self.check(self.control('protect', role='subscriber')['html'] == '', 'Subscriber protection check must be silent')
        self.check((self.site / '.htaccess').read_bytes() == before, 'Subscribers cannot write server configuration')
        self.check(self.control('protect', file_mods='denied')['html'] == '', 'Disabled file modifications must remain silent')
        self.check((self.site / '.htaccess').read_bytes() == before, 'DISALLOW_FILE_MODS must prevent configuration writes')
        setup = self.control('protect')
        self.check(setup['html'] == '', 'Automatic administrator setup must not show an alert')
        after = (self.site / '.htaccess').read_bytes()
        if name == 'ols':
            self.check(after.endswith(before) and after.count(b'# BEGIN SyncBasalam Async') == 1, f'LiteSpeed must install one scoped block while preserving original bytes: {setup}')
            self.check(self.control()['protection']['status'] == 'installed', 'Installation status must be persisted internally')
            self.check(self.control()['backup_sha256'] == hashlib.sha256(before).hexdigest(), 'Backup must contain the exact previous configuration')
            self.check(self.control()['backup_autoload'] in ['off', 'no'], 'Configuration backup must not autoload')
            self.control('protect')
            self.check((self.site / '.htaccess').read_bytes() == after, 'Repeated admin requests must not duplicate the rule')
        else:
            self.check(after == before, 'Other actual webservers must not change htaccess')
        self.check(not self.control()['effective_noabort'], 'noabort must not apply to unrelated AJAX requests')
        scenario('automatic privileged configuration, permission denial, byte preservation and no visible alert')

        if name == 'ols':
            # OpenLiteSpeed 1.9.3 loads these rules on server reload. Installing
            # the file must not be confused with effective runtime protection.
            self.worker()
            state = self.control()
            reload_required = state['health'].get('protection_missing', False)
            result['configuration_reload_required'] = reload_required
            if reload_required:
                self.check(self.control('notice')['html'] == '', 'Unapplied host configuration must not display an alert')
                self.reload_rules()
            scenario('file installation is distinct from runtime verification; apply required OpenLiteSpeed reload')

        if name != 'ols':
            # Exercise the exact block even where automatic installation skips
            # it. Apache must evaluate the guard; Nginx/Caddy must stay healthy
            # with the otherwise-unused file present.
            rules = self.control('rules')['text'].encode()
            self.write_htaccess(rules + b'\n' + before)
            self.check(not self.control()['effective_noabort'], 'A manually present block must not protect unrelated requests')
            scenario('exact managed block is present on a non-LiteSpeed server without breaking HTTP requests')

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
        if name in ['apache', 'ols']:
            event = state['events']['success']
            self.check(event['litespeed_module'] is (name == 'ols'), 'Actual module evaluation must identify LiteSpeed correctly')
            self.check(event['other_module'] is (name == 'apache'), 'Actual Apache probe must identify the non-LiteSpeed module branch')
        result['healthy_evidence'] = state
        scenario('actual queue success, runtime protection, cleanup and non-autoloaded health')

        if self.args.protection_only:
            self.control('reset')
            dispatched = self.control('enqueue', key='protected-background', seconds=3, dispatch=1)
            state = self.completed('protected-background')
            event = state['events']['protected-background']
            self.check(dispatched['dispatch_returned_at'] < event['returned_at'], 'Dispatcher must return before the background job completes')
            self.check(event['returned_at'] - event['started_at'] >= 2.9, 'The delayed background job must actually run to completion')
            self.check(state['health']['last_returned_protected'] is (True if name == 'ols' else None), 'Background protection must match the actual server')
            self.check(self.control('notice')['html'] == '', 'Completed background execution must not display an alert')
            result['background_evidence'] = event
            scenario('real three-second nonblocking background job completes once after its caller returns')
            if name == 'ols':
                self.verify_rule_repair(result, scenario)
            self.finish_server(result, start_assertions, services)
            return

        self.control('reset')
        for index in range(5):
            self.control('enqueue', key=f'continuation-{index}', seconds=.65)
        first = self.control('dispatch')
        self.check(bool(first['recovery_at']), 'Initial dispatch must schedule a recovery event')
        state = self.wait(lambda s: not s['jobs'] and s['lock_free'])
        events = [state['events'][f'continuation-{index}'] for index in range(5)]
        self.check(all(e['starts'] == 1 and e['returns'] == 1 for e in events), 'One dispatch must drain multiple batches exactly once without visitor requests')
        self.check(all(events[i]['returned_at'] <= events[i + 1]['started_at'] for i in range(4)), 'Successor jobs must not overlap')
        self.check(state['recovery_at'] == first['recovery_at'], 'Batch handoffs must reuse one recovery event')
        self.check(self.control('recover')['recovery_at'] is False, 'Recovery must stop rearming once the queue is empty')
        result['continuation_evidence'] = events
        scenario('one loopback dispatch drains five jobs across multiple batches with cron disabled and no visitors')

        self.control('reset')
        self.control('enqueue', key='rejected', mode='reject')
        self.worker()
        state = self.control()
        self.check(state['jobs'][0]['status'] == 'failed' and '422' in state['jobs'][0]['error_message'], 'API rejection must be preserved')
        self.check(state['notice'] is None, 'API rejection must not produce a hosting alert')
        scenario('explicit API 422 remains separate from host interruption')

        self.control('reset')
        began = time.monotonic()
        dispatched = self.control('enqueue', key='disconnected', seconds=3, dispatch=1)
        dispatch_seconds = time.monotonic() - began
        state = self.completed('disconnected')
        self.check(dispatched['dispatch_returned_at'] < state['events']['disconnected']['returned_at'], 'Dispatcher must return before the background job completes; exclude HTTP bootstrap latency')
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
        self.check(html == '', 'Interrupted workers must not display an admin alert')
        self.check(('noabort' in state['notice']['guidance']) == (name == 'ols'), 'Internal hosting diagnosis must match actual runtime')
        self.check(self.control('notice', role='anonymous')['html'] == '', 'Anonymous callers cannot see the admin notice')
        result['kill_evidence'] = {'pid': pid, 'health': state['health'], 'notice': state['notice']}
        scenario('SIGKILL of actual HTTP PHP worker, silent independent detection and admin permissions')

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
            self.verify_rule_repair(result, scenario)

        self.finish_server(result, start_assertions, services)

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
        self.write_htaccess(ORIGINAL.encode())
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
            self.output.joinpath('ols-error.log').write_text(self.compose('exec', '-T', 'ols', 'cat', '/usr/local/lsws/logs/error.log', check=False).stdout)
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
    parser.add_argument('--protection-only', action='store_true', help='Focus on automatic htaccess configuration, actual module detection and silent diagnostics')
    Matrix(parser.parse_args()).run()
