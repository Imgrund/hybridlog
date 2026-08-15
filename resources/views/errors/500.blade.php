@include('errors.layout', [
    'code' => '500',
    'headline' => __('Something broke.'),
    'body' => __('The error is written to the log. Try again in a moment.'),
])
