# Pharos — status en sessieoverdracht

Bijgewerkt: 23 september 2026. Deze notitie beschrijft de multi-page uitbreiding en de interne testinstallatie.

## Belangrijk voor de volgende sessie

- **Actieve werkmap:** `/root/pharos-multipage-20260920`
- **Branch:** `feature/multiple-status-pages`
- **Laatste implementatiecommit:** `2e9256a` (page roles, scoped tokens, delivery filters); QA-fix `33f5de6` (23 sep).
- Oorspronkelijke repository: `/root/projects/pharos`; basis van deze uitbreiding: `007c0c9` (v0.6.0).
- Werk verder in de actieve werkmap. Niet opnieuw beginnen in de oorspronkelijke checkout.
- **Niet naar GitHub gepusht.** Gebruiker wil eerst intern testen en pas publiceren wanneer alles af is. Geen release, merge of push uitvoeren zonder die vervolginstructie.
- Geen openstaande bekende blokkerende fouten na de laatste review. Gebruikersacceptatie van de laatste toevoegingen staat nog open.
- Cross-session geheugenopslag meldde een providerlimiet; vertrouw op dit bestand, Git en de documentatie. De geheugenworker is niet herstart.

## Toegevoegd en intern geplaatst

### Onafhankelijke statuspagina’s

- Meerdere pagina’s met eigen componenten, servicegroepen, incidenten, subscribers, instellingen en notificatiebestemmingen.
- Services behoren aan één pagina; delen of verplaatsen tussen pagina’s is niet geïmplementeerd.
- Hoofdpagina behoudt `/` en bestaande API- en abonnementslinks.
- Extra pagina’s gebruiken `/status/{slug}`; slug is na aanmaken onveranderlijk om bestaande links te behouden.
- Expliciete beheer-URL’s `/admin/pages/{id}/...` houden tabbladen onafhankelijk.
- Pagina’s kunnen concept, gepubliceerd of gearchiveerd zijn. Archiveren bewaart gegevens en stopt publicatie, monitoring en notificaties voor die pagina.
- Optionele eigen domeinen; DNS en TLS worden buiten Pharos ingericht. Beheer en API blijven op de centrale installatiehost.
- Onderliggende pagina-afbakening geldt ook voor routes, modelbinding, achtergrondtaken, notificaties en API-tokens.

### Navigatie en herkenbaarheid

- Iconen toegevoegd voor Status pages en Page email.
- Manage a page en View status page gebruiken dezelfde compacte vormgeving.
- View status page staat onderaan de navigatie en biedt gepubliceerde pagina’s aan.
- Begrensde, scrollbare keuzelijsten; bij meer dan vijf keuzes is zoeken op naam/tag beschikbaar. Eén keuzemenu tegelijk open.
- Hoofdpagina heeft blauwe Default-badge. Extra pagina’s hebben een eigen label en kleur; dit is alleen voor beheer.
- Status pages toont openbare links en beheer-/bekijkacties.
- Integrations, Mail templates en Page email maken zichtbaar op welke pagina instellingen betrekking hebben.

### Branding, mail en logo

- Eigen branding en uploads per pagina, binnen de bestaande Brand Pack-rechten.
- Page email ondersteunt overnemen van centraal mailtransport of eigen SMTP, plus eigen afzender en reply-to.
- Eigen mailtemplates per pagina.
- Settings → Central mail blijft nodig voor accountmails en pagina’s die centraal transport gebruiken. Wachtwoordherstel blijft centraal.
- Bevestigings- en uitschrijflinks gebruiken de centrale host en vaste paginaslug; eigen domein wijzigen maakt oude links niet ongeldig.
- Oude logo-uploads op de interne installatie vervangen door de huidige Pharos-logo’s. Bestanden staan in persistente opslag en worden door de updater behouden.

### Integraties en verzendhistorie

