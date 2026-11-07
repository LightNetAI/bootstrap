#!/usr/bin/env bash
#
# Bootstrap installer for LightNetAI projects.
#
#   curl -fsSL https://raw.githubusercontent.com/LightNetAI/bootstrap/main/install.sh \
#     | bash -s -- --webroot "$HOME/public_html"
#
# The installer downloads the release archive, copies the frontend into the
# document root and writes config.php with the hard-coded backend URL
# (https://bootstrap.dark.net.au by default).
#
set -eu

OWNER="LightNetAI"
REPO="bootstrap"
BRANCH="main"
BACKEND="${BOOTSTRAP_BACKEND:-https://bootstrap.dark.net.au}"
WEBROOT=""
DATA_DIR=""
TOKEN=""
OWNER_OVERRIDE=""
GIT_BASE=""
FORCE=0
SKIP_HEALTH=0
ALLOW_INSECURE=0
ARCHIVE=""
PHP_BIN="${PHP_BIN:-php}"

usage() {
    cat <<'EOF'
Bootstrap installer

Usage:
  install.sh --webroot <path> [options]

Options:
  --webroot <path>        Document root to install into (default: current directory)
  --backend <url>         BootStrapServer base URL (default: https://bootstrap.dark.net.au)
  --data-dir <path>       Runtime data directory (default: outside the web root)
  --token <token>         Optional GitHub token so private repos are listed
  --owner <name>          GitHub owner (default: LightNetAI)
  --git-base <url>        Git base URL (default: git@github.com:<owner>)
  --archive <file>        Use a local .tar.gz instead of downloading (offline installs)
  --branch <name>         Git branch of this installer (default: main)
  --force                 Overwrite an existing install (keeps working config)
  --skip-health           Do not probe the backend during the self-test
  --allow-insecure-backend Allow an http:// backend URL (local testing only)
  --no-color              Disable coloured output
  -h, --help              Show this help

Examples:
  install.sh --webroot /home/site/public_html
  install.sh --webroot "$HOME/public_html" --token ghp_xxx
EOF
}

COLOR=1
while [ $# -gt 0 ]; do
    case "$1" in
        --webroot) WEBROOT="${2:?--webroot needs a value}"; shift 2 ;;
        --webroot=*) WEBROOT="${1#*=}"; shift ;;
        --backend) BACKEND="${2:?--backend needs a value}"; shift 2 ;;
        --backend=*) BACKEND="${1#*=}"; shift ;;
        --data-dir) DATA_DIR="${2:?--data-dir needs a value}"; shift 2 ;;
        --data-dir=*) DATA_DIR="${1#*=}"; shift ;;
        --token) TOKEN="${2:?--token needs a value}"; shift 2 ;;
        --token=*) TOKEN="${1#*=}"; shift ;;
        --owner) OWNER_OVERRIDE="${2:?--owner needs a value}"; shift 2 ;;
        --owner=*) OWNER_OVERRIDE="${1#*=}"; shift ;;
        --git-base) GIT_BASE="${2:?--git-base needs a value}"; shift 2 ;;
        --git-base=*) GIT_BASE="${1#*=}"; shift ;;
        --archive) ARCHIVE="${2:?--archive needs a value}"; shift 2 ;;
        --archive=*) ARCHIVE="${1#*=}"; shift ;;
        --branch) BRANCH="${2:?--branch needs a value}"; shift 2 ;;
        --branch=*) BRANCH="${1#*=}"; shift ;;
        --force) FORCE=1; shift ;;
        --skip-health) SKIP_HEALTH=1; shift ;;
        --allow-insecure-backend) ALLOW_INSECURE=1; shift ;;
        --no-color) COLOR=0; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
done

if [ -t 1 ] && [ "$COLOR" = "1" ]; then
    C_RESET=$'\033[0m'; C_DIM=$'\033[2m'; C_OK=$'\033[0;32m'; C_ERR=$'\033[0;31m'; C_INFO=$'\033[0;36m'
else
    C_RESET=""; C_DIM=""; C_OK=""; C_ERR=""; C_INFO=""
fi

info() { printf '%s==>%s %s\n' "$C_INFO" "$C_RESET" "$1"; }
ok()   { printf '%s  ok %s %s\n' "$C_OK" "$C_RESET" "$1"; }
die()  { printf '%sFAIL%s %s\n' "$C_ERR" "$C_RESET" "$1" >&2; exit 1; }
dim()  { printf '%s%s%s\n' "$C_DIM" "$1" "$C_RESET"; }

if [ -z "$WEBROOT" ]; then
    WEBROOT="$PWD"
fi
if [ "$WEBROOT" = "/" ]; then
    die "Refusing to install into /."
fi

command -v "$PHP_BIN" >/dev/null 2>&1 || die "php was not found in PATH. Pass PHP_BIN=/path/to/php."
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' \
    || die "PHP 8.1 or newer is required (found $("$PHP_BIN" -r 'echo PHP_VERSION;'))."

