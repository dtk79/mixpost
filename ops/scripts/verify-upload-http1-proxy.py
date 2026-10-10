#!/usr/bin/env python3
"""Verify scoped TLS negotiation and rollback in an isolated exact proxy image.

No production volumes, Docker socket, host ports, or external network. This
verifies protocol selection only; absent backend responses are expected 502s.
"""
import argparse
import hashlib
import json
import pathlib
import re
import shutil
import subprocess
import tempfile
import time
import uuid


def run(*args):
    return subprocess.check_output(args, text=True, timeout=20).strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', required=True, help='Already-present immutable Traefik image ID')
    parser.add_argument('--config', type=pathlib.Path,
                        default=pathlib.Path(__file__).resolve().parents[1] / 'production-network/mixpost-upload-http1.yml')
    args = parser.parse_args()
    if not re.fullmatch(r'sha256:[0-9a-f]{64}', args.image):
        parser.error('image must be an immutable image ID')
    if run('docker', 'image', 'inspect', args.image, '--format', '{{.Id}}') != args.image:
        raise RuntimeError('Image ID mismatch')
    candidate = args.config.read_text()
    name = 'mixpost-protocol-rehearsal-' + uuid.uuid4().hex[:12]
    folder = pathlib.Path(tempfile.mkdtemp(prefix=name))
    folder.chmod(0o755)
    routing = '''http:
  routers:
    mixpost:
      rule: Host(`mixpost.peachyhq.com`)
      entryPoints: [probe]
      tls:
        options: mixpost-upload-http1
      service: probe
    control:
      rule: Host(`control.invalid`)
      entryPoints: [probe]
      tls: {}
      service: probe
  services:
    probe:
      loadBalancer:
        servers:
          - url: http://127.0.0.1:9999
'''
    config = folder / 'probe.yml'
    config.write_text(candidate + '\n' + routing)
    config.chmod(0o644)
    owned = False
    try:
        run('docker', 'run', '-d', '--name', name, '--network', 'none', '--read-only',
            '--cap-drop', 'ALL', '--user', '65534', '--mount',
            f'type=bind,source={folder},target=/probe,readonly', args.image,
            '--providers.file.directory=/probe', '--entrypoints.probe.address=:8443')
        owned = True
        pid = run('docker', 'inspect', name, '--format', '{{.State.Pid}}')

        def probe(host):
            return json.loads(run('nsenter', '-t', pid, '-n', 'curl', '--http2', '-k',
                                  '--max-time', '3', '--resolve', f'{host}:8443:127.0.0.1',
                                  '-sS', '-o', '/dev/null', '-w', '%{json}', f'https://{host}:8443/'))

        time.sleep(2)
        results = []
        for host, expected in [('mixpost.peachyhq.com', '1.1'), ('control.invalid', '2')]:
            response = probe(host)
            if response['http_version'] != expected or response['response_code'] != 502:
                raise RuntimeError('Unexpected protocol or backend status')
            results.append({'scope': 'mixpost' if host.startswith('mixpost.') else 'other-host',
                            'http_version': response['http_version'], 'backend_absent_status': 502})

        # Revert the reference, then verify new connections negotiate the original protocol.
        replacement = folder / 'replacement.tmp'
        replacement.write_text(config.read_text().replace('options: mixpost-upload-http1', 'options: default'))
        replacement.chmod(0o644)
        replacement.replace(config)
        deadline = time.monotonic() + 15
        while True:
            response = probe('mixpost.peachyhq.com')
            if response['http_version'] == '2' and response['response_code'] == 502:
                break
            if time.monotonic() >= deadline:
                raise RuntimeError('Rollback did not restore HTTP/2')
            time.sleep(.5)
        results.append({'scope': 'mixpost-rollback', 'http_version': '2'})
        print(json.dumps({'passed': True, 'isolated': True, 'production_mutated': False,
                          'proxy_image': args.image,
                          'tls_config_sha256': hashlib.sha256(candidate.encode()).hexdigest(),
                          'results': results}, indent=2))
    finally:
        if owned:
            run('docker', 'rm', '-f', name)
        shutil.rmtree(folder)


if __name__ == '__main__':
    main()
