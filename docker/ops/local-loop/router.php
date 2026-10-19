<?php

// LOCAL TEST HARNESS (not part of the product): serves Ava with the e2e workflow registered in the catalog, against the disposable e2e database.
use Illuminate\Http\Request;

if (! str_ends_with((string) getenv('DB_DATABASE'), '_e2e')) {
    http_response_code(500);
    exit('refusing: DB_DATABASE must end with _e2e');
}
chdir('/var/www/html');
define('LARAVEL_START', microtime(true));
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->booted(fn () => config([
    'n8n.workflows.e2e' => ['name' => 'E2E double', 'n8n_workflow_id' => 'wf_e2e', 'webhook_path' => 'ava/e2e-v1', 'version' => '1'],
    'n8n.backoff' => [0, 0],
]));
$app->handleRequest(Request::capture());
