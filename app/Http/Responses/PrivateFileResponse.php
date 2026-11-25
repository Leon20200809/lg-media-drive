<?php
namespace App\Http\Responses;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PrivateFileResponse extends BinaryFileResponse
{
    /** 原本をメモリに展開せず、私用かつ保存不可として配信する。 */
    public function __construct(string $path, string $mime, ?string $downloadName = null)
    {
        parent::__construct($path, 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], public: false);

        if ($downloadName !== null) {
            $fallback = str_replace('%', '', Str::ascii($downloadName));
            $this->setContentDisposition('attachment', $downloadName, $fallback === '' ? 'download' : $fallback);
        }
    }

    /** Symfonyのストリームは範囲外で空なので、416の本文サイズも0と明示する。 */
    public function prepare(Request $request): static
    {
        parent::prepare($request);
        if ($this->getStatusCode() === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
            $this->headers->set('Content-Length', '0');
        }

        return $this;
    }
}
