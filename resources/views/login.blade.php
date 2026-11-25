@extends('layout')
@section('title', 'ログイン')
@section('content')
<section class="p-login" aria-labelledby="login-title">
    <p class="c-kicker">TEAM WORKSPACE</p>
    <h1 id="login-title">共有ドライブへ</h1>
    <p>チーム用アカウントでログインしてください。</p>
    <form class="c-form" method="post" action="{{ route('login.store') }}">
        @csrf
        <label class="c-form__field">メールアドレス
            <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
        </label>
        <label class="c-form__field">パスワード
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="c-button" type="submit">ログイン</button>
    </form>
</section>
@endsection
