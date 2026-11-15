{{-- A throttle said no: too many requests from one address in a short time. --}}
@include('errors.shell', [
  'code' => 429,
  'title' => __('Too many requests'),
  'heading' => __('Slow down for a moment'),
  'lede' => __('Too many requests came from your address in a short time. Wait a minute, then try again.'),
  'caption' => [__('Still here.'), __('Just catching our breath.')],
  'retry' => true,
])
