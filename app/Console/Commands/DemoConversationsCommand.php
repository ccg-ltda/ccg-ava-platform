<?php

namespace App\Console\Commands;

use App\Exceptions\DemoNotAllowed;
use App\Services\DemoConversations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Generates, resets or removes the demo scenarios of Conversations in the administrative Workspace. Refuses to run
 * outside the environments of config/demo.php; `clean` removes only conversations marked as demo.
 */
#[Signature('demo:conversations {action : generate, reset or clean}')]
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
