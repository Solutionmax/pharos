<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Laravel's translator, scoped so a page or mail cannot change the next request. */
class Localization
{
    public const LANGUAGES = ['en' => 'English', 'nl' => 'Nederlands', 'de' => 'Deutsch', 'es' => 'Español'];

    public static function valid(mixed $locale): string
    {
        return is_string($locale) && isset(self::LANGUAGES[$locale]) ? $locale : 'en';
    }

    public static function page(): string
    {
        return self::valid(Setting::get('page.locale', 'en'));
    }

    public static function plural(string $word, int $count): string
    {
        return trans_choice($word.'|'.Str::plural($word), $count);
    }

    public static function run(mixed $locale, callable $callback): mixed
    {
        $previous = app()->getLocale();
        $carbon = Carbon::getLocale();
        $immutable = CarbonImmutable::getLocale();
        $selected = self::valid($locale);
        app()->setLocale($selected);
        Carbon::setLocale($selected);
        CarbonImmutable::setLocale($selected);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
            Carbon::setLocale($carbon);
            CarbonImmutable::setLocale($immutable);
        }
    }
}
