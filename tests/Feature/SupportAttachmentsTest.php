<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SupportDesk;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Files in a support request: screenshots, videos and PDFs sent by the administrator of a client Workspace and read by CCG's team in Conversaciones. The type is decided from the
 * content, the file is private and served only after checking the conversation and the Workspace. Storage is the fake of the test case: nothing real is written.
 */
class SupportAttachmentsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $a;

    private Workspace $b;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        Mail::fake();
        Notification::fake();
        $this->seedRoleCatalog();
        $this->dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->a = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations']);
        $this->b = $this->bookingWorkspace('CLIENTE_B', ['dashboard', 'conversations']);
    }

    private function as(Workspace $workspace, string $role = 'admin', ?User $user = null): User
    {
        $user ??= $this->member($workspace, $role);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    private function send(array $files, string $text = 'Mira este error', ?string $id = null)
    {
        static $n = 0;

        return $this->post('/support/messages', ['message' => $text, 'message_id' => $id ?? 'att-'.str_pad((string) ++$n, 8, '0', STR_PAD_LEFT), 'files' => $files], ['Accept' => 'application/json']);
    }

    private function pdf(string $name = 'factura.pdf', int $bytes = 600): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, str_pad("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", $bytes, ' '));
    }

    private function mp4(string $name = 'error.mp4'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 200));
    }

    private function stored(): int
    {
        return count(Storage::allFiles('workspaces'));
    }

    // --- sending ----------------------------------------------------------------------------------------------------

    public function test_a_screenshot_a_video_and_a_pdf_are_stored_privately_and_tied_to_the_message(): void
    {
        $admin = $this->as($this->a);

        $response = $this->send([UploadedFile::fake()->image('captura.png', 40, 30), $this->mp4(), $this->pdf()])->assertOk()->assertJsonCount(3, 'messages.0.attachments');

        $conversation = Conversation::where('contact_id', SupportDesk::contactId($admin, $this->a))->firstOrFail();
        $message = $conversation->messages()->firstOrFail();
        $rows = MessageAttachment::orderBy('id')->get();

        $this->assertSame(['image', 'video', 'pdf'], $rows->pluck('kind')->all());
        $this->assertSame(['image/png', 'video/mp4', 'application/pdf'], $rows->pluck('mime')->all());
        $this->assertSame([$message->id], $rows->pluck('message_id')->unique()->all());
        $this->assertSame([$this->dev->id], $rows->pluck('workspace_id')->unique()->all(), 'they live in the administrative Workspace with the conversation');
        $this->assertSame($admin->id, $rows->first()->uploaded_by);
        $this->assertSame(3, $this->stored());

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('#^workspaces/'.$this->dev->id.'/support/'.$conversation->id.'/[0-9a-f-]{36}\.(png|mp4|pdf)$#', $row->storage_path);
            Storage::assertExists($row->storage_path);
        }

        $payload = json_encode($response->json());
        $this->assertStringNotContainsString('workspaces/', $payload, 'no internal path reaches the browser');
        $this->assertStringNotContainsString('storage_path', $payload);
        $this->assertSame('captura.png', $response->json('messages.0.attachments.0.name'));
    }

    public function test_a_message_with_only_files_is_accepted_and_one_without_text_or_files_is_not(): void
    {
        $admin = $this->as($this->a);

        $this->post('/support/messages', ['message' => '', 'message_id' => 'only-files-1', 'files' => [$this->pdf()]], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('document', Message::where('workspace_id', $this->dev->id)->firstOrFail()->type);

        $this->post('/support/messages', ['message' => '', 'message_id' => 'nothing-1234'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame($admin->id, MessageAttachment::firstOrFail()->uploaded_by);
    }

    public function test_the_real_content_decides_not_the_name_nor_the_type_the_browser_claims(): void
    {
        $this->as($this->a);
        $cases = [
            'html as png' => UploadedFile::fake()->createWithContent('captura.png', '<html><script>alert(1)</script></html>'),
            'windows executable' => UploadedFile::fake()->createWithContent('programa.pdf', "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 100)),
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'),
            'shell script' => UploadedFile::fake()->createWithContent('run.mp4', "#!/bin/sh\necho hi\n"),
            'empty' => UploadedFile::fake()->createWithContent('vacio.png', ''),
            'truncated png' => UploadedFile::fake()->createWithContent('rota.png', "\x89PNG\r\n\x1a\n\x00\x00"),
            'pdf with a wrong body' => UploadedFile::fake()->createWithContent('falso.pdf', 'just text pretending'),
        ];

        foreach ($cases as $label => $file) {
            $this->send([$file], id: 'bad-'.md5($label))->assertStatus(422)->assertJsonValidationErrors('files');
        }

        $this->assertSame(0, MessageAttachment::count());
        $this->assertSame(0, Message::count(), 'a refused file leaves no message behind');
        $this->assertSame(0, $this->stored());
    }

    public function test_size_and_count_limits_are_enforced_and_nothing_is_kept_when_they_fail(): void
    {
        $this->as($this->a);
        config(['support.attachments.max_pdf_mb' => 1, 'support.attachments.max_files' => 2, 'support.attachments.max_total_mb' => 2]);

        $this->send([$this->pdf('grande.pdf', 1024 * 1024 + 10)])->assertStatus(422)->assertJsonPath('errors.files.0', fn ($m) => str_contains($m, 'pesa más de 1 MB'));
        $this->send([$this->pdf('1.pdf'), $this->pdf('2.pdf'), $this->pdf('3.pdf')])->assertStatus(422);
        $this->send([UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.png')])->assertStatus(422);

        $this->assertSame([0, 0, 0], [MessageAttachment::count(), Message::count(), $this->stored()]);
    }

    public function test_a_repeated_message_id_stores_the_files_once_and_leaves_no_orphan(): void
    {
        $this->as($this->a);

        $this->send([$this->pdf()], id: 'same-id-0001')->assertOk()->assertJsonPath('duplicate', false);
        $this->send([$this->pdf()], id: 'same-id-0001')->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame([1, 1, 1], [Message::count(), MessageAttachment::count(), $this->stored()]);
    }

    public function test_hostile_names_never_choose_a_path_or_break_the_download_header(): void
    {
        $admin = $this->as($this->a);
        $this->send([$this->pdf("../../etc/pass\"wd\r\nX-Evil: 1.pdf")])->assertOk();
        $row = MessageAttachment::firstOrFail();

        $this->assertStringNotContainsString('..', $row->storage_path);
        $this->assertStringNotContainsString('etc', $row->storage_path);
        $this->assertStringNotContainsString("\n", $row->original_name);
        $this->assertStringNotContainsString('/', $row->original_name);

        $response = $this->get(route('support.attachments', $row->id, false));
        $response->assertOk();
        $this->assertStringNotContainsString("\n", (string) $response->headers->get('Content-Disposition'));
        $this->assertNull($response->headers->get('X-Evil'));
        $this->assertSame($admin->id, $row->uploaded_by);
    }

    // --- reading ----------------------------------------------------------------------------------------------------

    public function test_the_owner_reads_the_file_with_headers_that_stop_it_from_running_as_a_page(): void
    {
        $this->as($this->a);
        $this->send([$this->pdf()])->assertOk();
        $row = MessageAttachment::firstOrFail();

        $response = $this->get(route('support.attachments', $row->id, false))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_another_workspace_a_non_administrator_and_a_guest_can_not_read_it(): void
    {
        $owner = $this->as($this->a);
        $this->send([$this->pdf()])->assertOk();
        $row = MessageAttachment::firstOrFail();
        $url = route('support.attachments', $row->id, false);

        $this->as($this->b)->id; // an administrator of ANOTHER client Workspace
        $this->get($url)->assertNotFound();

        $this->as($this->a, 'cliente');
        $this->get($url)->assertForbidden();

        $this->as($this->a, 'admin', $this->member($this->a, 'admin')); // an administrator of the same Workspace: a different person, a different conversation
        $this->get($url)->assertNotFound();

        auth()->logout();
        $this->flushSession();
        $this->get($url)->assertRedirect();
        $this->assertNotNull($owner);
    }

    public function test_the_team_of_the_administrative_workspace_sees_and_downloads_the_files_from_the_conversation(): void
    {
        $this->as($this->a);
        $this->send([UploadedFile::fake()->image('captura.png', 20, 20), $this->pdf()], text: 'Adjunto evidencia')->assertOk();
        $conversation = Conversation::firstOrFail();
        $row = MessageAttachment::where('kind', 'pdf')->firstOrFail();

        $this->as($this->dev);
        $this->get('/conversations?c='.$conversation->id)->assertOk()->assertInertia(fn ($page) => $page
            ->where('selected.items.0.body', 'Adjunto evidencia')
            ->has('selected.items.0.attachments', 2)
            ->where('selected.items.0.attachments.1.name', 'factura.pdf')
            ->where('selected.items.0.attachments.1.url', route('conversations.attachments', ['conversation' => $conversation->id, 'attachment' => $row->id], false)));

        $this->get(route('conversations.attachments', ['conversation' => $conversation->id, 'attachment' => $row->id], false))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_client_workspace_can_not_reach_the_support_conversation_files_through_the_inbox_route(): void
    {
        $this->as($this->a);
        $this->send([$this->pdf()])->assertOk();
        $conversation = Conversation::firstOrFail();
        $row = MessageAttachment::firstOrFail();

        // Even an administrator of the client Workspace with the module: the conversation belongs to the administrative Workspace.
        $this->get(route('conversations.attachments', ['conversation' => $conversation->id, 'attachment' => $row->id], false))->assertNotFound();

        // A file id that belongs to another conversation does not open through this one.
        $this->as($this->b);
        $this->send([$this->pdf('otra.pdf')])->assertOk();
        $other = MessageAttachment::where('original_name', 'otra.pdf')->firstOrFail();
        $this->as($this->dev);
        $this->get(route('conversations.attachments', ['conversation' => $conversation->id, 'attachment' => $other->id], false))->assertNotFound();
    }

    public function test_a_file_that_is_gone_gives_a_clear_not_found(): void
    {
        $this->as($this->a);
        $this->send([$this->pdf()])->assertOk();
        $row = MessageAttachment::firstOrFail();
        Storage::delete($row->storage_path);

        $this->get(route('support.attachments', $row->id, false))->assertNotFound();
    }

    public function test_the_attachment_row_can_not_point_to_a_message_of_another_conversation_or_workspace(): void
    {
        $this->as($this->a);
        $this->send([$this->pdf()])->assertOk();
        $message = Message::firstOrFail();

        $this->expectException(QueryException::class);
        MessageAttachment::create(['workspace_id' => $this->a->id, 'conversation_id' => $message->conversation_id, 'message_id' => $message->id, 'kind' => 'pdf', 'mime' => 'application/pdf', 'original_name' => 'x.pdf', 'size_bytes' => 1, 'storage_path' => 'x']);
    }
}
