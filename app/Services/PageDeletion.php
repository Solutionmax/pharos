<?php

namespace App\Services;

use App\Models\StatusPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Removes a status page for good, with everything it owns. Archiving is the
 * way to keep a page's history; this is for the page nobody wants back.
 *
 * Rows go by table rather than through their models on purpose: a model's
 * deleted event writes an audit line and can notify, and a page that is being
 * removed should say so once and tell nobody outside.
 */
class PageDeletion
{
    /**
     * Tables that hold a page's rows and refuse to lose their page. Everything
     * hanging off these rows (updates, uptime days, deliveries) cascades.
     */
    private const OWNED = [
        'subscriber_notifications',
        'maintenances',
        'incidents',
        'incident_templates',
        'components',
        'component_groups',
        'subscribers',
        'webhook_endpoints',
        'api_tokens',
    ];

    /** What the confirmation names, and where each number is counted. */
    private const COUNTED = ['services' => 'components', 'incidents' => 'incidents', 'subscribers' => 'subscribers'];

    public function delete(StatusPage $page): void
    {
        $id = (int) $page->getKey();
        $settingKeys = DB::table('status_page_settings')->where('status_page_id', $id)->pluck('key');

        DB::transaction(function () use ($page, $id): void {
            // Written first: once the page is gone the line keeps its name and loses its page.
            Audit::record('page.deleted', $page, $this->countsByPage()[$id] ?? []);

            foreach (self::OWNED as $table) {
                DB::table($table)->where('status_page_id', $id)->delete();
            }

            $page->delete();
        });

        foreach ($settingKeys as $key) {
            Cache::forget("status_page.$id.setting.$key");
        }
        Storage::disk('public')->deleteDirectory('brand/pages/'.$id);
    }

    /**
     * What each page would take with it, for every page in three queries.
     *
     * @return array<int, array<string, int>>
     */
    public function countsByPage(): array
    {
        $counts = [];

        foreach (self::COUNTED as $label => $table) {
            $totals = DB::table($table)->groupBy('status_page_id')
                ->selectRaw('status_page_id, count(*) as total')->pluck('total', 'status_page_id');

            foreach ($totals as $pageId => $total) {
                $counts[(int) $pageId][$label] = (int) $total;
            }
        }

        return array_map(fn (array $own) => array_merge(array_fill_keys(array_keys(self::COUNTED), 0), $own), $counts);
    }

    /**
     * "4 services, 12 incidents and 48 subscribers": only what there is.
     *
     * @param  array<string, int>  $counts
     */
    public static function summary(array $counts): string
    {
        $parts = [];

        foreach (array_keys(self::COUNTED) as $label) {
            $total = (int) ($counts[$label] ?? 0);

            if ($total > 0) {
                $parts[] = $total.' '.Str::plural(Str::singular($label), $total);
            }
        }

        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' and '.$last;
    }
}
