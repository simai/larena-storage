@extends('larena-admin::layouts.app')

@section('title', __('larena-storage::admin.section.jobs.title') . ' — ' . ($selected['label'] ?? $structureId))
@section('eyebrow', $structureId)
@section('heading', __('larena-storage::admin.section.jobs.title') . ' — ' . ($selected['label'] ?? $structureId))
@section('description', $selected['label'] ?? $structureId)

@section('content')
<div class="larena-workbench larena-storage larena-storage-jobs-page" data-larena-storage-section="jobs">
    {{-- The alerts, the export by the queue and the jobs panel, which reads itself again while a job runs (AdminLiveRegion):
         described by the controller (StorageJobsPanel) and drawn by the shared renderer. --}}
    {{ $content }}

    <p><a href="{{ $recordsPath }}/jobs">{{ __('larena-storage::admin.section.jobs.refresh') }}</a> · <a href="{{ $recordsPath }}">{{ __('larena-storage::admin.section.import.back') }}</a></p>
</div>
@endsection
