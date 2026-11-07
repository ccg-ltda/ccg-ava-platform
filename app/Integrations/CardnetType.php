<?php

namespace App\Integrations;

use App\Audit\Masked;
use App\Models\Integration;
use App\Payments\Gateways\PaymentGatewayType;
use Illuminate\Validation\Rule;

/**
 * The CardNET (República Dominicana) commercial account of ONE Workspace. The same account serves all the branches (sucursales) of that business and is never shared with another
 * Workspace. The identifiers CardNET assigns to the merchant (number and terminals) are kept ENCRYPTED and write-only like any credential (`secrets`); the rest is `config`.
 *
 * What is and is not known (see docs/pagos-pasarelas.md §9): the field NAMES and which of them are mandatory come from the CardNET form used by the CCG team and from a search
 * snippet of the official guide; the official specification could not be read. So every format rule below is only an UPPER bound that rejects obvious garbage, the technical
 * parameters are marked «por confirmar», and nothing here claims a working connection: `supportsTest()` is false until the laboratory protocol is verified, so a saved
 * configuration stays «guardada» and never becomes «validada».
 */
class CardnetType implements PaymentGatewayType
{
    private const SECRETS = ['merchant_number', 'merchant_terminal', 'merchant_terminal_amex'];

    public function key(): string
    {
        return 'cardnet';
    }

    public function label(): string
    {
        return 'CardNET';
    }

    public function description(): string
    {
        return 'Pagos con tarjeta en República Dominicana (DOP). El cliente paga en el sitio seguro de CardNET: Ava no ve ni guarda datos de tarjeta.';
    }

    public function notice(): string
    {
        return 'Estos datos los asigna CardNET a tu comercio y son los mismos para todas tus sucursales; no se comparten con ningún otro Workspace. Se guardan cifrados y no se vuelven a mostrar. Guardar no conecta nada: la conexión con CardNET todavía no se puede validar desde Ava (falta confirmar su protocolo oficial), así que el pago con CardNET no se ofrece a tus clientes hasta entonces.';
    }

    public function blockedBy(): array
    {
        return [
            'Especificación oficial de CardNET (creación de la sesión de pago y consulta del resultado).',
            'Credenciales y tarjetas del laboratorio de CardNET para la prueba de conexión.',
        ];
    }

