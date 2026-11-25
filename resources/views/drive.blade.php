@extends('layout')
@section('title', $trash ? 'ゴミ箱' : '共有ドライブ')
@section('content')
@php
    // 小さな画像から動画まで読みやすい単位で表示する。
    $formatSize = static function (int $bytes): string {
        foreach ([1073741824 => 'GiB', 1048576 => 'MiB', 1024 => 'KiB'] as $divisor => $label) {
            if ($bytes >= $divisor) {
                return number_format($bytes / $divisor, 1).' '.$label;
            }
        }
        return $bytes.' B';
    };
    $usageLabel = $formatSize($usedBytes).' / '.$formatSize($quotaBytes);
    $usagePercent = min(100, (int) round($usedBytes / max(1, $quotaBytes) * 100));
@endphp
<div class="p-drive__top">
    <div>
        <p class="c-kicker">TEAM WORKSPACE</p>
        <h1>{{ $trash ? 'ゴミ箱' : ($parents->last()?->name ?? '共有ドライブ') }}</h1>
    </div>
    <div class="p-drive__usage">
        <label for="drive-usage">使用量 {{ $usageLabel }}</label>
        <meter id="drive-usage" min="0" max="100" value="{{ $usagePercent }}">{{ $usagePercent }}%</meter>
        <small>ゴミ箱の原本を含む</small>
    </div>
</div>

<nav class="p-drive__navigation" aria-label="共有ドライブの移動">
    <ol class="p-drive__breadcrumbs">
        <li><a href="{{ route('drive.index') }}">共有ドライブ</a></li>
        @foreach ($parents as $parent)
            <li><a href="{{ route('drive.index', ['folder' => $parent->id]) }}" @if($loop->last) aria-current="page" @endif>{{ $parent->name }}</a></li>
        @endforeach
    </ol>
    <a class="c-button c-button--neutral" href="{{ $trash ? route('drive.index') : route('drive.index', ['trash' => 1]) }}">
        {{ $trash ? '共有ドライブに戻る' : 'ゴミ箱を見る' }}
    </a>
</nav>

@if (!$trash)
    <div class="p-drive__actions">
        <form class="c-form c-form--panel" method="post" action="{{ route('drive.upload') }}" enctype="multipart/form-data" data-upload-form>
            @csrf
            <input type="hidden" name="parent_id" value="{{ $folderId }}">
            <label class="c-form__field">ファイルを選択
                <input name="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,video/mp4" required data-upload-input>
            </label>
            <p>画像・MP4のみ。上限はそれぞれ {{ $formatSize((int) config('media.image_max_kb') * 1024) }} と {{ $formatSize((int) config('media.video_max_kb') * 1024) }}。</p>
            <button class="c-button" type="submit" data-upload-submit>アップロード</button>
            <p class="c-form__status" role="status" aria-live="polite" data-upload-status hidden></p>
            <ul class="c-form__errors" data-upload-errors hidden></ul>
            <a data-upload-refresh hidden href="{{ route('drive.index', $folderId ? ['folder' => $folderId] : []) }}">一覧を更新</a>
        </form>
        <form class="c-form c-form--panel" method="post" action="{{ route('drive.folder') }}">
            @csrf
            <input type="hidden" name="parent_id" value="{{ $folderId }}">
            <label class="c-form__field">新しいフォルダの名前
                <input type="text" name="name" required maxlength="180" placeholder="例：動画の素材">
            </label>
            <button class="c-button c-button--neutral" type="submit">フォルダを作成</button>
        </form>
    </div>
@else
    <p>原本は残ります。フォルダを復元すると、個別に削除していない中身も戻ります。</p>
@endif

<section class="p-drive__files" aria-label="{{ $trash ? 'ゴミ箱の項目' : 'ファイルとフォルダ' }}">
    @if ($items->isEmpty())
        <p class="c-empty">{{ $trash ? 'ゴミ箱に項目はありません。' : 'このフォルダは空です。' }}</p>
    @else
        <ul class="p-drive__grid">
            @foreach ($items as $item)
                <li class="c-item">
                    <p class="c-kicker">{{ $item->isFolder() ? 'フォルダ' : ($item->mime === 'video/mp4' ? '動画' : '画像') }}</p>
                    <h2>
                        @if (!$trash && $item->isFolder())
                            <a href="{{ route('drive.index', ['folder' => $item->id]) }}">{{ $item->name }}</a>
                        @else
                            {{ $item->name }}
                        @endif
                    </h2>
                    @if (!$item->isFolder())
                        <p class="c-item__size">{{ $formatSize($item->size_bytes) }}</p>
                    @endif
                    @if ($trash)
                        <form method="post" action="{{ route('drive.restore', $item->id) }}">
                            @csrf
                            <button class="c-button c-button--neutral" type="submit">復元</button>
                        </form>
                    @else
                        @unless ($item->isFolder())
                            <div class="c-item__links">
                                <a href="{{ route('drive.preview', $item->id) }}">原本をプレビュー</a>
                                <a href="{{ route('drive.download', $item->id) }}">ダウンロード</a>
                            </div>
                            @if ($item->mime === 'video/mp4')
                                <details class="c-item__preview" data-preview>
                                    <summary>この画面で再生</summary>
                                    <video controls preload="none" playsinline data-preview-src="{{ route('drive.preview', $item->id) }}" aria-label="{{ $item->name }}"></video>
                                    <noscript><p>JavaScriptなしでは上の原本プレビューを利用できます。</p></noscript>
                                </details>
                            @else
                                <a class="c-item__thumbnail" href="{{ route('drive.preview', $item->id) }}">
                                    <img src="{{ route('drive.preview', $item->id) }}" alt="{{ $item->name }} をプレビュー" loading="lazy" decoding="async">
                                </a>
                            @endif
                        @endunless
                        <details class="c-item__actions">
                            <summary>操作</summary>
                            <form class="c-form" method="post" action="{{ route('drive.rename', $item->id) }}">
                                @csrf
                                @method('PATCH')
                                <label class="c-form__field">新しい名前
                                    <input type="text" name="name" value="{{ $item->name }}" required maxlength="180">
                                </label>
                                <button class="c-button c-button--neutral" type="submit">名前を変更</button>
                            </form>
                            <form class="c-form" method="post" action="{{ route('drive.move', $item->id) }}">
                                @csrf
                                @method('PATCH')
                                <label class="c-form__field">移動先
                                    <select name="destination">
                                        <option value="">共有ドライブ</option>
                                        @foreach ($destinations as $destination)
                                            @if ($item->id !== $destination['id'])
                                                <option value="{{ $destination['id'] }}">{{ $destination['path'] }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </label>
                                <button class="c-button c-button--neutral" type="submit">移動</button>
                            </form>
                            <form method="post" action="{{ route('drive.trash', $item->id) }}">
                                @csrf
                                @method('DELETE')
                                <button class="c-button c-button--danger" type="submit">ゴミ箱へ移動</button>
                            </form>
                        </details>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($items->hasPages())
        <nav class="p-drive__pagination" aria-label="一覧のページ">
            @if ($items->previousPageUrl()) <a rel="prev" href="{{ $items->previousPageUrl() }}">前へ</a> @endif
            <span>{{ $items->currentPage() }} / {{ $items->lastPage() }}</span>
            @if ($items->nextPageUrl()) <a rel="next" href="{{ $items->nextPageUrl() }}">次へ</a> @endif
        </nav>
    @endif
</section>
@endsection
