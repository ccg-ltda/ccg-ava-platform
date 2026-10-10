<?php

namespace App\Integrations;

use App\Audit\Masked;
use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The WhatsApp Business (Cloud API) number of ONE Workspace: its phone number ID (`config`, not secret) and an access
 * token (`secrets`, encrypted, write-only). Ava uses them to VERIFY the number against Meta (a read-only request); the
 * conversation itself runs in n8n with its own WhatsApp credentials, and Ava never hands this token to anyone. The same
 * credentials let Ava deliver the replies of a human agent (`send`), the only message Ava itself puts on this channel.
 */
class WhatsAppType implements ChannelIntegrationType, SendsMessages
{
    public function key(): string
    {
        return 'whatsapp';
    }

    public function label(): string
    {
        return 'WhatsApp Business';
    }

    public function fields(): array
    {
        return [
            ['name' => 'phone_number_id', 'label' => 'ID del número de teléfono', 'kind' => 'text', 'required' => true, 'hint' => 'Meta for Developers > WhatsApp > Configuración de la API.'],
            ['name' => 'access_token', 'label' => 'Token de acceso', 'kind' => 'secret', 'required' => true, 'hint' => 'Token con permiso sobre este número, preferiblemente de un usuario del sistema.'],
        ];
    }

    public function notice(): string
    {
        return 'Ava usa estos datos solo para comprobar el número con Meta. Guardarlos no conecta n8n ni activa ninguna conversación: n8n responde con sus propias credenciales de WhatsApp y se configura aparte, en Integraciones → n8n.';
    }

