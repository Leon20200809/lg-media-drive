<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class MediaItem extends Model
{
    use SoftDeletes;

    protected $fillable = ['parent_id', 'kind', 'name', 'storage_key', 'mime', 'size_bytes', 'created_by'];
    protected $hidden = ['storage_key'];

    /** 登録者は表示だけに使い、編集権限の判定に使わない。 */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** 一覧のフォルダを原本のある項目と区別する。 */
    public function isFolder(): bool
    {
        return $this->kind === 'folder';
    }
}
