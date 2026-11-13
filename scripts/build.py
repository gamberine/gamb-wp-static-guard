"""Gera um pacote WordPress determinístico contendo somente a distribuição."""
from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parents[1]
version = re.search(r"Version:\s*([\d.]+)", (root / "gamb-wp-static-guard.php").read_text(encoding="utf-8")).group(1)
output = root / "dist"
output.mkdir(exist_ok=True)
package = output / f"gamb-wp-static-guard-{version}.zip"
paths = [root / name for name in ("gamb-wp-static-guard.php", "index.php", "readme.txt", "LICENSE")]
for directory in ("src", "assets"):
    paths.extend(p for p in (root / directory).rglob("*") if p.is_file())
with zipfile.ZipFile(package, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(paths):
        if path.is_symlink():
            raise ValueError(f"Link simbólico não permitido: {path.name}")
        info = zipfile.ZipInfo("gamb-wp-static-guard/" + path.relative_to(root).as_posix(), (2020, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, path.read_bytes())
digest = hashlib.sha256(package.read_bytes()).hexdigest()
(output / "SHA256SUMS").write_text(f"{digest}  {package.name}\n", encoding="ascii")
print(f"{package.name}: {digest}")
