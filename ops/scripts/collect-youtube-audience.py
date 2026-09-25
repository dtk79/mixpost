#!/usr/bin/env python3
"""Collect and persist YouTube reports using already deployed Mixpost collector classes."""
import argparse
from pathlib import Path
import shlex
import subprocess
import sys


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('workspace', type=int)
    parser.add_argument('account', type=int)
    parser.add_argument('--start')
    parser.add_argument('--end')
    parser.add_argument('--host', default='mixpost-hetzner')
    parser.add_argument('--container', default='mixpost-mixpost-1')
    args = parser.parse_args()
    if args.workspace < 1 or args.account < 1 or bool(args.start) != bool(args.end):
        parser.error('Positive workspace/account IDs and either both dates or neither date are required.')
    command = ['docker', 'exec', '-i', '-w', '/var/www/html', args.container, 'php', '/dev/stdin', str(args.workspace), str(args.account)]
    if args.start:
        command.extend([args.start, args.end])
    payload = Path(__file__).with_suffix('.php').read_text()
    return subprocess.run(['ssh', '--', args.host, shlex.join(command)], input=payload, text=True).returncode


if __name__ == '__main__':
    sys.exit(main())
