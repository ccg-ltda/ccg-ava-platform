<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A day or range of days (or hours of a day) that cannot be booked, for one professional or the whole business. Route middleware: `manage-availability`. */
class SaveScheduleExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'professional_id' => ['nullable', 'integer', Rule::exists('professionals', 'id')->where('workspace_id', $this->attributes->get('workspace')->id)],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'starts_at' => ['nullable', 'required_with:ends_at', 'date_format:H:i'],
            'ends_at' => ['nullable', 'required_with:starts_at', 'date_format:H:i', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function attributes(): array
    {
        return ['date_from' => 'la fecha inicial', 'date_to' => 'la fecha final', 'starts_at' => 'la hora inicial', 'ends_at' => 'la hora final', 'reason' => 'el motivo'];
    }

    /** @return array<string, mixed> */
    public function exception(): array
    {
        $data = $this->validated();

        return [
            'professional_id' => $data['professional_id'] ?? null,
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'starts_at' => filled($data['starts_at'] ?? null) ? $data['starts_at'].':00' : null,
            'ends_at' => filled($data['ends_at'] ?? null) ? $data['ends_at'].':00' : null,
            'reason' => filled($data['reason'] ?? null) ? trim($data['reason']) : null,
        ];
    }
}
