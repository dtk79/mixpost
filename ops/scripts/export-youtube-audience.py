#!/usr/bin/env python3
"""Run the read-only collector over SSH without copying credentials or installing files."""
import argparse
from pathlib import Path
import re
import shlex
import subprocess
import sys


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('workspace', type=int)
    parser.add_argument('account', type=int)
    parser.add_argument('start', help='YYYY-MM-DD, inclusive Pacific date')
    parser.add_argument('end', help='YYYY-MM-DD, inclusive Pacific date')
    parser.add_argument('--subscription', choices=['all', 'subscribed', 'unsubscribed'], default='all')
    parser.add_argument('--host', default='mixpost-hetzner')
    parser.add_argument('--container', default='mixpost-mixpost-1')
    args = parser.parse_args()
    if args.workspace < 1 or args.account < 1 or not all(re.fullmatch(r'\d{4}-\d{2}-\d{2}', d) for d in [args.start, args.end]):
        parser.error('Positive workspace/account and YYYY-MM-DD dates are required.')
    root = Path(__file__).resolve().parents[1]
    collector = (root / 'production-overrides/YoutubeAudienceReport.php').read_text()
    collector = collector.replace('namespace Inovector\\Mixpost\\Support;', 'namespace Inovector\\Mixpost\\Support {')
    runtime = (root / 'scripts/export-youtube-audience.php').read_text().split('declare(strict_types=1);', 1)[1]
    runtime = '\n'.join(line for line in runtime.splitlines() if not line.startswith("require_once getenv('YOUTUBE_AUDIENCE_REPORT_PATH')"))
    payload = collector + '\n}\nnamespace {\n' + runtime + '\n}\n'
    remote = shlex.join(['docker', 'exec', '-i', '-w', '/var/www/html', args.container, 'php', '/dev/stdin', str(args.workspace), str(args.account), args.start, args.end, args.subscription])
    # Only source code travels over stdin. Existing encrypted tokens remain inside Mixpost.
    result = subprocess.run(['ssh', '--', args.host, remote], input=payload, text=True)
    return result.returncode


if __name__ == '__main__':
    sys.exit(main())
