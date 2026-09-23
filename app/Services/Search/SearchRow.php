<?php

namespace App\Services\Search;

use App\Models\StatusPage;

/**
 * One row of the command palette, as plain data for JSON. The browser builds
 * the row from these fields with textContent only, so nothing here is HTML.
 */
class SearchRow
{
    /** Type => [group heading, icon]. The order here is the order of the groups. */
    public const TYPES = [
        'Status page' => ['Pages', 'pages'],
        'Component' => ['Components', 'components'],
        'Service' => ['Services', 'services'],
        'Incident' => ['Incidents', 'incidents'],
        'Maintenance' => ['Maintenance', 'maintenance'],
        'User' => ['Users', 'users'],
        'Action' => ['Jump to', 'action'],
        'Screen' => ['Screens', 'screen'],
    ];

    /** Tones the palette knows; anything else is shown as neutral. */
    public const TONES = ['ok', 'w', 'p', 'b', 'm', 'off'];

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function make(string $type, string $label, string $url, array $extra = []): array
    {
        [$group, $icon] = self::TYPES[$type];

        return array_merge([
            'type' => $type,
            'group' => $group,
            'icon' => $icon,
            'label' => $label,
            'context' => '',
            'url' => $url,
            'page' => null,
            'status' => null,
            'meta' => null,
            'hint' => 'Open',
            'current' => false,
        ], $extra);
    }

    /** @return array{label: string, tone: string} */
    public static function status(string $label, string $tone): array
    {
        return ['label' => $label, 'tone' => in_array($tone, self::TONES, true) ? $tone : 'off'];
    }

    /** @return array{id: int, name: string, tag: string, color: string} */
    public static function page(StatusPage $page): array
    {
        return [
            'id' => (int) $page->getKey(),
            'name' => (string) $page->name,
            'tag' => $page->tagLabel(),
            'color' => array_key_exists($page->tagColor(), StatusPage::TAG_COLORS) ? $page->tagColor() : 'slate',
        ];
    }

    /**
     * Rows in the order of TYPES, at most $perGroup of each, keeping the order
     * they arrived in within a group (the current page's rows come first).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function grouped(array $rows, int $perGroup): array
    {
        $out = [];
        foreach (array_keys(self::TYPES) as $type) {
            $ofType = array_values(array_filter($rows, fn (array $row) => $row['type'] === $type));
            array_push($out, ...array_slice($ofType, 0, $perGroup));
        }

        return $out;
    }
}
