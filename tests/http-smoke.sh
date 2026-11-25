#!/usr/bin/env bash
# 本番や既存DBに触れず、一時SQLiteと実HTTPで操作を確認する。
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
test -f public/build/manifest.json || { echo 'Run npm ci && npm run build first.' >&2; exit 1; }
temp=$(mktemp -d)
pid=""
cleanup() {
  if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  rm -rf -- "$temp"
}
trap cleanup EXIT
php_bin=${PHP_BIN:-php}
port=${TEST_PORT:-18187}
url="http://127.0.0.1:$port"
export APP_ENV=local APP_DEBUG=false APP_URL="$url" DB_CONNECTION=sqlite DB_DATABASE="$temp/db.sqlite"
export SESSION_DRIVER=file CACHE_STORE=file LOG_CHANNEL=single LARAVEL_STORAGE_PATH="$temp/store"
quota=400
if [[ -n "${TEST_MP4:-}" ]]; then quota=$((quota + $(wc -c <"$TEST_MP4"))); fi
export MEDIA_IMAGE_MAX_KB=4
export APP_KEY
APP_KEY="base64:$("$php_bin" -r 'echo base64_encode(random_bytes(32));')"
touch "$DB_DATABASE"
mkdir -p "$LARAVEL_STORAGE_PATH"/{app/private,framework/{cache/data,sessions,views},logs}
"$php_bin" artisan migrate --force \
  --path=database/migrations/2026_11_25_000000_create_users.php \
  --path=database/migrations/2026_11_25_000001_create_media_items.php >/dev/null
"$php_bin" tests/seed-smoke.php "$temp"
"$php_bin" artisan migrate --force >/dev/null
quota=$((quota + $(wc -c <"$temp/large-enough.png") + $(wc -c <"$temp/wide.png") + $(wc -c <"$temp/test.png")))
export MEDIA_QUOTA_BYTES=$quota
has_gd=$("$php_bin" -r 'echo (int) function_exists("imagecreatefrompng");')
if [[ ${REQUIRE_GD:-0} == 1 && "$has_gd" != 1 ]]; then echo 'GD required for this verification' >&2; exit 1; fi
"$php_bin" -c docker/uploads.ini -S "127.0.0.1:$port" -t public >"$temp/server.log" 2>&1 &
pid=$!
for _ in {1..50}; do
  if curl -fsS --noproxy '*' "$url/login" -o "$temp/ready.html" 2>/dev/null; then break; fi
  if ! kill -0 "$pid" 2>/dev/null; then cat "$temp/server.log" >&2; exit 1; fi
  sleep 0.1
done

count=0
req() {
  local expected=$1
  shift
  local code
  code=$(curl -sS --max-time 12 --noproxy '*' -D "$temp/headers" -o "$temp/body" -w '%{http_code}' "$@")
  if [[ "$code" != "$expected" ]]; then
    printf 'Expected HTTP %s, got %s (%s)\n' "$expected" "$code" "${*: -1}" >&2
    tail -n 16 "$temp/server.log" >&2
    tail -n 35 "$LARAVEL_STORAGE_PATH/logs/laravel.log" 2>/dev/null >&2 || true
    exit 1
  fi
  count=$((count + 1))
}
field() {
  sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$temp/body" | sed -n '1p'
}
# 暗号化時に毎回変わるcookie文字列ではなく、復号した一時セッションIDを比較する。
session_id() {
  local jar=$1 raw
  raw=$(awk -F '\t' '$6 ~ /_session$/ {print $7; exit}' "$jar")
  "$php_bin" -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $value = $app->make("encrypter")->decrypt(rawurldecode($argv[1]), false);
    if (!preg_match("/^[a-f0-9]{40}\|([a-zA-Z0-9]{40})$/D", $value, $matches)) exit(1);
    echo $matches[1];
  ' "$raw"
}
sign_in() {
  local email=$1 jar=$2
  req 200 -c "$jar" "$url/login"
  local token old_session; token=$(field); old_session=$(session_id "$jar")
  [[ -n "$token" && -n "$old_session" ]]
  req 302 -b "$jar" -c "$jar" --data-urlencode "_token=$token" \
    --data-urlencode "email=$email" --data-urlencode "password=$(cat "$temp/password")" "$url/login"
  req 200 -b "$jar" -c "$jar" "$url/"
  [[ "$(session_id "$jar")" != "$old_session" ]]
  [[ "$(field)" != "$token" ]]
}
match() { grep -Fq -- "$1" "$temp/body"; }
json() {
  "$php_bin" -r '$data=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); echo $data[$argv[2]] ?? "";' "$temp/body" "$1"
}
upload() {
  local expected=$1 file=$2 token=$3 jar=$4 folder=${5:-}
  req "$expected" -b "$jar" -H 'Accept: application/json' -F "_token=$token" -F "parent_id=$folder" -F "file=@$file" "$url/items"
}
id_by_name() {
  "$php_bin" -r '$db=new PDO("sqlite:".getenv("DB_DATABASE"));$s=$db->prepare("SELECT id FROM media_items WHERE name=?");$s->execute([$argv[1]]);echo $s->fetchColumn();' "$1"
}

