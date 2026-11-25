<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class MediaItem extends Model
{
    use SoftDeletes;

    protected $fillable = ['parent_id', 'kind', 'name', 'storage_key', 'mime', 'size_bytes'];
    protected $hidden = ['storage_key'];

    /** 一覧のフォルダを原本のある項目と区別する。 */
    public function isFolder(): bool
    {
        return $this->kind === 'folder';
    }
}
