{{-- Something broke while building the answer. errors/shell reads the database carefully, so this still renders when that is what broke. --}}
@include('errors.shell', [
  'code' => 500,
  'title' => __('Server error'),
  'heading' => __('Something went wrong on our side'),
  'lede' => __('The page could not be built just now. Try again in a moment.'),
  'caption' => [__('A check failed.'), __('Ours, not yours.')],
  'retry' => true,
])
