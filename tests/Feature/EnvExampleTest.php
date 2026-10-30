<?php

namespace Tests\Feature;

use Tests\TestCase;

/** `.env.example` stays coherent with what the code and Docker Compose really read: no retired credential comes back, and no variable Compose interpolates is missing. */
class EnvExampleTest extends TestCase
{
    /** @return array<string, bool> variable => defined (true) or only commented (false) */
    private function example(): array
    {
        $names = [];

        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*(#\s*)?([A-Z][A-Z0-9_]*)=/', $line, $m)) {
                $names[$m[2]] = ($names[$m[2]] ?? false) || $m[1] === '';
            }
        }

        return $names;
    }

    public function test_the_wompi_credentials_are_not_environment_variables_of_the_example_any_more(): void
    {
        $example = $this->example();

        foreach (['WOMPI_ENV', 'WOMPI_PUBLIC_KEY', 'WOMPI_INTEGRITY_SECRET', 'WOMPI_EVENTS_SECRET'] as $retired) {
            $this->assertArrayNotHasKey($retired, $example, "{$retired} is stored encrypted in the database now");
        }

        $this->assertArrayHasKey('WOMPI_TIMEOUT', $example); // a technical parameter still read from the environment
        $this->assertArrayHasKey('SUPPORT_INACTIVITY_MINUTES', $example);
    }

    /**
     * The production containers read ONLY what docker-compose.prod.yml lists under `environment:` (there is no .env in the image), so a variable documented
     * in .env.example that the production Compose neither forwards nor explains here would silently stay at its default.
     */
    public function test_every_documented_variable_reaches_the_production_containers_or_is_listed_as_not_forwarded_on_purpose(): void
    {
        // Only the shared PHP environment (app, worker, scheduler and reverb all use this block), not the postgres or redis services.
        $compose = (string) file_get_contents(base_path('docker-compose.prod.yml'));
        $start = strpos($compose, '&php-env');
        $end = strpos($compose, "\nservices:");
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        preg_match_all('/^\s+([A-Z][A-Z0-9_]+):/m', substr($compose, $start, $end - $start), $found);
        $forwarded = array_flip($found[1]);

        $notForwarded = [
            // Development only (published ports, the local MinIO) and the build-time Vite variable (the production image is built without an .env).
            'APP_PORT', 'VITE_PORT', 'DB_FORWARD_PORT', 'MINIO_PORT', 'MINIO_ROOT_USER', 'MINIO_ROOT_PASSWORD', 'VITE_APP_NAME',
            // Defaults computed in code, fixed by the Compose (LOG_CHANNEL=stderr) or used only by seeders.
            'CACHE_PREFIX', 'ALERT_LOG_PATH', 'LOG_STACK', 'LOG_DEPRECATIONS_CHANNEL', 'APP_FAKER_LOCALE',
            // Fixed by the Compose itself instead of being forwarded.
            'APP_MAINTENANCE_DRIVER', 'APP_MAINTENANCE_STORE', 'APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'REDIS_CLIENT', 'QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER',
            'BROADCAST_CONNECTION', 'FILESYSTEM_DISK', 'LOG_CHANNEL', 'RUN_MIGRATIONS', 'INTEGRATIONS_ALLOW_PRIVATE_HOSTS',
        ];

        $missing = array_values(array_diff(array_keys($this->example()), array_keys($forwarded), $notForwarded));

        $this->assertSame([], $missing, 'Documented in .env.example but neither forwarded by docker-compose.prod.yml nor listed as intentionally not forwarded');
    }

    public function test_no_variable_is_assigned_twice_in_the_example(): void
    {
        $assignments = [];

        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^(# ?)?([A-Z][A-Z0-9_]*)=/', $line, $m)) {
                $assignments[$m[2]] = ($assignments[$m[2]] ?? 0) + 1;
            }
        }

        $this->assertSame([], array_keys(array_filter($assignments, fn (int $count) => $count > 1)), 'A variable appears more than once in .env.example');
    }

    public function test_every_variable_compose_requires_is_documented_and_the_compatible_fallback_still_reads_the_environment(): void
    {
        $example = $this->example();
        preg_match_all('/(?<!\$)\$\{([A-Z][A-Z0-9_]*)(?::?[-?][^}]*)?\}/', (string) file_get_contents(base_path('docker-compose.yml')), $m);

        foreach (array_unique($m[1]) as $variable) {
            $this->assertArrayHasKey($variable, $example, "docker-compose.yml interpolates {$variable} but .env.example does not mention it");
        }

        // The code that remains as the compatible fallback still reads these names (config/wompi.php): removing them from the example did not remove the support.
        $config = (string) file_get_contents(config_path('wompi.php'));
        foreach (['WOMPI_ENV', 'WOMPI_PUBLIC_KEY', 'WOMPI_INTEGRITY_SECRET', 'WOMPI_EVENTS_SECRET', 'WOMPI_TIMEOUT'] as $name) {
            $this->assertStringContainsString("env('{$name}'", $config);
        }

        // Nothing may hold a real-looking secret.
        $this->assertDoesNotMatchRegularExpression('/\b(pub|prv)_(test|prod)_[A-Za-z0-9]{8,}|test_(events|integrity)_[A-Za-z0-9]{8,}/', (string) file_get_contents(base_path('.env.example')));
    }
}
