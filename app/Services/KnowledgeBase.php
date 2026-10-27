<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Models\Chatbot;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * The knowledge base of the assistants: the only writer and the only reader of `knowledge_*`.
 *
 * What it really does, and what it does not:
 *  - It accepts txt, md, csv and docx, checks the REAL content (not only the name: a binary renamed to .txt, a .docx that is not a zip with `word/document.xml`, an empty or
 *    huge text are refused or marked as error) and extracts the text itself. A PDF is refused with an explanation: this stack has no PDF extractor, and storing a file that
 *    cannot be read would only pretend it was learned.
 *  - Extraction is synchronous and bounded (5 MB, 400 000 characters): no queue is involved, so there is nothing to retry out of band. The state is `pending` → `processing`
 *    → `ready` | `error`; a document in `error` can be retried (at most `max_attempts`), and a second concurrent attempt on the same document does nothing (the claim is atomic).
 *  - The text is split into passages and searched with PostgreSQL full-text search (Spanish). There are no embeddings and no model: it finds passages that share words
 *    with the question. The assistant (the n8n workflow) calls `GET /api/agent/knowledge/search` and decides what to say; Zonno only returns passages with their source.
 *  - A document is only retrieved by the assistants it is linked to, and every query starts from the Workspace. Deleting a document deletes its passages, its links and the
 *    stored file.
 */
class KnowledgeBase
{
    /** Fixed codes the interface explains (never provider or exception text). */
    public const ERRORS = [
        'empty' => 'El documento no tiene texto que se pueda leer.',
        'unreadable' => 'No se pudo leer el contenido del documento. Revisa que el archivo no esté dañado y vuelve a intentarlo.',
        'too_long' => 'El texto del documento es demasiado largo. Divídelo en varios documentos.',
        'missing_file' => 'El archivo guardado ya no está disponible. Elimina el documento y súbelo de nuevo.',
    ];

    private ?bool $ready = null;

    public function __construct(private readonly AuditLogger $audit) {}

