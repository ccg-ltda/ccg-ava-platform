<?php

/*
 * Spanish validation messages. Rules that are missing here fall back to the framework's English text
 * (`fallback_locale`), so only the rules the application actually uses need a translation.
 */
return [
    'accepted' => 'Debes aceptar :attribute.',
    'array' => ':Attribute debe ser una lista.',
    'between' => [
        'numeric' => ':Attribute debe estar entre :min y :max.',
        'file' => ':Attribute debe pesar entre :min y :max kilobytes.',
        'string' => ':Attribute debe tener entre :min y :max caracteres.',
        'array' => ':Attribute debe tener entre :min y :max elementos.',
    ],
    'boolean' => ':Attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'current_password' => 'La contraseña actual es incorrecta.',
    'date' => ':Attribute no es una fecha válida.',
    'email' => ':Attribute debe ser un correo electrónico válido.',
    'exists' => 'El valor de :attribute no es válido.',
    'file' => ':Attribute debe ser un archivo.',
    'filled' => ':Attribute no puede estar vacío.',
    'image' => ':Attribute debe ser una imagen.',
    'in' => 'El valor de :attribute no es válido.',
    'integer' => ':Attribute debe ser un número entero.',
    'lowercase' => ':Attribute debe estar en minúsculas.',
    'max' => [
        'numeric' => ':Attribute no puede ser mayor que :max.',
        'file' => ':Attribute no puede pesar más de :max kilobytes.',
        'string' => ':Attribute no puede tener más de :max caracteres.',
        'array' => ':Attribute no puede tener más de :max elementos.',
    ],
    'min' => [
        'numeric' => ':Attribute debe ser al menos :min.',
        'file' => ':Attribute debe pesar al menos :min kilobytes.',
        'string' => ':Attribute debe tener al menos :min caracteres.',
        'array' => ':Attribute debe tener al menos :min elementos.',
    ],
    'numeric' => ':Attribute debe ser un número.',
    'password' => [
        'letters' => ':Attribute debe incluir al menos una letra.',
        'mixed' => ':Attribute debe incluir mayúsculas y minúsculas.',
        'numbers' => ':Attribute debe incluir al menos un número.',
        'symbols' => ':Attribute debe incluir al menos un símbolo.',
        'uncompromised' => ':Attribute apareció en una filtración de datos; elige otra.',
    ],
    'regex' => 'El formato de :attribute no es válido.',
    'required' => ':Attribute es obligatorio.',
    'same' => ':Attribute y :other deben coincidir.',
    'size' => [
        'numeric' => ':Attribute debe ser :size.',
        'file' => ':Attribute debe pesar :size kilobytes.',
        'string' => ':Attribute debe tener :size caracteres.',
        'array' => ':Attribute debe contener :size elementos.',
    ],
    'string' => ':Attribute debe ser texto.',
    'unique' => 'Ya existe un registro con ese valor de :attribute.',
    'url' => ':Attribute debe ser una URL válida.',

    'custom' => [],

    'attributes' => [
        'name' => 'el nombre',
        'email' => 'el correo electrónico',
        'password' => 'la contraseña',
        'password_confirmation' => 'la confirmación de la contraseña',
        'current_password' => 'la contraseña actual',
        'role' => 'el rol',
        'workspace_id' => 'el Workspace',
        'workspace_code' => 'el código del Workspace',
        'organization_id' => 'la organización',
        'code' => 'el código',
        'permissions' => 'los permisos',
    ],
];
