<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * How the button / widget of a channel looks on the customer's site. It owns the whole contract of that
 * configuration: which settings each kind of channel has (`widget`: button + chat panel; `button`: a link button), their
 * defaults, how they are validated and which of them the public script receives. Settings are stored per
 * chatbot + channel (`chatbot_channel_appearances`) and never contain credentials. What each value looks like is decided
 * by the widget script (public/widget/ava-widget.js), the same code that draws the preview in the interface.
 */
class ChannelAppearanceService
{
    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    private const LABELS = [
        'enabled' => 'Mostrar en la página',
        'button_text' => 'Texto del botón',
        'header_title' => 'Título del encabezado',
        'welcome_message' => 'Mensaje inicial',
        'message' => 'Mensaje inicial del chat',
        'icon' => 'Icono',
        'primary_color' => 'Color principal',
        'text_color' => 'Color del texto',
        'size' => 'Tamaño del botón',
        'shape' => 'Forma del botón',
        'position' => 'Posición',
        'shadow' => 'Sombra',
        'widget_size' => 'Tamaño del chat',
        'radius' => 'Redondeo del chat',
        'open_behavior' => 'Apertura',
    ];

    /** @var array<string, array<string, mixed>> kind => setting => default (null colors resolve at read time) */
    private const DEFAULTS = [
        'widget' => [
            'enabled' => true, 'button_text' => null, 'header_title' => null, 'welcome_message' => null, 'icon' => 'avatar',
            'primary_color' => null, 'text_color' => null, 'size' => 60, 'shape' => 50, 'position' => 'bottom-right',
            'shadow' => 2, 'widget_size' => 370, 'radius' => 16, 'open_behavior' => 'click',
        ],
        'button' => [
            'enabled' => true, 'button_text' => null, 'message' => null, 'icon' => 'channel',
            'primary_color' => null, 'text_color' => null, 'size' => 60, 'shape' => 50, 'position' => 'bottom-right',
            'shadow' => 2,
        ],
    ];

    public function __construct(private readonly ChannelCatalog $channels) {}

    /** The visual kind of a channel (`widget` | `button`), or a 404 when the channel has no visual configuration. */
    public function kindOrFail(string $channelKey): string
    {
        $channel = $this->channels->get($channelKey);

        abort_unless(($channel['appearance'] ?? null) && $channel['available'], 404);

        return $channel['appearance'];
    }

    public function kind(string $channelKey): ?string
    {
        return config("chatbots.channels.{$channelKey}.appearance");
    }

    /** @return array<string, string> setting => label shown in the interface and in the audit history */
    public function labels(): array
    {
        return self::LABELS;
    }

    /** The settings of a channel of the chatbot: what was saved over the defaults (colors may still be null = automatic). */
    public function values(Chatbot $chatbot, string $channelKey): array
    {
        $kind = $this->kindOrFail($channelKey);
        $saved = $chatbot->appearances()->where('channel', $channelKey)->value('settings');
        $defaults = self::DEFAULTS[$kind];

        return array_replace($defaults, $this->modernized(array_intersect_key(is_array($saved) ? $saved : [], $defaults)));
    }

    /** A look saved with the earlier named choices (small, soft...) becomes the number that draws the same thing. */
    private function modernized(array $saved): array
    {
        foreach (config('chatbots.appearance.legacy') as $setting => $names) {
            if (isset($saved[$setting]) && is_string($saved[$setting])) {
                $saved[$setting] = $names[$saved[$setting]] ?? self::DEFAULTS['widget'][$setting];
            }
        }

        return $saved;
    }

    /** The button color when the client has not chosen one: the channel's own color or the Workspace's main color. */
    public function defaultPrimary(Workspace $workspace, string $channelKey): string
    {
        return config("chatbots.appearance.default_colors.{$channelKey}") ?? $workspace->settingsOrDefault()->primary_color;
    }

