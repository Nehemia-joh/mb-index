#!/usr/bin/env bash
# tests/e2e.sh — end-to-end HTTP tests for the Maisha Bora app.
# Run from the project root:  bash tests/e2e.sh
# Covers: wording/title/age JS, therapist form scoring, scoring-table
# edit + four-eyes approval, global effect of an approved revision,
# validation failures, role guards, version labels (v1.0, v1.1, …),
# restoring an older version from the history, sessions-by-user page,
# the filterable activity log (403 for therapists), the P0/P1 security
# hardening (CSRF tokens, cookie flags, HTTPS redirect, login lockout,
# security headers), the RBAC console (roles, groups, user management)
# and the mobile JSON API (/api/v1: bearer tokens, scoring, records).
set -u

BASE=http://127.0.0.1:8123
PASS=0
FAIL=0
JAR=/tmp/opencode/e2e-jars
OUT=/tmp/opencode/e2e-out
mkdir -p "$JAR" "$OUT"

pass() { PASS=$((PASS+1)); echo "  PASS  $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $1"; }
check() { # check <description> <expected> <actual>
  if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected [$2], got [$3])"; fi
}
contains() { # contains <description> <file> <needle>
  if grep -qF -- "$3" "$2"; then pass "$1"; else fail "$1 (missing: $3)"; fi
}
not_contains() { # not_contains <description> <file> <needle>
  if grep -qF -- "$3" "$2"; then fail "$1 (found: $3)"; else pass "$1"; fi
}
sql() { mysql -u fallback_change_me -pfallback_change_me maishabora_gars3 -N -B -e "$1" 2>/dev/null; }

# ---------------------------------------------------------------- clean slate
sql "DELETE FROM scoring_revisions WHERE change_note LIKE '%E2E%'";
sql "DELETE FROM assessments WHERE individual_name LIKE 'E2E-%'";
sql "DELETE FROM login_attempts";
sql "DELETE FROM app_settings";

# ---------------------------------------------------------------- start server
pkill -f "php -S 127.0.0.1:8123" 2>/dev/null
sleep 0.3
php -S 127.0.0.1:8123 route.php >/tmp/opencode/e2e-server.log 2>&1 &
SRV=$!
sleep 1
if ! curl -s -o /dev/null "$BASE/login"; then
  echo "server failed to start:"; tail -20 /tmp/opencode/e2e-server.log; exit 1
fi

