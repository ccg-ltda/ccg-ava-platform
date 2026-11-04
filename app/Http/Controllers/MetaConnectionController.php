<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Services\MetaConnection;
use App\Services\MetaConnectionException;
use App\Services\WorkspaceChannels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Connects the ACTIVE Workspace's Instagram account or Facebook Page with Meta's OAuth (see App\Services\MetaConnection). Everything here belongs to the Workspace of the session, never to
 * one named by the client: the `state` that comes back from Meta must match this Workspace, this person and the channel they started from. Every action needs `manage-integrations` (the
 * routes), and the channel's module is the plan's `integrations`. The token never reaches the browser: after Meta redirects back, the only thing the interface learns is the account's name.
 */
class MetaConnectionController extends Controller
{
    private const CHANNELS = ['instagram', 'messenger'];

    public function __construct(private readonly MetaConnection $meta) {}

    /** Sends the person to Meta to authorize the account or Page. */
    public function connect(Request $request, string $channel): RedirectResponse
    {
        abort_unless(in_array($channel, self::CHANNELS, true), 404);
        $this->assertPlan($request, $channel);

        try {
            return redirect()->away($this->meta->begin($request->attributes->get('workspace'), $request->user(), $channel));
        } catch (MetaConnectionException $e) {
            return $this->back($e->getMessage(), 'error');
        }
    }

    /** Where Meta sends the person back. Instagram is saved here; Messenger asks which Page to use. */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->query('error') !== null || $request->query('error_reason') !== null) {
            return $this->back('La autorización se canceló en Meta: no se conectó nada.', 'error');
        }

        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_string($state) || ! is_string($code) || $state === '' || $code === '' || strlen($state) > 128 || strlen($code) > 2048) {
            return $this->back('Meta no devolvió una autorización válida. Vuelve a intentarlo.', 'error');
        }

        try {
            $result = $this->meta->complete($request->attributes->get('workspace'), $request->user(), $state, $code);
        } catch (MetaConnectionException $e) {
            return $this->back($e->getMessage(), 'error');
        }

        if ($result['selection'] !== null) {
            $request->session()->put('meta_selection', $result['selection']);

            return $this->back('Elige la página de Facebook que quieres conectar.', 'success');
        }

        return $this->back('Instagram conectado. Usa «Probar conexión» para comprobarlo con Meta.', 'success');
    }

    /** The Messenger Page the person chose from the ones they authorized. */
    public function selectPage(Request $request): RedirectResponse
    {
        $this->assertPlan($request, 'messenger');
        $data = $request->validate(['page_id' => ['required', 'string', 'regex:/^\d{5,32}$/']]);
        $selection = (string) $request->session()->get('meta_selection', '');

        try {
            $this->meta->connectPage($request->attributes->get('workspace'), $request->user(), $selection, $data['page_id']);
        } catch (MetaConnectionException $e) {
            $request->session()->forget('meta_selection');

            return $this->back($e->getMessage(), 'error');
        }

        $request->session()->forget('meta_selection');

        return $this->back('Messenger conectado. Usa «Probar conexión» para comprobarlo con Meta.');
    }

    /** The person closes the Page list without choosing: the authorization is forgotten. */
    public function cancelSelection(Request $request): RedirectResponse
    {
        $request->session()->forget('meta_selection');

        return $this->back('No se conectó ninguna página.');
    }

    /** Asks Meta again to deliver the account's messages to Ava. */
    public function subscribe(Request $request, string $channel): RedirectResponse
    {
        $integration = $this->integration($request, $channel);
        $this->assertPlan($request, $channel);

        return $this->meta->subscribe($integration)
            ? $this->back('Meta aceptó la suscripción a los mensajes.')
            : $this->back('Meta no aceptó la suscripción. Revisa que la app tenga el webhook configurado y vuelve a conectar la cuenta.', 'error');
    }

    /** Removes the connection and its token. */
    public function disconnect(Request $request, string $channel): RedirectResponse
    {
        $this->meta->disconnect($this->integration($request, $channel));

        return $this->back('Se desconectó '.config("chatbots.channels.{$channel}.label").': Ava ya no recibe ni envía mensajes por ese canal.');
    }

    /** The plan must include the channel to start, finish or repair a connection (disconnecting is always allowed). */
    private function assertPlan(Request $request, string $channel): void
    {
        abort_unless(app(WorkspaceChannels::class)->allows($request->attributes->get('workspace'), $channel), 403, app(WorkspaceChannels::class)->restriction());
    }

    private function integration(Request $request, string $channel): Integration
    {
        abort_unless(in_array($channel, self::CHANNELS, true), 404);

        return $request->attributes->get('workspace')->integrations()->where('type', $channel)->firstOrFail();
    }

    private function back(string $message, string $tone = 'success'): RedirectResponse
    {
        return redirect()->route('integrations.index')->with($tone, $message);
    }
}
