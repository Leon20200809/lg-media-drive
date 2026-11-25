<?php
namespace App\Http\Controllers;

use App\Http\Responses\PrivateFileResponse;
use App\Models\MediaItem;
use App\Services\MediaLibrary;
use App\Services\ThumbnailStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DriveController
{
    public function __construct(
        private readonly MediaLibrary $library,
        private readonly ThumbnailStore $thumbnails,
    ) {}

    /** 一覧と移動先は同じ私用領域から取得する。 */
    public function index(Request $request): View
    {
        $input = $request->validate(['folder' => ['nullable', 'integer', 'min:1'], 'trash' => ['nullable', 'in:1']]);
        $folderId = isset($input['folder']) ? (int) $input['folder'] : null;
        $trash = isset($input['trash']);
        $parents = $trash ? collect() : $this->library->parents($folderId);
        $query = $trash ? MediaItem::onlyTrashed() : MediaItem::query()->where('parent_id', $folderId);
        $items = $query->with('creator:id,name')->orderByDesc('kind')->orderBy('name')->orderBy('id')->paginate(48)->withQueryString();
        $thumbnailIds = [];
        if (!$trash) {
            foreach ($items as $item) {
                if ($this->thumbnails->existing($item) !== null) {
                    $thumbnailIds[$item->id] = true;
                }
            }
        }

        return view('drive', [
            'items' => $items,
            'thumbnailIds' => $thumbnailIds,
            'parents' => $parents,
            'folderId' => $folderId,
            'trash' => $trash,
            'destinations' => $trash ? collect() : $this->library->destinations(),
            'usedBytes' => $this->library->usedBytes(),
            'quotaBytes' => (int) config('media.quota_bytes'),
        ]);
    }

    /** HTMLの単体送信と、JSの直列送信は同じ認証・検証を通す。 */
    public function upload(Request $request): JsonResponse|RedirectResponse
    {
        $input = $request->validate([
            'file' => ['required', 'file', 'max:'.max(config('media.image_max_kb'), config('media.video_max_kb'))],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $item = $this->library->upload($input['file'], $this->nullableId($input, 'parent_id'), (int) $request->user()->getAuthIdentifier());

        return $request->expectsJson()
            ? response()->json(['id' => $item->id, 'name' => $item->name], 201)
            : $this->folderRedirect($item->parent_id, 'アップロードしました。');
    }

    /** 認証後の原本表示。BinaryFileResponseが動画を区間単位で読み出す。 */
    public function preview(int $item): BinaryFileResponse
    {
        $file = $this->library->active($item);

        return new PrivateFileResponse($this->library->original($file), $file->mime);
    }

    /** 一覧用の縮小画像も原本と同じ認証・ゴミ箱境界で守る。 */
    public function thumbnail(int $item): BinaryFileResponse
    {
        $path = $this->thumbnails->existing($this->library->active($item));
        abort_if($path === null, 404);

        return new PrivateFileResponse($path, 'image/png');
    }

    /** ダウンロードはDBの表示名を使い、原本バイト列をそのまま返す。 */
    public function download(int $item): BinaryFileResponse
    {
        $file = $this->library->active($item);

        return new PrivateFileResponse($this->library->original($file), $file->mime, $this->library->downloadName($file));
    }

    /** フォルダを作成し、作成した階層へ戻る。 */
    public function folder(Request $request): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:180'], 'parent_id' => ['nullable', 'integer', 'min:1']]);
        $folder = $this->library->createFolder($input['name'], $this->nullableId($input, 'parent_id'), (int) $request->user()->getAuthIdentifier());

        return $this->folderRedirect($folder->parent_id, 'フォルダを作成しました。');
    }

    public function rename(Request $request, int $item): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:180']]);
        $record = $this->library->rename($item, $input['name']);

        return $this->folderRedirect($record->parent_id, '名前を変更しました。');
    }

    public function move(Request $request, int $item): RedirectResponse
    {
        $input = $request->validate(['destination' => ['nullable', 'integer', 'min:1']]);
        $record = $this->library->move($item, $this->nullableId($input, 'destination'));

        return $this->folderRedirect($record->parent_id, '移動しました。');
    }

    public function trash(int $item): RedirectResponse
    {
        $record = $this->library->trash($item);

        return $this->folderRedirect($record->parent_id, 'ゴミ箱へ移動しました。');
    }

    public function restore(int $item): RedirectResponse
    {
        $this->library->restore($item);

        return redirect()->route('drive.index', ['trash' => 1])->with('notice', '復元しました。');
    }

    private function nullableId(array $input, string $key): ?int
    {
        return isset($input[$key]) ? (int) $input[$key] : null;
    }

    private function folderRedirect(?int $folderId, string $message): RedirectResponse
    {
        return redirect()->route('drive.index', $folderId ? ['folder' => $folderId] : [])->with('notice', $message);
    }
}
