@extends('larena-admin::layouts.app')

@section('title', $heading . ' — ' . $structureLabel)
@section('eyebrow', $structureId)
@section('heading', $heading)
@section('description', $structureLabel)

@section('content')
{{-- The page editor of a record's page document: one screen per page. The Framework's sf-editor, described by the
     controller and drawn by the shared renderer; form-state.js hands it the document port on its data-* addresses. --}}
<div class="larena-storage larena-storage-document-editor" data-larena-storage-section="document-editor">
    {{ $content }}
    <p><a href="{{ $recordPath }}">{{ __('larena-storage::admin.section.document_editor.back') }}</a></p>
</div>
@endsection