    public function fields(): array
    {
        $pending = 'Por confirmar con la especificación oficial de CardNET.';

        return [
            ['name' => 'merchant_number', 'label' => 'Número de comercio (MerchantNumber)', 'kind' => 'secret', 'section' => 'Datos comerciales', 'required' => true, 'placeholder' => 'Lo asigna CardNET', 'hint' => 'Solo dígitos. Máximo 15.'],
            ['name' => 'merchant_terminal', 'label' => 'Terminal (MerchantTerminal)', 'kind' => 'secret', 'section' => 'Datos comerciales', 'required' => true, 'placeholder' => 'Lo asigna CardNET', 'hint' => 'Solo dígitos. Máximo 12.'],
            ['name' => 'merchant_terminal_amex', 'label' => 'Terminal American Express (MerchantTerminal_amex)', 'kind' => 'secret', 'section' => 'Datos comerciales', 'required' => false, 'hint' => 'Solo si CardNET te asignó una terminal distinta para American Express.', 'pending' => $pending],
            ['name' => 'merchant_type', 'label' => 'Categoría del comercio (MerchantType)', 'kind' => 'text', 'section' => 'Datos comerciales', 'required' => true, 'placeholder' => '5812', 'hint' => 'Código de 4 dígitos (MCC) que CardNET asignó a tu comercio.'],
            ['name' => 'merchant_name', 'label' => 'Nombre del comercio (MerchantName)', 'kind' => 'text', 'section' => 'Datos comerciales', 'required' => true, 'hint' => 'Como CardNET lo registró. Máximo 40 caracteres.', 'pending' => 'El límite y el formato exactos están por confirmar con la especificación oficial.'],
            ['name' => 'environment', 'label' => 'Ambiente', 'kind' => 'select', 'section' => 'Ambiente', 'required' => true, 'default' => 'sandbox', 'options' => [['value' => 'sandbox', 'label' => 'Laboratorio (pruebas)'], ['value' => 'production', 'label' => 'Producción']], 'hint' => 'Producción no cobra mientras la instalación no la autorice. Cambiar de ambiente exige volver a validar.'],
            ['name' => 'transaction_type', 'label' => 'Tipo de transacción (TransactionType)', 'kind' => 'text', 'section' => 'Parámetros técnicos', 'required' => false, 'placeholder' => '200', 'hint' => 'Déjalo vacío para usar el valor que confirme la especificación.', 'pending' => 'Por confirmar: 200 o 0200.'],
            ['name' => 'page_language', 'label' => 'Idioma de la página de pago', 'kind' => 'select', 'section' => 'Parámetros técnicos', 'required' => false, 'default' => 'ES', 'options' => [['value' => 'ES', 'label' => 'Español'], ['value' => 'EN', 'label' => 'Inglés']], 'pending' => 'Por confirmar el nombre oficial del campo y sus valores.'],
            ['name' => 'contact_email', 'label' => 'Correo de contacto', 'kind' => 'text', 'section' => 'Contacto y dirección', 'required' => false, 'hint' => 'Opcional. Se usará solo si CardNET lo exige cuando el pedido no trae el del cliente.', 'pending' => $pending],
            ['name' => 'contact_phone', 'label' => 'Teléfono de contacto', 'kind' => 'text', 'section' => 'Contacto y dirección', 'required' => false, 'pending' => $pending],
            ['name' => 'address_line1', 'label' => 'Dirección, línea 1', 'kind' => 'text', 'section' => 'Contacto y dirección', 'required' => false, 'pending' => $pending],
            ['name' => 'address_line2', 'label' => 'Dirección, línea 2', 'kind' => 'text', 'section' => 'Contacto y dirección', 'required' => false, 'pending' => $pending],
            ['name' => 'city', 'label' => 'Ciudad', 'kind' => 'text', 'section' => 'Contacto y dirección', 'required' => false, 'pending' => $pending],
            ['name' => 'state', 'label' => 'Estado o provincia', 'kind' => 'text', 'section' => 'Contacto y dirección', 'required' => false, 'pending' => $pending],
            ['name' => 'country', 'label' => 'País (ISO de 2 letras)', 'kind' => 'text', 'section' => 'Contacto y dirección', 'required' => false, 'default' => 'DO', 'pending' => $pending],
            ['name' => 'postal_code', 'label' => 'Código postal', 'kind' => 'text', 'section' => 'Contacto y dirección', 'required' => false, 'pending' => $pending],
        ];
    }

    public function rules(array $input, ?Integration $current): array
    {
        // A partial configuration may be saved (nothing is activated or validated by saving), so only the SHAPE of what is sent is checked here; completeness is `problems()`.
        return [
            'merchant_number' => ['nullable', 'string', 'regex:/^\d{1,15}$/'],
            'merchant_terminal' => ['nullable', 'string', 'regex:/^\d{1,12}$/'],
            'merchant_terminal_amex' => ['nullable', 'string', 'regex:/^\d{1,12}$/'],
            'merchant_type' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'merchant_name' => ['nullable', 'string', 'max:40'],
            'environment' => ['required', Rule::in(array_keys(config('integrations.cardnet.hosts')))],
            'transaction_type' => ['nullable', 'string', 'regex:/^\d{3,4}$/'],
            'page_language' => ['nullable', Rule::in(['ES', 'EN'])],
            'contact_email' => ['nullable', 'email', 'max:160'],
            'contact_phone' => ['nullable', 'string', 'regex:/^[0-9+()\-\s]{7,20}$/'],
            'address_line1' => ['nullable', 'string', 'max:120'],
            'address_line2' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'postal_code' => ['nullable', 'string', 'max:12'],
        ];
    }

    public function messages(): array
    {
        return [
            'merchant_number.regex' => 'El número de comercio solo lleva dígitos (máximo 15).',
            'merchant_terminal.regex' => 'La terminal solo lleva dígitos (máximo 12).',
            'merchant_terminal_amex.regex' => 'La terminal de American Express solo lleva dígitos (máximo 12).',
            'merchant_type.regex' => 'La categoría del comercio es un código de 4 dígitos.',
            'transaction_type.regex' => 'El tipo de transacción es un código numérico de 3 o 4 dígitos.',
            'contact_phone.regex' => 'Escribe un teléfono válido.',
            'country.regex' => 'Escribe el país con su código ISO de 2 letras (por ejemplo DO).',
        ];
    }

