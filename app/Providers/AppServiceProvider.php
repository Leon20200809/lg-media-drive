<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /** ログイン試行を抑え、共有メディアへの入口を保護する。 */
    public function boot(): void
    {
        // ルートの throttle ミドルウェアで認証前のIPごとの試行を制限する。
    }
}
