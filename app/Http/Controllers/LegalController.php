<?php

namespace App\Http\Controllers;

use App\Legal\LegalDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public legal pages (no session needed), the acceptance screen for signed-in accounts and the separate choice about
 * commercial communications. None of it touches Workspaces, roles or permissions: it is about the account itself.
 */
class LegalController extends Controller
{
    public function __construct(private readonly LegalDocuments $legal) {}

    /** Index of the documents. */
    public function index(): Response
    {
        return Inertia::render('Legal/Index', ['documents' => $this->documentList(), 'draft' => ! config('legal.reviewed')]);
    }

    /** One document by its slug. */
    public function show(string $slug): Response
    {
        [$key, $definition] = $this->legal->findBySlug($slug) ?? abort(404);

        return Inertia::render('Legal/Show', [
            'document' => ['title' => $definition['title'], 'version' => $definition['version']] + $this->legal->render($key),
            'documents' => $this->documentList(),
            'draft' => ! config('legal.reviewed'),
            'currentSlug' => $slug,
        ]);
    }

    /** The screen where an account accepts the current version of the required documents. */
    public function accept(Request $request): Response|RedirectResponse
    {
        if (! $this->legal->available()) {
            abort(503, LegalDocuments::UNAVAILABLE);
        }

        $pending = $this->legal->pendingFor($request->user());

        if ($pending->isEmpty()) {
            return redirect()->intended(route('profile.edit'));
        }

        return Inertia::render('Legal/Accept', [
            'pending' => $pending->map(fn (array $definition, string $key) => ['key' => $key, 'title' => $definition['title'], 'version' => $definition['version'], 'slug' => $definition['slug']])->values(),
            'draft' => ! config('legal.reviewed'),
            'marketing' => $this->legal->marketingGranted($request->user()),
        ]);
    }

    /** Records the acceptance. Every pending required document must be accepted; commercial communications are optional and separate. */
    public function storeAcceptance(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->legal->available()) {
            return back()->with('error', LegalDocuments::UNAVAILABLE);
        }

        $pending = $this->legal->pendingFor($user)->keys()->all();

        if ($pending === []) {
            return redirect()->intended(route('profile.edit'));
        }

        $request->validate([
            'accept' => ['required', 'array'],
            'accept.*' => ['string'],
            'marketing' => ['sometimes', 'boolean'],
        ]);

        $missing = array_diff($pending, (array) $request->input('accept'));

        if ($missing !== []) {
            throw ValidationException::withMessages(['accept' => 'Para continuar debes aceptar todos los documentos indicados.']);
        }

        $this->legal->accept($user, $pending, $request->ip());
        $this->legal->setMarketing($user, $request->boolean('marketing'), 'acceptance', $request->ip());

        return redirect()->intended(route('profile.edit'));
    }

    /** The account's own choice about commercial communications, independent of any acceptance. */
    public function updateCommunications(Request $request): RedirectResponse
    {
        $validated = $request->validate(['marketing' => ['required', 'boolean']]);

        if (! $this->legal->available()) {
            return back()->with('error', LegalDocuments::UNAVAILABLE);
        }

        $changed = $this->legal->setMarketing($request->user(), (bool) $validated['marketing'], 'profile', $request->ip());

        return back()->with('success', $changed
            ? ($validated['marketing'] ? 'Autorizaste recibir comunicaciones comerciales.' : 'Retiraste la autorización de comunicaciones comerciales.')
            : 'Tu preferencia no cambió.');
    }

    /** @return list<array{title: string, slug: string, version: string}> */
    private function documentList(): array
    {
        return collect($this->legal->all())->map(fn (array $d) => ['title' => $d['title'], 'slug' => $d['slug'], 'version' => $d['version']])->values()->all();
    }
}
