<?php

namespace App\Http\Middleware;

use App\Services\PosSystemUserService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePosSystemUserAuthenticated
{
    public function __construct(
        private readonly PosSystemUserService $posSystemUserService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->posSystemUserService->ensureAuthenticated();

        return $next($request);
    }
}
