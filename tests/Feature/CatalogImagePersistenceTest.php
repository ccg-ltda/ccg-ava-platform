<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\E2eFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The picture of a product is stored on the default disk (MinIO/S3 in Docker, a persistent volume) under a Workspace path, the product keeps ONLY that path, and
 * the route serves it to whoever is allowed, in a new session as in the first one. A save that does not carry a picture never removes it.
 */
class CatalogImagePersistenceTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $ws;

    private Location $centro;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        $this->seedRoleCatalog();
        $this->ws = $this->bookingWorkspace('TIENDA', ['dashboard', 'product_catalog', 'locations']);
        $this->centro = $this->ws->locations()->create(['name' => 'Centro', 'is_active' => true]);
        $this->admin = $this->actAsMember($this->ws);
    }

    private function upload(): Product
    {
        $this->post('/catalog', ['name' => 'Brownie con helado', 'price' => '9000', 'location_ids' => [$this->centro->id], 'image' => UploadedFile::fake()->image('brownie.png', 200, 200)])->assertSessionHasNoErrors();

        return $this->ws->products()->where('name', 'Brownie con helado')->firstOrFail();
    }

    public function test_the_picture_is_stored_under_the_workspace_and_still_served_after_logging_out_and_in_again(): void
    {
        $product = $this->upload();

        $this->assertStringStartsWith("workspaces/{$this->ws->id}/products/{$product->id}/", $product->image_path);
        Storage::assertExists($product->image_path);
        $bytes = Storage::get($product->image_path);
        $this->get("/catalog/{$product->id}/image")->assertOk();

        // A brand new session (logout, login): the list carries the picture URL and the route returns the same bytes.
        auth()->logout();
        $this->flushSession();
        $this->actingAs($this->admin)->withSession(['workspace_id' => $this->ws->id]);

        $this->get('/catalog')->assertInertia(fn ($page) => $page->where('products.data.0.imageUrl', fn ($url) => str_contains($url, "/catalog/{$product->id}/image")));
        $this->assertSame($bytes, $this->get("/catalog/{$product->id}/image")->assertOk()->streamedContent());
        $this->assertSame($product->image_path, $product->fresh()->image_path, 'the product keeps the stored path, not a URL that can expire');
    }

    public function test_saving_the_product_without_a_new_picture_never_removes_the_stored_one(): void
    {
        $product = $this->upload();
        $path = $product->image_path;

        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Brownie', 'price' => '9500', 'location_ids' => [$this->centro->id]])->assertSessionHasNoErrors();

        $this->assertSame($path, $product->fresh()->image_path);
        Storage::assertExists($path);
        $this->get("/catalog/{$product->id}/image")->assertOk();
    }

    public function test_replacing_the_picture_keeps_exactly_one_stored_object_and_the_new_one_is_served(): void
    {
        $product = $this->upload();
        $old = $product->image_path;

        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Brownie con helado', 'price' => '9000', 'location_ids' => [$this->centro->id], 'image' => UploadedFile::fake()->image('nueva.jpg', 120, 120)])->assertSessionHasNoErrors();

        $new = $product->fresh()->image_path;
        $this->assertNotSame($old, $new);
        Storage::assertExists($new);
        Storage::assertMissing($old);
        $this->assertCount(1, Storage::allFiles("workspaces/{$this->ws->id}/products/{$product->id}"));
        $this->get("/catalog/{$product->id}/image")->assertOk();
    }

    public function test_another_workspace_can_not_read_the_picture_by_changing_the_url(): void
    {
        $product = $this->upload();
        $other = $this->bookingWorkspace('OTRA', ['dashboard', 'product_catalog']);
        $this->actAsMember($other);

        $this->get("/catalog/{$product->id}/image")->assertNotFound();
    }

    public function test_a_reference_whose_file_is_gone_answers_404_and_is_never_replaced_by_a_made_up_image(): void
    {
        $product = $this->upload();
        Storage::delete($product->image_path);

        $this->get("/catalog/{$product->id}/image")->assertNotFound();
        $this->assertNotNull($product->fresh()->image_path, 'the reference is kept: nothing is erased or invented');
    }

    public function test_a_failed_write_keeps_the_reference_and_the_old_file_and_says_so(): void
    {
        $product = $this->upload();
        $path = $product->image_path;

        // The disk answers false instead of throwing; the product must not lose its picture because of it.
        Storage::shouldReceive('putFile')->once()->andReturn(false);
        Storage::shouldReceive('exists')->andReturn(true);
        Storage::shouldReceive('response')->andReturn(response('x'));

        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Brownie con helado', 'price' => '9000', 'location_ids' => [$this->centro->id], 'image' => UploadedFile::fake()->image('nueva.jpg', 120, 120)])
            ->assertSessionHasErrors('image');

        $this->assertSame($path, $product->fresh()->image_path);
    }

    public function test_a_database_failure_after_the_upload_leaves_no_orphan_and_keeps_the_old_picture(): void
    {
        $product = $this->upload();
        $old = $product->image_path;

        Product::updating(fn () => throw new \RuntimeException('database down'));
        $this->withoutExceptionHandling();

        try {
            $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Brownie con helado', 'price' => '9000', 'location_ids' => [$this->centro->id], 'image' => UploadedFile::fake()->image('nueva.jpg', 120, 120)]);
            $this->fail('the failure must surface');
        } catch (\RuntimeException) {
        }

        $this->assertSame($old, $product->fresh()->image_path);
        Storage::assertExists($old);
        $this->assertCount(1, Storage::allFiles("workspaces/{$this->ws->id}/products/{$product->id}"), 'the file uploaded for the failed save was removed');
    }

    public function test_a_shared_file_is_not_deleted_when_another_product_still_needs_it(): void
    {
        $product = $this->upload();
        $twin = $this->ws->products()->create(['name' => 'Gemelo', 'price' => 1, 'image_path' => $product->image_path, 'is_active' => true]);

        $this->post("/catalog/{$product->id}", ['_method' => 'put', 'name' => 'Brownie con helado', 'price' => '9000', 'location_ids' => [$this->centro->id], 'remove_image' => true])->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->image_path);
        Storage::assertExists($twin->image_path);
    }

    public function test_cleaning_the_e2e_fixtures_never_removes_files_of_other_workspaces(): void
    {
        $product = $this->upload();
        $fixtures = app(E2eFixtures::class);

        $fixtures->setup();
        $fixtures->cleanup();

        Storage::assertExists($product->image_path);
    }
}
