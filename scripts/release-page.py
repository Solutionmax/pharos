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

        cards.append('<article class="release" id="v'+v+'"><header><div><span class="eyebrow">'+('Latest release' if v == version else 'Previous release')+'</span><h2>Pharos '+v+'</h2></div><time datetime="'+d+'">'+nice_date(d)+'</time></header><p class="release-summary">'+inline(summary)+'</p><details class="notes" '+('open' if v == version else '')+'><summary>Read release notes</summary>'+notes+'</details><div class="release-downloads"><a href="'+RELEASES+'/pharos-'+v+'.zip">Download ZIP</a><a href="'+RELEASES+'/pharos-install-'+v+'.php" download>Version-pinned installer</a>'+('' if v == version else '<span>Use the latest version for a new installation.</span>')+'</div>'+checksum+'</article>')
    nav = ''.join('<a href="#v'+v+'">'+v+' <span>'+d+'</span></a>' for v,d,_,_ in rels)
    schema = json.dumps({"@context":"https://schema.org","@type":"SoftwareApplication","name":"Pharos","softwareVersion":version,"operatingSystem":"PHP 8.3+ or Docker","applicationCategory":"DeveloperApplication","url":"https://pharos.solutionmax.net/","downloadUrl":RELEASES+'/pharos-'+version+'.zip'})
    template = TEMPLATE.replace('@@CSS@@', CSS).replace('@@HEADER@@', HEADER).replace('@@FOOTER@@', FOOTER).replace('@@SCHEMA@@', schema).replace('@@VERSION@@', html.escape(version)).replace('@@DATE@@', nice_date(current[1])).replace('@@SUMMARY@@', inline(featured_summary)).replace('@@SIZE@@',size).replace('@@CARDS@@',''.join(cards)).replace('@@NAV@@',nav)
    print(template)


# The page uses the stylesheet of the website; only what is particular to this page is set here.
CSS = r"""
.rel{padding-block:clamp(2.5rem,5vw,4.5rem) clamp(5rem,9vw,8rem)}
.rel-intro h1{max-width:16ch;margin-top:.9rem}.rel-intro .lead{margin-top:1.4rem}
.rel-latest{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:clamp(2rem,5vw,4.5rem);margin-top:3.5rem;padding-block:2.4rem;border-block:1px solid var(--rule)}
.rel-latest h2{margin-top:.6rem;font-size:clamp(2.2rem,1.6rem + 2.4vw,3.2rem)}.rel-latest p{max-width:34rem;margin-top:.8rem;overflow-wrap:break-word}
.rel-actions{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1.6rem}
.rel-points{align-self:center;padding-left:clamp(1.5rem,3vw,2.5rem);border-left:1px solid var(--rule)}
.rel-points p{color:var(--soft);font-size:.95rem}.rel-points a{display:inline-block;margin-top:.5rem;font-size:.95rem}.rel-points a+h3{margin-top:1.8rem}
.rel-paths{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:clamp(1.5rem,3vw,3rem);margin-top:3.5rem}
.rel-path{display:flex;flex-direction:column;min-width:0;padding-top:1.2rem;border-top:var(--pen) solid var(--ink)}
.rel-path h3{margin-top:.5rem}.rel-path p{margin-top:.7rem;color:var(--soft);font-size:.95rem}.rel-path pre{margin:.9rem 0 0;white-space:pre-wrap;overflow-wrap:anywhere}.rel-path>a{margin-top:auto;padding-top:1rem;font-size:.95rem}
.rel-history{display:grid;grid-template-columns:200px minmax(0,1fr);gap:clamp(2.5rem,5vw,5rem);margin-top:5rem}
.rel-versions{position:sticky;top:calc(var(--nav-h) + 24px);display:flex;flex-direction:column;align-self:start}
.rel-versions .eyebrow{margin-bottom:.8rem}
.rel-versions a{padding:.45rem .5rem .45rem .95rem;border-left:var(--pen) solid var(--rule);color:var(--ink);font-family:var(--mono);font-size:.8rem;text-decoration:none}
.rel-versions a:hover{border-color:var(--blue);color:var(--blue)}.rel-versions a span{display:block;color:var(--soft);font-size:.72rem}
.release{min-width:0;padding-block:2.4rem;border-top:1px solid var(--rule);scroll-margin-top:calc(var(--nav-h) + 24px)}
.release:first-child{padding-top:0;border-top:0}
.release header{display:flex;justify-content:space-between;align-items:end;gap:1rem}.release h2{margin-top:.4rem}
.release time{color:var(--soft);font-family:var(--mono);font-size:.76rem;white-space:nowrap}
.release-summary{max-width:44rem;margin-top:1rem;color:var(--soft)}
.release summary{padding:.9rem 0;font-size:.95rem;font-weight:500;cursor:pointer}
.change-group{display:grid;grid-template-columns:110px minmax(0,1fr);gap:1.2rem;padding:1rem 0;border-top:1px solid var(--rule)}
.change-group h3{padding-top:.3rem;color:var(--soft);font-family:var(--mono);font-size:.76rem;letter-spacing:0}
.change-group ul{margin:0;padding-left:1.2rem;font-size:.95rem;overflow-wrap:break-word}.change-group li+li{margin-top:.5rem}
.release-downloads{display:flex;flex-wrap:wrap;align-items:baseline;gap:.5rem 1.4rem;padding-top:1rem;border-top:1px solid var(--rule);font-size:.95rem}
.release-downloads span{color:var(--soft);font-size:.88rem}
.verify p{margin-bottom:.8rem;color:var(--soft);font-size:.92rem}
.digest{display:block;margin-bottom:.8rem;padding:.8rem;overflow-wrap:anywhere;font-size:.8rem}
.verify button{height:36px;margin-right:1rem;padding:0 .9rem;border:1px solid var(--ink);border-radius:var(--r);background:var(--paper);color:var(--ink);font:500 .88rem var(--body);cursor:pointer;transition:background-color .2s,color .2s}
.verify button:hover{background:var(--ink);color:#fff}.verify>a{margin-right:1rem;font-size:.92rem}
.rel-note{max-width:46rem;margin-top:3.5rem;padding-top:2.4rem;border-top:1px solid var(--rule)}.rel-note p{margin-top:1rem;color:var(--soft)}
.copy-status{margin-top:1rem;color:var(--soft);font-size:.88rem}
@media(max-width:900px){.rel-latest{grid-template-columns:minmax(0,1fr)}.rel-points{padding:1.8rem 0 0;border-left:0;border-top:1px solid var(--rule)}.rel-paths{grid-template-columns:minmax(0,1fr)}.rel-history{grid-template-columns:minmax(0,1fr);gap:1.5rem}.rel-versions{position:static;flex-flow:row wrap;gap:.3rem}.rel-versions .eyebrow{width:100%}.rel-versions a{padding:.4rem .7rem;border:1px solid var(--rule);border-radius:var(--r)}.release header{flex-direction:column;align-items:start;gap:.3rem}.change-group{grid-template-columns:minmax(0,1fr);gap:.4rem}.rel-actions .btn{flex:1 1 100%;justify-content:space-between;white-space:normal}}
"""

