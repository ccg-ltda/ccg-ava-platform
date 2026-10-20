<?php

namespace App\Console\Commands;

use App\Exceptions\DemoNotAllowed;
use App\Models\Workspace;
use App\Services\DemoConversations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Generates, resets or removes the demo scenarios of Conversations in the administrative Workspace. Refuses to run
 * outside the environments of config/demo.php; `reset` and `clean` remove only conversations marked as demo, and only when the person confirms the
 * Workspace by typing its code (`--confirm=DESARROLLO_DEV`).
 */
#[Signature('demo:conversations {action : generate, reset or clean} {--confirm= : code of the Workspace whose demo conversations may be removed (required by reset and clean)}')]
#[Description('Generate, reset or clean the demo scenarios of Conversations (administrative Workspace, local only)')]
class DemoConversationsCommand extends Command
{
    public function handle(DemoConversations $demo): int
    {
        $action = $this->argument('action');

        if (! in_array($action, ['generate', 'reset', 'clean'], true)) {
            $this->error('La acción debe ser generate, reset o clean.');

            return self::INVALID;
        }

        $workspace = $demo->workspace();

        if (! $workspace) {
            $this->error('No existe el Workspace administrativo; créalo antes (WorkspaceSeeder).');

            return self::FAILURE;
        }

        if ($action !== 'generate' && Workspace::normalizeCode((string) $this->option('confirm')) !== $workspace->code) {
            $this->error("Para eliminar las conversaciones demo escribe el código del Workspace: --confirm={$workspace->code}. No se eliminó nada.");

            return self::FAILURE;
        }

        try {
            if ($action === 'clean') {
                $this->info("Conversaciones demo eliminadas: {$demo->clean($workspace)}.");

                return self::SUCCESS;
            }

            $result = $action === 'reset' ? $demo->reset($workspace) : $demo->generate($workspace);
        } catch (DemoNotAllowed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Escenarios creados: {$result['created']}; ya existían: {$result['existing']}.");

        if ($result['unassigned'] > 0) {
            $this->warn("{$result['unassigned']} escenario(s) de 'en atención' quedaron pendientes: el Workspace no tiene agentes.");
        }

        return self::SUCCESS;
    }
}
