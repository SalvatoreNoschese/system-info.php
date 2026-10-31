# Contributing

Patches are welcome. The constraints below are what make the file worth
dropping onto a server you do not control, so a change that breaks one of them
will be asked to find another way round.

## The constraints

**One file.** No composer, no vendor directory, no build step, no assets. CSS
and JavaScript are inline. If a change needs a second file, it needs a
different design.

**PHP 8.0 syntax.** The file runs on whatever the host is running, and shared
hosting is slow to move. Enums, readonly properties, `never`, first-class
callables and 8.1+ syntax are out. CI parses the file on 8.0 through 8.5 and
every one of them has to accept it.

**Nothing leaves the machine** except the optional nameserver lookup, and that
one is behind `DNS_LOOKUPS`. No CDN, no fonts, no telemetry, no update check.

**Nothing is written** except the failed-PIN counter in the system temp
directory. Never the web root.

**Everything degrades.** No `/proc`, an `open_basedir` jail, a disabled
function, Windows: the section says what it cannot see and the page still
renders. A collector that can fatal takes the whole report down with it, so
guard the call rather than assume the extension.

## Style

Four spaces in PHP, CSS and JavaScript, tabs in shell scripts (`.editorconfig`
says so to your editor), one statement per line, `strict_types` on. Functions are small and
named for what they answer. Comments explain why a thing is the way it is —
the code already says what it does.

Findings are written as a sentence about the consequence, not a restatement of
the setting. "Session cookies are readable by JavaScript, so an XSS bug
becomes a session hijack" rather than "session.cookie_httponly is Off". If you
add a check, write it that way, and pick the severity by what happens if it is
ignored: `error` for something already broken or exploitable, `warning` for
something that will bite, `info` for something worth knowing.

## Before opening a pull request

```
bash bin/lint.sh
```

It parses the file, builds the report on the command line and fails on any
warning, and checks the things that are easy to forget: the shipped PIN, the
version numbers agreeing, nothing having crept in beside the one file. CI runs
the same script on every supported PHP version, so a green run here is a green
run there. It needs bash and php and nothing else — `pkg install php` is enough
on Termux.

Then load the page in a browser on at least one real server. Say in the PR
which PHP version, web server and SAPI you tested on — most bugs in a file
like this one are bugs about a particular host, not about the code in general.

Leave `ACCESS_PIN` empty in anything you commit. CI checks for it, because a
file that ships with an open PIN is a file that gets published with one.

## Publishing a new version

There are no releases: the download is `system-info.php` on `main`, and the
README links to it directly.

1. In `CHANGELOG.md`, give the new version its date:
   `## [x.y.z] - YYYY-MM-DD`.
2. Set `APP_VERSION` at the top of `system-info.php` to the same number
   (`bin/lint.sh` fails if they disagree).
3. Run `bash bin/lint.sh`, push to `main` and wait for CI to turn green.

Only push to `main` what you would want people to download: it is the
current version for everyone.

The actions in `.github/workflows/` are pinned to a commit, with the release
named beside it. `.github/dependabot.yml` has GitHub look for new releases
once a week and open one pull request that moves every pin; merge it once CI
is green.
