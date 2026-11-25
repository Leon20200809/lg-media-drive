#!/usr/bin/env bash
# 既存のenvは上書きせず、初回だけランダムな開発用DBパスワードを作る。
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
if [[ -e .env || -L .env ]]; then
  printf '%s\n' '既存の .env は変更していません。'
  exit 0
fi
umask 077
db_password=$(openssl rand -hex 24)
root_password=$(openssl rand -hex 24)
set -o noclobber
sed -e "s/^DB_PASSWORD=$/DB_PASSWORD=${db_password}/" \
    -e "s/^DB_ROOT_PASSWORD=$/DB_ROOT_PASSWORD=${root_password}/" .env.example > .env
printf '%s\n' '.env を作成しました。値は画面に表示しません。'
