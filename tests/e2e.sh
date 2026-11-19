#!/usr/bin/env bash
# tests/e2e.sh — end-to-end HTTP tests for the Maisha Bora app.
# Run from the project root:  bash tests/e2e.sh
# Covers: wording/title/age JS, therapist form scoring, scoring-table
# edit + four-eyes approval, global effect of an approved revision,
# validation failures, role guards.
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
sql "DELETE FROM scoring_revisions WHERE change_note LIKE 'E2E%'";
sql "DELETE FROM assessments WHERE individual_name LIKE 'E2E-%'";

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
login() { # login <jar> <user> <pass>
  rm -f "$1"
  curl -s -c "$1" -b "$1" -o /dev/null -d "username=$2&password=$3" "$BASE/login"
}
assess_post() { # assess_post <jar> <name>  -> body of the scoring report
  local jar=$1 name=$2 data=""
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
  curl -s -c "$jar" -b "$jar" -d "$data" "$BASE/score"
}

echo "== setup: test accounts =="
login "$JAR/admin.jar" admin 'ChangeMe2026!'
if [ "$(sql "SELECT COUNT(*) FROM users WHERE username='admin2e2e'")" = "0" ]; then
  curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/register-admin2.html" \
    -d "new_username=admin2e2e&new_password=E2eAdminPass&confirm=E2eAdminPass&full_name=E2E+Second+Admin&role=it_admin" \
    "$BASE/register"
  check "register second it_admin" "1" "$(sql "SELECT COUNT(*) FROM users WHERE username='admin2e2e' AND role='it_admin'")"
fi
if [ "$(sql "SELECT COUNT(*) FROM users WHERE username='testere2e'")" = "0" ]; then
  curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/register-therapist.html" \
    -d "new_username=testere2e&new_password=E2eTherPass&confirm=E2eTherPass&full_name=E2E+Therapist&role=therapist" \
    "$BASE/register"
  check "register therapist" "1" "$(sql "SELECT COUNT(*) FROM users WHERE username='testere2e' AND role='therapist'")"
fi

# ---------------------------------------------------------------- auth & guards
login "$JAR/therapist.jar" testere2e 'E2eTherPass'
login "$JAR/admin.jar" admin 'ChangeMe2026!'
login "$JAR/admin2.jar" admin2e2e 'E2eAdminPass'

CODE=$(curl -s -c "$JAR/therapist.jar" -b "$JAR/therapist.jar" -o "$OUT/ther-scoring.html" -w "%{http_code}" "$BASE/scoring-tables")
check "therapist blocked from /scoring-tables" "403" "$CODE"
contains "therapist sees the 403 message" "$OUT/ther-scoring.html" "403"

CODE=$(curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/adm-scoring.html" -w "%{http_code}" "$BASE/scoring-tables")
check "admin can open /scoring-tables" "200" "$CODE"
contains "admin page lists Table A editor" "$OUT/adm-scoring.html" "Table A — raw subscale score"
contains "admin page shows built-in baseline" "$OUT/adm-scoring.html" "Built-in published table"
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
  "$BASE/scoring-tables"
contains "proposal saved as pending" "$OUT/submit1.html" "saved as revision"
REV1=$(sql "SELECT id FROM scoring_revisions WHERE change_note LIKE 'E2E-B%' AND status='pending'")
[ -n "$REV1" ] && pass "pending revision exists (#$REV1)" || fail "pending revision missing"

# ---------------------------------------------------------------- four-eyes: own approval blocked
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/own-approve.html" -L \
  -d "action=approve&id=$REV1" "$BASE/scoring-tables"
contains "own approval is refused" "$OUT/own-approve.html" "your own change"
check "revision still pending after self-approve attempt" "pending" "$(sql "SELECT status FROM scoring_revisions WHERE id=$REV1")"

# ---------------------------------------------------------------- validation failure
BAD='[["1","<1",[5,2],null,null,null,null,null]]'
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/bad-table.html" \
  --data-urlencode "action=submit" \
  --data-urlencode "kind=table_a" \
  --data-urlencode "note=E2E-bad" \
  --data-urlencode "payload=$BAD" \
  "$BASE/scoring-tables"
contains "inverted range rejected with a message" "$OUT/bad-table.html" "low ≤ high"
check "invalid proposal not stored" "0" "$(sql "SELECT COUNT(*) FROM scoring_revisions WHERE change_note LIKE 'E2E-bad%'")"

# ---------------------------------------------------------------- second admin approves
curl -s -c "$JAR/admin2.jar" -b "$JAR/admin2.jar" -o "$OUT/admin2-page.html" "$BASE/scoring-tables"
contains "second admin sees the pending change" "$OUT/admin2-page.html" "E2E-B change sum65 percentile to 88"
curl -s -c "$JAR/admin2.jar" -b "$JAR/admin2.jar" -o "$OUT/approve.html" -L \
  -d "action=approve&id=$REV1" "$BASE/scoring-tables"
contains "approval confirmed" "$OUT/approve.html" "approved — it is now used by every scoring run"
check "revision approved" "approved" "$(sql "SELECT status FROM scoring_revisions WHERE id=$REV1")"
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/active.html" "$BASE/scoring-tables"
contains "Table B now shows an active revision" "$OUT/active.html" "Revision #$REV1"

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
  "$BASE/scoring-tables"
REV2=$(sql "SELECT id FROM scoring_revisions WHERE change_note LIKE 'E2E-I%' AND status='pending'")
[ -n "$REV2" ] && pass "interpretation proposal pending (#$REV2)" || fail "interpretation proposal missing"
curl -s -c "$JAR/admin2.jar" -b "$JAR/admin2.jar" -o "$OUT/reject.html" -L \
  -d "action=reject&id=$REV2" "$BASE/scoring-tables"
contains "rejection confirmed" "$OUT/reject.html" "rejected"
check "revision status rejected" "rejected" "$(sql "SELECT status FROM scoring_revisions WHERE id=$REV2")"
curl -s -c "$JAR/admin.jar" -b "$JAR/admin.jar" -o "$OUT/active2.html" "$BASE/scoring-tables"
contains "interpretation still built-in after rejection" "$OUT/active2.html" "Built-in published table"

# ---------------------------------------------------------------- history & records
curl -s -c "$JAR/therapist.jar" -b "$JAR/therapist.jar" -o "$OUT/records.html" "$BASE/records"
contains "records list shows the new assessment" "$OUT/records.html" "E2E-After"
curl -s -c "$JAR/therapist.jar" -b "$JAR/therapist.jar" -o "$OUT/record.html" "$BASE/assessment?id=$(sql "SELECT id FROM assessments WHERE individual_name='E2E-After'")"
contains "stored record shows revised percentile 88" "$OUT/record.html" '<span class="pfig-n">88</span>'

# ---------------------------------------------------------------- cleanup
sql "DELETE FROM scoring_revisions WHERE change_note LIKE 'E2E%'";
sql "DELETE FROM assessments WHERE individual_name LIKE 'E2E-%'";
sql "DELETE FROM user_sessions WHERE user_id IN (SELECT id FROM users WHERE username IN ('admin2e2e','testere2e'))";
sql "DELETE FROM activity_logs WHERE username IN ('admin2e2e','testere2e')";
sql "DELETE FROM users WHERE username IN ('admin2e2e','testere2e')";

kill $SRV 2>/dev/null
pkill -f "php -S 127.0.0.1:8123" 2>/dev/null

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
