#!/usr/bin/env python3
"""Compare mounted sources with pristine old/new packages without reading secrets.
Roots must mirror container paths, or use app/, bootstrap/, routes/, pro/ as audit exports.
"""
import argparse, hashlib, json
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--mounts',required=True);p.add_argument('--live',required=True);p.add_argument('--old',required=True);p.add_argument('--new',required=True);p.add_argument('--output',required=True)
a=p.parse_args()
def digest(path):
 return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None
def relative(target):
 return target.replace('/var/www/html/vendor/inovector/mixpost-pro-team/','pro/').replace('/var/www/html/','').lstrip('/')
rows=[]
for mount in json.loads(Path(a.mounts).read_text()):
 if mount['Type']!='bind':continue
 name=Path(mount['Source']).name;rel=relative(mount['Destination'])
 old,new,live=(digest(Path(root)/path) for root,path in [(a.old,rel),(a.new,rel),(a.live,name)])
 rows.append({'host':name,'container':mount['Destination'],'readOnly':not mount['RW'],'liveSha256':live,'oldUpstreamSha256':old,'newUpstreamSha256':new,'review':'custom-file' if old is None else 'target-removed' if new is None else 'upstream-unchanged' if old==new else 'rebase-required'})
Path(a.output).write_text(json.dumps(rows,indent=2)+'\n')
print(f'{len(rows)} mounts; {sum(x["review"]=="rebase-required" for x in rows)} upstream changes; {sum(x["review"]=="target-removed" for x in rows)} removed targets')
if any(not x['readOnly'] or not x['liveSha256'] for x in rows):raise SystemExit('Incomplete or writable source inventory')
