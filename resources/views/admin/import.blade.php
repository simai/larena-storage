@extends('larena-admin::layouts.app')

@section('title', __('larena-storage::admin.section.import.title') . ' — ' . ($selected['label'] ?? $structureId))
@section('eyebrow', $structureId)
@section('heading', __('larena-storage::admin.section.import.title'))
@section('description', $selected['label'] ?? $structureId)

@section('content')
{{-- The refusal of a write, the upload, a file the queue checks, the plan with «Применить» and the person's jobs:
     described by StorageImportPage and drawn by the shared renderer. --}}
<div class="larena-workbench larena-storage larena-storage-import" data-larena-storage-section="import">
    {{ $content }}
</div>
@endsection
