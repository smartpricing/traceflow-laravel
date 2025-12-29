<?php

namespace Smartness\TraceFlow\Middleware;

use Closure;
use Illuminate\Http\Request;
use Smartness\TraceFlow\TraceFlowSDK;
use Symfony\Component\HttpFoundation\Response;

class TraceFlowMiddleware
{
    public function __construct(private TraceFlowSDK $sdk) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('traceflow.middleware.enabled')) {
            return $next($request);
        }

        $headerName = config('traceflow.middleware.header_name', 'X-Trace-Id');

        // Get trace ID from header or generate new
        $traceId = $request->header($headerName) ?? \Ramsey\Uuid\Uuid::uuid4()->toString();

        // Check if continuing existing trace
        if ($request->hasHeader($headerName)) {
            // Retrieve existing trace
            $trace = $this->sdk->getTrace($traceId);
        } else {
            // Start new trace
            $trace = $this->sdk->startTrace(
                traceId: $traceId,
                traceType: 'http_request',
                title: "{$request->method()} {$request->path()}",
                metadata: [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );
        }

        // Store trace ID in request
        $request->attributes->set('trace_id', $traceId);
        $request->attributes->set('trace', $trace);

        // Execute request
        try {
            $response = $next($request);

            // Success
            $trace->finish([
                'status_code' => $response->getStatusCode(),
            ]);

            // Add trace ID to response headers
            $response->headers->set($headerName, $traceId);

            return $response;
        } catch (\Throwable $e) {
            // Failure
            $trace->fail($e);
            throw $e;
        }
    }
}
