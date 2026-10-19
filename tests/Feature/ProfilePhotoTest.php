<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/** The profile page and the personal photo: safe upload, own-account-only access, honest account state, no secret on the page. */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is needed to process images.');
        }

        Storage::fake();
    }

    private function upload(User $user, UploadedFile $file)
    {
        return $this->actingAsWorkspaceMember($user)->from('/profile')->post('/profile/photo', ['photo' => $file]);
    }

    public function test_a_valid_image_is_re_encoded_as_a_square_jpeg_and_served_only_to_its_owner(): void
    {
        $user = User::factory()->create();

        $this->upload($user, UploadedFile::fake()->image('yo.png', 800, 500))->assertSessionHasNoErrors()->assertSessionHas('success');

        $stored = Storage::get("profile-photos/{$user->id}.jpg");
        $info = getimagesizefromstring($stored);
        $this->assertSame([512, 512, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);

        $response = $this->get('/profile/photo')->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_nobody_can_read_another_persons_photo_there_is_no_route_with_an_id(): void
    {
        $owner = User::factory()->create();
        $this->upload($owner, UploadedFile::fake()->image('yo.jpg', 400, 400));

        $stranger = User::factory()->create();
        $this->actingAsWorkspaceMember($stranger)->get('/profile/photo')->assertNotFound();
        $this->get("/profile/photo/{$owner->id}")->assertNotFound();
        $this->get("/users/{$owner->id}/photo")->assertNotFound();

        auth()->logout();
        $this->get('/profile/photo')->assertRedirect();
    }

    public function test_the_page_and_the_top_bar_know_the_photo_with_a_version_and_without_any_path_of_the_bucket(): void
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user)->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page->where('photoUrl', null)->where('auth.user.photoUrl', null));

        $this->upload($user, UploadedFile::fake()->image('yo.jpg', 300, 300));
        $first = null;
        $this->get('/profile')->assertInertia(function (AssertableInertia $page) use (&$first) {
            $first = $page->toArray()['props']['photoUrl'];
            $this->assertStringStartsWith('/profile/photo?v=', $first);
            $this->assertStringNotContainsString('profile-photos', $first);
            $page->where('auth.user.photoUrl', $first);
        });

        $this->upload($user, UploadedFile::fake()->image('otra.jpg', 300, 300));
        $this->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page->where('photoUrl', fn ($url) => $url !== $first));
    }

    public function test_a_replaced_photo_is_never_served_from_a_stale_validator(): void
    {
        $user = User::factory()->create();
        $this->upload($user, UploadedFile::fake()->image('a.jpg', 300, 300));
        $etag = $this->get('/profile/photo')->headers->get('ETag');
        $this->get('/profile/photo', ['If-None-Match' => $etag])->assertStatus(304);

        Storage::put("profile-photos/{$user->id}.jpg", 'x'.Storage::get("profile-photos/{$user->id}.jpg"));
        touch(Storage::path("profile-photos/{$user->id}.jpg"), time() + 5);
        clearstatcache();

        $this->assertNotSame($etag, $this->get('/profile/photo')->headers->get('ETag'));
    }

    public function test_files_that_are_not_images_are_too_big_too_small_or_disguised_are_refused(): void
    {
        $user = User::factory()->create();

        $bad = [
            'a pdf' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'),
            'a script named as an image' => UploadedFile::fake()->createWithContent('x.png', '<?php echo "x";'),
            'an svg' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'too heavy' => UploadedFile::fake()->image('big.jpg', 600, 600)->size(3000),
            'too small' => UploadedFile::fake()->image('tiny.png', 64, 64),
            'too large in pixels' => UploadedFile::fake()->image('huge.png', 3500, 200),
        ];

        foreach ($bad as $why => $file) {
            try {
                $this->upload($user, $file)->assertSessionHasErrors('photo');
            } catch (AssertionFailedError) {
                $this->fail("{$why} must be refused");
            }
        }
        $this->assertFalse(Storage::exists("profile-photos/{$user->id}.jpg"));
        $this->upload($user, UploadedFile::fake()->image('ok.png', 400, 400))->assertSessionHasNoErrors();
    }

    public function test_the_photo_can_be_removed_and_the_page_falls_back_to_the_initials(): void
    {
        $user = User::factory()->create();
        $this->upload($user, UploadedFile::fake()->image('a.jpg', 300, 300));

        $this->delete('/profile/photo')->assertSessionHas('success');

        $this->assertFalse(Storage::exists("profile-photos/{$user->id}.jpg"));
        $this->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page->where('photoUrl', null));
        $this->get('/profile/photo')->assertNotFound();
    }

    public function test_uploading_is_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 10) as $i) {
            $this->upload($user, UploadedFile::fake()->image('a.jpg', 200, 200));
        }
        $this->upload($user, UploadedFile::fake()->image('a.jpg', 200, 200))->assertStatus(429);
    }

    public function test_the_profile_shows_the_account_state_and_never_the_password_or_its_hash(): void
    {
        $user = User::factory()->create(['name' => 'Ana Prueba', 'email' => 'ana@example.com']);
        $hash = $user->password;

        $this->actingAsWorkspaceMember($user, 'admin')->get('/profile')->assertOk()->assertInertia(function (AssertableInertia $page) use ($hash) {
            $page->where('account.name', 'Ana Prueba')->where('account.email', 'ana@example.com')->where('account.passwordSet', true)->where('account.active', true)->where('account.role', 'admin');
            $json = json_encode($page->toArray()['props']);
            $this->assertStringNotContainsString($hash, $json);
            $this->assertStringNotContainsString('remember_token', $json);
        });
    }

    public function test_changing_the_name_and_the_email_still_works_and_only_for_the_signed_in_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['name' => 'Otra persona', 'email' => 'otra@example.com']);

        $this->actingAsWorkspaceMember($user)->patch('/profile', ['name' => 'Nuevo', 'email' => 'nuevo@example.com', 'id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame('Nuevo', $user->fresh()->name);
        $this->assertSame('Otra persona', $other->fresh()->name);
        $this->patch('/profile', ['name' => 'X', 'email' => 'otra@example.com'])->assertSessionHasErrors('email');
    }
}
