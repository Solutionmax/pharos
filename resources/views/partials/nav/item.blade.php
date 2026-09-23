{{-- One sidebar entry from App\Support\AdminMenu: a link, or a group that
     expands to its children. Only the group holding the current screen starts
     open; the script in pharos-ui.js opens a closed group and goes to its first
     child. --}}
@if ($item['children'])
  <div class="navgroup{{ $item['active'] ? ' open has-current' : '' }}" data-navgroup>
    <button type="button" class="nav nav-parent" aria-expanded="{{ $item['active'] ? 'true' : 'false' }}"
            aria-controls="navkids-{{ $item['key'] }}" data-first="{{ $item['url'] }}">
      @include('partials.icon', ['name' => $item['icon']]) {{ $item['label'] }}
      <svg class="chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
    </button>
    <div class="navkids" id="navkids-{{ $item['key'] }}" @unless ($item['active']) hidden @endunless>
      @foreach ($item['children'] as $child)
        <a class="nav nav-sub" href="{{ $child['url'] }}" @if ($child['active']) aria-current="page" @endif>{{ $child['label'] }}</a>
      @endforeach
    </div>
  </div>
@else
  <a class="nav" href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif>
    @include('partials.icon', ['name' => $item['icon'] ?? 'pages']) {{ $item['label'] }}
    @if ($item['hint'])<span class="navhint">{{ $item['hint'] }}</span>@endif
    @if ($item['dot'])<span class="dot-new" role="img" aria-label="{{ $item['dot'] }}"></span>@endif
  </a>
@endif
