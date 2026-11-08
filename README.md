# Bootstrap

A one-command installer that turns an empty web folder into a small PHP
application which signs in against **BootStrapServer**, receives a
password-encrypted SSH key, lists the projects in
[github.com/LightNetAI](https://github.com/LightNetAI/), and deploys the one
you pick with `git` — deleting itself in the process.

```
curl -fsSL https://raw.githubusercontent.com/LightNetAI/bootstrap/main/install.php \
  | php -- --webroot "$HOME/public_html"
```

That is the whole installation — the installer is a PHP script, so no bash
features are involved. If piping into `php` is awkward on your host:

```
curl -fsSL -o /tmp/bootstrap-install.php \
  https://raw.githubusercontent.com/LightNetAI/bootstrap/main/install.php
php /tmp/bootstrap-install.php --webroot "$HOME/public_html"
```

Open the site, sign in with the credentials issued by the backend, choose a
project, press **Deploy project**.

---

## How it works

```
hosting shell                browser                       BootStrapServer
     |                          |                                 |
     | 1. one-line installer    |                                 |
     |------------------------->|                                 |
     | 2. login (user/pass)     |                                 |
     |                          |---- 3. HTTPS POST /session ---->|
     |                          |<--- 4. encrypted SSH key --------|
     |                          |      (AES-256-GCM, PBKDF2)       |
     |                          |                                 |
     |                          | 5. decrypt key locally          |
     |                          | 6. ssh -T git@github.com        |
     |                          | 7. list repos via GitHub API    |
     |                          | 8. POST ?a=deploy                |
     |                          |---- 9. git clone over SSH ------> GitHub
     |                          |----10. swap web root, delete installer
```

1. **Install** — `install.php` downloads this repository (or uses a local
   checkout/archive), copies `app/` into the document root, writes
   `config.php` with the hard-coded backend URL and runs a self-test.
2. **Sign in** — the login form posts to your own server, which forwards the
   credentials to BootStrapServer over HTTPS. The backend answers with the SSH
   private key sealed with a key derived from the password you just typed.
   Neither the password nor the key is ever sent back to JavaScript.
3. **Verify** — the frontend decrypts the key, checks it matches the advertised
   public key and fingerprint, then runs `ssh -T git@github.com` to prove the
   key works.
4. **Deploy** — the chosen repository is cloned over SSH into a staging
   directory, the web root is swapped by rename, an index file is verified, and
   then the previous tree (including this installer) is deleted.

## Requirements

| Requirement | Notes |
|---|---|
| PHP 8.1+ CLI | runs the installer and the app; `curl`, `openssl`, `session`, `json`, `mbstring` |
| `proc_open` | must not be in `disable_functions` |
| `git` and `ssh` binaries | on `PATH` (or set `git_binary`) |
| Writable document root | the installer and the deploy both write there |
| HTTPS | strongly recommended; set `allow_insecure_backend` only for local tests |

No Composer, no database, no Node. The frontend stores its session and cache in
a data directory outside the web root (or inside it, deny-protected, if
`open_basedir` forces that).

## Installer options

```
--webroot <path>         document root (default: current directory)
--backend <url>          BootStrapServer URL (default: https://bootstrap.dark.net.au)
--data-dir <path>        runtime directory (default: sibling .bootstrap-data-*)
--token <token>          fallback GitHub token (normally stored in BootStrapServer)
--owner <name>           GitHub owner (default: LightNetAI)
--git-base <url>         git base URL (default: git@github.com:<owner>)
--archive <file>         install from a local .tar.gz (offline)
--source <dir>           install from a local checkout instead of downloading
--branch <name>          installer branch (default: main)
--force                  overwrite an existing install, keep config.php
--skip-health            skip the backend probe in the self-test
--allow-insecure-backend permit an http:// backend (local testing)
--no-color               disable coloured output
```

`BOOTSTRAP_BACKEND` and `BOOTSTRAP_TOKEN` are read from the environment when the
matching option is not given (the token stays out of the process list and shell
history).

Re-running with `--force` overwrites the application files and regenerates
`config.php`; the data directory is deterministic, so session/cache state is
kept.

## Configuration

`config.php` is generated in the document root. The important keys:

```php
'backend_url'   => 'https://bootstrap.dark.net.au', // hard-coded, not user editable
'data_dir'      => '/home/site/.bootstrap-data-xxxxxxxxxx',
'github_owner'  => 'LightNetAI',
'github_token'  => '',                              // fallback; normally supplied by BootStrapServer
'git_base'      => 'git@github.com:LightNetAI',
'known_hosts'   => [ ... pinned github.com host keys ... ],
'session_ttl'   => 1800,
'deploy' => [
    'branch'             => '',    // '' = repository default branch
    'composer_install'   => false,
    'keep_git'           => false,
    'require_index'      => true,
    'opcache_reset_on_deploy' => false,
],
```

See [`app/config.example.php`](app/config.example.php) for every option.

## Why listing uses the API (and SSH is still used)

GitHub's SSH endpoint cannot enumerate repositories — it only authenticates
`git` operations. The project list therefore comes from the GitHub REST API,
while the issued SSH key is used for two things it *can* do:

* `ssh -T git@github.com` at sign-in, to prove the key is authorised and to
  show which account/repository it is scoped to;
* `git clone` with a pinned `known_hosts` and `IdentitiesOnly=yes` during
  deployment.

Public repositories are listed without a token. Private repositories need one,
stored once in BootStrapServer:

```bash
php bin/bootstrap-server github:token     # or --token-stdin
```

On login the backend seals the token into the encrypted envelope alongside the
SSH key. The frontend decrypts it, keeps it in its server-side session (0600,
never sent to the browser), and uses it for `/user/repos`, keeping only
repositories owned by `github_owner`. That endpoint is required because
`/users/{owner}/repos` never returns private entries, even when authenticated.
The token is discarded at logout.

Use a classic token with the `repo` scope, or a fine-grained token with access
to those repositories (**Metadata: read** is enough to list them; clones use
the SSH key). `github_token` in `config.php` is still accepted as a fallback
for local development; the value from the login envelope wins when both exist.

## Deployment mechanics (and why deleting the installer is safe)

The deployment has to delete the files that are currently serving the request.
It does that without leaving a half-written site:

1. Clone into `<webroot>/.bs-stage-XXXX/repo` — same filesystem, web root
   untouched. If the clone fails, nothing changed.
2. Optionally run `composer install` in the staging tree.
3. Rename every current web root entry into `<webroot>/.bs-old-XXXX`
   (instant, same filesystem).
4. Rename the staged project files into the web root.
5. Require `index.php` or `index.html` (or `public/index.php`); otherwise
   move everything back — a full rollback, the bootstrap still works.
6. Send the JSON response and flush it (`fastcgi_finish_request` when
   available).
7. Delete `.bs-old-XXXX` (which contains this installer) and the data
   directory.

On Linux the running PHP script keeps its inode open after the file is
unlinked, so step 7 can delete the very file executing step 7. The response has
already been sent. If a host refuses to delete the open file, the deployment
still succeeded; only `.bs-old-*` remains and can be removed manually.

Files the installer did not create are **not** deleted silently. If the web
root contains extras, deployment stops and the UI asks you to confirm; only
then is `force` sent.

## Security notes

* The password is only used in memory: once to authenticate, once as the PBKDF2
  input that unwraps the SSH key. It is never stored.
* The decrypted private key lives in a server-side PHP session file (0600,
  `data_dir/sessions`) with a 30 minute TTL, and is written to a 0600 file
  only for the duration of a git/ssh call.
* github.com host keys are pinned in `config.php` and re-checked against known
  fingerprints before every connection; `ssh -F /dev/null` ignores host-level
  ssh configuration.
* Every state-changing request is CSRF protected; the session cookie is
  `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS.
* Repository names are validated against `^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$`
  and passed to `proc_open` as an argv array, so no shell interpolation is
  possible.

See [SECURITY.md](SECURITY.md) for the threat model and reporting.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `git is not available to PHP` | enable `proc_open`, ensure `git` is on `PATH` |
| `SSH check failed: GitHub rejected the key` | add the public key printed by `key:show` to the GitHub account (or as a deploy key for the repo) |
| `The web root contains files …` | confirm the extra files can be deleted, then deploy again |
| `Could not decrypt the SSH key` | the password or the backend response changed in transit — retry; check TLS |
| Blank page after deploy | the project has no `index.php` or `index.html`; the deploy was rolled back |
| Private repos missing | store the token with `php bin/bootstrap-server github:token` (classic `repo` scope, or fine-grained with access to those repositories). The listing uses `/user/repos`; sign in again after changing it |
| Sign-in says `HTTP 403 … non-JSON response` | the backend host is returning an Apache/nginx error instead of JSON: make sure its document root is `public/`, PHP runs `index.php`, and `public/.htaccess` is readable by the web user (`chmod 644`, parent directories `+x`) |
| `php: command not found` | run the installer with the panel's binary, e.g. `php8.3` or `/usr/local/bin/php` |
| `FAIL PHP 8.1 or newer is required` | switch the shell to a newer PHP (panel PHP selector, `update-alternatives`) and re-run |

Logs: PHP error log plus `data_dir/cache` (repo list) and the session files.

## Development

```
php tests/run.php          # unit + integration tests (needs git and ssh-keygen)

php install.php --source app --webroot /tmp/site --skip-health   # local install
php -S 127.0.0.1:8100 -t /tmp/site tests/router.php              # run that install
```

The suite covers envelope decryption, config validation, process timeouts,
web-root swap, rollback, the unknown-files guard, path traversal, the CSRF gate
and `install.php` itself (local source, archive extraction, `--force`).

## License

MIT — see [LICENSE](LICENSE).
