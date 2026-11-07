# Security policy

## Reporting

Email security@dark.net.au with steps to reproduce. Please do not open a public
issue for a vulnerability.

## What this software handles

* BootStrapServer credentials (username + password)
* An SSH private key that can clone (and, depending on how it was registered,
  push to) the LightNetAI repositories
* The contents of the document root it is installed into

## Threat model

**Trusted:** the hosting server, its PHP runtime and the operator using the
panel shell. The frontend necessarily handles the private key in memory and
briefly on disk; a compromised host can always read it.

**Untrusted:** the network between the browser, the frontend host and
BootStrapServer; other tenants on shared hosting; anyone who finds the login
page.

## Mitigations

| Risk | Mitigation |
|---|---|
| Credentials in transit | HTTPS-only backend (enforced unless `allow_insecure_backend`), no redirects followed |
| Credentials at rest | never stored; only Argon2id hashes live in the backend DB |
| Key in transit | AES-256-GCM, key = PBKDF2-HMAC-SHA256(password, random salt, 210k), AAD binds username + key fingerprint |
| Key at rest (backend) | AES-256-GCM with a 256-bit `app_key` outside the database |
| Key on the frontend | server-side session file (0600) only, 30 min TTL, no key in cookies or HTML; 0600 temp file during git/ssh, deleted with the staging directory |
| MITM on git/ssh | pinned github.com host keys, `StrictHostKeyChecking=yes`, `IdentitiesOnly=yes`, `ssh -F /dev/null` |
| CSRF | per-session token required on every POST |
| Session theft | `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, optional UA/IP binding |
| Brute force | per-IP and per-IP+username lockout in the backend |
| Username enumeration | identical error and constant-time dummy password check |
| Command injection | argv arrays for `proc_open`, strict repo-name allowlist |
| Path traversal | repo name regex rejects `/` and `..`; deploy only writes inside the web root |
| Source leakage | `.git` removed after clone by default; `.htaccess` denies `lib/`, `views/`, `config.php` |
| Web-root leftovers | unknown files block deployment until explicitly confirmed |

## Known limitations

* Listing repositories requires the GitHub API; SSH cannot enumerate repos.
* Private repositories need a `github_token` in `config.php`.
* A deploy key is scoped to one repository; a normal account SSH key can clone
  every repository the account can read. Use whichever matches your policy.
* The deployment deletes everything in the web root. Do not install this into a
  directory that also holds unrelated sites.
* Non-Linux filesystems may refuse to unlink the executing script; the deploy
  still succeeds but the operator must remove `.bs-old-*` manually.
* Once deployed, this application is gone by design. There is no admin panel,
  no update mechanism and no second deploy from the same install.
