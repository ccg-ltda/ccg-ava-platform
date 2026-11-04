<?php

namespace App\Integrations;

use App\Audit\Masked;
use App\Models\Integration;
use App\Observability\ApiCallRecorder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What Instagram Messaging and Messenger have in common as integrations of ONE Workspace: the account (Instagram professional account or Facebook Page) the person
 * AUTHORIZED through Meta's OAuth and the token Meta gave for it (`secrets`, encrypted, write-only). Nothing is typed in a form: `fields()` is empty and `build()` refuses, because
 * the connection is made by App\Services\MetaConnection and the token never passes through the interface. Each channel supplies its own hosts, paths and facts (the subclasses);
 * the way Ava reads the outcome of Meta is shared. Messages leave only as a REPLY inside the messaging window; Meta's own error text is never echoed.
 */
abstract class MetaMessagingType implements ChannelIntegrationType, SendsMessages
{
    /** Provider tag of the API call log. */
    abstract protected function provider(): string;

    /** Full URL of a path of this channel's Graph API (with the configured version). */
    abstract protected function url(string $path): string;

    /** The request that proves the token reads THIS account: path and the fields to ask for. @return array{0: string, 1: string} */
    abstract protected function identityRequest(Integration $integration): array;

    /** Whether the answer of the identity request is about the stored account. */
    abstract protected function identityMatches(Response $response, Integration $integration): bool;

    /** The body of a text reply to a contact. @return array<string, mixed> */
    abstract protected function messageBody(string $to, string $text): array;

    /** Path that sends a message for the stored account. */
    abstract protected function sendPath(Integration $integration): string;

    /** Name of the account in the words of the channel («cuenta de Instagram», «página de Facebook»). */
    abstract protected function accountNoun(): string;

    public function fields(): array
    {
        return [];
    }

    public function rules(array $input, ?Integration $current): array
    {
        return [];
    }

    public function build(array $input, ?Integration $current): array
    {
        throw ValidationException::withMessages(['channel' => "Este canal se conecta con la autorización de Meta: usa «Conectar con {$this->label()}»."]);
    }

    public function form(Integration $integration): array
    {
        return ['account_id' => $integration->config['account_id'] ?? '', 'account_name' => $integration->config['account_name'] ?? ''];
    }

    public function summary(Integration $integration): array
    {
        $rows = [['label' => ucfirst($this->accountNoun()), 'value' => (string) ($integration->config['account_name'] ?? $integration->config['account_id'] ?? '—')], ['label' => 'ID de la cuenta', 'value' => (string) ($integration->config['account_id'] ?? '—')]];

        if (filled($integration->config['token_expires_at'] ?? null)) {
            $rows[] = ['label' => 'La autorización vence', 'value' => substr((string) $integration->config['token_expires_at'], 0, 10)];
        }

        $rows[] = ['label' => 'Webhook de mensajes', 'value' => filled($integration->config['webhook_subscribed_at'] ?? null) ? 'Suscrito' : 'Sin suscribir'];

        return ['rows' => $rows];
    }

    public function agentView(Integration $integration): array
    {
        return ['account_id' => $integration->config['account_id'] ?? null];
    }

    public function notice(): string
    {
        return "Se conecta con la autorización de Meta: no hace falta copiar ningún token. Ava guarda el permiso de la {$this->accountNoun()} que elijas, cifrado, y solo lo usa para recibir sus mensajes y responder dentro de la ventana que Meta permite.";
    }

    public function supportsTest(): bool
    {
        return true;
    }

    /** Reads the account with the stored token: it proves the token is valid and is about that account, not that a message can be sent or received. */
    public function test(Integration $integration): TestResult
    {
        [$path, $fields] = $this->identityRequest($integration);
        $url = $this->url($path);
        $timeout = (int) config('integrations.meta.timeout');
        $start = hrtime(true);

        try {
            $response = app(ApiCallRecorder::class)->call($this->provider(), 'verify_account', 'GET', $url, fn () => Http::withToken((string) ($integration->secrets['access_token'] ?? ''))
                ->acceptJson()->timeout($timeout)->connectTimeout(min($timeout, 10))->withoutRedirecting()->get($url, ['fields' => $fields]), [], null, ['workspace_id' => $integration->workspace_id], false);
        } catch (ConnectionException) {
            return TestResult::failure('No se pudo conectar con Meta.', null, $this->elapsed($start));
        } catch (Throwable) {
            return TestResult::failure('No se pudo completar la solicitud a Meta.', null, $this->elapsed($start));
        }

        $status = $response->status();
        $ms = $this->elapsed($start);

        return match (true) {
            $status === 200 && $this->identityMatches($response, $integration) => new TestResult(true, "Cuenta verificada con Meta: {$this->name($response, $integration)}.", $status, $ms),
            $status === 200 => TestResult::failure('Meta respondió con una cuenta distinta a la conectada.', $status, $ms),
            $status === 401 || (int) $response->json('error.code') === 190 => TestResult::failure('Meta rechazó la autorización: venció o fue revocada. Vuelve a conectar la cuenta.', $status, $ms),
            in_array($status, [400, 403, 404], true) => TestResult::failure('Meta no encontró la cuenta o la autorización ya no tiene permiso sobre ella. Vuelve a conectarla.', $status, $ms),
            default => TestResult::failure("Meta respondió HTTP {$status}.", $status, $ms),
        };
    }

