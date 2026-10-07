@extends('layouts.public-document')
@section('title', __('Monthly uptime report'))
@push('head')
<link rel="stylesheet" href="{{ asset('assets/pharos-reports.css') }}?v={{ filemtime(public_path('assets/pharos-reports.css')) }}">
@endpush
@section('content')
@include('partials.monthly-report')
@endsection
