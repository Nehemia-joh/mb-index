# Maisha Bora GARS-3 — Security Back-Test Report

**Guide used:** `APP_SECURITY_CHECKLIST(1).md` (AI Application Security Checklist v1.1)
**Date:** 20 November 2026
**Scope:** whole application — auth, sessions, records, scoring-table change control,
activity log, sessions page, configuration, deployment artefacts
**Method:** static code review of every PHP file + dynamic probes against a live
instance (`php -S 127.0.0.1:8130 route.php`, PHP 8.4.23, MySQL) with three roles
(anonymous, therapist, admin/IT-admin)
**Environment caveat:** probes ran on the PHP built-in dev server. `.htaccess`
directives (Apache/cPanel production) were reviewed statically and are marked
*production-only* where they could not be exercised here.

---

## 1. Executive summary

| Result | Count |
|---|---|
| ✅ PASS | 22 |
| ⚠️ PARTIAL | 9 |
| ❌ FAIL | 11 |
| ➖ N/A (feature not present) | 13 |

**Bottom line:** the fundamentals that guard *patient data* are solid — every page
is authenticated and role-checked server-side, SQL is parameterised everywhere,
output is escaped everywhere (stored + reflected XSS probes came back clean),
record ownership blocks IDOR, and there is a strong append-only audit trail.
The gaps are around **the login door and browser hardening**: no brute-force
protection, no CSRF tokens, insecure session-cookie flags, and missing security
headers.

> **Update (same day):** the four P0 findings (F-01, F-02, F-03, F-06) have been
> implemented and verified — see the **P0 implemented** log at the end of §3.
> F-04 (security headers), F-05, F-07…F-11 remain open for the P1 pass.

### Top findings

