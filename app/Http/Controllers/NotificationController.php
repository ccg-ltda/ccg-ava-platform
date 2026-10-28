<?php

namespace App\Http\Controllers;

use App\Services\Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The bell: recent notifications, explicit read and read-all. Everything is resolved from the Workspace of the session and the user's own permissions. */
class NotificationController extends Controller
{
    public function __construct(private readonly Notifications $notifications) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->notifications->summary($request))->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        abort_unless($this->notifications->markRead($request, $notification), 404);

        return response()->json($this->notifications->summary($request))->header('Cache-Control', 'no-store');
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->notifications->markAllRead($request);

        return response()->json($this->notifications->summary($request))->header('Cache-Control', 'no-store');
    }
}