    public function build(array $input, ?Integration $current): array
    {
        $secrets = [];

        foreach (self::SECRETS as $name) {
            $value = filled($input[$name] ?? null) ? trim($input[$name]) : ($current?->secrets[$name] ?? null);

            if (filled($value)) {
                $secrets[$name] = $value;
            }
        }

        $text = fn (string $name) => filled($input[$name] ?? null) ? trim($input[$name]) : null;

        return [
            'config' => array_filter([
                'environment' => $input['environment'],
                'merchant_type' => $text('merchant_type'),
                'merchant_name' => $text('merchant_name'),
                'transaction_type' => $text('transaction_type'),
                'page_language' => $text('page_language'),
                'contact_email' => $text('contact_email'),
                'contact_phone' => $text('contact_phone'),
                'address_line1' => $text('address_line1'),
                'address_line2' => $text('address_line2'),
                'city' => $text('city'),
                'state' => $text('state'),
                'country' => $text('country') ? strtoupper($text('country')) : null,
                'postal_code' => $text('postal_code'),
            ], fn ($value) => $value !== null),
            'secrets' => $secrets,
        ];
    }

    public function problems(array $config, ?array $secrets): array
    {
        $missing = [];

        foreach (['merchant_number' => 'el número de comercio', 'merchant_terminal' => 'la terminal'] as $name => $label) {
            if (! filled($secrets[$name] ?? null)) {
                $missing[] = "Falta {$label}.";
            }
        }

        foreach (['merchant_type' => 'la categoría del comercio', 'merchant_name' => 'el nombre del comercio'] as $name => $label) {
            if (! filled($config[$name] ?? null)) {
                $missing[] = "Falta {$label}.";
            }
        }

        return $missing;
    }

    public function form(Integration $integration): array
    {
        $form = [];

        foreach ($this->fields() as $field) {
            if ($field['kind'] !== 'secret') {
                $form[$field['name']] = $integration->config[$field['name']] ?? ($field['default'] ?? '');
            }
        }

        foreach (self::SECRETS as $name) {
            $value = $integration->secrets[$name] ?? null;
            $form[$name] = '';
            $form["{$name}_set"] = filled($value);
            $form["{$name}_mask"] = filled($value) ? self::mask($value) : null;
        }

        return $form;
    }

    public function summary(Integration $integration): array
    {
        $secrets = $integration->secrets ?? [];

        return ['rows' => array_values(array_filter([
            ['label' => 'Ambiente', 'value' => ($integration->config['environment'] ?? 'sandbox') === 'production' ? 'Producción' : 'Laboratorio'],
            filled($secrets['merchant_number'] ?? null) ? ['label' => 'Comercio', 'value' => self::mask($secrets['merchant_number'])] : null,
            filled($secrets['merchant_terminal'] ?? null) ? ['label' => 'Terminal', 'value' => self::mask($secrets['merchant_terminal'])] : null,
            filled($integration->config['merchant_name'] ?? null) ? ['label' => 'Nombre', 'value' => $integration->config['merchant_name']] : null,
        ]))];
    }

    /** False on purpose: the connection test needs the verified CardNET protocol and its laboratory. Until then a saved configuration can never become «validada». */
    public function supportsTest(): bool
    {
        return false;
    }

    public function test(Integration $integration): TestResult
    {
        return TestResult::failure('La prueba de conexión con CardNET todavía no está disponible: falta confirmar su protocolo oficial.');
    }

    public function auditValues(array $config, ?array $secrets): array
    {
        $labels = ['environment' => 'Ambiente de CardNET', 'merchant_type' => 'Categoría del comercio (CardNET)', 'merchant_name' => 'Nombre del comercio (CardNET)', 'transaction_type' => 'Tipo de transacción (CardNET)', 'page_language' => 'Idioma de la página (CardNET)'];
        $audited = [];

        foreach ($labels as $name => $label) {
            $audited[$label] = $config[$name] ?? null;
        }

        // Contact and address are personal or business data: the audit says THAT they changed, never their value.
        $contact = array_intersect_key($config, array_flip(['contact_email', 'contact_phone', 'address_line1', 'address_line2', 'city', 'state', 'country', 'postal_code']));
        $audited['Contacto y dirección (CardNET)'] = Masked::of($contact === [] ? null : json_encode($contact));

        return $audited + [
            'Número de comercio (CardNET)' => Masked::of($secrets['merchant_number'] ?? null),
            'Terminal (CardNET)' => Masked::of($secrets['merchant_terminal'] ?? null),
            'Terminal American Express (CardNET)' => Masked::of($secrets['merchant_terminal_amex'] ?? null),
        ];
    }

    /** `••••1234`: never more than the last four characters of an identifier. */
    public static function mask(string $value): string
    {
        return '••••'.substr($value, -4);
    }
}
