@extends('layouts.auth')
@section('title', 'Two factor code')
@section('card')
  <p class="pa-kicker"><i aria-hidden="true"></i>Password accepted</p>
  <h1 class="pa-h1">One more step</h1>
  <p class="pa-lede">Enter the six digit code from your authenticator app.</p>

  @include('partials.auth.errors')

  {{--
    One real field, `code`, takes either a six digit code or a recovery code.
    Without JavaScript that is all there is. With it, the six boxes fill that
    field for you, and "Use a recovery code" brings the single field back.
  --}}
  <form class="pa-form" method="POST" action="{{ route('admin.two-factor.verify') }}"
        data-pa-two-factor data-pa-fail="{{ route('admin.two-factor') }}" data-pa-back="{{ route('admin.login') }}">
    @csrf
    <div class="pa-codes-wrap" data-pa-codes hidden>
      <p class="pa-codes-label" id="pa-codes-label">Six digit code</p>
      <div class="pa-codes" role="group" aria-labelledby="pa-codes-label">
        @for ($i = 1; $i <= 6; $i++)
          <input type="text" inputmode="numeric" pattern="[0-9]*" aria-label="Digit {{ $i }} of 6"
                 @if ($i === 1) autocomplete="one-time-code" @else autocomplete="off" @endif data-pa-digit>
        @endfor
      </div>
    </div>
    <div class="pa-fl" data-pa-code-field>
      <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder=" " required autofocus
             aria-describedby="code-help" spellcheck="false">
      <label for="code" data-pa-code-label>Code</label>
      <span class="pa-help" id="code-help">Lost your phone? A recovery code works here too, once each.</span>
    </div>
    @include('partials.auth.submit', ['label' => 'Verify and continue'])
    <button type="button" class="pa-link" data-pa-mode hidden>Use a recovery code</button>
  </form>

  <p class="pa-back"><a href="{{ route('admin.login') }}">Back to sign in</a></p>
@endsection
