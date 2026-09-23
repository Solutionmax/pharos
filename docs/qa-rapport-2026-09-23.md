# Pharos QA-rapport multi-page en paginarechten (23 september 2026)

Doel: de interne installatie `http://192.168.18.166:8130` (CT 106) volledig doorlopen op functionaliteit en beveiliging. In het bijzonder: meerdere statuspagina's, paginarollen, API-tokens en integraties. Fouten herstellen zonder bestaande functionaliteit te breken.

## Aanpak

- Twee Sonnet-agents parallel, elk in een eigen worktree:
  - **Functioneel** (`/root/pharos-qa-functional`, branch `qa/functional`)
  - **Security** (`/root/pharos-qa-security`, branch `qa/security`)
- Getest op de live interne installatie met eigen tijdelijke testdata (prefix `QA-F` en `QA-S`): extra pagina's, gebruikers per rol, componenten, incidenten, subscribers, tokens en een uitgeschakelde webhook naar `127.0.0.1:9`.
- Echte data van pagina 1 (SolutionMAX) en pagina 2 (Harbor demo) niet aangepast. Geen `pharos:update`, geen service-restart.
- Mail staat op `MAIL_MAILER=log`, dus er is geen echte mail verstuurd. Er is geen Slack-bericht verstuurd: `webhook_deliveries` stond voor en na op 501.
- Nulmeting van alle tabellen vooraf. Alle testdata is na afloop verwijderd. De telling is terug op de nulmeting (2 pagina's, 1 gebruiker, 2 tokens, 47 + 4 componenten, 150 + 1 incidenten). `/status/qa-*` geeft 404 en `/` en `/api/v1/components` bevatten geen QA-tekst.

## Resultaat functioneel

| Onderdeel | Resultaat |
|---|---|
| Publieke pagina's `/` en `/status/{slug}` | OK. Concept en onbekend geven 404 |
| Scheiding van data tussen pagina's (HTML en API) | OK |
| Legacy `/api/v1/...` en `/api/v1/pages/{slug}/...` | OK |
| Token-scopes read/write, gebonden aan een pagina | OK. Read kan niet schrijven (403), verkeerde pagina 403, ongeldig 401 |
| Oude tokens zonder eigenaar | OK. Werken alleen op de hoofdpagina |
| API-validatie | OK. 422 en 404 correct |
| Rollen Read only, Editor, Page admin, geen toegang, globaal | OK na fix (27 van 28 checks slaagden eerst) |
| Rolverlaging in een lopende sessie | OK. Het werkt direct, zonder opnieuw inloggen |
| Filters en paginering van de verzendhistorie | OK via bestaande tests en code-review |
| Admin → Users | **Fout, hersteld** (zie hieronder) |
| Mobiel (390×844) | OK |
| Zoekveld in de paginakiezer bij meer dan 5 pagina's | Alleen code-review: de licentie staat maximaal 5 pagina's toe |
| RSS, Atom, JSON-feeds, badges, embeds | Bestaan niet in deze codebase |

## Resultaat security

| Onderdeel | Resultaat |
|---|---|
| Horizontale IDOR (ID's van pagina B via pagina A, expliciete en legacy routes) | OK. 404 via de global scope `BelongsToStatusPage` en `PageContext` |
| Verticale escalatie (viewer naar schrijven, editor naar page admin, page admin naar globaal) | OK. Alles 403 |
| Mass assignment (`status_page_id`, `role`, `user_id`) | OK. Whitelists, en `status_page_id` kan niet gewijzigd worden |
| Rolverlaging en intrekken van tokenrechten | OK. Direct effectief |
| Private incidenten via de API met een token van een andere pagina | OK. Blijven verborgen |
| Stored XSS (namen, incident-markdown) | OK. Escaped |
| Concept- en gearchiveerde pagina's publiek | OK. 404 |
| Omzeilen van de licentielimiet met een directe POST | OK. `lockForUpdate` in een transactie |
| Host-header en cache poisoning | OK. Links komen uit `app.url` |
| CSRF, rate limit op login, security headers, cookieflags | OK |
| Debug-lekken (`.env`, `.git`, telescope, ignition, phpinfo) | OK. Alles 404 |
| 2FA-bypass en scope van wachtwoordherstel | Niet live getest; deze code is niet gewijzigd in deze feature |

**Geen kwetsbaarheden gevonden.**

## Gevonden en hersteld

**Medium: `/admin/users` gaf 500** zodra een `status_page_user`-rij een onbekende rolwaarde had. De app schrijft zo'n waarde zelf nooit weg, maar een import of handmatige wijziging kan hem achterlaten. Dan crasht de gebruikerslijst voor alle beheerders.

- Oorzaak: `resources/views/admin/users.blade.php` zocht de rol op in een array zonder fallback.
- Fix: `?? $assignedPage->pivot->role`. Commit `33f5de6`, ge-fast-forward op `feature/multiple-status-pages`.
- Regressietest: `PageRolesTest::test_users_list_survives_an_unrecognised_page_role_value`.

## Verificatie na de fix

- Volledige suite: **734 tests, 3.153 assertions geslaagd** (was 733 en 3.151; 1 test erbij).
- PHPStan 0 fouten, Pint geslaagd, `git diff --check` schoon.

## Incidenten tijdens het testen

- Het eerste fixture-script van de security-agent maakte records buiten een HTTP-request aan. Die kwamen daardoor op **pagina 1** terecht en waren **kort publiek zichtbaar** op `/` en `/api/v1/components` (`QA-S Incident A`, `QA-S Component A/B`). Binnen dezelfde sessie verwijderd. De aantallen van pagina 1 zijn weer gelijk aan de nulmeting. Er is **geen** Slack- of mailnotificatie uitgegaan.
- Omgevingsvalkuil: `vendor/` in de QA-worktrees is een symlink naar deze werkmap. Daardoor draait `php artisan test` daar zonder `APP_BASE_PATH` stilletjes tegen de code van de hoofdwerkmap. Altijd `APP_BASE_PATH=$PWD php artisan test` gebruiken.

## Nog open

1. **Fix live op `.166`** (23 sep): bladebestand gekopieerd, `view:clear` gedraaid, hash gelijk aan de repo.
2. Aanbevelingen uitgevoerd (23 sep, zie hieronder). Deze commits staan nog niet op `.166`.
3. Nog steeds niet gepusht naar GitHub. De QA-branches `qa/functional` en `qa/security` kunnen weg na akkoord.

## Vervolg: aanbevelingen uitgevoerd

### HSTS
- `Strict-Transport-Security: max-age=31536000` wordt alleen verstuurd over HTTPS, zonder `includeSubDomains` en zonder `preload`. Over HTTP verandert er niets. Achter een TLS-proxy werkt het alleen als `TRUSTED_PROXIES` is ingesteld. Een header die de proxy zelf al zet, heeft voorrang.
- Commit `c2a3d54`, test `SecurityHeadersTest::test_hsts_is_sent_over_https_only`.

### Eigen domein
- De eerdere aanbeveling klopte niet. Een eigen domein instellen kan alleen via `admin/pages`, en daar zit `EnsureAdmin` op: alleen globale beheerders dus, geen Page admin.
- DNS-verificatie is niet gebouwd. Het risico is alleen een typefout door een vertrouwde beheerder, en DNS en TLS worden bewust buiten Pharos ingericht. Toevoegen als er ooit klanten zelf domeinen gaan instellen.

### Securityronde 2FA, wachtwoordherstel en login
Alleen lokaal getest, `.166` is niet aangeraakt.

| Controle | Resultaat |
|---|---|
| Admin bereikbaar na de wachtwoordstap maar vóór 2FA | OK |
| Brute force op de 2FA-code | OK. 5 pogingen per 300 s per gebruiker en IP; test toegevoegd |
| TOTP-replay | OK. Een al gebruikte stap wordt geweigerd |
| Recoverycodes eenmalig en gehasht | OK |
| 2FA aan- en uitzetten | OK. Aanzetten vereist een code, uitzetten het huidige wachtwoord |
| Remember-me omzeilt 2FA | OK |
| Wachtwoordherstel: enumeration, eenmalige token, verlopen, Host-header, rate limit | OK |
| Andere sessies na wachtwoordherstel | OK. Worden uitgelogd |
| 2FA na wachtwoordherstel | OK. Blijft vereist |
| Open redirect, CSRF bij uitloggen | OK |
| **Session fixation bij de wachtwoordstap** | **Hersteld** |

- **Session fixation (medium):** met 2FA aan werd het sessie-ID na een juist wachtwoord niet vernieuwd. Iemand die vooraf een sessie-ID had vastgezet, kwam zo in dezelfde halfingelogde sessie terecht. Voor volledige toegang was nog steeds de 2FA-code nodig. Fix: `session()->regenerate()` direct na de wachtwoordcheck. Commit `f0ad4ef`, test `AdminTest::test_the_session_id_is_rotated_as_soon_as_the_password_checks_out`.
- 2FA-rate-limit had nog geen test. Toegevoegd in `ba9c59f`.
- Bewust niet opgelost:
  - Brute force verspreid over veel IP's. Een limiet per gebruiker zou buitensluiten van het slachtoffer mogelijk maken, en dat is erger.
  - Een theoretische race bij gelijktijdige TOTP-replay. Die levert geen extra rechten op.
  - `pharos:2fa:disable` wordt niet gelogd. Dat is ontwerp: alleen acties met een actor worden gelogd.

**Na deze commits:** 737 tests, 3.163 assertions geslaagd; PHPStan 0; Pint ok.

**Valkuil (belangrijker dan eerst gedacht):** in de QA-worktrees wijst de classmap van Composer via de `vendor/`-symlink naar de hoofdwerkmap. Ook met `APP_BASE_PATH` testte `php artisan test` daar de code van de hoofdwerkmap. Alle eindcijfers in dit rapport zijn daarom opnieuw gedraaid in `/root/pharos-multipage-20260920` zelf.