# The same header and footer as the other pages of the website; keep them equal to those in pharos-site.
HEADER = r"""<header class="nav">
  <div class="wrap nav-in">
    <a class="brand" href="/">
      <img class="brand-logo" src="/assets/img/pharos-logo.svg?v=20260906" alt="Pharos" width="99" height="30">
    </a>
    <nav class="nav-links" aria-label="Main">
      <a href="/#product">Product</a>
      <a href="/#pricing">Pricing</a>
      <a href="/docs/">Docs</a>
      <a href="/releases/" aria-current="page">Releases</a>
      <a href="/#contact">Contact</a>
      <a class="gh" href="https://github.com/solutionmax/pharos"><svg class="gh-ico" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>GitHub</a>
    </nav>
    <div class="nav-end">
      <a class="nav-cta" href="/docs/#install">Install it</a>
      <details class="mobile-menu"><summary>Menu</summary><nav aria-label="Mobile navigation"><a href="/#product">Product</a><a href="/#pricing">Pricing</a><a href="/docs/">Docs</a><a href="/releases/" aria-current="page">Releases</a><a href="/#contact">Contact</a><a class="gh" href="https://github.com/solutionmax/pharos"><svg class="gh-ico" width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>GitHub</a></nav></details>
    </div>
  </div>
</header>"""

FOOTER = r"""<footer class="footer">
  <div class="wrap">
    <p class="footer-ask">A question? It lands with the person who wrote the code.</p>
    <p class="footer-mail">Normally answered within a working day: <a href="mailto:mail@solutionmax.net">mail@solutionmax.net</a></p>
    <div class="footer-grid">
      <div>
        <img class="footer-logo" src="/assets/img/pharos-logo-white.svg" alt="Pharos" width="79" height="24" loading="lazy" decoding="async">
        <p>A <a href="https://solutionmax.net">SolutionMAX</a> product. Self hosted software, made in Alicante, Spain.</p>
      </div>
      <nav aria-label="Product">
        <h2>Product</h2>
        <ul>
          <li><a href="/docs/">Documentation</a></li>
          <li><a href="/releases/">Releases</a></li>
          <li><a href="https://github.com/solutionmax/pharos">Source on GitHub</a></li>
          <li><a href="/cachet-alternative.html">Coming from Cachet</a></li>
          <li><a href="/uptime-kuma-status-page.html">Using Uptime Kuma</a></li>
          <li><a href="/status-page-cpanel.html">Status page on cPanel, DirectAdmin and Plesk</a></li>
          <li><a href="/llms.txt">llms.txt</a></li>
        </ul>
      </nav>
      <nav aria-label="Legal">
        <h2>Legal</h2>
        <ul>
          <li><a href="/legal.html">Legal notice and privacy</a></li>
          <li><a href="/legal.html#terms">Terms, delivery and refunds</a></li>
          <li><a href="/account/withdraw">Withdraw from a contract</a></li>
          <li><a href="/sales-es.html" lang="es" hreflang="es">Condiciones en español</a></li>
        </ul>
      </nav>
    </div>
    <p class="footer-end">© 2026 SolutionMAX · Core licensed AGPL-3.0 · These pages set no cookies and load nothing from third parties.</p>
  </div>
</footer>"""

