{{-- A site's files (Panel plan 6.1, 6.2): a folder, a file open in the editor, or the trash. Every change is planned and confirmed. --}}
<section class="larena-panel larena-panel-padded" data-env-files="{{ $files['supported'] ? 'on' : 'unsupported' }}" aria-labelledby="env-site-files">
    <h2 id="env-site-files">{{ __('larena-env::admin.files.title') }}</h2>
    @if (!$files['supported'])
        <p data-env-files-unsupported>{{ __('larena-env::admin.files.unsupported') }}</p>
    @else
        <p>{{ __('larena-env::admin.files.explain') }}</p>
        <nav aria-label="{{ __('larena-env::admin.files.path') }}" data-env-files-crumbs style="display: flex; flex-wrap: wrap; gap: .25rem .5rem; overflow-wrap: anywhere;">
            <a href="{{ $files['base'] }}&amp;path=.">{{ $domain }}</a>
            @foreach ($files['crumbs'] as $crumb)
                <span aria-hidden="true">/</span>
                <a href="{{ $files['base'] }}&amp;path={{ rawurlencode($crumb['path']) }}">{{ $crumb['name'] }}</a>
            @endforeach
            @if ($files['view'] !== null)
                <span aria-hidden="true">/</span><span>{{ basename($files['view']) }}</span>
            @endif
        </nav>
        <p style="display: flex; flex-wrap: wrap; gap: .5rem 1rem;">
            @if ($files['view'] !== null || $files['trashOpen'])
                <a href="{{ $files['base'] }}&amp;path={{ rawurlencode($files['view'] !== null ? \Larena\Env\Files\FilePath::parent($files['view']) : $files['path']) }}" data-env-files-back>{{ __('larena-env::admin.files.back_to_folder') }}</a>
            @elseif ($files['parent'] !== null)
                <a href="{{ $files['base'] }}&amp;path={{ rawurlencode($files['parent']) }}" data-env-files-up>{{ __('larena-env::admin.files.up') }}</a>
            @endif
            @if ($files['allows']['trash'] && !$files['trashOpen'])
                <a href="{{ $files['base'] }}&amp;trash=1" data-env-files-trash-link>{{ __('larena-env::admin.files.trash') }}</a>
            @endif
        </p>
        @if ($files['problem'] !== null)
            <p role="alert" data-env-files-problem>{{ $files['problem'] }}</p>
        @endif

        @if ($files['text'] !== null)
            {{-- A file open: its text, saved by plan and confirmation; the previous version goes to the trash. --}}
            @if ($files['text']['binary'])
                <p data-env-files-binary>{{ __('larena-env::admin.files.binary') }}</p>
            @else
                <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-files-editor>
                    @csrf
                    <input type="hidden" name="op" value="write">
                    <input type="hidden" name="path" value="{{ $files['view'] }}">
                    <div class="larena-field">
                        <label for="env-files-content">{{ __('larena-env::admin.files.content', ['name' => basename($files['view'])]) }}</label>
                        <textarea id="env-files-content" name="content" rows="18" spellcheck="false" style="font-family: var(--sf-font-mono, monospace); width: 100%; white-space: pre; overflow-x: auto;" @if (!$files['allows']['write']) readonly @endif>{{ $files['text']['content'] }}</textarea>
                    </div>
                    @if ($files['allows']['write'])
                        <p>{{ __('larena-env::admin.files.save_note') }}</p>
                        <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.files.save') }}</button></div>
                    @endif
                </form>
            @endif
            @if ($files['allows']['download'])
                <p><a href="{{ route('larena.env.admin.site.files.download', ['domain' => $domain], false) }}?path={{ rawurlencode($files['view']) }}" data-env-files-download>{{ __('larena-env::admin.files.download') }}</a></p>
            @endif
        @elseif ($files['trash'] !== null)
            {{-- The trash: what was deleted or replaced, kept 14 days, put back where it was. --}}
            <h3>{{ __('larena-env::admin.files.trash') }}</h3>
            <p>{{ __('larena-env::admin.files.trash_note') }}</p>
            @if ($files['trash'] === [])
                <p data-env-files-trash-empty>{{ __('larena-env::admin.files.trash_empty') }}</p>
            @else
                <ul class="larena-list" data-env-files-trash>
                    @foreach ($files['trash'] as $item)
                        <li data-env-trash-item="{{ $item->id }}" style="display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; overflow-wrap: anywhere;">
                            <span>{{ $item->path }}@if ($item->deletedAt !== null) · {{ \Larena\Env\Server\Moment::human($item->deletedAt) }}@endif</span>
                            @if ($files['allows']['restore'])
                                <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" data-env-files-op="restore">
                                    @csrf
                                    <input type="hidden" name="op" value="restore">
                                    <input type="hidden" name="id" value="{{ $item->id }}">
                                    <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.files.operations.restore') }}</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif ($files['folder'] !== null)
            @if ($files['folder']['entries'] === [])
                <p data-env-files-empty>{{ __('larena-env::admin.files.empty') }}</p>
            @else
                <div class="larena-table-scroll">
                    <table class="larena-table larena-table-stack" data-env-files-list>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('larena-env::admin.files.name') }}</th>
                                <th scope="col">{{ __('larena-env::admin.files.size') }}</th>
                                <th scope="col">{{ __('larena-env::admin.files.changed') }}</th>
                                <th scope="col">{{ __('larena-env::admin.files.mode') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($files['folder']['entries'] as $entry)
                                <tr data-env-file="{{ $entry->name }}" data-env-file-type="{{ $entry->type }}">
                                    <td data-label="{{ __('larena-env::admin.files.name') }}" style="overflow-wrap: anywhere;">
                                        @if ($entry->isDir())
                                            <a href="{{ $files['base'] }}&amp;path={{ rawurlencode(\Larena\Env\Files\FilePath::join($files['path'], $entry->name)) }}">{{ $entry->name }}/</a>
                                        @elseif ($entry->type === 'file' && $files['allows']['read'])
                                            <a href="{{ $files['base'] }}&amp;view={{ rawurlencode(\Larena\Env\Files\FilePath::join($files['path'], $entry->name)) }}">{{ $entry->name }}</a>
                                        @else
                                            {{ $entry->name }}@if ($entry->type === 'link') <span>({{ __('larena-env::admin.files.link') }})</span>@endif
                                        @endif
                                    </td>
                                    <td data-label="{{ __('larena-env::admin.files.size') }}">{{ $entry->isDir() ? '—' : \Larena\Env\Server\Bytes::human($entry->size) }}</td>
                                    <td data-label="{{ __('larena-env::admin.files.changed') }}">{{ $entry->mtime !== null ? \Larena\Env\Server\Moment::human($entry->mtime) : '—' }}</td>
                                    <td data-label="{{ __('larena-env::admin.files.mode') }}"><code>{{ $entry->mode ?? '—' }}</code></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($files['folder']['truncated'])
                    <p data-env-files-truncated>{{ __('larena-env::admin.files.truncated') }}</p>
                @endif
            @endif

            {{-- The changes of this folder: each form plans its change; nothing happens before the confirmation. --}}
            <h3>{{ __('larena-env::admin.files.changes') }}</h3>
            @if ($files['allows']['mkdir'])
                <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-files-op="mkdir">
                    @csrf
                    <input type="hidden" name="op" value="mkdir">
                    <input type="hidden" name="folder" value="{{ $files['path'] }}">
                    <div class="larena-field">
                        <label for="env-files-mkdir">{{ __('larena-env::admin.files.new_folder') }}</label>
                        <input type="text" id="env-files-mkdir" name="name" required maxlength="255">
                    </div>
                    <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                </form>
            @endif
            @if ($files['allows']['upload'])
                <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form" enctype="multipart/form-data" data-env-files-op="upload">
                    @csrf
                    <input type="hidden" name="op" value="upload">
                    <input type="hidden" name="folder" value="{{ $files['path'] }}">
                    <div class="larena-field">
                        <label for="env-files-upload">{{ __('larena-env::admin.files.upload') }}</label>
                        <input type="file" id="env-files-upload" name="file" required>
                    </div>
                    <div class="larena-field" style="display: flex; align-items: center; gap: .5rem;">
                        <input type="checkbox" id="env-files-overwrite" name="overwrite" value="yes" style="width: auto; min-width: 1.25rem; min-height: 1.25rem;">
                        <label for="env-files-overwrite" style="margin: 0;">{{ __('larena-env::admin.files.overwrite') }}</label>
                    </div>
                    <p>{{ __('larena-env::admin.files.upload_note') }}</p>
                    <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                </form>
            @endif
            @if ($files['folder']['entries'] !== [] && ($files['allows']['rename'] || $files['allows']['copy'] || $files['allows']['delete'] || $files['allows']['chmod'] || $files['allows']['archive'] || $files['allows']['extract'] || $files['allows']['download']))
                {{-- One entry, one change: the person picks the entry and what to do with it. --}}
                <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-files-op="entry">
                    @csrf
                    <div class="larena-field">
                        <label for="env-files-entry">{{ __('larena-env::admin.files.entry') }}</label>
                        <select id="env-files-entry" name="path" required>
                            @foreach ($files['folder']['entries'] as $entry)
                                <option value="{{ \Larena\Env\Files\FilePath::join($files['path'], $entry->name) }}">{{ $entry->name }}{{ $entry->isDir() ? '/' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="larena-field">
                        <label for="env-files-what">{{ __('larena-env::admin.files.what') }}</label>
                        <select id="env-files-what" name="op" required>
                            @foreach (['rename', 'copy', 'delete', 'chmod', 'archive', 'extract'] as $op)
                                @if ($files['allows'][$op])
                                    <option value="{{ $op }}" data-env-files-choice="{{ $op }}">{{ __('larena-env::admin.files.operations.'.$op) }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="larena-field">
                        <label for="env-files-name">{{ __('larena-env::admin.files.new_name') }}</label>
                        <input type="text" id="env-files-name" name="name" maxlength="255">
                    </div>
                    @if ($files['allows']['chmod'])
                        <div class="larena-field">
                            <label for="env-files-mode">{{ __('larena-env::admin.files.mode_choice') }}</label>
                            <select id="env-files-mode" name="mode">
                                <optgroup label="{{ __('larena-env::admin.files.mode_files') }}">
                                    @foreach ($files['fileModes'] as $mode)
                                        <option value="{{ $mode }}" @if ($mode === '0644') selected @endif>{{ $mode }}</option>
                                    @endforeach
                                </optgroup>
                                <optgroup label="{{ __('larena-env::admin.files.mode_folders') }}">
                                    @foreach ($files['dirModes'] as $mode)
                                        <option value="{{ $mode }}">{{ $mode }}</option>
                                    @endforeach
                                </optgroup>
                            </select>
                        </div>
                        <div class="larena-field">
                            <label for="env-files-kind">{{ __('larena-env::admin.files.mode_for') }}</label>
                            <select id="env-files-kind" name="kind">
                                <option value="file">{{ __('larena-env::admin.files.mode_for_file') }}</option>
                                <option value="dir">{{ __('larena-env::admin.files.mode_for_folder') }}</option>
                            </select>
                        </div>
                    @endif
                    @if ($files['allows']['archive'])
                        <div class="larena-field">
                            <label for="env-files-format">{{ __('larena-env::admin.files.format') }}</label>
                            <select id="env-files-format" name="format">
                                <option value="zip">zip</option>
                                <option value="tar.gz">tar.gz</option>
                            </select>
                        </div>
                    @endif
                    <input type="hidden" name="folder" value="{{ $files['path'] }}">
                    <div class="larena-field" style="display: flex; align-items: center; gap: .5rem;">
                        <input type="checkbox" id="env-files-recursive" name="recursive" value="yes" style="width: auto; min-width: 1.25rem; min-height: 1.25rem;">
                        <label for="env-files-recursive" style="margin: 0;">{{ __('larena-env::admin.files.recursive') }}</label>
                    </div>
                    <p>{{ __('larena-env::admin.files.entry_note') }}</p>
                    <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                </form>
            @endif
            @if ($files['allows']['download'] && $files['folder']['entries'] !== [])
                <form method="get" action="{{ route('larena.env.admin.site.files.download', ['domain' => $domain], false) }}" class="larena-form" data-env-files-op="download">
                    <div class="larena-field">
                        <label for="env-files-download">{{ __('larena-env::admin.files.download') }}</label>
                        <select id="env-files-download" name="path" required>
                            @foreach ($files['folder']['entries'] as $entry)
                                @if ($entry->type === 'file')
                                    <option value="{{ \Larena\Env\Files\FilePath::join($files['path'], $entry->name) }}">{{ $entry->name }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.files.download') }}</button></div>
                </form>
            @endif
        @endif
    @endif
</section>
