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
// 単色の有効なPNG。縮小サイズと、許可範囲を超える幅のフォールバックを見る。
$makePng = static function (int $width, int $height): string {
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $row = "\0".str_repeat("\x2a\x69\x93", $width);
    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress(str_repeat($row, $height), 6))
        .$chunk('IEND', '');
};
$png = $makePng(1, 1);
file_put_contents($fixtureDir.'/test.png', $png);
file_put_contents($fixtureDir.'/large.png', $png.str_repeat("\0", 4096));
file_put_contents($fixtureDir.'/unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle r="2"/></svg>');
file_put_contents($fixtureDir.'/imposter.png', 'not a PNG');

file_put_contents($fixtureDir.'/large-enough.png', $makePng(640, 480));
file_put_contents($fixtureDir.'/wide.png', $makePng(8193, 1));

// 最初の2マイグレーションだけが当たった状態で、登録者列のない古い原本を用意する。
$key = str_repeat('ee', 24);
$root = config('media.root');
mkdir($root, 0700, true);
file_put_contents($root.'/'.$key, $png);
$app->make('db')->table('media_items')->insert([
    'kind' => 'file',
    'name' => 'legacy.png',
    'storage_key' => $key,
    'mime' => 'image/png',
    'size_bytes' => strlen($png),
]);
