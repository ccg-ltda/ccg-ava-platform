<?php

namespace App\Campaigns;

use App\Models\Customer;
use App\Models\Workspace;

/**
 * Variables of a message ({{nombre}}, {{telefono}}...): which exist (config/campaigns.php), which a body uses, how a body is filled
 * for a customer and which data a customer lacks. The only place that knows the syntax, so templates, campaigns and the preview agree.
 */
class MessageTemplates
{
    private const PATTERN = '/\{\{\s*([A-Za-z_]+)\s*\}\}/';

    /** The text as it is stored and counted: every line break is a single LF (a browser may send CR+LF) and the ends are trimmed. */
    public function normalize(?string $body): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $body));
    }

    /** Characters of the body, counted in Unicode code points. The same rule as the editor's counter. */
    public function length(string $body): int
    {
        return mb_strlen($body, 'UTF-8');
    }

    /** A clear message when the body is longer than WhatsApp accepts, with the excess; null when it fits. */
    public function lengthError(string $body, ?int $max = null): ?string
    {
        $max ??= (int) config('campaigns.body_max');
        $length = $this->length($body);

        return $length > $max
            ? 'El mensaje tiene '.number_format($length, 0, ',', '.').' caracteres y el máximo del cuerpo de una plantilla de WhatsApp es '.number_format($max, 0, ',', '.').': sobran '.number_format($length - $max, 0, ',', '.').'. Acórtalo; Ava no lo recorta por ti.'
            : null;
    }

    /**
     * Double braces that are not a variable of the right shape ({{nombre}}): a lone "{{", "}}", an empty "{{ }}" or a numbered "{{1}}", which is the
     * form Meta uses for positional parameters and Ava does not fill in. Single braces are plain text.
     */
    public function hasMalformedMarkers(string $body): bool
    {
        $rest = preg_replace(self::PATTERN, '', $body);

        return str_contains($rest, '{{') || str_contains($rest, '}}');
    }

    /** @return list<string> variable names used by the body, once each, in order */
    public function used(string $body): array
    {
        preg_match_all(self::PATTERN, $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> variables of the body that Ava does not know */
    public function unknown(string $body): array
    {
        return array_values(array_diff($this->used($body), array_keys(config('campaigns.variables'))));
    }

    /** The value a customer gives to a variable, or null when that data is missing. */
    public function valueFor(Customer $customer, string $variable): ?string
    {
        $field = config("campaigns.variables.{$variable}.field");
        $value = match ($field) {
            'full_name' => $customer->fullName(),
            'phone' => $customer->phone,
            default => $customer->{$field} ?? null,
        };

        return filled($value) ? trim((string) $value) : null;
    }

    /** @return list<string> variables of the body the customer has no data for */
    public function missingFor(Customer $customer, string $body): array
    {
        return array_values(array_filter($this->used($body), fn (string $variable) => $this->valueFor($customer, $variable) === null));
    }

    /** The body for one customer. A missing value stays visible as the variable itself: a message is never rendered with a hole. */
    public function render(string $body, Customer $customer): string
    {
        return preg_replace_callback(self::PATTERN, fn (array $match) => $this->valueFor($customer, $match[1]) ?? $match[0], $body);
    }

    /**
     * How many customers of the Workspace have the data of each variable: lets the editor say which variables would leave people out.
     *
     * @return array<string, array{label: string, available: int, total: int}>
     */
    public function availability(Workspace $workspace): array
    {
        $total = $workspace->customers()->count();
        $filled = fn (string $column) => $workspace->customers()->whereNotNull($column)->where($column, '!=', '')->count();
        $counts = [
            'first_name' => $total,
            'last_name' => $filled('last_name'),
            'full_name' => $total,
            'phone' => $filled('phone'),
            'email' => $filled('email'),
        ];

        return collect(config('campaigns.variables'))->map(fn (array $variable) => [
            'label' => $variable['label'],
            'available' => $counts[$variable['field']],
            'total' => $total,
        ])->all();
    }
}
