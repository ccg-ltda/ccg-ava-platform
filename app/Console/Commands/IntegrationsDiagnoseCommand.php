<?php

namespace App\Console\Commands;

use App\Booking\AppointmentNotifier;
use App\Legal\LegalDocuments;
use App\Models\AppointmentNotification;
use App\Models\BookingSetting;
use App\Models\Chatbot;
use App\Models\ChatbotChannel;
use App\Models\ChatbotExecution;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use App\Support\MailAvailability;
use App\Support\ScheduledCommandMonitor;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Read-only readiness and health report for the real integrations (Meta WhatsApp webhook, the platform n8n, queues, cache,
 * scheduler). It prints only booleans, counts, identifiers and fixed codes: never a secret, a name, a contact, a phone number
 * or the text of a message, so its output can be pasted into a ticket. `--workspace=ID` limits the counts to one Workspace.
 * Exit code 1 when something that blocks a real integration is wrong.
 */
class IntegrationsDiagnoseCommand extends Command
{
    protected $signature = 'integrations:diagnose {--workspace= : limit the counts to one Workspace id}';

    protected $description = 'Check that Ava is ready for the real WhatsApp / n8n integration and report its health (no secrets, no private data)';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(ModuleAccess $modules): int
    {
        $workspaceId = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        $this->components->info('Entorno y servicios base');
        $this->environment();
        $this->queueAndCache();
        $this->scheduler();

        $this->components->info('Acceso de las cuentas (recuperación de contraseña y textos legales)');
        $this->accountAccess();

        $this->components->info('Meta (WhatsApp Cloud API)');
        $this->meta();

        $this->components->info('n8n de la plataforma');
        $this->n8n();

        $this->components->info('Integraciones por Workspace'.($workspaceId ? " (Workspace {$workspaceId})" : ''));
        $this->workspaces($modules, $workspaceId);

        $this->components->info('Ejecuciones y entregas (últimas 24 h)');
        $this->executions($workspaceId);

        $this->components->info('Módulos y permisos (lo que hace visible un módulo)');
        $this->modulesAndPermissions();

        $this->components->info('Confirmaciones y recordatorios de citas');
        $this->appointmentNotifications($workspaceId);

        $this->newLine();
        $this->line("Resultado: {$this->failures} bloqueo(s), {$this->warnings} aviso(s).");
        $this->line('Esto solo revisa la configuración y el estado interno: no llama a Meta ni a n8n. La conexión real se prueba con «Probar conexión» y un mensaje de prueba.');

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function good(string $text): void
    {
        $this->line("  <fg=green>OK</>     {$text}");
    }

    private function caution(string $text): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>AVISO</>  {$text}");
    }

    private function blocker(string $text): void
    {
        $this->failures++;
        $this->line("  <fg=red>FALLO</>  {$text}");
    }

    private function environment(): void
    {
        $this->good('Entorno: '.app()->environment().'.');

        if (app()->isProduction()) {
            str_starts_with((string) config('app.url'), 'https://') ? $this->good('APP_URL usa https.') : $this->blocker('APP_URL no usa https: Meta y n8n exigen TLS en producción.');
            config('app.debug') ? $this->blocker('APP_DEBUG está activo en producción.') : $this->good('APP_DEBUG apagado.');
            config('integrations.allow_private_hosts') ? $this->blocker('INTEGRATIONS_ALLOW_PRIVATE_HOSTS está activo en producción (permite llamar a la red interna).') : $this->good('Las conexiones salientes no pueden alcanzar la red interna.');
        } elseif (config('integrations.allow_private_hosts')) {
            $this->caution('INTEGRATIONS_ALLOW_PRIVATE_HOSTS activo (solo para pruebas locales contra una API simulada).');
        }

        try {
            DB::select('select 1');
            $this->good('Base de datos: '.DB::getDriverName().' responde.');
            $this->migrations();
        } catch (Throwable) {
            $this->blocker('La base de datos no responde.');
        }
    }

    /** The code may be deployed before the database is migrated: pending migrations break the pages that use their tables. */
    private function migrations(): void
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            $this->blocker('La base de datos no tiene la tabla de migraciones: ejecuta php artisan migrate.');