- Notificatiebestemmingen, waaronder Slack, zijn per pagina afgebakend.
- Afzonderlijke paginering: vijf regels voor bestemmingen, verzendhistorie en heartbeats; tien voor tokens.
- Historie filterbaar op bestemming, kanaal en resultaat: Delivered, Pending of Failed. Filters blijven behouden bij bladeren.
- Bekijken van historie verstuurt geen notificaties. Tijdens verificatie zijn geen echte testmails of Slack-testberichten verstuurd.

### Gebruikers en paginarollen

- Users → Page access wijst één of meerdere pagina’s aan een gebruiker toe, ook bij accountcreatie.
- Per toewijzing een eigen rol:
  - **Read only:** operationele overzichten en historie bekijken; geen wijzigingen of integratiecredentials.
  - **Editor:** componenten, services, incidenten, subscribers en notificatiebestemmingen beheren.
  - **Page administrator:** editorrechten plus eigen branding, mail, templates en API-tokens beheren.
- Globale beheerders houden toegang tot alle pagina’s. Installatie-instellingen, licenties, gebruikersbeheer en pagina’s aanmaken/publiceren/archiveren blijven globaal beheer.
- Bestaande toewijzingen zijn bij migratie Editor geworden. Wijzigingen trekken rechten direct in, ook in bestaande sessies.
- Viewer-weergave verbergt heartbeat-/checktargets en integratiecredentials. Dit is ook server-side afgedwongen.
- Paginarollen beperken beheer; een gepubliceerde statuspagina wordt hierdoor niet privé.

### API-tokens

- Tokens hebben één eigenaar, één pagina en scope `read` of `write`.
- Nieuwe tokens via de interface staan standaard op read; bestaande tokens en CLI-standaard behouden write voor compatibiliteit.
- Read kan private incidentinformatie lezen, maar geen wijzigingen uitvoeren.
- Write vereist daarnaast actuele bewerkrechten van de eigenaar. Downgrade naar Read only blokkeert bestaande schrijftokens direct.
- Intrekken van paginatoegang trekt tokenrechten in; verwijderen van eigenaar verwijdert diens tokens.
- Oude tokens zonder eigenaar blijven uitsluitend voor de hoofdpagina werken.
- Een eenmalig getoonde plaintext-token is aan de juiste pagina gekoppeld.
- CLI-voorbeeld: `php artisan pharos:token "FreeScout readout" --user=admin@example.net --page=2 --scope=read`.

### Licentie/paywall-basis

- Ondersteuning voor ondertekende feature `multi_pages` en optioneel maximum `limits.status_pages`.
- Zonder Multi-page-recht alleen de hoofdpagina; Brand Pack blijft apart herkenbaar.
- Whitelabelbundel kan `brand_pack` en `multi_pages` combineren.
- Downgrade blokkeert extra aanmaak/reactivatie boven de limiet; bestaande pagina’s en gegevens worden niet verwijderd.
- Prijzen, verkoopproducten, checkout en betaalprovider zijn nog niet aangepast.

## Interne installatie

- URL: `http://192.168.18.166:8130`
- Proxmox-toegang: `ssh proxmox`; container **106** via `pct exec 106 -- ...`.
- Applicatie: `/root/pharos-fresh`
- Webservice: `pharos-fresh.service`; scheduler via root-cron, service `cron`.
- Database: SQLite `/root/pharos-fresh/database/database.sqlite`.
- Hoofdpagina: SolutionMAX, ID 1.
- Extra demo: **Harbor Logistics — demo**, ID 2, tag Demo/teal.
- Demo-URL: `http://192.168.18.166:8130/status/harbor-demo`
- Demo bevat vier handmatige fictieve services en een fictief incident; geen actieve checks, subscribers of notificatiebestemmingen.
- Interne testlicentie: Brand Pack + Multi-page, maximaal vijf pagina’s, verloopt **22 oktober 2026**, gebonden aan `192.168.18.166`. Controleer dit wanneer later verder wordt getest.
- Vendor-private sleutel staat uitsluitend lokaal onder `/root/secrets`; niet naar de installatie kopiëren of in Git opnemen.
- Actuele logo’s: `storage/app/public/brand/pages/1/pharos-current-logo.png`, `pharos-current-logo-dark.svg`, `pharos-current-favicon.svg`.

