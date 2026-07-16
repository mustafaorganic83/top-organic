<?php

namespace Tests\Feature\Foundation;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Localization foundation: Arabic is primary, English is the fallback, and the
 * locale is resolved from request headers against the region pack (docs 04/07).
 */
class LocalizationTest extends TestCase
{
    public function test_default_locale_is_arabic(): void
    {
        $this->assertSame('ar', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
    }

    public function test_region_pack_defaults_are_iraq_first(): void
    {
        $this->assertSame('IQ', config('region.region'));
        $this->assertSame('IQD', config('region.currency.primary'));
        $this->assertSame('Asia/Baghdad', config('region.timezone'));
        $this->assertContains('ar', config('region.rtl_locales'));
    }

    public function test_timestamps_are_stored_in_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_arabic_and_english_message_keys_are_mirrored(): void
    {
        $this->assertSame(
            array_keys(trans('messages', [], 'ar')),
            array_keys(trans('messages', [], 'en')),
        );
    }

    public function test_x_locale_header_switches_the_active_locale(): void
    {
        $middleware = new SetLocale;
        $request = Request::create('/');
        $request->headers->set('X-Locale', 'en');

        $middleware->handle($request, fn () => response(''));

        $this->assertSame('en', App::getLocale());
    }

    public function test_unsupported_locale_falls_back_to_default(): void
    {
        App::setLocale('ar');
        $middleware = new SetLocale;
        $request = Request::create('/');
        $request->headers->set('X-Locale', 'fr');

        $middleware->handle($request, fn () => response(''));

        $this->assertSame('ar', App::getLocale());
    }
}
