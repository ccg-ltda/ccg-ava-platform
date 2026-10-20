<?php

namespace App\Services;

use App\Campaigns\CampaignManager;
use App\Customers\CustomerRegistry;
use App\Exceptions\DemoNotAllowed;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use App\Orders\OrderService;
use App\Orders\ProductCategories;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demonstration data for the operational modules (Catálogo, Pedidos, Servicios, Sedes, Profesionales, Agenda, Clientes and Campañas), so their screens, filters,
 * pagination, detail pages and states can be inspected without real data. It is the only code that creates or removes it.
 *
 * Safety, the same as the demo of Conversations: it runs only in the environments of config/demo.php (never in production) and only in the
 * ADMINISTRATIVE Workspace, which no client sees; it never touches another Workspace and it never contacts a provider (no message leaves Ava).
 *
 * How it is recognised: every record carries an explicit mark in a field the person sees — names start with «Demo ·», SKUs with «DEMO-» and every
 * contact uses the reserved domain `demo.invalid` or the fictitious number range +1 202 555 01xx. `clean` removes ONLY what carries those marks, in
 * the order the foreign keys need, and leaves anything a person created. `generate` is idempotent: it creates what is missing to reach TARGETS and leaves the rest as it is.
 * Everything is deterministic (no randomness), so two runs give the same data.
 */
class DemoBusinessData
{
    public const PREFIX = 'Demo · ';

    public const DOMAIN = 'demo.invalid';

    public const SKU = 'DEMO-';

    /**
     * How many records each module holds in the Workspace once it is prepared for demonstration. A target is the TOTAL of the module (records that people
     * created count too), never an amount added on top: the generator creates only what is missing to reach it, so a second run adds nothing and
     * nothing that already exists is touched. The lists below are ordered so that the first records already show every state.
     */
    public const TARGETS = [
        'customers' => 10, 'products' => 10, 'services' => 10, 'orders' => 10, 'locations' => 5, 'professionals' => 10,
        'resources' => 10, 'exceptions' => 3, 'appointments' => 10, 'templates' => 3, 'campaigns' => 1,
    ];

    private const FIRST_NAMES = ['Laura', 'Carlos', 'Marta', 'Andrés', 'Sofía', 'Diego', 'Valentina', 'Mateo', 'Camila', 'Julián', 'Isabela', 'Sergio'];

    private const LAST_NAMES = ['Gómez', 'Rodríguez', 'Pérez', 'Martínez', 'López', 'Ramírez', 'Torres', 'Castro', 'Vargas', 'Ortiz'];

    public function __construct(
        private readonly CustomerRegistry $customers,
        private readonly OrderService $orders,
        private readonly CampaignManager $campaigns,
        private readonly DemoConversations $guard,
        private readonly ProductCategories $categories,
    ) {}

    /** @throws DemoNotAllowed */
    public function assertAllowed(Workspace $workspace): void
    {
        $this->guard->assertAllowed($workspace);
    }

    /** How many records of a module may still be created to reach its target (the TOTAL of the module counts, whoever created each record). */
    private function room(int $existing, string $kind): int
    {
        return max(0, self::TARGETS[$kind] - $existing);
    }