TEMPLATE = r"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pharos @@VERSION@@: downloads and release notes</title><meta name="description" content="Download Pharos @@VERSION@@, choose a hosting installer or Docker, and review release notes, checksums and update instructions."><meta name="robots" content="index,follow,max-image-preview:large"><link rel="canonical" href="https://pharos.solutionmax.net/releases/"><meta name="theme-color" content="#ffffff"><meta property="og:type" content="website"><meta property="og:title" content="Pharos @@VERSION@@: downloads and release notes"><meta property="og:description" content="Get the latest Pharos release, choose your installation route and see what changed."><meta property="og:url" content="https://pharos.solutionmax.net/releases/"><meta property="og:image" content="https://pharos.solutionmax.net/assets/img/og.png"><meta name="twitter:card" content="summary_large_image"><link rel="icon" href="/assets/img/favicon.svg"><link rel="stylesheet" href="/assets/css/fonts.css?v=20261117"><link rel="preload" href="/assets/fonts/jost-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="/assets/fonts/instrument-sans-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="stylesheet" href="/assets/css/site.css?v=20261117"><script type="application/ld+json">@@SCHEMA@@</script><style>@@CSS@@</style></head>
<body><a class="skip" href="#main">Skip to content</a>
@@HEADER@@
<main id="main"><div class="wrap rel"><div class="rel-intro"><p class="eyebrow">Downloads and release notes</p><h1>The latest Pharos, ready for your hosting.</h1><p class="lead">Choose your installation, see what changed and update with a clear recovery path.</p></div>
<section class="rel-latest" aria-labelledby="latest-title"><div><p class="eyebrow"><i></i>Latest release</p><h2 id="latest-title">Pharos @@VERSION@@</h2><p class="small">Released @@DATE@@</p><p>@@SUMMARY@@</p><div class="rel-actions"><a class="btn btn-primary" href="https://pharos.solutionmax.net/pharos-install.php" download>Download web installer</a><a class="btn" href="https://pharos.solutionmax.net/releases/pharos-@@VERSION@@.zip">Download ZIP</a></div><p class="small">ZIP: @@SIZE@@ · Dependencies included · PHP 8.3+</p></div><div class="rel-points"><h3>Installing for the first time?</h3><p>Use the web installer on shared hosting. It checks requirements and verifies the signed release before unpacking.</p><a href="/docs/install/">Installation guide</a><h3>Already running Pharos?</h3><p>On PHP hosting, open Admin, then Updates. On Docker, update from the host using the guide below.</p><a href="#v@@VERSION@@">Read the release notes</a></div></section>
<section class="rel-paths" aria-label="Installation and update options"><article class="rel-path"><p class="eyebrow">Shared hosting</p><h3>Upload. Open. Set up.</h3><p>DirectAdmin, cPanel or Plesk. Upload the PHP installer to your web folder, open it in a browser and follow the steps, including cron verification.</p><a href="/docs/install/">Install without SSH</a></article><article class="rel-path"><p class="eyebrow">Docker</p><h3>Update your containers.</h3><p>For an existing Compose installation, set PHAROS_VERSION=@@VERSION@@ in its environment, then run:</p><pre><code>docker compose pull
docker compose up -d</code></pre><a href="/docs/docker/">First install or updating? Read the guide</a></article><article class="rel-path"><p class="eyebrow">PHP with SSH</p><h3>Install from your shell.</h3><p>For a new PHP installation, run the installer and follow the document root and scheduler instructions:</p><pre><code>curl -fsSL https://pharos.solutionmax.net/get | sh -s -- --php --version @@VERSION@@</code></pre><a href="/docs/install/ssh/">SSH installation guide</a></article></section>
<div class="rel-history" id="history"><aside class="rel-versions" aria-label="Release versions"><p class="eyebrow">Release history</p>@@NAV@@</aside><div>@@CARDS@@</div></div>
<section class="rel-note" id="specific-version"><h2>Before you update or roll back</h2><p>Take a backup first. Pharos snapshots include SQLite; MySQL needs a separate database backup. A version-pinned installer is for a fresh installation, not a downgrade. Restore matching code and database when rolling back.</p><p>Updating from 0.5.4 may require one PHP or container restart to clear old OPcache code. <a href="/docs/recovery/">Read the update and recovery guide</a></p><p><a href="https://pharos.solutionmax.net/releases/latest.json">Signed update manifest</a></p></section>
<p class="copy-status" role="status" id="copy-status"></p></div></main>
@@FOOTER@@
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
