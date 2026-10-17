<?php

namespace App\Http\Middleware;

use App\Services\AgentToolTrace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Traces a business tool call of the assistant (see AgentToolTrace): the row is written when the call arrives and settled when the tool answered. It runs
 * INSIDE the tool routes, after the token, the plan and the module checks, so only authorized calls to real tools are traced; a refused token or a plan without
 * the module never reaches it. It never alters the request, the response or the exception.
 */
class TraceAgentTool
{
    public function __construct(private readonly AgentToolTrace $trace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $call = $this->trace->start($request);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->trace->finish($call, $request, null, $exception, $startedAt);

            throw $exception;
        }

        $this->trace->finish($call, $request, $response, null, $startedAt);

        return $response;
    }
}
