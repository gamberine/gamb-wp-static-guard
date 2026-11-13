"""Obtém somente uma fixture oficial do WordPress para testar sua HTML API."""
from pathlib import Path
import io
import re
import sys
import urllib.request
import zipfile

version = sys.argv[1]
if version != 'latest' and not re.fullmatch(r'\d+\.\d+(?:\.\d+)?', version):
    raise ValueError('Versão de teste inválida.')
url = 'https://wordpress.org/latest.zip' if version == 'latest' else f'https://wordpress.org/wordpress-{version}.zip'
root = (Path(__file__).resolve().parents[1] / 'tests/runtime' / ('core-' + version)).resolve()
with urllib.request.urlopen(url, timeout=60) as response:
    data = response.read(40 * 1024 * 1024 + 1)
if len(data) > 40 * 1024 * 1024:
    raise ValueError('Fixture excedeu o limite de tamanho.')
with zipfile.ZipFile(io.BytesIO(data)) as archive:
    for item in archive.infolist():
        path = (root / item.filename).resolve()
        if not path.is_relative_to(root) or item.file_size > 20 * 1024 * 1024:
            raise ValueError('Entrada inválida na fixture oficial.')
    archive.extractall(root)
print(root / 'wordpress')
