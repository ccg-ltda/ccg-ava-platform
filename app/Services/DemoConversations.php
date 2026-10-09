<?php

namespace App\Services;

use App\Exceptions\DemoNotAllowed;
use App\Models\Chatbot;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * The demo environment of Conversations: scenarios generated INSIDE the administrative Workspace, so the module can be
 * tried without any real channel. It is the only code that creates or removes demo data, and it can run only in the
 * environments of config/demo.php and only in that Workspace (`assertAllowed`).
 *
 * How demo data is recognised: `conversations.demo_key` (the scenario), `chatbots.is_demo` and `messages.simulated`;
 * never a name or a text. `generate` is idempotent (an existing scenario is left as it is) and `clean` removes only
 * conversations with a `demo_key` of this Workspace, plus the demo chatbot when it is left empty. Nothing here calls a
 * provider: contacts are fictitious and no message leaves Ava.
 */
class DemoConversations
{
    public const CHATBOT = 'Demo · Asistente (simulado)';

    public function __construct(private readonly ConversationAgents $agents) {}

    /** Whether the demo tools exist in this environment at all. */
    public function enabled(): bool
    {
        return in_array(app()->environment(), config('demo.environments'), true);
    }

    /** The administrative Workspace (by code, never by id), or null when it does not exist. */
    public function workspace(): ?Workspace
    {
        return Workspace::where('code', Workspace::normalizeCode((string) config('workspace.admin_code')))->first();
    }

    /** Refuses anything outside the allowed environments or outside the administrative Workspace. */
    public function assertAllowed(Workspace $workspace): void
    {
        if (! $this->enabled()) {
            throw new DemoNotAllowed('Los datos demo solo existen en entornos locales o de desarrollo.');
        }

        if (! $workspace->isAdministrative()) {
            throw new DemoNotAllowed('Los datos demo solo se generan en el Workspace administrativo.');
        }
    }

    /** @return array{demo: int, total: int} the demo conversations of the Workspace and all of them */
    public function counts(Workspace $workspace): array
    {
        return [
            'demo' => Conversation::where('workspace_id', $workspace->id)->whereNotNull('demo_key')->count(),
            'total' => Conversation::where('workspace_id', $workspace->id)->count(),
        ];
    }

    /**
     * Creates the scenarios that do not exist yet.
     *
     * @return array{created: int, existing: int, unassigned: int} `unassigned`: scenarios that wanted an agent but the Workspace has none
     */
    public function generate(Workspace $workspace): array
    {
        $this->assertAllowed($workspace);

        return DB::transaction(function () use ($workspace) {
            $bot = $workspace->chatbots()->where('is_demo', true)->first()
                ?? $workspace->chatbots()->create(['name' => self::CHATBOT, 'description' => 'Chatbot ficticio para probar Conversaciones. No usa ningún canal ni modelo real.', 'is_active' => true]);
            $bot->forceFill(['is_demo' => true])->save();

            $agents = $this->agents->of($workspace)->values();
            $result = ['created' => 0, 'existing' => 0, 'unassigned' => 0];

            foreach ($this->scenarios() as $index => $scenario) {
                if ($bot->conversations()->where('demo_key', $scenario['key'])->exists()) {
                    $result['existing']++;

                    continue;
                }

                $agent = ($scenario['agent'] ?? false) ? $agents->get($index % max($agents->count(), 1)) : null;
                $wantsAgent = $scenario['handling'] === Conversation::HUMAN;
                $result['unassigned'] += $wantsAgent && ! $agent ? 1 : 0;
                $this->build($bot, $scenario, $wantsAgent && ! $agent ? Conversation::PENDING : $scenario['handling'], $agent);
                $result['created']++;
            }

            return $result;
        });
    }

    /**
     * Removes the demo conversations (and their messages) of the Workspace; nothing that is not demo.
     *
     * @return int how many conversations were removed
     */
    public function clean(Workspace $workspace): int
    {
        $this->assertAllowed($workspace);

        return DB::transaction(function () use ($workspace) {
            $removed = Conversation::where('workspace_id', $workspace->id)->whereNotNull('demo_key')->delete();

            // The demo chatbot goes only when no conversation (real or demo) is left on it.
            $workspace->chatbots()->where('is_demo', true)->whereDoesntHave('conversations')->delete();

            return $removed;
        });
    }