# A: Blade、マイグレーション、ViteのCSS/JSを実際の公開ディレクトリから配信。
req 200 "$url/login"
match '共有ドライブへ'
for asset in $(grep -o '/build/assets/[^"]*' "$temp/body"); do
  req 200 "$url$asset"
done
echo 'A PASS: PHP 8.3.37 server, SQLite migrations, Blade and built assets'

# B: CSRFと認証、画像の共同CRUD、原本バイト列比較。
req 302 "$url/"
req 419 -H 'Accept: application/json' -d 'email=missing@example.test&password=invalid' "$url/login"
req 404 "$url/register"
req 404 "$url/storage/example.png"
sign_in first@example.test "$temp/first"
token_first=$(field)
match '共有ドライブ'
match 'legacy.png'
match '登録者：記録なし'
match '画像プレビューは上のリンクから'
legacy=$(id_by_name legacy.png)
req 404 -b "$temp/first" "$url/items/$legacy/thumbnail"
req 200 -b "$temp/first" "$url/items/$legacy/download"
cmp "$temp/test.png" "$temp/body"
upload 201 "$temp/test.png" "$token_first" "$temp/first"
image=$(json id)
[[ -n "$image" ]]
req 302 "$url/items/$image/preview"
req 302 "$url/items/$image/thumbnail"
req 401 -H 'Accept: application/json' "$url/items/$image/thumbnail"
req 302 "$url/items/$image/download"
req 401 -H 'Accept: application/json' "$url/items/$image/preview"
req 200 -b "$temp/first" "$url/"
match 'test.png'
match '登録者：first'
! grep -Fq "src=\"$url/items/$image/preview\"" "$temp/body"
if [[ "$has_gd" == 1 ]]; then
  grep -Fq "src=\"$url/items/$image/thumbnail\"" "$temp/body"
fi
req 200 -b "$temp/first" "$url/items/$image/preview"
cmp "$temp/test.png" "$temp/body"
grep -iq '^X-Content-Type-Options: nosniff' "$temp/headers"
grep -iq '^Content-Type: image/png' "$temp/headers"
grep -iq '^Cache-Control:.*no-store.*private' "$temp/headers"
! grep -iq '^Cache-Control:.*public' "$temp/headers"
sign_in second@example.test "$temp/second"
token_second=$(field)
match 'test.png'
req 200 -b "$temp/second" "$url/items/$image/download"
cmp "$temp/test.png" "$temp/body"
grep -iq '^Content-Disposition: attachment' "$temp/headers"
grep -iq '^Cache-Control:.*no-store.*private' "$temp/headers"
! grep -iq '^Cache-Control:.*public' "$temp/headers"
req 302 -b "$temp/second" --data-urlencode "_token=$token_second" -d '_method=PATCH' --data-urlencode 'name=共同編集.png' "$url/items/$image/rename"
req 200 -b "$temp/first" "$url/"
match '共同編集.png'
match '登録者：first'
req 302 -b "$temp/second" --data-urlencode "_token=$token_second" -d '_method=DELETE' "$url/items/$image"
req 404 -b "$temp/first" "$url/items/$image/preview"
req 404 -b "$temp/first" "$url/items/$image/thumbnail"
req 200 -b "$temp/first" "$url/?trash=1"
match '共同編集.png'
req 302 -b "$temp/first" --data-urlencode "_token=$token_first" "$url/items/$image/restore"
req 200 -b "$temp/second" "$url/items/$image/download"
cmp "$temp/test.png" "$temp/body"
upload 422 "$temp/unsafe.svg" "$token_first" "$temp/first"
upload 422 "$temp/imposter.png" "$token_first" "$temp/first"
upload 422 "$temp/large.png" "$token_first" "$temp/first"
upload 201 "$temp/large-enough.png" "$token_first" "$temp/first"
sized=$(json id)
if [[ "$has_gd" == 1 ]]; then
  req 200 -b "$temp/second" "$url/items/$sized/thumbnail"
  grep -iq '^Content-Type: image/png' "$temp/headers"
  grep -iq '^Cache-Control:.*no-store.*private' "$temp/headers"
  "$php_bin" -r '$s = getimagesize($argv[1]); if ($s[0] !== 320 || $s[1] !== 240 || filesize($argv[1]) > 327680) exit(1);' "$temp/body"
  ! cmp -s "$temp/large-enough.png" "$temp/body"
  cp "$temp/body" "$temp/thumbnail"
