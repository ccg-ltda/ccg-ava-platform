<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\MailAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
            'mailUsable' => MailAvailability::usable(),
            'expireMinutes' => $this->expireMinutes(),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * The answer is the same whether or not the email belongs to an account (and whether the account is active), so this
     * form cannot be used to find out who has one. The only different answer is "mail is not configured", which says
     * nothing about the account and is shown instead of pretending that a message was sent.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        if (! MailAvailability::usable()) {
            throw ValidationException::withMessages([
                'email' => 'El envío de correos no está configurado en este entorno, así que no podemos enviar el enlace. Pide al administrador de la plataforma que restablezca tu contraseña.',
            ]);
        }

        // Only an active account gets a link. Password::sendResetLink also applies its own per-account wait; its outcome is not shown.
        $user = User::where('email', $validated['email'])->first();

        if ($user && $user->is_active) {
            Password::sendResetLink(['email' => $user->email]);
        }

        $local = MailAvailability::deliversRealMail() ? '' : ' (Entorno local: el mensaje no sale del servidor; se escribe en el registro.)';

        return back()->with('status', "Si el correo corresponde a una cuenta activa, recibirás en unos minutos un enlace para elegir una contraseña nueva. Caduca en {$this->expireMinutes()} minutos.{$local}");
    }

    private function expireMinutes(): int
    {
        return (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
    }
}
