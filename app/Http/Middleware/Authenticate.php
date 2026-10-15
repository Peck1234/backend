<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // API only: there is no login page to send anyone to, so a missing or
        // revoked token is always a plain 401 (see Exceptions\Handler) - even
        // for the Excel download, which asks for a file rather than JSON.
        return null;
    }
}
