@extends('larena-admin::layouts.app')

@section('title', __('larena-storage::admin.workbench.title'))
@section('eyebrow', __('larena-storage::admin.workbench.eyebrow'))
@section('heading', __('larena-storage::admin.workbench.heading'))
@section('description', __('larena-storage::admin.workbench.description'))

@section('content')
<div class="larena-workbench" data-larena-storage-workbench data-status="{{ $status }}">
    @if ($status !== 'ok')
        <section class="larena-panel larena-workbench-state" role="alert" aria-labelledby="workbench-state-title">
            <h2 id="workbench-state-title">{{ __('larena-storage::admin.workbench.states.' . $status . '_title') }}</h2>
            <p>{{ __('larena-storage::admin.workbench.states.' . $status . '_text') }}</p>
        </section>
    @else
        <section class="larena-panel larena-workbench-scope" aria-labelledby="workbench-scope-title">
            <div>
                <p class="larena-eyebrow">{{ __('larena-storage::admin.workbench.scope') }}</p>
                <h2 id="workbench-scope-title">{{ $scopeRef }}</h2>
            </div>
            <form method="get" action="{{ $workbenchQueryAction }}" class="larena-form-compact">
                <label for="workbench-scope-ref">{{ __('larena-storage::admin.workbench.requested_scope') }}</label>
                <div class="larena-workbench-inline">
                    <input id="workbench-scope-ref" name="scope_ref" value="{{ $scopeRef }}" required maxlength="191" autocomplete="off">
                    <button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.open_scope') }}</button>
                </div>
            </form>
        </section>

        <div class="larena-workbench-grid">
            <aside class="larena-panel larena-workbench-structures" aria-labelledby="workbench-structures-title">
                <h2 id="workbench-structures-title">{{ __('larena-storage::admin.workbench.structures') }}</h2>
                @if ($structures === [])
                    <p class="larena-muted">{{ __('larena-storage::admin.workbench.no_structures') }}</p>
                @else
                    <nav aria-label="{{ __('larena-storage::admin.workbench.structures') }}">
                        <ul class="larena-workbench-list">
                            @foreach ($structures as $structure)
                                @if (is_array($structure) && is_string($structure['structure_id'] ?? null))
                                    <li><a href="{{ $workbenchQueryAction }}?scope_ref={{ urlencode($scopeRef) }}&amp;structure_id={{ urlencode($structure['structure_id']) }}" @if ($selectedId === $structure['structure_id']) aria-current="page" @endif>
                                        <strong>{{ $structure['label'] ?? $structure['structure_id'] }}</strong>
                                        <small>{{ $structure['structure_id'] }} · v{{ $structure['version'] ?? '?' }}</small>
                                    </a></li>
                                @endif
                            @endforeach
                        </ul>
                    </nav>
                @endif

                @if (($permissions['structure.create'] ?? false) === true)
                    <details class="larena-workbench-create">
                        <summary>{{ __('larena-storage::admin.workbench.create_structure') }}</summary>
                        <form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>
                            @csrf
                            <input type="hidden" name="action" value="structure.create">
                            <input type="hidden" name="scope_ref" value="{{ $scopeRef }}">
                            <label>{{ __('larena-storage::admin.workbench.structure_id') }}<input name="payload[structure_id]" required pattern="workbench\.[a-z][a-z0-9_.:-]{0,109}" placeholder="workbench.generic_model"></label>
                            <label>{{ __('larena-storage::admin.workbench.label') }}<input name="payload[label]" required maxlength="120"></label>
                            @for ($fieldIndex = 0; $fieldIndex < 3; $fieldIndex++)
                                <fieldset class="larena-workbench-fieldset">
                                    <legend>{{ __('larena-storage::admin.workbench.field') }} {{ $fieldIndex + 1 }}</legend>
                                    <label>{{ __('larena-storage::admin.workbench.field_key') }}<input name="payload[fields][{{ $fieldIndex }}][key]" @if ($fieldIndex === 0) required @endif pattern="[a-z][a-z0-9_]{0,63}"></label>
                                    <label>{{ __('larena-storage::admin.workbench.label') }}<input name="payload[fields][{{ $fieldIndex }}][label]" @if ($fieldIndex === 0) required @endif maxlength="120"></label>
                                    <label>{{ __('larena-storage::admin.workbench.type') }}<select name="payload[fields][{{ $fieldIndex }}][type]"><option>string</option><option>text</option><option>integer</option><option>number</option><option>boolean</option><option>date</option><option>file</option><option>relation</option></select></label>
                                    <label>{{ __('larena-storage::admin.workbench.position') }}<input type="number" min="0" max="10000" name="payload[fields][{{ $fieldIndex }}][position]" value="{{ ($fieldIndex + 1) * 10 }}"></label>
                                    <label><input type="checkbox" name="payload[fields][{{ $fieldIndex }}][required]" value="1"> {{ __('larena-storage::admin.workbench.required') }}</label>
                                </fieldset>
                            @endfor
                            <button class="larena-button larena-button-primary" type="submit">{{ __('larena-storage::admin.workbench.create') }}</button>
                        </form>
                    </details>
                @endif
            </aside>

            <div class="larena-workbench-main">
                @if ($selected === null)
                    <section class="larena-panel larena-workbench-empty" aria-labelledby="workbench-empty-title">
                        <h2 id="workbench-empty-title">{{ __('larena-storage::admin.workbench.select_structure') }}</h2>
                        <p>{{ __('larena-storage::admin.workbench.select_structure_text') }}</p>
                    </section>
                @else
                    <section class="larena-panel larena-workbench-schema" aria-labelledby="workbench-schema-title">
                        <header class="larena-workbench-section-header"><div><p class="larena-eyebrow">{{ $selectedId }}</p><h2 id="workbench-schema-title">{{ $selected['label'] ?? $selectedId }}</h2></div><span>v{{ $selected['version'] ?? '?' }} / schema {{ $selected['schema_version'] ?? '?' }}</span></header>
                        <div class="larena-table-scroll" tabindex="0" aria-label="{{ __('larena-storage::admin.workbench.fields') }}">
                            <table class="larena-table"><thead><tr><th>{{ __('larena-storage::admin.workbench.field_key') }}</th><th>{{ __('larena-storage::admin.workbench.label') }}</th><th>{{ __('larena-storage::admin.workbench.type') }}</th><th>{{ __('larena-storage::admin.workbench.position') }}</th></tr></thead>
                            <tbody>@foreach (($selected['fields'] ?? []) as $field) @if (is_array($field))<tr><td><code>{{ $field['key'] ?? '' }}</code></td><td>{{ $field['label'] ?? '' }}</td><td>{{ $field['type'] ?? '' }}@if (($field['required'] ?? false) === true) * @endif</td><td>{{ $field['position'] ?? '' }}</td></tr>@endif @endforeach</tbody></table>
                        </div>
                        @if (($permissions['structure.update'] ?? false) === true)
                            <details data-larena-workbench-schema-evolution><summary>{{ __('larena-storage::admin.workbench.evolve_structure') }}</summary>
                                <form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf
                                    <input type="hidden" name="action" value="structure.update"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[structure_id]" value="{{ $selectedId }}"><input type="hidden" name="payload[expected_version]" value="{{ $selected['version'] ?? '' }}">
                                    <label>{{ __('larena-storage::admin.workbench.label') }}<input name="payload[label]" value="{{ $selected['label'] ?? '' }}" required maxlength="120"></label>
                                    @foreach (($selected['fields'] ?? []) as $fieldIndex => $field) @if (is_array($field))
                                        <fieldset class="larena-workbench-fieldset"><legend>{{ __('larena-storage::admin.workbench.field') }} {{ $fieldIndex + 1 }}</legend>
                                            <label>{{ __('larena-storage::admin.workbench.field_key') }}<input name="payload[fields][{{ $fieldIndex }}][key]" value="{{ $field['key'] ?? '' }}" required readonly></label>
                                            <label>{{ __('larena-storage::admin.workbench.label') }}<input name="payload[fields][{{ $fieldIndex }}][label]" value="{{ $field['label'] ?? '' }}" required maxlength="120"></label>
                                            <input type="hidden" name="payload[fields][{{ $fieldIndex }}][type]" value="{{ $field['type'] ?? 'string' }}"><input type="hidden" name="payload[fields][{{ $fieldIndex }}][position]" value="{{ $field['position'] ?? 0 }}"><input type="hidden" name="payload[fields][{{ $fieldIndex }}][required]" value="{{ ($field['required'] ?? false) ? '1' : '0' }}">
                                        </fieldset>
                                    @endif @endforeach
                                    <fieldset class="larena-workbench-fieldset"><legend>{{ __('larena-storage::admin.workbench.add_optional_field') }}</legend>
                                        <label>{{ __('larena-storage::admin.workbench.field_key') }}<input name="payload[fields][{{ $newFieldIndex }}][key]" pattern="[a-z][a-z0-9_]{0,63}"></label>
                                        <label>{{ __('larena-storage::admin.workbench.label') }}<input name="payload[fields][{{ $newFieldIndex }}][label]" maxlength="120"></label>
                                        <label>{{ __('larena-storage::admin.workbench.type') }}<select name="payload[fields][{{ $newFieldIndex }}][type]"><option>string</option><option>text</option><option>integer</option><option>number</option><option>boolean</option><option>date</option><option>file</option><option>relation</option></select></label>
                                        <input type="hidden" name="payload[fields][{{ $newFieldIndex }}][position]" value="{{ ($newFieldIndex + 1) * 10 }}"><input type="hidden" name="payload[fields][{{ $newFieldIndex }}][required]" value="0">
                                    </fieldset>
                                    <button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.save_schema') }}</button>
                                </form>
                            </details>
                        @endif
                    </section>

                    <section class="larena-panel larena-workbench-records" aria-labelledby="workbench-records-title">
                        <header class="larena-workbench-section-header"><div><h2 id="workbench-records-title">{{ __('larena-storage::admin.workbench.records') }}</h2><p>{{ __('larena-storage::admin.workbench.matched', ['count' => $records['matched_count'] ?? 0]) }}</p></div></header>
                        @if ($dataViewHtml instanceof \Illuminate\Support\HtmlString)
                            <div data-larena-row-revisions="{}" data-larena-storage-workbench-composite="1"
                                data-larena-filter-fields="{{ json_encode($dataViewFilterFields, JSON_UNESCAPED_UNICODE) }}" data-larena-port-capabilities="[]"
                                data-larena-confirm-bulk-delete="{{ __('larena-storage::admin.workbench.confirm_bulk') }}">
                                <div data-larena-composite-surface hidden>
                                    <form method="get" action="{{ $workbenchQueryAction }}" data-larena-dataview-query
                                        data-larena-port-url="/admin/storage-workbench/port"
                                        data-larena-preferences-csrf="{{ csrf_token() }}"
                                        data-larena-pagination-query="{{ json_encode($dataViewQuery, JSON_UNESCAPED_UNICODE) }}">
                                        <input type="hidden" name="scope_ref" value="{{ $scopeRef }}">
                                        <input type="hidden" name="structure_id" value="{{ $selectedId }}">
                                        <input type="hidden" name="search" value="{{ $dataViewQuery['search'] }}">
                                        <input type="hidden" name="sort_field" value="{{ $dataViewQuery['sort_field'] }}">
                                        @if ($dataViewQuery['sort_field'] !== '')
                                            <input type="hidden" name="sort_direction" value="{{ $dataViewQuery['sort_direction'] }}">
                                        @endif
                                        @if ($query['include_archived'])<input type="hidden" name="include_archived" value="1">@endif
                                        {{ $dataViewHtml }}
                                    </form>
                                </div>
                            </div>
                        @endif
                        <details data-larena-storage-legacy open><summary>{{ __('larena-storage::admin.workbench.actions') }}</summary>
                        <form method="get" action="{{ $workbenchQueryAction }}" class="larena-workbench-filter">
                            <input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="structure_id" value="{{ $selectedId }}">
                            <label>{{ __('larena-storage::admin.workbench.search') }}<input name="search" maxlength="100" value="{{ $query['search'] }}"></label>
                            {{-- Without a sort the list is newest first (StorageListOrder): the form shows it as chosen. --}}
                            <label>{{ __('larena-storage::admin.workbench.sort') }}<select name="sort_field"><option value="_created_at" @selected($query['sort_field'] === '_created_at')>{{ __('larena-storage::admin.workbench.created_at') }}</option><option value="_record_id" @selected($query['sort_field'] === '_record_id')>ID</option>@foreach (($selected['fields'] ?? []) as $field) @if (is_array($field))<option value="{{ $field['key'] ?? '' }}" @selected($query['sort_field'] === ($field['key'] ?? null))>{{ $field['label'] ?? $field['key'] ?? '' }}</option>@endif @endforeach</select></label>
                            <label>{{ __('larena-storage::admin.workbench.direction') }}<select name="sort_direction"><option value="asc" @selected($query['sort_direction'] === 'asc')>↑</option><option value="desc" @selected($query['sort_direction'] === 'desc')>↓</option></select></label>
                            <label class="larena-workbench-check"><input type="checkbox" name="include_archived" value="1" @checked($query['include_archived'])> {{ __('larena-storage::admin.workbench.include_archived') }}</label>
                            <button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.apply') }}</button>
                        </form>

                        @if (($records['items'] ?? []) === [])
                            <p class="larena-workbench-empty-row">{{ __('larena-storage::admin.workbench.no_records') }}</p>
                        @else
                            <div class="larena-table-scroll" tabindex="0"><table class="larena-table larena-table-stack"><thead><tr><th>ID</th>@foreach (($selected['fields'] ?? []) as $field)<th>{{ $field['label'] ?? $field['key'] ?? '' }}</th>@endforeach<th>{{ __('larena-storage::admin.workbench.state') }}</th><th>{{ __('larena-storage::admin.workbench.actions') }}</th></tr></thead>
                            <tbody>@foreach ($records['items'] as $record) @if (is_array($record))<tr><td data-label="ID"><code>{{ $record['record_id'] ?? '' }}</code><small>r{{ $record['revision'] ?? '?' }}</small></td>@foreach (($selected['fields'] ?? []) as $field)<td data-label="{{ is_array($field) ? ($field['label'] ?? $field['key'] ?? '') : '' }}">{{ is_array($field) ? ($record['display_values'][$field['key'] ?? ''] ?? '') : '' }}</td>@endforeach<td data-label="{{ __('larena-storage::admin.workbench.state') }}">{{ $record['state'] ?? '' }}</td><td data-label="{{ __('larena-storage::admin.workbench.actions') }}">
                                <a class="larena-button" href="{{ $workbenchQueryAction }}?scope_ref={{ urlencode($scopeRef) }}&amp;structure_id={{ urlencode($selectedId) }}&amp;record_id={{ urlencode((string) ($record['record_id'] ?? '')) }}&amp;include_archived=1">{{ __('larena-storage::admin.workbench.history') }}</a>
                                @if (($permissions['record.update'] ?? false) === true && ($record['state'] ?? null) === 'active')<details><summary>{{ __('larena-storage::admin.workbench.edit') }}</summary><form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="record.update"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[structure_id]" value="{{ $selectedId }}"><input type="hidden" name="payload[record_id]" value="{{ $record['record_id'] ?? '' }}"><input type="hidden" name="payload[expected_revision]" value="{{ $record['revision'] ?? '' }}">@foreach (($selected['fields'] ?? []) as $field) @if (is_array($field))<label>{{ $field['label'] ?? $field['key'] ?? '' }}@if (($field['type'] ?? null) === 'text')<textarea name="payload[values][{{ $field['key'] ?? '' }}]">{{ $record['values'][$field['key'] ?? ''] ?? '' }}</textarea>@elseif (($field['type'] ?? null) === 'boolean')<select name="payload[values][{{ $field['key'] ?? '' }}]"><option value="false" @selected(($record['values'][$field['key'] ?? ''] ?? false) === false)>false</option><option value="true" @selected(($record['values'][$field['key'] ?? ''] ?? false) === true)>true</option></select>@else<input name="payload[values][{{ $field['key'] ?? '' }}]" value="{{ $record['values'][$field['key'] ?? ''] ?? '' }}">@endif</label>@endif @endforeach<button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.save_record') }}</button></form></details>@endif
                                @if (($permissions['record.archive'] ?? false) === true && ($record['state'] ?? null) === 'active')<form method="post" action="{{ $workbenchAction }}" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="record.archive"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[structure_id]" value="{{ $selectedId }}"><input type="hidden" name="payload[record_id]" value="{{ $record['record_id'] ?? '' }}"><input type="hidden" name="payload[expected_revision]" value="{{ $record['revision'] ?? '' }}"><label><input type="checkbox" name="payload[confirmed]" value="1" required> {{ __('larena-storage::admin.workbench.confirm_archive') }}</label><button class="larena-button larena-button--danger" type="submit">{{ __('larena-storage::admin.workbench.archive') }}</button></form>@endif
                            </td></tr>@endif @endforeach</tbody></table></div>
                        @endif
                        @if (is_string($records['continuation'] ?? null) && $records['continuation'] !== '')<a class="larena-button" href="{{ $workbenchQueryAction }}?scope_ref={{ urlencode($scopeRef) }}&amp;structure_id={{ urlencode($selectedId) }}&amp;continuation={{ urlencode($records['continuation']) }}">{{ __('larena-storage::admin.workbench.next_page') }}</a>@endif

                        @if (($records['history'] ?? []) !== [])
                            <section data-larena-workbench-history><h3>{{ __('larena-storage::admin.workbench.revision_history') }}</h3><ol class="larena-workbench-list">@foreach ($records['history'] as $revision) @if (is_array($revision))<li><strong>r{{ $revision['revision'] ?? '?' }}</strong> · {{ $revision['state'] ?? '' }} · <code>{{ $revision['record_id'] ?? '' }}</code></li>@endif @endforeach</ol></section>
                        @endif

                        @if (($permissions['record.bulk_archive'] ?? false) === true && ($records['items'] ?? []) !== [])
                            <details data-larena-workbench-bulk><summary>{{ __('larena-storage::admin.workbench.bulk_archive') }}</summary><form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="record.bulk_archive"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[structure_id]" value="{{ $selectedId }}">@foreach ($records['items'] as $record) @if (is_array($record) && ($record['state'] ?? null) === 'active')<label><input type="checkbox" name="payload[expected_revisions][{{ $record['record_id'] ?? '' }}]" value="{{ $record['revision'] ?? '' }}"> {{ $record['values']['title'] ?? $record['record_id'] ?? '' }}</label>@endif @endforeach<label><input type="checkbox" name="payload[confirmed]" value="1" required> {{ __('larena-storage::admin.workbench.confirm_bulk') }}</label><button class="larena-button larena-button--danger" type="submit">{{ __('larena-storage::admin.workbench.bulk_archive') }}</button></form></details>
                        @endif
                        </details>

                        @if (($permissions['record.create'] ?? false) === true)
                            <details><summary>{{ __('larena-storage::admin.workbench.create_record') }}</summary><form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="record.create"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[structure_id]" value="{{ $selectedId }}">
                                @foreach (($selected['fields'] ?? []) as $field) @if (is_array($field))<label>{{ $field['label'] ?? $field['key'] ?? '' }}@if (($field['required'] ?? false) === true) * @endif
                                    @if (($field['type'] ?? null) === 'text')<textarea name="payload[values][{{ $field['key'] ?? '' }}]" @if (($field['required'] ?? false) === true) required @endif></textarea>@elseif (($field['type'] ?? null) === 'boolean')<select name="payload[values][{{ $field['key'] ?? '' }}]"><option value="false">false</option><option value="true">true</option></select>@else<input name="payload[values][{{ $field['key'] ?? '' }}]" @if (($field['required'] ?? false) === true) required @endif>@endif
                                </label>@endif @endforeach
                                <button class="larena-button larena-button-primary" type="submit">{{ __('larena-storage::admin.workbench.create') }}</button>
                            </form></details>
                        @endif
                    </section>
                @endif

                <section class="larena-panel larena-workbench-integrations" aria-labelledby="workbench-integrations-title">
                    <header class="larena-workbench-section-header"><div><h2 id="workbench-integrations-title">{{ __('larena-storage::admin.workbench.integrations') }}</h2><p>{{ __('larena-storage::admin.workbench.integrations_text') }}</p></div></header>
                    <div class="larena-workbench-integration-grid">
                        <article><h3>{{ __('larena-storage::admin.workbench.files') }}</h3><ul class="larena-workbench-list">@foreach (($workbench['files']['nodes'] ?? []) as $node) @if (is_array($node))<li><strong>{{ $node['name'] ?? $node['node_ref'] ?? '' }}</strong> · {{ $node['kind'] ?? '' }} · r{{ $node['revision'] ?? '?' }}@if (($permissions['file.rename'] ?? false) === true)<details><summary>{{ __('larena-storage::admin.workbench.rename') }}</summary><form method="post" action="{{ $workbenchAction }}" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="file.rename"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[node_ref]" value="{{ $node['node_ref'] ?? '' }}"><input type="hidden" name="payload[expected_revision]" value="{{ $node['revision'] ?? '' }}"><label>{{ __('larena-storage::admin.workbench.label') }}<input name="payload[name]" value="{{ $node['name'] ?? '' }}" required maxlength="120"></label><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.rename') }}</button></form></details>@endif @if (($permissions['file.move'] ?? false) === true)<details><summary>{{ __('larena-storage::admin.workbench.move') }}</summary><form method="post" action="{{ $workbenchAction }}" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="file.move"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[node_ref]" value="{{ $node['node_ref'] ?? '' }}"><input type="hidden" name="payload[expected_revision]" value="{{ $node['revision'] ?? '' }}"><label>{{ __('larena-storage::admin.workbench.parent_ref') }}<input name="payload[parent_ref]"></label><label>{{ __('larena-storage::admin.workbench.position') }}<input type="number" name="payload[sibling_order]" min="0" max="1000000" value="{{ $node['sibling_order'] ?? 0 }}"></label><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.move') }}</button></form></details>@endif</li>@endif @endforeach</ul>@if (($permissions['file.folder_create'] ?? false) === true)<form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="file.folder_create"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><label>{{ __('larena-storage::admin.workbench.folder_name') }}<input name="payload[name]" required maxlength="120"></label><label>{{ __('larena-storage::admin.workbench.parent_ref') }}<input name="payload[parent_ref]"></label><input type="hidden" name="payload[sibling_order]" value="0"><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.create_folder') }}</button></form>@endif @if (($permissions['file.upload'] ?? false) === true)<form method="post" enctype="multipart/form-data" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="file.upload"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><label>{{ __('larena-storage::admin.workbench.file') }}<input type="file" name="payload[file]" required></label><label>{{ __('larena-storage::admin.workbench.label') }}<input name="payload[label]" required maxlength="120"></label><label>{{ __('larena-storage::admin.workbench.parent_ref') }}<input name="payload[parent_ref]"></label><input type="hidden" name="payload[sibling_order]" value="0"><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.upload') }}</button></form>@endif</article>
                        <article><h3>{{ __('larena-storage::admin.workbench.documents') }}</h3>
                            <form method="get" action="{{ $workbenchQueryAction }}" class="larena-form-compact"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><label>{{ __('larena-storage::admin.workbench.document_id') }}<input name="document_id" value="{{ $query['document_id'] }}" required></label><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.open') }}</button></form>
                            @if (($permissions['content.create'] ?? false) === true)<details><summary>{{ __('larena-storage::admin.workbench.create_document') }}</summary><form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="content.create"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><label>{{ __('larena-storage::admin.workbench.document_id') }}<input name="payload[document_id]" required></label><label>EditorJS JSON<textarea name="payload[document_json]" rows="10" required>{
  "schema": "larena.content.block_document",
  "schema_version": 1,
  "document_id": "document.example",
  "scope_ref": "{{ $scopeRef }}",
  "registry": "larena.content.blocks",
  "time": 0,
  "version": "2.30.0",
  "blocks": []
}</textarea></label><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.create') }}</button></form></details>@endif
                            @foreach (($workbench['documents'] ?? []) as $document) @if (is_array($document))<form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="content.update"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[document_id]" value="{{ $document['document_id'] ?? '' }}"><input type="hidden" name="payload[expected_version]" value="{{ $document['version'] ?? '' }}"><label>{{ $document['label'] ?? $document['document_id'] ?? '' }}<textarea name="payload[document_json]" rows="8">{{ $document['document_json'] ?? '{}' }}</textarea></label><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.save_document') }}</button></form>@endif @endforeach
                        </article>
                        <article><h3>{{ __('larena-storage::admin.workbench.pages') }}</h3>
                            <form method="get" action="{{ $workbenchQueryAction }}" class="larena-form-compact"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><label>{{ __('larena-storage::admin.workbench.page_id') }}<input name="page_id" value="{{ $query['page_id'] }}" required></label><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.preview') }}</button></form>
                            @if (($permissions['layout.create'] ?? false) === true)<details><summary>{{ __('larena-storage::admin.workbench.create_page') }}</summary><form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="layout.create"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><label>{{ __('larena-storage::admin.workbench.page_id') }}<input name="payload[page_id]" required></label><label>Layout JSON<textarea name="payload[descriptor_json]" rows="10" required>{
  "schema": "larena.layout.page_descriptor",
  "schema_version": 1,
  "page_id": "page.example",
  "scope_ref": "{{ $scopeRef }}",
  "layout_id": "layout.universal-workbench",
  "regions": []
}</textarea></label><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.create') }}</button></form></details>@endif
                            @foreach (($workbench['pages'] ?? []) as $page) @if (is_array($page))<section data-larena-workbench-page><form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="layout.update"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[page_id]" value="{{ $page['page_id'] ?? '' }}"><input type="hidden" name="payload[expected_revision]" value="{{ $page['revision'] ?? '' }}"><label>{{ $page['label'] ?? $page['page_id'] ?? '' }}<textarea name="payload[descriptor_json]" rows="8">{{ $page['descriptor_json'] ?? '{}' }}</textarea></label><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.save_page') }}</button></form>@if (is_string($page['preview_json'] ?? null))<details open><summary>{{ __('larena-storage::admin.workbench.preview') }}</summary><pre class="larena-workbench-preview">{{ $page['preview_json'] }}</pre></details>@endif</section>@endif @endforeach
                            @if ($publication === null && ($permissions['publication.draft'] ?? false) === true)<form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="publication.draft"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><label>{{ __('larena-storage::admin.workbench.page_id') }}<input name="payload[page_id]" value="{{ $query['page_id'] }}" required></label><button class="larena-button" type="submit">{{ __('larena-storage::admin.workbench.save_draft') }}</button></form>@endif
                            @if (in_array(($publication['status'] ?? null), ['draft', 'revoked'], true) && ($permissions['publication.publish'] ?? false) === true)<form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="publication.publish"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[page_id]" value="{{ $publication['page_id'] ?? '' }}"><button class="larena-button larena-button-primary" type="submit">{{ __('larena-storage::admin.workbench.publish') }}</button></form>@endif
                            @if (($publication['status'] ?? null) === 'published' && ($permissions['publication.unpublish'] ?? false) === true)<form method="post" action="{{ $workbenchAction }}" class="larena-form-compact" data-larena-workbench-form>@csrf<input type="hidden" name="action" value="publication.unpublish"><input type="hidden" name="scope_ref" value="{{ $scopeRef }}"><input type="hidden" name="payload[page_id]" value="{{ $publication['page_id'] ?? '' }}"><button class="larena-button larena-button--danger" type="submit">{{ __('larena-storage::admin.workbench.unpublish') }}</button></form>@endif
                        </article>
                    </div>
                </section>
            </div>
        </div>
    @endif
</div>
@endsection
