<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The browser must not contact a font or script provider on its own (it would hand the visitor's IP address to a third party): Inter and three.js are npm
 * dependencies bundled by Vite. This guard fails if a CDN or font host comes back into the code the browser receives.
 */
class NoExternalAssetsTest extends TestCase
{
    private const HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'code.jquery.com', 'ajax.googleapis.com', 'use.typekit.net', 'fonts.bunny.net'];

    public function test_no_view_script_stylesheet_or_widget_loads_a_font_or_script_from_a_third_party(): void
    {
        $found = [];

        foreach ([resource_path('views'), resource_path('js'), resource_path('css'), public_path('widget')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $text = (string) file_get_contents($file->getPathname());

                foreach (self::HOSTS as $host) {
                    if (str_contains($text, $host)) {
                        $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())." → {$host}";
                    }
                }
            }
        }

        $this->assertSame([], $found, 'A third-party asset host is referenced by code the browser receives');
    }

    public function test_the_application_ships_its_own_font_and_three_js_as_dependencies(): void
    {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true);

        $this->assertSame('0.128.0', $package['dependencies']['three'] ?? null);
        $this->assertArrayHasKey('@fontsource-variable/inter', $package['dependencies']);
        $this->assertStringContainsString('@fontsource-variable/inter', (string) file_get_contents(resource_path('css/app.css')));
        $this->assertStringContainsString("import('three')", (string) file_get_contents(resource_path('js/Components/Access/ParticleBackground.jsx')));
    }

    public function test_the_security_policy_names_no_third_party_host(): void
    {
        foreach (['script_hosts', 'style_hosts', 'font_hosts'] as $key) {
            $this->assertSame([], config("security.csp.{$key}"), "{$key} must stay empty while no third-party asset is used");
        }
    }
}
