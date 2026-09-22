# Meerdere Pharos-statuspagina’s — ontwerp

Datum: 20 september 2026. Status: ontwerp ter review, geen implementatie.

## Doel en uitgangspunt

Eén installatie, centrale login, meerdere statuspagina’s met uitsluitend eigen
services, incidenten, branding, abonnees en mailinstellingen. Geen gedeelde services.
De gebruiker heeft deze paginascheiding gekozen. Werk blijft lokaal/intern;
geen push, release of publieke deployment zonder later akkoord.

Bron: lokale repository `/root/projects/pharos`, schone main op `007c0c9`
(release 0.6.0). CT106 draait `/root/pharos-fresh`, service
`pharos-fresh.service`, intern `http://192.168.18.166:8130`; het geïnstalleerde
configuratiebestand noemt 0.6.0. Dit bewijst nog geen volledige bestandsidentiteit.
Vóór deployment vergelijken we de applicatiebestanden met de releasebasis.

Stack blijft PHP/Laravel met bestaande database- en mailvoorzieningen. Geen nieuwe
tenancy-, billing- of queuebibliotheek. SQLite en MySQL blijven ondersteund.

## Bestaande situatie en concrete gevolgen

- `StatusPageController` bevraagt componenten, groepen en incidenten installatiebreed.
- `Branding`, `MailTemplates`, `MailConfig` en `Subscriptions` lezen globale instellingen.
- `SubscriberNotifier::queue()` selecteert alle actieve abonnees bij een publiek incident.
- API-tokens hebben nu geen eigenaar of paginatoewijzing.
- `License` controleert offline ondertekende rechten en bindt aan de host uit APP_URL.
- De licentiedocumentatie bevat verouderde passages over domeinen en verlopen sleutels;
  implementatie en actuele regressietests zijn de basis, documentatie wordt bijgewerkt.

## Datamodel en grenzen

Voeg `status_pages` toe: id, unieke slug, naam, publicatiestatus en timestamps.
Een installatie-instelling wijst de standaardpagina aan. Deze kan niet worden verwijderd.
Nieuwe pagina’s beginnen leeg en ongepubliceerd; geen automatische kopie van diensten
of abonnees. Eerste versie biedt archiveren in plaats van permanent verwijderen.
Archiveren stopt publieke toegang en nieuwe meldingen; afmelden blijft werken.

Voeg verplicht `status_page_id` toe aan componenten, componentgroepen, incidenten,
incidenttemplates, abonnees en uitgaande webhook-endpoints. Checks, resultaten,
uptimehistorie en incidentupdates erven hun eigenaar via hun bestaande ouderrelatie.
Auditregels krijgen een optionele pagina-id; systeemacties blijven installatiebreed.
Alle ouder-kindkoppelingen moeten bij dezelfde pagina horen, ook bij API-writes.
Verplaatsen tussen pagina’s valt buiten deze update: historie blijft bij de oorspronkelijke pagina.

Voeg `status_page_settings` toe met unieke combinatie `(status_page_id, key)`.
Gebruik bestaande instellingencodering en versleuteling voor geheimen. Cachekeys
bevatten pagina-id. Pagina-instellingen vallen niet stilzwijgend terug op de branding
van een andere pagina. Nieuwe pagina’s starten met Pharos-standaardwaarden.
Alleen centrale mailtransportinstellingen mogen expliciet worden geërfd.

Abonnees zijn uniek per `(status_page_id, email)`. Bevestiging, afmelding,
notificatiededuplicatie en exports respecteren deze eigenaar. Een adres kan meerdere
pagina’s volgen en per pagina afmelden.

## Publieke routes, beheer en rechten

Behoud `/` en bestaande publieke routes voor de standaardpagina. Nieuwe pagina’s
krijgen `/status/{slug}` met bijbehorende incident-, historie- en abonnementsroutes.
Directe objectlinks worden binnen de gekozen pagina opgezocht; verkeerde pagina
geeft 404. Een onbekende host of slug mag nooit op een willekeurige pagina terugvallen.
Openbare pagina’s zijn geen besloten klantenportaal: URL-kennis geeft publieke toegang.

Beheer krijgt een zichtbare paginakiezer en pagina-id in de route. Geen uitsluitend
sessiegebaseerde selectie: twee browsertabs moeten veilig verschillende pagina’s beheren.
Installatiebeheerder heeft alle rechten. Bestaande niet-adminrollen behouden hun
rolbeperkingen en krijgen daarnaast expliciete toewijzingen via `status_page_user`.
Een toewijzing verleent nooit extra rolrechten. Gebruikers zonder toewijzing zien geen pagina.
Gebruikersbeheer, licentie, updates, SSO en accountmails blijven installatiebreed.