## Laatste update, back-up en herstel

- Laatste volledige bron-/databaseback-up vóór de rechtenupdate: **`/root/pharos-permissions-backup.73liUB` in container 106**.
- Back-up bevat `source.tar.gz`, consistente `database.sqlite` en `counts.json`.
- Scheduler en HTTP zijn tijdens migratie gepauzeerd. Bestaande aantallen records zijn vóór en na migratie vergeleken en ongewijzigd gebleven.
- Beide nieuwe migraties geplaatst: scope op `api_tokens` en role op `status_page_user`.
- Na plaatsing acht beheer-/publieke routes HTTP 200; webservice en cron actief; logo-bestanden aanwezig.
- Een eerste back-uppoging stopte op een ontbrekend optioneel document, vóór applicatie-aanpassing. Services direct hervat, back-uppaden gecorrigeerd en update daarna succesvol uitgevoerd.
- Bij herstel broncode en database als passend paar herstellen; behoud `.env`, APP_KEY en uploads. Geen databasebestand kopiëren terwijl er geschreven wordt; gebruik SQLite backup-API.
- Bij terugzetten naar vóór deze update ook nieuw toegevoegde migratie-/middlewarebestanden verwijderen volgens het patchmanifest; een bronarchief uitpakken alleen verwijdert geen nieuwe bestanden.
- Lokale tijdelijke deploymentbestanden: `/tmp/pharos-permissions-deploy.sh`, `/tmp/pharos-permissions-verify.php`, `/tmp/pharos-permissions-patch-files.json`. `/tmp` is geen blijvende documentatie.

## Uitgevoerde verificatie

- Volledige suite: **733 tests, 3.151 assertions geslaagd**.
- MySQL 8.4 compatibiliteit: **31 tests, 218 assertions geslaagd**.
- Gecachte routes: **19 tests, 154 assertions geslaagd**.
- PHPStan: nul fouten. Pint en `git diff --check`: geslaagd.
- Onafhankelijke review: goedgekeurd na herstel van viewer-checktargetlek; regressietest dekt heartbeat- en HTTP-credentials op legacy en expliciete paginaroutes.
- Browser: roltoewijzing opslaan, directe rolverlaging in actieve sessie, filters/paginering en mobiele layout gecontroleerd.
- Tijdelijke QA-gebruiker en uitgeschakelde QA-bestemming na afloop verwijderd.
- Bewijsbestanden lokaal: `/tmp/pharos-permissions-full-final.json`, `/tmp/pharos-permissions-mysql.json`, `/tmp/pharos-permissions-cached.json`, `/tmp/pharos-permissions-static-final.txt`, `/tmp/pharos-permissions-format-final.txt`.
- Screenshots lokaal: `/tmp/pharos-page-roles-mobile.png`, `/tmp/pharos-delivery-filters-mobile.png`.

## Nog open

0. **QA-ronde 23 sep:** zie `docs/qa-rapport-2026-09-23.md`. Fix `33f5de6` (Users-lijst 500 bij onbekende rol) staat live op `.166` sinds 23 sep. Daarna nog HSTS (`c2a3d54`) en session-fixationfix (`f0ad4ef`); ook live op `.166` sinds 23 sep (hashes gelijk).

1. **Gebruikersacceptatie:** laatste rollen, tokenkeuzes en filters zelf testen op de interne installatie. Eventuele workflow-/vormgevingsfeedback verwerken.
2. **Releasevoorbereiding:** pas na akkoord versie, changelog, release-instructies en definitieve GitHub-publicatie voorbereiden. Er is nog niets gepusht.
3. **Commerciële uitwerking:** bepalen welke bundels/pagina-aantallen worden verkocht, prijzen, website-/checkoutteksten en uitgifte via betaalprovider. Technische licentierechten bestaan al; commerciële koppeling nog niet.
4. **FreeScout-module — later:** nog geen module gebouwd of FreeScout-installatie gewijzigd. Voorgesteld eerste bereik: mailbox koppelen aan Pharos-pagina en service-/incidentstatus naast tickets tonen. Read-only API-token is nu beschikbaar voor private incidentinformatie. Hooks en compatibiliteit eerst toetsen aan de daadwerkelijke FreeScout-versie.
5. **Externe configuratietests indien gewenst:** eigen SMTP-afzender/domeinautorisatie en eigen statusdomeinen/TLS in de gewenste omgeving controleren. Er zijn bewust geen echte nieuwe notificatietests verstuurd.
6. **Testlicentie:** zo nodig verlengen vóór 22 oktober 2026.

