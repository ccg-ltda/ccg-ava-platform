<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\WorkspaceAdministration;
use App\Services\WorkspaceInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Invitations to a Workspace, from the platform administration (the routes require `manage-workspaces`, `create-users` and `assign-roles` inside
 * Desarrollo_DEV). A client administrator never reaches these: they only manage the people of their own Workspace from Usuarios y roles.
 */
class WorkspaceInvitationController extends Controller
{
    public function __construct(private readonly WorkspaceInvitations $invitations) {}

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->assertReady();

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
        ], [], ['email' => 'el correo', 'role' => 'el rol']);

        $result = $this->invitations->invite($request->user(), $workspace, $data['email'], $data['name'] ?? null, $data['role']);

        return back()->with('success', $result['outcome'] === 'associated'
            ? 'Esa cuenta ya existía: se añadió al Workspace sin cambiar su contraseña ni sus otros accesos.'
            : 'Invitación enviada. El enlace caduca en '.WorkspaceInvitations::hours().' horas.');
    }

    public function resend(Request $request, Workspace $workspace, int $invitation): RedirectResponse
    {
        $this->assertReady();
        $this->invitations->resend($request->user(), $workspace, $this->find($workspace, $invitation));

        return back()->with('success', 'Invitación reenviada. El enlace anterior dejó de funcionar.');
    }

    public function destroy(Request $request, Workspace $workspace, int $invitation): RedirectResponse
    {
        $this->assertReady();
        abort_unless(app(WorkspaceAdministration::class)->canAdminister($request->user(), $workspace), 404);
        $this->invitations->revoke($workspace, $this->find($workspace, $invitation));

        return back()->with('success', 'Invitación cancelada.');
    }

    private function find(Workspace $workspace, int $invitation): WorkspaceInvitation
    {
        return $workspace->invitations()->findOrFail($invitation);
    }

    private function assertReady(): void
    {
        if (! WorkspaceInvitations::ready()) {
            throw ValidationException::withMessages(['email' => 'Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».']);
        }
    }
}
