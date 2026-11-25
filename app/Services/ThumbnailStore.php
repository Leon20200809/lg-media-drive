<?php
namespace App\Services;

use App\Models\MediaItem;
use Throwable;

final class ThumbnailStore
{
    private const DECODERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/gif' => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
        'image/avif' => 'imagecreatefromavif',
    ];
    private const MAX_SOURCE_SIDE = 8192;
    private const MAX_SOURCE_PIXELS = 8_000_000;
    private const MAX_WIDTH = 320;
    private const MAX_HEIGHT = 240;
    private const MAX_BYTES = 320 * 1024;

    /** 一覧・配信の双方で、存在する私用の派生画像だけを使う。 */
    public function existing(MediaItem $item): ?string
    {
        if ($item->isFolder() || !isset(self::DECODERS[$item->mime])) {
            return null;
        }
        $path = $this->path((string) $item->storage_key);

        return $path !== null && is_file($path) ? $path : null;
    }

    /** 元寸法をデコード前に制限し、縮小PNGを一時ファイルから公開外へ保存する。 */
    public function create(string $source, string $key, string $mime): void
    {
        $decoder = self::DECODERS[$mime] ?? null;
        $target = $this->path($key);
        if (!$decoder || !function_exists($decoder) || !$target || !function_exists('imagepng')) {
            return;
        }

        $temporary = $target.'.tmp';
        try {
            $size = @getimagesize($source);
            if ($size === false || $size['mime'] !== $mime) {
                return;
            }
            [$width, $height] = $size;
            if ($width < 1 || $height < 1 || max($width, $height) > self::MAX_SOURCE_SIDE
                || $width > intdiv(self::MAX_SOURCE_PIXELS, $height)) {
                return;
            }

            $original = @$decoder($source);
            if ($original === false || imagesx($original) !== $width || imagesy($original) !== $height) {
                return;
            }
            $scale = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height, 1);
            $outWidth = max(1, (int) floor($width * $scale));
            $outHeight = max(1, (int) floor($height * $scale));
            $thumbnail = imagecreatetruecolor($outWidth, $outHeight);
            if ($thumbnail === false) {
                return;
            }
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            if (!imagecopyresampled($thumbnail, $original, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height)) {
                return;
            }

            $directory = dirname($target);
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                return;
            }
            if (@imagepng($thumbnail, $temporary, 3)) {
                $bytes = @filesize($temporary);
                if ($bytes !== false && $bytes > 0 && $bytes <= self::MAX_BYTES && @chmod($temporary, 0600)) {
                    @rename($temporary, $target);
                }
            }
        } catch (Throwable $error) {
            // 派生画像が作れなくても原本の登録を優先する。
            report($error);
        } finally {
            $this->removeIfPresent($temporary);
        }
    }

    /** 原本をDB登録できなかった場合だけ、同じキーの派生画像を捨てる。 */
    public function discard(string $key): void
    {
        if ($path = $this->path($key)) {
            $this->removeIfPresent($path);
        }
    }

    private function path(string $key): ?string
    {
        if (!preg_match('/\A[a-f0-9]{48}\z/', $key)) {
            return null;
        }

        return config('media.root').DIRECTORY_SEPARATOR.'thumbnails'.DIRECTORY_SEPARATOR.$key.'.png';
    }

    private function removeIfPresent(string $path): void
    {
        clearstatcache(true, $path);
        if (is_file($path) && !@unlink($path)) {
            report(new \RuntimeException('不要な縮小画像ファイルを削除できません。'));
        }
    }
}