    /** Clean and generate again. */
    public function reset(Workspace $workspace): array
    {
        $this->clean($workspace);

        return $this->generate($workspace);
    }

    /** @param  array<string, mixed>  $scenario */
    private function build(Chatbot $bot, array $scenario, string $handling, ?User $agent): void
    {
        $start = now()->subMinutes($scenario['ago']);
        $conversation = $bot->conversations()->create([
            'workspace_id' => $bot->workspace_id,
            'channel' => $scenario['channel'],
            'contact_id' => $scenario['contact'],
            'contact_name' => $scenario['name'],
            'demo_key' => $scenario['key'],
            'last_message_at' => $start,
        ]);

        $last = $start;

        foreach ($scenario['messages'] as $step => [$sender, $text, $status]) {
            $last = $start->copy()->addMinutes($step * ($scenario['gap'] ?? 2));
            $incoming = $sender === 'contact';

            $conversation->messages()->create([
                'workspace_id' => $bot->workspace_id,
                'direction' => $incoming ? 'in' : 'out',
                'sender' => $sender,
                'sender_user_id' => $sender === 'agent' ? $agent?->id : null,
                'type' => 'text',
                'body' => $text,
                'status' => $incoming ? null : $status,
                'failure_reason' => $status === 'failed' ? 'Fallo simulado: no se envió nada a ningún proveedor.' : null,
                'simulated' => true,
                'sent_at' => $last,
            ]);
        }

        $conversation->forceFill(['last_message_at' => $last, 'handling' => $handling, 'assigned_user_id' => $handling === Conversation::HUMAN || $handling === Conversation::RESOLVED ? $agent?->id : null])->save();

        if ($handling === Conversation::PENDING) {
            $conversation->forceFill(['handoff_reason' => $scenario['reason'] ?? 'Solicitado por el asistente (simulado).', 'handoff_requested_at' => $last])->save();
        }

        if ($handling === Conversation::RESOLVED) {
            $conversation->forceFill(['resolved_at' => $last])->save();
        }
    }

