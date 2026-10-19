<?php

/*
 * Saved filters: the modules whose search criteria a user can save and reuse (`saved_filters` table). Each module
 * (`scope`) names the permission needed to use it (the same one that opens its page), the plan module that includes it and the criteria it accepts
 * (validated by App\Services\SavedFilters). Adding a module = one entry here plus its rules in the service and the
 * <SavedFilters> component on its page. Filters of different modules are never mixed.
 */
return [
    'scopes' => [
        'audit' => ['label' => 'Auditoría', 'permission' => 'view-audit', 'module' => 'audit'],
        'conversations' => ['label' => 'Conversaciones', 'permission' => 'view-conversations', 'module' => 'conversations'],
    ],

    /* Most filters one user can keep per module and Workspace, and the longest name. */
    'max_per_scope' => 20,
    'name_max' => 60,
];
