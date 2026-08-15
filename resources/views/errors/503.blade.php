{{-- What `artisan down` serves, so it promises a return rather than
     explaining a failure. --}}
@include('errors.layout', [
    'code' => '503',
    'headline' => __('Back shortly.'),
    'body' => __('This installation is being updated.'),
])
