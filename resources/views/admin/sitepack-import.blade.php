@extends('larena-admin::layouts.app')

@section('title', $title)
@section('eyebrow', __('larena-storage::admin.section.eyebrow'))
@section('heading', $title)
@section('description', $description)

@section('content')
{{-- «Загрузить хранилище»: the upload, a run's plan, progress and end, described by StorageSitePackImportPage and drawn by the shared renderer. --}}
<div class="larena-workbench larena-storage larena-storage-import" data-larena-storage-section="sitepack-import">
    {{ $html }}
    <p><a href="{{ $back }}">{{ __('larena-storage::admin.section.sitepack_import.back') }}</a></p>
</div>
@endsection
