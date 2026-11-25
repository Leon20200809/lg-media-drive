# LG Media Drive

約3人で画像と短いMP4を扱う、ログイン必須の共同ドライブです。登録済みメンバーは全員が同じファイル・フォルダを操作できます。公開登録、外部共有リンク、動画変換、完全削除はありません。

約3人の事前作成アカウントだけに絞るため、Breezeは導入せずLaravel標準のセッション認証・CSRF・レート制限を使用します。

## ROOM1-4のWSLで起動

対象は `/home/espo/projects/lg-media-drive`。このブランチを取得してから、そのディレクトリで次を実行します。既存Dockerデーモンを使い、UID/GIDの変換や権限設定は変更しません。

```bash
docker version
docker compose version
id -u; id -g

bash docker/init-env.sh
export LG_UID="$(id -u)" LG_GID="$(id -g)"
dc() { docker compose --env-file .env -f docker/compose.yml "$@"; }

dc config --quiet
dc build php
dc run --rm --no-deps php composer install --no-interaction --prefer-dist
dc run --rm --no-deps node npm ci
dc run --rm --no-deps node npm run build
# 初回のみ。既存 APP_KEY は再開時に作り直さない。
dc run --rm --no-deps php php artisan key:generate
dc up -d --wait db php web
dc exec php php artisan migrate
dc exec php php artisan media:dev-user
# 共同操作を確認したい場合のみ、別メールで2人目を用意。
dc exec php php artisan media:dev-user second@example.test --name="開発ユーザー2"
```

Windowsのブラウザで **http://localhost:8187** を開きます。各開発アカウントのメールと生成済みパスワードは、WSL側の権限0600の `.dev-credentials` で確認してください。SlackやGitHubに貼らないでください。再発行は `dc exec php php artisan media:dev-user second@example.test --reset` です。同じメールの末尾行が有効です。コマンドは `APP_ENV=local` 専用です。

再開時はUID/GIDと `dc` を設定し直して `dc up -d --wait db php web`。依存やアセットを変更した場合だけ `composer install` または `npm ci && npm run build` を再実行します。Viteサーバーの常駐は不要です。停止は `dc stop`。DBを含むため `down -v` は通常操作に使いません。

実行先の確認（秘密値は表示しません）：

```bash
dc exec php php -v
dc exec php composer --version
dc exec php php artisan --version
dc exec php php -m
dc exec db mariadb --version
dc run --rm --no-deps node node -v
dc ps
```

## できる操作

- JPEG／PNG／GIF／WebP／AVIFとMP4を登録し、一覧・プレビュー・原本ダウンロード・名前変更・ゴミ箱移動・復元。
- フォルダを作成して移動・名前変更・ゴミ箱移動・復元。自己や配下へのフォルダ移動は禁止。親をゴミ箱に入れている間は中身の原本URLも使えません。
- 複数選択は1件ずつ送信。JSなしでも単体アップロードと原本プレビューを利用可能。
- MP4の視聴・区間取得はログインが必要です。アプリは変換しないため、再生にはブラウザが対応するコーデック（例：H.264/AAC）を使ってください。
- 共有上限100 GiB、画像20 MiB、動画500 MiBは仮値です。ゴミ箱も容量に含め、今回の画面では完全削除できません。
- 動画上限などは `.env` から編集可。引き上げる場合は `docker/nginx.conf` と `docker/uploads.ini` も合わせて見直します。独立したNginx/PHPの一時領域と原本の実ディスク容量に余裕を確保してください。

原本は `storage/app/private/media`。公開ルートは **`public/` のみ**です。原本・DB・依存・秘密情報はGitに入りません。公開環境ではTLS・安全なセッション設定・秘密情報・DBと原本のバックアップ／復旧を別工程で検証し、開発用アカウントや資格情報は展開しません。この任務ではXserverへの反映は行っていません。

## 回帰検査

PHP 8.3.37 + pdo_sqlite/curl、Composer依存、ビルド済みアセットを用いる実HTTPスモーク（DockerのMariaDBとは別）。一時DBと合成画像を使用し、既存DBに接続しません。

```bash
npm ci && npm run build
bash tests/http-smoke.sh
# 任意：小さな自作MP4で認証・206/416・区間のバイト列も確かめる
ffmpeg -v error -f lavfi -i 'color=c=blue:s=64x64:r=5:d=0.4' -c:v libx264 -pix_fmt yuv420p /tmp/lg-smoke.mp4
TEST_MP4=/tmp/lg-smoke.mp4 bash tests/http-smoke.sh
```

詳細な実行結果と未検証範囲は [docs/progress.md](docs/progress.md)。