    /**
     * Fictitious scenarios. Contacts use reserved-looking fake ids, never a real number or person. Each message is
     * [sender, text, status of an outgoing one].
     *
     * @return list<array<string, mixed>>
     */
    private function scenarios(): array
    {
        $long = [];
        foreach (range(1, 20) as $n) {
            $long[] = ['contact', "Pregunta {$n}: ¿cuál es el horario de la sucursal {$n}?", null];
            $long[] = ['ai', "Respuesta simulada {$n}: la sucursal {$n} abre de 8:00 a 18:00.", $n % 7 === 0 ? 'read' : 'delivered'];
        }

        return [
            ['key' => 'web-faq-ai', 'channel' => 'web', 'contact' => 'demo-session-0001', 'name' => 'Visitante Demo 1', 'handling' => 'ai', 'ago' => 25, 'messages' => [
                ['contact', 'Hola, ¿tienen servicio a domicilio?', null],
                ['ai', 'Respuesta simulada: sí, hay domicilios de lunes a sábado.', 'sent'],
                ['contact', '¿Cuánto tarda normalmente?', null],
                ['ai', 'Respuesta simulada: entre 30 y 45 minutos.', 'sent'],
            ]],
            ['key' => 'wa-order-ai', 'channel' => 'whatsapp', 'contact' => '570000000001', 'name' => 'Cliente Demo 2', 'handling' => 'ai', 'ago' => 90, 'messages' => [
                ['contact', 'Buenas, quiero hacer un pedido', null],
                ['ai', 'Respuesta simulada: claro, ¿qué te gustaría pedir?', 'delivered'],
                ['contact', 'Dos combos familiares', null],
                ['ai', 'Respuesta simulada: anotado. ¿Dirección de entrega?', 'read'],
                ['contact', 'Calle 0 # 0-00 (dirección ficticia)', null],
            ]],
            ['key' => 'wa-complaint-pending', 'channel' => 'whatsapp', 'contact' => '570000000002', 'name' => 'Cliente Demo 3', 'handling' => 'pending', 'ago' => 40, 'reason' => 'Queja por un pedido incompleto (simulado).', 'messages' => [
                ['contact', 'Mi pedido llegó incompleto y nadie me contesta', null],
                ['ai', 'Respuesta simulada: lamento lo ocurrido. ¿Quieres que te comunique con un agente?', 'delivered'],
                ['contact', 'Sí, por favor, es un reclamo', null],
            ]],
            ['key' => 'web-human-request-pending', 'channel' => 'web', 'contact' => 'demo-session-0002', 'name' => null, 'handling' => 'pending', 'ago' => 12, 'reason' => 'El visitante pidió hablar con una persona (simulado).', 'messages' => [
                ['contact', 'Quiero hablar con una persona', null],
                ['ai', 'Respuesta simulada: te paso con un agente.', 'sent'],
            ]],
            ['key' => 'wa-claim-human', 'channel' => 'whatsapp', 'contact' => '570000000003', 'name' => 'Cliente Demo 4', 'handling' => 'human', 'agent' => true, 'ago' => 180, 'messages' => [
                ['contact', 'Me cobraron dos veces la misma compra', null],
                ['ai', 'Respuesta simulada: voy a pasar tu caso a un agente.', 'delivered'],
                ['agent', 'Hola, soy tu agente. Reviso el cobro y te escribo en unos minutos.', 'delivered'],
                ['contact', 'Gracias, quedo atento', null],
                ['agent', 'Confirmado el doble cobro simulado; se reversará.', 'sent'],
            ]],
            ['key' => 'web-complaint-human', 'channel' => 'web', 'contact' => 'demo-session-0003', 'name' => 'Visitante Demo 5', 'handling' => 'human', 'agent' => true, 'ago' => 60, 'messages' => [
                ['contact', 'La página no me deja pagar, es la tercera vez', null],
                ['agent', 'Hola, soy un agente. ¿Qué mensaje de error te aparece?', 'delivered'],
                ['contact', 'Dice "pago rechazado" (texto ficticio)', null],
                ['agent', 'Te ayudo a hacerlo por otro medio.', 'failed'],
            ]],
            ['key' => 'wa-resolved-yesterday', 'channel' => 'whatsapp', 'contact' => '570000000004', 'name' => 'Cliente Demo 6', 'handling' => 'resolved', 'agent' => true, 'ago' => 60 * 26, 'gap' => 5, 'messages' => [
                ['contact', 'No me llegó el código de descuento', null],
                ['ai', 'Respuesta simulada: lo reviso. ¿Cuál es tu correo?', 'read'],
                ['contact', 'cliente.demo6@example.invalid', null],
                ['agent', 'Reenviado el código (simulado). ¿Algo más?', 'read'],
                ['contact', 'Todo listo, gracias', null],
            ]],
            ['key' => 'web-resolved-lastweek', 'channel' => 'web', 'contact' => 'demo-session-0004', 'name' => 'Visitante Demo 7', 'handling' => 'resolved', 'agent' => true, 'ago' => 60 * 24 * 10, 'gap' => 10, 'messages' => [
                ['contact', '¿Dónde veo el estado de mi reclamo?', null],
                ['agent', 'En tu cuenta, sección Reclamos (simulado).', 'delivered'],
                ['contact', 'Lo encontré, gracias', null],
            ]],
            ['key' => 'wa-long-history', 'channel' => 'whatsapp', 'contact' => '570000000005', 'name' => 'Cliente Demo 8', 'handling' => 'ai', 'ago' => 60 * 24 * 3, 'gap' => 3, 'messages' => $long],
            ['key' => 'web-only-incoming-ai', 'channel' => 'web', 'contact' => 'demo-session-0005', 'name' => null, 'handling' => 'ai', 'ago' => 3, 'messages' => [
                ['contact', 'Hola', null],
            ]],
            ['key' => 'wa-failed-delivery-human', 'channel' => 'whatsapp', 'contact' => '570000000006', 'name' => 'Cliente Demo 9', 'handling' => 'human', 'agent' => true, 'ago' => 30, 'messages' => [
                ['contact', 'Necesito cambiar la dirección de mi pedido', null],
                ['agent', 'Claro, ¿cuál es la nueva dirección?', 'failed'],
            ]],
        ];
    }
}
