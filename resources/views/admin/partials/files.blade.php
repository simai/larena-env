{{-- A site's files (simai-env 1.39.0–1.40.0): a folder or a file open in the editor. Every change is planned and
     confirmed; there is no trash, so a removal says it is final. --}}
<section class="larena-panel larena-panel-padded" data-env-files="{{ $files['supported'] ? 'on' : 'unsupported' }}" aria-labelledby="env-site-files">
    <h2 id="env-site-files">{{ __('larena-env::admin.files.title') }}</h2>
    @if (!$files['supported'])
        <p data-env-files-unsupported>{{ __('larena-env::admin.files.unsupported') }}</p>
    @else
        <p>{{ __('larena-env::admin.files.explain') }}</p>
        <nav aria-label="{{ __('larena-env::admin.files.path') }}" data-env-files-crumbs class="flex flex-wrap g-1" style="overflow-wrap: anywhere;">
            <a href="{{ $files['base'] }}&amp;path=.">{{ $domain }}</a>
            @foreach ($files['crumbs'] as $crumb)
                <span aria-hidden="true">/</span>
                <a href="{{ $files['base'] }}&amp;path={{ rawurlencode($crumb['path']) }}">{{ $crumb['name'] }}</a>
            @endforeach
            @if ($files['view'] !== null)
                <span aria-hidden="true">/</span><span>{{ basename($files['view']) }}</span>
            @endif
        </nav>
        @if ($files['view'] !== null)
            <p><a href="{{ $files['base'] }}&amp;path={{ rawurlencode($files['path']) }}" data-env-files-back>{{ __('larena-env::admin.files.back_to_folder') }}</a></p>
        @elseif ($files['parent'] !== null)
            <p><a href="{{ $files['base'] }}&amp;path={{ rawurlencode($files['parent']) }}" data-env-files-up>{{ __('larena-env::admin.files.up') }}</a></p>
        @endif
        @if ($files['problem'] !== null)
            <p role="alert" data-env-files-problem>{{ $files['problem'] }}</p>
        @endif

        @if ($files['view'] !== null)
            @if ($files['text'] !== null)
                {{-- The text as it was read: saved with its sha256, so a change made meanwhile is refused, never lost. --}}
                @if (!$files['text']['savable'])
                    <p data-env-files-too-long role="note">{{ __('larena-env::admin.files.too_long_to_save') }}</p>
                @endif
                <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-files-editor>
                    @csrf
                    <input type="hidden" name="op" value="write">
                    <input type="hidden" name="path" value="{{ $files['view'] }}">
                    <input type="hidden" name="sha256" value="{{ $files['text']['sha256'] }}">
                    <div class="larena-field">
                        <label for="env-files-content">{{ __('larena-env::admin.files.content', ['name' => basename($files['view'])]) }}</label>
                        <textarea id="env-files-content" name="content" rows="18" spellcheck="false" style="font-family: var(--sf-font-mono, monospace); width: 100%; white-space: pre; overflow-x: auto;" @if (!($files['allows']['write'] && $files['text']['savable'])) readonly @endif>{{ $files['text']['content'] }}</textarea>
                    </div>
                    @if (($files['allows']['write'] && $files['text']['savable']))
                        <p>{{ __('larena-env::admin.files.save_note') }}</p>
                        <div class="larena-form-actions"><button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.files.save') }}</button></div>
                    @endif
                </form>
            @endif
            @if ($files['allows']['download'])
                <p><a href="{{ route('larena.env.admin.site.files.download', ['domain' => $domain], false) }}?path={{ rawurlencode($files['view']) }}" data-env-files-download>{{ __('larena-env::admin.files.download') }}</a></p>
            @endif
        @elseif ($files['folder'] !== null)
            {{-- What the folder takes: a folder, an empty file, an upload; each one planned first. --}}
            @if ($files['allows']['mkdir'] || $files['allows']['create'] || $files['allows']['upload'])
                <div class="flex flex-wrap g-1 m-bottom-2" data-env-files-toolbar>
                    @if ($files['allows']['mkdir'])
                        <details class="larena-panel larena-panel-padded" style="margin: 0;" data-env-files-op="mkdir">
                            <summary style="min-height: 2.75rem; display: flex; align-items: center; cursor: pointer;">{{ __('larena-env::admin.files.new_folder') }}</summary>
                            <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form">
                                @csrf
                                <input type="hidden" name="op" value="mkdir">
                                <input type="hidden" name="folder" value="{{ $files['path'] }}">
                                <div class="larena-field">
                                    <label for="env-files-mkdir">{{ __('larena-env::admin.files.name') }}</label>
                                    <input type="text" id="env-files-mkdir" name="name" required maxlength="255">
                                </div>
                                <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                            </form>
                        </details>
                    @endif
                    @if ($files['allows']['create'])
                        <details class="larena-panel larena-panel-padded" style="margin: 0;" data-env-files-op="create">
                            <summary style="min-height: 2.75rem; display: flex; align-items: center; cursor: pointer;">{{ __('larena-env::admin.files.new_file') }}</summary>
                            <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form">
                                @csrf
                                <input type="hidden" name="op" value="create">
                                <input type="hidden" name="folder" value="{{ $files['path'] }}">
                                <div class="larena-field">
                                    <label for="env-files-create">{{ __('larena-env::admin.files.name') }}</label>
                                    <input type="text" id="env-files-create" name="name" required maxlength="255">
                                </div>
                                <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.files.new_file_note') }}</p>
                                <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                            </form>
                        </details>
                    @endif
                    @if ($files['allows']['upload'])
                        <details class="larena-panel larena-panel-padded" style="margin: 0;" data-env-files-op="upload">
                            <summary style="min-height: 2.75rem; display: flex; align-items: center; cursor: pointer;">{{ __('larena-env::admin.files.upload') }}</summary>
                            <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form" enctype="multipart/form-data">
                                @csrf
                                <input type="hidden" name="op" value="upload">
                                <input type="hidden" name="folder" value="{{ $files['path'] }}">
                                <div class="larena-field">
                                    <label for="env-files-upload">{{ __('larena-env::admin.files.upload_file') }}</label>
                                    <input type="file" id="env-files-upload" name="file" required>
                                </div>
                                <div class="larena-field flex items-cross-center g-1">
                                    <input type="checkbox" id="env-files-overwrite" name="overwrite" value="yes" style="width: auto; min-width: 1.25rem; min-height: 1.25rem;">
                                    <label for="env-files-overwrite" style="margin: 0;">{{ __('larena-env::admin.files.overwrite') }}</label>
                                </div>
                                <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.files.upload_note') }}</p>
                                <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                            </form>
                        </details>
                    @endif
                </div>
            @endif

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
                                <th scope="col"><span class="larena-visually-hidden">{{ __('larena-env::admin.files.actions') }}</span></th>
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
                                            {{ $entry->name }}
                                        @endif
                                        @if ($entry->type === 'link')
                                            <span class="color-on-surface-variant" data-env-file-link>→ {{ $entry->target ?? __('larena-env::admin.files.link') }}</span>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('larena-env::admin.files.size') }}">{{ $entry->type === 'file' ? \Larena\Env\Server\Bytes::human($entry->size) : '—' }}</td>
                                    <td data-label="{{ __('larena-env::admin.files.changed') }}">{{ \Larena\Env\Server\Moment::human($entry->mtime) }}</td>
                                    <td data-label="{{ __('larena-env::admin.files.mode') }}"><code>{{ $entry->mode ?? '—' }}</code></td>
                                    <td data-label="{{ __('larena-env::admin.files.actions') }}">
                                        <div class="flex flex-wrap items-cross-center g-1">
                                            @if ($entry->type === 'file' && $files['allows']['download'])
                                                <a class="flex items-cross-center p-x-1" style="min-height: 2.75rem;" href="{{ route('larena.env.admin.site.files.download', ['domain' => $domain], false) }}?path={{ rawurlencode(\Larena\Env\Files\FilePath::join($files['path'], $entry->name)) }}" data-env-file-download>{{ __('larena-env::admin.files.download') }}</a>
                                            @endif
                                            @if ($files['allows']['rename'] || $files['allows']['remove'] || $files['allows']['archive'] || ($files['allows']['extract'] && $entry->isArchive()))
                                                <details data-env-file-actions>
                                                    <summary class="flex items-cross-center p-x-1" style="min-height: 2.75rem; cursor: pointer;" aria-label="{{ __('larena-env::admin.files.more_of', ['name' => $entry->name]) }}">{{ __('larena-env::admin.files.more') }}</summary>
                                                    <div class="flex flex-column g-1 p-y-1" style="min-width: min(18rem, 80vw);">
                                                        @if ($files['allows']['rename'])
                                                            <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="flex flex-wrap items-cross-end g-1 m-0" data-env-files-op="rename">
                                                                @csrf
                                                                <input type="hidden" name="op" value="rename">
                                                                <input type="hidden" name="path" value="{{ \Larena\Env\Files\FilePath::join($files['path'], $entry->name) }}">
                                                                <label class="flex flex-column text-small grow">{{ __('larena-env::admin.files.new_name') }}
                                                                    <input type="text" name="name" value="{{ $entry->name }}" required maxlength="255" style="min-height: 2.75rem;">
                                                                </label>
                                                                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.files.operations.rename') }}</button>
                                                            </form>
                                                        @endif
                                                        @if ($files['allows']['archive'])
                                                            <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="flex flex-wrap items-cross-end g-1 m-0" data-env-files-op="archive">
                                                                @csrf
                                                                <input type="hidden" name="op" value="archive">
                                                                <input type="hidden" name="path" value="{{ \Larena\Env\Files\FilePath::join($files['path'], $entry->name) }}">
                                                                <label class="flex flex-column text-small">{{ __('larena-env::admin.files.format') }}
                                                                    <select name="format" style="min-height: 2.75rem;">
                                                                        @foreach ($files['formats'] as $format)
                                                                            <option value="{{ $format }}">{{ $format }}</option>
                                                                        @endforeach
                                                                    </select>
                                                                </label>
                                                                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.files.operations.archive') }}</button>
                                                            </form>
                                                        @endif
                                                        @if ($files['allows']['extract'] && $entry->isArchive())
                                                            <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="flex flex-wrap items-cross-end g-1 m-0" data-env-files-op="extract">
                                                                @csrf
                                                                <input type="hidden" name="op" value="extract">
                                                                <input type="hidden" name="path" value="{{ \Larena\Env\Files\FilePath::join($files['path'], $entry->name) }}">
                                                                <label class="flex flex-column text-small grow">{{ __('larena-env::admin.files.extract_to') }}
                                                                    <input type="text" name="folder" value="{{ $files['path'] }}" required maxlength="1024" style="min-height: 2.75rem;">
                                                                </label>
                                                                <label class="flex items-cross-center g-1 text-small"><input type="checkbox" name="overwrite" value="yes" style="width: auto; min-width: 1.25rem; min-height: 1.25rem;"> {{ __('larena-env::admin.files.extract_overwrite') }}</label>
                                                                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.files.operations.extract') }}</button>
                                                            </form>
                                                        @endif
                                                        @if ($files['allows']['remove'])
                                                            <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="m-0" data-env-files-op="remove">
                                                                @csrf
                                                                <input type="hidden" name="op" value="remove">
                                                                <input type="hidden" name="path" value="{{ \Larena\Env\Files\FilePath::join($files['path'], $entry->name) }}">
                                                                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.files.operations.remove') }}</button>
                                                            </form>
                                                        @endif
                                                    </div>
                                                </details>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($files['folder']['truncated'])
                    <p data-env-files-truncated>{{ __('larena-env::admin.files.truncated') }}</p>
                @endif
            @endif
        @endif
    @endif
</section>
