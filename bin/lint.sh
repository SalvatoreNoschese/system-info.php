#!/usr/bin/env bash
#
# Pre-publish checks for system-info.php. Runs anywhere with bash and php,
# Termux included: pkg install php, then bash bin/lint.sh from anywhere in
# the repository.
#
# Exit code is 0 when everything passed, 1 otherwise.

set -u

FILE="system-info.php"
failed=0

if [ -t 1 ]; then
	red=$'\e[31m'; green=$'\e[32m'; yellow=$'\e[33m'; dim=$'\e[2m'; off=$'\e[0m'
else
	red=""; green=""; yellow=""; dim=""; off=""
fi

pass() { printf '%s  ok  %s%s\n' "$green" "$off" "$1"; }
warn() { printf '%s warn %s%s\n' "$yellow" "$off" "$1"; }
fail() { printf '%s FAIL %s%s\n' "$red" "$off" "$1"; failed=1; }
note() { printf '%s      %s%s\n' "$dim" "$1" "$off"; }

cd "$(dirname "$0")/.." || exit 1

if [ ! -f "$FILE" ]; then
	fail "$FILE not found next to bin/ — is this the repository?"
	exit 1
fi

if ! command -v php >/dev/null 2>&1; then
	fail "php not found (on Termux: pkg install php)"
	exit 1
fi
note "$(php -r 'echo "php " . PHP_VERSION;')"

# --- 1. it parses -------------------------------------------------------
if php -l "$FILE" >/dev/null 2>&1; then
	pass "parses"
else
	fail "syntax error"
	php -l "$FILE" 2>&1 | sed 's/^/      /'
fi

# --- 2. it runs, quietly ------------------------------------------------
# The CLI path builds the whole report: every collector, every check, the
# Markdown export. Warnings count as failures — a page that reports on a
# server should not be emitting reports about itself.
errors="$(php -d error_reporting=E_ALL -d display_errors=stderr "$FILE" 2>&1 >/dev/null)"
if [ -z "$errors" ]; then
	pass "builds the report with no warnings"
else
	fail "the report emits diagnostics"
	printf '%s\n' "$errors" | sed 's/^/      /'
fi

# --- 3. shipped defaults ------------------------------------------------
if grep -qx "const ACCESS_PIN = '';" "$FILE"; then
	pass "ACCESS_PIN ships empty"
else
	fail "ACCESS_PIN is not empty — do not publish your own PIN"
	grep -n "^const ACCESS_PIN" "$FILE" | sed 's/^/      /'
fi

# --- 4. still one file, still no dependencies ---------------------------
extras=""
for stray in $(find . -name '*.php' ! -name "$FILE" ! -path './.git/*' 2>/dev/null); do
	extras="$extras $stray"
done
[ -e composer.json ] && extras="$extras composer.json"
[ -d vendor ] && extras="$extras vendor/"
if [ -z "$extras" ]; then
	pass "one file, no dependencies"
else
	fail "this is meant to be one file with no dependencies, but found:$extras"
fi

# --- 5. versions agree --------------------------------------------------
app="$(grep -m1 "^const APP_VERSION" "$FILE" | sed "s/.*'\(.*\)'.*/\1/")"
log="$(grep -m1 -o '^## \[[0-9][^]]*\]' CHANGELOG.md 2>/dev/null | tr -d '#[] ')"
if [ -z "$log" ] || [ "$app" = "$log" ]; then
	pass "version $app matches the changelog"
else
	fail "versions disagree: APP_VERSION=$app CHANGELOG=$log"
fi
# Not a failure: the heading stays "unreleased" until the day of the tag
# (CONTRIBUTING.md, "Publishing a new version").
if grep -m1 '^## \[' CHANGELOG.md 2>/dev/null | grep -qi 'unreleased'; then
	warn "CHANGELOG.md: $log has no date yet — give it one when you tag v$log"
fi

# --- 6. repository furniture --------------------------------------------
for f in README.md LICENSE; do
	[ -f "$f" ] || fail "$f is missing"
done
[ -f README.md ] && [ -f LICENSE ] && pass "README and LICENSE present"

if [ -f README.md ]; then
	# Both forms: Markdown images and the <img src="..."> the README uses to
	# set a width.
	for image in $( { grep -o '!\[[^]]*\]([^)]*)' README.md | sed 's/.*(\(.*\))/\1/'
		grep -o '<img [^>]*src="[^"]*"' README.md | sed 's/.*src="\([^"]*\)"/\1/'; } | sort -u); do
		case "$image" in
			http*) continue ;;
		esac
		[ -f "$image" ] || warn "README shows $image, which does not exist yet"
	done
fi

# --- 7. whitespace ------------------------------------------------------
if grep -q "$(printf '\r')" "$FILE"; then
	fail "CRLF line endings"
elif grep -q ' $' "$FILE"; then
	warn "trailing whitespace on some lines"
	note "$(grep -c ' $' "$FILE") line(s)"
else
	pass "clean line endings"
fi

# --- 8. the release download -------------------------------------------
# What GitHub's "Source code" zip of a tag will hold: `git archive`, which
# honours export-ignore in .gitattributes, is how GitHub makes it. That list
# keeps things out, so a new file at the top would ship unless it is listed
# too. Uncommitted edits count (`git stash create`). Outside a git checkout (a
# copy unpacked from a zip) the same answer comes from a throwaway repository
# that reads .gitignore and .gitattributes, deleted afterwards.
want="LICENSE system-info.php"
archive_top() { tar -t 2>/dev/null | sed 's#/.*##' | LC_ALL=C sort -u | tr '\n' ' ' | sed 's/ $//'; }
if ! command -v git >/dev/null 2>&1; then
	note "release zip: skipped (needs git)"
else
	how=""
	if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
		rev="$(git stash create 2>/dev/null)"
		[ -n "$rev" ] || rev=HEAD
		have="$(git -C "$(git rev-parse --show-toplevel)" archive --format=tar "$rev:$(git rev-parse --show-prefix)" | archive_top)"
	else
		have="$(
			tmp="$(mktemp -d)" || exit 1
			trap 'rm -rf "$tmp"' EXIT
			export GIT_DIR="$tmp" GIT_WORK_TREE="$PWD"
			git init -q && git add -A &&
				git -c user.name=lint -c user.email=lint@localhost -c commit.gpgsign=false \
					commit -q -m lint &&
				git archive --format=tar HEAD | archive_top
		)"
		how=" (via a temporary repository)"
	fi
	if [ "$have" = "$want" ]; then
		pass "release zip holds $want only$how"
	else
		fail "the release zip would hold: $have (expected: $want) — add export-ignore lines to .gitattributes"
	fi
fi

echo
if [ "$failed" -eq 0 ]; then
	printf '%sReady to publish.%s\n' "$green" "$off"
else
	printf '%sFix the above before publishing.%s\n' "$red" "$off"
fi
exit "$failed"
