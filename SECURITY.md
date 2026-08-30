# Security

Pharos is a status page: it holds the names of your services, the addresses it checks, subscriber e-mail addresses and your admin accounts. Take reports seriously and so do we.

## Reporting a vulnerability

E-mail **mail@solutionmax.net** with a description, the version (Updates screen or `config/pharos.php`) and, if you can, steps to reproduce. Please do not open a public issue for security problems.

You will get an answer within 3 working days. Fixes ship as a signed release; installs pick it up on the Updates screen.

## Scope

- The application in this repository, the web installer (`pharos-install.php`) and the `get` script.
- Not in scope: the hosting panel, PHP or web server your install runs on, and third-party services you connect (Slack, Zabbix, Uptime Kuma, your SMTP provider).

## What is already in place

Ed25519-signed release manifests verified before a byte is written; SafeHttp for every outbound webhook and SSO call (no loopback, link-local or metadata addresses, no redirects); security headers; throttled login, API and subscribe endpoints; zip-slip and symlink checks in the updater; secrets encrypted at rest in the database.
