<?php

namespace App\Http\Middleware;

use Closure;

class ResponseFilter
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
    	$response = $next($request);
    	if ($response) {
	    	$content = json_decode($response->getContent(), true);
	    	if (!isset($content['flag'])) {
	    		$response->setContent(json_encode(['data'=>$content, 'code'=>0, 'msg'=>'','flag'=>'success']));
	    	}
	        return $response;
    	}
    }
}
