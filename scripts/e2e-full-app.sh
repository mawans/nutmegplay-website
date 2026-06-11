#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
SB_URL="${SB_URL:-http://127.0.0.1:54321}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

tmp_dir="/tmp/nutmeg-e2e"
mkdir -p "$tmp_dir"

say() { printf '%s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'PASS: %s\n' "$*"; }

assert_eq() {
    local expected="$1"
    local actual="$2"
    local msg="$3"
    if [[ "$expected" != "$actual" ]]; then
        fail "$msg (expected: $expected, actual: $actual)"
    fi
    pass "$msg"
}

assert_nonempty() {
    local value="$1"
    local msg="$2"
    [[ -n "$value" ]] || fail "$msg"
    pass "$msg"
}

csrf_from_file() {
    local file="$1"
    rg -o 'name="_csrf" value="[^"]+"' "$file" | head -n1 | sed -E 's/.*value="([^"]+)"/\1/'
}

get_code() {
    local cookie="$1"
    local path="$2"
    local out_file="$3"
    curl -sS -b "$cookie" -c "$cookie" -o "$out_file" -w '%{http_code}' "${BASE_URL}${path}"
}

post_form() {
    local cookie="$1"
    local path="$2"
    local body_out="$3"
    local headers_out="$4"
    shift 4
    curl -sS -b "$cookie" -c "$cookie" -D "$headers_out" -o "$body_out" -w '%{http_code}' \
        -X POST "${BASE_URL}${path}" "$@"
}

redirect_from_headers() {
    local headers_file="$1"
    awk -F': ' 'tolower($1)=="location"{print $2}' "$headers_file" | tr -d '\r' | tail -n1
}

json_count() {
    php -r '$j=json_decode(stream_get_contents(STDIN), true); echo is_array($j) ? count($j) : 0;'
}

json_first_value() {
    local key="$1"
    php -r '$k=$argv[1]; $j=json_decode(stream_get_contents(STDIN), true); echo $j[0][$k] ?? "";' "$key"
}

account_by_email() {
    local email="$1"
    curl -sS --get "${SB_URL}/rest/v1/accounts" \
        -H "apikey: ${SB_KEY}" \
        -H "Authorization: Bearer ${SB_KEY}" \
        --data-urlencode "select=id,uid,email,fname,country,position" \
        --data-urlencode "email=eq.${email}"
}

account_by_uid() {
    local uid="$1"
    curl -sS --get "${SB_URL}/rest/v1/accounts" \
        -H "apikey: ${SB_KEY}" \
        -H "Authorization: Bearer ${SB_KEY}" \
        --data-urlencode "select=id,uid,email,fname,country,position,club_assign,role" \
        --data-urlencode "uid=eq.${uid}"
}

invites_between() {
    local from_uid="$1"
    local to_uid="$2"
    curl -sS --get "${SB_URL}/rest/v1/invites" \
        -H "apikey: ${SB_KEY}" \
        -H "Authorization: Bearer ${SB_KEY}" \
        --data-urlencode "select=id,sent_by,sent_to,status" \
        --data-urlencode "sent_by=eq.${from_uid}" \
        --data-urlencode "sent_to=eq.${to_uid}" \
        --data-urlencode "order=id.desc"
}

match_between() {
    local challenger_uid="$1"
    local opponent_uid="$2"
    curl -sS --get "${SB_URL}/rest/v1/matchs" \
        -H "apikey: ${SB_KEY}" \
        -H "Authorization: Bearer ${SB_KEY}" \
        --data-urlencode "select=id,challanger,opponent,challange_status,challenger_id,opponent_id,video_url,video_status" \
        --data-urlencode "challenger_id=eq.${challenger_uid}" \
        --data-urlencode "opponent_id=eq.${opponent_uid}" \
        --data-urlencode "order=id.desc" \
        --data-urlencode "limit=1"
}

# Read app credential source of truth
SB_KEY="$(php -r '$c=require "app/Config/supabase.php"; echo $c["key"] ?? "";' 2>/dev/null | tr -d '\r\n')"
assert_nonempty "$SB_KEY" "Supabase key is present in app/Config/supabase.php"

# Supabase health
health_code="$(curl -sS -o "${tmp_dir}/sb_health.json" -w '%{http_code}' "${SB_URL}/auth/v1/health" -H "apikey: ${SB_KEY}" -H "Authorization: Bearer ${SB_KEY}")"
assert_eq "200" "$health_code" "Supabase auth health endpoint is reachable"

# Public route checks
anon_cookie="${tmp_dir}/anon.cookies"
rm -f "$anon_cookie"
for p in / /landing /login /login-alt /register; do
    code="$(get_code "$anon_cookie" "$p" "${tmp_dir}/anon_${p//\//_}.html")"
    assert_eq "200" "$code" "Public route ${p} renders"
done

# Protected route redirect checks (anonymous)
for p in /dashboard /matchmaking /fixtures /challenges /instructor /match-history /players /teams /video-upload /video/upload; do
    headers_file="${tmp_dir}/anon_${p//\//_}.headers"
    code="$(curl -sS -D "$headers_file" -o /dev/null -w '%{http_code}' "${BASE_URL}${p}")"
    [[ "$code" == "302" || "$code" == "303" ]] || fail "Anonymous access to ${p} should redirect"
    loc="$(redirect_from_headers "$headers_file")"
    [[ "$loc" == "/login" ]] || fail "Anonymous redirect for ${p} should go to /login (got ${loc})"
    pass "Anonymous route guard works for ${p}"
done

register_user() {
    local cookie="$1"
    local name="$2"
    local email="$3"
    local country="$4"
    local position="$5"
    local role="$6"
    local page_file="${tmp_dir}/${name}_register.html"
    local body_file="${tmp_dir}/${name}_register_post.html"
    local headers_file="${tmp_dir}/${name}_register_post.headers"

    code="$(get_code "$cookie" "/register" "$page_file")"
    assert_eq "200" "$code" "Register page loads for ${name}"
    csrf="$(csrf_from_file "$page_file")"
    assert_nonempty "$csrf" "CSRF token available on register page for ${name}"

    post_code="$(post_form "$cookie" "/register" "$body_file" "$headers_file" \
        --data-urlencode "_csrf=${csrf}" \
        --data-urlencode "fname=${name}" \
        --data-urlencode "email=${email}" \
        --data-urlencode "password=Password123!" \
        --data-urlencode "country=${country}" \
        --data-urlencode "position=${position}" \
        --data-urlencode "role=${role}")"
    [[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Register should redirect for ${name}"
    reg_loc="$(redirect_from_headers "$headers_file")"
    [[ "$reg_loc" == "/dashboard" ]] || fail "Register redirect for ${name} should be /dashboard (got ${reg_loc})"
    pass "Register flow works for ${name}"
}

lookup_uid() {
    local email="$1"
    local json
    json="$(account_by_email "$email")"
    uid="$(printf '%s' "$json" | json_first_value uid)"
    [[ -n "$uid" ]] || fail "Account row exists for ${email}"
    pass "Account row exists for ${email}" >&2
    printf '%s' "$uid"
}

u1_cookie="${tmp_dir}/u1.cookies"
u2_cookie="${tmp_dir}/u2.cookies"
u3_cookie="${tmp_dir}/u3.cookies"
rm -f "$u1_cookie" "$u2_cookie" "$u3_cookie"

ts="$(date +%s)"
email1="e2euser1${ts}@nutmeg.local"
email2="e2euser2${ts}@nutmeg.local"
email3="e2euser3${ts}@nutmeg.local"

register_user "$u1_cookie" "E2E User One" "$email1" "PK" "MID" "instructor"
register_user "$u2_cookie" "E2E User Two" "$email2" "PK" "DEF" "player"
register_user "$u3_cookie" "E2E User Three" "$email3" "PK" "FWD" "player"

uid1="$(lookup_uid "$email1")"
uid2="$(lookup_uid "$email2")"
uid3="$(lookup_uid "$email3")"

# Logged-in route checks for user1
for p in /dashboard /matchmaking /fixtures /challenges /instructor /match-history /players /teams /video-upload /video/upload; do
    code="$(get_code "$u1_cookie" "$p" "${tmp_dir}/u1_${p//\//_}.html")"
    assert_eq "200" "$code" "Authenticated route ${p} renders for user1"
done

# GET /logout behavior when logged in
u1_logout_get_headers="${tmp_dir}/u1_logout_get.headers"
code="$(curl -sS -b "$u1_cookie" -c "$u1_cookie" -D "$u1_logout_get_headers" -o /dev/null -w '%{http_code}' "${BASE_URL}/logout")"
[[ "$code" == "302" || "$code" == "303" ]] || fail "GET /logout should redirect when logged in"
loc="$(redirect_from_headers "$u1_logout_get_headers")"
[[ "$loc" == "/dashboard" ]] || fail "GET /logout (logged in) should redirect to /dashboard"
pass "GET /logout redirect works for logged-in user"

# POST /logout, then login again
dash_file="${tmp_dir}/u1_dashboard_for_logout.html"
code="$(get_code "$u1_cookie" "/dashboard" "$dash_file")"
assert_eq "200" "$code" "Dashboard available before logout"
csrf="$(csrf_from_file "$dash_file")"
assert_nonempty "$csrf" "Logout CSRF token found"
logout_headers="${tmp_dir}/u1_logout_post.headers"
post_code="$(post_form "$u1_cookie" "/logout" "${tmp_dir}/u1_logout_post.html" "$logout_headers" --data-urlencode "_csrf=${csrf}")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "POST /logout should redirect"
loc="$(redirect_from_headers "$logout_headers")"
[[ "$loc" == "/login" ]] || fail "POST /logout should redirect to /login"
pass "POST /logout works"

headers_after_logout="${tmp_dir}/u1_after_logout.headers"
code="$(curl -sS -b "$u1_cookie" -c "$u1_cookie" -D "$headers_after_logout" -o /dev/null -w '%{http_code}' "${BASE_URL}/dashboard")"
[[ "$code" == "302" || "$code" == "303" ]] || fail "Dashboard should redirect after logout"
loc="$(redirect_from_headers "$headers_after_logout")"
[[ "$loc" == "/login" ]] || fail "Dashboard redirect after logout should be /login"
pass "Session invalidated after logout"

login_page="${tmp_dir}/u1_login_page.html"
code="$(get_code "$u1_cookie" "/login" "$login_page")"
assert_eq "200" "$code" "Login page loads after logout"
csrf="$(csrf_from_file "$login_page")"
assert_nonempty "$csrf" "Login CSRF token found"
login_headers="${tmp_dir}/u1_login_post.headers"
post_code="$(post_form "$u1_cookie" "/login" "${tmp_dir}/u1_login_post.html" "$login_headers" \
    --data-urlencode "_csrf=${csrf}" \
    --data-urlencode "email=${email1}" \
    --data-urlencode "password=Password123!")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Login should redirect"
loc="$(redirect_from_headers "$login_headers")"
[[ "$loc" == "/dashboard" ]] || fail "Login redirect should be /dashboard"
pass "Login works after logout"

# Profile update (route has no visible form; test API path directly)
dash_file="${tmp_dir}/u1_dashboard_profile.html"
code="$(get_code "$u1_cookie" "/dashboard" "$dash_file")"
assert_eq "200" "$code" "Dashboard loads for profile update"
csrf="$(csrf_from_file "$dash_file")"
assert_nonempty "$csrf" "Profile CSRF token found"
profile_headers="${tmp_dir}/u1_profile_post.headers"
new_country="E2E-COUNTRY-${ts}"
new_position="FWD"
post_code="$(post_form "$u1_cookie" "/profile" "${tmp_dir}/u1_profile_post.html" "$profile_headers" \
    --data-urlencode "_csrf=${csrf}" \
    --data-urlencode "fname=E2E User One Updated" \
    --data-urlencode "country=${new_country}" \
    --data-urlencode "position=${new_position}")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Profile update should redirect"
loc="$(redirect_from_headers "$profile_headers")"
[[ "$loc" == "/dashboard" ]] || fail "Profile update redirect should be /dashboard"
acct_json="$(account_by_email "$email1")"
country_val="$(printf '%s' "$acct_json" | json_first_value country)"
position_val="$(printf '%s' "$acct_json" | json_first_value position)"
[[ "$country_val" == "$new_country" ]] || fail "Profile country update did not persist"
[[ "$position_val" == "$new_position" ]] || fail "Profile position update did not persist"
pass "Profile update persists in database"

# Teams: create club, prevent duplicate
teams_page="${tmp_dir}/u1_teams_page.html"
code="$(get_code "$u1_cookie" "/teams" "$teams_page")"
assert_eq "200" "$code" "Teams page loads"
csrf="$(csrf_from_file "$teams_page")"
assert_nonempty "$csrf" "Teams CSRF token found"
club_name="E2E Club ${ts}"
teams_headers="${tmp_dir}/u1_teams_post.headers"
post_code="$(post_form "$u1_cookie" "/teams" "${tmp_dir}/u1_teams_post.html" "$teams_headers" \
    --data-urlencode "_csrf=${csrf}" \
    --data-urlencode "name=${club_name}")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Create club should redirect"
clubs_json="$(curl -sS --get "${SB_URL}/rest/v1/clubs" \
    -H "apikey: ${SB_KEY}" -H "Authorization: Bearer ${SB_KEY}" \
    --data-urlencode "select=id,name,owner" --data-urlencode "owner=eq.${uid1}")"
club_count="$(printf '%s' "$clubs_json" | json_count)"
assert_eq "1" "$club_count" "User1 owns exactly one club after creation"
club_id="$(printf '%s' "$clubs_json" | json_first_value id)"
assert_nonempty "$club_id" "Instructor club id resolved"

teams_page_dup="${tmp_dir}/u1_teams_page_dup.html"
code="$(get_code "$u1_cookie" "/teams" "$teams_page_dup")"
assert_eq "200" "$code" "Teams page reloads for duplicate test"
csrf="$(csrf_from_file "$teams_page_dup")"
dup_headers="${tmp_dir}/u1_teams_dup_post.headers"
post_code="$(post_form "$u1_cookie" "/teams" "${tmp_dir}/u1_teams_dup_post.html" "$dup_headers" \
    --data-urlencode "_csrf=${csrf}" \
    --data-urlencode "name=Another Club ${ts}")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Duplicate create attempt should redirect"
clubs_json_after="$(curl -sS --get "${SB_URL}/rest/v1/clubs" \
    -H "apikey: ${SB_KEY}" -H "Authorization: Bearer ${SB_KEY}" \
    --data-urlencode "select=id,name,owner" --data-urlencode "owner=eq.${uid1}")"
club_count_after="$(printf '%s' "$clubs_json_after" | json_count)"
assert_eq "1" "$club_count_after" "Duplicate club creation is blocked"

# Instructor team control: move and remove player assignment
teams_manage_page="${tmp_dir}/u1_teams_manage_page.html"
code="$(get_code "$u1_cookie" "/teams" "$teams_manage_page")"
assert_eq "200" "$code" "Instructor teams page loads for team-control actions"
csrf_teams_manage="$(csrf_from_file "$teams_manage_page")"
assert_nonempty "$csrf_teams_manage" "Instructor team-control CSRF token found"

move_headers="${tmp_dir}/u1_move_member_post.headers"
post_code="$(post_form "$u1_cookie" "/teams/member/move" "${tmp_dir}/u1_move_member_post.html" "$move_headers" \
    --data-urlencode "_csrf=${csrf_teams_manage}" \
    --data-urlencode "player_uid=${uid2}" \
    --data-urlencode "target_club_id=${club_id}")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Instructor move-member should redirect"
u2_after_move="$(account_by_uid "$uid2")"
u2_club_assign="$(printf '%s' "$u2_after_move" | json_first_value club_assign)"
assert_eq "$club_id" "$u2_club_assign" "Instructor can move player to another team"

teams_manage_page2="${tmp_dir}/u1_teams_manage_page2.html"
code="$(get_code "$u1_cookie" "/teams" "$teams_manage_page2")"
assert_eq "200" "$code" "Instructor teams page reloads for remove-member action"
csrf_teams_manage2="$(csrf_from_file "$teams_manage_page2")"
remove_headers="${tmp_dir}/u1_remove_member_post.headers"
post_code="$(post_form "$u1_cookie" "/teams/member/remove" "${tmp_dir}/u1_remove_member_post.html" "$remove_headers" \
    --data-urlencode "_csrf=${csrf_teams_manage2}" \
    --data-urlencode "player_uid=${uid2}")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Instructor remove-member should redirect"
u2_after_remove="$(account_by_uid "$uid2")"
u2_club_assign_after_remove="$(printf '%s' "$u2_after_remove" | json_first_value club_assign)"
assert_eq "" "$u2_club_assign_after_remove" "Instructor can remove player from team"

# Players invites: success and self-block
invites_before="$(invites_between "$uid1" "$uid2")"
invite_count_before="$(printf '%s' "$invites_before" | json_count)"

players_page="${tmp_dir}/u1_players_page.html"
code="$(get_code "$u1_cookie" "/players" "$players_page")"
assert_eq "200" "$code" "Players page loads"
csrf="$(csrf_from_file "$players_page")"
assert_nonempty "$csrf" "Players CSRF token found"
invite_headers="${tmp_dir}/u1_invite_post.headers"
post_code="$(post_form "$u1_cookie" "/players" "${tmp_dir}/u1_invite_post.html" "$invite_headers" \
    --data-urlencode "_csrf=${csrf}" \
    --data-urlencode "sent_to=${uid2}")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Invite should redirect"
invites_after="$(invites_between "$uid1" "$uid2")"
invite_count_after="$(printf '%s' "$invites_after" | json_count)"
(( invite_count_after == invite_count_before + 1 )) || fail "Invite creation failed"
pass "Player invite creation works"

players_page2="${tmp_dir}/u1_players_page2.html"
code="$(get_code "$u1_cookie" "/players" "$players_page2")"
assert_eq "200" "$code" "Players page reloads for self-invite test"
csrf="$(csrf_from_file "$players_page2")"
self_invite_headers="${tmp_dir}/u1_self_invite_post.headers"
post_code="$(post_form "$u1_cookie" "/players" "${tmp_dir}/u1_self_invite_post.html" "$self_invite_headers" \
    --data-urlencode "_csrf=${csrf}" \
    --data-urlencode "sent_to=${uid1}")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Self-invite attempt should redirect"
invites_self_after="$(invites_between "$uid1" "$uid1")"
self_count="$(printf '%s' "$invites_self_after" | json_count)"
assert_eq "0" "$self_count" "Self-invite is blocked"

# Matchmaking create + self-challenge block
mm_page="${tmp_dir}/u1_matchmaking_page.html"
code="$(get_code "$u1_cookie" "/matchmaking" "$mm_page")"
assert_eq "200" "$code" "Matchmaking page loads"
csrf="$(csrf_from_file "$mm_page")"
assert_nonempty "$csrf" "Matchmaking CSRF token found"
mm_headers="${tmp_dir}/u1_matchmaking_post.headers"
today="$(date +%F)"
post_code="$(post_form "$u1_cookie" "/matchmaking" "${tmp_dir}/u1_matchmaking_post.html" "$mm_headers" \
    --data-urlencode "_csrf=${csrf}" \
    --data-urlencode "opponent_id=${uid2}" \
    --data-urlencode "location=E2E Arena" \
    --data-urlencode "date=${today}" \
    --data-urlencode "time=18:30")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Create challenge should redirect"
match_json="$(match_between "$uid1" "$uid2")"
match_id="$(printf '%s' "$match_json" | json_first_value id)"
match_status="$(printf '%s' "$match_json" | json_first_value challange_status)"
assert_nonempty "$match_id" "Challenge row created"
assert_eq "pending" "$match_status" "New challenge status is pending"

mm_page2="${tmp_dir}/u1_matchmaking_page2.html"
code="$(get_code "$u1_cookie" "/matchmaking" "$mm_page2")"
assert_eq "200" "$code" "Matchmaking page reloads for self-challenge test"
csrf="$(csrf_from_file "$mm_page2")"
self_mm_headers="${tmp_dir}/u1_matchmaking_self_post.headers"
post_code="$(post_form "$u1_cookie" "/matchmaking" "${tmp_dir}/u1_matchmaking_self_post.html" "$self_mm_headers" \
    --data-urlencode "_csrf=${csrf}" \
    --data-urlencode "opponent_id=${uid1}" \
    --data-urlencode "location=E2E Arena 2" \
    --data-urlencode "date=${today}" \
    --data-urlencode "time=19:00")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Self challenge attempt should redirect"
self_match_json="$(curl -sS --get "${SB_URL}/rest/v1/matchs" \
    -H "apikey: ${SB_KEY}" -H "Authorization: Bearer ${SB_KEY}" \
    --data-urlencode "select=id" \
    --data-urlencode "challenger_id=eq.${uid1}" \
    --data-urlencode "opponent_id=eq.${uid1}")"
self_match_count="$(printf '%s' "$self_match_json" | json_count)"
assert_eq "0" "$self_match_count" "Self-challenge is blocked"

# Challenges: instructor accept, re-accept blocked, unauthorized user blocked
u1_challenges_page="${tmp_dir}/u1_challenges_page.html"
code="$(get_code "$u1_cookie" "/challenges" "$u1_challenges_page")"
assert_eq "200" "$code" "Instructor challenges page loads"
csrf_u1="$(csrf_from_file "$u1_challenges_page")"
assert_nonempty "$csrf_u1" "Instructor challenge CSRF token found"
accept_headers="${tmp_dir}/u1_accept_post.headers"
post_code="$(post_form "$u1_cookie" "/challenges" "${tmp_dir}/u1_accept_post.html" "$accept_headers" \
    --data-urlencode "_csrf=${csrf_u1}" \
    --data-urlencode "match_id=${match_id}" \
    --data-urlencode "action=accept")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Challenge accept should redirect"
match_json="$(match_between "$uid1" "$uid2")"
match_status="$(printf '%s' "$match_json" | json_first_value challange_status)"
assert_eq "accepted" "$match_status" "Instructor challenge accept updates status"

u1_challenges_page2="${tmp_dir}/u1_challenges_page2.html"
code="$(get_code "$u1_cookie" "/challenges" "$u1_challenges_page2")"
assert_eq "200" "$code" "Instructor challenges page reloads"
csrf_u1="$(csrf_from_file "$u1_challenges_page2")"
reaccept_headers="${tmp_dir}/u1_reaccept_post.headers"
post_code="$(post_form "$u1_cookie" "/challenges" "${tmp_dir}/u1_reaccept_post.html" "$reaccept_headers" \
    --data-urlencode "_csrf=${csrf_u1}" \
    --data-urlencode "match_id=${match_id}" \
    --data-urlencode "action=accept")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Re-accept attempt should redirect"
match_json="$(match_between "$uid1" "$uid2")"
match_status="$(printf '%s' "$match_json" | json_first_value challange_status)"
assert_eq "accepted" "$match_status" "Processed challenge cannot be changed by instructor re-accept"

u3_challenges_page="${tmp_dir}/u3_challenges_page.html"
code="$(get_code "$u3_cookie" "/challenges" "$u3_challenges_page")"
assert_eq "200" "$code" "User3 challenges page loads"
csrf_u3="$(csrf_from_file "$u3_challenges_page")"
assert_nonempty "$csrf_u3" "User3 challenge CSRF token found"
u3_decline_headers="${tmp_dir}/u3_decline_post.headers"
post_code="$(post_form "$u3_cookie" "/challenges" "${tmp_dir}/u3_decline_post.html" "$u3_decline_headers" \
    --data-urlencode "_csrf=${csrf_u3}" \
    --data-urlencode "match_id=${match_id}" \
    --data-urlencode "action=decline")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Unauthorized challenge response should redirect"
match_json="$(match_between "$uid1" "$uid2")"
match_status="$(printf '%s' "$match_json" | json_first_value challange_status)"
assert_eq "accepted" "$match_status" "Unauthorized user cannot alter challenge status"

# Video upload URL mode (authorized)
u1_video_page="${tmp_dir}/u1_video_page.html"
code="$(get_code "$u1_cookie" "/video-upload" "$u1_video_page")"
assert_eq "200" "$code" "Video upload page loads"
csrf_video="$(csrf_from_file "$u1_video_page")"
assert_nonempty "$csrf_video" "Video upload CSRF token found"
video_url_headers="${tmp_dir}/u1_video_url_post.headers"
video_url_value="https://example.com/e2e-video-${ts}.mp4"
post_code="$(post_form "$u1_cookie" "/video-upload" "${tmp_dir}/u1_video_url_post.html" "$video_url_headers" \
    --data-urlencode "_csrf=${csrf_video}" \
    --data-urlencode "match_id=${match_id}" \
    --data-urlencode "video_url=${video_url_value}")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Video URL upload should redirect"
match_json="$(match_between "$uid1" "$uid2")"
db_video_url="$(printf '%s' "$match_json" | json_first_value video_url)"
db_video_status="$(printf '%s' "$match_json" | json_first_value video_status)"
assert_eq "$video_url_value" "$db_video_url" "Video URL upload persists URL"
assert_eq "external_url" "$db_video_status" "Video URL upload sets status"

# Video upload file mode (authorized)
video_file="${tmp_dir}/nutmeg_e2e_sample.mp4"
ffmpeg -hide_banner -loglevel error -y \
    -f lavfi -i color=c=black:s=320x240:d=1 \
    -f lavfi -i anullsrc=r=44100:cl=stereo \
    -shortest -c:v libx264 -pix_fmt yuv420p -c:a aac "${video_file}"
[[ -s "${video_file}" ]] || fail "Generated video file is empty"
mime="$(file --mime-type -b "${video_file}")"
[[ "$mime" == "video/mp4" ]] || fail "Generated test file mime is not video/mp4 (got ${mime})"
pass "Generated valid MP4 test file"

u1_video_page2="${tmp_dir}/u1_video_page2.html"
code="$(get_code "$u1_cookie" "/video-upload" "$u1_video_page2")"
assert_eq "200" "$code" "Video upload page reloads for file upload"
csrf_video="$(csrf_from_file "$u1_video_page2")"
file_headers="${tmp_dir}/u1_video_file_post.headers"
post_code="$(curl -sS -b "$u1_cookie" -c "$u1_cookie" -D "$file_headers" -o "${tmp_dir}/u1_video_file_post.html" -w '%{http_code}' \
    -X POST "${BASE_URL}/video-upload" \
    -F "_csrf=${csrf_video}" \
    -F "match_id=${match_id}" \
    -F "video_file=@${video_file};type=video/mp4")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Video file upload should redirect"
match_json="$(match_between "$uid1" "$uid2")"
db_video_url="$(printf '%s' "$match_json" | json_first_value video_url)"
db_video_status="$(printf '%s' "$match_json" | json_first_value video_status)"
if [[ "$db_video_url" == /videos/* ]]; then
  local_video_path="${ROOT_DIR}/public${db_video_url}"
  [[ -f "$local_video_path" ]] || fail "Uploaded file missing at ${local_video_path}"
  [[ -s "$local_video_path" ]] || fail "Uploaded file exists but is empty"
else
  fail "File upload should store a local /videos path (got ${db_video_url})"
fi
assert_eq "queued" "$db_video_status" "Video file upload queues AI processing"
pass "Video file upload saves a website-hosted video URL"

# Unauthorized upload attempt by user3 should not overwrite
before_unauth_url="$db_video_url"
u3_video_page="${tmp_dir}/u3_video_page.html"
code="$(get_code "$u3_cookie" "/video-upload" "$u3_video_page")"
assert_eq "200" "$code" "User3 video page loads"
csrf_u3_video="$(csrf_from_file "$u3_video_page")"
unauth_headers="${tmp_dir}/u3_video_unauth_post.headers"
post_code="$(post_form "$u3_cookie" "/video-upload" "${tmp_dir}/u3_video_unauth_post.html" "$unauth_headers" \
    --data-urlencode "_csrf=${csrf_u3_video}" \
    --data-urlencode "match_id=${match_id}" \
    --data-urlencode "video_url=https://example.com/unauthorized.mp4")"
[[ "$post_code" == "302" || "$post_code" == "303" ]] || fail "Unauthorized upload attempt should redirect"
match_json="$(match_between "$uid1" "$uid2")"
after_unauth_url="$(printf '%s' "$match_json" | json_first_value video_url)"
assert_eq "$before_unauth_url" "$after_unauth_url" "Unauthorized user cannot overwrite video"

# GET /logout behavior when logged out (user1 cookie still logged in; create fresh anon check)
anon_headers="${tmp_dir}/anon_logout_get.headers"
code="$(curl -sS -D "$anon_headers" -o /dev/null -w '%{http_code}' "${BASE_URL}/logout")"
[[ "$code" == "302" || "$code" == "303" ]] || fail "GET /logout should redirect when logged out"
loc="$(redirect_from_headers "$anon_headers")"
[[ "$loc" == "/login" ]] || fail "GET /logout (logged out) should redirect to /login"
pass "GET /logout redirect works for logged-out user"

say
say "All E2E checks passed."
say "Users: ${email1}, ${email2}, ${email3}"
say "Match ID: ${match_id}"
say "Uploaded file: ${local_video_path}"