    /**
     * Realigns the demo appointments that an earlier version of the generator stored with the working hours read as UTC instead of as local hours of the
     * Workspace (they showed hours before the professional starts). Only appointments that are unmistakably demo are considered: their customer uses the reserved
     * domain and their service and professional carry the demo prefix. They are grouped by the minute they were created in (one run of the generator). A group is
     * stale when at least one of its appointments falls OUTSIDE the working hours of its professional as stored but INSIDE them once its stored wall-clock time is
     * read as local time: that proves the whole run was stored that way, so every appointment of the group that fits read as local is moved (some happen to fit
     * both ways, which is why one proof covers the group). `starts_at`, `ends_at`, `blocks_until` and `cancelled_at` move by the same offset, so duration, order and
     * relations stay. A group with no such proof, manual appointments and anything that does not fit read as local are never touched; running it again changes nothing.
     *
     * @return list<array{id: int, from: string, to: string, local: string, applied: bool}> the appointments to realign (or realigned with `$apply`)
     *
     * @throws DemoNotAllowed
     */
    public function realignAppointmentTimes(Workspace $workspace, bool $apply = false): array
    {
        $this->assertAllowed($workspace);

        $timezone = $workspace->settingsOrDefault()->timezone;
        $candidates = [];

        foreach ($this->demoAppointments($workspace)->where('service_name', 'like', self::PREFIX.'%')->where('professional_name', 'like', self::PREFIX.'%')->orderBy('id')->get() as $appointment) {
            $wall = fn ($moment) => CarbonImmutable::parse($moment->format('Y-m-d H:i:s'), 'UTC');
            $stored = ['start' => $wall($appointment->starts_at), 'end' => $wall($appointment->ends_at)];
            $local = ['start' => CarbonImmutable::parse($stored['start']->format('Y-m-d H:i:s'), $timezone), 'end' => CarbonImmutable::parse($stored['end']->format('Y-m-d H:i:s'), $timezone)];
            $rules = $workspace->professionals()->whereKey($appointment->professional_id)->first()?->scheduleRules()->get() ?? collect();
            // Whether the whole appointment lies inside the hours of some rule of its weekday, reading both moments in the Workspace's zone.
            $fits = fn (CarbonImmutable $start, CarbonImmutable $end) => $rules->contains(fn ($rule) => (int) $rule->weekday === $start->dayOfWeekIso
                && substr((string) $rule->starts_at, 0, 5) <= $start->format('H:i') && $end->format('H:i') <= substr((string) $rule->ends_at, 0, 5));

            $candidates[] = [
                'id' => $appointment->id,
                'batch' => $appointment->created_at->format('Y-m-d H:i'),
                'asStored' => $fits($stored['start']->setTimezone($timezone), $stored['end']->setTimezone($timezone)),
                'asLocal' => $fits($local['start'], $local['end']),
                'from' => $stored['start'],
                'to' => $local['start']->utc(),
            ];
        }

        $stale = collect($candidates)->filter(fn (array $row) => ! $row['asStored'] && $row['asLocal'])->pluck('batch')->unique()->all();
        $plan = array_values(array_filter($candidates, fn (array $row) => in_array($row['batch'], $stale, true) && $row['asLocal']));

        if ($apply && $plan !== []) {
            DB::transaction(function () use ($workspace, $plan) {
                foreach ($plan as $row) {
                    $appointment = $workspace->appointments()->lockForUpdate()->findOrFail($row['id']);

                    // Guard: the row is still exactly what was planned (nothing else touched it meanwhile).
                    if ($appointment->starts_at->format('Y-m-d H:i') !== $row['from']->format('Y-m-d H:i')) {
                        continue;
                    }

                    $seconds = $row['to']->diffInSeconds($row['from'], false) * -1;
                    $move = fn ($moment) => $moment?->copy()->addSeconds($seconds);
                    $appointment->forceFill(['starts_at' => $move($appointment->starts_at), 'ends_at' => $move($appointment->ends_at), 'blocks_until' => $move($appointment->blocks_until), 'cancelled_at' => $move($appointment->cancelled_at)])->saveQuietly();
                }
            });
        }

        return array_map(fn (array $row) => ['id' => $row['id'], 'from' => $row['from']->format('Y-m-d H:i'), 'to' => $row['to']->format('Y-m-d H:i'), 'local' => $row['to']->setTimezone($workspace->settingsOrDefault()->timezone)->format('Y-m-d H:i'), 'applied' => $apply], $plan);
    }

    /** @return array<string, int> what the demo set of the Workspace holds now */
    public function counts(Workspace $workspace): array
    {
        return [
            'customers' => $this->demoCustomers($workspace)->count(),
            'products' => $workspace->products()->where('sku', 'like', self::SKU.'%')->count(),
            'orders' => $this->demoOrders($workspace)->count(),
            'services' => $workspace->services()->where('name', 'like', self::PREFIX.'%')->count(),
            'locations' => $workspace->locations()->where('name', 'like', self::PREFIX.'%')->count(),
            'professionals' => $workspace->professionals()->where('name', 'like', self::PREFIX.'%')->count(),
            'appointments' => $this->demoAppointments($workspace)->count(),
            'resources' => $workspace->bookableResources()->where('name', 'like', self::PREFIX.'%')->count(),
            'exceptions' => $workspace->scheduleExceptions()->where('reason', 'like', self::PREFIX.'%')->count(),
            'templates' => $workspace->messageTemplates()->where('name', 'like', self::PREFIX.'%')->count(),
            'campaigns' => $workspace->campaigns()->where('name', 'like', self::PREFIX.'%')->count(),
        ];
    }

    /**
     * Creates what is missing. Inside one transaction: either the whole set exists or nothing changed.
     *
     * @return array<string, int> how many records of each kind exist afterwards
     *
     * @throws DemoNotAllowed
     */
    public function generate(Workspace $workspace): array
    {
        $this->assertAllowed($workspace);

        DB::transaction(function () use ($workspace) {
            $locations = $this->locations($workspace);
            $services = $this->services($workspace, $locations);
            $professionals = $this->professionals($workspace, $services, $locations);
            $this->resourcesAndExceptions($workspace, $services, $professionals);
            $customers = $this->customerRecords($workspace);
            $this->appointments($workspace, $services, $professionals, $locations);
            $products = $this->products($workspace, $locations);
            $this->orders($workspace, $products, $customers, $locations);
            $this->campaignsAndTemplates($workspace);
        });

        return $this->counts($workspace);
    }

