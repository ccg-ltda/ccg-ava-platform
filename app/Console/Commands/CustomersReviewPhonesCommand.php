<?php

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Models\Customer;
use App\Models\Workspace;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Controlled review of HISTORICAL customer phones. It never rewrites `phone` or `phone_normalized` and never merges anyone: it can only
 * fill the separate `phone_e164` column, and only when the original text itself says which country the number is in (a leading "+" or the
 * "00" international call prefix). A local number ("300 111 2233") has no such evidence and is left exactly as it is.
 *
 * Dry run by default. `--apply` and `--revert` need one Workspace (`--workspace=ID`), so a review never crosses companies. `phone_e164` is a
 * pure function of `phone`, which makes the change reversible and repeatable: `--revert` empties it for that Workspace and a new `--apply` fills
 * it again. A number whose international form already belongs to another customer of the Workspace is reported and skipped, never merged.
 * The output has counts only: no phone, name or customer id.
 */
class CustomersReviewPhonesCommand extends Command
{
    protected $signature = 'customers:review-phones {--workspace= : Workspace id (required with --apply or --revert)} {--apply : write the international form of the numbers that prove it} {--revert : empty phone_e164 for the Workspace}';

    protected $description = 'Review historical customer phones and (only with --apply, one Workspace) fill phone_e164 where the original text proves the country; never changes phone or merges customers';

    public function handle(AuditLogger $audit): int
    {
        $apply = (bool) $this->option('apply');
        $revert = (bool) $this->option('revert');
        $id = $this->option('workspace');

        if ($apply && $revert) {
            $this->error('Elige --apply o --revert, no ambos.');

            return self::FAILURE;
        }

        if (($apply || $revert) && ! ctype_digit((string) $id)) {
            $this->error('--apply y --revert exigen --workspace=ID: una revisión nunca cruza Workspaces.');

            return self::FAILURE;
        }

        $workspaces = Workspace::when($id !== null && $id !== '', fn ($query) => $query->whereKey((int) $id))->orderBy('id')->get();

        if ($workspaces->isEmpty()) {
            $this->error('No existe ese Workspace.');

            return self::FAILURE;
        }

        $this->line($apply ? 'MODO APLICAR' : ($revert ? 'MODO REVERTIR' : 'SIMULACIÓN (no se escribe nada)'));

        foreach ($workspaces as $workspace) {
            $revert ? $this->revert($workspace, $audit) : $this->review($workspace, $apply, $audit);
        }

        return self::SUCCESS;
    }

    private function review(Workspace $workspace, bool $apply, AuditLogger $audit): void
    {
        $withPhone = $workspace->customers()->whereNotNull('phone')->whereNull('anonymized_at');
        $already = (clone $withPhone)->whereNotNull('phone_e164')->count();
        $eligible = [];
        $noEvidence = 0;
        $conflicts = 0;

        // What each historical number would become. Two records that would become the SAME number are the same person written twice:
        // Ava does not pick which one keeps it (that is a merge decision), so both are left as they are.
        $proposed = (clone $withPhone)->whereNull('phone_e164')->orderBy('id')->get(['id', 'phone'])
            ->mapWithKeys(fn (Customer $customer) => [$customer->id => PhoneNumber::e164($customer->phone, true)]);
        $noEvidence = $proposed->filter(fn ($e164) => $e164 === null)->count();
        $shared = $proposed->filter()->countBy()->filter(fn (int $count) => $count > 1)->keys();

        foreach ($proposed->filter() as $customerId => $e164) {
            $takenByAnother = $shared->contains($e164) || $workspace->customers()->whereKeyNot($customerId)
                ->where(fn ($query) => $query->where('phone_e164', $e164)->orWhere('phone_normalized', PhoneNumber::digits($e164)))->exists();

            $takenByAnother ? $conflicts++ : $eligible[$customerId] = $e164;
        }

        if ($apply && $eligible !== []) {
            DB::transaction(function () use ($eligible, $workspace) {
                foreach ($eligible as $customerId => $e164) {
                    // Query builder on purpose: only this column changes and no model event rewrites anything else.
                    DB::table('customers')->where('workspace_id', $workspace->id)->where('id', $customerId)->whereNull('phone_e164')->update(['phone_e164' => $e164]);
                }
            });
            $audit->system('updated', 'customer', null, 'Teléfonos internacionales', [AuditLogger::change('Teléfonos con prefijo verificado', null, (string) count($eligible))], $workspace, 'Revisión de teléfonos (consola)');
        }

        $this->line(sprintf(
            'Workspace %d: con teléfono %d | ya internacionales %d | %s %d | sin prefijo comprobable (se dejan igual) %d | en conflicto con otro cliente (se omiten) %d',
            $workspace->id, (clone $withPhone)->count(), $already, $apply ? 'actualizados' : 'actualizables', count($eligible), $noEvidence, $conflicts,
        ));
    }

    private function revert(Workspace $workspace, AuditLogger $audit): void
    {
        $count = DB::table('customers')->where('workspace_id', $workspace->id)->whereNotNull('phone_e164')->update(['phone_e164' => null]);

        if ($count > 0) {
            $audit->system('updated', 'customer', null, 'Teléfonos internacionales', [AuditLogger::change('Teléfonos con prefijo verificado', (string) $count, '0')], $workspace, 'Revisión de teléfonos (consola)');
        }

        $this->line("Workspace {$workspace->id}: valores internacionales vaciados {$count}. phone y phone_normalized no se tocaron.");
    }
}
