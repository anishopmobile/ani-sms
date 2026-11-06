"""Build only the original ani-sms plugin; never package the repository installation."""
from pathlib import Path
import argparse
import hashlib
import re
import zipfile

repo = Path(__file__).resolve().parents[1]
source = repo / 'wp-content/plugins/ani-sms'
parser = argparse.ArgumentParser()
parser.add_argument('--output', default=str(repo / 'artifacts'))
args = parser.parse_args()
version = re.search(r'^ \* Version: (.+)$', (source / 'ani-sms.php').read_text(encoding='utf-8'), re.M).group(1).strip()
target = Path(args.output).resolve()
target.mkdir(parents=True, exist_ok=True)
archive = target / f'ani-sms-{version}.zip'
allowed_files = {'ani-sms.php', 'uninstall.php', 'readme.txt', 'LICENSE.txt'}
allowed_dirs = {'src', 'assets', 'languages'}
files = [p for p in source.rglob('*') if p.is_file() and (p.relative_to(source).as_posix() in allowed_files or p.relative_to(source).parts[0] in allowed_dirs)]
assert all((source / file).is_file() for file in allowed_files), 'Required distribution file missing'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as bundle:
    for file in sorted(files):
        relative = file.relative_to(source).as_posix()
        assert '..' not in Path(relative).parts
        bundle.write(file, 'ani-sms/' + relative)
with zipfile.ZipFile(archive) as bundle:
    assert bundle.testzip() is None
    assert all(name.startswith('ani-sms/') for name in bundle.namelist())
    assert not any(name.endswith('.sql') or 'wp-config' in name or 'vendor/' in name or 'Plugin-sms-ir/' in name or 'tests/' in name for name in bundle.namelist())
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(target / f'{archive.name}.sha256').write_text(f'{digest}  {archive.name}\n', encoding='ascii')
print(f'PASS package: {len(files)} allowlisted files; {archive}; SHA256 {digest}')
