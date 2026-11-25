<?php
namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AuthController
{
    /** 既存のチームユーザーにだけログインフォームを提供する。 */
    public function show(): View
    {
        return view('login');
    }

    /** 認証後はセッションIDを再生成し、既存IDを引き継がない。 */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'メールアドレスまたはパスワードが一致しません。']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('drive.index'));
    }

    /** ログアウト時はセッションとCSRFトークンを破棄する。 */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