            return;
        }

        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        $pending = array_values(array_diff($files, $migrator->getRepository()->getRan()));

        $pending === []
            ? $this->good('Migraciones al día ('.count($files).').')
            : $this->blocker(count($pending).' migración(es) pendiente(s) ('.implode(', ', $pending).'): ejecuta php artisan migrate antes de usar el código nuevo.');
    }

    private function queueAndCache(): void
    {
        $queue = config('queue.default');
        $queue === 'sync' ? $this->caution('QUEUE_CONNECTION=sync: las ejecuciones corren dentro de la petición del webhook (Meta espera la respuesta). Usa redis o database con un worker.') : $this->good("Cola: {$queue}.");

        if ($queue === 'database') {
            Schema::hasTable('jobs') ? $this->good('Tabla jobs presente.') : $this->blocker('Falta la tabla jobs para la cola database.');
        }

        Schema::hasTable('failed_jobs') ? $this->good('failed_jobs: '.DB::table('failed_jobs')->count().' trabajo(s) fallido(s).') : $this->blocker('Falta la tabla failed_jobs.');

        try {
            $key = 'ava-diagnose-'.bin2hex(random_bytes(4));
            Cache::put($key, 'x', 10);
            Cache::get($key) === 'x' ? $this->good('Caché ('.config('cache.default').') lee y escribe.') : $this->blocker('La caché no devuelve lo escrito.');
            Cache::forget($key);
        } catch (Throwable) {
            $this->blocker('La caché ('.config('cache.default').') no responde.');
        }
    }

    private function runtime(): void
    {
        $production = app()->isProduction();

        // The scheduler writes a heartbeat every minute (routes/console.php).
        $beat = Cache::get('ava:scheduler:heartbeat');
        match (true) {
            $beat === null => $this->{$production ? 'blocker' : 'caution'}('Sin latido del scheduler: no está corriendo o acaba de arrancar (el latido llega cada minuto). Sin él nada retoma ejecuciones a medias.'),
            now()->timestamp - (int) $beat > 180 => $this->{$production ? 'blocker' : 'caution'}('El scheduler lleva más de 3 minutos sin latir: está detenido.'),
            default => $this->good('Scheduler corriendo (latido reciente).'),
        };

        if (config('queue.default') === 'redis') {
            try {
                $masters = interface_exists(MasterSupervisorRepository::class) ? count(app(MasterSupervisorRepository::class)->all()) : 0;
                $masters > 0 ? $this->good('Horizon corriendo.') : $this->{$production ? 'blocker' : 'caution'}('Horizon no está corriendo: ninguna ejecución ni envío se procesará.');
            } catch (Throwable) {
                $this->caution('No se pudo consultar Horizon (Redis no responde).');
            }
        }

        try {
            $backlog = Queue::size();
            $backlog > 100 ? $this->caution("Cola con {$backlog} trabajos pendientes: ¿faltan workers?") : $this->good("Cola: {$backlog} trabajo(s) pendiente(s).");
        } catch (Throwable) {
            $this->caution('No se pudo leer el tamaño de la cola.');
        }
    }

    private function scheduler(): void
    {
        $this->runtime();

        $scheduled = collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'executions:recover'));

        $scheduled
            ? $this->good('executions:recover está programado (cada minuto); el scheduler del despliegue debe estar corriendo.')
            : $this->blocker('executions:recover no está programado: nada retomará ejecuciones a medias.');

        $this->scheduledCommands();
    }

    /**
     * What each watched scheduled command did: when it last SUCCEEDED (tells "it did not run" apart from "it failed") and how many times it failed in
     * 24 h. A command failing right now (its last failure is newer than its last success) blocks in production.
     */
    private function scheduledCommands(): void
    {
        $production = app()->isProduction();

        foreach (ScheduledCommandMonitor::WATCHED as $name => $every) {
            try {
                $status = ScheduledCommandMonitor::status($name);
            } catch (Throwable) {
                $this->caution('No se pudo leer el estado de los comandos programados (la caché no responde).');

                return;
            }

            $age = $status['last_ok'] === null ? null : (int) floor((now()->timestamp - $status['last_ok']) / 60);
            $failingNow = $status['last_failure'] !== null && ($status['last_ok'] === null || $status['last_failure'] > $status['last_ok']);

            match (true) {
                $failingNow => $this->{$production ? 'blocker' : 'caution'}("{$name}: FALLA ahora ({$status['failures_24h']} falla(s) en 24 h; la última es posterior al último éxito). Causa en storage/logs/scheduler-failures.log."),
                $status['last_ok'] === null => $this->caution("{$name}: sin ninguna ejecución correcta registrada (el scheduler acaba de arrancar o no corre)."),
                $age > max(5, $every * 3) => $this->{$production ? 'blocker' : 'caution'}("{$name}: NO SE EJECUTA desde hace {$age} min (se espera cada {$every} min): el scheduler está detenido o este comando no se programó."),
                $status['failures_24h'] > 0 => $this->caution("{$name}: {$status['failures_24h']} falla(s) en 24 h, ya recuperada(s) (último éxito hace {$age} min). Causa en storage/logs/scheduler-failures.log."),
                default => $this->good("{$name}: último éxito hace {$age} min."),
            };
        }
    }

    private function accountAccess(): void
    {
        // Recovery needs real mail: without it the form says so and sends nothing, so it is a warning (never a block).
        MailAvailability::deliversRealMail()
            ? $this->good('Correo: transporte real y remitente propio (no se envía nada desde este comando).')
            : $this->caution('Correo no configurado (MAIL_MAILER log/array/null o remitente de ejemplo): «¿Olvidaste tu contraseña?» avisa de que no puede enviar el enlace.');

        $tables = app(LegalDocuments::class)->available();
        $required = (bool) config('legal.acceptance_required');

        if (! $tables) {
            $required
                ? $this->blocker('LEGAL_ACCEPTANCE_REQUIRED está activo pero faltan las tablas de aceptaciones: toda cuenta recibe un 503. Ejecuta php artisan migrate.')
                : $this->caution('Faltan las tablas de aceptaciones y de comunicaciones comerciales (migración create_legal_acceptances_tables): el perfil funciona pero no guarda esas preferencias.');
        } else {
            $this->good('Tablas de aceptaciones presentes.');
        }

        if ($required && ! config('legal.reviewed')) {
            $this->caution('Se exige aceptar textos legales que siguen marcados como borrador (LEGAL_DOCUMENTS_REVIEWED=false).');
        }
    }

    private function meta(): void
    {
        $secret = filled(config('services.meta.app_secret'));
        $verify = filled(config('services.meta.webhook_verify_token'));

        $secret && $verify
            ? $this->good('META_APP_SECRET y META_WEBHOOK_VERIFY_TOKEN definidos: el webhook existe.')
            : $this->caution('Falta '.implode(' y ', array_filter([$secret ? null : 'META_APP_SECRET', $verify ? null : 'META_WEBHOOK_VERIFY_TOKEN'])).': el webhook responde 404 y no entra ningún mensaje de WhatsApp.');

        $this->line('         URL del webhook para Meta: '.url('/api/webhooks/whatsapp'));
        $this->line('         Graph API: '.config('integrations.whatsapp.graph_version'));
    }

    private function n8n(): void
    {
        $url = config('n8n.execution_url');
        $secret = filled(config('n8n.secret'));

        if (! filled($url) || ! $secret) {
            $this->caution('Falta '.implode(' y ', array_filter([filled($url) ? null : 'N8N_EXECUTION_URL', $secret ? null : 'N8N_EXECUTION_SECRET'])).': no corre ningún workflow; los mensajes se guardan y se ven en la bandeja, sin respuesta automática.');
        } else {
            $this->good('N8N_EXECUTION_URL y N8N_EXECUTION_SECRET definidos.');
            app()->isProduction() && ! str_starts_with(strtolower($url), 'https://') ? $this->blocker('N8N_EXECUTION_URL no usa https.') : null;
        }

        $workflows = collect(config('n8n.workflows'));
        $enabled = $workflows->filter(fn ($workflow) => ($workflow['enabled'] ?? true) !== false)->count();
        $workflows->isEmpty()
            ? $this->caution('El catálogo config/n8n.php `workflows` está vacío: no hay workflow que asignar a un chatbot.')
            : $this->good("Catálogo de workflows: {$workflows->count()} registrado(s), {$enabled} activo(s).");

        $orphans = Chatbot::whereNotNull('workflow_key')->pluck('workflow_key')->reject(fn (string $key) => ($workflows[$key]['enabled'] ?? false) && isset($workflows[$key]))->count();
        $orphans > 0 ? $this->caution("{$orphans} chatbot(s) tienen un workflow que ya no existe o está apagado en el catálogo (no responden).") : null;
    }

    private function workspaces(ModuleAccess $modules, ?int $workspaceId): void
    {
        $scope = fn ($query) => $workspaceId ? $query->where('workspace_id', $workspaceId) : $query;

        $integrations = $scope(Integration::where('type', 'whatsapp'))->get();
        $this->line('         Integraciones WhatsApp: '.$integrations->count().' ('.$integrations->where('is_active', true)->count().' activas, '.$integrations->where('last_test_ok', true)->count().' con la última prueba correcta).');

        // Two live integrations claiming one number are ignored by the webhook: report them by id, never by number.
        $claims = Integration::where('type', 'whatsapp')->where('is_active', true)->get()->groupBy(fn (Integration $integration) => (string) ($integration->config['phone_number_id'] ?? ''))->filter(fn ($group, $phone) => $phone !== '' && $group->count() > 1);
        foreach ($claims as $group) {
            $this->blocker('Integraciones '.$group->pluck('id')->implode(', ').' reclaman el mismo número: el webhook las ignora a todas.');
        }

        $channels = $scope(ChatbotChannel::where('channel', 'whatsapp')->where('is_active', true))->with('integration', 'chatbot.workspace')->get();
        foreach ($channels as $channel) {
            $problems = array_filter([
                $channel->integration?->is_active ? null : 'integración apagada',
                $channel->integration && blank($channel->integration->config['phone_number_id'] ?? null) ? 'sin phone_number_id' : null,
                $channel->chatbot?->is_active ? null : 'chatbot apagado',
                $channel->chatbot && ! $modules->allowsAll($channel->chatbot->workspace, ModuleAccess::CHANNEL_MODULES) ? 'su plan no incluye Asistentes y Conversaciones' : null,
                $channel->chatbot && ! $channel->chatbot->workspace->is_active ? 'Workspace inactivo' : null,
            ]);

            $problems
                ? $this->caution("Canal WhatsApp #{$channel->id} (Workspace {$channel->workspace_id}) no recibirá mensajes: ".implode(', ', $problems).'.')
                : null;
        }

        $this->line('         Canales WhatsApp activos: '.$channels->count().'.');
        $this->line('         Workspaces activos: '.Workspace::where('is_active', true)->count().'.');
    }

    /**
     * A module is only usable from the interface when FOUR things line up: its code (config/modules.php `implemented`), its row in the catalog
     * (ModuleCatalogSeeder), its permissions in the database (RolesAndPermissionsSeeder) and the migration of its tables. Code deployed without the
     * other steps leaves the module invisible or closed (a menu entry needs its permission; the catalog of Planes needs its row).
     */
    private function modulesAndPermissions(): void
    {
        if (! Schema::hasTable('modules') || ! Schema::hasTable('permissions')) {
            $this->blocker('Faltan las tablas del catálogo de módulos o de permisos.');

            return;
        }

        $missingModules = array_values(array_diff(array_keys(config('modules.implemented')), DB::table('modules')->pluck('key')->all()));
        $missingModules === []
            ? $this->good('Todos los módulos implementados están en el catálogo.')
            : $this->blocker('Módulos implementados que faltan en el catálogo ('.implode(', ', $missingModules).'): ejecuta php artisan db:seed --class=ModuleCatalogSeeder.');

        $missingPermissions = array_values(array_diff(array_keys(config('permissions.permissions')), DB::table('permissions')->pluck('name')->all()));
        $missingPermissions === []
            ? $this->good('Todos los permisos del sistema existen.')
            : $this->blocker('Permisos que faltan ('.implode(', ', $missingPermissions).'): ejecuta php artisan db:seed --class=RolesAndPermissionsSeeder.');

        $admin = DB::table('roles')->where('name', 'admin')->first();
        $lacking = $admin ? array_values(array_diff(
            array_keys(array_filter(config('permissions.permissions'), fn (array $permission) => ! ($permission['platform'] ?? false) && $permission !== config('permissions.permissions.access-private-data'))),
            DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('role_has_permissions.role_id', $admin->id)->pluck('permissions.name')->all(),
        )) : [];
        $lacking === [] ? $this->good('El rol admin tiene todos los permisos de cliente.') : $this->caution('El rol admin no tiene ('.implode(', ', $lacking).'): su menú no mostrará esos módulos.');
    }

    /** Workspaces that asked for confirmations or reminders but cannot send them, and what happened to the messages of the last 24 hours (ids and counts only). */
    private function appointmentNotifications(?int $workspaceId): void
    {
        $notifier = app(AppointmentNotifier::class);

        if (! $notifier->ready()) {
            $this->caution('Faltan las tablas o columnas de avisos de citas (migración pendiente): no se planifica ni se envía nada.');

            return;
        }

        $configured = BookingSetting::query()->with('workspace')->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where(fn ($query) => $query->where('notify_received', true)->orWhere('notify_confirmed', true)->orWhere('reminders_enabled', true))->get();

        $configured->isEmpty() ? $this->good('Ningún Workspace tiene avisos activos.') : $this->good($configured->count().' Workspace(s) con avisos activos.');

        foreach ($configured as $setting) {
            $channel = $notifier->channel($setting->workspace);
            $channel['ok'] ? null : $this->blocker("Workspace {$setting->workspace_id}: tiene avisos activos pero no puede enviarlos ({$channel['code']}).");

            if ($setting->provider !== 'internal') {
                $this->caution("Workspace {$setting->workspace_id}: avisos activos con una agenda externa; no se enviará nada.");
            }
        }

        $recent = AppointmentNotification::query()->where('created_at', '>=', now()->subDay())->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId));
        $counts = (clone $recent)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $this->line('  '.($counts->isEmpty() ? 'Sin avisos planificados en 24 h.' : $counts->map(fn ($total, $status) => "{$status}: {$total}")->implode(' · ')));

        foreach (['failed' => 'fallidos', 'uncertain' => 'sin confirmar (revisar a mano, no se reenvían solos)'] as $status => $label) {
            if (($counts[$status] ?? 0) > 0) {
                $this->caution("{$counts[$status]} aviso(s) {$label}. Detalle: php artisan appointments:notifications --status={$status}");
            }
        }

        $stuck = AppointmentNotification::whereIn('status', [AppointmentNotification::SCHEDULED, AppointmentNotification::QUEUED])->where('scheduled_for', '<', now()->subMinutes(10))->count();
        $stuck > 0 ? $this->blocker("{$stuck} aviso(s) vencidos sin enviar: ¿el scheduler (appointments:notify) o el worker de colas no corren?") : $this->good('Ningún aviso vencido sin enviar.');
    }

    private function executions(?int $workspaceId): void
    {
        $since = now()->subDay();
        $scope = fn ($query) => $workspaceId ? $query->where('workspace_id', $workspaceId) : $query;

        $byStatus = $scope(ChatbotExecution::where('created_at', '>=', $since))->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $this->line('         Ejecuciones: '.($byStatus->isEmpty() ? 'ninguna.' : $byStatus->map(fn ($total, $status) => "{$status}={$total}")->implode(', ').'.'));

        $codes = $scope(ChatbotExecution::where('created_at', '>=', $since)->where('status', 'failed'))->selectRaw('outcome_code, count(*) as total')->groupBy('outcome_code')->pluck('total', 'outcome_code');
        $codes->isNotEmpty() ? $this->caution('Ejecuciones fallidas por código: '.$codes->map(fn ($total, $code) => ($code ?: 'sin código')."={$total}")->implode(', ').'.') : null;

        $handedOver = $scope(ChatbotExecution::where('control_result', 'escalated')->where('created_at', '>=', $since))->count();
        $handedOver > 0 ? $this->caution("{$handedOver} conversación(es) pasaron a una persona en las últimas 24 h porque la IA falló (alerta en storage/logs/alerts.log): atiéndelas desde la bandeja «Pendientes».") : null;

        $stale = $scope(ChatbotExecution::whereIn('status', ['pending', 'running'])->where('updated_at', '<', now()->subMinutes(5)))->count();
        $stale > 0 ? $this->caution("{$stale} ejecución(es) llevan más de 5 minutos sin avanzar (¿worker detenido? ejecuta executions:recover).") : $this->good('Ninguna ejecución atascada.');

        $uncertain = $scope(ChatbotExecution::where('delivery', ChatbotExecution::DELIVERY_UNCERTAIN))->count();
        $unconfirmed = $scope(Message::where('status', Message::UNCONFIRMED))->count();
        ($uncertain + $unconfirmed) > 0
            ? $this->caution("Envíos sin confirmar: {$uncertain} ejecución(es), {$unconfirmed} mensaje(s). Una persona debe verificarlos en WhatsApp; Ava no los reenvía.")
            : $this->good('Ningún envío sin confirmar.');

        $failedDelivery = $scope(ChatbotExecution::where('delivery', ChatbotExecution::DELIVERY_FAILED)->where('created_at', '>=', $since))->count();
        $failedDelivery > 0 ? $this->caution("{$failedDelivery} respuesta(s) rechazadas por WhatsApp en las últimas 24 h (ventana de 24 h, token vencido o número sin permiso).") : null;
    }
}
