#!/usr/bin/env python3
"""Inspects an app archive with Python's tarfile, which (unlike macOS bsdtar -t) shows AppleDouble entries.

Fails when the archive has a ._* or .DS_Store entry, a PaxHeader entry or pax extended headers
(macOS xattrs such as com.apple.provenance travel there), any top-level entry other than mcp/, or a
script/style referenced with Util::addScript/addStyle('mcp', X) that is not packaged as js/X.js or css/X.css.
"""
import pathlib
import re
import sys
import tarfile

REFERENCE = re.compile(r"""add(Script|Style)\(\s*['"]mcp['"]\s*,\s*['"]([^'"]+)['"]""")


def problems(archive: str, sources: list[str]) -> list[str]:
    found = []
    with tarfile.open(archive) as tar:
        members = tar.getmembers()
    names = {m.name.rstrip("/") for m in members}
    for member in members:
        base = member.name.rstrip("/").split("/")[-1]
        if base.startswith("._") or base == ".DS_Store":
            found.append(f"forbidden macOS entry: {member.name}")
        if "PaxHeader" in member.name or member.pax_headers:
            found.append(f"pax header on: {member.name}")
    tops = sorted({m.name.split("/")[0] for m in members})
    if tops != ["mcp"]:
        found.append(f"top-level entries must be only mcp/, found: {tops}")
    for source in sources:
        for path in pathlib.Path(source).rglob("*.php"):
            for kind, name in REFERENCE.findall(path.read_text(encoding="utf-8")):
                expected = f"mcp/js/{name}.js" if kind == "Script" else f"mcp/css/{name}.css"
                if expected not in names:
                    found.append(f"missing {expected} (add{kind} in {path})")
    return found


if __name__ == "__main__":
    issues = problems(sys.argv[1], sys.argv[2:] or ["templates", "lib"])
    for issue in issues:
        print(issue, file=sys.stderr)
    sys.exit(1 if issues else 0)
