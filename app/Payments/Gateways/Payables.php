<?php

namespace App\Payments\Gateways;

/** Finds the payable of a Workspace by type and id (config/payments.php `payables`). The Workspace is part of the query: another Workspace's payable simply does not exist. */
class Payables
{
    public function find(int $workspaceId, string $type, int $id): ?Payable
    {
        $class = config("payments.payables.{$type}");

        if (! is_string($class) || ! is_a($class, Payable::class, true)) {
            return null;
        }

        return $class::query()->where('workspace_id', $workspaceId)->whereKey($id)->first();
    }
}
