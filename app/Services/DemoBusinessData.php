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
 * the order the foreign keys need, and leaves anything a person created. `generate` is idempotent: it creates what is missing and leaves the rest as it is.
 * Everything is deterministic (no randomness), so two runs give the same data.
 */
class DemoBusinessData
{
    public const PREFIX = 'Demo · ';

    public const DOMAIN = 'demo.invalid';

    public const SKU = 'DEMO-';

    private const FIRST_NAMES = ['Laura', 'Carlos', 'Marta', 'Andrés', 'Sofía', 'Diego', 'Valentina', 'Mateo', 'Camila', 'Julián', 'Isabela', 'Sergio'];

    private const LAST_NAMES = ['Gómez', 'Rodríguez', 'Pérez', 'Martínez', 'López', 'Ramírez', 'Torres', 'Castro', 'Vargas', 'Ortiz'];

    public function __construct(
        private readonly CustomerRegistry $customers,
        private readonly OrderService $orders,
        private readonly CampaignManager $campaigns,
        private readonly DemoConversations $guard,
    ) {}

    /** @throws DemoNotAllowed */
    public function assertAllowed(Workspace $workspace): void
    {
        $this->guard->assertAllowed($workspace);
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
        ];
        $locations = [];

        foreach ($rows as $key => [$name, $address, $city, $active]) {
            $locations[$key] = $workspace->locations()->firstOrCreate(['name' => self::PREFIX.$name], [
                'address' => $address, 'city' => $city, 'phone' => '+1202555010'.(count($locations) + 1), 'notes' => 'Sede de demostración.', 'is_active' => $active,
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
            'retirado' => ['Peinado de novia (retirado)', 'Belleza', 180, 30, 250000, false],
        ];
        $services = [];

        foreach ($rows as $key => [$name, $category, $minutes, $buffer, $price, $active]) {
            $service = $workspace->services()->firstOrCreate(['name' => self::PREFIX.$name], [
                'description' => 'Servicio de demostración.', 'category' => $category, 'duration_minutes' => $minutes, 'buffer_minutes' => $buffer, 'price' => $price, 'is_active' => $active,
            ]);
            $services[$key] = $service;
        }

        // Which sede offers what: the first sede offers everything, the second only some (a service with no row would be offered in every sede).
        foreach (['corte', 'color', 'barba', 'manicure', 'masaje', 'limpieza', 'consulta'] as $key) {
            $locations['centro']->services()->syncWithoutDetaching([$services[$key]->id => ['workspace_id' => $workspace->id]]);
        }
        foreach (['corte', 'barba', 'consulta'] as $key) {
            $locations['norte']->services()->syncWithoutDetaching([$services[$key]->id => ['workspace_id' => $workspace->id]]);
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
        ];
        $professionals = [];

        foreach ($rows as $key => [$name, $specialty, $offered, $weekdays, $from, $to, $active]) {
            $professional = $workspace->professionals()->firstOrCreate(['name' => self::PREFIX.$name], ['specialty' => $specialty, 'phone' => null, 'is_active' => $active]);

            if ($professional->wasRecentlyCreated) {
                $professional->services()->sync(collect($offered)->mapWithKeys(fn (string $service) => [$services[$service]->id => ['workspace_id' => $workspace->id]])->all());

                foreach ($weekdays as $weekday) {
                    $professional->scheduleRules()->create(['workspace_id' => $workspace->id, 'location_id' => $key === 'luis' ? $locations['norte']->id : null, 'weekday' => $weekday, 'starts_at' => "{$from}:00", 'ends_at' => "{$to}:00"]);
                }
            }

            $professionals[$key] = $professional;
        }

        return $professionals;
    }

    private function resourcesAndExceptions(Workspace $workspace, array $services, array $professionals): void
    {
        foreach ([['Sala de masajes', 'Sala', ['masaje']], ['Silla de manicure', 'Silla', ['manicure']]] as [$name, $kind, $offered]) {
            $resource = $workspace->bookableResources()->firstOrCreate(['name' => self::PREFIX.$name], ['kind' => $kind, 'is_active' => true]);

            if ($resource->wasRecentlyCreated) {
                foreach ($offered as $service) {
                    DB::table('resource_service')->insertOrIgnore(['workspace_id' => $workspace->id, 'resource_id' => $resource->id, 'service_id' => $services[$service]->id]);
                }
            }
        }

        $today = now()->startOfDay();
        foreach ([
            [null, $today->copy()->addDays(9), $today->copy()->addDays(9), 'Festivo'],
            [$professionals['ana'], $today->copy()->addDays(3), $today->copy()->addDays(5), 'Vacaciones'],
        ] as [$professional, $from, $to, $reason]) {
            $workspace->scheduleExceptions()->firstOrCreate(
                ['reason' => self::PREFIX.$reason, 'professional_id' => $professional?->id],
                ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            );
        }
    }

    // --- Clientes y Agenda ---------------------------------------------------------------------------------------------------

    /** @return list<Customer> */
    private function customerRecords(Workspace $workspace): array
    {
        $records = [];
        $consents = ['granted', 'granted', 'unknown', 'revoked', 'granted', 'unknown'];
        $tags = [['vip'], ['mayorista'], ['nuevo'], [], ['vip', 'frecuente'], ['frecuente']];

        for ($n = 1; $n <= 36; $n++) {
            $email = sprintf('cliente%02d@%s', $n, self::DOMAIN);
            $existing = $workspace->customers()->where('email_normalized', $email)->first();

            if ($existing) {
                $records[] = $existing;

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
        if ($this->demoAppointments($workspace)->count() >= 36) {
            return;
        }

        $pairs = [
            ['ana', 'corte', 'centro'], ['ana', 'color', 'centro'], ['luis', 'barba', 'norte'], ['luis', 'corte', 'norte'],
            ['paula', 'manicure', 'centro'], ['paula', 'limpieza', 'centro'], ['jorge', 'consulta', 'centro'], ['paula', 'masaje', 'centro'],
        ];
        // Offset in days (negative = past), status and how it came in. A cancelled one carries its reason; a future one is confirmed or requested.
        $plan = [
            [-28, 'completed', 'admin'], [-25, 'completed', 'web'], [-21, 'no_show', 'chat'], [-18, 'completed', 'admin'], [-14, 'cancelled', 'web'], [-11, 'completed', 'chat'],
            [-9, 'completed', 'admin'], [-7, 'no_show', 'web'], [-5, 'completed', 'admin'], [-3, 'cancelled', 'chat'], [-2, 'completed', 'web'], [-1, 'completed', 'admin'],
            [1, 'confirmed', 'admin'], [2, 'confirmed', 'web'], [2, 'requested', 'chat'], [4, 'confirmed', 'admin'], [5, 'requested', 'web'], [6, 'confirmed', 'chat'],
            [7, 'confirmed', 'admin'], [8, 'requested', 'admin'], [10, 'confirmed', 'web'], [11, 'confirmed', 'admin'], [12, 'cancelled', 'web'], [13, 'confirmed', 'chat'],
        ];
        $currency = $workspace->settingsOrDefault()->currency;
        $taken = [];
        // The days (1 = Monday) and the starting hours each professional works, as set in professionals().
        $schedules = ['ana' => [[1, 2, 3, 4, 5], [9, 10, 11, 13, 14]], 'luis' => [[2, 3, 4, 5, 6], [10, 11, 12, 14, 15]], 'paula' => [[1, 3, 5, 6], [8, 9, 10, 11, 13]], 'jorge' => [[1, 2, 3, 4], [8, 9, 10, 11]]];

        foreach (array_merge($plan, $plan) as $index => [$offset, $status, $channel]) {
            [$professionalKey, $serviceKey, $locationKey] = $pairs[$index % count($pairs)];
            $professional = $professionals[$professionalKey];
            $service = $services[$serviceKey];
            // Always inside the professional's own days and hours; the second pass over the plan uses other hours so a day never overlaps itself.
            [$days, $hours] = $schedules[$professionalKey];
            $start = now()->startOfDay()->addDays($offset);

            while (! in_array($start->dayOfWeekIso, $days, true)) {
                $start = $start->addDay();
            }

            $start = $start->setTime($hours[($index + intdiv($index, count($plan)) * 2) % count($hours)], $index % 2 === 0 ? 0 : 30)->utc();
            $end = $start->copy()->addMinutes($service->duration_minutes);
            $until = $end->copy()->addMinutes($service->buffer_minutes);

            // Never two appointments of one professional that overlap (the database refuses them in PostgreSQL, and a real agenda would too).
            $clash = collect($taken[$professional->id] ?? [])->contains(fn (array $busy) => $start->lt($busy[1]) && $until->gt($busy[0]));

            if ($clash) {
                continue;
            }
            $taken[$professional->id][] = [$start, $until];

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
            ['04', 'Ensalada de la casa', 'Comida', 15000, true], ['05', 'Papas a la francesa', 'Acompañamientos', 8000, true], ['06', 'Aros de cebolla', 'Acompañamientos', 9000, true],
            ['07', 'Limonada natural', 'Bebidas', 6000, true], ['08', 'Gaseosa 400 ml', 'Bebidas', 4500, true], ['09', 'Café americano', 'Bebidas', 5000, true],
            ['10', 'Brownie con helado', 'Postres', 12000, true], ['11', 'Cheesecake', 'Postres', 14000, true], ['12', 'Combo familiar', 'Combos', 68000, true],
            ['13', 'Sopa del día (agotada)', 'Comida', 13000, false], ['14', 'Promoción de temporada (retirada)', 'Combos', 49000, false],
        ];
        $products = [];

        foreach ($rows as [$code, $name, $category, $price, $active]) {
            $product = $workspace->products()->firstOrCreate(['sku' => self::SKU.$code], ['name' => self::PREFIX.$name, 'description' => 'Producto de demostración.', 'category' => $category, 'price' => $price, 'is_active' => $active]);
            $products[$code] = $product;
        }

        // Some products are only sold in some sedes (the others are sold everywhere).
        foreach (['10', '11'] as $code) {
            $locations['centro']->products()->syncWithoutDetaching([$products[$code]->id => ['workspace_id' => $workspace->id]]);
        }

        return $products;
    }

    /** Orders in every state, pickup and delivery, built with the real service so totals, numbers and history follow the real rules. */
    private function orders(Workspace $workspace, array $products, array $customers, array $locations): void
    {
        if ($this->demoOrders($workspace)->count() >= 24) {
            return;
        }

        $codes = array_keys(array_filter($products, fn (Product $product) => $product->is_active));
        // The state each order ends in: the path walks the allowed graph, so the history of each one is complete.
        $paths = [
            ['received'], ['received'], ['received'], ['confirmed'], ['confirmed'], ['preparing'], ['preparing'], ['ready'], ['ready'],
            ['dispatched'], ['dispatched'], ['delivered'], ['delivered'], ['delivered'], ['delivered'], ['delivered'], ['delivered'], ['delivered'],
            ['cancelled'], ['cancelled'], ['cancelled'], ['delivered'], ['delivered'], ['dispatched'],
        ];
        $flow = ['received', 'confirmed', 'preparing', 'ready', 'dispatched', 'delivered'];

        foreach ($paths as $index => [$final]) {
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

        foreach ([
            ['Bienvenida', 'Comunicación', 'Hola {{nombre}}, gracias por elegirnos. Este es un mensaje de demostración.'],
            ['Recordatorio de cita', 'Recordatorios', 'Hola {{nombre}}, te recordamos tu cita de la próxima semana. (Demostración)'],
            ['Promoción de temporada', 'Promociones', 'Hola {{nombre_completo}}, esta semana tienes un beneficio especial. (Demostración)'],
            ['Plantilla en desuso', 'Comunicación', 'Mensaje antiguo para {{nombre}}. (Demostración)', false],
        ] as $row) {
            [$name, $category, $body, $active] = array_pad($row, 4, true);
            $templates[] = $workspace->messageTemplates()->firstOrCreate(['name' => self::PREFIX.$name], ['category' => $category, 'body' => $body, 'is_active' => $active]);
        }

        $rows = [
            ['Bienvenida a clientes nuevos', 'whatsapp', 'filtered', ['tag' => 'nuevo'], $templates[0], 'draft'],
            ['Recordatorio general', 'whatsapp', 'all', [], $templates[1], 'prepared'],
            ['Promoción de temporada', 'whatsapp', 'all', [], $templates[2], 'simulated'],
            ['Campaña cancelada', 'email', 'all', [], $templates[0], 'cancelled'],
        ];

        foreach ($rows as [$name, $channel, $audience, $filters, $template, $final]) {
            if ($workspace->campaigns()->where('name', self::PREFIX.$name)->exists()) {
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
