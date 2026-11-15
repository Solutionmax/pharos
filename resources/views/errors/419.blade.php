{{-- A form sent after its session ran out. The admin sends people to sign in again (bootstrap/app.php); this is the rest. --}}
@include('errors.shell', [
  'code' => 419,
  'title' => __('Page expired'),
  'heading' => __('This form has expired'),
  'lede' => __('It was open for too long, so nothing was sent. Reload the page and try again.'),
  'caption' => [__('Nothing was saved.'), __('Try it once more.')],
])