    /**
     * Removes only what carries the demo marks. Records are removed in the order that keeps every foreign key valid; a record a person created in
     * the same tables is never touched. Returns how many records of each kind were removed.
     *
     * @return array<string, int>
     *
     * @throws DemoNotAllowed
     */
    public function clean(Workspace $workspace): array
    {
        $this->assertAllowed($workspace);
        $before = $this->counts($workspace);

        DB::transaction(function () use ($workspace) {
            // Appointments and orders reference the rest, so they go first.
            $appointments = $this->demoAppointments($workspace)->pluck('id');
            DB::table('appointment_notifications')->whereIn('appointment_id', $appointments)->delete();
            $workspace->appointments()->whereIn('id', $appointments)->delete();
            $workspace->bookingCustomers()->where('email', 'like', '%@'.self::DOMAIN)->delete();

            $orders = $this->demoOrders($workspace)->pluck('id');
            DB::table('order_status_changes')->whereIn('order_id', $orders)->delete();
            DB::table('order_lines')->whereIn('order_id', $orders)->delete();
            $workspace->orders()->whereIn('id', $orders)->delete();

            $campaigns = $workspace->campaigns()->where('name', 'like', self::PREFIX.'%')->pluck('id');
            DB::table('campaign_recipients')->whereIn('campaign_id', $campaigns)->delete();
            $workspace->campaigns()->whereIn('id', $campaigns)->delete();
            $workspace->messageTemplates()->where('name', 'like', self::PREFIX.'%')->delete();

            $customers = $this->demoCustomers($workspace)->pluck('id');
            DB::table('customer_identities')->whereIn('customer_id', $customers)->delete();
            DB::table('customer_tags')->whereIn('customer_id', $customers)->delete();
            $workspace->customers()->whereIn('id', $customers)->delete();

            $professionals = $workspace->professionals()->where('name', 'like', self::PREFIX.'%')->pluck('id');
            DB::table('schedule_rules')->whereIn('professional_id', $professionals)->delete();
            DB::table('schedule_exceptions')->where('workspace_id', $workspace->id)->where('reason', 'like', self::PREFIX.'%')->delete();
            DB::table('professional_service')->whereIn('professional_id', $professionals)->delete();
            $workspace->professionals()->whereIn('id', $professionals)->delete();

            $resources = $workspace->bookableResources()->where('name', 'like', self::PREFIX.'%')->pluck('id');
            DB::table('resource_service')->whereIn('resource_id', $resources)->delete();
            $workspace->bookableResources()->whereIn('id', $resources)->delete();
            $services = $workspace->services()->where('name', 'like', self::PREFIX.'%')->pluck('id');
            DB::table('location_service')->whereIn('service_id', $services)->delete();
            $workspace->services()->whereIn('id', $services)->delete();

            $products = $workspace->products()->where('sku', 'like', self::SKU.'%')->pluck('id');
            DB::table('location_product')->whereIn('product_id', $products)->delete();
            $workspace->products()->whereIn('id', $products)->delete();

            $locations = $workspace->locations()->where('name', 'like', self::PREFIX.'%')->pluck('id');
            DB::table('location_service')->whereIn('location_id', $locations)->delete();
            DB::table('location_product')->whereIn('location_id', $locations)->delete();
            $workspace->locations()->whereIn('id', $locations)->delete();
        });

        return $before;
    }

    // --- Sedes, servicios, profesionales ---------------------------------------------------------------------------------

    /** @return array<string, Location> keyed by a short name */
    private function locations(Workspace $workspace): array
    {
        $rows = [
            'centro' => ['Centro', 'Calle 10 # 5-20', 'Bogotá', true],
            'norte' => ['Norte', 'Carrera 15 # 100-30', 'Bogotá', true],
            'cerrada' => ['Cerrada (temporal)', 'Avenida 1 # 2-3', 'Medellín', false],
            'sur' => ['Sur', 'Carrera 50 # 20-15', 'Medellín', true],
            'occidente' => ['Occidente', 'Calle 80 # 69-40', 'Cali', true],
        ];
        $locations = [];
        $room = $this->room($workspace->locations()->count(), 'locations');

        foreach ($rows as $key => [$name, $address, $city, $active]) {
            $existing = $workspace->locations()->where('name', self::PREFIX.$name)->first();

            if (! $existing && $room-- <= 0) {
                continue;
            }

            $locations[$key] = $existing ?? $workspace->locations()->create([
                'name' => self::PREFIX.$name, 'address' => $address, 'city' => $city, 'phone' => '+1202555010'.(count($locations) + 1), 'notes' => 'Sede de demostración.', 'is_active' => $active,
            ]);
        }

        return $locations;
    }

