<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfilePhotoRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Legal\LegalDocuments;
use App\Services\ProfilePhoto;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-in person's own profile: their account data, password, personal photo and privacy preferences. Everything here belongs to the
 * account of the request (never to an id from the client), so there is no way to read or change another person's profile from it.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly LegalDocuments $legal,
        private readonly ProfilePhoto $photo,
    ) {}

    /**
     * Display the user's profile.
     */
    public function edit(Request $request): \Inertia\Response
    {
        $user = $request->user();
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            // What the account is, in plain words: no secret, and the password only as "configured" (it cannot be read: only a hash exists).
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'verified' => $user->email_verified_at !== null,
                'active' => (bool) $user->is_active,
                'memberSince' => $workspace->settingsOrDefault()->formatDate($user->created_at),
                'role' => $request->attributes->get('workspace_role'),
                'workspace' => $workspace->name,
                'passwordSet' => filled($user->getAuthPassword()),
            ],
            'photoUrl' => $this->photo->url($user),
            'photoRules' => ['maxKb' => ProfilePhoto::MAX_KB, 'min' => 128, 'max' => ProfilePhoto::MAX_SIDE],
            'legalAvailable' => $available = $this->legal->available(),
            'legalReviewed' => (bool) config('legal.reviewed'),
            'marketing' => $available && $this->legal->marketingGranted($user),
            'accepted' => $available ? $this->legal->acceptedBy($user) : [],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('success', 'Perfil actualizado correctamente');
    }

    /** The photo of the signed-in account, for the profile and the top bar. There is no route that names another account. */
    public function photo(Request $request): Response|StreamedResponse
    {
        return $this->photo->response($request->user(), $request);
    }

    public function storePhoto(ProfilePhotoRequest $request): RedirectResponse
    {
        $this->photo->store($request->user(), $request->file('photo'));

        return Redirect::route('profile.edit')->with('success', 'Foto actualizada correctamente');
    }

    public function destroyPhoto(Request $request): RedirectResponse
    {
        $this->photo->remove($request->user());

        return Redirect::route('profile.edit')->with('success', 'Foto retirada');
    }
}
