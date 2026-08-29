#!/usr/bin/env python3
"""Render https://pharos.solutionmax.net/releases/ from CHANGELOG.md, the manifest and releases.json.

    scripts/release-page.py CHANGELOG.md dist/latest.json dist/releases.json > dist/index.html

releases.json is kept by build-release.sh: [{"version","date","size","sha256"}], newest first.
Static, no dependencies. Light + dark. Same type as the site (Archivo / Public Sans / JetBrains Mono).
"""
import base64
import html
import json
import re
import sys

RELEASES = "https://pharos.solutionmax.net/releases"
KIND = {  # heading in CHANGELOG → (label, css class)
    "Added": ("Added", "add"), "Changed": ("Changed", "chg"), "Fixed": ("Fixed", "fix"),
    "Security": ("Security", "sec"), "Removed": ("Removed", "rem"), "Deprecated": ("Deprecated", "rem"),
}


def manifest(path):
    token = open(path).read().strip().split(".", 1)[0]
    return json.loads(base64.urlsafe_b64decode(token + "=" * (-len(token) % 4)))


def sections(changelog):
    """[(version, date, summary, [(kind, [items])])] newest first."""
    out = []
    for m in re.finditer(r"^## \[(\d+\.\d+\.\d+)\] — (\d{4}-\d{2}-\d{2})\n(.*?)(?=^## \[|\Z)", changelog, re.M | re.S):
        body = m.group(3).strip()
        summary, groups, cur = [], [], None
        for line in body.splitlines():
            if line.startswith("### "):
                cur = (line[4:].strip(), []); groups.append(cur)
            elif line.startswith("- ") and cur:
                cur[1].append(line[2:].strip())
            elif line.strip() and cur is None:
                summary.append(line.strip())
        out.append((m.group(1), m.group(2), " ".join(summary), groups))
    return out


def inline(s):
    s = html.escape(s)
    s = re.sub(r"`([^`]+)`", r"<code>\1</code>", s)
    s = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", s)
    return s


def nice_date(iso):
    y, mo, d = iso.split("-")
    return f"{int(d)} {['January','February','March','April','May','June','July','August','September','October','November','December'][int(mo)-1]} {y}"


def human(n):
    return f"{n/1048576:.1f} MB" if n >= 1048576 else f"{n/1024:.0f} KB"


