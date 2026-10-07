# Hosting package and Composer installation

The local beta builder creates a flat hosting archive with production dependencies,
an application `VERSION` file, SHA-256 checksums, `release-info.json`, a
`pharos-latest.zip` alias and a local Composer repository (`packages.json`).

```sh
php scripts/build-local-package.php 1.0.0-beta.1 /path/to/private/beta-output
composer create-project --repository='{"type":"composer","url":"file:///path/to/private/beta-output/packages.json"}' --no-dev solutionmax/pharos pharos 1.0.0-beta.1
```

The builder does not publish a release or register the project on Packagist.
`release-info.json` is separate from the existing signed `latest.json`, so existing
updaters continue to verify the same manifest contract.

The archive excludes Docker and frontend build files, tests, developer tools,
configuration secrets and live storage. Set the document root to `public/`, copy
`.env.example` to `.env`, supply the installation URL/database and run:

```sh
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link
```

Keep `.env`, `APP_KEY`, the database and `storage/` during upgrades. Back up files
and the database consistently before replacing application files. Apply new
migrations, clear configuration/views and restart PHP workers to clear OPcache.
Restore the matching files and database together when rolling back.

## Plesk

Live Plesk verification is pending access to a Plesk test server. These steps are
an executable procedure, not a claim that a real Plesk installation has passed.

1. Select PHP 8.3 or later. Enable cURL, mbstring, OpenSSL, sodium, PDO SQLite
   (or PDO MySQL), XML/DOM, fileinfo, GD and ZIP. Check the packaged Composer
   requirements with `composer check-platform-reqs --no-dev`.
2. Extract the hosting package into `pharos-app/` under the site's own system
   user. Set Hosting Settings → Document root to `pharos-app/public`.
3. Set `.env` with the site's HTTPS URL, `APP_DEBUG=false` and its database.
   Generate `APP_KEY`, migrate, and make the storage link as that site's user.
4. Give that user write access to `storage/` and `bootstrap/cache/`; never use
   world-writable permissions. Deny web access to `.env`, `vendor/`, the database
   and everything outside `public/`.
5. Add a Scheduled Task, running as the site user every minute:

   ```sh
   /opt/plesk/php/8.3/bin/php /var/www/vhosts/DOMAIN/pharos-app/artisan schedule:run
   ```

6. Finish account setup; prove an HTTP check, a delivered confirmation mail,
   a scheduled maintenance window, a backup, an update and rollback. Confirm
   the scheduler's last run is current and that no database/configuration file
   can be downloaded from a browser.

An actual test must record Plesk/PHP versions, PHP handler, cron user, outcomes and
the rollback result before this platform is marked verified.
