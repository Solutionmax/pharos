{{-- A form or a script sent the wrong kind of request to a real address (a POST to the status page). --}}
@include('errors.shell', [
  'code' => 405,
  'title' => __('Method not allowed'),
  'heading' => __('This address does not work that way'),
  'lede' => __('The page is there, but not for this kind of request. Open it as a normal link instead.'),
  'caption' => [__('The page answered.'), __('Just not to that.')],
])
