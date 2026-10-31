# Security

## What this file is

`system-info.php` prints your server's configuration to a browser. That is its
purpose, and it makes the file itself a disclosure risk: anyone who can read
the page learns your paths, your PHP version, your extensions, the address your
server answers on, and which of your settings are weak. Treat it the way you
would treat a `phpinfo()` page — set a PIN, serve it over HTTPS, and delete it
when you are done.

The PIN travels in the URL and lands in server logs. It is a doorbell, not a
lock. For a lock, put HTTP authentication in front of the file; the README
shows how for Apache, nginx and Caddy.

## Reporting a vulnerability

Report anything that lets someone read the report without the PIN, get the
file to write outside its counter in the temp directory, execute something, or
inject markup into the page.

Please do not open a public issue for those. Use GitHub's private reporting —
**Security → Report a vulnerability** on this repository — with a description
and, if you have one, a proof of concept. You will get an acknowledgement
within a few days, and credit in the release notes unless you would rather not
have it.

## Supported versions

The latest release is the supported one. This is a single file with no
dependencies: upgrading means replacing it.

## Out of scope

- The page disclosing server details to someone who has the PIN. That is the
  feature.
- Missing hardening on a deliberately open install (`ACCESS_PIN = '0'`).
- Findings that amount to "an attacker with shell access on the server can
  read the file".
