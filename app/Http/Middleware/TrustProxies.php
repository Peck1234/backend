<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Loopback only: on the university server the backend listens on
     * 127.0.0.1 and every request arrives through the ngrok agent running on
     * the same machine. Trusting it lets Laravel see the original https
     * scheme, so generated links (profile photo URLs) are https - Android
     * refuses to load plain-http images. Nothing outside the machine can
     * reach the backend directly, so these forwarded headers can't be faked.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = ['127.0.0.1', '::1'];

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
