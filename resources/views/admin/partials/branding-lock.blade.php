{{-- A Brand pack section on an installation without it: what it does and what
     unlocks it, instead of a greyed out form. Expects: $what, $saved (or null). --}}
<div class="bx-lock">
  <span class="bx-lock-ic" aria-hidden="true">@include('partials.icon', ['name' => 'key', 'size' => 18])</span>
  <div>
    <p class="bx-lock-what">{{ $what }}</p>
    @if ($saved)<p class="bx-lock-saved">{{ $saved }}</p>@endif
    <p class="bx-lock-how">Unlocked by the <b>Brand pack</b>, also included in Supported. <a href="#plan">See what this installation has</a></p>
  </div>
</div>