    /**
     * Sends a text REPLY to a contact. Success means Meta ACCEPTED it (it returns the message id). A refusal carries a fixed reason; an answer that may have been lost is
     * `uncertain` and is never retried by itself (Meta offers no idempotency key here).
     */
    public function send(Integration $integration, string $to, string $text, ?string $reference = null): SendResult
    {
        $url = $this->url($this->sendPath($integration));
        $timeout = (int) config('integrations.meta.timeout');

        try {
            // Metadata only (bodies = false): the body is a customer's message and identifier.
            $response = app(ApiCallRecorder::class)->call($this->provider(), 'send_message', 'POST', $url, fn () => Http::withToken((string) ($integration->secrets['access_token'] ?? ''))
                ->acceptJson()->asJson()->timeout($timeout)->connectTimeout(min($timeout, 10))->withoutRedirecting()->post($url, $this->messageBody($to, $text)), [], null, ['workspace_id' => $integration->workspace_id], false);
        } catch (ConnectionException $e) {
            return MetaGraph::neverReached($e)
                ? SendResult::failed('No se pudo conectar con Meta.', true)
                : SendResult::uncertain('Meta no respondió a tiempo: el mensaje pudo haberse enviado. No se reintenta solo; verifica en la app antes de reenviarlo.');
        } catch (Throwable) {
            return SendResult::uncertain('La solicitud a Meta terminó con un error inesperado: el mensaje pudo haberse enviado. No se reintenta solo; verifica en la app antes de reenviarlo.');
        }

        $status = $response->status();
        $code = (int) $response->json('error.code');
        $messageId = $response->json('message_id');

        return match (true) {
            $status === 200 && is_string($messageId) && $messageId !== '' => SendResult::accepted($messageId),
            $status === 401 || $code === 190 => SendResult::failed('Meta rechazó la autorización: venció o fue revocada. Vuelve a conectar la cuenta.'),
            $code === 10 || $code === 200 => SendResult::failed('Meta no permite responder a este contacto ahora: la ventana de mensajería terminó (24 horas desde su último mensaje) o falta un permiso.'),
            $code === 551 => SendResult::failed('El contacto no está disponible para recibir mensajes.'),
            in_array($code, [4, 17, 32, 613], true) || $status === 429 => SendResult::failed('Meta limitó el ritmo de envío: se reintentará.', true),
            $status === 200 => SendResult::uncertain('Meta respondió sin confirmar el mensaje: pudo haberse enviado. No se reintenta solo; verifica en la app antes de reenviarlo.'),
            $status >= 500 => SendResult::uncertain("Meta respondió con un error del servidor (HTTP {$status}): el mensaje pudo haberse enviado. No se reintenta solo; verifica en la app antes de reenviarlo."),
            default => SendResult::failed("Meta no aceptó el mensaje (HTTP {$status})."),
        };
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        $show = fn (mixed $value) => $value === null || $value === '' ? null : (string) $value;

        return [
            'ID de la cuenta' => $show($config['account_id'] ?? null),
            'Cuenta' => $show($config['account_name'] ?? null),
            'Autorización de Meta' => Masked::of($secrets['access_token'] ?? null),
            'Webhook suscrito' => filled($config['webhook_subscribed_at'] ?? null) ? 'Sí' : 'No',
        ];
    }

    /** The name Meta reported for the account, shortened. */
    protected function name(Response $response, Integration $integration): string
    {
        $name = $response->json('username') ?? $response->json('name') ?? ($integration->config['account_name'] ?? '');

        return mb_substr((string) $name, 0, 60);
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
