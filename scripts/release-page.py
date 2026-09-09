#!/usr/bin/env python3
"""Render https://pharos.solutionmax.net/releases/ from CHANGELOG.md, the manifest and releases.json.

    scripts/release-page.py CHANGELOG.md dist/latest.json dist/releases.json > dist/index.html

releases.json is kept by build-release.sh: [{"version","date","size","sha256"}], newest first.
Static, no build dependencies. Same type as the site (Archivo / Public Sans / JetBrains Mono).
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
    version = latest["version"]
    current = next((r for r in rels if r[0] == version), None)
    if current is None:
        raise ValueError("Latest manifest version is missing from changelog")
    featured_summary = current[2] or latest.get("notes", "")
    size = human(meta[version]["size"]) if meta.get(version, {}).get("size") else "Production package"
    cards = []
    for v, d, summary, groups in rels:
        digest = meta.get(v, {}).get("sha256") or (latest["sha256"] if v == version else "")
        notes = "".join('<section class="change-group"><h3>'+inline(k)+'</h3><ul>'+"".join('<li>'+inline(it)+'</li>' for it in items)+'</ul></section>' for k, items in groups if items)
        digest_html = '<code class="digest">'+html.escape(digest)+'</code><button type="button" data-copy="'+html.escape(digest, quote=True)+'">Copy SHA-256</button>' if digest else ''
        checksum = '<details class="verify"><summary>Verify this download</summary><p>The installer verifies the signed manifest and archive. A checksum alone confirms file integrity, not who published it.</p>'+digest_html+'<a href="'+RELEASES+'/pharos-'+v+'.json">Signed manifest</a><a href="'+RELEASES+'/pharos-'+v+'.zip.sha256">Checksum file</a></details>'

        cards.append('<article class="release" id="v'+v+'"><header><div><span class="eyebrow">'+('LATEST RELEASE' if v == version else 'PREVIOUS RELEASE')+'</span><h2>Pharos '+v+'</h2></div><time datetime="'+d+'">'+nice_date(d)+'</time></header><p class="release-summary">'+inline(summary)+'</p><details class="notes" '+('open' if v == version else '')+'><summary>Read release notes</summary>'+notes+'</details><div class="release-downloads"><a href="'+RELEASES+'/pharos-'+v+'.zip">Download ZIP</a><a href="'+RELEASES+'/pharos-install-'+v+'.php" download>Version-pinned installer</a>'+('' if v == version else '<span>Use the latest version for a new installation.</span>')+'</div>'+checksum+'</article>')
    nav = ''.join('<a href="#v'+v+'">'+v+' <span>'+d+'</span></a>' for v,d,_,_ in rels)
    schema = json.dumps({"@context":"https://schema.org","@type":"SoftwareApplication","name":"Pharos","softwareVersion":version,"operatingSystem":"PHP 8.3+ or Docker","applicationCategory":"DeveloperApplication","url":"https://pharos.solutionmax.net/","downloadUrl":RELEASES+'/pharos-'+version+'.zip'})
    template = TEMPLATE.replace('@@CSS@@', CSS).replace('@@SCHEMA@@', schema).replace('@@VERSION@@', html.escape(version)).replace('@@DATE@@', nice_date(current[1])).replace('@@SUMMARY@@', inline(featured_summary)).replace('@@SIZE@@',size).replace('@@CARDS@@',''.join(cards)).replace('@@NAV@@',nav)
    print(template)


CSS = r"""
:root{--ink:#101c2d;--muted:#596a7e;--blue:#0066c0;--line:#dfe7f0;--bg:#f8fafd;--card:#fff;font-family:'Public Sans',system-ui,sans-serif;color:var(--ink);background:var(--bg)}
*{box-sizing:border-box}body{margin:0;line-height:1.65}a{color:var(--blue);text-underline-offset:4px}button,summary{cursor:pointer}a:focus-visible,button:focus-visible,summary:focus-visible{outline:3px solid #168ef0;outline-offset:4px}h1,h2,h3{font-family:Archivo,system-ui,sans-serif;letter-spacing:-.04em;line-height:1.12;margin:0}p{margin:12px 0}code{font-family:'JetBrains Mono',monospace;font-size:12px}.wrap{max-width:1200px;padding:0 26px;margin:auto}.nav{border-bottom:1px solid var(--line);background:white}.nav .wrap{min-height:68px;display:flex;align-items:center;justify-content:space-between;gap:20px}.brand img{display:block;width:110px;height:auto}.nav-links{display:flex;gap:25px;align-items:center}.nav-links a{font-size:13px;color:var(--muted);text-decoration:none}.nav-links .nav-start{background:var(--blue);color:white;padding:9px 15px;border-radius:6px}.skip{position:absolute;left:10px;top:-100px}.skip:focus{top:10px;background:white;z-index:5}.intro{padding:48px 0 30px;display:flex;justify-content:space-between;gap:30px;align-items:end}.eyebrow{font:10px 'JetBrains Mono',monospace;letter-spacing:.12em;color:var(--blue);display:block;margin-bottom:10px}h1{font-size:clamp(35px,4vw,52px)}.intro p{max-width:56ch;color:var(--muted);font-size:16px}.intro>a{white-space:nowrap;font-size:13px;margin-bottom:18px}.latest{display:grid;grid-template-columns:1.15fr 1fr;gap:45px;background:#0c2038;color:white;border:1px solid #203c5a;border-radius:16px;padding:34px 38px;box-shadow:0 20px 48px -32px #163a69;margin-bottom:34px}.latest .eyebrow{color:#83bfff}.latest h2{font-size:42px}.latest p{color:#c1d0e0;font-size:14px;max-width:53ch}.latest .release-date{font-size:12px;color:#96abc4}.latest-badge{display:inline-flex;gap:7px;align-items:center;color:#94ebc0;background:#123f39;font:10px 'JetBrains Mono',monospace;border:1px solid #27584c;border-radius:30px;padding:4px 10px;margin-bottom:14px}.latest-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}.button{display:inline-flex;justify-content:center;align-items:center;text-decoration:none;padding:11px 17px;background:#0066c0;border-radius:7px;color:white;font-size:13px;font-weight:600}.button.secondary{background:#1d3653;border:1px solid #38546f}.latest .file-note{font-size:11px;color:#aabbd0}.latest-points{border-left:1px solid #304761;padding-left:32px;align-self:center}.latest-points h3{font-size:16px;letter-spacing:-.015em}.latest-points p{font-size:13px}.latest-points a{color:#9bd1ff;font-size:12px}.paths{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:45px}.path{background:white;padding:23px;border:1px solid var(--line);border-radius:10px;min-width:0;display:flex;flex-direction:column}.path h3{font-size:19px}.path p{font-size:13px;color:var(--muted)}.path>a{font-size:12px;font-weight:600;margin-top:auto;padding-top:12px}.path-label{font:10px 'JetBrains Mono',monospace;color:var(--muted);display:block;margin-bottom:12px}.command{background:#edf2f8;border-radius:6px;padding:12px;overflow-wrap:anywhere;white-space:pre-wrap;margin:10px 0}.history-layout{display:grid;grid-template-columns:180px minmax(0,1fr);gap:40px}.version-nav{position:sticky;top:25px;align-self:start}.version-nav a{display:block;padding:9px 10px;border-left:2px solid var(--line);text-decoration:none;font:12px 'JetBrains Mono',monospace;color:var(--ink)}.version-nav a:hover{border-color:var(--blue);background:#edf4fc}.version-nav a span{display:block;font-size:10px;color:var(--muted);margin-top:5px}.release{scroll-margin-top:20px;background:white;border:1px solid var(--line);border-radius:12px;padding:28px;margin-bottom:18px;min-width:0}.release header{display:flex;justify-content:space-between;align-items:center;gap:16px}.release h2{font-size:27px}.release time{font-size:11px;color:var(--muted);white-space:nowrap}.release-summary{font-size:14px;color:var(--muted)}summary{font-size:13px;font-weight:600;padding:12px 0}.change-group{display:grid;grid-template-columns:90px minmax(0,1fr);gap:18px;border-top:1px solid #edf1f6;padding:15px 0}.change-group h3{font-size:12px;color:var(--blue);padding-top:5px;letter-spacing:0}.change-group ul{margin:0;padding-left:18px;font-size:13px;color:var(--muted)}li+li{margin-top:9px}.release-downloads{display:flex;gap:16px;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:16px;font-size:12px}.release-downloads span{color:var(--muted);font-size:11px}.verify{margin-top:10px}.verify p{font-size:12px;color:var(--muted)}.digest{display:block;overflow-wrap:anywhere;white-space:normal;background:#f2f5f9;padding:12px;margin-bottom:12px}.verify button{border:1px solid #ccd9e8;border-radius:5px;padding:7px 10px;background:white;font:12px inherit;color:var(--ink);margin-right:15px}.verify>a{font-size:12px;margin-right:15px}.upgrade-note{margin:28px 0;background:#eef4fa;border-left:3px solid var(--blue);padding:20px 24px}.upgrade-note h2{font-size:20px}.upgrade-note p{font-size:13px;color:var(--muted)}.copy-status{font-size:12px;color:var(--muted)}
.foot{background:#0a1729;color:#b9c8da;border-top:1px solid #0e2036;margin-top:32px}.foot-end{display:flex;flex-wrap:wrap;gap:.6rem 1.4rem;padding-block:.9rem;font-family:'JetBrains Mono',monospace;font-size:.69rem;letter-spacing:.1em;text-transform:uppercase;color:#91a8c2}.foot-end a{color:#77bfff;text-decoration:underline}
@media(max-width:850px){.latest{grid-template-columns:1fr;gap:24px}.latest-points{border-left:0;border-top:1px solid #304761;padding:22px 0 0}.paths{grid-template-columns:1fr}.history-layout{grid-template-columns:1fr;gap:20px}.version-nav{position:static;display:flex;flex-wrap:wrap;gap:5px}.version-nav .eyebrow{width:100%}.version-nav a{border:1px solid var(--line);border-radius:6px}.intro{display:block}.nav-links{gap:14px}}
@media(max-width:520px){.wrap{padding:0 20px}.intro{padding:32px 0 20px}.latest{padding:25px}.latest h2{font-size:35px}.release{padding:20px}.release header{align-items:start;flex-direction:column;gap:4px}.change-group{grid-template-columns:1fr;gap:8px}.nav-links a:nth-child(2){display:none}.nav-links{gap:12px}.nav-links a{font-size:12px}.brand img{width:90px}.latest-actions .button{width:100%}.release code{overflow-wrap:anywhere}}
"""

TEMPLATE = r"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pharos downloads and release notes — @@VERSION@@</title><meta name="description" content="Download Pharos @@VERSION@@, choose a hosting installer or Docker, and review release notes, checksums and update instructions."><meta name="robots" content="index,follow,max-image-preview:large"><link rel="canonical" href="https://pharos.solutionmax.net/releases/"><meta property="og:type" content="website"><meta property="og:title" content="Pharos @@VERSION@@ — downloads and release notes"><meta property="og:description" content="Get the latest Pharos release, choose your installation route and see what changed."><meta property="og:url" content="https://pharos.solutionmax.net/releases/"><meta property="og:image" content="https://pharos.solutionmax.net/assets/img/og.png"><meta name="twitter:card" content="summary_large_image"><link rel="icon" href="/assets/img/favicon.svg"><link rel="stylesheet" href="/assets/css/fonts.css?v=20260907"><link rel="preload" href="/assets/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/assets/fonts/public-sans-latin.woff2" as="font" type="font/woff2" crossorigin><script type="application/ld+json">@@SCHEMA@@</script><style>@@CSS@@</style></head>
<body><a class="skip" href="#main">Skip to content</a><header class="nav"><div class="wrap"><a class="brand" href="/"><img src="/assets/img/pharos-logo.svg" alt="Pharos" width="110" height="35"></a><nav class="nav-links" aria-label="Main navigation"><a href="/docs/">Docs</a><a href="/#pricing">Pricing</a><a class="nav-start" href="/#install-pharos">Install Pharos</a></nav></div></header>
<main id="main" class="wrap"><div class="intro"><div><span class="eyebrow">DOWNLOADS &amp; CHANGELOG</span><h1>The latest Pharos.<br>Ready for your hosting.</h1><p>Choose your installation, see what changed and update with a clear recovery path.</p></div><a href="#history">Browse release history ↓</a></div>
<section class="latest" aria-labelledby="latest-title"><div><span class="latest-badge">● LATEST RELEASE</span><h2 id="latest-title">Pharos @@VERSION@@</h2><p class="release-date">Released @@DATE@@</p><p>@@SUMMARY@@</p><div class="latest-actions"><a class="button" href="https://pharos.solutionmax.net/pharos-install.php" download>Download web installer ↓</a><a class="button secondary" href="https://pharos.solutionmax.net/releases/pharos-@@VERSION@@.zip">Download ZIP ↓</a></div><p class="file-note">ZIP: @@SIZE@@ · Dependencies included · PHP 8.3+</p></div><div class="latest-points"><h3>Installing for the first time?</h3><p>Use the web installer on shared hosting. It checks requirements and verifies the signed release before unpacking.</p><a href="/docs/install/">Installation guide →</a><h3 style="margin-top:22px">Already running Pharos?</h3><p>On PHP hosting, open Admin → Updates. On Docker, update from the host using the guide below.</p><a href="#v@@VERSION@@">Read the release notes →</a></div></section>
<section class="paths" aria-label="Installation and update options"><article class="path"><span class="path-label">01 / SHARED HOSTING</span><h3>Upload. Open. Set up.</h3><p>DirectAdmin, cPanel or Plesk. Upload the PHP installer to your web folder, open it in a browser and follow the steps, including cron verification.</p><a href="/docs/install/">Install without SSH →</a></article><article class="path"><span class="path-label">02 / DOCKER</span><h3>Update your containers.</h3><p>For an existing Compose installation, set PHAROS_VERSION=@@VERSION@@ in its environment, then run:</p><pre class="command"><code>docker compose pull
docker compose up -d</code></pre><a href="/docs/docker/">First install or updating? Read the guide →</a></article><article class="path"><span class="path-label">03 / PHP WITH SSH</span><h3>Install from your shell.</h3><p>For a new PHP installation, run the installer and follow the document-root and scheduler instructions:</p><pre class="command"><code>curl -fsSL https://pharos.solutionmax.net/get | sh -s -- --php --version @@VERSION@@</code></pre><a href="/docs/install/">SSH installation guide →</a></article></section>
<div class="history-layout" id="history"><aside class="version-nav" aria-label="Release versions"><span class="eyebrow">RELEASE HISTORY</span>@@NAV@@</aside><div>@@CARDS@@</div></div>
<section class="upgrade-note" id="specific-version"><h2>Before you update or roll back</h2><p>Take a backup first. Pharos snapshots include SQLite; MySQL needs a separate database backup. A version-pinned installer is for a fresh installation, not a downgrade. Restore matching code and database when rolling back.</p><p>Updating from 0.5.4 may require one PHP or container restart to clear old OPcache code. <a href="/docs/recovery/">Read the update and recovery guide →</a></p></section>
</main><footer class="foot"><div class="wrap foot-end" role="contentinfo"><span>Pharos by SolutionMAX</span><span><a href="/docs/">Documentation</a></span><span><a href="/legal.html">Legal &amp; privacy</a></span><span><a href="https://pharos.solutionmax.net/releases/latest.json">Signed update manifest</a></span></div></footer><p class="wrap copy-status" role="status" id="copy-status"></p>
<script>
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
 const status = document.getElementById('copy-status');
 try {
  if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(button.dataset.copy);
  else {
   const area = document.createElement('textarea'); area.value = button.dataset.copy; area.style.position='fixed'; area.style.opacity='0'; document.body.append(area); area.select();
   const ok = document.execCommand('copy'); area.remove(); if (!ok) throw new Error('Copy unavailable');
  }
  button.textContent='Copied'; status.textContent='SHA-256 checksum copied.';
 } catch { button.textContent='Select checksum above'; status.textContent='Copy was unavailable. Select and copy the full checksum above.'; }
}));
</script></body></html>"""

if __name__ == "__main__":
    main()
