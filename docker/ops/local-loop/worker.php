<?php

use Illuminate\Contracts\Console\Kernel;

// LOCAL TEST HARNESS: a queue worker (database driver, disposable e2e DB) with the e2e workflow registered and no waiting between retries.
if (! str_ends_with((string) getenv('DB_DATABASE'), '_e2e')) {
    exit("refusing\n");
}
chdir('/var/www/html');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config([
    'n8n.workflows.e2e' => ['name' => 'E2E double', 'n8n_workflow_id' => 'wf_e2e', 'webhook_path' => 'ava/e2e-v1', 'version' => '1'],
    'n8n.backoff' => [0, 0],
]);
exit($kernel->call('queue:work', ['--sleep' => 1, '--tries' => 6, '--timeout' => 55, '--queue' => 'default']));