if ! mkdir -p "$WEBROOT" 2>/dev/null; then
    die "Cannot create $WEBROOT"
fi
WEBROOT="$(cd "$WEBROOT" && pwd -P)"

if [ "$(id -u)" = "0" ]; then
    dim "Warning: running as root. Prefer the site owner account so file ownership is correct."
fi

# Refuse to scribble over an unrelated site.
if [ "$FORCE" != "1" ] && [ -n "$(ls -A "$WEBROOT" 2>/dev/null || true)" ] \
   && [ ! -f "$WEBROOT/.bootstrap-manifest.json" ]; then
    if [ -f "$WEBROOT/index.php" ] || [ -f "$WEBROOT/index.html" ]; then
        die "$WEBROOT already contains a site. Re-run with --force if you really want to replace it."
    fi
fi

TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/bootstrap-install.XXXXXX")"
cleanup() { rm -rf "$TMP_DIR"; }
trap cleanup EXIT INT TERM

download() {
    url="$1"; destination="$2"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --retry 3 --retry-delay 1 --connect-timeout 15 "$url" -o "$destination"
    elif command -v wget >/dev/null 2>&1; then
        wget -q -O "$destination" "$url"
    else
        die "curl or wget is required to download the installer."
    fi
}

ARCHIVE_PATH="$TMP_DIR/bootstrap.tar.gz"
if [ -n "$ARCHIVE" ]; then
    [ -f "$ARCHIVE" ] || die "Archive not found: $ARCHIVE"
    cp "$ARCHIVE" "$ARCHIVE_PATH"
    info "Using local archive $ARCHIVE"
else
    info "Downloading $OWNER/$REPO ($BRANCH)"
    if ! download "https://codeload.github.com/$OWNER/$REPO/tar.gz/refs/heads/$BRANCH" "$ARCHIVE_PATH" 2>/dev/null; then
        download "https://github.com/$OWNER/$REPO/archive/refs/heads/$BRANCH.tar.gz" "$ARCHIVE_PATH"
    fi
fi

[ -s "$ARCHIVE_PATH" ] || die "Downloaded archive is empty."

tar -xzf "$ARCHIVE_PATH" -C "$TMP_DIR" || die "Unable to extract the archive."
SRC_DIR=""
for candidate in "$TMP_DIR"/"$REPO"-* "$TMP_DIR"/"$REPO"; do
    if [ -d "$candidate/app" ]; then
        SRC_DIR="$candidate"
        break
    fi
done
[ -n "$SRC_DIR" ] || die "Archive does not contain the expected app/ directory."

info "Installing into $WEBROOT"
cp -R "$SRC_DIR/app/." "$WEBROOT/"
ok "Copied application files"

chmod 755 "$WEBROOT" 2>/dev/null || true
find "$WEBROOT" -maxdepth 1 -type d -exec chmod 755 {} + 2>/dev/null || true
find "$WEBROOT/lib" "$WEBROOT/views" "$WEBROOT/bin" -type f -name '*.php' -exec chmod 644 {} + 2>/dev/null || true
chmod 644 "$WEBROOT/index.php" "$WEBROOT/.htaccess" 2>/dev/null || true
chmod 755 "$WEBROOT/bin/configure.php" 2>/dev/null || true

CONFIGURE_ARGS=(--webroot "$WEBROOT" --backend "$BACKEND")
if [ -n "$DATA_DIR" ]; then CONFIGURE_ARGS+=(--data-dir "$DATA_DIR"); fi
if [ -n "$TOKEN" ]; then CONFIGURE_ARGS+=(--token "$TOKEN"); fi
if [ -n "$OWNER_OVERRIDE" ]; then CONFIGURE_ARGS+=(--owner "$OWNER_OVERRIDE"); fi
if [ -n "$GIT_BASE" ]; then CONFIGURE_ARGS+=(--git-base "$GIT_BASE"); fi
if [ "$FORCE" = "1" ]; then CONFIGURE_ARGS+=(--force); fi
if [ "$SKIP_HEALTH" = "1" ]; then CONFIGURE_ARGS+=(--skip-health); fi
if [ "$ALLOW_INSECURE" = "1" ]; then CONFIGURE_ARGS+=(--allow-insecure-backend); fi

info "Configuring"
"$PHP_BIN" "$WEBROOT/bin/configure.php" "${CONFIGURE_ARGS[@]}" \
    || die "Configuration/self-test failed. Fix the errors above and re-run."

printf '\n'
ok "Bootstrap is installed in $WEBROOT"
printf '\n'
printf 'Open the site in your browser and sign in with the credentials from BootStrapServer.\n'
printf 'If you installed into a subdirectory, adjust the URL accordingly.\n\n'
dim "The Deploy button replaces every file in $WEBROOT (including this installer)."
