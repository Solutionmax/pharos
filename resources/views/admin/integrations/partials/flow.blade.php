{{-- The three places a status travels through, with this screen's place lit. --}}
@php
  $flowPage = $contextPage ?? app(\App\Services\PageContext::class)->page();
  $flowNodes = [
      'in' => ['route' => 'admin.integrations.in', 'title' => 'Your monitoring', 'sub' => 'Checks, n8n, scripts, jobs',
          'icon' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>'],
      'core' => ['route' => 'admin.integrations.tokens', 'title' => 'Pharos · '.$flowPage->name, 'sub' => 'Status, incidents, API tokens',
          'icon' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>'],
      'out' => ['route' => 'admin.integrations.out', 'title' => 'Your team', 'sub' => 'Slack, Teams, workflows',
          'icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
  ];
@endphp
<nav class="ix-flow" aria-label="How status travels">
  @foreach ($flowNodes as $key => $node)
    @if (! $loop->first)<span class="ix-arrow" aria-hidden="true">→</span>@endif
    <a class="ix-node {{ $key === 'core' ? 'core' : '' }} {{ $active === $key ? 'on' : '' }}" href="{{ \App\Services\PageUrls::route($node['route']) }}" @if ($active === $key) aria-current="page" @endif>
      <span class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $node['icon'] !!}</svg></span>
      <span><b>{{ $node['title'] }}</b><span>{{ $node['sub'] }}</span></span>
    </a>
  @endforeach
</nav>
