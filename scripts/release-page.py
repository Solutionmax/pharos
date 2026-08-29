#!/usr/bin/env python3
"""Render https://pharos.solutionmax.net/releases/ from CHANGELOG.md + the manifest.

    scripts/release-page.py CHANGELOG.md dist/latest.json > dist/index.html

Static, no dependencies. Each released version becomes a section with its notes,
the zip + sha256 links, and the one-line install commands at the top.
"""
import base64
import html
import json
import re
import sys

RELEASES = "https://pharos.solutionmax.net/releases"


def manifest(path: str) -> dict:
    token = open(path).read().strip()
    payload = token.split(".", 1)[0]
    return json.loads(base64.urlsafe_b64decode(payload + "=" * (-len(payload) % 4)))


def sections(changelog: str) -> list[tuple[str, str, str]]:
    """[(version, date, markdown_body)] for released versions, newest first."""
    out = []
    for m in re.finditer(r"^## \[(\d+\.\d+\.\d+)\] — (\d{4}-\d{2}-\d{2})\n(.*?)(?=^## \[|\Z)", changelog, re.M | re.S):
        out.append((m.group(1), m.group(2), m.group(3).strip()))
    return out


def md(body: str) -> str:
    """The subset Keep-a-Changelog uses: paragraphs, ### headings, - lists, `code`, **bold**."""
    out, items = [], []

    def flush():
        nonlocal items
        if items:
            out.append("<ul>" + "".join(f"<li>{inline(i)}</li>" for i in items) + "</ul>")
            items = []

    def inline(s: str) -> str:
        s = html.escape(s)
        s = re.sub(r"`([^`]+)`", r"<code>\1</code>", s)
        s = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", s)
        return s

    for line in body.splitlines():
        if line.startswith("### "):
            flush(); out.append(f"<h4>{inline(line[4:])}</h4>")
        elif line.startswith("- "):
            items.append(line[2:])
        elif line.strip():
            flush(); out.append(f"<p>{inline(line)}</p>")
    flush()
    return "\n".join(out)


def main() -> None:
    changelog = open(sys.argv[1]).read()
    latest = manifest(sys.argv[2])
    blocks = []
    for version, date, body in sections(changelog):
        tag = ' <span class="tag">latest</span>' if version == latest["version"] else ""
        blocks.append(f"""
<section class="rel" id="v{version}">
  <div class="rel-hd"><h3>{version}{tag}</h3><time datetime="{date}">{date}</time></div>
  {md(body)}
  <p class="dl"><a href="{RELEASES}/pharos-{version}.zip">pharos-{version}.zip</a> · <a href="{RELEASES}/pharos-{version}.zip.sha256">sha256</a></p>
</section>""")

    print(f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pharos releases</title>
<meta name="robots" content="noindex">
<style>
:root{{--bg:#fbfcfe;--card:#fff;--line:#e3e9f1;--ink:#0e1726;--ink2:#475467;--ink3:#667085;--blue:#0079d2;--navy:#0a1729;--green:#027a48;--green-soft:#e6f7ef}}
@media(prefers-color-scheme:dark){{:root{{--bg:#080e18;--card:#0e1a2b;--line:#1c2b3f;--ink:#eaf0f7;--ink2:#a7b6c7;--ink3:#7d8fa3;--blue:#53b1fd;--green:#6ce9a6;--green-soft:#0d2a1e}}}}
*{{box-sizing:border-box}}body{{margin:0;background:var(--bg);color:var(--ink);font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}}
.wrap{{max-width:760px;margin:0 auto;padding:40px 22px 80px}}h1{{font-size:28px;letter-spacing:-.03em;margin:0 0 6px}}.lede{{color:var(--ink2);margin:0 0 26px}}
.how{{background:var(--navy);color:#e6edf6;border-radius:12px;padding:14px 16px;margin:0 0 30px;font:12.5px ui-monospace,monospace;line-height:1.9}}.how span{{color:#7e9ab5}}.how b{{color:#7cc4ff;font-weight:500}}
.rel{{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 22px;margin:0 0 14px}}.rel-hd{{display:flex;align-items:baseline;gap:12px}}.rel h3{{margin:0;font-size:20px;letter-spacing:-.02em}}.rel time{{color:var(--ink3);font:12px ui-monospace,monospace;margin-left:auto}}
.tag{{font:10px ui-monospace,monospace;letter-spacing:.1em;text-transform:uppercase;background:var(--green-soft);color:var(--green);border-radius:999px;padding:3px 8px;vertical-align:middle;margin-left:6px}}
h4{{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);margin:14px 0 4px}}ul{{margin:0;padding-left:18px;color:var(--ink2)}}li{{margin:2px 0}}p{{color:var(--ink2);margin:8px 0}}
code{{font:.9em ui-monospace,monospace;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:.05em .35em}}.dl{{font-size:13px;margin-top:12px}}a{{color:var(--blue)}}
.foot{{color:var(--ink3);font-size:12.5px;margin-top:30px}}
</style></head><body><div class="wrap">
<h1>Pharos releases</h1>
<p class="lede">Every release is a zip with everything in it, a SHA-256, and a signed manifest your install checks before it touches a file.</p>
<div class="how"><span># VPS or Docker host</span><br>curl -fsSL https://pharos.solutionmax.net/get | sh<br><span># cPanel / DirectAdmin / Plesk without SSH: upload this one file and open it</span><br>https://pharos.solutionmax.net/pharos-install.php<br><span># manifest the Updates screen reads</span><br><b>{RELEASES}/latest.json</b></div>
{''.join(blocks)}
<p class="foot">Manifests are signed with Ed25519 by SolutionMAX and verified locally; nothing here phones home. <a href="https://pharos.solutionmax.net">pharos.solutionmax.net</a></p>
</div></body></html>""")


if __name__ == "__main__":
    main()
