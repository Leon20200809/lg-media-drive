/** 複数ファイルを一度に送らず、一件ずつ処理して失敗理由を表示する。 */
function enableUpload(form) {
  const input = form.querySelector('[data-upload-input]');
  const submit = form.querySelector('[data-upload-submit]');
  const status = form.querySelector('[data-upload-status]');
  const errors = form.querySelector('[data-upload-errors]');
  const refresh = form.querySelector('[data-upload-refresh]');
  if (!input || !submit || !status || !errors || !refresh) return;
  input.multiple = true;
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const files = [...input.files];
    if (!files.length || submit.disabled) return;
    const initial = new FormData(form);
    const notice = (text) => { status.hidden = false; status.textContent = text; };
    const failure = (text) => {
      const item = document.createElement('li');
      item.textContent = text;
      errors.append(item);
      errors.hidden = false;
    };
    errors.replaceChildren();
    errors.hidden = true;
    refresh.hidden = true;
    submit.disabled = true;
    input.disabled = true;
    let succeeded = 0;

    try {
      for (const [index, file] of files.entries()) {
        notice(`${index + 1} / ${files.length}：${file.name} を送信中`);
        const payload = new FormData();
        payload.set('_token', initial.get('_token'));
        payload.set('parent_id', initial.get('parent_id'));
        payload.set('file', file);
        try {
          const response = await fetch(form.action, {
            method: 'POST',
            body: payload,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
          });
          if (response.status === 201) {
            succeeded++;
            continue;
          }
          const json = await response.json().catch(() => null);
          const details = Object.values(json?.errors ?? {}).flat().join(' ');
          failure(`${file.name}：${details || ({
            401: 'ログインし直してください。',
            413: 'サーバーの送信上限を超えました。',
            419: 'セッションが切れました。画面を再読み込みしてください。',
          }[response.status] ?? '送信を完了できませんでした。')}`);
        } catch {
          failure(`${file.name}：通信が切れました。再送信する前に一覧で登録の有無を確認してください。`);
        }
      }
    } finally {
      input.value = '';
      input.disabled = false;
      submit.disabled = false;
      refresh.hidden = false;
      notice(`${files.length} 件中 ${succeeded} 件を登録しました。`);
      if (succeeded === files.length) window.location.assign(refresh.href);
    }
  });
}

/** 動画を開いたときだけ認証付きURLを設定し、閉じたら再生を止める。 */
function enablePreview(details) {
  const video = details.querySelector('[data-preview-src]');
  if (!video) return;
  details.addEventListener('toggle', () => {
    if (details.open && !video.hasAttribute('src')) video.src = video.dataset.previewSrc;
    if (!details.open) video.pause();
  });
}

document.querySelectorAll('[data-upload-form]').forEach(enableUpload);
document.querySelectorAll('[data-preview]').forEach(enablePreview);
