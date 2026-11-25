<?php
namespace App\Services;

use App\Models\MediaItem;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class MediaLibrary
{
    private const VIDEO = 'video/mp4';
    private const IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public function __construct(private readonly ThumbnailStore $thumbnails) {}

    /** ロック行を先に取得し、容量確認・階層変更を同時実行から守る。 */
    private function change(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            if (!DB::table('media_locks')->where('id', 1)->lockForUpdate()->first()) {
                throw new RuntimeException('共有領域の初期化が必要です。');
            }

            return $operation();
        });
    }

    /** ゴミ箱の原本も残るため、削除済みを除外せずに集計する。 */
    public function usedBytes(): int
    {
        return (int) MediaItem::withTrashed()->where('kind', 'file')->sum('size_bytes');
    }

    /** 親がゴミ箱へ移動した項目も、一覧や直接URLから参照させない。 */
    public function active(int $id): MediaItem
    {
        $item = MediaItem::query()->findOrFail($id);
        $this->parents($item->parent_id);

        return $item;
    }

    /** 親フォルダ列をルート順で取得する。壊れた階層は開かない。 */
    public function parents(?int $id): Collection
    {
        $parents = collect();
        $seen = [];
        while ($id !== null) {
            abort_if(isset($seen[$id]), 404);
            $seen[$id] = true;
            $folder = MediaItem::query()->where('kind', 'folder')->findOrFail($id);
            $parents->prepend($folder);
            $id = $folder->parent_id;
        }

        return $parents;
    }

    /** 移動の候補は通常の一覧に存在するフォルダのみにする。 */
    public function destinations(): Collection
    {
        $all = MediaItem::query()->where('kind', 'folder')->orderBy('name')->get()->keyBy('id');

        return $all->map(function (MediaItem $folder) use ($all) {
            $names = [$folder->name];
            $seen = [$folder->id => true];
            for ($id = $folder->parent_id; $id !== null; $id = $parent->parent_id) {
                $parent = $all->get($id);
                if (!$parent || isset($seen[$id])) {
                    return null;
                }
                $seen[$id] = true;
                array_unshift($names, $parent->name);
            }

            return ['id' => $folder->id, 'path' => implode(' / ', $names)];
        })->filter()->values();
    }

    /** 公開領域外の、表示名とは無関係な原本パスを返す。 */
    public function original(MediaItem $item): string
    {
        abort_unless(!$item->isFolder() && preg_match('/\A[a-f0-9]{48}\z/', $item->storage_key ?? ''), 404);
        $path = $this->directory().DIRECTORY_SEPARATOR.$item->storage_key;
        abort_unless(is_file($path), 404);

        return $path;
    }

    /** 添付名の拡張子が実形式と異なる場合は、実形式の拡張子を末尾に付ける。 */
    public function downloadName(MediaItem $item): string
    {
        $extension = match ($item->mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            self::VIDEO => 'mp4',
        };
        $given = strtolower(pathinfo($item->name, PATHINFO_EXTENSION));

        return $given === $extension || ($extension === 'jpg' && $given === 'jpeg')
            ? $item->name
            : $item->name.'.'.$extension;
    }

    /** 実MIMEとサイズを検証し、登録に失敗した原本は補償削除する。 */
    public function upload(UploadedFile $file, ?int $parentId, int $creatorId): MediaItem
    {
        $mime = $file->getMimeType();
        if ($mime !== self::VIDEO && !in_array($mime, self::IMAGES, true)) {
            $this->invalid('file', 'JPEG、PNG、GIF、WebP、AVIF、MP4のいずれかを選んでください。SVGは登録できません。');
        }
        $maxKb = (int) config($mime === self::VIDEO ? 'media.video_max_kb' : 'media.image_max_kb');
        $bytes = $file->getSize();
        if ($bytes === false || $bytes <= 0 || $bytes > $maxKb * 1024) {
            $this->invalid('file', 'ファイルのサイズが上限を超えているか、空です。');
        }
        $name = $this->cleanName($file->getClientOriginalName(), 'file');
        $directory = $this->directory();
        $key = bin2hex(random_bytes(24));
        $path = $directory.DIRECTORY_SEPARATOR.$key;
        $committed = false;
        try {
            $item = $this->change(function () use ($file, $parentId, $creatorId, $mime, $bytes, $name, $directory, $key, $path) {
                $this->parents($parentId);
                if ($bytes > (int) config('media.quota_bytes') - $this->usedBytes()) {
                    $this->invalid('file', '共有容量の上限に達しました。ゴミ箱の原本も使用量に含まれます。');
                }
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException('原本の保管先を作成できません。');
                }
                $file->move($directory, $key);
                if (!chmod($path, 0600)) {
                    throw new RuntimeException('原本を保護できません。');
                }

                $this->thumbnails->create($path, $key, $mime);

                return MediaItem::create([
                    'parent_id' => $parentId,
                    'created_by' => $creatorId,
                    'kind' => 'file',
                    'name' => $name,
                    'storage_key' => $key,
                    'mime' => $mime,
                    'size_bytes' => $bytes,
                ]);
            });
            $committed = true;

            return $item;
        } finally {
            if (!$committed) {
                $this->thumbnails->discard($key);
                if (is_file($path) && !unlink($path)) {
                    report(new RuntimeException('失敗したアップロードの原本を削除できません。'));
                }
            }
        }
    }

    /** フォルダを通常領域に追加する。 */
    public function createFolder(string $name, ?int $parentId, int $creatorId): MediaItem
    {
        return $this->change(function () use ($name, $parentId, $creatorId) {
            $this->parents($parentId);

            return MediaItem::create(['name' => $this->cleanName($name), 'parent_id' => $parentId, 'kind' => 'folder', 'created_by' => $creatorId]);
        });
    }

    /** DB上の表示名だけを更新する。原本キーは変更しない。 */
    public function rename(int $id, string $name): MediaItem
    {
        return $this->change(function () use ($id, $name) {
            $item = $this->active($id);
            $item->update(['name' => $this->cleanName($name)]);

            return $item;
        });
    }

    /** 自分自身や子孫への移動を拒み、元の順路を保護する。 */
    public function move(int $id, ?int $destination): MediaItem
    {
        return $this->change(function () use ($id, $destination) {
            $item = $this->active($id);
            $parents = $this->parents($destination);
            if ($parents->contains(fn (MediaItem $folder) => $folder->id === $item->id)) {
                $this->invalid('destination', 'フォルダを自分自身やその中のフォルダへ移動できません。');
            }
            $item->update(['parent_id' => $destination]);

            return $item;
        });
    }

    /** ゴミ箱へ移す。フォルダを戻すまで、その配下の直接参照も不可とする。 */
    public function trash(int $id): MediaItem
    {
        return $this->change(function () use ($id) {
            $item = $this->active($id);
            $item->delete();

            return $item;
        });
    }

    /** ゴミ箱から戻す。親もゴミ箱なら先に親を復元する。 */
    public function restore(int $id): MediaItem
    {
        return $this->change(function () use ($id) {
            $item = MediaItem::onlyTrashed()->findOrFail($id);
            try {
                $this->parents($item->parent_id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->invalid('restore', '先に親フォルダを復元してください。');
            }
            $item->restore();

            return $item;
        });
    }

    /** ファイル名・フォルダ名にパス区切りや制御文字を許可しない。 */
    private function cleanName(string $name, string $field = 'name'): string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..' || mb_strlen($name) > 180
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/u', $name)) {
            $this->invalid($field, '名前は180文字以内で、記号 / やバックラッシュ、制御文字を含めず入力してください。');
        }

        return $name;
    }

    private function directory(): string
    {
        return (string) config('media.root');
    }

    private function invalid(string $field, string $text): never
    {
        throw ValidationException::withMessages([$field => $text]);
    }
}
