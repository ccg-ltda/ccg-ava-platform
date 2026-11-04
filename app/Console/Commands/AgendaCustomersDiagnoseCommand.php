<?php

namespace App\Console\Commands;

use App\Models\BookingCustomer;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('agenda:diagnose-customers {--workspace= : Only this Workspace id}')]
#[Description('READ-ONLY. Counts, per Workspace, how the people of Agenda relate to the cards of Clientes (linked, would match exactly one card, ambiguous, no match). Changes nothing and prints no personal data.')]
class AgendaCustomersDiagnoseCommand extends Command
{
    public function handle(): int
    {
        if (! Schema::hasTable('booking_customers')) {
            $this->components->error('There is no booking_customers table.');

            return self::FAILURE;
        }

        $linkable = Schema::hasColumn('booking_customers', 'customer_id');
        $rows = [];

        Workspace::query()->when($this->option('workspace'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->each(function (Workspace $workspace) use (&$rows, $linkable) {
            $total = $linked = $exact = $ambiguous = $none = $noContact = 0;

            // The cards of the Workspace by identity, built once: a booking person is compared by normalized phone or e-mail, NEVER by name.
            $byPhone = $workspace->customers()->whereNull('anonymized_at')->whereNotNull('phone_normalized')->pluck('id', 'phone_normalized');
            $phoneCount = $workspace->customers()->whereNull('anonymized_at')->whereNotNull('phone_normalized')->selectRaw('phone_normalized, count(*) as n')->groupBy('phone_normalized')->pluck('n', 'phone_normalized');
            $byEmail = $workspace->customers()->whereNull('anonymized_at')->whereNotNull('email_normalized')->pluck('id', 'email_normalized');
            $emailCount = $workspace->customers()->whereNull('anonymized_at')->whereNotNull('email_normalized')->selectRaw('email_normalized, count(*) as n')->groupBy('email_normalized')->pluck('n', 'email_normalized');

            BookingCustomer::where('workspace_id', $workspace->id)->orderBy('id')->each(function (BookingCustomer $person) use (&$total, &$linked, &$exact, &$ambiguous, &$none, &$noContact, $linkable, $byPhone, $phoneCount, $byEmail, $emailCount) {
                $total++;

                if ($linkable && $person->customer_id !== null) {
                    $linked++;

                    return;
                }

                $phone = Customer::normalizePhone($person->phone);
                $email = Customer::normalizeEmail($person->email);

                if ($phone === null && $email === null) {
                    $noContact++;

                    return;
                }

                $ids = collect([
                    $phone !== null && isset($byPhone[$phone]) ? $byPhone[$phone] : null,
                    $email !== null && isset($byEmail[$email]) ? $byEmail[$email] : null,
                ])->filter()->unique();

                $many = ($phone !== null && ($phoneCount[$phone] ?? 0) > 1) || ($email !== null && ($emailCount[$email] ?? 0) > 1);

                match (true) {
                    $ids->isEmpty() => $none++,
                    $many || $ids->count() > 1 => $ambiguous++,
                    default => $exact++,
                };
            });

            $rows[] = [$workspace->id, $total, $linked, $exact, $ambiguous, $none, $noContact];
        });

        $this->table(['Workspace', 'Agenda people', 'Linked', 'Would match 1 card', 'Ambiguous', 'No card', 'No contact data'], $rows);
        $this->line($linkable ? 'Read-only: nothing was linked or changed.' : 'The customer_id column does not exist yet (migration not applied): every person counts as unlinked. Read-only: nothing was changed.');

        return self::SUCCESS;
    }
}
