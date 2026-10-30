<?php

namespace App\Reminders;

use App\Integrations\IntegrationRegistry;
use App\Integrations\SendsTemplates;
use App\Models\Integration;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Support\MailAvailability;

/**
 * Which channels a Workspace can REALLY use to send a reminder, decided from what is configured and verified, never from the existence of code or of a widget:
 *  - email: the platform's own mail must deliver real mail (not the `log` / `array` mailers). It is the base channel.
 *  - whatsapp: the Workspace's plan includes Integraciones, it has exactly one ACTIVE WhatsApp account whose last connection test passed, and that channel type can send an approved
 *    template (a follow-up is always outside Meta's 24 h window, so a free text could not go out).
 * The web widget is NOT a channel: Ava has no way to push a message to a visitor who is not connected. An unavailable channel says why and can not be chosen; the server checks
 * again when saving and again when sending.
 */
class ReminderChannels
{
    public function __construct(private readonly ModuleAccess $modules, private readonly IntegrationRegistry $types) {}

    /** @return array{ok: bool, code: ?string, reason: ?string, integration: ?Integration} */
    public function check(Workspace $workspace, string $channel): array
    {
        return match ($channel) {
            'email' => MailAvailability::deliversRealMail()
                ? $this->ok(null)
                : $this->no('mail_not_configured', 'El correo de la plataforma todavía no está configurado para enviar mensajes reales.'),
            'whatsapp' => $this->whatsapp($workspace),
            default => $this->no('unknown_channel', 'Ese canal no existe.'),
        };
    }

    /** @return list<array{key: string, label: string, description: string, available: bool, reason: ?string}> */
    public function offered(Workspace $workspace): array
    {
        return collect(config('reminders.channels'))->map(function (array $channel, string $key) use ($workspace) {
            $check = $this->check($workspace, $key);

            return ['key' => $key, 'label' => $channel['label'], 'description' => $channel['description'], 'available' => $check['ok'], 'reason' => $check['reason']];
        })->values()->all();
    }

    /** @return array{ok: bool, code: ?string, reason: ?string, integration: ?Integration} */
    private function whatsapp(Workspace $workspace): array
    {
        if (! $this->modules->allows($workspace, 'integrations')) {
            return $this->no('plan', 'El plan del Workspace no incluye Integraciones, donde se conecta WhatsApp.');
        }

        $accounts = $workspace->integrations()->where('type', 'whatsapp')->where('is_active', true)->orderBy('id')->get();

        if ($accounts->isEmpty()) {
            return $this->no('no_whatsapp', 'No hay una cuenta de WhatsApp activa en este Workspace.');
        }

        if ($accounts->count() > 1) {
            return $this->no('several_whatsapp', 'Hay varias cuentas de WhatsApp activas: deja una sola para los recordatorios.');
        }

        $account = $accounts->first();

        if (! $account->last_test_ok) {
            return $this->no('not_verified', 'La conexión de WhatsApp no está verificada: pruébala en Integraciones.');
        }

        if (! $this->types->get($account->type) instanceof SendsTemplates) {
            return $this->no('no_templates', 'El canal no puede enviar plantillas.');
        }

        return $this->ok($account);
    }

    /** @return array{ok: bool, code: ?string, reason: ?string, integration: ?Integration} */
    private function ok(?Integration $integration): array
    {
        return ['ok' => true, 'code' => null, 'reason' => null, 'integration' => $integration];
    }

    /** @return array{ok: bool, code: ?string, reason: ?string, integration: ?Integration} */
    private function no(string $code, string $reason): array
    {
        return ['ok' => false, 'code' => $code, 'reason' => $reason, 'integration' => null];
    }
}
