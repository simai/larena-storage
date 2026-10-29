@extends('larena-admin::layouts.app')

@section('title', __('larena-storage::admin.section.title'))
@section('eyebrow', __('larena-storage::admin.section.eyebrow'))
@section('heading', __('larena-storage::admin.section.heading'))
@section('description', __('larena-storage::admin.section.description'))
@if (is_array($dataview))
@section('heading-meta'){{ $dataview['heading']->meta }}@endsection
@section('actions')
    {{-- The count, the table view and the header actions come from the shared data view presenter. --}}
    {{ $dataview['heading']->actions }}
@endsection
@endif

@section('content')
<div class="larena-workbench larena-storage" data-larena-storage-section="index" data-status="{{ $status }}">
    @include('larena-storage::admin._state')
    @if ($status === 'ok')
        <div data-larena-storage-list>
            @foreach ($structures as $structure)<span hidden data-larena-storage-row="{{ $structure['structure_id'] }}"></span>@endforeach
            @if (is_array($dataview))
                @include('larena-admin::dataview.page', ['dataview' => $dataview, 'owner' => 'larena/storage',
                    'ariaLabel' => __('larena-storage::admin.section.storages'), 'queryUrl' => route('larena.admin.storage.index', [], false),
                    'matchedCount' => count($structures), 'source' => 'storage_section_projection',
                    'hidden' => $showArchived ? ['archived' => '1'] : []])
            @endif
        </div>

        {{-- Creating a storage: the shared record panel over the list, opened by the toolbar create button. --}}
        @if (is_array($createPanel))
            <div data-larena-storage-create>{{ $createPanel['markup'] }}</div>
        @endif
    @endif
</div>
@endsection
