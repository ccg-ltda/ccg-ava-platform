<?php

namespace Tests\Unit;

use App\Observability\ApiRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the redactor catches and what it can not. It is a heuristic, not a guarantee: the cases listed under `$knownLimits` are NOT caught and are written down on purpose so nobody
 * assumes they are (RECOMENDACIONES.md says the same). The rest are formats of real providers and shapes of data the technical activity meets.
 */
class ApiRedactorFormatsTest extends TestCase
{
    /** @return array<string, array{string, string}> text containing a secret => the secret that must not survive */
    public static function secretsInText(): array
    {
        return [
            'url with user and password' => ['conectar a https://admin:Sup3rS3cret@db.example.com/x', 'Sup3rS3cret'],
            'connection string' => ['postgres://ava:hunter2pass@10.0.0.5:5432/ava', 'hunter2pass'],
            'api_key in a query string' => ['GET https://api.x.com/v1/items?api_key=abc123XYZ&page=2', 'abc123XYZ'],
            'access_token in a query string' => ['https://x.test/cb?access_token=EAAxyzSECRET&state=1', 'EAAxyzSECRET'],
            'name=value in a log line' => ['login con password=hunter2 y user=ana', 'hunter2'],
            'json carried by a string' => ['{"password":"hunter2","name":"ana"}', 'hunter2'],
            'client_secret in a form' => ['grant_type=client_credentials&client_secret=zzTOPsecret', 'zzTOPsecret'],
            'aws secret by its name' => ['aws_secret_access_key=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'wJalrXUtnFEMI'],
            'stripe live key' => ['s'.'k_li'.'ve_FAKEfakeFAKE0123456789ab', 'FAKEfakeFAKE0123456789ab'],
            'stripe webhook secret' => ['whs'.'ec_FAKEfake0123456789', 'FAKEfake0123456789'],
            'google api key' => ['AI'.'zaFAKEfake1234567890abcdefghijklmnop', 'FAKEfake1234567890abcdefghijklmnop'],
            'sendgrid key' => ['S'.'G.fakefakefakefake.qrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUV', 'fakefakefakefake'],
            'twilio key sid' => ['S'.'K0123456789abcdef0123456789abcdef', '0123456789abcdef0123456789abcdef'],
            'slack webhook' => ['https://hooks.sl'.'ack.com/services/T0123ABCD/B0123ABCD/abcdEFGH1234ijklMNOP5678', 'abcdEFGH1234ijklMNOP5678'],
            'private key block' => ["-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC\n-----END PRIVATE KEY-----", 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASC'],
            'private key cut by the size cap' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA7", 'MIIEowIBAAKCAQEA7'],
            'bearer token' => ['Authorization: Bearer abc.def.ghi', 'abc.def.ghi'],
            'basic credentials' => ['Authorization: Basic dXNlcjpwYXNzd29yZA==', 'dXNlcjpwYXNzd29yZA'],
            'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U', 'eyJzdWIiOiIxMjM0NTY3ODkwIn0'],
            'wompi key' => ['prv_test_AbCdEf1234567890', 'AbCdEf1234567890'],
            'meta long token' => ['EAAGm0PX4ZCpsBAKZBabcdefghijklmnopqrstuvwxyz0123456789ZDZD', 'abcdefghijklmnopqrstuvwxyz0123456789'],
            'ava agent token' => ['ava_0123456789abcdef0123456789abcdef', '0123456789abcdef0123456789abcdef'],
            'card number' => ['tarjeta 4111 1111 1111 1111 vence 12/30', '4111 1111 1111 1111'],
            'card number with dashes' => ['4111-1111-1111-1111', '4111-1111'],
            'cookie header text' => ['Set-Cookie: sid=abcdef123456', 'abcdef123456'],
        ];
    }

    #[DataProvider('secretsInText')]
    public function test_a_secret_inside_a_string_does_not_survive(string $text, string $secret): void
    {
        $this->assertStringNotContainsString($secret, (new ApiRedactor)->data($text));
        $this->assertStringNotContainsString($secret, (new ApiRedactor)->forActivity()->data($text));
    }

    public function test_the_host_and_the_rest_of_a_url_stay_readable(): void
    {
        $this->assertSame('postgres://[REDACTED]@10.0.0.5:5432/ava', (new ApiRedactor)->data('postgres://ava:hunter2pass@10.0.0.5:5432/ava'));
        $this->assertSame('GET https://api.x.com/v1/items?api_key=[REDACTED]&page=2', (new ApiRedactor)->data('GET https://api.x.com/v1/items?api_key=abc123XYZ&page=2'));
        $this->assertSame('login con password=[REDACTED] y user=ana', (new ApiRedactor)->data('login con password=hunter2 y user=ana'));
    }

    public function test_an_already_masked_value_is_not_masked_twice(): void
    {
        $this->assertSame('Authorization: [REDACTED]', (new ApiRedactor)->data('Authorization: Bearer abc.def.ghi'));
        $this->assertSame('token=[REDACTED]', (new ApiRedactor)->data('token=prv_test_AbCdEf1234567890'));
    }

    /** @return array<string, array{mixed}> */
    public static function notSecrets(): array
    {
        return [
            'phone number id of Meta (15 digits)' => ['phone_number_id 109876543210987 ok'],
            'page id (16 digits)' => ['page 1234567890123456'],
            'order number (17 digits)' => ['pedido 20261105123456789'],
            'a normal sentence' => ['El cliente pidió reprogramar su cita del martes.'],
            'a short number' => ['Tu código es 482913'],
        ];
    }

    #[DataProvider('notSecrets')]
    public function test_ids_and_ordinary_text_are_not_mistaken_for_secrets(string $text): void
    {
        $this->assertSame($text, (new ApiRedactor)->data($text));
    }

    public function test_keys_are_matched_by_name_and_nested_data_is_walked(): void
    {
        $out = (new ApiRedactor)->data([
            'a' => ['b' => ['client_secret' => 'zzz', 'items' => [['password' => 'p', 'name' => 'ok']]]],
            'accessToken' => 'x', 'refreshToken' => 'y', 'apiKey' => 'k', 'appSecret' => 's', 'card_number' => '4111111111111111', 'session_id' => 'abc', 'idempotency_key' => 'abc',
            'X-Hub-Signature-256' => 'sha256=abc', 'Proxy-Authorization' => 'Basic abc',
            'discard_reason' => 'no aplica', 'postcard' => 'hola', 'sessions_count' => 3, 'conversation_id' => 12, 'name' => 'Ana',
        ]);

        $this->assertSame(ApiRedactor::MASK, $out['a']['b']['client_secret']);
        $this->assertSame(ApiRedactor::MASK, $out['a']['b']['items'][0]['password']);
        $this->assertSame('ok', $out['a']['b']['items'][0]['name']);

        foreach (['accessToken', 'refreshToken', 'apiKey', 'appSecret', 'card_number', 'session_id', 'idempotency_key', 'X-Hub-Signature-256', 'Proxy-Authorization'] as $key) {
            $this->assertSame(ApiRedactor::MASK, $out[$key], $key);
        }

        // Names that only CONTAIN a sensitive word are data, not secrets.
        $this->assertSame(['discard_reason' => 'no aplica', 'postcard' => 'hola', 'sessions_count' => 3, 'conversation_id' => 12, 'name' => 'Ana'], array_intersect_key($out, array_flip(['discard_reason', 'postcard', 'sessions_count', 'conversation_id', 'name'])));
    }

    public function test_the_activity_variant_also_masks_contact_data_inside_text(): void
    {
        $out = (new ApiRedactor)->forActivity()->data('Escribe a ana@example.com o al +573001112233');

        $this->assertStringNotContainsString('ana@example.com', $out);
        $this->assertStringNotContainsString('573001112233', $out);
    }

    /**
     * Secrets this heuristic does NOT catch, written down so the limit is explicit: a bare secret with no recognizable shape and no name (a base64 secret with slashes, a numeric PIN or a
     * one-time code inside prose, a password written in a sentence). The defence for those is to not send them to a technical log at all, not a better regex.
     */
    public function test_known_limits_are_not_pretended_away(): void
    {
        $redactor = new ApiRedactor;

        $this->assertSame('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $redactor->data('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'));
        $this->assertSame('mi clave es hunter2', $redactor->data('mi clave es hunter2'));
        $this->assertSame('Tu código es 482913', $redactor->data('Tu código es 482913'));
    }
}
