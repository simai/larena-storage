{{-- Success and error notices come from the admin shell; only an unavailable state is drawn here. A page may name
     the state in its own words (stateNotice: its reason and its alert); StorageStateViewComposer draws the general one. --}}
@if ($status !== 'ok' && is_array($stateNotice ?? null))
    <div role="alert" data-larena-storage-state="{{ $status }}" data-larena-storage-state-reason="{{ $stateNotice['reason'] }}">{{ $stateNotice['alert'] }}</div>
@elseif ($status !== 'ok')
    <div role="alert" data-larena-storage-state="{{ $status }}">{{ $stateAlert }}</div>
@endif
