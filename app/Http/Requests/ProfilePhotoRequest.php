<?php

namespace App\Http\Requests;

use App\Services\ProfilePhoto;
use Illuminate\Foundation\Http\FormRequest;

/** The personal photo of the signed-in account: a real JPG, PNG or WebP (checked by content, not by name), of a bounded size and dimensions. */
class ProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.ProfilePhoto::MAX_KB,
                'dimensions:min_width=128,min_height=128,max_width='.ProfilePhoto::MAX_SIDE.',max_height='.ProfilePhoto::MAX_SIDE,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.image' => 'El archivo debe ser una imagen.',
            'photo.mimes' => 'Usa una imagen JPG, PNG o WebP.',
            'photo.max' => 'La imagen pesa demasiado: el máximo es 2 MB.',
            'photo.dimensions' => 'La imagen debe medir entre 128 y '.ProfilePhoto::MAX_SIDE.' píxeles por lado.',
        ];
    }
}
