<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The files attached to a support message: screenshots, videos and PDFs. What is accepted is decided from the CONTENT of the file (finfo, and for images a real decode), never
 * from its extension or the type the browser claims; anything else (executables, SVG, HTML, scripts, empty or corrupt files) is refused. The file is stored on the default
 * (private) disk under a path generated here, so a name sent by the person can never choose a path, and it is served only through `response()`, after the caller proved the
 * attachment belongs to a conversation and Workspace the user may read. Nothing is ever served as a page: `nosniff` and a sandboxing Content-Security-Policy go with every file.
 */
class MessageAttachments
{
    /** detected type => [kind, stored extension] */
    private const TYPES = [
        'image/png' => ['image', 'png'], 'image/jpeg' => ['image', 'jpg'], 'image/webp' => ['image', 'webp'],
        'video/mp4' => ['video', 'mp4'], 'video/webm' => ['video', 'webm'],
        'application/pdf' => ['pdf', 'pdf'],
    ];

    /** What the interface and the server both say about the limits, in one place. @return array{maxFiles: int, maxMb: array<string, int>, maxTotalMb: int, accept: string} */
    public static function limits(): array
    {
        return [
            'maxFiles' => (int) config('support.attachments.max_files'),
            'maxMb' => ['image' => (int) config('support.attachments.max_image_mb'), 'video' => (int) config('support.attachments.max_video_mb'), 'pdf' => (int) config('support.attachments.max_pdf_mb')],
            'maxTotalMb' => (int) config('support.attachments.max_total_mb'),
            'accept' => 'image/png,image/jpeg,image/webp,video/mp4,video/webm,application/pdf',
        ];
    }

    /**
     * Checks every file and returns what to store. Nothing is written here.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{file: UploadedFile, kind: string, mime: string, ext: string, name: string}>
     *
     * @throws ValidationException
     */
    public function inspect(array $files): array
    {
        $limits = self::limits();
        $fail = fn (string $message) => throw ValidationException::withMessages(['files' => $message]);

        if (count($files) > $limits['maxFiles']) {
            $fail("Puedes adjuntar hasta {$limits['maxFiles']} archivos por mensaje.");
        }

        $total = 0;
        $checked = [];

        foreach ($files as $file) {
            $name = $this->displayName($file->getClientOriginalName());

            if (! $file->isValid()) {
                $fail("No se pudo recibir «{$name}». Inténtalo de nuevo.");
            }

            $size = (int) $file->getSize();

            if ($size <= 0) {
                $fail("«{$name}» está vacío.");
            }

            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

            if (! isset(self::TYPES[$mime])) {
                $fail("«{$name}» no es un formato permitido. Usa PNG, JPG, WebP, MP4, WebM o PDF.");
            }

            [$kind, $ext] = self::TYPES[$mime];

            if ($size > $limits['maxMb'][$kind] * 1024 * 1024) {
                $fail("«{$name}» pesa más de {$limits['maxMb'][$kind]} MB, el máximo para ".['image' => 'imágenes', 'video' => 'videos', 'pdf' => 'PDF'][$kind].'.');
            }

            // A real image decodes; a file that only claims to be one (or is corrupt) does not.
            if ($kind === 'image' && @getimagesize($file->getRealPath()) === false) {
                $fail("«{$name}» no es una imagen válida.");
            }

            if ($kind === 'pdf' && ! str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-')) {
                $fail("«{$name}» no es un PDF válido.");
            }

            $total += $size;
            $checked[] = ['file' => $file, 'kind' => $kind, 'mime' => $mime, 'ext' => $ext, 'name' => $name];
        }

        if ($total > $limits['maxTotalMb'] * 1024 * 1024) {
            $fail("Los archivos de un mensaje no pueden superar {$limits['maxTotalMb']} MB en total.");
        }

        return $checked;
    }

    /**
     * Writes the checked files to the private disk and returns their rows' data. If a write fails, the ones already written are removed and the error is a validation error.
     *
     * @param  list<array{file: UploadedFile, kind: string, mime: string, ext: string, name: string}>  $checked
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function put(Conversation $conversation, array $checked): array
    {
        $written = [];

        try {
            foreach ($checked as $item) {
                $path = "workspaces/{$conversation->workspace_id}/support/{$conversation->id}/".Str::uuid().".{$item['ext']}";
                $stream = fopen($item['file']->getRealPath(), 'rb');

                if ($stream === false || ! Storage::put($path, $stream)) {
                    throw new \RuntimeException('storage');
                }

                is_resource($stream) && fclose($stream);
                $written[] = ['kind' => $item['kind'], 'mime' => $item['mime'], 'original_name' => $item['name'], 'size_bytes' => (int) $item['file']->getSize(), 'storage_path' => $path];
            }
        } catch (\Throwable) {
            $this->forget($written);

            throw ValidationException::withMessages(['files' => 'No se pudieron guardar los archivos. Inténtalo de nuevo; tu mensaje no se envió.']);
        }

        return $written;
    }

    /** @param  list<array<string, mixed>>  $written */
    public function attach(Message $message, array $written, ?User $by): void
    {
        foreach ($written as $row) {
            MessageAttachment::create($row + ['workspace_id' => $message->workspace_id, 'conversation_id' => $message->conversation_id, 'message_id' => $message->id, 'uploaded_by' => $by?->id]);
        }
    }

    /** Removes files that were written but never got a row (the message was a repeat, or saving failed). @param  list<array<string, mixed>>  $written */
    public function forget(array $written): void
    {
        foreach ($written as $row) {
            try {
                Storage::delete($row['storage_path']);
            } catch (\Throwable) {
                // the path is generated here and holds nothing else; a leftover is harmless and never reachable without a row
            }
        }
    }

    /** What the interface shows of an attachment; the `url` is a route that authorizes every read. @return array<string, mixed> */
    public static function present(MessageAttachment $attachment, string $url): array
    {
        return ['id' => $attachment->id, 'kind' => $attachment->kind, 'mime' => $attachment->mime, 'name' => $attachment->original_name, 'size' => $attachment->size_bytes, 'url' => $url];
    }

    /** The file, as a response that can never run as a page. The caller has ALREADY proved the attachment is the user's to read. */
    public function response(MessageAttachment $attachment): Response
    {
        abort_unless(Storage::exists($attachment->storage_path), 404, 'Este archivo ya no está disponible.');

        $disposition = $attachment->kind === 'pdf' || $attachment->kind === 'image' || $attachment->kind === 'video' ? 'inline' : 'attachment';

        return Storage::response($attachment->storage_path, $this->headerName($attachment), [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $disposition);
    }

    /** A name for display: no path, no control characters, bounded. */
    public function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F<>"\'`|?*:]+/u', '', $name));
        $name = mb_substr($name, -120);

        return $name === '' ? 'archivo' : $name;
    }

    /** The name in the Content-Disposition header: plain ASCII, so a crafted name can never break the header. */
    private function headerName(MessageAttachment $attachment): string
    {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($attachment->original_name)), '_.');

        return $ascii === '' ? 'archivo.'.pathinfo($attachment->storage_path, PATHINFO_EXTENSION) : $ascii;
    }
}
