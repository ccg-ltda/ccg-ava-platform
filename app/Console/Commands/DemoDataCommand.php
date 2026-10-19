<?php

namespace App\Console\Commands;

use App\Exceptions\DemoNotAllowed;
use App\Services\DemoBusinessData;
use App\Services\DemoConversations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Generates, counts or removes the demonstration data of the operational modules (Catálogo, Pedidos, Servicios, Sedes, Profesionales, Agenda, Clientes y
 * Campañas) in the administrative Workspace. Refuses to run outside the environments of config/demo.php. It is never run by a seeder or by a deploy: only
 * a person who types it. `clean` removes only records that carry the demo marks.
 */
#[Signature('demo:data {action : generate, status or clean}')]
#[Description('Generate, count or clean the demo data of the operational modules (administrative Workspace, local only)')]
class DemoDataCommand extends Command
{
    public function handle(DemoBusinessData $demo, DemoConversations $conversations): int
    {
        $action = $this->argument('action');

        if (! in_array($action, ['generate', 'status', 'clean'], true)) {
            $this->error('La acción debe ser generate, status o clean.');

            return self::INVALID;
        }

        $workspace = $conversations->workspace();

        if (! $workspace) {
            $this->error('No existe el Workspace administrativo; créalo antes (WorkspaceSeeder).');

            return self::FAILURE;
        }

        try {
            $demo->assertAllowed($workspace);
            $result = match ($action) {
                'generate' => $demo->generate($workspace),
                'clean' => $demo->clean($workspace),
                default => $demo->counts($workspace),
            };
        } catch (DemoNotAllowed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(match ($action) {
            'generate' => "Datos demo en {$workspace->code} (los que faltaban se crearon; los demás se dejaron como estaban):",
            'clean' => 'Datos demo eliminados (solo los que llevan la marca de demostración):',
            default => "Datos demo en {$workspace->code}:",
        });
        $this->table(['Tipo', 'Registros'], collect($result)->map(fn (int $count, string $kind) => [$kind, $count])->values()->all());

        return self::SUCCESS;
    }
}
