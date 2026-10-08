<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveChannelAppearanceRequest;
use App\Services\ChannelAppearanceService;
use Illuminate\Http\RedirectResponse;

/** Saves how a chatbot's channel button / widget looks on the customer's site (page: Chatbots > Canales y apariencia). */
class ChannelAppearanceController extends Controller
{
    public function update(SaveChannelAppearanceRequest $request, ChannelAppearanceService $appearance, int $chatbot, string $channel): RedirectResponse
    {
        $appearance->save($request->chatbot, $channel, $request->validated());

        return redirect()->back(fallback: route('chatbots.show', $chatbot))->with('success', 'Cambios guardados correctamente');
    }
}