else
  req 404 -b "$temp/second" "$url/items/$sized/thumbnail"
fi
req 200 -b "$temp/second" "$url/items/$sized/download"
cmp "$temp/large-enough.png" "$temp/body"
upload 201 "$temp/wide.png" "$token_first" "$temp/first"
wide=$(json id)
req 404 -b "$temp/second" "$url/items/$wide/thumbnail"
req 200 -b "$temp/first" "$url/items/$wide/download"
cmp "$temp/wide.png" "$temp/body"
# 一時ディレクトリで派生物の書込み先をファイルに置換し、原本の登録を妨げないことを確認。
if [[ "$has_gd" == 1 ]]; then
  thumb_dir="$LARAVEL_STORAGE_PATH/app/private/media/thumbnails"
  mv "$thumb_dir" "$temp/held-thumbnails"
  touch "$thumb_dir"
  upload 201 "$temp/test.png" "$token_first" "$temp/first"
  fallback=$(json id)
  req 404 -b "$temp/first" "$url/items/$fallback/thumbnail"
  req 200 -b "$temp/first" "$url/items/$fallback/download"
  cmp "$temp/test.png" "$temp/body"
  rm -- "$thumb_dir"
  mv "$temp/held-thumbnails" "$thumb_dir"
fi
echo 'B PASS: real sessions, private routes, image upload/preview/download, second-user rename/trash/restore, MIME/size checks'

# C: フォルダと祖先の保護、実MP4区間。各送信は通常のHTTPを通す。
req 302 -b "$temp/first" --data-urlencode "_token=$token_first" --data-urlencode 'name=親' "$url/folders"
parent=$(id_by_name 親)
req 302 -b "$temp/second" --data-urlencode "_token=$token_second" --data-urlencode 'name=子' -d "parent_id=$parent" "$url/folders"
child=$(id_by_name 子)
req 200 -b "$temp/first" "$url/?folder=$parent"
match '登録者：second'
req 422 -b "$temp/first" -H 'Accept: application/json' --data-urlencode "_token=$token_first" -d "_method=PATCH&destination=$child" "$url/items/$parent/move"
req 302 -b "$temp/second" --data-urlencode "_token=$token_second" -d "_method=PATCH&destination=$child" "$url/items/$image/move"
req 200 -b "$temp/first" "$url/?folder=$child"
match '共同編集.png'
req 302 -b "$temp/second" --data-urlencode "_token=$token_second" -d '_method=DELETE' "$url/items/$parent"
req 404 -b "$temp/first" "$url/items/$image/download"
req 404 -b "$temp/second" "$url/items/$image/thumbnail"
req 404 -b "$temp/first" "$url/?folder=$child"
req 302 -b "$temp/first" --data-urlencode "_token=$token_first" "$url/items/$parent/restore"
req 200 -b "$temp/second" "$url/items/$image/preview"
if [[ "$has_gd" == 1 ]]; then
  req 200 -b "$temp/second" "$url/items/$image/thumbnail"
  req 200 -b "$temp/second" "$url/items/$sized/thumbnail"
  cmp "$temp/thumbnail" "$temp/body"
