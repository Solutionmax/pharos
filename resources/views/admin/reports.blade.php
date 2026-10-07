@extends('layouts.admin')
@section('title', __('Monthly uptime report'))
@section('content')
<section class="op-card">@include('partials.monthly-report')</section>
@include('admin.integrations.partials.public-sharing')
@endsection
