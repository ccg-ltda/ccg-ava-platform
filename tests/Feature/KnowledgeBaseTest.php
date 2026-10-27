<?php

namespace Tests\Feature;

use App\Models\AgentToolCall;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\KnowledgeDocument;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\KnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;
use ZipArchive;

/** The knowledge base: real content validation, extraction, states, retrieval with its source, isolation by Workspace and assistant, permissions and cleanup. */
class KnowledgeBaseTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Chatbot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('CLIENTE', ['dashboard', 'assistants', 'conversations']);
        $this->actAsMember($this->workspace);
        $this->bot = $this->workspace->chatbots()->create(['name' => 'Ventas']);
    }

    private function upload(string $name, string $content, ?Chatbot $bot = null, ?string $title = null)
    {
        $bot ??= $this->bot;

        return $this->post("/chatbots/{$bot->id}/knowledge", ['file' => UploadedFile::fake()->createWithContent($name, $content), 'title' => $title]);
    }

    private function docx(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $body = implode('', array_map(fn ($line) => '<w:p><w:r><w:t>'.htmlspecialchars($line, ENT_XML1).'</w:t></w:r></w:p>', explode("\n", $text)));
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function search(string $token, string $q)
    {
        return $this->getJson('/api/agent/knowledge/search?q='.urlencode($q), ['Authorization' => 'Bearer '.$token]);
    }

    public function test_a_text_document_is_validated_extracted_and_ready_for_the_assistant_that_received_it(): void
    {
        $this->upload('horarios.txt', "Atendemos de lunes a viernes de 8am a 5pm.\n\nLos sábados abrimos hasta el mediodía.", null, 'Horarios')->assertSessionHasNoErrors()->assertSessionHas('success');

        $doc = KnowledgeDocument::firstOrFail();
        $this->assertSame([$this->workspace->id, 'Horarios', 'ready', 1, 'txt'], [$doc->workspace_id, $doc->title, $doc->status, $doc->chunks_count, $doc->extension]);
        Storage::assertExists($doc->storage_path);
        $this->assertSame([$this->bot->id], $doc->chatbots()->pluck('chatbots.id')->all());
        $this->assertTrue(AuditLog::where('resource_type', 'knowledge_document')->where('action', 'created')->exists());

        $this->get("/chatbots/{$this->bot->id}")->assertInertia(fn ($page) => $page->where('knowledge.documents.0.status', 'ready')->where('knowledge.documents.0.linked', true)->where('knowledge.documents.0.chunks', 1));
    }

    public function test_a_real_docx_is_extracted_and_a_fake_one_is_refused(): void
    {
        $this->upload('catalogo.docx', $this->docx("Vendemos camisetas de algodón.\nEnvíos a toda Colombia."))->assertSessionHasNoErrors();
        $this->assertSame('ready', KnowledgeDocument::firstOrFail()->status);
        $this->assertStringContainsString('camisetas de algodón', DB::table('knowledge_chunks')->value('content'));

        $this->upload('falso.docx', 'esto no es un zip')->assertSessionHasErrors('file');
        $this->upload('zip.docx', "PK\x03\x04".str_repeat('x', 50))->assertSessionHasErrors('file');
        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_a_pdf_and_other_unreadable_files_are_refused_instead_of_pretending_they_were_learned(): void
    {
        $this->upload('manual.pdf', "%PDF-1.4\n...")->assertSessionHasErrors('file');
        $this->upload('programa.exe', 'MZ')->assertSessionHasErrors('file');
        $this->upload('binario.txt', "abc\0def")->assertSessionHasErrors('file');
        $this->upload('latin.txt', "caf\xE9")->assertSessionHasErrors('file');
        $this->upload('vacio.txt', '')->assertSessionHasErrors('file');

        $this->assertSame(0, KnowledgeDocument::count());
        $this->assertSame([], Storage::allFiles());
    }

    public function test_the_same_file_is_not_stored_twice_in_a_workspace_and_the_size_is_limited(): void
    {
        $this->upload('a.txt', 'Hola mundo con contenido.')->assertSessionHasNoErrors();
        $this->upload('b.txt', 'Hola mundo con contenido.')->assertSessionHasErrors('file');

        config(['chatbots.knowledge.max_kb' => 1]);
        $this->upload('grande.txt', str_repeat('palabra ', 400))->assertSessionHasErrors('file');
        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_a_document_without_text_is_kept_with_a_clear_error_and_retries_are_bounded(): void
    {
        $this->upload('blanco.docx', $this->docx('   '))->assertSessionHas('error');
        $doc = KnowledgeDocument::firstOrFail();
        $this->assertSame(['error', 'empty', 1], [$doc->status, $doc->error_code, (int) $doc->attempts]);

        $this->get("/chatbots/{$this->bot->id}")->assertInertia(fn ($page) => $page->where('knowledge.documents.0.status', 'error')->where('knowledge.documents.0.canRetry', true));

        $this->post("/chatbots/{$this->bot->id}/knowledge/{$doc->id}/retry")->assertSessionHas('error');
        $this->post("/chatbots/{$this->bot->id}/knowledge/{$doc->id}/retry")->assertSessionHas('error');
        $this->assertSame(3, (int) $doc->fresh()->attempts);

        $this->post("/chatbots/{$this->bot->id}/knowledge/{$doc->id}/retry")->assertSessionHasErrors('file');
        $this->assertSame(3, (int) $doc->fresh()->attempts);
    }

    public function test_processing_a_document_that_is_ready_or_being_processed_changes_nothing(): void
    {
        $this->upload('a.txt', 'Contenido útil para el asistente.')->assertSessionHasNoErrors();
        $doc = KnowledgeDocument::firstOrFail();
        $chunks = DB::table('knowledge_chunks')->pluck('id')->all();

        app(KnowledgeBase::class)->process($doc);
        $this->assertSame(1, (int) $doc->fresh()->attempts);
        $this->assertSame($chunks, DB::table('knowledge_chunks')->pluck('id')->all());

        $doc->update(['status' => 'processing']);
        app(KnowledgeBase::class)->process($doc);
        $this->assertSame('processing', $doc->fresh()->status);
    }

    public function test_the_assistant_finds_passages_with_their_source_and_the_search_is_traced_without_the_question(): void
    {
        $this->upload('horarios.txt', "Atendemos de lunes a viernes de 8am a 5pm.\nLos sábados abrimos hasta el mediodía.", null, 'Horarios');
        $token = app(AgentAccess::class)->generate($this->bot);

        $response = $this->search($token, 'a qué hora atienden los sábados')->assertOk();
        $response->assertJsonPath('count', 1)->assertJsonPath('results.0.document', 'Horarios')->assertJsonPath('results.0.file', 'horarios.txt')->assertJsonPath('results.0.chunk', 1);
        $this->assertStringContainsString('sábados', $response->json('results.0.text'));
        $this->assertStringContainsString('no instrucciones', $response->json('note'));

        $this->assertSame(1, AgentToolCall::where('tool', 'knowledge.search')->where('status', 'succeeded')->count());
        $retrieval = DB::table('knowledge_retrievals')->first();
        $this->assertSame($this->bot->id, (int) $retrieval->chatbot_id);
        $this->assertStringNotContainsString('sábados', json_encode($retrieval));

        $this->search($token, 'zzzz qqqq')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_a_document_is_only_retrieved_by_linked_assistants_of_its_own_workspace(): void
    {
        $this->upload('interno.txt', 'La clave del almacén es un secreto interno del negocio.', null, 'Interno');
        $doc = KnowledgeDocument::firstOrFail();
        $sibling = $this->workspace->chatbots()->create(['name' => 'Soporte']);
        $other = $this->bookingWorkspace('OTRO', ['dashboard', 'assistants', 'conversations']);
        $foreign = $other->chatbots()->create(['name' => 'Ajeno']);

        $this->search(app(AgentAccess::class)->generate($sibling), 'clave del almacén secreto')->assertOk()->assertJsonPath('count', 0);
        $this->search(app(AgentAccess::class)->generate($foreign), 'clave del almacén secreto')->assertOk()->assertJsonPath('count', 0);

        // The database itself refuses to link a document to an assistant of another Workspace.
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('knowledge_document_chatbot')->insert(['workspace_id' => $this->workspace->id, 'document_id' => $doc->id, 'chatbot_id' => $foreign->id])));

        // Unlinking from the assistant stops the retrieval; linking the sibling enables it.
        $this->post("/chatbots/{$this->bot->id}/knowledge/{$doc->id}/link", ['linked' => false])->assertSessionHasNoErrors();
        $this->search(app(AgentAccess::class)->generate($this->bot), 'clave del almacén secreto')->assertJsonPath('count', 0);
        $this->post("/chatbots/{$sibling->id}/knowledge/{$doc->id}/link", ['linked' => true])->assertSessionHasNoErrors();
        $this->search(app(AgentAccess::class)->generate($sibling), 'clave del almacén secreto')->assertJsonPath('count', 1);

        // A document in error or not processed is never retrieved.
        $doc->update(['status' => 'error']);
        $this->search(app(AgentAccess::class)->generate($sibling), 'clave del almacén secreto')->assertJsonPath('count', 0);
    }

    public function test_search_needs_the_assistants_token_and_a_valid_question(): void
    {
        $this->getJson('/api/agent/knowledge/search?q=horarios')->assertUnauthorized();
        $this->getJson('/api/agent/knowledge/search?q=horarios', ['Authorization' => 'Bearer invalido'])->assertUnauthorized();

        $token = app(AgentAccess::class)->generate($this->bot);
        $this->search($token, '')->assertUnprocessable();
        $this->search($token, 'ab')->assertUnprocessable();
        $this->search($token, str_repeat('a', 301))->assertUnprocessable();
    }

    public function test_deleting_a_document_removes_its_passages_links_and_stored_file(): void
    {
        $this->upload('a.txt', 'Contenido que se va a borrar del negocio.');
        $doc = KnowledgeDocument::firstOrFail();
        $path = $doc->storage_path;

        $this->delete("/chatbots/{$this->bot->id}/knowledge/{$doc->id}")->assertSessionHas('success');

        $this->assertSame([0, 0, 0], [KnowledgeDocument::count(), DB::table('knowledge_chunks')->count(), DB::table('knowledge_document_chatbot')->count()]);
        Storage::assertMissing($path);
        $this->assertTrue(AuditLog::where('resource_type', 'knowledge_document')->where('action', 'deleted')->exists());
    }

    public function test_permissions_and_other_workspaces_documents_are_enforced(): void
    {
        $this->upload('a.txt', 'Contenido del negocio uno.');
        $mine = KnowledgeDocument::firstOrFail();

        $other = $this->bookingWorkspace('OTRO', ['dashboard', 'assistants', 'conversations']);
        $foreignBot = $other->chatbots()->create(['name' => 'Ajeno']);
        $this->upload('b.txt', 'Contenido ajeno.', $foreignBot)->assertNotFound();

        $this->actAsMember($other);
        $this->upload('b.txt', 'Contenido del negocio dos.', $foreignBot)->assertSessionHasNoErrors();
        // Another Workspace's document through my assistant, or my document through its assistant: not found.
        $this->delete("/chatbots/{$foreignBot->id}/knowledge/{$mine->id}")->assertNotFound();
        $this->post("/chatbots/{$foreignBot->id}/knowledge/{$mine->id}/link", ['linked' => true])->assertNotFound();
        $this->assertNotNull($mine->fresh());

        $this->actAsMember($this->workspace, 'cliente');
        $this->upload('c.txt', 'No debería poder.')->assertForbidden();
        $this->delete("/chatbots/{$this->bot->id}/knowledge/{$mine->id}")->assertForbidden();
        Role::findByName('cliente', 'web')->givePermissionTo(['view-chatbots']);
        $this->actAsMember($this->workspace, 'cliente');
        $this->upload('c.txt', 'No debería poder.')->assertForbidden();
        $this->assertSame(1, KnowledgeDocument::where('workspace_id', $this->workspace->id)->count());
    }
}