    public function rules(array $input, ?Integration $current): array
    {
        return [
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,32}$/'],
            'access_token' => ['nullable', 'string', 'max:4096'],
        ];
    }

    public function build(array $input, ?Integration $current): array
    {
        $token = filled($input['access_token'] ?? null) ? trim($input['access_token']) : ($current?->secrets['access_token'] ?? null);

        if (! filled($token)) {
            throw ValidationException::withMessages(['access_token' => 'Escribe el token de acceso de WhatsApp.']);
        }

        return [
            'config' => ['phone_number_id' => $input['phone_number_id']],
            'secrets' => ['access_token' => $token],
        ];
    }

    public function form(Integration $integration): array
    {
        return [
            'phone_number_id' => $integration->config['phone_number_id'],
            'access_token' => '',
            'access_token_set' => filled($integration->secrets['access_token'] ?? null),
        ];
    }

    public function summary(Integration $integration): array
    {
        $rows = [['label' => 'ID del número', 'value' => $integration->config['phone_number_id']]];

        if ($number = $integration->config['display_number'] ?? null) {
            $rows[] = ['label' => 'Número verificado', 'value' => '+'.$number];
        }

        return ['rows' => $rows];
    }

    public function agentView(Integration $integration): array
    {
        return ['phone_number_id' => $integration->config['phone_number_id']];
    }

    public function supportsTest(): bool
    {
        return true;
    }

    /**
     * Reads the phone number from Meta's Graph API with the stored token. It proves the token is valid and has access
     * to that number; it does not prove that a message can be sent or received. Only fixed messages are reported:
     * neither the token nor Meta's error text is echoed.
     */
    public function test(Integration $integration): TestResult
    {
        $id = $integration->config['phone_number_id'];
        $url = rtrim(config('integrations.whatsapp.graph_url'), '/').'/'.config('integrations.whatsapp.graph_version')."/{$id}";
        $timeout = (int) config('integrations.whatsapp.timeout');
        $start = hrtime(true);

        try {
            $response = Http::withToken((string) ($integration->secrets['access_token'] ?? ''))
                ->acceptJson()->timeout($timeout)->connectTimeout(min($timeout, 10))->withoutRedirecting()
                ->get($url, ['fields' => 'display_phone_number,verified_name']);
        } catch (ConnectionException) {
            return TestResult::failure('No se pudo conectar con Meta.', null, $this->elapsed($start));
        } catch (Throwable) {
            return TestResult::failure('No se pudo completar la solicitud a Meta.', null, $this->elapsed($start));
        }

        $status = $response->status();
        $ms = $this->elapsed($start);
        $code = (int) $response->json('error.code');

        return match (true) {
            $status === 200 && (string) $response->json('id') === $id => new TestResult(true, $this->verified($response->json('verified_name'), $response->json('display_phone_number')), $status, $ms, $this->facts($response->json('display_phone_number'))),
            $status === 200 => TestResult::failure('Meta respondió con un número distinto al configurado.', $status, $ms),
            $status === 401 || $code === 190 => TestResult::failure('Meta rechazó el token: es inválido o expiró.', $status, $ms),
            in_array($status, [400, 403, 404], true) => TestResult::failure('Meta no encontró ese ID de número o el token no tiene permiso sobre él.', $status, $ms),
            default => TestResult::failure("Meta respondió HTTP {$status}.", $status, $ms),
        };
    }

    /**
     * Sends a text message through the Cloud API. Success means Meta ACCEPTED it (it returns the message id); delivery
     * and reading arrive later as statuses through n8n. Only fixed reasons are returned: neither the token nor Meta's
     * error text is echoed.
     */
    public function send(Integration $integration, string $to, string $text, ?string $reference = null): SendResult
    {
        $id = $integration->config['phone_number_id'];
        $url = rtrim(config('integrations.whatsapp.graph_url'), '/').'/'.config('integrations.whatsapp.graph_version')."/{$id}/messages";
        $timeout = (int) config('integrations.whatsapp.timeout');

        try {
            $response = Http::withToken((string) ($integration->secrets['access_token'] ?? ''))
                ->acceptJson()->asJson()->timeout($timeout)->connectTimeout(min($timeout, 10))->withoutRedirecting()
                ->post($url, ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => false]]
                    // Meta echoes this string in the delivery states (`statuses`) of the message, so Ava can recognize it later.
                    + ($reference !== null ? ['biz_opaque_callback_data' => $reference] : []));
        } catch (ConnectionException $e) {
            // Only a failure BEFORE the request could be processed (name, connection or certificate) proves nothing went out;
            // a timeout or a dropped connection may have happened after Meta accepted the message.
            return self::neverReached($e)
                ? SendResult::failed('No se pudo conectar con Meta.')
                : SendResult::uncertain('Meta no respondió a tiempo: el mensaje pudo haberse enviado. No se reintenta solo; verifica en WhatsApp antes de reenviarlo.');
        } catch (Throwable) {
            return SendResult::uncertain('La solicitud a Meta terminó con un error inesperado: el mensaje pudo haberse enviado. No se reintenta solo; verifica en WhatsApp antes de reenviarlo.');
        }

        $status = $response->status();
        $code = (int) $response->json('error.code');
        $messageId = $response->json('messages.0.id');

        return match (true) {
            $status === 200 && is_string($messageId) && $messageId !== '' => SendResult::accepted($messageId),
            $status === 401 || $code === 190 => SendResult::failed('Meta rechazó el token de WhatsApp: es inválido o expiró.'),
            $code === 131047 => SendResult::failed('Pasaron más de 24 horas desde el último mensaje del contacto: WhatsApp solo permite enviarle una plantilla aprobada.'),
            $code === 131030 => SendResult::failed('El número del contacto no está autorizado en esta cuenta de WhatsApp.'),
            $status === 200 => SendResult::uncertain('Meta respondió sin confirmar el mensaje: pudo haberse enviado. No se reintenta solo; verifica en WhatsApp antes de reenviarlo.'),
            $status >= 500 => SendResult::uncertain("Meta respondió con un error del servidor (HTTP {$status}): el mensaje pudo haberse enviado. No se reintenta solo; verifica en WhatsApp antes de reenviarlo."),
            default => SendResult::failed("Meta no aceptó el mensaje (HTTP {$status})."),
        };
    }

    /** cURL errors that happen before the request is sent: could not resolve, could not connect, TLS / certificate problems. */
    private static function neverReached(ConnectionException $e): bool
    {
        return preg_match('/cURL error (\d+)/', $e->getMessage(), $m) === 1 && in_array((int) $m[1], [5, 6, 7, 35, 51, 58, 59, 60, 77, 83, 90, 91], true);
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        $show = fn (mixed $value) => $value === null || $value === '' ? null : (string) $value;

        return [
            'ID del número' => $show($config['phone_number_id'] ?? null),
            'Token de acceso' => Masked::of($secrets['access_token'] ?? null),
        ];
    }

    /** The dialable number Meta reported (digits only, as a wa.me link needs it); nothing when Meta gave no usable one. */
    private function facts(mixed $number): array
    {
        $digits = is_string($number) ? preg_replace('/\D+/', '', $number) : '';

        return preg_match('/^\d{7,15}$/', $digits) ? ['display_number' => $digits] : [];
    }

    private function verified(mixed $name, mixed $number): string
    {
        $detail = collect([$name, $number])->filter(fn ($value) => is_string($value) && $value !== '')->map(fn ($value) => mb_substr($value, 0, 60))->implode(' · ');

        return 'Número verificado con Meta'.($detail !== '' ? ": {$detail}" : '').'.';
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