Niet geïmplementeerd of toegezegd voor deze update: gedeelde services tussen pagina’s, automatisch DNS/TLS provisionen, privé-klantportalen of een volledige FreeScout-koppeling.

## Nieuwe admin UI (23 sep, gebouwd en live op `.166`)

- Gebouwd door twee agents (navigatie en accounts / dagelijkse schermen), samengevoegd op `feature/multiple-status-pages` tot `0b9da21`. Mockups zijn verwijderd na bouw.
- Menu: groepen *This page* en *Installation* met uitklapbare subitems (Incidents, Services, Appearance, Email, Integrations & API, Settings). Profiel via naam onderaan.
- Nieuw: Overview met startwizard en Page health, zoeken (Ctrl K), statusbolletjes in paginakiezer, Status pages als kaarten, Users met uitnodigingsmail en optioneel verplichte 2FA, Profiel met sessies en thema per gebruiker, kruimelpad, toegankelijke dialogen.
- Nieuw: Incidents in 3 stappen met live voorbeeld, snelle acties en templates; Components per service met status in de rij; gepland onderhoud (`pharos:maintenance`, elke minuut); Integrations als Send out / Bring in / API tokens / Delivery log met echte logo's (Slack en Teams nog letters); events per bestemming; Uptime Kuma endpoint `POST /api/v1/integrations/kuma/{component}`; Audit log filters.
- Tekstregel: geen em/en dash en geen koppeltekens tussen woorden in zichtbare tekst.
- Migraties: events op webhook_endpoints, maintenances (+2 koppeltabellen), require_two_factor en theme op users, invitation_tokens.
- Verificatie: 833 tests / 3.775 assertions, PHPStan 0, Pint ok. Repetitie op kopie van live database, daarna live: alle 34 schermen 200, tellingen gelijk (alleen migraties + geleegde cache).
- Back-up vóór deploy in container 106: `/root/pharos-ui-backup.202609230852` (database.sqlite, source.tar.gz, counts-before/after.json).
- Vervolg 23 sep (live, back-up `/root/pharos-ui-backup.202609230924`): tijdzone per gebruiker (Profiel, alleen admin; publiek, mail, webhooks en previews blijven installatiezone; subscriber CSV-kop blijft `subscribed_at`), zoeken als commandopalet (acties, recent, schermen op trefwoord), officiële Slack- en Teams-logo's in `public/brand/partners/` (Slack via media kit, EULA door Raymon). 861 tests.
- Open: talen later · paginanaam "Harbor Logistics — demo" bevat een em dash (data, zelf aanpassen) · één keer een niet reproduceerbare testfout gezien (8 volgende runs groen).

## Documentatie en lokale ontwikkelomgeving

- `docs/multiple-status-pages.md`: beheer, API, mail, licenties, herstel en FreeScout-vervolg.
- `docs/superpowers/specs/2026-09-20-multiple-status-pages-design.md`: oorspronkelijke opzet.
- `docs/superpowers/plans/2026-09-20-multiple-status-pages.md`: eerste implementatieplan.
- `docs/superpowers/plans/2026-09-23-page-permissions.md`: rollen/tokens/filters en verificatie.
- Lokale preview: `http://192.168.18.162:8140`, database `database/preview.sqlite`, mail naar log, geen scheduler. Niet verwarren met de interne installatie op `.166`.
- MySQL-testcontainer: `pharos-multipage-mysql`, localhost-poort 13326, database `pharos_test`; uitsluitend testdata.
- Volgende sessie: lees dit bestand, controleer branch en `git status`, en ga verder met het door de gebruiker gekozen openstaande punt.
