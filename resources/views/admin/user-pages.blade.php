@extends('layouts.admin')
@section('title', 'Page access')
@section('content')
@include('partials.pagehead', [
  'title' => 'Page access for '.$member->name,
  'sub' => $member->email.' · User',
  'back' => ['url' => route('admin.users'), 'label' => 'Users'],
])
<div class="panel"><div class="panel-bd">
  <form method="POST" action="{{ route('admin.users.pages.update', $member) }}" style="display:grid;gap:20px">
    @csrf @method('PUT')
    @include('admin.partials.user-page-assignments')
    <p class="help">Changes take effect immediately, including access through existing API tokens. Installation settings and account management remain restricted to installation administrators.</p>
    <div class="actions">
      <button class="btn" type="submit">Save page access</button>
      <a class="btn ghost" href="{{ route('admin.users') }}">Cancel</a>
    </div>
  </form>
</div></div>
@endsection
