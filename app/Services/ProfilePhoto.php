<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The personal photo of an account. It is kept on the application's private disk (S3/MinIO in Docker) under a path made only from the account's
 * id, so the table of users needs no new column and no path ever comes from the client; it is served through an authenticated route that only
 * ever answers for the signed-in account, so the bucket stays private and nobody can ask for another person's photo. Every upload is DECODED and
 * RE-ENCODED (cropped to a square, 512 px, JPEG): whatever else the file carried (metadata, embedded scripts, a disguised type) is dropped.
 * Whether a photo exists is remembered for an hour so the top bar does not ask the disk on every request; the answer is also corrected whenever
 * the photo changes.
 */
class ProfilePhoto
{
    public const SIZE = 512;

    public const MAX_KB = 2048;

    public const MAX_SIDE = 3000;

    public function path(User $user): string
    {
        return "profile-photos/{$user->getKey()}.jpg";
    }

    public function exists(User $user): bool
    {
        return (bool) Cache::remember("profile-photo:exists:{$user->getKey()}", 3600, fn () => Storage::exists($this->path($user)));
    }

    /** The URL of the own photo, or null without one. The version changes with each upload so the browser never shows a replaced photo. */
    public function url(User $user): ?string
    {
        return $this->exists($user) ? route('profile.photo', ['v' => Cache::get("profile-photo:version:{$user->getKey()}", 1)], absolute: false) : null;
    }

    /** @throws ValidationException when the file is not an image this server can decode */
    public function store(User $user, UploadedFile $file): void
    {
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['photo' => 'Este servidor no puede procesar imágenes (falta la extensión GD).']);
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw ValidationException::withMessages(['photo' => 'No se pudo leer la imagen. Usa un archivo JPG, PNG o WebP válido.']);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        // A transparent PNG is flattened on white: the result is always an opaque JPEG.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($canvas, null, 85);
        $encoded = (string) ob_get_clean();

        Storage::put($this->path($user), $encoded);
        $this->changed($user, true);
    }

    public function remove(User $user): void
    {
        Storage::delete($this->path($user));
        $this->changed($user, false);
    }

    /** The photo of the SIGNED-IN account only: the controller passes the request's own user, never an id from the client. */
    public function response(User $user, Request $request): Response|StreamedResponse
    {
        $path = $this->path($user);
        abort_unless(Storage::exists($path), 404);

        $headers = [
            'Cache-Control' => 'private, no-cache',
            'ETag' => '"'.sha1($path.Storage::lastModified($path)).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => 'image/jpeg',
        ];

        if ($request->header('If-None-Match') === $headers['ETag']) {
            return response('', 304, $headers);
        }

        return Storage::response($path, null, $headers);
    }

    private function changed(User $user, bool $exists): void
    {
        Cache::put("profile-photo:exists:{$user->getKey()}", $exists, 3600);
        Cache::forever("profile-photo:version:{$user->getKey()}", (int) Cache::get("profile-photo:version:{$user->getKey()}", 1) + 1);
    }
}
