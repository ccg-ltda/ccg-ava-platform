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
 * conversation itself runs in n8n with its own WhatsApp credentials, and Ava never hands this token to anyone.
 */
class WhatsAppType implements ChannelIntegrationType
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
        return 'Ava usa estos datos para verificar el número con Meta. Guardarlos no activa ninguna conversación: n8n responde con sus propias credenciales de WhatsApp.';
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
        return ['rows' => [['label' => 'ID del número', 'value' => $integration->config['phone_number_id']]]];
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
            $status === 200 && (string) $response->json('id') === $id => new TestResult(true, $this->verified($response->json('verified_name'), $response->json('display_phone_number')), $status, $ms),
            $status === 200 => TestResult::failure('Meta respondió con un número distinto al configurado.', $status, $ms),
            $status === 401 || $code === 190 => TestResult::failure('Meta rechazó el token: es inválido o expiró.', $status, $ms),
            in_array($status, [400, 403, 404], true) => TestResult::failure('Meta no encontró ese ID de número o el token no tiene permiso sobre él.', $status, $ms),
            default => TestResult::failure("Meta respondió HTTP {$status}.", $status, $ms),
        };
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        $show = fn (mixed $value) => $value === null || $value === '' ? null : (string) $value;

        return [
            'ID del número' => $show($config['phone_number_id'] ?? null),
            'Token de acceso' => Masked::of($secrets['access_token'] ?? null),
        ];
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
