<?php

namespace App\Http\Requests;

use App\Rules\ReadableBrandColor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Configuraciones form. Authorization is the `workspace.permission:manage-settings` route middleware. */
class UpdateWorkspaceSettingsRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', Rule::in(array_keys(config('workspace.currencies')))],
            'timezone' => ['required', Rule::in(array_keys(config('workspace.timezones')))],
            'date_format' => ['required', Rule::in(array_keys(config('workspace.date_formats')))],
            'time_format' => ['required', Rule::in(array_keys(config('workspace.time_formats')))],
            'appearance' => ['required', Rule::in(array_keys(config('workspace.appearances')))],
            'primary_color' => ['required', new ReadableBrandColor],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['boolean'],
            'tax_country' => ['required', Rule::in(array_keys(config('workspace.tax_countries')))],
            'tax_enabled' => ['required', 'boolean'],
            'tax_name' => ['required', 'string', 'max:40'],
            'tax_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'description' => 'la descripción',
            'currency' => 'la moneda',
            'timezone' => 'la zona horaria',
            'date_format' => 'el formato de fecha',
            'time_format' => 'el formato de hora',
            'appearance' => 'el modo de apariencia',
            'primary_color' => 'el color principal',
            'logo' => 'el logo',
            'tax_country' => 'el país fiscal',
            'tax_name' => 'el nombre del impuesto',
            'tax_rate' => 'la tasa',
        ];
    }

    public function messages(): array
    {
        return [
            'logo.max' => 'El logo no puede pesar más de 1 MB.',
            'logo.image' => 'El logo debe ser una imagen PNG, JPG o WebP.',
            'logo.mimes' => 'El logo debe ser una imagen PNG, JPG o WebP.',
            'tax_rate.between' => 'La tasa debe estar entre 0 y 100.',
            'tax_rate.decimal' => 'La tasa admite hasta dos decimales.',
        ];
    }
}
