{{-- Maintenance mode (php artisan down), or a service that is briefly unavailable. --}}
@include('errors.shell', [
  'code' => 503,
  'title' => __('Back in a moment'),
  'heading' => __('Back in a moment'),
  'lede' => __('This site is being updated. Try again in a few minutes.'),
  'caption' => [__('Planned work.'), __('Nothing is lost.')],
  'retry' => true,
])