    /**
     * The settings the PUBLIC script receives (camelCase). Only presentation: nothing here is a credential, an id or a
     * Workspace detail. The button color is always a real color.
     *
     * @return array<string, mixed>
     */
    public function publicStyle(Chatbot $chatbot, string $channelKey): array
    {
        $values = $this->values($chatbot, $channelKey);
        $values['primary_color'] ??= $this->defaultPrimary($chatbot->workspace, $channelKey);

        return collect($values)->mapWithKeys(fn ($value, $key) => [Str::camel($key) => $value])->all();
    }

    /** Whether the client wants the button shown on the page (a stopped one does not exist for the public script). */
    public function enabled(Chatbot $chatbot, string $channelKey): bool
    {
        return (bool) $this->values($chatbot, $channelKey)['enabled'];
    }

    /**
     * Where a channel's button leads. The web chat always has its destination (Ava itself); WhatsApp needs the number
     * of the Workspace's integration, taken from a REAL verification with Meta (never typed by hand), because a button
     * without a valid destination would look like it works.
     *
     * @return array{ready: bool, message: ?string, number: ?string}
     */
    public function destination(Workspace $workspace, string $channelKey): array
    {
        if ($channelKey !== 'whatsapp') {
            return ['ready' => true, 'message' => null, 'number' => null];
        }

        $integration = $workspace->integrations()->where('type', 'whatsapp')->first();
        $number = $integration?->config['display_number'] ?? null;

        return match (true) {
            $integration === null => ['ready' => false, 'message' => 'WhatsApp todavía no está configurado. Configura primero el canal WhatsApp.', 'number' => null],
            ! $integration->is_active => ['ready' => false, 'message' => 'La integración de WhatsApp está inactiva. Actívala en Integraciones.', 'number' => null],
            $integration->last_test_ok !== true || ! $number => ['ready' => false, 'message' => 'Verifica el número de WhatsApp con «Probar conexión» en Integraciones para generar el botón.', 'number' => null],
            default => ['ready' => true, 'message' => null, 'number' => $number],
        };
    }

    /** The wa.me link of the WhatsApp button, or null while there is no valid destination. */
    public function whatsappLink(Chatbot $chatbot): ?string
    {
        $number = $this->destination($chatbot->workspace, 'whatsapp')['number'];

        if (! $number) {
            return null;
        }

        $text = trim((string) ($this->values($chatbot, 'whatsapp')['message'] ?? ''));

        return "https://wa.me/{$number}".($text !== '' ? '?text='.rawurlencode($text) : '');
    }

    /** Saves the (already validated) settings of a channel, replacing the previous ones. */
    public function save(Chatbot $chatbot, string $channelKey, array $input): void
    {
        $kind = $this->kindOrFail($channelKey);
        $settings = array_replace($this->values($chatbot, $channelKey), array_intersect_key($input, self::DEFAULTS[$kind]));

        foreach (['button_text', 'header_title', 'welcome_message', 'message'] as $text) {
            if (array_key_exists($text, $settings)) {
                $settings[$text] = filled($settings[$text]) ? trim($settings[$text]) : null;
            }
        }

        foreach (['primary_color', 'text_color'] as $color) {
            $settings[$color] = filled($settings[$color]) ? strtolower($settings[$color]) : null;
        }

        $chatbot->appearances()->updateOrCreate(
            ['channel' => $channelKey],
            ['workspace_id' => $chatbot->workspace_id, 'settings' => $settings],
        );
    }

