<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an image stored on the default disk (S3/MinIO in Docker) through the app, so the bucket stays private and
 * the file only reaches whoever the calling controller already authorized. The same URL can return a different image
 * per Workspace, so the browser may keep a copy (ETag) but must revalidate it with the server on every use
 * (`no-cache`): a copy cached for one session is never shown to another, because the server answers by the
 * requester's own Workspace.
 */
class PrivateImage
{
    public static function response(?string $path, Request $request): Response|StreamedResponse
    {
        abort_unless($path && Storage::exists($path), 404);

        $headers = [
            'Cache-Control' => 'private, no-cache',
            'ETag' => '"'.sha1($path).'"',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->header('If-None-Match') === $headers['ETag']) {
            return response('', 304, $headers);
        }

        return Storage::response($path, null, $headers);
    }
}
