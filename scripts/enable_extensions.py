#!/usr/bin/env python3
"""
Enable PHP extensions required by trueCORD.

Usage:
    python enable_extensions.py <path/to/php.ini>
    python enable_extensions.py <path/to/php.ini> --dry-run
    python enable_extensions.py <path/to/php.ini> --verify
"""

import sys
import re
import shutil
import subprocess
from pathlib import Path

REQUIRED = ["pdo_sqlite", "sqlite3", "mbstring"]
OPTIONAL = ["gd", "fileinfo", "curl"]
ALL_EXTENSIONS = REQUIRED + OPTIONAL


def detect_encoding(p: Path) -> str:
    """Detect file encoding, handling UTF-8 BOM."""
    raw = p.read_bytes()
    if raw.startswith(b"\xef\xbb\xbf"):
        return "utf-8-sig"
    # Try UTF-8 first; fall back to latin-1 (never fails, preserves bytes)
    try:
        raw.decode("utf-8")
        return "utf-8"
    except UnicodeDecodeError:
        return "latin-1"


def find_extension_lines(content: str, ext: str):
    """Return list of (start_pos, matched_text, is_enabled) for a given extension."""
    results = []
    # Match variations: extension=foo, extension = foo, ;extension=foo, ; extension = foo
    pattern = re.compile(
        rf"^(;?\s*extension\s*=\s*{re\.escape(ext)}\b)",
        re.MULTILINE,
    )
    for m in pattern.finditer(content):
        line = m.group(0)
        enabled = not line.lstrip().startswith(";")
        results.append((m.start(), line, enabled))
    return results


def run(ini_path: str, dry_run: bool = False):
    p = Path(ini_path)
    if not p.is_file():
        print(f"Error: file not found: {p}")
        sys.exit(1)

    enc = detect_encoding(p)
    content = p.read_text(encoding=enc)

    # Strip BOM from content for processing (re-add on write if needed)
    had_bom = content.startswith("\ufeff")
    if had_bom:
        content = content[1:]

    modified = False
    status = {}

    for ext in ALL_EXTENSIONS:
        matches = find_extension_lines(content, ext)
        already_on = any(enabled for _, _, enabled in matches)

        if already_on:
            status[ext] = "already enabled"
            continue

        if matches:
            pos, line, _ = matches[0]
            new_line = line.lstrip(";").lstrip()
            content = content[:pos] + new_line + content[pos + len(line):]
            status[ext] = "enabled (uncommented)"
            modified = True
        else:
            # Insert after the last extension= line
            anchor = content.rfind("extension=")
            if anchor == -1:
                content += f"\nextension={ext}\n"
            else:
                eol = content.find("\n", anchor)
                if eol == -1:
                    eol = len(content)
                content = content[: eol + 1] + f"extension={ext}\n" + content[eol + 1:]
            status[ext] = "added"
            modified = True

    # Print status
    print(f"{'Extension':<14} {'Type':<11} Status")
    print("-" * 45)
    for ext in ALL_EXTENSIONS:
        tag = "required" if ext in REQUIRED else "optional"
        print(f"  {ext:<12} {tag:<9} {status[ext]}")

    if dry_run:
        print("\n[DRY RUN] No changes written.")
        return

    if not modified:
        print("\nNo changes needed.")
        return

    # Restore BOM if original had it
    if had_bom:
        content = "\ufeff" + content

    # Backup
    backup = p.with_suffix(".ini.bak")
    try:
        shutil.copy2(p, backup)
        print(f"\nBackup: {backup}")
    except OSError as e:
        print(f"\nWarning: could not create backup: {e}")

    try:
        p.write_text(content, encoding=enc)
        print(f"Updated: {p}")
    except OSError as e:
        print(f"Error writing file: {e}")
        sys.exit(1)


def verify():
    """Check if PHP can load the required extensions."""
    php = shutil.which("php")
    if not php:
        print("PHP not found in PATH — skip verification.")
        return

    print("\nVerifying with PHP:")
    for ext in ALL_EXTENSIONS:
        tag = "required" if ext in REQUIRED else "optional"
        try:
            r = subprocess.run(
                [php, "-m"],
                capture_output=True, text=True, timeout=10,
            )
            loaded = ext.lower() in r.stdout.lower().splitlines()
            mark = "OK" if loaded else "MISSING"
            print(f"  {ext:<12} {tag:<9} {mark}")
        except Exception:
            print(f"  {ext:<12} {tag:<9} unknown")


def main():
    args = sys.argv[1:]
    if not args or "--help" in args or "-h" in args:
        print(__doc__.strip())
        sys.exit(0)

    dry_run = "--dry-run" in args
    do_verify = "--verify" in args
    path_args = [a for a in args if not a.startswith("--")]

    if not path_args:
        print("Error: php.ini path required.")
        sys.exit(1)

    run(path_args[0], dry_run=dry_run)

    if do_verify:
        verify()


if __name__ == "__main__":
    main()
