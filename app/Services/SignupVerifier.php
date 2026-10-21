<?php

namespace App\Services;

use App\Mail\SignupCodeMail;
use App\Models\SignupRequest;
use App\Models\SignupVerification;
use App\Models\User;
use App\Support\MailAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Proves that the person signing up can receive mail at the address they typed, with a one-time code. The address is never treated as verified
 * because it was typed: only a correct, unexpired, unused code opens the next step.
 *
 *  - The code is 6 random digits (CSPRNG), valid for `signup.code_ttl_minutes`, and only a keyed hash (HMAC with the application key, bound to the
 *    address) is stored, so a database leak does not reveal live codes.
 *  - Wrong attempts are limited per code and, past the limit, the code dies; sending is limited per address per hour and by a cooldown.
 *  - Sending answers the same whether or not the address already has an account (no enumeration). Only AFTER the code proves ownership of the
 *    address may the flow say that an account exists.
 *  - A verified code is consumed in the same transaction that creates (or resumes) the sign-up request, so it can not be used twice.
 */
class SignupVerifier
{
    /** Sends a new code to the address and returns the verification that must be proved. Throws a validation error when a limit applies. */
    public function send(string $email, ?string $ip): SignupVerification
    {
        $email = Str::lower(trim($email));

        if (! MailAvailability::usable()) {
            throw ValidationException::withMessages(['email' => 'El envío de correos no está configurado en este entorno, así que no podemos enviar el código. Contacta con soporte.']);
        }

        $recent = SignupVerification::where('email', $email)->where('created_at', '>=', now()->subHour());

        if ((clone $recent)->count() >= (int) config('signup.code_sends_per_hour')) {
            throw ValidationException::withMessages(['email' => 'Has solicitado demasiados códigos. Espera un poco antes de intentarlo de nuevo.']);
        }

        if ($last = (clone $recent)->latest('id')->first()) {
            $wait = (int) config('signup.code_resend_seconds') - (int) $last->last_sent_at->diffInSeconds(now(), true);

            if ($wait > 0) {
                throw ValidationException::withMessages(['email' => "Podrás pedir otro código en {$wait} segundos."]);
            }
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $verification = DB::transaction(function () use ($email, $code, $ip) {
            // A new code replaces the previous ones of the address: only the latest can be used.
            SignupVerification::where('email', $email)->whereNull('consumed_at')->update(['consumed_at' => now()]);

            return SignupVerification::create([
                'email' => $email, 'code_hash' => $this->hash($email, $code), 'last_sent_at' => now(),
                'expires_at' => now()->addMinutes((int) config('signup.code_ttl_minutes')), 'ip_address' => $ip,
            ]);
        });

        try {
            Mail::to($email)->send(new SignupCodeMail($code, (int) config('signup.code_ttl_minutes')));
        } catch (Throwable $e) {
            // The mail server failed: the code that was never delivered is voided, a fixed code is reported (no address, no code, no server message) and the
            // visitor gets a controlled error instead of a stack trace.
            $verification->update(['consumed_at' => now()]);
            app(OperationalAlert::class)->raise('signup_code_mail_failed', ['exception' => $e::class]);

            throw ValidationException::withMessages(['email' => 'No pudimos enviar el código en este momento. Inténtalo de nuevo en unos minutos o contacta con soporte.']);
        }

        return $verification;
    }

    /**
     * Checks the code. A correct one is consumed and gives the sign-up request of that address (a new draft, or the open one that was left half
     * finished, so the person can resume). Any failure counts as an attempt and says nothing beyond "invalid or expired".
     */
    public function verify(SignupVerification $verification, string $code, ?string $ip): SignupRequest
    {
        // The outcome is decided inside the transaction but the error is thrown AFTER it commits: an exception inside would roll back the counted
        // attempt (or the consumed code) and the limits would never hold.
        $result = DB::transaction(function () use ($verification, $code, $ip) {
            $locked = SignupVerification::whereKey($verification->id)->lockForUpdate()->firstOrFail();

            if ($locked->consumed_at !== null || $locked->expires_at->isPast() || $locked->attempts >= (int) config('signup.code_max_attempts')) {
                return 'invalid';
            }

            if (! hash_equals($locked->code_hash, $this->hash($locked->email, trim($code)))) {
                $locked->increment('attempts');

                return 'invalid';
            }

            $locked->update(['verified_at' => now(), 'consumed_at' => now()]);

            // Ownership of the address is proven now, so it is safe to say it already has an account.
            if (User::where('email', $locked->email)->exists()) {
                return 'exists';
            }

            $open = SignupRequest::where('email', $locked->email)->whereIn('status', [SignupRequest::DRAFT, SignupRequest::AWAITING_PAYMENT, SignupRequest::QUOTE_REQUESTED])
                ->where('expires_at', '>', now())->latest('id')->first();

            return $open ?? SignupRequest::create([
                'public_id' => (string) Str::uuid(), 'email' => $locked->email, 'email_verified_at' => $locked->verified_at,
                'status' => SignupRequest::DRAFT, 'expires_at' => now()->addHours((int) config('signup.draft_ttl_hours')), 'ip_address' => $ip,
            ]);
        });

        if ($result === 'invalid') {
            throw ValidationException::withMessages(['code' => 'El código no es válido o ya venció. Revisa tu correo o pide uno nuevo.']);
        }

        if ($result === 'exists') {
            throw ValidationException::withMessages(['email' => 'Este correo ya tiene una cuenta. Inicia sesión con tu Workspace o recupera tu contraseña.']);
        }

        return $result;
    }

    private function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, (string) config('app.key'));
    }
}