Eigen domeinen worden voorbereid door een optionele unieke domeinbinding per pagina.
Activatie vereist door installatiebeheerder gecontroleerde DNS/routering en TLS;
geen automatische certificaat- of DNS-providerintegratie in deze update.
Beheer en authenticatie blijven op de centrale installatie-URL. Gegenereerde publieke
links gebruiken uitsluitend opgeslagen, gevalideerde URL’s, nooit een willekeurige Host-header.

## API en integraties

Voeg `/api/v1/pages/{slug}/...` toe voor paginaresources. Bestaande `/api/v1/...`
routes blijven uitsluitend de standaardpagina bedienen; nooit een verzameling van alle pagina’s.
Nieuwe API-tokens krijgen eigenaar en één expliciete pagina. Toegang vereist zowel
het tokenrecht als het actuele paginarecht en rolrecht van de eigenaar.
Verwijderde/geblokkeerde eigenaren of ingetrokken toewijzingen maken het token onbruikbaar.
Gemigreerde bestaande tokens blijven expliciet installatiebeheerde tokens voor alleen
de standaardpagina, zodat bestaande automatisering niet breekt of extra rechten krijgt.

Kuma, Zabbix, heartbeats, inkomende webhooks en uitgaande meldingen krijgen dezelfde
grenzen. Heartbeattokens blijven aan hun eigen component gebonden. Een globale
integratiesleutel mag geen nieuwe pagina’s ontsluiten zonder expliciete configuratie.
Globale tellingen, statusaggregatie, previews en exports mogen geen andere pagina lekken.

## Branding en mail

Per pagina: naam, beschrijving, logo’s, favicon, kleuren, footer, zichtbare modules,
mailtemplates, afzendernaam, afzenderadres en reply-to. Bestaande uploadvalidatie behouden;
bestandslocaties per pagina scheiden. Geen willekeurige scripts of HTML toevoegen.

Mailtransport heeft twee expliciete standen: centrale SMTP gebruiken of eigen SMTP.
Bij eigen SMTP worden credentials versleuteld opgeslagen. Een fout in eigen SMTP
geeft een zichtbare verzendfout; nooit stilzwijgend via een andere identiteit versturen.
Een testmail gebruikt dezelfde afzender, branding en transportkeuze als echte meldingen.
Afzenderdomeinen moeten bij de gekozen provider correct zijn geconfigureerd.

De outbox bewaart expliciete pagina-eigendom. Zowel bij queueing als bij verzending
controleren we pagina, publicatiestatus, incidentzichtbaarheid en actieve inschrijving.
Mailers worden per verzending correct opgebouwd; geen globale configuratie laten
doorlekken naar het volgende bericht in hetzelfde cronproces. Bevestigings- en
afmeldlinks bevatten de juiste pagina. Afmelden blijft na archivering of licentieverlies mogelijk.
Accountwachtwoordherstel blijft centraal en gebruikt geen willekeurige klantbranding.

## Voorgesteld licentiebeleid

Gratis: één pagina. Bestaand `brand_pack` behoudt zijn huidige brandingrechten.
Nieuw ondertekend recht `multi_pages` staat extra pagina’s toe; optionele ondertekende
`limits.status_pages` begrenst het aantal niet-gearchiveerde pagina’s. Bij afwezigheid
van deze limiet betekent een geldig multi_pages-recht onbeperkt. Ongeldige limietwaarden
worden geweigerd; zonder recht geldt één pagina. Geen nieuw abonnementstarief vastleggen.
De commerciële Multi-page-bundel bevat zowel `brand_pack` als `multi_pages`.

Dit is een voorstel, geen wijziging van verkochte rechten. De eerdere interne roadmap
noemde meerdere pagina’s onderdeel van een betaalde whitelabellicentie. Vóór verkoop
wordt bepaald of bestaande Brand Pack-klanten multi_pages kosteloos krijgen via
heruitgifte; bestaande sleutels verliezen in ieder geval geen huidige functies.

Licentie bindt aan de centrale installatiehost (APP_URL), niet aan elk klantdomein.
Controle vindt serverzijdig plaats, inclusief API en gelijktijdig pagina-aanmaken.
Verlopen, verwijderde of lager gelimiteerde sleutels verwijderen geen gegevens:
bestaande pagina’s, monitoring, incidentbeheer, abonnementen en meldingen blijven werken.
Nieuwe extra pagina’s en heractivering boven de limiet worden geblokkeerd.
Brand Pack houdt de bestaande perpetual-semantiek. Geen ongemerkt nieuwe rechten
blijvend vastleggen op basis van een ongeldige of nooit geactiveerde sleutel.

