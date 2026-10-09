<?php

/*
 * Demo environment of Conversations: generated scenarios and the simulator. It exists only to try the module without
 * real channel APIs. It can run ONLY in these environments (never in production, whatever the user's role), and only
 * inside the administrative Workspace (config/workspace.php `admin_code`).
 */
return [
    'environments' => ['local', 'testing'],
];
