@extends('layouts.admin')
@section('title', __('Monthly uptime report'))
@push('head')
<link rel="stylesheet" href="{{ asset('assets/pharos-reports.css') }}?v={{ filemtime(public_path('assets/pharos-reports.css')) }}">
@endpush
@section('content')
@include('partials.pagehead', ['crumbs' => ['Reports'], 'title' => __('Monthly uptime report'), 'sub' => app(\App\Services\PageContext::class)->page()->name])
@include('partials.monthly-report')
@include('admin.integrations.partials.public-sharing')
@endsection