Het bestaande verkoopportaal en de signer worden pas bij releasevoorbereiding aangepast;
intern testen gebeurt met aparte testondertekening. Geen Stripe-writes, prijswijzigingen
of echte klantmails tijdens ontwikkeling. Offline licentiecontrole blijft behouden.

## Migratie, uitrol en herstel

1. Schone lokale ontwikkelbranch/worktree vanaf 0.6.0; niets pushen.
2. Backup van database, instellingen, uploads en sleutelmaterialen vóór testdeployment.
3. Schema uitbreiden, standaardpagina maken, bestaande records eraan koppelen,
   paginagebonden instellingen kopiëren en bestaande gebruikers aan eerste pagina koppelen.
4. Pas na backfill eigendom en unieke indexen afdwingen. Bestaande ids, links,
   abonnementslinks, tokens en historie blijven behouden. Mailqueue niet opnieuw vullen.
5. Migratie oefenen op kopie met uitgaande mail/webhooks en echte checks uitgeschakeld.
6. Gecontroleerde interne deployment met scheduler kort gepauzeerd tijdens migratie;
   daarna checks hervatten en werking verifiëren.
7. Rollback herstelt applicatie én bijpassende databasebackup. Geen destructieve
   down-migratie die meerdere pagina’s weer tot één samenvoegt. Nieuwe gegevens sinds
   backup vereisen eerst export/herstelkeuze; geen stil verlies.

## Acceptatie en testbewijs

- Migratie van gevulde 0.6.0-data op SQLite en MySQL behoudt ids, historie en instellingen.
- Twee pagina’s met opvallend verschillende namen, logo’s, diensten en abonnees.
- Publieke HTML, incidentlinks, historie, previews, API en aggregaten tonen alleen eigen data.
- Gebruiker A kan B niet lezen, wijzigen, exporteren, koppelen of via geraden ids bereiken.
- Admin ziet beide; tokens worden beperkt door pagina en actuele eigenaarsrechten.
- Bestaande scripts blijven op standaardpagina werken; nieuwe pagina’s worden niet impliciet zichtbaar.
- Zelfde e-mailadres op A en B: bevestigen/afmelden werkt onafhankelijk.
- Eén cronbatch met mail voor A en B mengt geen SMTP, branding of links; retries dupliceren niet.
- Incident dat na queueing privé wordt of pagina die archiveert verstuurt geen publiek bericht meer.
- Eigen domeinen en canonieke URLs laten geen verkeerde pagina of Host-headerinjectie toe.
- Licentiematrix: gratis, Brand Pack, Multi-page, limiet bereikt, verlopen, ongeldig en downgrade.
- Gelijktijdig aanmaken overschrijdt limiet niet; bestaande pagina’s blijven bruikbaar.
- Bestaande PHPUnit-suite, Pint en PHPStan slagen; browsercontrole op desktop en mobiel.
- Interne testinstallatie pas bijwerken na bovenstaande controles en bruikbare rollbackbackup.

## FreeScout — vervolg na deze update

Later apart onderzoeken: storingen naast tickets, mailbox/klant koppelen aan pagina,
statuslink invoegen, eventueel incident aanmaken vanuit ticket. Eerst read-only koppeling
beoordelen. Deze update levert paginagebonden API en beperkte tokens; geen FreeScout-module,
extra webhooks of FreeScout-specifieke tabellen bouwen. Modulecompatibiliteit pas bij
dat onderzoek verifiëren tegen de dan gebruikte FreeScout-installatie.

## Fasering van uitvoering

1. Datamodel en migratie plus regressiebewijs.
2. Paginaresolutie, beheerrechten, publieke routes en API-isolatie.
3. Branding, abonnementen, mail en integraties per pagina.
4. Licentielimieten, beheerinterface, domeinbinding en volledige regressie.
5. Interne migratieproef, browseracceptatie en bevindingen voor gebruiker.

Alleen gezamenlijk vormen deze fasen een bruikbare update. Tussentijdse schemawijzigingen
gaan niet naar de actieve installatie. GitHub-push en publieke release blijven aparte acties.

## Uitvoeringsbesluiten na review — 22 september 2026

- Slugs zijn na aanmaken onveranderlijk; namen blijven aanpasbaar. Bevestigings- en
  afmeldlinks gebruiken centraal APP_URL met vaste slug, ook bij een klantdomein.
- API-verkeer gebruikt uitsluitend de centrale installatiehost. Een publieke
  weergavealias voor een pagina met eigen domein verwijst naar dat domein.
- Nieuwe CLI-tokens vereisen --user en --page. Bestaande gemigreerde systeemtokens
  blijven uitsluitend op de standaardpagina werken.
- Gestreamde exports houden paginacontext vast tot het verzenden van hun inhoud.
