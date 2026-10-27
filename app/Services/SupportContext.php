<?php

namespace App\Services;

use App\Models\ChatbotChannel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * Who is writing to CCG's support chat when they are a signed-in member of a CLIENT Workspace. The page gives the widget a short-lived, signed token (HMAC with the
 * application key) that names the person and the Workspace; the widget repeats it in a header. The server believes it only if (1) the signature and the expiry are
 * valid, (2) the channel really is CCG's support channel (administrative Workspace), and (3) the person still belongs to that active Workspace. Then the conversation
 * is the one of THAT person (so it continues in any browser) and carries their Workspace and name for the team. Anything else — no token, a forged or expired one, another
 * channel — is an anonymous visitor exactly as before: nothing a browser sends can choose a Workspace, a person or a conversation.
 */
class SupportContext
{
    public const HEADER = 'X-Zonno-Context';

    private const TTL_SECONDS = 43200; // 12 hours: the token lives in the page, never in a cookie

    /** The token for a member of a client Workspace, or null for CCG's own team (they are the ones who answer). */
    public function issue(User $user, Workspace $workspace): ?string
    {
        if ($workspace->isAdministrative()) {
            return null;
        }

        $payload = rtrim(strtr(base64_encode(json_encode(['u' => $user->id, 'w' => $workspace->id, 'e' => now()->timestamp + self::TTL_SECONDS])), '+/', '-_'), '=');

        return $payload.'.'.$this->signature($payload);
    }

    /**
     * @return array{contact_id: string, contact_name: string}|null the conversation key and the name the team sees, or null for an anonymous visitor
     */
    public function resolve(Request $request, ChatbotChannel $channel): ?array
    {
        $token = (string) $request->header(self::HEADER);

        if ($token === '' || ! $channel->chatbot->workspace->isAdministrative()) {
            return null;
        }

        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if (! hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        if (! is_array($data) || ! isset($data['u'], $data['w'], $data['e']) || (int) $data['e'] < now()->timestamp) {
            return null;
        }

        $workspace = Workspace::available()->find((int) $data['w']);
        $user = $workspace?->users()->whereKey((int) $data['u'])->where('users.is_active', true)->first();

        if (! $workspace || ! $user || $workspace->isAdministrative()) {
            return null;
        }

        return ['contact_id' => 'c'.$workspace->id.'u'.$user->id, 'contact_name' => mb_substr("{$workspace->name} · {$user->name}", 0, 100)];
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', 'zonno-support-context|'.$payload, (string) config('app.key'));
    }
}
