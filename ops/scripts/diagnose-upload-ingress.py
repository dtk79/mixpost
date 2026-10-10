#!/usr/bin/env python3
"""Read-only host-side upload evidence; never output addresses or request data.

Run on the Docker host as root. Uses an exact UUID, bounded proxy-log reads,
durable chunk metadata, and matching TCP counters. No application reloads,
packet payload capture, network probes, or storage mutations.
"""
import argparse
import datetime
import glob
import json
import os
import re
import subprocess
import time


def command(*args):
    return subprocess.check_output(args, text=True, timeout=15).strip()


def proxy_requests(container, session):
    path = command('docker', 'inspect', container, '--format', '{{.LogPath}}')
    requests, clients = [], set()
    pattern = re.compile(
        r'(\S+) - - \[([^]]+)\] "(\S+) ([^ ]+) ([^"]+)" (\d+) .* (\d+)ms')
    with open(path, 'rb') as log:
        offset = max(0, os.path.getsize(path) - 16 * 1024 * 1024)
        log.seek(offset)
        if offset:
            log.readline()
        for raw in log:
            try:
                line = json.loads(raw).get('log', '')
            except (ValueError, UnicodeDecodeError):
                continue
            match = pattern.match(line)
            if not match:
                continue
            client, started, method, route, protocol, status, duration = match.groups()
            # Reject queries and similarly named sessions; do not emit full routes.
            if not re.search(r'/chunked/' + re.escape(session) + r'/(upload|complete)$', route):
                continue
            status = int(status)
            if 200 <= status < 300:
                clients.add(client)
            requests.append(dict(started=started, method=method,
                                 operation=route.rsplit('/', 1)[-1],
                                 protocol=protocol, status=status,
                                 duration_ms=int(duration)))
    return requests, clients


def tcp_counters(container, clients):
    pid = command('docker', 'inspect', container, '--format', '{{.State.Pid}}')
    lines = command('nsenter', '-t', pid, '-n', 'ss', '-tinm',
                    'state', 'established', 'sport', '=', ':443').splitlines()
    result = {}
    for index, line in enumerate(lines):
        if line.startswith((' ', '\t')) or len(line.split()) < 4:
            continue
        peer = line.split()[3]
        # Keep the address only in process memory for correlation. Output counters only.
        address, _, port = peer.rpartition(':')
        address = address.strip('[]').removeprefix('::ffff:')
        if address not in clients or index + 1 >= len(lines):
            continue
        detail = lines[index + 1]
        values = {}
        for name in ('bytes_received', 'bytes_sent', 'data_segs_in', 'rcv_ooopack',
                     'rcv_space', 'rcv_ssthresh'):
            match = re.search(r'\b' + name + r':(\d+)', detail)
            if match:
                values[name] = int(match.group(1))
        for name in ('rtt', 'minrtt', 'rcv_rtt'):
            match = re.search(r'\b' + name + r':([\d.]+)', detail)
            if match:
                values[name + '_ms'] = float(match.group(1))
        memory = re.search(r'skmem:\(([^)]+)\)', detail)
        if memory:
            for name, number in re.findall(r'([a-z]+)(\d+)', memory.group(1)):
                if name in ('r', 'rb', 'd'):
                    values[{'r': 'receive_memory_bytes', 'rb': 'receive_buffer_bytes',
                            'd': 'socket_drops'}[name]] = int(number)
        result[(address, port)] = values
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('session')
    parser.add_argument('--proxy', default='traefik')
    parser.add_argument('--sample-seconds', type=int, default=10)
    parser.add_argument('--state-root', default='/mnt/appdata/docker/volumes/mixpost_storage/_data/peachy-upload-state/chunked')
    args = parser.parse_args()
    if not re.fullmatch(r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}', args.session):
        parser.error('session must be a UUID')
    if not 1 <= args.sample_seconds <= 30:
        parser.error('sample-seconds must be between 1 and 30')
    path = os.path.join(args.state_root, args.session)
    with open(os.path.join(path, 'session.json')) as source:
        session = json.load(source)
    requests, clients = proxy_requests(args.proxy, args.session)
    before = tcp_counters(args.proxy, clients)
    started = time.monotonic()
    time.sleep(args.sample_seconds)
    after = tcp_counters(args.proxy, clients)
    elapsed = time.monotonic() - started
    connections = []
    for key, counters in after.items():
        entry = dict(counters)
        if key in before:
            for name in ('bytes_received', 'rcv_ooopack', 'socket_drops'):
                if name in counters and name in before[key]:
                    entry[name + '_delta'] = counters[name] - before[key][name]
            if 'bytes_received_delta' in entry:
                entry['received_bytes_per_second'] = round(entry['bytes_received_delta'] / elapsed)
        connections.append(entry)
    with open(os.path.join(path, 'session.json')) as source:
        session = json.load(source)
    requests, _ = proxy_requests(args.proxy, args.session)
    print(json.dumps(dict(
        observed_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
        session=args.session, size_bytes=session['total_size'],
        total_chunks=session['total_chunks'], local_route=not session['is_s3'],
        accepted_media_id=session.get('accepted_media_id'),
        durable_chunks=len(glob.glob(os.path.join(path, 'chunk_*'))),
        sample_seconds=round(elapsed, 3), requests=requests,
        same_client_connections=connections,
        limitations=['TCP counters identify the peer, not an individual HTTP/2 stream.',
                     'Reordering/drop counters do not identify the originating device or cause.']),
        indent=2))


if __name__ == '__main__':
    main()
