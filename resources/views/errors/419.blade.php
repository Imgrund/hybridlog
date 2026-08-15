{{-- Laravel's word for this is "page expired", which reads as a fault.
     It is the ordinary fate of a form left open, so the page says what
     to do and that nothing was lost. --}}
@include('errors.layout', [
    'code' => '419',
    'headline' => __('This page sat open too long.'),
    'body' => __('Load it again and repeat what you were doing. Nothing was saved.'),
])
