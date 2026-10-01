#!/usr/bin/env python3
"""Inspects an app archive with Python's tarfile, which (unlike macOS bsdtar -t) shows AppleDouble entries.

Fails when the archive has a ._* or .DS_Store entry, a PaxHeader entry or pax extended headers
(macOS xattrs such as com.apple.provenance travel there), any top-level entry other than mcp/, a
script/style referenced with Util::addScript/addStyle('mcp', X) that is not packaged as js/X.js or css/X.css,
or a vendor/ package outside ALLOWED_VENDOR (a dev dependency such as sabre/* or symfony/console must
never ship in the production package).
"""
import pathlib
import re
import sys
import tarfile

REFERENCE = re.compile(r"""add(Script|Style)\(\s*['"]mcp['"]\s*,\s*['"]([^'"]+)['"]""")

# Every top-level entry the production vendor/ may hold: the two runtime dependencies
# (composer --no-dev resolves exactly these), Composer's own autoloader directory and
# the autoload files it writes. Anything else means a dev dependency leaked into the package.
ALLOWED_VENDOR = {"smalot/", "symfony/polyfill-mbstring/", "composer/", "autoload.php"}


def vendor_problems(entries: list[tuple[str, bool]]) -> list[str]:
    """Rejects any mcp/vendor/ entry outside ALLOWED_VENDOR.

    `entries` is a list of (name, is_dir) taken from the archive. A directory entry carries no
    code and passes when it is a path-segment prefix of an allowed directory (macOS tar writes
    directory names without a trailing slash, so is_dir cannot be recovered from the name).
    A file passes when it equals an allowed leaf or sits under an allowed directory, matched on
    a segment boundary so symfony/polyfill-mbstring/ passes while symfony/console/ does not.
    """
    directories = [d for d in ALLOWED_VENDOR if d.endswith("/")]
    found = []
    for name, is_dir in sorted(entries):
        if not name.startswith("mcp/vendor/") or is_dir:
            continue
        rest = name[len("mcp/vendor/") :]
        if not rest.strip("/"):
            continue
        allowed = rest in ALLOWED_VENDOR or any(
            rest == d.rstrip("/") or rest.startswith(d) for d in directories
        )
        if not allowed:
            found.append(f"forbidden vendor entry: {name}")
    return found


def problems(archive: str, sources: list[str]) -> list[str]:
    found = []
    with tarfile.open(archive) as tar:
        members = tar.getmembers()
    names = {m.name.rstrip("/") for m in members}
    entries = [(m.name, m.isdir()) for m in members]
    for member in members:
        base = member.name.rstrip("/").split("/")[-1]
        if base.startswith("._") or base == ".DS_Store":
            found.append(f"forbidden macOS entry: {member.name}")
        if "PaxHeader" in member.name or member.pax_headers:
            found.append(f"pax header on: {member.name}")
    tops = sorted({m.name.split("/")[0] for m in members})
    if tops != ["mcp"]:
        found.append(f"top-level entries must be only mcp/, found: {tops}")
    found.extend(vendor_problems(entries))
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
