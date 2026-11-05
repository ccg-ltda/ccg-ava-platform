<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The favicon lives in public/brand: links are emitted only for files that exist and /favicon.ico follows the same folder (README, Favicon). The application ships
 * `favicon.png` (derived from the original `Favicon.jpg`, which stays untouched); an `.ico`, when someone adds one, takes precedence for /favicon.ico.
 */
class FaviconTest extends TestCase
{
    private string $ico;

    private string $png;

    private ?string $pngBackup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ico = public_path('brand/favicon.ico');
        $this->png = public_path('brand/favicon.png');
        @unlink($this->ico);
    }

    protected function tearDown(): void
    {
        @unlink($this->ico);

        if ($this->pngBackup !== null) {
            file_put_contents($this->png, $this->pngBackup);
        }

        parent::tearDown();
    }

    private function withoutThePng(): void
    {
        $this->pngBackup = (string) file_get_contents($this->png);
        unlink($this->png);
    }

    public function test_the_original_assets_and_the_derived_icon_are_in_place_and_valid_images(): void
    {
        foreach (['brand/Favicon.jpg' => 'image/jpeg', 'brand/Mascota.jpg' => 'image/jpeg', 'brand/favicon.png' => 'image/png'] as $file => $mime) {
            $info = getimagesize(public_path($file));

            $this->assertNotFalse($info, "{$file} is a valid image");
            $this->assertSame($mime, $info['mime'], $file);
        }

        $this->assertSame([256, 256], array_slice(getimagesize($this->png), 0, 2));
    }

    public function test_every_page_links_the_icon_and_the_root_icon_redirects_to_it(): void
    {
        $head = $this->get('/pre-login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]*/brand/favicon\.png\?v=\d+" type="image/png" sizes="256x256">#', $head);
        $this->assertMatchesRegularExpression('#<link rel="apple-touch-icon" href="[^"]*/brand/favicon\.png\?v=\d+" sizes="256x256">#', $head);
        $this->get('/favicon.ico')->assertStatus(301)->assertRedirect('/brand/favicon.png');
    }

    public function test_an_ico_in_the_folder_is_preferred_for_the_root_icon_and_linked_too(): void
    {
        file_put_contents($this->ico, 'ICO');

        $this->get('/favicon.ico')->assertRedirect('/brand/favicon.ico')->assertStatus(301);
        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]*/brand/favicon\.ico\?v=\d+" type="image/x-icon" sizes="48x48">#', view('partials.favicon')->render());
    }

    public function test_without_any_icon_file_no_link_is_emitted_and_the_root_icon_is_a_404(): void
    {
        $this->withoutThePng();

        $this->get('/favicon.ico')->assertNotFound();
        $this->assertStringNotContainsString('rel="icon"', view('partials.favicon')->render());
        $this->assertStringNotContainsString('rel="apple-touch-icon"', view('partials.favicon')->render());
    }
}
