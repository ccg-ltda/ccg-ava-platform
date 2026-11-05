<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The favicon lives in public/brand: links are emitted only for files that exist and /favicon.ico follows the same folder (README, Favicon). */
class FaviconTest extends TestCase
{
    private string $ico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ico = public_path('brand/favicon.ico');
        @unlink($this->ico);
    }

    protected function tearDown(): void
    {
        @unlink($this->ico);
        parent::tearDown();
    }

    public function test_without_the_files_no_link_is_emitted_and_the_root_icon_is_a_404(): void
    {
        $this->get('/favicon.ico')->assertNotFound();
        $this->assertStringNotContainsString('rel="icon"', $this->get('/legal')->getContent().view('partials.favicon')->render());
    }

    public function test_when_the_file_is_in_place_the_page_links_it_and_the_root_icon_redirects(): void
    {
        file_put_contents($this->ico, 'ICO');

        $this->get('/favicon.ico')->assertRedirect('/brand/favicon.ico')->assertStatus(301);
        $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]*/brand/favicon\.ico\?v=\d+" type="image/x-icon" sizes="48x48">#', view('partials.favicon')->render());
    }
}
