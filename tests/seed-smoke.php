<?php
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// 一時SQLiteだけにテストユーザーを作成し、実アカウントに触れない。
$fixtureDir = $argv[1] ?? '';
if (!in_array(config('database.default'), ['sqlite'], true)
    || !is_dir($fixtureDir)
    || config('database.connections.sqlite.database') !== $fixtureDir.'/db.sqlite') {
    throw new LogicException('Smoke seed requires a scratch SQLite database.');
}
$password = bin2hex(random_bytes(16));
foreach (['first', 'second'] as $user) {
    User::create(['name' => $user, 'email' => $user.'@example.test', 'password' => Hash::make($password)]);
}
file_put_contents($fixtureDir.'/password', $password);
// 1pxサンプルを合成し、利用者の実原本を複製しない。
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+W2ncAAAAASUVORK5CYII=', true);
file_put_contents($fixtureDir.'/test.png', $png);
file_put_contents($fixtureDir.'/large.png', $png.str_repeat("\0", 1024));
file_put_contents($fixtureDir.'/unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle r="2"/></svg>');
file_put_contents($fixtureDir.'/imposter.png', 'not a PNG');
