<?php

namespace App\Http\Controllers\PublicFeatures;

use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Services\PageContext;
use App\Services\PageUrls;
use App\Services\PublicComponents;

class PublicationController extends Controller
{
    public function badge(string $kind, string $id)
    {
        if ($kind === 'components') {
            $entity = PublicComponents::query()->findOrFail($id);
            $status = $entity->status;
        } else {
            $entity = ComponentGroup::where('visible', true)->with(['components' => fn ($q) => $q->where('enabled', true)])->findOrFail($id);
            $status = $entity->status();
        }
        $label = $this->xmlText(mb_substr($entity->name, 0, 60));
        $state = __($status->label());
        $left = min(480, max(70, mb_strlen($label) * 8 + 20));
        $right = min(300, max(95, mb_strlen($state) * 8 + 20));
        $color = match ($status) {
            ComponentStatus::Operational => '#237a47',
            ComponentStatus::PerformanceIssues => '#946500',
            ComponentStatus::UnderMaintenance => '#4466aa',
            default => '#b52d39',
        };
        $escape = fn ($text) => htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        $width = $left + $right;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="28" role="img" aria-label="'.$escape($label.': '.$state).'">'
            .'<title>'.$escape($label.': '.$state).'</title><a href="'.$escape(PageUrls::route('status')).'" target="_blank">'
            .'<rect width="'.$width.'" height="28" rx="4" fill="#334155"/><rect x="'.$left.'" width="'.$right.'" height="28" fill="'.$color.'"/>'
            .'<g fill="#fff" font-family="Verdana,sans-serif" font-size="12" text-anchor="middle"><text x="'.($left / 2).'" y="19">'.$escape($label).'</text>'
            .'<text x="'.($left + $right / 2).'" y="19">'.$escape($state).'</text></g></a></svg>';

        return response($svg)->header('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=30')->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox allow-popups");
    }

    public function incident(Incident $incident)
    {
        abort_unless($incident->visibility === 'public', 404);
        $incident->load(['updates', 'components' => fn ($q) => $q->whereIn('components.id', PublicComponents::query()->select('id'))]);

        return view('public.incident', compact('incident'));
    }

    public function feed()
    {
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->startElement('channel');
        $xml->writeElement('title', $this->xmlText(app(PageContext::class)->page()->name.' '.__('Status updates')));
        $xml->writeElement('link', PageUrls::route('status'));
        $xml->writeElement('description', __('Incidents and scheduled maintenance'));
        $incidents = Incident::public()->with(['updates' => fn ($q) => $q->limit(500)])->latest('occurred_at')->limit(50)->get();
        foreach ($incidents as $incident) {
            $this->feedItem($xml, $incident->name, $incident->updates->first()->message ?? '', PageUrls::route('public.incident', $incident), $incident->updated_at ?? $incident->occurred_at);
        }
        foreach (Maintenance::whereNull('cancelled_at')->latest('starts_at')->limit(50)->get() as $maintenance) {
            $this->feedItem($xml, $maintenance->title, $maintenance->message ?? '', PageUrls::route('status').'#maintenance-'.$maintenance->id, $maintenance->updated_at);
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory())->header('Content-Type', 'application/rss+xml; charset=UTF-8')->header('Cache-Control', 'public, max-age=60');
    }

    private function feedItem(\XMLWriter $xml, string $title, string $message, string $url, $date): void
    {
        $xml->startElement('item');
        $xml->writeElement('title', $this->xmlText($title));
        $xml->writeElement('link', $url);
        $xml->writeElement('guid', $url);
        $xml->writeElement('description', $this->xmlText(mb_substr($message, 0, 20000)));
        $xml->writeElement('pubDate', $date->toRfc2822String());
        $xml->endElement();
    }

    private function xmlText(string $text): string
    {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
    }

    public function widgetData()
    {
        $worst = ComponentStatus::from((int) (PublicComponents::query()->max('status') ?? 1));

        return response()->json(['status' => $worst->value, 'label' => __($worst->label()), 'name' => app(PageContext::class)->page()->name, 'url' => PageUrls::route('status')])
            ->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'public, max-age=30');
    }

    public function widgetScript()
    {
        return response(view('public.embed', ['dataUrl' => PageUrls::route('public.widget'), 'statusUrl' => PageUrls::route('status')])->render())
            ->header('Content-Type', 'application/javascript; charset=UTF-8')->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'public, max-age=60');
    }
}
