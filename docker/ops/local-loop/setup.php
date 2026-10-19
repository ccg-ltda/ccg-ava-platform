<?php

// LOCAL TEST HARNESS: fixtures in the DISPOSABLE e2e database only.
use App\Models\Module;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Services\ChatbotWorkflows;
use Illuminate\Contracts\Console\Kernel;

if (! str_ends_with((string) getenv('DB_DATABASE'), '_e2e')) {
    exit("refusing\n");
}
chdir('/var/www/html');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['n8n.workflows.e2e' => ['name' => 'E2E double', 'n8n_workflow_id' => 'wf_e2e', 'webhook_path' => 'ava/e2e-v1', 'version' => '1']]);

$mods = ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings'];
$org = Organization::create(['name' => 'E2E Org']);
foreach (['A' => '1000001', 'B' => '1000002'] as $code => $phone) {
    $ws = Workspace::create(['organization_id' => $org->id, 'code' => "E2E_$code", 'name' => "E2E $code"]);
    $ws->settings()->create(array_merge(WorkspaceSetting::defaults(), ['timezone' => 'America/Bogota']));
    $plan = Plan::create(['name' => "Plan $code"]);
    $plan->modules()->sync(Module::whereIn('key', $mods)->pluck('id'));
    $ws->update(['plan_id' => $plan->id]);
    $bot = $ws->chatbots()->create(['name' => "Asistente $code", 'instructions' => 'Atiende citas']);
    $int = $ws->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => $phone], 'secrets' => ['access_token' => 'LOCAL-DOUBLE-TOKEN']]);
    $bot->channels()->create(['workspace_id' => $ws->id, 'channel' => 'whatsapp', 'integration_id' => $int->id, 'is_active' => true]);
    app(ChatbotWorkflows::class)->assign($bot->refresh(), 'e2e');
    $svc = $ws->services()->create(['name' => 'Consulta', 'duration_minutes' => 30, 'buffer_minutes' => 0]);
    $pro = $ws->professionals()->create(['name' => 'Ana'])->fresh();
    $pro->services()->sync([$svc->id => ['workspace_id' => $ws->id]]);
    foreach ([0, 1, 2, 3, 4, 5, 6] as $day) {
        $pro->scheduleRules()->create(['workspace_id' => $ws->id, 'weekday' => $day, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']);
    }
    echo "workspace $code ready (phone_number_id $phone)\n";
}
