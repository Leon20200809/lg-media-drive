<?php
namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class CreateDevelopmentUser extends Command
{
    protected $signature = 'media:dev-user {email=media@example.test} {--name=開発ユーザー} {--reset}';
    protected $description = '開発専用ユーザーを作り、秘密値をローカルの除外ファイルへ保存する';

    /** パスワードは標準出力に載せず、開発用ファイルにのみ追記する。 */
    public function handle(): int
    {
        if (!app()->environment('local')) {
            $this->error('開発環境専用です。');
            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('有効なメールアドレスを指定してください。');
            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        if ($user->exists && !$this->option('reset')) {
            $this->error('作成済みです。再生成が必要なら --reset を指定してください。');
            return self::FAILURE;
        }

        $path = base_path('.dev-credentials');
        $oldUmask = umask(0077);
        try {
            $handle = fopen($path, 'ab');
            if ($handle === false || !chmod($path, 0600)) {
                throw new \RuntimeException('ローカルの認証情報ファイルを書き込めません。');
            }
            $password = Str::random(32);
            $user->fill(['name' => $this->option('name'), 'password' => Hash::make($password)]);
            $user->save();
            try {
                $line = $email.' '.$password.PHP_EOL;
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new \RuntimeException('認証情報を保存できません。');
                }
            } finally {
                fclose($handle);
            }
        } finally {
            umask($oldUmask);
        }

        $this->info('認証情報は .dev-credentials に保存しました。同じメールは末尾の行を使ってください。');
        return self::SUCCESS;
    }
}