    /** Whether the tables exist (the code can reach an environment before its migration): until then the feature is absent and nothing else changes. */
    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('knowledge_documents') && Schema::hasTable('knowledge_chunks') && Schema::hasTable('knowledge_document_chatbot');
    }

    /**
     * Validates, stores and processes one file. A refused file raises a validation error on `file`; a file that was accepted but whose text cannot be extracted is kept
     * with the status `error` so the person sees why.
     */
    public function upload(Workspace $workspace, ?User $by, UploadedFile $file, ?string $title): KnowledgeDocument
    {
        $config = config('chatbots.knowledge');
        $name = mb_substr(basename((string) $file->getClientOriginalName()), 0, 255);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            $this->refuse('Los PDF todavía no se pueden leer: Zonno no tiene un extractor de PDF instalado, y guardarlo sin poder leerlo no serviría de nada. Conviértelo a .docx o .txt y súbelo de nuevo.');
        }

        if (! in_array($extension, $config['extensions'], true)) {
            $this->refuse('Formato no admitido. Sube un archivo '.implode(', ', array_map(fn ($e) => ".{$e}", $config['extensions'])).'.');
        }

        if (! $file->isValid() || (int) $file->getSize() > $config['max_kb'] * 1024) {
            $this->refuse("El archivo es demasiado grande (máximo {$config['max_kb']} KB) o no se recibió completo.");
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $this->assertContent($extension, $bytes);

        if (KnowledgeDocument::where('workspace_id', $workspace->id)->count() >= $config['max_documents']) {
            $this->refuse("Se alcanzó el máximo de {$config['max_documents']} documentos. Elimina alguno para subir otro.");
        }

        $sha = hash('sha256', $bytes);

        if (KnowledgeDocument::where('workspace_id', $workspace->id)->where('sha256', $sha)->exists()) {
            $this->refuse('Este mismo archivo ya está en la base de conocimiento.');
        }

        $path = "workspaces/{$workspace->id}/knowledge/".bin2hex(random_bytes(16)).".{$extension}";
        Storage::put($path, $bytes);

        try {
            $document = KnowledgeDocument::create([
                'workspace_id' => $workspace->id,
                'title' => mb_substr(trim((string) $title) !== '' ? trim((string) $title) : pathinfo($name, PATHINFO_FILENAME), 0, 160),
                'original_name' => $name,
                'extension' => $extension,
                'mime' => mb_substr((string) $file->getClientMimeType(), 0, 100) ?: null,
                'size_bytes' => strlen($bytes),
                'sha256' => $sha,
                'storage_path' => $path,
                'status' => KnowledgeDocument::PENDING,
                'uploaded_by' => $by?->id,
            ]);
        } catch (Throwable $e) {
            // A concurrent upload of the same file lost the unique key (or the row could not be written): no orphan file is left behind.
            Storage::delete($path);

            throw $e;
        }

        $this->audit->record('created', 'knowledge_document', $document->id, $document->title, [AuditLogger::change('Archivo', null, $name)], $workspace);

        return $this->process($document);
    }

    /** Extracts and indexes a pending or failed document. A document that another request is already processing, or that is ready, is returned untouched. */
    public function process(KnowledgeDocument $document): KnowledgeDocument
    {
        $claimed = KnowledgeDocument::where('id', $document->id)->where('workspace_id', $document->workspace_id)
            ->whereIn('status', [KnowledgeDocument::PENDING, KnowledgeDocument::ERROR])
            ->update(['status' => KnowledgeDocument::PROCESSING, 'error_code' => null, 'attempts' => DB::raw('attempts + 1')]);

        if ($claimed === 0) {
            return $document->refresh();
        }

        try {
            $bytes = $document->storage_path && Storage::exists($document->storage_path) ? (string) Storage::get($document->storage_path) : null;

            if ($bytes === null) {
                return $this->fail($document, 'missing_file');
            }

            $text = $this->extract($document->extension, $bytes);

            if ($text === '') {
                return $this->fail($document, 'empty');
            }

            if (mb_strlen($text) > config('chatbots.knowledge.max_text_chars')) {
                return $this->fail($document, 'too_long');
            }

            $chunks = $this->chunk($text);

            DB::transaction(function () use ($document, $chunks, $text) {
                DB::table('knowledge_chunks')->where('document_id', $document->id)->where('workspace_id', $document->workspace_id)->delete();

                foreach (array_chunk($chunks, 100, true) as $part) {
                    DB::table('knowledge_chunks')->insert(array_map(fn ($content, $position) => [
                        'workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'position' => $position + 1, 'content' => $content,
                    ], $part, array_keys($part)));
                }

                KnowledgeDocument::where('id', $document->id)->update([
                    'status' => KnowledgeDocument::READY, 'error_code' => null, 'chunks_count' => count($chunks), 'text_chars' => mb_strlen($text), 'processed_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('knowledge.process_failed', ['document' => $document->id, 'exception' => $e::class]);

            return $this->fail($document, 'unreadable');
        }

        return $document->refresh();
    }

    /** Retries a document in error, up to the attempts allowed. */
    public function retry(KnowledgeDocument $document): KnowledgeDocument
    {
        if ($document->status !== KnowledgeDocument::ERROR) {
            $this->refuse('Solo se puede reintentar un documento con error.');
        }

        if ($document->attempts >= config('chatbots.knowledge.max_attempts')) {
            $this->refuse('Se agotaron los reintentos. Elimina el documento y súbelo de nuevo.');
        }

        $result = $this->process($document);
        $this->audit->record('updated', 'knowledge_document', $document->id, $document->title, [AuditLogger::change('Procesamiento', 'Con error', $result->status === KnowledgeDocument::READY ? 'Listo' : 'Con error')], Workspace::findOrFail($document->workspace_id));

        return $result;
    }

    /** Deletes the document with its passages, its links and the stored file. */
    public function delete(KnowledgeDocument $document): void
    {
        $path = $document->storage_path;
        $workspace = Workspace::findOrFail($document->workspace_id);

        DB::transaction(function () use ($document) {
            DB::table('knowledge_document_chatbot')->where('document_id', $document->id)->where('workspace_id', $document->workspace_id)->delete();
            DB::table('knowledge_chunks')->where('document_id', $document->id)->where('workspace_id', $document->workspace_id)->delete();
            $document->delete();
        });

        $this->audit->record('deleted', 'knowledge_document', $document->id, $document->title, [AuditLogger::change('Archivo', $document->original_name, null)], $workspace);

        if ($path) {
            try {
                Storage::delete($path);
            } catch (Throwable $e) {
                Log::warning('knowledge.file_delete_failed', ['document' => $document->id, 'exception' => $e::class]);
            }
        }
    }

    /**
     * Sets which assistants of the Workspace may use the document. Assistants of another Workspace are ignored (and the composite foreign key would refuse them anyway).
     *
     * @param  list<int>  $chatbotIds
     */
    public function assign(KnowledgeDocument $document, array $chatbotIds): void
    {
        $valid = Chatbot::where('workspace_id', $document->workspace_id)->whereIn('id', $chatbotIds)->pluck('id')->all();
        $before = $document->chatbots()->orderBy('chatbots.name')->pluck('chatbots.name')->implode(', ');

        $document->chatbots()->sync(array_fill_keys($valid, ['workspace_id' => $document->workspace_id]));

        $after = $document->chatbots()->orderBy('chatbots.name')->pluck('chatbots.name')->implode(', ');
        $this->audit->record('updated', 'knowledge_document', $document->id, $document->title, [AuditLogger::change('Asistentes con acceso', $before ?: 'Ninguno', $after ?: 'Ninguno')], Workspace::findOrFail($document->workspace_id));
    }

    /**
     * Passages that answer a question, for one assistant: only `ready` documents linked to it, in its Workspace. Returns the passages with their source and records
     * which ones were returned (ids only). The text is reference material from the Workspace's own documents, never instructions.
     *
     * @return list<array{document_id: int, document: string, file: string, chunk: int, text: string}>
     */
    public function search(Chatbot $chatbot, string $query): array
    {
        $terms = $this->terms($query);

        if (! $this->ready() || $terms === []) {
            return [];
        }

        $limit = (int) config('chatbots.knowledge.search_limit');
        $rows = DB::table('knowledge_chunks as c')
            ->join('knowledge_documents as d', fn ($j) => $j->on('d.id', '=', 'c.document_id')->on('d.workspace_id', '=', 'c.workspace_id'))
            ->join('knowledge_document_chatbot as l', fn ($j) => $j->on('l.document_id', '=', 'd.id')->on('l.workspace_id', '=', 'd.workspace_id'))
            ->where('c.workspace_id', $chatbot->workspace_id)
            ->where('l.chatbot_id', $chatbot->id)
            ->where('d.status', KnowledgeDocument::READY)
            ->select('c.id as chunk_id', 'c.position', 'c.content', 'd.id as document_id', 'd.title', 'd.original_name')
            ->limit($limit);

        if (DB::getDriverName() === 'pgsql') {
            $tsquery = implode(' or ', $terms);
            $rows->whereRaw("to_tsvector('spanish', c.content) @@ websearch_to_tsquery('spanish', ?)", [$tsquery])
                ->orderByRaw("ts_rank(to_tsvector('spanish', c.content), websearch_to_tsquery('spanish', ?)) desc", [$tsquery])->orderBy('c.id');
        } else {
            $rows->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhere('c.content', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%');
                }
            })->orderBy('c.id');
        }

        $found = $rows->get();

        if ($found->isNotEmpty()) {
            DB::table('knowledge_retrievals')->insert([
                'workspace_id' => $chatbot->workspace_id,
                'chatbot_id' => $chatbot->id,
                'query_chars' => mb_strlen($query),
                'sources' => json_encode($found->map(fn ($r) => ['document_id' => $r->document_id, 'chunk_id' => $r->chunk_id])->all()),
                'created_at' => now(),
            ]);
        }

        return $found->map(fn ($r) => [
            'document_id' => (int) $r->document_id, 'document' => $r->title, 'file' => $r->original_name, 'chunk' => (int) $r->position, 'text' => $r->content,
        ])->all();
    }

    /**
     * The documents of the Workspace for the page of an assistant, with their state and whether this assistant may use each one.
     *
     * @return list<array<string, mixed>>
     */
    public function forPage(Workspace $workspace, Chatbot $chatbot): array
    {
        if (! $this->ready()) {
            return [];
        }

        $linked = DB::table('knowledge_document_chatbot')->where('workspace_id', $workspace->id)->where('chatbot_id', $chatbot->id)->pluck('document_id')->flip();

        return KnowledgeDocument::where('workspace_id', $workspace->id)->orderByDesc('id')->get()->map(fn (KnowledgeDocument $d) => [
            'id' => $d->id,
            'title' => $d->title,
            'file' => $d->original_name,
            'size' => $this->size($d->size_bytes),
            'status' => $d->status,
            'error' => $d->error_code ? (self::ERRORS[$d->error_code] ?? 'No se pudo procesar el documento.') : null,
            'chunks' => $d->chunks_count,
            'canRetry' => $d->status === KnowledgeDocument::ERROR && $d->attempts < config('chatbots.knowledge.max_attempts'),
            'linked' => $linked->has($d->id),
            'at' => $d->created_at?->format('d/m/Y H:i'),
        ])->all();
    }

    /** @return Collection<int, string> */
    private function words(string $text): Collection
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $matches);

        return collect($matches[0])->unique()->values();
    }

    /** @return list<string> The words of a question that can be searched (letters and digits only: nothing of the question reaches the tsquery syntax). */
    private function terms(string $query): array
    {
        return $this->words(mb_substr($query, 0, config('chatbots.knowledge.query_max')))->take(12)->all();
    }

    private function assertContent(string $extension, string $bytes): void
    {
        if ($bytes === '') {
            $this->refuse('El archivo está vacío.');
        }

        if ($extension === 'docx') {
            if (! str_starts_with($bytes, "PK\x03\x04") || $this->docxXml($bytes) === null) {
                $this->refuse('El archivo no es un documento de Word (.docx) válido.');
            }

            return;
        }

        if (str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8')) {
            $this->refuse('El archivo no es texto UTF-8 legible. Guárdalo como texto plano (UTF-8) y súbelo de nuevo.');
        }
    }

    /** The XML of the body of a docx, or null if the zip is not a Word document or declares a body too large to expand. */
    private function docxXml(string $bytes): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kb');

        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive;

            if ($zip->open($tmp) !== true) {
                return null;
            }

            $stat = $zip->statName('word/document.xml');

            if ($stat === false || $stat['size'] > 20 * 1024 * 1024) {
                $zip->close();

                return null;
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            return $xml === false ? null : $xml;
        } finally {
            @unlink($tmp);
        }
    }

    /** Plain text of the file. The docx is read with string operations only (no XML parser, so no entity expansion). */
    private function extract(string $extension, string $bytes): string
    {
        if ($extension === 'docx') {
            $xml = (string) $this->docxXml($bytes);
            $xml = preg_replace(['/<w:tab\b[^>]*\/>/', '/<w:br\b[^>]*\/>/', '/<\/w:p>/'], ["\t", "\n", "\n"], $xml);
            $text = html_entity_decode(strip_tags((string) $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } else {
            $text = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? '';

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** @return list<string> */
    private function chunk(string $text): array
    {
        $max = (int) config('chatbots.knowledge.chunk_chars');
        $chunks = [];
        $current = '';

        foreach (preg_split('/\n+/', $text) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            foreach (mb_str_split($line, $max) as $piece) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 1 > $max) {
                    $chunks[] = $current;
                    $current = '';
                }

                $current .= ($current === '' ? '' : "\n").$piece;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function fail(KnowledgeDocument $document, string $code): KnowledgeDocument
    {
        KnowledgeDocument::where('id', $document->id)->update(['status' => KnowledgeDocument::ERROR, 'error_code' => $code, 'chunks_count' => 0, 'processed_at' => now()]);
        DB::table('knowledge_chunks')->where('document_id', $document->id)->where('workspace_id', $document->workspace_id)->delete();

        return $document->refresh();
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