# ---------------------------------------------------------------- helpers
token() { # token <jar> <page> — this session's CSRF token (from the page meta)
  curl -s -c "$1" -b "$1" "$2" | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' | head -1
}
login() { # login <jar> <user> <pass>
  rm -f "$1"
  local tok
  tok=$(curl -s -c "$1" -b "$1" "$BASE/login" | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' | head -1)
  curl -s -c "$1" -b "$1" -o /dev/null \
    --data-urlencode "username=$2" \
    --data-urlencode "password=$3" \
    --data-urlencode "csrf_token=$tok" \
    "$BASE/login"
}
assess_post() { # assess_post <jar> <name>  -> body of the scoring report
  local jar=$1 name=$2 data="" tok
  data="individual_name=$name&school=E2E-School&rater_name=E2E+Rater&rater_title=Therapist"
  data="$data&date_of_birth=2017-03-14&age=8&gender=Male&grade=3"
  data="$data&examiner_name=E2E+Examiner&examiner_title=Clinician"
  data="$data&date_of_rating=2026-11-20&rater_known_for=2+years&is_mute=no"
  local i
  for i in $(seq 0 12); do data="$data&rb_item_$i=2"; done
  for i in $(seq 0 13); do data="$data&si_item_$i=2"; done
  for i in $(seq 0 8);  do data="$data&sc_item_$i=2"; done
  for i in $(seq 0 7);  do data="$data&er_item_$i=2"; done
  for i in $(seq 0 6);  do data="$data&cs_item_$i=2"; done
  for i in $(seq 0 6);  do data="$data&ms_item_$i=2"; done
  tok=$(token "$jar" "$BASE/index")
  curl -s -c "$jar" -b "$jar" -d "$data&csrf_token=$tok" "$BASE/score"
}

echo "== setup: test accounts =="
login "$JAR/admin.jar" admin 'ChangeMe2026!'
ATOK=$(token "$JAR/admin.jar" "$BASE/scoring-tables")
if [ "$(sql "SELECT COUNT(*) FROM users WHERE username='admin2e2e'")" = "0" ]; then
  curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/register-admin2.html" \
    -d "new_username=admin2e2e&new_password=E2eAdminPass&confirm=E2eAdminPass&full_name=E2E+Second+Admin&role=it_admin&csrf_token=$ATOK" \
    "$BASE/register"
  check "register second it_admin" "1" "$(sql "SELECT COUNT(*) FROM users WHERE username='admin2e2e' AND role='it_admin'")"
fi
if [ "$(sql "SELECT COUNT(*) FROM users WHERE username='testere2e'")" = "0" ]; then
  curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/register-therapist.html" \
    -d "new_username=testere2e&new_password=E2eTherPass&confirm=E2eTherPass&full_name=E2E+Therapist&role=therapist&csrf_token=$ATOK" \
    "$BASE/register"
  check "register therapist" "1" "$(sql "SELECT COUNT(*) FROM users WHERE username='testere2e' AND role='therapist'")"
fi

# ---------------------------------------------------------------- auth & guards
login "$JAR/therapist.jar" testere2e 'E2eTherPass'
login "$JAR/admin.jar" admin 'ChangeMe2026!'
login "$JAR/admin2.jar" admin2e2e 'E2eAdminPass'
ATOK=$(token "$JAR/admin.jar" "$BASE/scoring-tables")
ATOK2=$(token "$JAR/admin2.jar" "$BASE/scoring-tables")

CODE=$(curl -s -c "$JAR/therapist.jar" -b "$JAR/therapist.jar" -o "$OUT/ther-scoring.html" -w "%{http_code}" "$BASE/scoring-tables")
check "therapist blocked from /scoring-tables" "403" "$CODE"
contains "therapist sees the 403 message" "$OUT/ther-scoring.html" "403"

CODE=$(curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/adm-scoring.html" -w "%{http_code}" "$BASE/scoring-tables")
check "admin can open /scoring-tables" "200" "$CODE"
contains "admin page lists Table A editor" "$OUT/adm-scoring.html" "Table A — raw subscale score"
contains "admin page shows built-in baseline" "$OUT/adm-scoring.html" "Built-in published table"
contains "built-in baseline is version v1.0" "$OUT/adm-scoring.html" "v1.0"
contains "admin nav has Scoring tables link" "$OUT/adm-scoring.html" "Scoring tables"

# ---------------------------------------------------------------- form wording
curl -s -c "$JAR/therapist.jar" -b "$JAR/therapist.jar" -o "$OUT/form.html" "$BASE/index"
contains "title is Therapist form" "$OUT/form.html" "<title>Therapist form</title>"
contains "h1 is Therapist form" "$OUT/form.html" "<h1>Therapist form</h1>"
contains "heinous fixed to humorous" "$OUT/form.html" "humorous stimuli"
contains "sting fixed to slang" "$OUT/form.html" "slang expressions"
not_contains "no heinous left" "$OUT/form.html" "heinous"
not_contains "no sting expressions left" "$OUT/form.html" "sting expressions"
contains "age auto-fill script shipped" "$OUT/form.html" "autoFillAge"
contains "date_of_birth input present" "$OUT/form.html" 'id="date_of_birth"'
contains "age input present" "$OUT/form.html" 'id="age"'

# ---------------------------------------------------------------- baseline score
assess_post "$JAR/therapist.jar" "E2E-Baseline" > "$OUT/report1.html"
contains "baseline: sum of scaled scores 65" "$OUT/report1.html" '<span class="pfig-n">65</span>'
contains "baseline: composite percentile 70" "$OUT/report1.html" '<span class="pfig-n">70</span>'
contains "baseline: Autism Index 108" "$OUT/report1.html" '<span class="plate-n">108</span>'
contains "baseline: Level 3 interpretation" "$OUT/report1.html" "level 3"

# ---------------------------------------------------------------- propose change
TBLB='[["88",null,[65,65],111],["50",[40,40],[60,60],100]]'
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/submit1.html" -L \
  --data-urlencode "action=submit" \
  --data-urlencode "kind=table_b" \
  --data-urlencode "note=E2E-B change sum65 percentile to 88" \
  --data-urlencode "payload=$TBLB" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/scoring-tables"
contains "proposal saved as pending" "$OUT/submit1.html" "saved as revision"
REV1=$(sql "SELECT id FROM scoring_revisions WHERE change_note LIKE 'E2E-B%' AND status='pending'")
[ -n "$REV1" ] && pass "pending revision exists (#$REV1)" || fail "pending revision missing"

# ---------------------------------------------------------------- four-eyes: own approval blocked
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/own-approve.html" -L \
  -d "action=approve&id=$REV1&csrf_token=$ATOK" "$BASE/scoring-tables"
contains "own approval is refused" "$OUT/own-approve.html" "your own change"
check "revision still pending after self-approve attempt" "pending" "$(sql "SELECT status FROM scoring_revisions WHERE id=$REV1")"

# ---------------------------------------------------------------- validation failure
BAD='[["1","<1",[5,2],null,null,null,null,null]]'
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/bad-table.html" \
  --data-urlencode "action=submit" \
  --data-urlencode "kind=table_a" \
  --data-urlencode "note=E2E-bad" \
  --data-urlencode "payload=$BAD" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/scoring-tables"
contains "inverted range rejected with a message" "$OUT/bad-table.html" "low ≤ high"
check "invalid proposal not stored" "0" "$(sql "SELECT COUNT(*) FROM scoring_revisions WHERE change_note LIKE 'E2E-bad%'")"

# ---------------------------------------------------------------- second admin approves
curl -s -c "$JAR/admin2.jar" -b "$JAR/admin2.jar" -o "$OUT/admin2-page.html" "$BASE/scoring-tables"
contains "second admin sees the pending change" "$OUT/admin2-page.html" "E2E-B change sum65 percentile to 88"
curl -s -c "$JAR/admin2.jar" -b "$JAR/admin2.jar" -o "$OUT/approve.html" -L \
  -d "action=approve&id=$REV1&csrf_token=$ATOK2" "$BASE/scoring-tables"
contains "approval confirmed" "$OUT/approve.html" "approved — it is now used by every scoring run"
check "revision approved" "approved" "$(sql "SELECT status FROM scoring_revisions WHERE id=$REV1")"
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/active.html" "$BASE/scoring-tables"
contains "Table B now shows an active revision" "$OUT/active.html" "Revision #$REV1"
contains "first approved revision is version v1.1" "$OUT/active.html" "v1.1"
contains "history offers Restore for decided revisions" "$OUT/active.html" ">Restore</button>"

# ---------------------------------------------------------------- global effect
assess_post "$JAR/therapist.jar" "E2E-After" > "$OUT/report2.html"
contains "new record uses revised Table B (88)" "$OUT/report2.html" '<span class="pfig-n">88</span>'
contains "new record uses revised Autism Index 111" "$OUT/report2.html" '<span class="plate-n">111</span>'
not_contains "old percentile 70 gone for sum 65" "$OUT/report2.html" '<span class="pfig-n">70</span>'

# ---------------------------------------------------------------- reject flow
BANDS='[{"max":100,"probability":"Very Likely","severity":"Level 2","descriptor":"Requiring Substantial Support"},{"max":null,"probability":"Unlikely","severity":"Not ASD","descriptor":"Not ASD"}]'
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/submit2.html" -L \
  --data-urlencode "action=submit" \
  --data-urlencode "kind=interpretation" \
  --data-urlencode "note=E2E-I" \
  --data-urlencode "payload=$BANDS" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/scoring-tables"
REV2=$(sql "SELECT id FROM scoring_revisions WHERE change_note LIKE 'E2E-I%' AND status='pending'")
[ -n "$REV2" ] && pass "interpretation proposal pending (#$REV2)" || fail "interpretation proposal missing"
curl -s -c "$JAR/admin2.jar" -b "$JAR/admin2.jar" -o "$OUT/reject.html" -L \
  -d "action=reject&id=$REV2&csrf_token=$ATOK2" "$BASE/scoring-tables"
contains "rejection confirmed" "$OUT/reject.html" "rejected"
check "revision status rejected" "rejected" "$(sql "SELECT status FROM scoring_revisions WHERE id=$REV2")"
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/active2.html" "$BASE/scoring-tables"
contains "interpretation still built-in after rejection" "$OUT/active2.html" "Built-in published table"

# ---------------------------------------------------------------- versions + restore an older version
C2='[["77",null,[65,65],105]]'
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/submit3.html" -L \
  --data-urlencode "action=submit" \
  --data-urlencode "kind=table_b" \
  --data-urlencode "note=E2E-C change sum65 to 77" \
  --data-urlencode "payload=$C2" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/scoring-tables"
contains "pending proposal shows the version it would become" "$OUT/submit3.html" "would become <b>v1.2</b>"
REVC=$(sql "SELECT id FROM scoring_revisions WHERE change_note LIKE 'E2E-C%' AND status='pending'")
[ -n "$REVC" ] && pass "second proposal pending (#$REVC)" || fail "second proposal missing"
curl -s -c "$JAR/admin2.jar" -b "$JAR/admin2.jar" -o "$OUT/approve-c.html" -L \
  -d "action=approve&id=$REVC&csrf_token=$ATOK2" "$BASE/scoring-tables"
contains "second proposal approved" "$OUT/approve-c.html" "approved — it is now used by every scoring run"
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/active3.html" "$BASE/scoring-tables"
contains "second approved version is v1.2" "$OUT/active3.html" "v1.2"
contains "old version still listed as v1.1 in history" "$OUT/active3.html" "v1.1"
assess_post "$JAR/therapist.jar" "E2E-Mid" > "$OUT/report3.html"
contains "newer version live (77)" "$OUT/report3.html" '<span class="pfig-n">77</span>'
contains "newer version live (AI 105)" "$OUT/report3.html" '<span class="plate-n">105</span>'

# go back: restore revision REV1 (the v1.1 values 88/111)
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/restore.html" -L \
  -d "action=restore&id=$REV1&csrf_token=$ATOK" "$BASE/scoring-tables"
contains "restore creates a new pending revision" "$OUT/restore.html" "created from #$REV1"
REV4=$(sql "SELECT id FROM scoring_revisions WHERE change_note LIKE 'Restored from #${REV1}%' AND status='pending'")
[ -n "$REV4" ] && pass "restored copy pending (#$REV4)" || fail "restored copy missing"
check "restored payload is identical to the old version" \
  "$(sql "SELECT payload FROM scoring_revisions WHERE id=$REV1")" \
  "$(sql "SELECT payload FROM scoring_revisions WHERE id=$REV4")"
# the restore still needs the second admin
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/restore-own.html" -L \
  -d "action=approve&id=$REV4&csrf_token=$ATOK" "$BASE/scoring-tables"
contains "restored copy cannot be self-approved" "$OUT/restore-own.html" "your own change"
curl -s -c "$JAR/admin2.jar" -b "$JAR/admin2.jar" -o "$OUT/approve-restore.html" -L \
  -d "action=approve&id=$REV4&csrf_token=$ATOK2" "$BASE/scoring-tables"
contains "restored version approved" "$OUT/approve-restore.html" "approved — it is now used by every scoring run"
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/active4.html" "$BASE/scoring-tables"
contains "restored version becomes v1.3" "$OUT/active4.html" "v1.3"
contains "superseded v1.2 still visible in history" "$OUT/active4.html" "v1.2"
assess_post "$JAR/therapist.jar" "E2E-Restore" > "$OUT/report4.html"
contains "scoring back to old version percentile 88" "$OUT/report4.html" '<span class="pfig-n">88</span>'
contains "scoring back to old version AI 111" "$OUT/report4.html" '<span class="plate-n">111</span>'
check "C2 (v1.2) superseded" "superseded" "$(sql "SELECT status FROM scoring_revisions WHERE id=$REVC")"
check "REV1 (v1.1) superseded" "superseded" "$(sql "SELECT status FROM scoring_revisions WHERE id=$REV1")"

# ---------------------------------------------------------------- sessions by user + activity log filters
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR/therapist.jar" "$BASE/sessions")
check "therapist gets 403 on /sessions" "403" "$code"
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR/therapist.jar" "$BASE/activity")
check "therapist gets 403 on /activity" "403" "$code"
contains "admin nav has Sessions link" "$OUT/adm-scoring.html" ">Sessions</a>"
contains "admin nav has Activity link" "$OUT/adm-scoring.html" ">Activity</a>"

ACCOUNTS=$(sql "SELECT COUNT(*) FROM users")
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/sess.html" "$BASE/sessions"
contains "sessions page heading" "$OUT/sess.html" "Sessions by user"
contains "summary lists every account" "$OUT/sess.html" "Accounts shown: $ACCOUNTS"
contains "summary shows the e2e users" "$OUT/sess.html" "testere2e"
contains "sessions filter form present" "$OUT/sess.html" 'name="status"'
contains "summary offers a Trace link" "$OUT/sess.html" ">Trace</a>"
contains "session detail caption present" "$OUT/sess.html" "Sessions found:"

SESS_T=$(sql "SELECT COUNT(*) FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE u.username='testere2e'")
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/sess-t.html" "$BASE/sessions?u=testere2e"
contains "user filter narrows the summary to one account" "$OUT/sess-t.html" "Accounts shown: 1"
contains "user filter narrows the session list" "$OUT/sess-t.html" "Sessions found: $SESS_T"
contains "filter select marks the chosen user" "$OUT/sess-t.html" 'value="testere2e" selected'
contains "active session shown as live" "$OUT/sess-t.html" ">live</span>"

EXP_LOGIN=$(sql "SELECT COUNT(*) FROM activity_logs WHERE action='login'")
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/act-login.html" "$BASE/activity?action=login"
contains "action filter count matches the database" "$OUT/act-login.html" "of $EXP_LOGIN entries"
contains "login rows rendered" "$OUT/act-login.html" "<td>login</td>"
not_contains "no page_visit rows inside a login-only view" "$OUT/act-login.html" "<td>page_visit</td>"

EXP_T=$(sql "SELECT COUNT(*) FROM activity_logs WHERE username='testere2e' AND action='page_visit'")
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/act-t.html" "$BASE/activity?u=testere2e&action=page_visit"
contains "user + action combo count matches the database" "$OUT/act-t.html" "of $EXP_T entries"

EXP_ALL=$(sql "SELECT COUNT(*) FROM activity_logs")
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/act-bad.html" "$BASE/activity?u=nobody-here"
contains "unknown user filter is ignored (whitelist)" "$OUT/act-bad.html" "of $((EXP_ALL + 1)) entries"
EXP_ALL2=$(sql "SELECT COUNT(*) FROM activity_logs")
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/act-baddate.html" "$BASE/activity?from=banana"
contains "invalid date filter is ignored" "$OUT/act-baddate.html" "of $((EXP_ALL2 + 1)) entries"
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/act-empty.html" "$BASE/activity?from=2099-01-01"
contains "future date range returns zero entries" "$OUT/act-empty.html" "of 0 entries"
contains "empty result offers a friendly message" "$OUT/act-empty.html" "No entries match"
contains "activity page links back to sessions" "$OUT/act-t.html" "sessions?u=testere2e"

# ---------------------------------------------------------------- history & records
curl -s -c "$JAR/therapist.jar" -b "$JAR/therapist.jar" -o "$OUT/records.html" "$BASE/records"
contains "records list shows the new assessment" "$OUT/records.html" "E2E-After"
curl -s -c "$JAR/therapist.jar" -b "$JAR/therapist.jar" -o "$OUT/record.html" "$BASE/assessment?id=$(sql "SELECT id FROM assessments WHERE individual_name='E2E-After'")"
contains "stored record shows revised percentile 88" "$OUT/record.html" '<span class="pfig-n">88</span>'

# ---------------------------------------------------------------- P0 security: cookies, CSRF, HTTPS, lockout
echo "== security: P0 hardening =="

curl -s -D "$OUT/hdr-login.txt" -o /dev/null "$BASE/login"
grep -i '^set-cookie:' "$OUT/hdr-login.txt" > "$OUT/setcookie.txt" || true
contains "session cookie is HttpOnly" "$OUT/setcookie.txt" "HttpOnly"
contains "session cookie sets SameSite=Lax" "$OUT/setcookie.txt" "SameSite=Lax"

curl -s -o "$OUT/loginpage-sec.html" "$BASE/login"
contains "login page carries the CSRF meta token" "$OUT/loginpage-sec.html" 'name="csrf-token" content='
contains "login form carries the CSRF hidden field" "$OUT/loginpage-sec.html" 'name="csrf_token" value='

CJAR="$JAR/csrf.jar"; rm -f "$CJAR"
curl -s -c "$CJAR" -o /dev/null "$BASE/login"
CODE=$(curl -s -b "$CJAR" -o "$OUT/csrf403.html" -w "%{http_code}" -d "username=admin&password=ChangeMe2026!" "$BASE/login")
check "login POST without CSRF token gets 403" "403" "$CODE"
contains "403 message names the problem" "$OUT/csrf403.html" "session token is missing or expired"

CODE=$(curl -s -b "$JAR/admin.jar" -o "$OUT/csrf403b.html" -w "%{http_code}" -d "action=approve&id=1" "$BASE/scoring-tables")
check "scoring POST without CSRF token gets 403" "403" "$CODE"
contains "scoring 403 is the CSRF guard" "$OUT/csrf403b.html" "session token is missing or expired"

CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: example.org" "$BASE/")
LOC=$(curl -s -o /dev/null -w "%{redirect_url}" -H "Host: example.org" "$BASE/")
check "public host is redirected with 301" "301" "$CODE"
check "redirect target forces HTTPS" "https://example.org/" "$LOC"

# brute-force lockout on an isolated probe account
if [ "$(sql "SELECT COUNT(*) FROM users WHERE username='lockprobe_e2e'")" = "0" ]; then
  curl -s -b "$JAR/admin.jar" -o /dev/null \
    -d "new_username=lockprobe_e2e&new_password=LockProbePass1&confirm=LockProbePass1&full_name=Lock+Probe&role=therapist&csrf_token=$ATOK" \
    "$BASE/register"
  check "lockout probe account created" "1" "$(sql "SELECT COUNT(*) FROM users WHERE username='lockprobe_e2e'")"
fi
PJAR="$JAR/lockprobe.jar"; rm -f "$PJAR"
PTOK=$(curl -s -c "$PJAR" "$BASE/login" | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' | head -1)
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -b "$PJAR" -c "$PJAR" -d "username=lockprobe_e2e&password=Wrong$i&csrf_token=$PTOK" "$BASE/login"
done
check "5 failed attempts recorded" "5" "$(sql "SELECT COUNT(*) FROM login_attempts WHERE username='lockprobe_e2e'")"
curl -s -b "$PJAR" -o "$OUT/lock6.html" -d "username=lockprobe_e2e&password=Wrong6&csrf_token=$PTOK" "$BASE/login"
contains "account locked after 5 failures" "$OUT/lock6.html" "Too many failed sign-in attempts"
curl -s -b "$PJAR" -o "$OUT/lock-correct.html" -d "username=lockprobe_e2e&password=LockProbePass1&csrf_token=$PTOK" "$BASE/login"
contains "correct password refused while locked" "$OUT/lock-correct.html" "Too many failed sign-in attempts"
sql "DELETE FROM login_attempts WHERE username='lockprobe_e2e'"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$PJAR" -c "$PJAR" -d "username=lockprobe_e2e&password=LockProbePass1&csrf_token=$PTOK" "$BASE/login")
check "login works again once attempts are cleared" "302" "$CODE"

if [ "$(sql "SELECT COUNT(*) FROM activity_logs WHERE action='login_blocked'")" -ge 1 ]; then
  pass "lockout events are recorded in the activity trail"
else
  fail "lockout events are recorded in the activity trail"
fi
if [ "$(sql "SELECT COUNT(*) FROM activity_logs WHERE action='csrf_blocked'")" -ge 1 ]; then
  pass "CSRF rejections are recorded in the activity trail"
else
  fail "CSRF rejections are recorded in the activity trail"
fi

# IT admin panel: lockout limits (raise, prove the effect, reset)
CODE=$(curl -s -b "$JAR/admin.jar" -o "$OUT/itadmin.html" -w "%{http_code}" "$BASE/itadmin")
check "it admin opens /itadmin" "200" "$CODE"
curl -s -b "$JAR/admin.jar" -o "$OUT/itadmin-settings.html" "$BASE/itadmin?tab=settings"
contains "lockout card is on the settings tab" "$OUT/itadmin-settings.html" "Login lockout"
contains "default account limit shown" "$OUT/itadmin-settings.html" 'name="login_user_limit" value="5"'
contains "default IP limit shown" "$OUT/itadmin-settings.html" 'name="login_ip_limit" value="60"'
contains "default lock duration shown" "$OUT/itadmin-settings.html" 'name="login_lock_minutes" value="30"'

curl -s -b "$JAR/admin.jar" -o "$OUT/itadmin-saved.html" -L \
  -d "action=save_lockout&login_user_limit=7&login_ip_limit=90&login_lock_minutes=45&csrf_token=$ATOK" \
  "$BASE/itadmin"
contains "raised account limit shown after save" "$OUT/itadmin-saved.html" 'name="login_user_limit" value="7"'
contains "raised IP limit shown after save" "$OUT/itadmin-saved.html" 'name="login_ip_limit" value="90"'
contains "raised lock duration shown after save" "$OUT/itadmin-saved.html" 'name="login_lock_minutes" value="45"'
contains "page flags that custom values are saved" "$OUT/itadmin-saved.html" "Custom values are currently saved"
contains "save confirmation flash shown" "$OUT/itadmin-saved.html" "Login lockout limits saved"
check "settings stored in the database" "90|45|7" \
  "$(sql "SELECT GROUP_CONCAT(setting_value ORDER BY setting_key SEPARATOR '|') FROM app_settings WHERE setting_key IN ('login_ip_limit','login_lock_minutes','login_user_limit')")"

# the raised account limit must actually apply: 6 failures stay under 7
sql "DELETE FROM login_attempts WHERE username='lockprobe_e2e'"
rm -f "$PJAR"
PTOK=$(curl -s -c "$PJAR" "$BASE/login" | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' | head -1)
for i in 1 2 3 4 5 6; do
  curl -s -o /dev/null -b "$PJAR" -c "$PJAR" -d "username=lockprobe_e2e&password=Wrong$i&csrf_token=$PTOK" "$BASE/login"
done
check "6 failures recorded under the raised limit" "6" "$(sql "SELECT COUNT(*) FROM login_attempts WHERE username='lockprobe_e2e'")"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$PJAR" -c "$PJAR" -d "username=lockprobe_e2e&password=LockProbePass1&csrf_token=$PTOK" "$BASE/login")
check "raised limit lets the account through after 6 failures" "302" "$CODE"

curl -s -b "$JAR/admin.jar" -o "$OUT/itadmin-reset.html" -L \
  -d "action=reset_lockout&csrf_token=$ATOK" "$BASE/itadmin"
contains "defaults restored after reset" "$OUT/itadmin-reset.html" 'name="login_user_limit" value="5"'
contains "reset confirmation flash shown" "$OUT/itadmin-reset.html" "reset to the defaults"
check "database overrides removed on reset" "0" "$(sql "SELECT COUNT(*) FROM app_settings")"

# ---------------------------------------------------------------- P1 security: headers, static blocking, bcrypt cost
echo "== security: P1 hardening =="

contains "CSP header sent on dynamic pages" "$OUT/hdr-login.txt" "Content-Security-Policy"
contains "clickjacking protection sent" "$OUT/hdr-login.txt" "X-Frame-Options: DENY"
contains "referrer policy sent" "$OUT/hdr-login.txt" "Referrer-Policy: strict-origin-when-cross-origin"
contains "permissions policy sent" "$OUT/hdr-login.txt" "Permissions-Policy:"
contains "nosniff sent from PHP too" "$OUT/hdr-login.txt" "X-Content-Type-Options: nosniff"

check "test suite blocked on the dev server" "403" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/tests/e2e.sh")"
check "sql dump blocked on the dev server" "403" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/newdeploy.sql")"
check "env example blocked on the dev server" "403" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/.env.example")"

check "new passwords use bcrypt cost 12" '$2y$12$' \
  "$(sql "SELECT LEFT(password_hash,7) FROM users WHERE username='admin2e2e'")"

# a legacy cost-10 hash is upgraded on the next successful login
LEGACY=$(php -r 'echo password_hash("LockProbePass1", PASSWORD_BCRYPT, ["cost"=>10]);')
sql "UPDATE users SET password_hash='$LEGACY' WHERE username='lockprobe_e2e'"
check "legacy cost-10 hash written" '$2y$10$' \
  "$(sql "SELECT LEFT(password_hash,7) FROM users WHERE username='lockprobe_e2e'")"
rm -f "$PJAR"
PTOK=$(curl -s -c "$PJAR" "$BASE/login" | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' | head -1)
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$PJAR" -c "$PJAR" \
  -d "username=lockprobe_e2e&password=LockProbePass1&csrf_token=$PTOK" "$BASE/login")
check "legacy-hash user logs in normally" "302" "$CODE"
check "hash upgraded to cost 12 on login" '$2y$12$' \
  "$(sql "SELECT LEFT(password_hash,7) FROM users WHERE username='lockprobe_e2e'")"

# ---------------------------------------------------------------- RBAC: tabs, roles, groups, user management
echo "== RBAC: tabs, roles, groups, user management =="

# start clean (defensive against a crashed earlier run)
sql "DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM users WHERE username IN ('e2e_boss','e2e_lifecycle'))"
sql "DELETE FROM users WHERE username IN ('e2e_boss','e2e_lifecycle')"
sql "DELETE FROM login_attempts WHERE username IN ('e2e_boss','e2e_lifecycle')"
sql "DELETE FROM user_role_groups WHERE user_id = (SELECT id FROM users WHERE username='testere2e')"
sql "DELETE FROM user_roles WHERE user_id = (SELECT id FROM users WHERE username='testere2e')"

# every tab is open to the IT administrator
for T in users roles logs settings; do
  CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR/admin.jar" "$BASE/itadmin?tab=$T")
  check "it admin opens the $T tab" "200" "$CODE"
done
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR/therapist.jar" "$BASE/itadmin?tab=users")
check "therapist blocked from user management" "403" "$CODE"

# a plain administrator lands on Users and cannot reach the it_admin-only tabs
curl -s -b "$JAR/admin.jar" -o /dev/null \
  -d "new_username=e2e_boss&new_password=BossPass!2026&confirm=BossPass!2026&full_name=E2E+Boss&role=admin&csrf_token=$ATOK" \
  "$BASE/register"
check "base-admin probe created" "1" "$(sql "SELECT COUNT(*) FROM users WHERE username='e2e_boss'")"
BJAR="$JAR/boss.jar"
login "$BJAR" e2e_boss 'BossPass!2026'
curl -s -b "$BJAR" -o "$OUT/boss-itadmin.html" "$BASE/itadmin"
contains "administrator lands on the Users tab" "$OUT/boss-itadmin.html" 'name="new_username"'
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$BJAR" "$BASE/itadmin?tab=settings")
check "administrator blocked from Settings" "403" "$CODE"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$BJAR" "$BASE/itadmin?tab=roles")
check "administrator blocked from Roles" "403" "$CODE"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$BJAR" "$BASE/itadmin?tab=logs")
check "administrator can open Activity logs" "200" "$CODE"

# custom role + role group from the Roles & groups tab
curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=create_role" \
  --data-urlencode "name=e2e_reviewer" \
  --data-urlencode "label=E2E Reviewer" \
  --data-urlencode "description=Created by the e2e suite" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/itadmin?tab=roles"
check "custom role created" "1" "$(sql "SELECT COUNT(*) FROM roles WHERE name='e2e_reviewer'")"

curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=create_group" \
  --data-urlencode "name=E2E Panel" \
  --data-urlencode "description=Group role bundle" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/itadmin?tab=roles"
check "role group created" "1" "$(sql "SELECT COUNT(*) FROM role_groups WHERE name='E2E Panel'")"

curl -s -b "$JAR/admin.jar" -o "$OUT/roles-tab.html" "$BASE/itadmin?tab=roles"
contains "roles tab lists the custom role" "$OUT/roles-tab.html" "E2E Reviewer"
contains "roles tab lists the group" "$OUT/roles-tab.html" "E2E Panel"

GID=$(sql "SELECT id FROM role_groups WHERE name='E2E Panel'")
RID=$(sql "SELECT id FROM roles WHERE name='e2e_reviewer'")
ARID=$(sql "SELECT id FROM roles WHERE name='admin'")
curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=set_group_roles" \
  --data-urlencode "group_id=$GID" \
  --data-urlencode "roles[]=$RID" \
  --data-urlencode "roles[]=$ARID" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/itadmin?tab=roles"
check "group carries both roles" "2" "$(sql "SELECT COUNT(*) FROM role_group_roles WHERE group_id=$GID")"

# assign the group to the therapist — the group's admin role unlocks admin pages
TID=$(sql "SELECT id FROM users WHERE username='testere2e'")
TFULL=$(sql "SELECT full_name FROM users WHERE username='testere2e'")
curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=update_user" \
  --data-urlencode "user_id=$TID" \
  --data-urlencode "full_name=$TFULL" \
  --data-urlencode "role=therapist" \
  --data-urlencode "is_active=1" \
  --data-urlencode "groups[]=$GID" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/itadmin?tab=users"
check "group assigned to the therapist" "1" "$(sql "SELECT COUNT(*) FROM user_role_groups WHERE user_id=$TID AND group_id=$GID")"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR/therapist.jar" "$BASE/scoring-tables")
check "group-granted admin role unlocks /scoring-tables for the therapist" "200" "$CODE"

# remove the group → the access goes with it
curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=update_user" \
  --data-urlencode "user_id=$TID" \
  --data-urlencode "full_name=$TFULL" \
  --data-urlencode "role=therapist" \
  --data-urlencode "is_active=1" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/itadmin?tab=users"
check "group unassigned" "0" "$(sql "SELECT COUNT(*) FROM user_role_groups WHERE user_id=$TID")"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR/therapist.jar" "$BASE/scoring-tables")
check "access removed with the group" "403" "$CODE"

# full lifecycle: create → password reset → sign-in → deactivate → sign-in refused
curl -s -b "$JAR/admin.jar" -o /dev/null \
  -d "action=create_user&new_username=e2e_lifecycle&new_password=LifePass!2026&confirm=LifePass!2026&full_name=E2E+Lifecycle&email=&role=therapist&csrf_token=$ATOK" \
  "$BASE/itadmin?tab=users"
check "user created from the Users tab" "1" "$(sql "SELECT COUNT(*) FROM users WHERE username='e2e_lifecycle'")"

LID=$(sql "SELECT id FROM users WHERE username='e2e_lifecycle'")
curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=update_user" \
  --data-urlencode "user_id=$LID" \
  --data-urlencode "full_name=E2E Lifecycle" \
  --data-urlencode "role=therapist" \
  --data-urlencode "is_active=1" \
  --data-urlencode "new_password=NewLifePass1" \
  --data-urlencode "confirm=NewLifePass1" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/itadmin?tab=users"
LJAR="$JAR/lifecycle.jar"
login "$LJAR" e2e_lifecycle 'NewLifePass1'
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$LJAR" "$BASE/")
check "password reset from the Users tab works" "200" "$CODE"

curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=update_user" \
  --data-urlencode "user_id=$LID" \
  --data-urlencode "full_name=E2E Lifecycle" \
  --data-urlencode "role=therapist" \
  --data-urlencode "csrf_token=$ATOK" \
  "$BASE/itadmin?tab=users"
check "account disabled" "0" "$(sql "SELECT is_active FROM users WHERE username='e2e_lifecycle'")"
LJAR2="$JAR/lifecycle2.jar"; rm -f "$LJAR2"
LTOK=$(curl -s -c "$LJAR2" "$BASE/login" | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p' | head -1)
curl -s -b "$LJAR2" -c "$LJAR2" -o "$OUT/lifecycle-denied.html" \
  --data-urlencode "username=e2e_lifecycle" \
  --data-urlencode "password=NewLifePass1" \
  --data-urlencode "csrf_token=$LTOK" \
  "$BASE/login"
contains "disabled account cannot sign in" "$OUT/lifecycle-denied.html" "do not match"

# a plain administrator cannot change their own base role
BID=$(sql "SELECT id FROM users WHERE username='e2e_boss'")
BTOK=$(token "$BJAR" "$BASE/itadmin")
curl -s -b "$BJAR" -o "$OUT/boss-self.html" -L \
  --data-urlencode "action=update_user" \
  --data-urlencode "user_id=$BID" \
  --data-urlencode "full_name=E2E Boss" \
  --data-urlencode "role=therapist" \
  --data-urlencode "is_active=1" \
  --data-urlencode "csrf_token=$BTOK" \
  "$BASE/itadmin?tab=users"
contains "self role change refused" "$OUT/boss-self.html" "own base role"
check "boss base role unchanged" "admin" "$(sql "SELECT role FROM users WHERE username='e2e_boss'")"

# management POSTs still require the CSRF token
CODE=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR/admin.jar" \
  -d "action=create_role&name=e2e_noct&label=NoToken" \
  "$BASE/itadmin?tab=roles")
check "management POST without CSRF token gets 403" "403" "$CODE"

# RBAC cleanup
curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=delete_group" --data-urlencode "group_id=$GID" \
  --data-urlencode "csrf_token=$ATOK" "$BASE/itadmin?tab=roles"
check "group deleted" "0" "$(sql "SELECT COUNT(*) FROM role_groups WHERE name='E2E Panel'")"
curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=delete_role" --data-urlencode "role_id=$RID" \
  --data-urlencode "csrf_token=$ATOK" "$BASE/itadmin?tab=roles"
check "custom role deleted" "0" "$(sql "SELECT COUNT(*) FROM roles WHERE name='e2e_reviewer'")"
sql "DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM users WHERE username IN ('e2e_boss','e2e_lifecycle'))"
sql "DELETE FROM users WHERE username IN ('e2e_boss','e2e_lifecycle')"
sql "DELETE FROM login_attempts WHERE username IN ('e2e_boss','e2e_lifecycle')"

# ---------------------------------------------------------------- API (mobile JSON)
echo "== API: JSON endpoints for the mobile app =="
jget() { # jget <file> <dot.path> — read a value out of a JSON file
  php -r '$j=json_decode(file_get_contents($argv[1]),true); $v=$j;
    foreach(explode(".",$argv[2]) as $k){ if(!is_array($v)||!array_key_exists($k,$v)){$v=null;break;} $v=$v[$k];}
    echo is_scalar($v)?(string)$v:json_encode($v);' "$1" "$2"
}

curl -s -o "$OUT/api-discovery.json" "$BASE/api/v1"
contains "API discovery lists the login endpoint" "$OUT/api-discovery.json" "auth/login"

CODE=$(curl -s -o "$OUT/api-me-notoken.json" -w "%{http_code}" "$BASE/api/v1/auth/me")
check "API request without a token gets 401" "401" "$CODE"
contains "missing-token error code" "$OUT/api-me-notoken.json" "missing_token"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer deadbeef" "$BASE/api/v1/auth/me")
check "API request with a garbage token gets 401" "401" "$CODE"

CODE=$(curl -s -o "$OUT/api-login-bad.json" -w "%{http_code}" -X POST \
  -H "Content-Type: application/json" -d '{"username":"admin2e2e","password":"nope"}' \
  "$BASE/api/v1/auth/login")
check "API login with wrong password gets 401" "401" "$CODE"
contains "invalid-credentials error code" "$OUT/api-login-bad.json" "invalid_credentials"

CODE=$(curl -s -o "$OUT/api-login.json" -w "%{http_code}" -X POST \
  -H "Content-Type: application/json" \
  -d '{"username":"admin2e2e","password":"E2eAdminPass","device":"e2e-suite"}' \
  "$BASE/api/v1/auth/login")
check "API login succeeds" "200" "$CODE"
ATOKEN=$(jget "$OUT/api-login.json" data.token)
check "API login returns a 64-char token" "64" "$(printf %s "$ATOKEN" | wc -c)"
check "API login returns the user" "admin2e2e" "$(jget "$OUT/api-login.json" data.user.username)"
contains "effective roles included in login" "$OUT/api-login.json" "it_admin"

CODE=$(curl -s -o "$OUT/api-me.json" -w "%{http_code}" -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/auth/me")
check "auth/me works with the token" "200" "$CODE"
check "auth/me returns the user" "admin2e2e" "$(jget "$OUT/api-me.json" data.user.username)"
if curl -s -D - -o /dev/null -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/auth/me" | grep -qi "^set-cookie:"; then
  fail "API responses never set a session cookie"
else
  pass "API responses never set a session cookie"
fi

CODE=$(curl -s -o "$OUT/api-meta.json" -w "%{http_code}" -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/meta/form")
check "meta/form served" "200" "$CODE"
check "meta/form has 6 subscales" "6" \
  "$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo count($j["data"]["subscales"]);' "$OUT/api-meta.json")"
check "RB subscale carries 13 items" "13" \
  "$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["data"]["subscales"][0]["item_count"];' "$OUT/api-meta.json")"

# build a full rating form (every item = 2 -> sum 65 -> Autism Index 108)
php -r '
require "includes/rating_form.php";
$post = [
  "individual_name"=>"E2E-API-Record","school"=>"API School","rater_name"=>"E2E Rater",
  "rater_title"=>"Teacher","date_of_birth"=>"2018-05-04","age"=>"7","gender"=>"Male",
  "grade"=>"2","examiner_name"=>"E2E Examiner","examiner_title"=>"Psychologist",
  "date_of_rating"=>"2026-11-21","rater_known_for"=>"6 months","is_mute"=>"no",
];
foreach (gars_subscale_defs() as $s) { foreach ($s["questions"] as $i => $q) { $post[$s["id"]."_item_".$i] = 2; } }
file_put_contents($argv[1], json_encode($post));
' "$OUT/api-payload.json"

# the expected Autism Index comes from the scoring engine itself, so the
# assertion holds whatever table revision is active during the run
EXPECT_AI=$(php -r '
require "config.php"; require "includes/db.php"; require "includes/scoring.php";
$p = json_decode(file_get_contents($argv[1]), true);
$c = gars_compute_scores($p, false);
echo $c["autism_index"];' "$OUT/api-payload.json")
CODE=$(curl -s -o "$OUT/api-create.json" -w "%{http_code}" -X POST \
  -H "Authorization: Bearer $ATOKEN" -H "Content-Type: application/json" \
  --data-binary @"$OUT/api-payload.json" "$BASE/api/v1/assessments")
check "assessment created through the API" "201" "$CODE"
check "server-side scoring matches the engine" "$EXPECT_AI" "$(jget "$OUT/api-create.json" data.report.autism_index)"
check "all-items-2 form sums to 65 scaled" "65" "$(jget "$OUT/api-create.json" data.report.sum_scaled)"
check "server-side scoring returns Level 3" "Level 3" "$(jget "$OUT/api-create.json" data.report.severity)"
AID=$(jget "$OUT/api-create.json" data.id)

CODE=$(curl -s -o "$OUT/api-create-missing.json" -w "%{http_code}" -X POST \
  -H "Authorization: Bearer $ATOKEN" -H "Content-Type: application/json" -d '{}' \
  "$BASE/api/v1/assessments")
check "empty form rejected with 422" "422" "$CODE"
contains "validation error code" "$OUT/api-create-missing.json" "validation"
contains "missing field labels listed" "$OUT/api-create-missing.json" "Individual's Name"

CODE=$(curl -s -o "$OUT/api-list.json" -w "%{http_code}" -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/assessments?per_page=100")
check "records list served" "200" "$CODE"
check "admin list scope is all" "all" "$(jget "$OUT/api-list.json" data.scope)"
contains "list carries the new record" "$OUT/api-list.json" "E2E-API-Record"

CODE=$(curl -s -o "$OUT/api-detail.json" -w "%{http_code}" -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/assessments/$AID")
check "record detail served" "200" "$CODE"
check "detail carries the Autism Index" "$EXPECT_AI" "$(jget "$OUT/api-detail.json" data.record.scores.autism_index)"

# PUT recalculates an existing record through the same engine + store as the web edit.
# The expected values come from the engine itself (whatever scoring revision is
# active during the run): the report carries the raw value (number or null) and
# the NOT NULL column stores its int cast (null -> 0).
php -r '
$p = json_decode(file_get_contents($argv[1]), true);
$p["individual_name"] = "E2E-API-Record-Edited";
$p["school"] = "API School Updated";
foreach (array_keys($p) as $k) { if (str_contains($k, "_item_")) $p[$k] = 3; }
file_put_contents($argv[1], json_encode($p));
' "$OUT/api-payload.json"
AI2=$(php -r '
require "config.php"; require "includes/db.php"; require "includes/scoring.php";
$p = json_decode(file_get_contents($argv[1]), true);
$c = gars_compute_scores($p, false);
echo json_encode($c["autism_index"]), " ", (int) $c["autism_index"];' "$OUT/api-payload.json")
EXPECT_AI2=${AI2%% *}
EXPECT_AI2_DB=${AI2##* }
CODE=$(curl -s -o "$OUT/api-update.json" -w "%{http_code}" -X PUT \
  -H "Authorization: Bearer $ATOKEN" -H "Content-Type: application/json" \
  --data-binary @"$OUT/api-payload.json" "$BASE/api/v1/assessments/$AID")
check "record updated through the API" "200" "$CODE"
check "PUT re-scores with the server engine" "$EXPECT_AI2" "$(jget "$OUT/api-update.json" data.report.autism_index)"
check "PUT returns the updated record" "E2E-API-Record-Edited" "$(jget "$OUT/api-update.json" data.record.individual_name)"
CODE=$(curl -s -o "$OUT/api-detail2.json" -w "%{http_code}" -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/assessments/$AID")
check "edited record reads back with the new score" "$EXPECT_AI2_DB" "$(jget "$OUT/api-detail2.json" data.record.scores.autism_index)"
check "PUT stores the new item marks (RB raw = 13 x 3)" "39" "$(jget "$OUT/api-detail2.json" data.record.scores.raw.RB)"
contains "edited name stored" "$OUT/api-detail2.json" "E2E-API-Record-Edited"

CODE=$(curl -s -o "$OUT/api-update-missing.json" -w "%{http_code}" -X PUT \
  -H "Authorization: Bearer $ATOKEN" -H "Content-Type: application/json" -d '{}' \
  "$BASE/api/v1/assessments/$AID")
check "empty PUT rejected with 422" "422" "$CODE"
contains "PUT validation error code" "$OUT/api-update-missing.json" "validation"
CODE=$(curl -s -o "$OUT/api-detail3.json" -w "%{http_code}" -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/assessments/$AID")
check "failed PUT leaves the record untouched" "$EXPECT_AI2_DB" "$(jget "$OUT/api-detail3.json" data.record.scores.autism_index)"

CODE=$(curl -s -o /dev/null -w "%{http_code}" -X PUT \
  -H "Authorization: Bearer $ATOKEN" -H "Content-Type: application/json" -d '{}' \
  "$BASE/api/v1/assessments/99999999")
check "PUT on a missing record gets 404" "404" "$CODE"

CODE=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/assessments/$AID")
check "DELETE on a read endpoint gets 405" "405" "$CODE"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/nope")
check "unknown API route gets 404" "404" "$CODE"

# therapist: own records only
curl -s -o "$OUT/api-tlogin.json" -X POST -H "Content-Type: application/json" \
  -d '{"username":"testere2e","password":"E2eTherPass"}' "$BASE/api/v1/auth/login"
TTOKEN=$(jget "$OUT/api-tlogin.json" data.token)
cp "$OUT/api-payload.json" "$OUT/api-payload-t.json"
php -r '$p=json_decode(file_get_contents($argv[1]),true); $p["individual_name"]="E2E-API-Ther"; file_put_contents($argv[1], json_encode($p));' "$OUT/api-payload-t.json"
CODE=$(curl -s -o "$OUT/api-tcreate.json" -w "%{http_code}" -X POST \
  -H "Authorization: Bearer $TTOKEN" -H "Content-Type: application/json" \
  --data-binary @"$OUT/api-payload-t.json" "$BASE/api/v1/assessments")
check "therapist can create a record" "201" "$CODE"
TID=$(jget "$OUT/api-tcreate.json" data.id)
CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TTOKEN" "$BASE/api/v1/assessments/$AID")
check "therapist cannot read someone else's record" "404" "$CODE"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TTOKEN" "$BASE/api/v1/assessments/$TID")
check "therapist can read their own record" "200" "$CODE"
curl -s -o "$OUT/api-tlist.json" -H "Authorization: Bearer $TTOKEN" "$BASE/api/v1/assessments"
check "therapist list scope is own" "own" "$(jget "$OUT/api-tlist.json" data.scope)"

CODE=$(curl -s -o /dev/null -w "%{http_code}" -X PUT -H "Authorization: Bearer $TTOKEN" -H "Content-Type: application/json" \
  --data-binary @"$OUT/api-payload-t.json" "$BASE/api/v1/assessments/$AID")
check "therapist cannot update someone else's record" "404" "$CODE"
php -r '$p=json_decode(file_get_contents($argv[1]),true); $p["school"]="Own School Updated"; file_put_contents($argv[1], json_encode($p));' "$OUT/api-payload-t.json"
CODE=$(curl -s -o "$OUT/api-tupdate.json" -w "%{http_code}" -X PUT -H "Authorization: Bearer $TTOKEN" -H "Content-Type: application/json" \
  --data-binary @"$OUT/api-payload-t.json" "$BASE/api/v1/assessments/$TID")
check "therapist can update their own record" "200" "$CODE"
check "therapist's own update stored" "Own School Updated" "$(jget "$OUT/api-tupdate.json" data.record.school)"

# logout kills the token
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/auth/logout")
check "API logout succeeds" "200" "$CODE"
CODE=$(curl -s -o "$OUT/api-dead.json" -w "%{http_code}" -H "Authorization: Bearer $ATOKEN" "$BASE/api/v1/auth/me")
check "revoked token rejected" "401" "$CODE"
contains "revoked-token error code" "$OUT/api-dead.json" "token_revoked"

# lockout applies to API logins too (shared counters with the web form)
if [ "$(sql "SELECT COUNT(*) FROM users WHERE username='apiprobe_e2e'")" = "0" ]; then
  curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o /dev/null \
    -d "new_username=apiprobe_e2e&new_password=ApiProbePass1&confirm=ApiProbePass1&full_name=E2E+API+Probe&role=therapist&csrf_token=$ATOK" \
    "$BASE/register"
fi
N401=0; LAST=""
for i in 1 2 3 4 5 6; do
  C=$(curl -s -o "$OUT/api-lock.json" -w "%{http_code}" -X POST -H "Content-Type: application/json" \
    -d "{\"username\":\"apiprobe_e2e\",\"password\":\"Wrong$i\"}" "$BASE/api/v1/auth/login")
  [ "$C" = "401" ] && N401=$((N401+1))
  LAST=$C
done
check "API login attempts 1-5 rejected with 401" "5" "$N401"
check "API login attempt 6 is locked out" "423" "$LAST"
contains "lockout error code" "$OUT/api-lock.json" "locked"

# deactivation kills live tokens
if [ "$(sql "SELECT COUNT(*) FROM users WHERE username='apioff_e2e'")" = "0" ]; then
  curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o /dev/null \
    -d "new_username=apioff_e2e&new_password=ApiOffPass1&confirm=ApiOffPass1&full_name=E2E+API+Off&role=therapist&csrf_token=$ATOK" \
    "$BASE/register"
fi
curl -s -o "$OUT/api-offlogin.json" -X POST -H "Content-Type: application/json" \
  -d '{"username":"apioff_e2e","password":"ApiOffPass1"}' "$BASE/api/v1/auth/login"
OTOKEN=$(jget "$OUT/api-offlogin.json" data.token)
CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $OTOKEN" "$BASE/api/v1/auth/me")
check "token works while the account is active" "200" "$CODE"
OID=$(sql "SELECT id FROM users WHERE username='apioff_e2e'")
ATOK2=$(token "$JAR/admin.jar" "$BASE/itadmin?tab=users")
curl -s -b "$JAR/admin.jar" -o /dev/null \
  --data-urlencode "action=update_user" \
  --data-urlencode "user_id=$OID" \
  --data-urlencode "full_name=E2E API Off" \
  --data-urlencode "role=therapist" \
  --data-urlencode "csrf_token=$ATOK2" \
  "$BASE/itadmin?tab=users"
CODE=$(curl -s -o "$OUT/api-offdead.json" -w "%{http_code}" -H "Authorization: Bearer $OTOKEN" "$BASE/api/v1/auth/me")
check "deactivation kills the live token" "401" "$CODE"
contains "deactivated token is revoked" "$OUT/api-offdead.json" "token_revoked"

# ---------------------------------------------------------------- cleanup
sql "DELETE FROM scoring_revisions WHERE change_note LIKE '%E2E%'";
sql "DELETE FROM assessments WHERE individual_name LIKE 'E2E-%'";
sql "DELETE FROM api_tokens";
sql "DELETE FROM users WHERE username IN ('apiprobe_e2e','apioff_e2e')";
sql "DELETE FROM login_attempts WHERE username IN ('apiprobe_e2e','apioff_e2e')";
sql "DELETE FROM activity_logs WHERE page='api' OR details LIKE '%apiprobe_e2e%' OR details LIKE '%apioff_e2e%'";
LID=$(sql "SELECT id FROM users WHERE username='lockprobe_e2e'")
if [ -n "$LID" ]; then
  sql "DELETE FROM user_sessions WHERE user_id=$LID"
fi
sql "DELETE FROM users WHERE username='lockprobe_e2e'";
sql "DELETE FROM login_attempts";
sql "DELETE FROM app_settings";
sql "DELETE FROM activity_logs WHERE details LIKE '%lockprobe_e2e%' OR action IN ('csrf_blocked','login_blocked')";
sql "DELETE FROM activity_logs WHERE username IN ('e2e_boss','e2e_lifecycle') OR details LIKE '%e2e_boss%' OR details LIKE '%e2e_lifecycle%' OR details LIKE '%E2E Panel%' OR details LIKE '%e2e_reviewer%' OR action IN ('role_create','role_update','role_delete','group_create','group_update','group_delete','group_roles','user_update')";
sql "DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM users WHERE username IN ('admin2e2e','testere2e'))";
sql "DELETE FROM activity_logs WHERE username IN ('admin2e2e','testere2e')";
sql "DELETE FROM users WHERE username IN ('admin2e2e','testere2e')";

kill $SRV 2>/dev/null
pkill -f "php -S 127.0.0.1:8123" 2>/dev/null

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
