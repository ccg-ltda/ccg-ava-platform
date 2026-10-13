<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Which free starts are wanted: a service, optionally a professional, from a local date for a few days (never beyond the public window). */
class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'professional_id' => ['nullable', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:1,'.config('bookings.public.max_days')],
            // The appointment being moved (its own time counts as free): by id for staff and the automation, by its link for the customer.
            'ignore_appointment' => ['nullable', 'integer'],
            'token' => ['nullable', 'regex:/^[A-Za-z0-9]{40}$/'],
        ];
    }

    /** The first moment of the requested local day (the Workspace's timezone), as UTC. */
    public function from(string $timezone): CarbonImmutable
    {
        $date = $this->validated('from');

        return ($date ? CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone) : CarbonImmutable::now($timezone))->startOfDay()->utc();
    }

    public function days(): int
    {
        return (int) ($this->validated('days') ?? 7);
    }
}
