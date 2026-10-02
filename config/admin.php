<?php

/*
|--------------------------------------------------------------------------
| Initial administrator (read by Database\Seeders\AdminUserSeeder)
|--------------------------------------------------------------------------
|
| Values come only from the environment so nothing is hard-coded and the
| config can be cached. The example values in .env.example are for
| development; the seeder refuses weak passwords in production.
|
*/

return [
    'email' => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),
    'name' => env('ADMIN_NAME', 'Administrador'),
];
