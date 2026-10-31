# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-31

First public release.

### Added

- Resource meters for load, RAM, swap, disk, CPU and thermal zones, each with
  its percentage, refreshing in place.
- OPcache and APCu from inside PHP; Redis, Memcached and LSMCD found by
  sweeping the loopback ports and the usual socket paths, with no extension
  required.
- Host section: web server, PHP handler, the user PHP runs as, whether
  `.htaccess` is honoured, which reverse-proxy headers arrive, the domain
  being served, the address that domain resolves to, the address the machine
  itself answers on, and the domain's nameservers.
- Around thirty checks, sorted by severity and written as consequences.
- Markdown and JSON export of the whole report.
- PIN gate with per-address throttling, and a setup screen until a PIN is
  chosen. The PIN box hides what is typed, and the query string is moved from
  the address bar into the tab's session storage once the report has loaded.

[1.0.0]: https://github.com/SalvatoreNoschese/system-info.php/commits/main
