<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Which communication channels a Workspace may CONFIGURE and use: the ones its plan includes (`plans.channels`), independent of the plan's modules and of the user's role (three
 * separate controls). The administrative Workspace always has every channel. A plan whose list was never saved (NULL) keeps every channel, so nothing changes until an
 * administrator chooses. A Workspace without access to any module (suspended, no plan) has none. Restricting a channel never deletes its credentials, connection or
 * conversations: it only stops using them, and they work again when the plan includes the channel.
 *
 * The catalog is the chatbot channels of config/chatbots.php plus `email` (outgoing e-mail of reminders and campaigns). The server decides on every operation; the interface only reflects it.
 */
class WorkspaceChannels
{
    public const EMAIL = 'email';

    private const RESTRICTED = 'Tu plan no incluye este canal. Pide a tu administrador que lo incluya en el plan.';

    public function __construct(private readonly ModuleAccess $modules) {}

    /** @return array<string, string> key => label of every channel a plan can include */
    public static function catalog(): array
    {
        $channels = collect(config('chatbots.channels'))->map(fn (array $channel) => $channel['label'])->all();

        return $channels + [self::EMAIL => 'Correo electrónico'];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    private static bool $ready = false;

    /** Whether the database has `plans.channels` (the migration may not be applied yet). Only a YES is remembered, so a long-lived worker notices the migration without a restart. */
    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasColumn('plans', 'channels');
    }

    /** @return list<string> keys of the channels the Workspace can use now */
    public function allowed(Workspace $workspace): array
    {
        if ($workspace->isAdministrative()) {
            return self::keys();
        }

        if ($this->modules->keys($workspace) === []) {
            return [];
        }

        $plan = $workspace->plan_id ? $workspace->plan : $this->modules->defaultPlan();
        $chosen = $plan instanceof Plan && self::ready() ? $plan->channels : null;

        return is_array($chosen) ? array_values(array_intersect(self::keys(), $chosen)) : self::keys();
    }

    public function allows(Workspace $workspace, string $channel): bool
    {
        return in_array($channel, $this->allowed($workspace), true);
    }

    public function restriction(): string
    {
        return self::RESTRICTED;
    }

    /** @throws ValidationException */
    public function assertAllows(Workspace $workspace, string $channel): void
    {
        if (! $this->allows($workspace, $channel)) {
            throw ValidationException::withMessages(['channel' => self::RESTRICTED]);
        }
    }
}
