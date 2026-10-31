@extends('larena-admin::layouts.app')

@if (($recordPage ?? false) && is_string($recordTitle ?? null))
@section('title', $recordTitle . ' — ' . ($selected['label'] ?? $structureId))
@section('eyebrow', $structureId)
@section('heading', $recordTitle)
@section('description', $selected['label'] ?? $structureId)
@else
@section('title', ($selected['label'] ?? $structureId) . ' — ' . __('larena-storage::admin.section.records'))
@section('eyebrow', $structureId)
{{-- The archive names itself in the heading: the shell shows no description line. --}}
@section('heading', ($archiveView ? __('larena-storage::admin.section.archive_view') . ': ' : '') . ($selected['label'] ?? $structureId))
@section('description', __('larena-storage::admin.section.' . ($archiveView ? 'archive_view' : 'records')))
@endif
@php($structureArchived = is_array($selected) && ($selected['archived'] ?? false) === true)
@php($recordsUrl = route('larena.admin.storage.records', ['structure' => $structureId], false))
@if (is_array($dataview) && !($recordPage ?? false))
@section('heading-meta'){{ $dataview['heading']->meta }}@endsection
@endif
@section('actions')
    {{-- The count, the table view and the header actions come from the shared data view presenter. --}}
    @if ($recordPage ?? false)
    {{ $recordPageActions }}
    @else
    {{ is_array($dataview) ? $dataview['heading']->actions : $headerActions }}
    @endif
@endsection

@section('content')
@if (($recordPage ?? false) && $status === 'ok' && is_array($recordPanel))
{{-- One record on its own page: the same record panel, in the work area. --}}
<div class="larena-record-page" data-larena-record-page data-larena-storage-section="record">{{ $recordPanel['markup'] }}</div>
@else
<div class="larena-workbench larena-storage" data-larena-storage-section="records" data-status="{{ $status }}">
    @include('larena-storage::admin._state')
    @if ($status === 'ok' && is_array($selected))
        @if ($structureArchived)
            <div role="status" data-larena-storage-archived>{{ $archivedNotice }}</div>
        @elseif (($permissions['record.create'] ?? false) !== true && ($permissions['record.update'] ?? false) !== true)
            <p class="larena-muted" data-larena-storage-read-only>{{ __('larena-storage::admin.section.read_only_notice') }}</p>
        @endif
        @include('larena-storage::admin._field_change')

        @if (is_array($listNotice ?? null))
            {{-- A large storage's list is still being built in the background: no error, a word on when to come back. --}}
            <div role="status" data-larena-storage-list-state="{{ $listNotice['reason'] }}">{{ $listNotice['alert'] }}</div>
        @elseif ($archiveView)
            @foreach ($archivedRecords as $record)<span hidden data-larena-storage-archived-record="{{ $record['record_id'] ?? '' }}"></span>@endforeach
            @if ($archivedRecords === [])
                <section class="larena-panel" aria-labelledby="storage-archive-title" data-larena-storage-archive>
                    <h2 id="storage-archive-title">{{ __('larena-storage::admin.section.archive_view') }}</h2>
                    <p class="larena-muted">{{ __('larena-storage::admin.section.no_archived_records') }}</p>
                </section>
            @elseif (is_array($dataview))
                {{-- Archived records: open one to restore it or delete it permanently in the record panel. --}}
                <div data-larena-storage-archive>
                    @include('larena-admin::dataview.page', ['dataview' => $dataview, 'owner' => 'larena/storage',
                        'ariaLabel' => __('larena-storage::admin.section.archive_view'), 'queryUrl' => $recordsUrl, 'matchedCount' => count($archivedRecords),
                        'source' => 'storage_section_projection', 'hidden' => ['view' => 'archive']])
                </div>
            @endif
        @elseif (is_array($dataview))
            @if (is_array($countNotice ?? null))
                {{-- A large list is counted approximately; the link counts it exactly. --}}
                <p class="larena-muted" data-larena-storage-count-approximate>{{ $countNotice['text'] }} <a href="{{ $countNotice['href'] }}" data-larena-storage-count-exact>{{ $countNotice['link'] }}</a></p>
            @endif
            {{-- A large list sorted by a field without an index: a hint, never a refusal. The list's port answer refreshes it in place. --}}
            <p class="larena-muted" role="status" data-larena-storage-sort-hint @if (!is_array($sortHint ?? null)) hidden @endif>@if (is_array($sortHint ?? null)){{ $sortHint['text'] }}@if (is_array($sortHint['link'] ?? null)) <a href="{{ $sortHint['link']['href'] }}" data-larena-storage-sort-hint-link>{{ $sortHint['link']['text'] }}</a>@endif @endif</p>
            {{-- The records list: the same composite and element structure as the Larena CMS list. --}}
            @include('larena-admin::dataview.page', ['dataview' => $dataview, 'owner' => 'larena/storage',
                'ariaLabel' => __('larena-storage::admin.section.records'), 'queryUrl' => $recordsUrl, 'portUrl' => $portUrl,
                'filterFields' => $dataViewFilterFields, 'capabilities' => $portCapabilities ?? ['row-actions'], 'openViewInQuery' => true,
                'confirmBulk' => __('larena-storage::admin.section.confirm_bulk'),
                'confirmBulkAll' => __('larena-storage::admin.section.confirm_bulk_all'), 'bulkAll' => $bulkAll ?? false,
                'matchedCount' => $matchedCount, 'source' => 'storage_section_projection', 'addressQuery' => $addressQuery ?? [],
                'hidden' => ['scope_ref' => $scopeRef, 'structure_id' => $structureId]])
        @endif

        {{-- Viewing, editing and creating a record: the shared record panel over the list. --}}
        @if (is_array($recordPanel))
            {{ $recordPanel['markup'] }}
        @endif
    @endif
</div>
@endif
@endsection
