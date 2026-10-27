<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\WorkspaceInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Acceptance of an invitation (public, guests only). The link carries the token; every way it can fail (unknown, used, cancelled, expired, Workspace closed) shows
 * the same neutral message. The page names the Workspace and the e-mail the invitation was sent to, never anything else about it.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly WorkspaceInvitations $invitations) {}

    public function show(Request $request, string $token): SymfonyResponse
    {
        $invitation = $this->invitations->find($token);

        // The link carries the token: it must not be cached nor sent as a Referer to anything the page links to.
        return Inertia::render('Auth/AcceptInvitation', [
            'valid' => $invitation !== null,
            'token' => $invitation ? $token : null,
            'email' => $invitation?->email,
            'name' => $invitation?->name,
            'workspace' => $invitation?->workspace->name,
            'message' => $invitation ? null : WorkspaceInvitations::INVALID,
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $code = $this->invitations->find($token)?->workspace->code;
        $this->invitations->accept($token, $data['name'], $data['password']);

        return redirect()->route('pre-login')->with('status', "Tu cuenta está lista. Ingresa con el código de Workspace {$code}, tu correo y la contraseña que elegiste.");
    }
}