    /**
     * Validation rules of a channel's settings. `$primary` is the button color that will apply (the one being saved or
     * the default), needed to refuse a text color that cannot be read on it.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(string $channelKey, string $primary): array
    {
        $kind = $this->kindOrFail($channelKey);
        $options = config('chatbots.appearance');
        $length = $options['max_length'];
        $choice = fn (string $list) => ['required', 'string', Rule::in(array_keys($options[$list]))];
        $range = fn (string $setting) => ['bail', 'required', 'integer', "between:{$options[$setting]['min']},{$options[$setting]['max']}", "multiple_of:{$options[$setting]['step']}"];

        $rules = [
            'enabled' => ['required', 'boolean'],
            'button_text' => ['nullable', 'string', 'max:'.$length['button_text']],
            'icon' => $choice('icons'),
            'primary_color' => ['nullable', 'string', 'regex:'.self::HEX],
            'text_color' => ['bail', 'nullable', 'string', 'regex:'.self::HEX, $this->readableOn($primary)],
            'size' => $range('size'),
            'shape' => $range('shape'),
            'position' => $choice('positions'),
            'shadow' => $range('shadow'),
        ];

        if ($kind === 'widget') {
            $rules += [
                'header_title' => ['nullable', 'string', 'max:'.$length['header_title']],
                'welcome_message' => ['nullable', 'string', 'max:'.$length['welcome_message']],
                'widget_size' => $range('widget_size'),
                'radius' => $range('radius'),
                'open_behavior' => $choice('open_behaviors'),
            ];
        } else {
            $rules['message'] = ['nullable', 'string', 'max:'.$length['message']];
        }

        return $rules;
    }

    /**
     * What the form offers, in the shape the interface needs ({value, label} lists) so it never repeats the catalog.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $options = config('chatbots.appearance');
        $list = fn (array $items) => collect($items)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();

        return [
            'positions' => $list($options['positions']),
            'openBehaviors' => $list($options['open_behaviors']),
            'icons' => $list($options['icons']),
            'ranges' => collect(['size', 'shape', 'shadow', 'widget_size', 'radius'])->mapWithKeys(fn (string $setting) => [Str::camel($setting) => $options[$setting]])->all(),
            'maxLength' => collect($options['max_length'])->mapWithKeys(fn ($max, $key) => [Str::camel($key) => $max])->all(),
        ];
    }

    /**
     * What the Chatbots page needs per channel with a visual configuration. Channels the platform cannot serve yet are
     * listed as unavailable ("Próximamente") with no settings, so nothing pretends to work.
     *
     * @return list<array<string, mixed>>
     */
    public function forPage(Chatbot $chatbot): array
    {
        $saved = $chatbot->appearances()->pluck('channel')->all();

        return collect($this->channels->all())->filter(fn (array $channel) => isset($channel['appearance']) || ! $channel['available'])
            ->map(function (array $channel) use ($chatbot, $saved) {
                $key = $channel['key'];

                if (! $channel['available']) {
                    return ['key' => $key, 'label' => $channel['label'], 'available' => false];
                }

                return [
                    'key' => $key,
                    'label' => $channel['label'],
                    'available' => true,
                    'kind' => $channel['appearance'],
                    // Whether the client has saved a look for this channel yet (the page opens the form until then).
                    'saved' => in_array($key, $saved, true),
                    'values' => $this->values($chatbot, $key),
                    'defaultPrimary' => $this->defaultPrimary($chatbot->workspace, $key),
                    'destination' => $this->destination($chatbot->workspace, $key),
                ];
            })->values()->all();
    }

    /** Validation rule: the text color must keep a readable contrast against the button color. */
    private function readableOn(string $background): ValidationRule
    {
        $minimum = (float) config('chatbots.appearance.min_contrast');

        return new class($background, $minimum) implements ValidationRule
        {
            public function __construct(private readonly string $background, private readonly float $minimum) {}

            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                if (is_string($value) && self::contrast($value, $this->background) < $this->minimum) {
                    $fail('El color del texto casi no se distingue del color del botón. Elige uno con más contraste.');
                }
            }

            private static function luminance(string $hex): float
            {
                $channels = array_map(function (string $part) {
                    $value = hexdec($part) / 255;

                    return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
                }, str_split(ltrim($hex, '#'), 2));

                return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
            }

            public static function contrast(string $first, string $second): float
            {
                [$light, $dark] = [max(self::luminance($first), self::luminance($second)), min(self::luminance($first), self::luminance($second))];

                return ($light + 0.05) / ($dark + 0.05);
            }
        };
    }
}
