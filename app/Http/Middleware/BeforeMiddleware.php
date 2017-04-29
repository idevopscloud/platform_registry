<?php

namespace App\Http\Middleware;

use Closure;

class BeforeMiddleware
{
    public function handle($request, Closure $next)
    {
        // Perform action
    	header('Access-Control-Allow-Origin: *');
    	header('Access-Control-Allow-Credentials: true');
    	header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
    	header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Request-With');
        return $next($request);
    }
}