    /** @return array<string, Service> */
    private function services(Workspace $workspace, array $locations): array
    {
        $rows = [
            'corte' => ['Corte de cabello', 'Belleza', 45, 10, 35000, true],
            'color' => ['Coloración', 'Belleza', 120, 15, 120000, true],
            'barba' => ['Arreglo de barba', 'Belleza', 30, 5, 20000, true],
            'manicure' => ['Manicure', 'Estética', 60, 10, 45000, true],
            'masaje' => ['Masaje relajante', 'Bienestar', 60, 15, 90000, true],
            'limpieza' => ['Limpieza facial', 'Estética', 75, 10, 80000, true],
            'consulta' => ['Consulta de valoración', 'Consultas', 30, 0, null, true],
            'depilacion' => ['Depilación con cera', 'Estética', 45, 10, 55000, true],
            'retirado' => ['Peinado de novia (retirado)', 'Belleza', 180, 30, 250000, false],
            'tratamiento' => ['Tratamiento capilar', 'Belleza', 60, 10, 70000, true],
        ];
        $services = [];

        $room = $this->room($workspace->services()->count(), 'services');

        foreach ($rows as $key => [$name, $category, $minutes, $buffer, $price, $active]) {
            $existing = $workspace->services()->where('name', self::PREFIX.$name)->first();

            if (! $existing && $room-- <= 0) {
                continue;
            }

            $services[$key] = $existing ?? $workspace->services()->create([
                'name' => self::PREFIX.$name, 'description' => 'Servicio de demostración.', 'category' => $category, 'duration_minutes' => $minutes, 'buffer_minutes' => $buffer, 'price' => $price, 'is_active' => $active,
            ]);
        }

        // Which sede offers what: the first sede offers everything, the second only some (a service with no row would be offered in every sede).
        foreach ([
            'centro' => ['corte', 'color', 'barba', 'manicure', 'masaje', 'limpieza', 'consulta'],
            'norte' => ['corte', 'barba', 'consulta'],
            'sur' => ['corte', 'barba', 'manicure', 'depilacion', 'tratamiento'],
            'occidente' => ['limpieza', 'masaje', 'depilacion'],
        ] as $location => $keys) {
            foreach (array_filter($keys, fn (string $key) => isset($services[$key], $locations[$location])) as $key) {
                $locations[$location]->services()->syncWithoutDetaching([$services[$key]->id => ['workspace_id' => $workspace->id]]);
            }
        }

        return $services;
    }

    /** @return array<string, Professional> */
    private function professionals(Workspace $workspace, array $services, array $locations): array
    {
        $rows = [
            'ana' => ['Ana Ríos', 'Estilista', ['corte', 'color', 'barba'], [1, 2, 3, 4, 5], '09:00', '17:00', true],
            'luis' => ['Luis Mora', 'Barbero', ['corte', 'barba'], [2, 3, 4, 5, 6], '10:00', '18:00', true],
            'paula' => ['Paula Sierra', 'Esteticista', ['manicure', 'limpieza', 'masaje'], [1, 3, 5, 6], '08:00', '16:00', true],
            'jorge' => ['Jorge Nieto', 'Médico general', ['consulta'], [1, 2, 3, 4], '08:00', '12:00', true],
            'ausente' => ['Marcela Díaz (inactiva)', 'Estilista', ['corte'], [1, 2], '09:00', '13:00', false],
            'sara' => ['Sara Beltrán', 'Colorista', ['color', 'tratamiento'], [2, 3, 4, 5], '09:00', '17:00', true],
            'david' => ['David Quintero', 'Barbero', ['corte', 'barba'], [1, 3, 5, 6], '10:00', '18:00', true],
            'elena' => ['Elena Cruz', 'Esteticista', ['depilacion', 'limpieza'], [1, 2, 4, 5], '08:00', '16:00', true],
            'mario' => ['Mario Pardo', 'Masajista', ['masaje'], [2, 4, 6], '09:00', '15:00', true],
            'nora' => ['Nora Herrera', 'Manicurista', ['manicure'], [1, 2, 3, 5, 6], '09:00', '17:00', true],
        ];
        $professionals = [];

        $room = $this->room($workspace->professionals()->count(), 'professionals');
        $sedeOf = ['luis' => 'norte', 'david' => 'sur', 'nora' => 'sur', 'elena' => 'occidente'];

        foreach ($rows as $key => [$name, $specialty, $offered, $weekdays, $from, $to, $active]) {
            $existing = $workspace->professionals()->where('name', self::PREFIX.$name)->first();

            if (! $existing && $room-- <= 0) {
                continue;
            }

            $professional = $existing ?? $workspace->professionals()->create(['name' => self::PREFIX.$name, 'specialty' => $specialty, 'phone' => null, 'is_active' => $active]);

            if (! $existing) {
                $professional->services()->sync(collect($offered)->filter(fn (string $service) => isset($services[$service]))->mapWithKeys(fn (string $service) => [$services[$service]->id => ['workspace_id' => $workspace->id]])->all());

                foreach ($weekdays as $weekday) {
                    $professional->scheduleRules()->create(['workspace_id' => $workspace->id, 'location_id' => isset($sedeOf[$key]) ? ($locations[$sedeOf[$key]] ?? null)?->id : null, 'weekday' => $weekday, 'starts_at' => "{$from}:00", 'ends_at' => "{$to}:00"]);
                }
            }

            $professionals[$key] = $professional;
        }

        return $professionals;
    }

