{{-- One row of days on the shared axis. Height grows with the time lost that day, colour with how bad it was. --}}
<div class="track" aria-hidden="true">@foreach ($bar as $i => $d)@php $h = \App\Services\StatusTimeline::height($d); @endphp<i class="{{ ! $d['known'] ? 'none' : ($d['tone'] === 'ok' ? '' : $d['tone']) }}" style="--d:{{ $i }}{{ $h ? ';--h:'.$h.'%' : '' }}"></i>@endforeach</div>
