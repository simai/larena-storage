{{-- A field change in the background (with its progress), or the last one that failed or was cancelled; described by the
     controller and drawn by the shared renderer in a holder that reads itself again while the change runs (AdminLiveRegion). --}}
{{ $fieldChangeRegion ?? '' }}
