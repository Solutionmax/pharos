<?php

namespace App\Support;

final class Csv
{
    /**
     * A cell a spreadsheet will show, not run. "=1+1@example.net" is a valid
     * address and, opened in Excel, a formula; a leading apostrophe makes it text.
     */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