def main():
    changelog = open(sys.argv[1]).read()
    latest = manifest(sys.argv[2])
    meta = {r["version"]: r for r in json.load(open(sys.argv[3]))} if len(sys.argv) > 3 else {}
    rels = sections(changelog)

    rail = "".join(
        f'<a href="#v{v}" class="{"on" if v == latest["version"] else ""}"><b>{v}</b><span>{d}</span></a>'
        for v, d, _, _ in rels)

    cards = []
    for i, (v, d, summary, groups) in enumerate(rels):
        m = meta.get(v, {})
        size = f' · {human(m["size"])}' if m.get("size") else ""
        sha = m.get("sha256") or (latest["sha256"] if v == latest["version"] else "")
        counts = " ".join(f'<i class="{KIND.get(k, ("", "chg"))[1]}">{len(items)} {KIND.get(k, (k, ""))[0].lower()}</i>' for k, items in groups if items)
        body = "".join(
            f'<div class="grp"><h4 class="{KIND.get(k, ("", "chg"))[1]}">{inline(KIND.get(k, (k, ""))[0])}</h4><ul>'
            + "".join(f"<li>{inline(it)}</li>" for it in items) + "</ul></div>"
            for k, items in groups if items)
        shabox = (f'<span class="sha"><span>sha256</span><code>{sha[:12]}…{sha[-6:]}</code>'
                  f'<button type="button" data-sha="{sha}">Copy</button></span>') if sha else ""
        cards.append(f"""
<article class="rel" id="v{v}">
  <header>
    <div class="ver"><h2>{v}</h2>{'<span class="tag">latest</span>' if v == latest["version"] else ''}</div>
    <time datetime="{d}">{nice_date(d)}</time>
  </header>
  {f'<p class="sum">{inline(summary)}</p>' if summary else ''}
  <p class="counts">{counts}</p>
  {body}
  <footer>
    <a class="dl" href="{RELEASES}/pharos-{v}.zip"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v8m0 0 3-3m-3 3L5 7M3 12v1.5A.5.5 0 0 0 3.5 14h9a.5.5 0 0 0 .5-.5V12"/></svg>pharos-{v}.zip{size}</a>
    {shabox}
    <span class="upd">{'Installed already? The Updates screen offers this release; or run the install command again.' if i == 0 else 'Superseded — the manifest points at the newest release.'}</span>
  </footer>
</article>""")

    print(f"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pharos releases</title>
<meta name="description" content="Every Pharos release: what changed, the signed download and its checksum.">
<meta name="robots" content="noindex">
<link rel="icon" href="https://pharos.solutionmax.net/assets/img/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Public+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
<style>
:root{{--bg:#fbfcfe;--tint:#f2f6fb;--card:#fff;--line:#e8edf4;--line2:#d6e0ed;--ink:#0e1726;--ink2:#475467;--ink3:#667085;--blue:#0079d2;--blue-soft:#e8f3fc;--navy:#0a1729;--navy2:#0e2036;
 --add:#027a48;--add-bg:#e6f7ef;--chg:#175cd3;--chg-bg:#e8f1fd;--fix:#b54708;--fix-bg:#fef4e6;--sec:#b42318;--sec-bg:#fee4e2;--rem:#475467;--rem-bg:#eef2f6;
 --display:'Archivo',system-ui,sans-serif;--body:'Public Sans',system-ui,sans-serif;--mono:'JetBrains Mono',ui-monospace,monospace;--ease:cubic-bezier(.16,1,.3,1)}}
@media(prefers-color-scheme:dark){{:root:not([data-theme=light]){{--bg:#080e18;--tint:#0b1523;--card:#0e1a2b;--line:#1c2b3f;--line2:#2a3b52;--ink:#eaf0f7;--ink2:#a7b6c7;--ink3:#7d8fa3;--blue:#53b1fd;--blue-soft:#0f2438;
 --add:#6ce9a6;--add-bg:#0d2a1e;--chg:#84caff;--chg-bg:#10233a;--fix:#fec84b;--fix-bg:#2c2211;--sec:#fda29b;--sec-bg:#2d1614;--rem:#a7b6c7;--rem-bg:#16213a}}}}
:root[data-theme=dark]{{--bg:#080e18;--tint:#0b1523;--card:#0e1a2b;--line:#1c2b3f;--line2:#2a3b52;--ink:#eaf0f7;--ink2:#a7b6c7;--ink3:#7d8fa3;--blue:#53b1fd;--blue-soft:#0f2438;
 --add:#6ce9a6;--add-bg:#0d2a1e;--chg:#84caff;--chg-bg:#10233a;--fix:#fec84b;--fix-bg:#2c2211;--sec:#fda29b;--sec-bg:#2d1614;--rem:#a7b6c7;--rem-bg:#16213a}}
*{{box-sizing:border-box}}body{{margin:0;background:var(--bg);color:var(--ink);font-family:var(--body);font-size:15.5px;line-height:1.65;-webkit-font-smoothing:antialiased}}
a{{color:var(--blue)}}code{{font-family:var(--mono);font-size:.88em;background:var(--tint);border:1px solid var(--line);border-radius:6px;padding:.05em .4em}}
.nav{{border-bottom:1px solid var(--line);background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(10px);position:sticky;top:0;z-index:5}}
.nav .in{{max-width:1120px;margin:0 auto;padding:12px 24px;display:flex;align-items:center;gap:18px;font-size:14px}}.nav .brand{{font-weight:800;letter-spacing:-.02em;color:var(--ink);text-decoration:none;display:flex;align-items:center;gap:8px}}
.nav .brand img{{width:22px;height:22px}}.nav .r{{margin-left:auto;display:flex;gap:16px}}.nav .r a{{color:var(--ink2);text-decoration:none}}
.wrap{{max-width:1120px;margin:0 auto;padding:44px 24px 90px}}
.eyebrow{{font-family:var(--mono);font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--ink3);display:flex;align-items:center;gap:.6rem}}.eyebrow i{{width:6px;height:6px;border-radius:50%;background:#12b76a}}
h1{{font-family:var(--display);font-weight:800;font-size:clamp(32px,4.4vw,46px);letter-spacing:-.04em;line-height:1.02;margin:10px 0 12px;max-width:16ch;text-wrap:balance}}
.lede{{color:var(--ink2);font-size:17px;max-width:58ch;margin:0 0 26px}}
.how{{background:var(--navy);border-radius:12px;overflow:hidden;box-shadow:0 18px 40px -26px #0a172999;margin:0 0 44px}}
.how .bar{{display:flex;gap:8px;padding:8px 12px;border-bottom:1px solid #1b3350;font-family:var(--mono);font-size:11px;color:#7e9ab5;letter-spacing:.04em}}.how .bar b{{color:#2ea3ff;font-weight:500;margin-right:4px}}
.how pre{{margin:0;padding:14px 16px;font-family:var(--mono);font-size:12.5px;line-height:1.9;color:#eaf2fb;white-space:pre-wrap;overflow-wrap:anywhere}}.how .c{{color:#5d7794}}.how .k{{color:#6ee7a8}}
.grid{{display:grid;grid-template-columns:200px minmax(0,1fr);gap:40px;align-items:start}}
.rail{{position:sticky;top:76px;display:flex;flex-direction:column;gap:2px}}.rail .lbl{{font-family:var(--mono);font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--ink3);padding:0 10px 8px}}
.rail a{{display:flex;flex-direction:column;padding:8px 10px;border-radius:8px;text-decoration:none;color:var(--ink2);border-left:2px solid transparent}}.rail a b{{font-family:var(--mono);font-size:13px;color:var(--ink);font-weight:500}}.rail a span{{font-size:11.5px;color:var(--ink3)}}
.rail a:hover{{background:var(--tint)}}.rail a.on{{border-left-color:var(--blue);background:var(--blue-soft)}}.rail a.on b{{color:var(--blue)}}
.rel{{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:26px 28px 20px;margin:0 0 18px;box-shadow:0 1px 2px #10182808,0 24px 48px -36px #0a172940}}
.rel header{{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap}}.ver{{display:flex;align-items:center;gap:10px}}.rel h2{{font-family:var(--display);font-weight:800;font-size:32px;letter-spacing:-.04em;margin:0;line-height:1}}
.tag{{font-family:var(--mono);font-size:10px;letter-spacing:.12em;text-transform:uppercase;background:var(--add-bg);color:var(--add);border-radius:999px;padding:4px 9px}}
.rel time{{margin-left:auto;font-family:var(--mono);font-size:12px;color:var(--ink3)}}
.sum{{font-size:16.5px;color:var(--ink);margin:14px 0 6px;max-width:64ch}}
.counts{{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 6px}}.counts i{{font-style:normal;font-family:var(--mono);font-size:10.5px;letter-spacing:.06em;border-radius:999px;padding:3px 9px}}
.add{{color:var(--add);background:var(--add-bg)}}.chg{{color:var(--chg);background:var(--chg-bg)}}.fix{{color:var(--fix);background:var(--fix-bg)}}.sec{{color:var(--sec);background:var(--sec-bg)}}.rem{{color:var(--rem);background:var(--rem-bg)}}
.grp{{display:grid;grid-template-columns:110px minmax(0,1fr);gap:8px 16px;padding:14px 0;border-top:1px solid var(--line)}}.grp h4{{margin:0;font-family:var(--mono);font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;padding:4px 0 0;background:none;display:inline}}
.grp ul{{margin:0;padding-left:18px;color:var(--ink2)}}.grp li{{margin:3px 0}}.grp li::marker{{color:var(--line2)}}
.rel footer{{display:flex;align-items:center;gap:14px;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:16px;margin-top:4px;font-size:13px;color:var(--ink3)}}
.dl{{display:inline-flex;align-items:center;gap:8px;background:var(--blue);color:#fff;text-decoration:none;font-weight:600;font-size:13.5px;padding:9px 14px;border-radius:10px;transition:transform .15s var(--ease)}}.dl:hover{{transform:translateY(-1px)}}
.sha{{display:inline-flex;align-items:center;gap:8px}}.sha>span{{font-family:var(--mono);font-size:10.5px;letter-spacing:.1em;text-transform:uppercase}}.sha button{{font:11px var(--mono);color:var(--ink2);background:transparent;border:1px solid var(--line2);border-radius:6px;padding:3px 8px;cursor:pointer}}.sha button:hover{{border-color:var(--blue);color:var(--blue)}}
.upd{{margin-left:auto;max-width:34ch;text-align:right}}
.foot{{color:var(--ink3);font-size:12.5px;margin-top:28px;display:flex;gap:16px;flex-wrap:wrap}}
@media(max-width:820px){{.grid{{grid-template-columns:1fr}}.rail{{position:static;flex-direction:row;flex-wrap:wrap;gap:6px}}.rail .lbl{{width:100%}}.rail a{{border-left:0;border:1px solid var(--line)}}.rail a.on{{border-color:var(--blue)}}.rel{{padding:20px 18px 16px}}.grp{{grid-template-columns:1fr;gap:4px}}.upd{{margin-left:0;text-align:left;max-width:none}}}}
</style></head><body>
<nav class="nav"><div class="in"><a class="brand" href="https://pharos.solutionmax.net"><img src="https://pharos.solutionmax.net/assets/img/favicon.svg" alt="">Pharos</a><span style="color:var(--ink3)">/ releases</span><div class="r"><a href="https://pharos.solutionmax.net/docs.html">Docs</a><a href="https://pharos.solutionmax.net/#pricing">Pricing</a></div></div></nav>
<div class="wrap">
<p class="eyebrow"><i></i>Releases <span style="color:var(--line2)">/</span> signed manifests <span style="color:var(--line2)">/</span> latest {latest["version"]}</p>
<h1>What changed, and when.</h1>
<p class="lede">Every release is one zip with everything in it, a SHA-256, and a manifest signed by SolutionMAX that your install verifies before it touches a file. Nothing here phones home.</p>
<div class="how"><div class="bar"><b>›</b>install or update</div><pre><span class="c"># VPS or Docker host — installs Docker after a Y if it is missing</span>
<span class="k">curl</span> -fsSL https://pharos.solutionmax.net/get | sh

<span class="c"># cPanel · DirectAdmin · Plesk without SSH — upload this one file, open it in the browser</span>
https://pharos.solutionmax.net/pharos-install.php

<span class="c"># what the Updates screen in every install reads</span>
{RELEASES}/latest.json</pre></div>
<div class="grid">
  <aside class="rail"><span class="lbl">Versions</span>{rail}</aside>
  <div>{''.join(cards)}</div>
</div>
<p class="foot"><span>Manifests: Ed25519, verified locally.</span><span>Changelog kept in the repository as CHANGELOG.md.</span><a href="https://pharos.solutionmax.net">pharos.solutionmax.net</a></p>
</div>
<script>document.querySelectorAll('[data-sha]').forEach(function(b){{b.addEventListener('click',function(){{navigator.clipboard.writeText(b.dataset.sha).then(function(){{b.textContent='Copied';setTimeout(function(){{b.textContent='Copy'}},1500)}})}})}});</script>
</body></html>""")


if __name__ == "__main__":
    main()
