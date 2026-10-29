@extends('larena-admin::layouts.app')

@section('title', __('larena-storage::admin.section.settings') . ' — ' . ($selected['label'] ?? $structureId))
@section('eyebrow', $structureId)
@section('heading', $selected['label'] ?? $structureId)
@section('description', __('larena-storage::admin.section.settings'))
@isset($schemaPage)
@section('heading-meta'){{ $schemaPage['dataview']['heading']->meta }}@endsection
@endisset
@section('actions')
    {{-- The count, the table view and the header actions come from the shared data view presenter. --}}
    @isset($schemaPage){{ $schemaPage['dataview']['heading']->actions }}@endisset
@endsection

@section('content')
<div class="larena-workbench larena-storage" data-larena-storage-section="settings" data-status="{{ $status }}">
    @include('larena-storage::admin._state')
    @if ($status === 'ok' && is_array($selected))
        @if ($archivedNotice !== null)
            <div role="status" data-larena-storage-archived>{{ $archivedNotice }}</div>
        @endif
        @include('larena-storage::admin._field_change')
        @if (is_string($structureLocked ?? null))
            <p class="larena-muted" data-larena-storage-structure-locked>{{ $structureLocked }}</p>
        @endif
        @if (is_string($backfillNote ?? null))
            <p class="larena-muted" data-larena-storage-view-backfill>{{ $backfillNote }}</p>
        @endif

        @isset($schemaPage)
            {{-- The fields as a list (owner decision 2026-10-16): checkable cells, drag order and the row menu, over the schema port. --}}
            @include('larena-admin::dataview.page', ['dataview' => $schemaPage['dataview'], 'sectionId' => 'storage-schema', 'owner' => 'larena/storage',
                'ariaLabel' => $schemaPage['aria_label'], 'queryUrl' => $schemaPage['query_url'], 'matchedCount' => $schemaPage['count'], 'source' => 'storage_schema_port'])
        @endisset

        {{-- A field, a new field or the storage itself opens here, in the record panel (owner decision 2026-10-16). --}}
        {{ $schemaPage['panel'] ?? '' }}

        {{-- «Перенос на другой сайт»: described for a person with the right to export (StorageSitePackPanel), drawn by the shared renderer. --}}
        {{ $sitePackPanel ?? '' }}

        {{-- «Шифрование»: described for a person with the right to manage encryption (StorageEncryptionPanel), drawn by the shared renderer. --}}
        {{ $encryptionPanel ?? '' }}

        {{-- «Архивировать», «Восстановить», «Удалить навсегда»: described by the controller for a person with the right. --}}
        {{ $structureActions }}

    @endif
</div>
@endsection
