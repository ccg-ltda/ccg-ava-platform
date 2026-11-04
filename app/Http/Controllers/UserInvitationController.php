<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Invitations issued by the administrators of the ACTIVE Workspace (customer or CCG alike). The target is always the Workspace of the session: the request
 * carries no Workspace identifier and any one it sends is ignored. The routes require `create-users` and `assign-roles`, and the service re-checks that the
 * actor administers the Workspace and may hand out the role. An address that already has an account is invited too and accepts with its own password.
 */
class UserInvitationController extends Controller
{
    public function __construct(private readonly WorkspaceInvitations $invitations) {}

    public function store(Request $request): RedirectResponse
    {
        $this->assertReady();

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
        ], [], ['email' => 'el correo', 'role' => 'el rol']);

        $this->invitations->invite($request->user(), $request->attributes->get('workspace'), $data['email'], $data['name'] ?? null, $data['role'], true);

        return back()->with('success', 'Invitación enviada. El enlace caduca en '.WorkspaceInvitations::hours().' horas.');
    }

    public function resend(Request $request, int $invitation): RedirectResponse
    {
        $this->assertReady();
        $workspace = $request->attributes->get('workspace');
        $this->invitations->resend($request->user(), $workspace, $this->find($request, $invitation));

        return back()->with('success', 'Invitación reenviada. El enlace anterior dejó de funcionar.');
    }

    public function destroy(Request $request, int $invitation): RedirectResponse
    {
        $this->assertReady();
        $this->invitations->revoke($request->attributes->get('workspace'), $this->find($request, $invitation));

        return back()->with('success', 'Invitación cancelada.');
    }

    private function find(Request $request, int $invitation): WorkspaceInvitation
    {
        return $request->attributes->get('workspace')->invitations()->findOrFail($invitation);
    }

    private function assertReady(): void
    {
        if (! WorkspaceInvitations::ready()) {
            throw ValidationException::withMessages(['email' => 'Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».']);
        }
    }
}
