{{-- Most often the login throttle, which is doing its job. --}}
@include('errors.layout', [
    'code' => '429',
    'headline' => __('Too many tries.'),
    'body' => __('Wait a minute, then try again.'),
])
