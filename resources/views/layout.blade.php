<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | LG Media Drive</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<a class="u-skip-link" href="#main-content">本文へ</a>
<header class="l-header">
    <a class="c-brand" href="{{ route('drive.index') }}">LG<span> / </span>Media Drive</a>
    @auth
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="c-button c-button--neutral" type="submit">ログアウト</button>
        </form>
    @endauth
</header>
<main id="main-content" class="l-content" tabindex="-1">
    @if (session('notice'))
        <p class="c-notice" role="status">{{ session('notice') }}</p>
    @endif
    @if ($errors->any())
        <div class="c-notice c-notice--error" role="alert">
            <strong>操作を完了できませんでした。</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