fi
if [[ -n "${TEST_MP4:-}" ]]; then
  upload 201 "$TEST_MP4" "$token_first" "$temp/first"
  video=$(json id)
  req 206 -b "$temp/first" -H 'Range: bytes=2-15' "$url/items/$video/preview"
  grep -iq '^Content-Range: bytes 2-15/' "$temp/headers"
  grep -iq '^Content-Type: video/mp4' "$temp/headers"
  dd if="$TEST_MP4" of="$temp/range" bs=1 skip=2 count=14 status=none
  cmp "$temp/range" "$temp/body"
  req 416 -b "$temp/first" -H 'Range: bytes=999999-1000000' "$url/items/$video/preview"
  req 302 -H 'Range: bytes=2-15' "$url/items/$video/preview"
  req 200 -b "$temp/first" "$url/items/$video/download"
  cmp "$TEST_MP4" "$temp/body"
  echo 'C PASS: nested folder/move/cycle guard/restore, real MP4 206/416 and range bytes'
else
  echo 'C PARTIAL: folder/move/cycle guard/restore PASS; no TEST_MP4 supplied'
fi

# D: ゴミ箱の原本も容量に残り、DB失敗時の移動済みファイルが残らない。
before=$(find "$LARAVEL_STORAGE_PATH/app/private/media" -type f | wc -l)
"$php_bin" <<'PHP'
<?php
$db = new PDO('sqlite:'.getenv('DB_DATABASE'));
$db->exec("CREATE TRIGGER smoke_reject BEFORE INSERT ON media_items BEGIN SELECT RAISE(ABORT, 'forced test failure'); END");
PHP
upload 500 "$temp/test.png" "$token_first" "$temp/first"
[[ $(find "$LARAVEL_STORAGE_PATH/app/private/media" -type f | wc -l) == "$before" ]]
"$php_bin" -r '$db=new PDO("sqlite:".getenv("DB_DATABASE")); $db->exec("DROP TRIGGER smoke_reject");'
for _ in {1..90}; do
  req 200 -b "$temp/first" "$url/?trash=1"
  if [[ "$("$php_bin" -r '$p=new PDO("sqlite:".getenv("DB_DATABASE")); echo (int)$p->query("SELECT COALESCE(SUM(size_bytes),0) FROM media_items")->fetchColumn();')" -gt $((MEDIA_QUOTA_BYTES - $(wc -c <"$temp/test.png"))) ]]; then break; fi
  upload 201 "$temp/test.png" "$token_first" "$temp/first"
  duplicate=$(json id)
  req 302 -b "$temp/first" --data-urlencode "_token=$token_first" -d '_method=DELETE' "$url/items/$duplicate"
done
upload 422 "$temp/test.png" "$token_first" "$temp/first"
[[ "$(json message)" == *共有容量* ]]
req 200 -b "$temp/first" "$url/?trash=1"
match 'ゴミ箱の原本を含む'
cp "$temp/first" "$temp/old-auth"
old_session=$(session_id "$temp/first")
req 302 -b "$temp/first" -c "$temp/first" --data-urlencode "_token=$token_first" "$url/logout"
[[ "$(session_id "$temp/first")" != "$old_session" ]]
req 302 -b "$temp/old-auth" "$url/items/$image/download"
req 302 -b "$temp/first" "$url/"
req 200 -b "$temp/first" -c "$temp/first" "$url/login"
fresh_token=$(field)
[[ -n "$fresh_token" && "$fresh_token" != "$token_first" ]]
req 419 -b "$temp/first" -H 'Accept: application/json' -d "_token=$token_first&email=wrong@example.test&password=invalid" "$url/login"
# 認証済み2回に加え、未認証の失敗を4回。次の7回目には429になる。
for _ in {1..4}; do
  req 422 -b "$temp/first" -H 'Accept: application/json' -d "_token=$fresh_token&email=wrong@example.test&password=invalid" "$url/login"
done
req 429 -b "$temp/first" -H 'Accept: application/json' -d "_token=$fresh_token&email=wrong@example.test&password=invalid" "$url/login"
echo 'D PASS: trash quota, DB failure cleanup, logout session/CSRF renewal, old session denied and login rate limit'
if [[ "$has_gd" == 1 ]]; then
  echo 'THUMBNAIL PASS: bounded real PNG, no original list source, separate cache and ACL, oversized image fallback, rollback/restore'
else
  echo 'THUMBNAIL FALLBACK PASS: GD absent, originals and list remain usable'
fi
echo "PASS: $count real HTTP responses checked"
