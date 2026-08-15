@include('errors.layout', [
    'code' => '404',
    'headline' => __('Nothing at this address.'),
    'body' => __('The page may have been renamed or removed.'),
])