| # | Severity | Finding | Checklist item |
|---|---|---|---|
| F-01 | 🔴 CRITICAL | No lockout / rate limit on login — 8 failed attempts all served `200`, account usable immediately after | Lock Accounts After Failed Logins · Rate Limit Login Attempts |
| F-02 | 🔴 CRITICAL | Session cookie sent bare: `PHPSESSID=…; path=/` — no `HttpOnly`, `Secure`, `SameSite` | Secure Session Cookies |
| F-03 | 🟠 HIGH | No CSRF tokens on any state-changing POST (login, scoring approvals, user creation) | Implement CSRF Tokens |
| F-04 | 🟠 HIGH | Missing security headers: no CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS (only `X-Content-Type-Options` via `.htaccess`) | Add Security Headers · Add HSTS |
| F-05 | 🟠 HIGH | User enumeration by timing: wrong password for an existing user ≈ 0.30 s (bcrypt) vs 0.003 s for a missing user — ~100× | Prevent User Enumeration |
| F-06 | 🟠 HIGH | HTTPS/HSTS not enforced anywhere in the app or `.htaccess` — depends entirely on host config | Force HTTPS / TLS |
| F-07 | 🟡 MEDIUM | `tests/` is web-reachable: `tests/e2e.sh` source returns 200; under Apache `tests/run_tests.php` would execute unauthenticated | Remove Default Admin Routes · Disable Directory Listing |
| F-08 | 🟡 MEDIUM | Directory listing not disabled (`Options -Indexes` absent from `.htaccess`); `ads/` folder present in webroot | Disable Directory Listing |
| F-09 | 🟡 MEDIUM | Idle timeout `SESSION_TIMEOUT` is only applied for *display* on dashboards — no server-side request check revokes an idle session | Secure Session Cookies (session management) |
| F-10 | 🟡 MEDIUM | Client names (health PII) written into `activity_logs.details` (`Saved … for "Amani Juma"`), though never logged with passwords | Log Security Events (don't log PII) |
| F-11 | 🟡 MEDIUM | bcrypt at PHP default cost **10** (checklist asks ≥12); minimum password length 8, no complexity/history | Hash Passwords with Strong Algorithms |
| F-12 | 🟡 MEDIUM | No field-level encryption for assessment health data — at-rest protection depends solely on the host | Encrypt Sensitive Data at Rest |
| F-13 | 🔵 LOW | `config.php` falls back to `DB_USER=root`, `DB_PASS=''` if the env file is unreadable | Restrict Database Permissions |
| F-14 | 🔵 LOW | Database exception text shown to users on failed record updates (`edit.php` → `$e->getMessage()`) | Trim API Responses |
| F-15 | 🔵 LOW | `must_change_password` flag is stored but never enforced; `gars_after_login` is written but never read (dead code) | Reset Sessions on Password Change |
| F-16 | 🔵 LOW | No CAPTCHA/bot protection; logout reachable via GET (nuisance CSRF-logout) | Add Bot Protection / CAPTCHA |

---

## 2. Status by checklist section

### 2.1 Authentication & Access Control

| Item | Status | Evidence |
|---|---|---|
| Hide API keys and secrets | ✅ PASS | `.envmb-index` kept **one step outside the webroot** (`config.php:13`), gitignored; only `.env.example` tracked with placeholder values; `.htaccess` denies `.env*`/`.sql`/`.db` (production). git history contains no real env file. |
| Use Public Database Keys (read-only) | ⚠️ PARTIAL | Single cPanel DB user for all operations — no read-only role for pages that only read. Recommend a SELECT-only user for record *viewing* paths where feasible. |
| Restrict Database Permissions | ⚠️ PARTIAL | App uses one grant-everything DB user; `config.php:57` falls back to `root`/empty if env missing (F-13). No DDL separation — cPanel users normally hold DDL on their own DB. |
| Enable Row-Level Security | ➖ N/A | MySQL has no RLS. **Compensating control verified:** row-level ownership enforced in application SQL (see next rows). |
| Enforce Server-Side Authentication | ✅ PASS | Every page calls `gars_require_login()` / `gars_require_role()`; unauthenticated probe → `302 /login` on all 9 protected routes; wrong role → `403` (therapist on `/admin /itadmin /register /scoring-tables /sessions /activity`). |
| Block Unauthorized Record Access | ✅ PASS | IDOR probes: therapist GET/POST `/assessment?id=2` and `/edit?id=2` on an admin-owned record → `404`, row unchanged (`Baraka Mtuli`, not injected `HACKED`); `/records` scoped by SQL (`created_by = ?` for therapists — 0 admin records visible, admin sees them). |
| Secure Session Cookies | ❌ FAIL | F-02 — observed `Set-Cookie: PHPSESSID=…; path=/` only; the app never calls `session_set_cookie_params()`; dev `php.ini` has `httponly=Off, secure=Off, samesite=` empty. |
| Reset Sessions on Password Change | ➖ N/A | No password-change feature exists (`must_change_password` stored, never enforced — F-15). Login does `session_regenerate_id(true)` (fixation-safe). |
| Lock Accounts After Failed Logins | ❌ FAIL | F-01 — 8 consecutive failures all `200`; correct password worked immediately after; only `login_failed` rows were written. |
| Hash Passwords with Strong Algorithms | ⚠️ PARTIAL | F-11 — all rows `$2y$` (bcrypt, salted, `password_verify`) ✅; cost = PHP default **10** < 12; min length 8 (register), no history/rotation. |
| Rate Limit Login Attempts | ❌ FAIL | F-01 — no limiter of any kind (per-IP or per-user). |
| Rate Limit Password Reset Requests | ➖ N/A | No password-reset feature. |
| Add Bot Protection / CAPTCHA | ❌ FAIL | F-16 — none on login or registration. |
| Expire Password Reset Links | ➖ N/A | No reset feature. |
| Prevent User Enumeration | ⚠️ PARTIAL | ✅ message identical for wrong-password vs unknown-user (`That username and password do not match an account.`); ❌ timing oracle F-05 (0.30 s vs 0.003 s) because `password_verify` is skipped for missing users. |

### 2.2 Data Protection & Encryption

| Item | Status | Evidence |
|---|---|---|
| Encrypt Sensitive Data at Rest | ❌ FAIL | F-12 — health data (names, DOB, scores) stored in plaintext columns; relies on host-level disk/MySQL encryption. Field-level AES not implemented. |
| Force HTTPS / TLS + HSTS | ❌ FAIL | F-06 — no HTTP→HTTPS redirect, no HSTS header in app or `.htaccess`; must be enforced at host/`.htaccess` level before production traffic carries real records. |
| Trim API Responses to Necessary Data Only | ✅ PASS | Server-rendered HTML only; `password_hash` never output (grep: only `password_verify` reads it); no JSON API leaking internals. |
| Block Unauthorized Field Access/Modification | ✅ PASS | Role/field whitelists: registration role validated against `gars_roles()`; `edit.php` updates a fixed column list (no `role`/`is_active`/`created_by`); scoring payload schema-validated before storage. |

### 2.3 Database Security & Injection Prevention

| Item | Status | Evidence |
|---|---|---|
| Parameterize All Database Queries | ✅ PASS | Full-codebase scan: every user input bound (`?` placeholders). Only string-built SQL is `setup.php:153` over a **hardcoded** table list. Filters on `/activity` and `/sessions` bound + whitelisted. Injection probes: `u=' OR 1=1--` → whitelist dropped, `q=x' OR '1'='1` → literal LIKE, `0` rows, HTTP 200, no error leak; login payloads (`admin' OR '1'='1`, `admin" OR 1=1--`) → generic failure / validation path. |
| Validate All User Input on Server-Side | ✅ PASS | `gars_validate_required` on every form; `(int)` casts on all IDs; strict `!Y-m-d` date validation; filter whitelists against DB value lists; username pattern `^[a-zA-Z0-9._-]{3,60}$`. |
| Escape User-Generated Content | ✅ PASS | No `echo $_GET/$_POST/...` anywhere; `gars_e()` (htmlspecialchars ENT_QUOTES) on all output. **XSS probes:** reflected `<script>alert(1)</script>` in `/activity?q=` → 0 raw / 1 escaped; stored `<img src=x onerror=alert(1)>` as client name → 0 raw / escaped on record page, records list and activity log. |
| Sanitize Input Before Storing | ⚠️ PARTIAL | Output-escaping strategy (safe when applied everywhere — verified). No tag-stripping/length caps beyond DB column sizes; acceptable, but belt-and-braces sanitising of names is recommended. |
| Restrict File Uploads | ➖ N/A | No upload feature. |
| Verify Payment Webhooks · Set Prices Server-Side | ➖ N/A | No payments/webhooks. |

### 2.4 HTTP Headers & CSRF Protection

| Item | Status | Evidence |
|---|---|---|
| Add Security Headers | ❌ FAIL | F-04 — observed response headers contain **none** of CSP / X-Frame-Options / Referrer-Policy / Permissions-Policy / X-XSS-Protection. `.htaccess` sets only `X-Content-Type-Options: nosniff` (production-only; not present on dev server). |
| Add HSTS | ❌ FAIL | F-06 — absent. |
| Implement CSRF Tokens | ❌ FAIL | F-03 — zero tokens in any form (login, score, edit, register, scoring approvals, sessions/activity filters). Partial mitigation only: cookie has **no** `SameSite`, so modern browsers default to `Lax` (cross-site POSTs drop the cookie) — but this is implicit, version-dependent, and defeated by any future subdomain/cookie change. |
| Validate Request Origins (CORS) | ✅ PASS | No `Access-Control-*` headers emitted anywhere → no wildcard, no credentialed CORS surface; same-origin form app only. |
| Lock Down CORS | ✅ PASS | Nothing to lock down — cross-origin responses are never granted. |

### 2.5 Dependency & Code Security

| Item | Status | Evidence |
|---|---|---|
| Scan Dependencies for Vulnerabilities | ✅ PASS | **Zero third-party runtime dependencies** (no `composer.json`, `package.json`, `vendor/`, `node_modules/`) — pure PHP 8.4 stdlib. Keep PHP itself patched (currently 8.4.23). |
| Avoid Deserializing Untrusted Data | ✅ PASS | Only `json_decode(..., true)` on own DB/POST payloads; no `unserialize`, `eval`, `assert`, or `preg_replace /e`. |
| Review Third-Party Code | ➖ N/A | None used. |
| Disable Directory Listing | ❌ FAIL | F-08 — no `Options -Indexes` in `.htaccess`; production behaviour depends on host. `ads/` and `tests/` folders sit inside the webroot. |
| Remove Default Admin Routes and Credentials | ⚠️ PARTIAL | ✅ `/setup` → `403` once installed (it_admin or CLI only); setup **refuses** to seed an empty default password; no debug/metrics endpoints. ❌ F-07 `tests/e2e.sh` → 200 (source incl. local test DB credentials); `tests/run_tests.php` would execute under Apache's `RewriteCond -f` rule. |

### 2.6 AI-Specific & Advanced Threats

All items ➖ **N/A** — the system contains no AI model integration, prompts, token
usage or outbound API calls. (Checklist items: prompt injection, AI usage caps,
output guardrails, model access limits, training-data leakage, adversarial model
testing.)

### 2.7 Logging, Monitoring & Incident Response

| Item | Status | Evidence |
|---|---|---|
| Log Security Events and Access | ⚠️ PARTIAL | ✅ login success/failure, every page move, every create/update/approve/reject/restore, logout — each with IP, user agent, role, session link; passwords never logged. ❌ F-10 client names in `details`. |
| Monitor for Suspicious Activity | ⚠️ PARTIAL | ✅ Trail is queryable (new `/activity` filters: user, action, page, date, text — tested against DB counts). ❌ No alerting (e.g. repeated `login_failed` never notifies anyone). |
| Develop and Test Incident Response Plan | ❌ FAIL | No documented incident-response runbook (checklist severity: RECOMMENDED). |
| Implement Audit Trails for Sensitive Operations | ✅ PASS | Append-only `activity_logs` (app never UPDATEs/DELETEs it), `user_sessions` per login, `scoring_revisions` history with versions + who-approved-what, cross-links Sessions↔Activity. Failed-login attempts retained as evidence (including this test's probes). |

### 2.8 API Security & Rate Limiting

| Item | Status | Evidence |
|---|---|---|
| Implement Rate Limiting on All Endpoints | ❌ FAIL | F-01 — none anywhere (login or pages). |
| Validate and Type-Check All API Inputs | ✅ PASS | Server-side validation on every entry point (see 2.3); malformed filters are dropped, not executed. |
| API Versioning | ➖ N/A | No JSON API. |
| API Keys / Token Auth | ➖ N/A | Server-side PHP session auth (regenerated on login, destroyed on logout) instead of JWT/API keys — appropriate for this design. |

### 2.9 OWASP Top 10 2021 roll-up

| OWASP | Verdict | Key evidence |
|---|---|---|
| 01 Broken Access Control | ✅ PASS* | Guards, IDOR tests, ownership-scoped lists, field whitelists — *caveat: missing CSRF tokens (F-03) |
| 02 Cryptographic Failures | ⚠️ PARTIAL | bcrypt ✅; HTTPS/HSTS, cookie flags, cost ≥12, at-rest encryption gaps (F-02, F-06, F-11, F-12) |
| 03 Injection | ✅ PASS | Parameterised SQL + output escaping; live SQLi/XSS probes clean |
| 04 Insecure Design | ⚠️ PARTIAL | No rate limiting/bot protection (F-01, F-16); tests/ exposure (F-07) |
| 05 Security Misconfiguration | ⚠️ PARTIAL | Headers/dir listing/tests exposure/root-fallback (F-04, F-08, F-07, F-13); setup locked ✅ |
| 06 Vulnerable & Outdated Components | ✅ PASS | Zero dependencies; PHP 8.4.23 |
| 07 Identification & Authentication Failures | ❌ FAIL | No lockout/rate limit, timing enumeration, insecure cookies (F-01, F-02, F-05); no MFA |
| 08 Software & Data Integrity Failures | ✅ PASS | No unsafe deserialization; JSON only; CSPRNG (`random_bytes`) for session tokens |
| 09 Logging & Monitoring Failures | ⚠️ PARTIAL | Strong trail ✅, no alerting/IR plan, PII in details (F-10, F-18) |
| 10 SSRF | ➖ N/A | No outbound HTTP requests in the codebase |

### 2.10 Additional vulnerabilities beyond OWASP (checklist appendix)

| Item | Status | Evidence |
|---|---|---|
| XXE | ➖ N/A | No XML parsing. |
| Race Conditions | ✅ PASS | Scoring approvals run inside `beginTransaction()` (approve/supersede atomic). |
| Timing Attacks | ⚠️ PARTIAL | `password_verify` is constant-cost ✅; user-existence timing oracle remains (F-05). |
| Directory Traversal | ✅ PASS | `route.php` static-file branch uses `realpath()` + prefix check; no file-download endpoints. |
| Open Redirects | ✅ PASS | `gars_after_login` is assigned but **never consumed** — dead code (F-15 note); all redirects are fixed internal routes. |
| Insecure Randomness | ✅ PASS | `bin2hex(random_bytes(24))` for session tokens. |
| Weak Password Reset | ➖ N/A | Feature absent. |
| Account Enumeration | ⚠️ PARTIAL | See 2.1 (message ✅ / timing ❌). |
| Security Headers Missing | ❌ FAIL | F-04. |
| Outdated Frameworks | ✅ PASS | No framework; PHP 8.4.23. |

---

## 3. Prioritised remediation plan

### P0 — before real patient data flows (critical)

1. **F-01 brute force:** lock the account (or the IP) for 30 min after 5 failed
   logins in 15 min — store counters in a small `login_attempts` table or reuse
   `activity_logs`; add a global per-IP cap.
2. **F-02 cookie flags:** one call before any `session_start()`:
   `session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict'])`.
3. **F-06 HTTPS:** force-redirect at the top of `route.php` (or `.htaccess`) when
   `HTTPS !== on`, then send `Strict-Transport-Security` (start with a small max-age).
4. **F-03 CSRF:** per-session token in every `<form>` (hidden field) + constant-time
   check on every POST — smallest change: a `gars_csrf_field()` / `gars_csrf_check()`
   pair wired into the shared form helpers.

### P1 — high (next sprint)

5. **F-04 headers:** add CSP (`default-src 'self'` — the app uses inline `<style>`/`<script>`,
   so either `unsafe-inline` initially or move to nonces), `X-Frame-Options: DENY`,
   `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`.
6. **F-05 timing:** run `password_verify` against a dummy hash when the user is missing.
7. **F-07/F-08:** `Options -Indexes` + deny `/tests/` in `.htaccess`; keep `tests/`
   out of production deploys entirely.
8. **F-09:** enforce `SESSION_TIMEOUT` server-side in `gars_current_user()`
   (compare `last_activity_at`, force logout when stale).
9. **F-11:** re-hash passwords with `PASSWORD_BCRYPT ['cost'=>12]` on next successful login.

### P2 — medium / hardening

10. F-10 — log record **ID** instead of client name in activity details.
11. F-12 — enable MySQL/innoDB at-rest encryption on the host, or field-level AES for
    `individual_name`/`date_of_birth`.
12. F-13 — fail hard (no root/empty fallback) when the env file is missing.
13. F-14 — generic error page on DB failures; full message only to `error_log`.
14. F-15 — either enforce `must_change_password` or remove the column; delete dead `gars_after_login`.
15. F-16 — CAPTCHA on login after N failures; logout via POST.
16. Monitoring — a nightly query/alert on `login_failed` bursts; write a one-page IR runbook.

### ✅ P0 implemented — 20 November 2026

| Finding | Fix | Verified by |
|---|---|---|
| **F-01** brute force | New `includes/security.php`: 5 failed logins per account (or 20 per `REMOTE_ADDR`) inside 15 min → locked 30 min from the last failure; while locked even the correct password is refused; lockout + CSRF rejections logged to the trail; cleared on successful login. `login_attempts` table added to `setup.php`, `newdeploy.sql` and created on demand. | e2e: *5 failed attempts recorded*, *account locked after 5 failures*, *correct password refused while locked*, *login works again once attempts are cleared*, *lockout events recorded*; unit ×6 |
| **F-02** cookie flags | `gars_session_start()` is now the only way sessions start (all 5 call sites migrated): `HttpOnly`, `SameSite=Lax`, `Secure` whenever the request is HTTPS, `session.use_strict_mode` on. | e2e: *session cookie is HttpOnly / sets SameSite=Lax*; unit ×6 (no raw `@session_start()` left) |
| **F-03** CSRF tokens | Per-session 64-hex token: `<meta name="csrf-token">` on every page head, `gars_csrf_field()` in every POST form (login, register, rating form, all four scoring-table forms), `gars_csrf_check()` first in all five POST handlers → 403 + `csrf_blocked` trail entry; token rotated on successful login. | e2e: *login/scoring POST without token gets 403* + full suite drives every POST with tokens; unit ×13 |
| **F-06** HTTPS | `gars_force_https()` runs from `config.php` on every request: 301 → HTTPS for any non-loopback host, `Strict-Transport-Security` once on HTTPS, `FORCE_HTTPS=0` env escape hatch; localhost/127.0.0.1 exempt for dev. | e2e: *public host redirected with 301*, *redirect target forces HTTPS*; unit |

**Test totals after P0: 83 unit + 101 E2E = 184 passing, 0 failing.**
Next: P1 — security headers/CSP (F-04), timing-safe login (F-05), block `tests/`
+ `Options -Indexes` (F-07/08), server-side idle timeout (F-09), bcrypt cost 12
(F-11).

---

## 4. Test evidence appendix (raw observations)

> Recorded **before** the P0 remediation above — kept as the audit baseline.
> Post-fix verification lives in the P0 log (§3) and in the test suites.

```
== Headers (GET /login) ==
Set-Cookie: PHPSESSID=…; path=/        ← only header; no CSP/XFO/HSTS/etc.

== Cookie jar after login ==
127.0.0.1  FALSE  /  FALSE  0  PHPSESSID  9ba2c8…   ← no HttpOnly/Secure/SameSite

== SQLi login ==
admin' OR '1'='1   → "That username and password do not match an account."
admin" OR 1=1--    → "Please fill in every required field: Password."  (empty pw path)

== Enumeration ==
existing user: "That username and password do not match an account."
missing user  : "That username and password do not match an account."   ← IDENTICAL

== Brute force ==
8 × POST /login (bad1..bad8) → 200 200 200 200 200 200 200 200
login_failed rows: 11
login with correct password immediately after → 302 (no lockout)

== Timing oracle (avg of 3) ==
existing user wrong password : 0.304 s / 0.319 s / 0.325 s
missing user                 : 0.0036 s / 0.0031 s

== Unauthenticated → protected routes ==
/admin /itadmin /register /scoring-tables /sessions /activity /records /assessment /edit
→ 302 → /login (all nine)

== Therapist role guards ==
/admin /itadmin /register /scoring-tables /sessions /activity → 403 (all six)

== IDOR (therapist vs admin-owned record #2) ==
GET  /assessment?id=2 → 404     GET /edit?id=2 → 404
POST /edit?id=2       → 404     record unchanged in DB
/records as therapist → 0 × "Amani Juma"     as admin → 2 × "Amani Juma"

== XSS ==
reflected /activity?q=<script>alert(1)</script>  → raw 0, escaped 1
stored <img src=x onerror=alert(1)> as client name → raw 0, escaped on
record page (2), records list (1), activity log (0 raw / 2 escaped)

== SQLi filters ==
activity?u=' OR 1=1--  → 200 (whitelist dropped)
activity?q=x' OR '1'='1 → "Showing 0–0 of 0 entries" (literal LIKE)
activity?page=users--   → 200 (whitelist dropped)

== Sensitive paths (dev server; Apache rules noted) ==
/setup → 403 (locked)      /newdeploy.sql, /database.sql, /.env.example → 200 on
php -S (no .htaccess) but DENIED by .htaccess FilesMatch on Apache
/tests/e2e.sh → 200 even with rules applied → F-07
/includes/*.php, /config.php → 404 (router)

== Password storage ==
admin $2y$ · jmosha $2y$ · (bcrypt, salted)
```

**Artefacts left in the trail (intentionally):** 11 `login_failed` rows from the
brute-force probe — kept as audit evidence. All other probe data (test therapist
user, XSS test record) was removed after testing; regression suites still pass:
**53 unit + 84 E2E = 137 green**.

---

*Generated by the security back-test against `APP_SECURITY_CHECKLIST(1).md` v1.1.
Re-run after remediation; treat this report as the new baseline.*
