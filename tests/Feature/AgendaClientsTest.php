<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingCustomer;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Agenda uses Clientes, the central record of people, WITHOUT a second customer base: the staff can pick a card or save the person as a new one (one `customers` row, never a duplicate),
 * the link is explicit (never inferred from a name), it stays inside the Workspace, and appointments that already existed are not touched.
 */
class AgendaClientsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    private Service $cut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        Role::findOrCreate('solo_agenda', 'web')->syncPermissions(['view-bookings', 'create-bookings']);
        $this->a = $this->bookingWorkspace('AG_A', ['dashboard', 'service_catalog', 'professionals', 'bookings', 'customers']);
        $this->b = $this->bookingWorkspace('AG_B', ['dashboard', 'service_catalog', 'professionals', 'bookings', 'customers']);
        $this->cut = $this->makeService($this->a, ['name' => 'Corte', 'duration_minutes' => 30]);
        $this->makeProfessional($this->a, [$this->cut], 'Ana');
    }

    private function card(?Workspace $workspace = null, array $attributes = []): Customer
    {
        return ($workspace ?? $this->a)->customers()->create($attributes + ['first_name' => 'Laura', 'last_name' => 'Pérez', 'email' => 'laura@example.com', 'phone' => '+573001112233', 'marketing_consent' => 'granted', 'consent_note' => 'Formulario']);
    }

    private function book(array $extra = [], string $at = '2026-10-13T10:00:00Z')
    {
        return $this->post('/bookings', $extra + ['service_id' => $this->cut->id, 'starts_at' => $at, 'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233', 'email' => 'laura@example.com']]);
    }

    public function test_picking_a_client_links_the_appointment_to_that_card_and_uses_its_data(): void
    {
        $card = $this->card();
        $this->actAsMember($this->a);

        $this->book(['central_customer_id' => $card->id, 'customer' => ['name' => 'otro nombre escrito', 'phone' => '+573009998877']])->assertSessionHasNoErrors()->assertSessionHas('success');

        $booking = BookingCustomer::firstOrFail();
        $this->assertSame($card->id, $booking->customer_id);
        $this->assertSame('Laura Pérez', $booking->name, 'the card is the source of truth for the person');
        $this->assertSame('573001112233', $booking->phone, 'the phone of the card, in the digits-only form Agenda stores');
        $this->assertSame(1, Customer::count(), 'no second customer base: nothing was created');
    }

    public function test_saving_the_person_as_a_client_creates_one_card_and_reuses_it_the_next_time(): void
    {
        $this->actAsMember($this->a);

        $this->book(['save_as_client' => true])->assertSessionHasNoErrors()->assertSessionHas('success');

        $card = Customer::firstOrFail();
        $this->assertSame('Laura', $card->first_name);
        $this->assertSame('Pérez', $card->last_name);
        $this->assertSame($this->a->id, $card->workspace_id);
        $this->assertSame('booking', $card->source);
        $this->assertNotSame('granted', $card->marketing_consent, 'booking an appointment is not consent to marketing');
        $this->assertSame($card->id, BookingCustomer::firstOrFail()->customer_id);

        // Saving the same person again is refused naming the existing card: no duplicate row.
        $this->book(['save_as_client' => true], '2026-10-13T11:00:00Z')->assertSessionHasErrors();
        $this->assertSame(1, Customer::count());
    }

    public function test_without_picking_or_saving_nothing_changes_and_agenda_works_as_before(): void
    {
        $this->actAsMember($this->a);

        $this->book()->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(0, Customer::count(), 'a booking never creates a card by itself');
        $this->assertNull(BookingCustomer::firstOrFail()->customer_id, 'and never links one by guessing from the name');
    }

    public function test_a_card_of_another_workspace_or_a_deactivated_one_cannot_be_linked(): void
    {
        $foreign = $this->card($this->b);
        $inactive = $this->card(null, ['first_name' => 'Inactivo', 'email' => 'i@example.com', 'phone' => '+573005550000', 'is_active' => false]);
        $this->actAsMember($this->a);

        $this->book(['central_customer_id' => $foreign->id])->assertSessionHasErrors('customer.name');
        $this->book(['central_customer_id' => $inactive->id])->assertSessionHasErrors('customer.name');
        $this->book(['central_customer_id' => 999999])->assertSessionHasErrors('customer.name');
        $this->assertSame(0, Appointment::count());
    }

    public function test_the_role_needs_permission_over_clientes_to_link_or_create_and_the_search_is_closed_without_it(): void
    {
        $card = $this->card();
        $this->actAsMember($this->a, 'solo_agenda');

        $this->getJson('/bookings/clients?q=Laura')->assertForbidden();
        $this->book(['central_customer_id' => $card->id])->assertSessionHasErrors('customer.name');
        $this->book(['save_as_client' => true])->assertSessionHasErrors('customer.name');
        $this->assertSame(0, Appointment::count());
        $this->assertSame(1, Customer::count());

        // The plain booking (no card involved) still works for that role.
        $this->book()->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    public function test_the_search_finds_cards_of_this_workspace_only_and_needs_two_characters(): void
    {
        $this->card();
        $this->card($this->b, ['first_name' => 'Laura', 'last_name' => 'Ajena', 'email' => 'x@example.com', 'phone' => '+573001119999']);
        $this->actAsMember($this->a);

        $found = $this->getJson('/bookings/clients?q=Laura')->assertOk()->json('clients');
        $this->assertCount(1, $found);
        $this->assertSame('Laura Pérez', $found[0]['name']);
        $this->assertCount(0, $this->getJson('/bookings/clients?q=L')->assertOk()->json('clients'));
    }

    public function test_the_workspace_without_the_clientes_module_cannot_use_it_from_agenda(): void
    {
        $this->contractModules($this->a, ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        $card = $this->card();
        $this->actAsMember($this->a);

        $this->getJson('/bookings/clients?q=Laura')->assertForbidden();
        $this->book(['central_customer_id' => $card->id])->assertSessionHasErrors('customer.name');
        $this->book()->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    public function test_appointments_that_already_existed_keep_their_data_and_stay_unlinked(): void
    {
        $this->actAsMember($this->a);
        $this->book()->assertSessionHas('success');
        $before = BookingCustomer::firstOrFail()->only(['name', 'phone', 'email', 'customer_id']);

        // A card with the SAME phone appears later: nothing links by itself, the old appointment is untouched.
        $this->card();

        $this->assertSame($before, BookingCustomer::firstOrFail()->fresh()->only(['name', 'phone', 'email', 'customer_id']));
        $this->assertNull(BookingCustomer::firstOrFail()->customer_id);
    }

    public function test_the_diagnostic_counts_matches_without_changing_or_printing_anything_personal(): void
    {
        $this->card();
        $this->card(null, ['first_name' => 'Gemela', 'last_name' => 'Uno', 'email' => 'g1@example.com', 'phone' => '+573006660000']);
        $this->card(null, ['first_name' => 'Gemela', 'last_name' => 'Dos', 'email' => 'g2@example.com', 'phone' => '+573006660001']);
        $people = [
            ['name' => 'Mismo teléfono', 'phone' => '573001112233', 'email' => null],
            ['name' => 'Sin ficha', 'phone' => '573009990000', 'email' => 'nadie@example.com'],
            ['name' => 'Sin datos', 'phone' => null, 'email' => null],
            ['name' => 'Cruzada', 'phone' => '573006660000', 'email' => 'g2@example.com'],
        ];

        foreach ($people as $person) {
            $this->a->bookingCustomers()->create($person);
        }

        $linked = $this->a->bookingCustomers()->create(['name' => 'Ya vinculada', 'phone' => '573001112233']);
        $linked->forceFill(['customer_id' => Customer::first()->id])->save();
        $before = BookingCustomer::orderBy('id')->get()->map->only(['id', 'name', 'phone', 'email', 'customer_id'])->all();

        $this->artisan('agenda:diagnose-customers', ['--workspace' => $this->a->id])
            ->expectsOutputToContain('Read-only: nothing was linked or changed.')
            ->doesntExpectOutputToContain('Mismo teléfono')
            ->doesntExpectOutputToContain('573001112233')
            ->assertSuccessful();

        $this->assertSame($before, BookingCustomer::orderBy('id')->get()->map->only(['id', 'name', 'phone', 'email', 'customer_id'])->all(), 'the diagnostic changes nothing');
    }

    public function test_agenda_keeps_no_listing_of_people_when_clientes_is_in_the_plan_and_keeps_it_when_it_is_not(): void
    {
        $this->actAsMember($this->a);
        $this->book()->assertSessionHas('success');

        $props = $this->get('/bookings')->assertOk()->viewData('page')['props'];
        $this->assertNull($props['customers'], 'people are managed in Clientes only');
        $this->assertTrue($props['centralClients']);

        // A Workspace without the Clientes module has no other place that shows who booked: it keeps the listing.
        $this->contractModules($this->a, ['dashboard', 'service_catalog', 'professionals', 'bookings']);
        $props = $this->get('/bookings')->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['centralClients']);
        $this->assertSame(1, $props['customers']['meta']['total']);
    }
}
