# lg-media-drive 実装引き継ぎ

## 実行先

2026-11-25。ROOM1-4は再試行で応答を確認できなかったため、承認されたクラウド環境に切り替えました。ROOM1-4のWSLフォルダ、既存Dockerデーモン、既存のmain、本番には変更していません。構成の参考はリポジトリの [05-laravel](https://github.com/Leon20200809/docker-web-starter-kit/tree/main/05-laravel) の公開版のみで、別ネットワーク、別ポート、別DB・認証情報を使用します。ROOM1-4上の教材ファイルは未確認です。

## 変更した場所

- `app/Http/Controllers/`・`routes/web.php`・`resources/views/`: Laravel標準セッション認証、CSRF、ログイン試行制限、一覧・原本操作。
- `app/Services/MediaLibrary.php`・`database/migrations/`: 公開領域外の原本、実MIME・サイズ、容量のDBロック、階層保護、ゴミ箱と復元、DB登録失敗時の原本補償削除。
- `app/Http/Responses/PrivateFileResponse.php`: 範囲配信、キャッシュ禁止、ダウンロード名。
- `docker/`・`.env.example`・`README.md`・各lock: 開発起動手順と依存固定。
- `resources/css/app.css`・`resources/js/app.js`: 狭幅に折り返す画面、操作ラベル、直列アップロード、遅延動画プレビュー。
- `tests/http-smoke.sh`・`tests/seed-smoke.php`: 別のSQLite DB、別ユーザー、合成PNGと任意MP4で実HTTPの回帰を確認。

## バージョンの確認

クラウド実測: 公式PHP CLIイメージ由来の **PHP 8.3.37**（CLI、fileinfo、mbstring、PDO/pdo_sqlite）、Composer **2.11.3**、Laravel **13.44.1**、Node.js **24.22.3**、npm **11.19.1**、Vite **8.3.8**。PHP実行バイナリは公式 `php:8.3.37-cli-trixie` の linux/amd64 マニフェスト `sha256:ce7b2962a75f547bf9fbfb4df30646a4ccf6fdd8f0635d8ce04e464fc904d8c8` から、取得レイヤーのSHA256を照合して取り出しました。Dockerの起動とは別です。

Composeの公開タグを [docker-library/official-images](https://github.com/docker-library/official-images/tree/master/library) で確認: PHP-FPM **8.3.37-fpm-trixie**、Composer **2.11.3**、MariaDB **10.11.19**、Node **24.22.3-bookworm-slim**、Nginx **1.31.10-alpine**。ComposeのPHP-FPMには `pdo_mysql` とComposer配布zip用の `unzip` を追加します。タグの存在確認と、実環境のビルド・起動成功は別です。

## 検証と次

- ビルド（Vite）、PHP・JS・シェル構文、Composer依存の解決・プラットフォーム要件、Git差分チェックは合格。ViteのマニフェストとCSS・JSのHTTP 200を確認しました。
- 一時SQLite + PHP組み込みサーバーの `127.0.0.1:18187` で実HTTP **73件**合格。実ログイン2人、ログイン時のセッションIDとCSRF変更、ログアウト時の旧セッション失効・再生成、CSRF 419、ログイン制限429、公開登録404、ゲストの一覧・配信拒否、画像原本一致、別ユーザーの編集・削除・復元、実MIME偽装・SVG・画像上限の拒否、階層移動と循環防止、MP4の206/416と区間バイト列、ゴミ箱込み容量、失敗したDB登録後の原本清掃を確認しました。スモーク終了時にサーバーを停止し、一時データを消しています。ローカルURLは起動手順後の設定値で、ROOM1-4の稼働確認ではありません。
- Breezeは不使用です。手動作成した少人数に限り公開登録が不要なので、`AuthController` と `routes/web.php` でLaravel標準を使用。検証は `tests/http-smoke.sh`。
- ROOM1-4で最初に確認するのはREADMEの起動、実測UID/GID、MariaDB migration、Windowsから `http://localhost:8187` を開けるか、2ユーザーで実際に操作できるかです。
- MariaDBでの同時アップロード、動画のブラウザ再生と実際のシーク、500 MiB境界、狭幅／キーボード、複数ファイル選択の画面操作、100 GiBの実容量は未検証です。DB登録時の例外は補償削除しますが、PHPプロセス強制終了やDBコミット結果が不明な障害では別途突き合わせが必要です。
