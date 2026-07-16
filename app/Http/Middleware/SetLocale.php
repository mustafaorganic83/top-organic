<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active UI locale for the request. Arabic is the primary
 * experience; English is the fallback. Locale is taken from the `X-Locale`
 * header or the standard `Accept-Language` header, validated against the
 * region pack's supported locales, and defaults to the app locale.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('region.supported_locales', ['ar', 'en']);

        $requested = $request->header('X-Locale')
            ?? $request->getPreferredLanguage($supported);

        // Normalise "ar-IQ" / "en_US" style tags down to the base language.
        $normalised = $requested ? strtolower(substr($requested, 0, 2)) : null;

        if ($normalised && in_array($normalised, $supported, true)) {
            App::setLocale($normalised);
        }

        return $next($request);
    }
}