    private function resourcesAndExceptions(Workspace $workspace, array $services, array $professionals): void
    {
        $resourceRoom = $this->room($workspace->bookableResources()->count(), 'resources');

        foreach ([
            ['Sala de masajes', 'Sala', ['masaje']], ['Silla de manicure', 'Silla', ['manicure']], ['Sala de masajes 2', 'Sala', ['masaje']],
            ['Silla de corte 1', 'Silla', ['corte', 'barba']], ['Silla de corte 2', 'Silla', ['corte', 'barba']], ['Lavacabezas', 'Equipo', ['color', 'tratamiento']],
            ['Cabina facial', 'Cabina', ['limpieza']], ['Cabina de depilación', 'Cabina', ['depilacion']], ['Consultorio 1', 'Consultorio', ['consulta']],
            ['Silla de manicure 2', 'Silla', ['manicure']],
        ] as [$name, $kind, $offered]) {
            $existing = $workspace->bookableResources()->where('name', self::PREFIX.$name)->first();

            if (! $existing && $resourceRoom-- <= 0) {
                continue;
            }

            $resource = $existing ?? $workspace->bookableResources()->create(['name' => self::PREFIX.$name, 'kind' => $kind, 'is_active' => true]);

            if (! $existing) {
                foreach (array_filter($offered, fn (string $service) => isset($services[$service])) as $service) {
                    DB::table('resource_service')->insertOrIgnore(['workspace_id' => $workspace->id, 'resource_id' => $resource->id, 'service_id' => $services[$service]->id]);
                }
            }
        }

        $today = now()->startOfDay();
        $exceptionRoom = $this->room($workspace->scheduleExceptions()->count(), 'exceptions');
        foreach ([
            [null, $today->copy()->addDays(9), $today->copy()->addDays(9), 'Festivo'],
            [$professionals['ana'] ?? null, $today->copy()->addDays(3), $today->copy()->addDays(5), 'Vacaciones'],
            [null, $today->copy()->addDays(20), $today->copy()->addDays(20), 'Día no laborable (inventario)'],
        ] as [$professional, $from, $to, $reason]) {
            $criteria = ['reason' => self::PREFIX.$reason, 'professional_id' => $professional?->id];

            if (! $workspace->scheduleExceptions()->where($criteria)->exists() && $exceptionRoom-- > 0) {
                $workspace->scheduleExceptions()->create($criteria + ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]);
            }
        }
    }

    // --- Clientes y Agenda ---------------------------------------------------------------------------------------------------

    /** @return list<Customer> */
    private function customerRecords(Workspace $workspace): array
    {
        $records = [];
        $consents = ['granted', 'granted', 'unknown', 'revoked', 'granted', 'unknown'];
        $tags = [['vip'], ['mayorista'], ['nuevo'], [], ['vip', 'frecuente'], ['frecuente']];

        $room = $this->room($workspace->customers()->count(), 'customers');

        for ($n = 1; $n <= 36; $n++) {
            $email = sprintf('cliente%02d@%s', $n, self::DOMAIN);
            $existing = $workspace->customers()->where('email_normalized', $email)->first();

            if ($existing) {
                $records[] = $existing;

                continue;
            }

            if ($room-- <= 0) {
                continue;
            }

            $consent = $consents[$n % count($consents)];
            $records[] = $this->customers->save($workspace, [
                'first_name' => self::FIRST_NAMES[$n % count(self::FIRST_NAMES)],
                'last_name' => self::LAST_NAMES[$n % count(self::LAST_NAMES)].' (demo '.sprintf('%02d', $n).')',
                'phone' => sprintf('+1202555%04d', 100 + $n),
                'email' => $email,
                'source' => ['manual', 'conversation', 'booking', 'import'][$n % 4],
                'notes' => $n % 5 === 0 ? 'Prefiere atención por la tarde. (Dato de demostración)' : null,
                'marketing_consent' => $consent,
                'consent_note' => $consent === 'granted' ? 'Autorizó en el formulario de demostración' : null,
                'preferred_channel' => $n % 3 === 0 ? 'email' : 'whatsapp',
                'is_active' => $n % 9 !== 0, // a few inactive ones
            ], $tags[$n % count($tags)], $n % 4 === 0 ? [['channel' => 'whatsapp', 'external_id' => sprintf('1202555%04d', 100 + $n)]] : []);
        }

        return $records;
    }

    /** Appointments of the last month and the next two weeks, in every state; never two for the same professional at the same time. */
    private function appointments(Workspace $workspace, array $services, array $professionals, array $locations): void
    {
        $room = $this->room($workspace->appointments()->count(), 'appointments');

        if ($room === 0) {
            return;
        }

        $pairs = [
            ['ana', 'corte', 'centro'], ['ana', 'color', 'centro'], ['luis', 'barba', 'norte'], ['luis', 'corte', 'norte'],
            ['paula', 'manicure', 'centro'], ['paula', 'limpieza', 'centro'], ['jorge', 'consulta', 'centro'], ['paula', 'masaje', 'centro'],
        ];
        // Offset in days (negative = past), status and how it came in. A cancelled one carries its reason; a future one is confirmed or requested.
        // Ordered so the first ones already show every state, past and future; the rest only matters when the Workspace has fewer records.
        $plan = [
            [-14, 'completed', 'admin'], [-9, 'completed', 'web'], [-5, 'no_show', 'chat'], [-3, 'cancelled', 'web'], [-1, 'completed', 'admin'],
            [1, 'confirmed', 'admin'], [2, 'requested', 'chat'], [5, 'confirmed', 'web'], [8, 'confirmed', 'admin'], [10, 'requested', 'web'], [12, 'cancelled', 'web'], [13, 'confirmed', 'chat'],
        ];
        $currency = $workspace->settingsOrDefault()->currency;
        // The working hours of the professionals are LOCAL hours of the Workspace: the appointments are built in its time zone and stored in UTC, so they
        // fall inside those hours when the agenda shows them (building them in UTC put them hours off).
        $timezone = $workspace->settingsOrDefault()->timezone;
        $taken = [];
        // The days (1 = Monday) and the starting hours each professional works, as set in professionals().
        $schedules = ['ana' => [[1, 2, 3, 4, 5], [9, 10, 11, 13, 14]], 'luis' => [[2, 3, 4, 5, 6], [10, 11, 12, 14, 15]], 'paula' => [[1, 3, 5, 6], [8, 9, 10, 11, 13]], 'jorge' => [[1, 2, 3, 4], [8, 9, 10, 11]]];

        foreach ($plan as $index => [$offset, $status, $channel]) {
            if ($room <= 0) {
                break;
            }

            [$professionalKey, $serviceKey, $locationKey] = $pairs[$index % count($pairs)];
            $professional = $professionals[$professionalKey];
            $service = $services[$serviceKey];
            // Always inside the professional's own days and hours; the second pass over the plan uses other hours so a day never overlaps itself.
            [$days, $hours] = $schedules[$professionalKey];
            $start = now($timezone)->startOfDay()->addDays($offset);

            while (! in_array($start->dayOfWeekIso, $days, true)) {
                $start = $start->addDay();
            }

            $start = $start->setTime($hours[$index % count($hours)], $index % 2 === 0 ? 0 : 30)->utc();
            $end = $start->copy()->addMinutes($service->duration_minutes);
            $until = $end->copy()->addMinutes($service->buffer_minutes);

            // Never two appointments of one professional that overlap (the database refuses them in PostgreSQL, and a real agenda would too).
            $clash = collect($taken[$professional->id] ?? [])->contains(fn (array $busy) => $start->lt($busy[1]) && $until->gt($busy[0]));

            if ($clash) {
                continue;
            }
            $taken[$professional->id][] = [$start, $until];
            $room--;

            $customerNumber = ($index % 30) + 1;
            $contact = $workspace->bookingCustomers()->firstOrCreate(
                ['email' => sprintf('cliente%02d@%s', $customerNumber, self::DOMAIN)],
                ['name' => 'Cliente Demo '.sprintf('%02d', $customerNumber), 'phone' => sprintf('1202555%04d', 100 + $customerNumber)],
            );

            $workspace->appointments()->create([
                'service_id' => $service->id, 'professional_id' => $professional->id, 'customer_id' => $contact->id,
                'service_name' => $service->name, 'professional_name' => $professional->name,
                'location_id' => $locations[$locationKey]->id, 'location_name' => $locations[$locationKey]->name,
                'price_amount' => $service->price, 'price_currency' => $service->price === null ? null : $currency,
                'origin_channel' => $channel === 'chat' ? 'whatsapp' : ($channel === 'web' ? 'web' : null),
                'starts_at' => $start, 'ends_at' => $end, 'blocks_until' => $until,
                'status' => $status, 'provider' => 'internal', 'sync_status' => 'none', 'channel' => $channel,
                'token_hash' => hash('sha256', Str::random(40)), 'notes' => $index % 4 === 0 ? 'Cita de demostración.' : null,
                'cancelled_at' => $status === 'cancelled' ? $start->copy()->subDay() : null,
                'cancel_reason' => $status === 'cancelled' ? 'El cliente no pudo asistir (demostración)' : null,
            ]);
        }
    }

    // --- Catálogo y Pedidos ------------------------------------------------------------------------------------------------

    /** @return array<string, Product> */
    private function products(Workspace $workspace, array $locations): array
    {
        $rows = [
            ['01', 'Hamburguesa clásica', 'Comida', 18000, true], ['02', 'Hamburguesa doble', 'Comida', 26000, true], ['03', 'Pizza personal', 'Comida', 22000, true],
            ['05', 'Papas a la francesa', 'Acompañamientos', 8000, true], ['07', 'Limonada natural', 'Bebidas', 6000, true], ['10', 'Brownie con helado', 'Postres', 12000, true],
            ['12', 'Combo familiar', 'Combos', 68000, true], ['13', 'Sopa del día (agotada)', 'Comida', 13000, false], ['09', 'Café americano', 'Bebidas', 5000, true],
            ['04', 'Ensalada de la casa', 'Comida', 15000, true], ['06', 'Aros de cebolla', 'Acompañamientos', 9000, true], ['08', 'Gaseosa 400 ml', 'Bebidas', 4500, true],
            ['11', 'Cheesecake', 'Postres', 14000, true], ['14', 'Promoción de temporada (retirada)', 'Combos', 49000, false],
        ];
        $products = [];

        $room = $this->room($workspace->products()->count(), 'products');

        foreach ($rows as [$code, $name, $category, $price, $active]) {
            $existing = $workspace->products()->where('sku', self::SKU.$code)->first();

            if (! $existing && $room-- <= 0) {
                continue;
            }

            // The category is a valid one of the Workspace (created once, whatever the capitalization), like a product made from the screen.
            $normalized = $this->categories->ready() ? $this->categories->ensure($workspace, $category)[0] : null;
            $products[$code] = $existing ?? $workspace->products()->create(['sku' => self::SKU.$code, 'name' => self::PREFIX.$name, 'description' => 'Producto de demostración.', 'category' => $normalized?->name ?? $category, 'category_id' => $normalized?->id, 'price' => $price, 'is_active' => $active]);
        }

        // Some products are only sold in some sedes (the others are sold everywhere).
        foreach (array_filter(['10', '11'], fn (string $code) => isset($products[$code], $locations['centro'])) as $code) {
            $locations['centro']->products()->syncWithoutDetaching([$products[$code]->id => ['workspace_id' => $workspace->id]]);
        }

        return $products;
    }

    /** Orders in every state, pickup and delivery, built with the real service so totals, numbers and history follow the real rules. */
    private function orders(Workspace $workspace, array $products, array $customers, array $locations): void
    {
        $room = $this->room($workspace->orders()->count(), 'orders');
        $codes = array_keys(array_filter($products, fn (Product $product) => $product->is_active));
        // The state each order ends in: the path walks the allowed graph, so the history of each one is complete.
        // Ordered so the first ones already cover every state.
        $paths = [['received'], ['confirmed'], ['preparing'], ['ready'], ['dispatched'], ['delivered'], ['cancelled'], ['delivered'], ['received'], ['delivered'], ['dispatched'], ['cancelled']];
        $flow = ['received', 'confirmed', 'preparing', 'ready', 'dispatched', 'delivered'];

        if ($customers === [] || $codes === []) {
            return;
        }

        // Each run continues where the demo orders already are, so a repeated run creates none once the target is reached.
        $done = $this->demoOrders($workspace)->count();

        foreach (array_slice($paths, $done, $room) as $offset => [$final]) {
            $index = $done + $offset;
            $customer = $customers[$index % count($customers)];
            $delivery = in_array($final, ['dispatched'], true) || $index % 3 === 0;
            $lines = [];
            foreach ([0, 3, 7] as $step) {
                if (($index + $step) % 4 !== 3) {
                    $lines[] = ['product_id' => $products[$codes[($index + $step) % count($codes)]]->id, 'quantity' => 1 + (($index + $step) % 3)];
                }
            }
            $lines = $lines ?: [['product_id' => $products[$codes[0]]->id, 'quantity' => 1]];

            $order = $this->orders->create($workspace, [
                'customer_id' => $customer->id,
                'customer_name' => $customer->fullName(),
                'customer_phone' => $customer->phone,
                'customer_email' => sprintf('cliente%02d@%s', ($index % 36) + 1, self::DOMAIN),
                'delivery_method' => $delivery ? 'delivery' : 'pickup',
                'delivery_address' => $delivery ? 'Calle '.(10 + $index).' # 4-'.(20 + $index).' (demostración)' : null,
                'delivery_city' => $delivery ? 'Bogotá' : null,
                'recipient_name' => $delivery ? 'Recibe '.$customer->first_name : null,
                'location_id' => $index % 2 === 0 ? $locations['centro']->id : null,
                'notes' => $index % 5 === 0 ? 'Sin cebolla (pedido de demostración)' : null,
                'lines' => $lines,
            ], null);

            if ($final === 'cancelled') {
                $this->orders->transition($order, 'confirmed', null);
                $this->orders->transition($order, 'cancelled', null, 'El cliente cambió de opinión (demostración)');

                continue;
            }

            foreach (array_slice($flow, 1, (int) array_search($final, $flow, true)) as $step) {
                if ($step === 'dispatched' && ! $delivery) {
                    continue;
                }

                $this->orders->transition($order, $step, null);
            }
        }
    }

    // --- Campañas ----------------------------------------------------------------------------------------------------------

    private function campaignsAndTemplates(Workspace $workspace): void
    {
        $templates = [];
        $templateRoom = $this->room($workspace->messageTemplates()->count(), 'templates');

        foreach ([
            ['Bienvenida', 'Comunicación', 'Hola {{nombre}}, gracias por elegirnos. Este es un mensaje de demostración.'],
            ['Recordatorio de cita', 'Recordatorios', 'Hola {{nombre}}, te recordamos tu cita de la próxima semana. (Demostración)'],
            ['Promoción de temporada', 'Promociones', 'Hola {{nombre_completo}}, esta semana tienes un beneficio especial. (Demostración)'],
        ] as $row) {
            [$name, $category, $body] = $row;
            $existing = $workspace->messageTemplates()->where('name', self::PREFIX.$name)->first();

            if (! $existing && $templateRoom-- <= 0) {
                continue;
            }

            $templates[] = $existing ?? $workspace->messageTemplates()->create(['name' => self::PREFIX.$name, 'category' => $category, 'body' => $body, 'is_active' => true]);
        }

        if (count($templates) < 3) {
            return;
        }

        // One campaign is enough to see the module; «simulated» is a rehearsal that marks recipients and never touches a channel.
        $rows = [['Promoción de temporada', 'whatsapp', 'all', [], $templates[2], 'simulated']];
        $campaignRoom = $this->room($workspace->campaigns()->count(), 'campaigns');

        foreach ($rows as [$name, $channel, $audience, $filters, $template, $final]) {
            if ($campaignRoom-- <= 0 || $workspace->campaigns()->where('name', self::PREFIX.$name)->exists()) {
                continue;
            }

            $campaign = $this->campaigns->saveDraft($workspace, [
                'name' => self::PREFIX.$name, 'message_template_id' => $template->id, 'body' => $template->body, 'channel' => $channel, 'audience' => $audience,
                'filters' => $filters, 'customer_ids' => [],
            ], null, null);

            if (in_array($final, ['prepared', 'simulated', 'cancelled'], true)) {
                $campaign = $this->campaigns->prepare($campaign);
            }
            if ($final === 'simulated') {
                $this->campaigns->simulate($campaign);
            }
            if ($final === 'cancelled') {
                $this->campaigns->cancel($campaign);
            }
        }
    }

    // --- what carries the marks ------------------------------------------------------------------------------------------

    private function demoCustomers(Workspace $workspace)
    {
        return $workspace->customers()->where('email_normalized', 'like', '%@'.self::DOMAIN);
    }

    private function demoOrders(Workspace $workspace)
    {
        return $workspace->orders()->where('customer_email', 'like', '%@'.self::DOMAIN);
    }

    private function demoAppointments(Workspace $workspace)
    {
        return $workspace->appointments()->whereIn('customer_id', $workspace->bookingCustomers()->where('email', 'like', '%@'.self::DOMAIN)->select('id'));
    }
}
