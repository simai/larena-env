{{-- A site's files (simai-env 1.39.0; the trash 1.57.0), files study PX-91…PX-96: the tab opens on the folder visitors see,
     the server's own entries folded at the root; a folder opens by its name, a file has «Посмотреть» (a picture in place, a
     text in the editor); a file with passwords is marked and asked about before it is shown or downloaded. The list is the
     admin's shared data view (a list on the phone); every change is planned and confirmed, a removal goes to the trash. --}}
<section class="larena-panel larena-panel-padded" data-env-files="{{ $files['supported'] ? 'on' : 'unsupported' }}" aria-labelledby="env-site-files-title">
    <h2 id="env-site-files-title" class="text-2">{{ __('larena-env::admin.files.title') }}</h2>
    @if (!$files['supported'])
        <p data-env-files-unsupported>{{ __('larena-env::admin.files.unsupported') }}</p>
    @else
        <div class="m-bottom-1">@include('larena-env::admin.partials.explanation', ['text' => __('larena-env::admin.files.explain')])</div>
        {{-- Where a removal goes, said before anything is removed: the site's trash and its term, or that it is final. --}}
        <p class="m-0 m-bottom-1 text-small color-on-surface-variant" data-env-files-term="{{ $files['trashKept'] ? 'trash' : 'final' }}">{{ $files['trashKept']
            ? __('larena-env::admin.files.explain_trash', ['days' => (string) ($files['trash']['days'] ?? 30)]) : __('larena-env::admin.files.explain_final') }}</p>
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
        @if ($files['atVisitors'])
            {{-- PX-91: where the person is, said in words, with what a visitor would open. --}}
            <p class="m-y-1" data-env-files-visitors role="note">
                {{ __('larena-env::admin.files.visitors_hint', ['file' => 'robots.txt', 'url' => $domain.'/robots.txt']) }}
                @if ($files['visitorsLink'] !== null)
                    <span class="color-on-surface-variant">{{ __('larena-env::admin.files.visitors_release', ['link' => $files['visitorsLink']]) }}</span>
                @endif
                <a href="{{ $files['base'] }}&amp;path=." data-env-files-root>{{ __('larena-env::admin.files.to_root') }}</a>
            </p>
        @elseif ($files['view'] === null && $files['visitors'] !== null && $files['path'] === '.')
            <p class="m-y-1"><a href="{{ $files['base'] }}&amp;path={{ rawurlencode($files['visitors']) }}" data-env-files-to-visitors>{{ __('larena-env::admin.files.to_visitors') }}</a></p>
        @endif
        @if ($files['view'] !== null)
            <p><a href="{{ $files['base'] }}&amp;path={{ rawurlencode($files['path']) }}" data-env-files-back>{{ __('larena-env::admin.files.back_to_folder') }}</a></p>
        @elseif ($files['parent'] !== null && !$files['atVisitors'])
            <p><a href="{{ $files['base'] }}&amp;path={{ rawurlencode($files['parent']) }}" data-env-files-up>{{ __('larena-env::admin.files.up') }}</a></p>
        @endif
        @if ($files['problem'] !== null)
            <p role="alert" data-env-files-problem>{{ $files['problem'] }}</p>
        @endif

        @if ($files['view'] !== null)
            @if ($files['secret'])
                {{-- PX-92: a file with passwords is shown and downloaded only when the person says so. --}}
                <p role="note" data-env-files-secret class="m-y-1"><strong>{{ __('larena-env::admin.files.secret') }}</strong> · {{ __('larena-env::admin.files.secret_note') }}</p>
                @if (!$files['revealed'] && $files['allows']['read'])
                    <p><a class="larena-button" href="{{ $files['base'] }}&amp;view={{ rawurlencode($files['view']) }}&amp;reveal=1" data-env-files-reveal>{{ __('larena-env::admin.files.secret_reveal') }}</a></p>
                @endif
            @endif
            @if ($files['image'] !== null)
                {{-- PX-96: a picture in place; its bytes are checked before they are served, sandboxed and never kept. --}}
                <figure class="m-y-1" data-env-files-image>
                    <img src="{{ $files['image'] }}" alt="{{ __('larena-env::admin.files.image_alt', ['name' => basename($files['view'])]) }}" loading="lazy" style="max-width: 100%; max-height: 70vh; height: auto; border-radius: var(--sf-radius-1, .25rem);">
                    <figcaption class="text-small color-on-surface-variant">{{ __('larena-env::admin.files.image_note') }}</figcaption>
                </figure>
            @elseif ($files['text'] !== null)
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
                        <div class="m-bottom-1">@include('larena-env::admin.partials.explanation', ['text' => __('larena-env::admin.files.save_note')])</div>
                        <div class="larena-form-actions"><button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.files.save') }}</button></div>
                    @endif
                </form>
            @elseif (!$files['secret'] || $files['revealed'])
                <p data-env-files-not-shown>{{ __('larena-env::admin.files.not_shown') }}</p>
            @endif
            @if ($files['allows']['download'])
                @if ($files['secret'])
                    <form method="get" action="{{ route('larena.env.admin.site.files.download', ['domain' => $domain], false) }}" class="m-0" data-larena-confirm-submit="{{ __('larena-env::admin.files.secret_confirm') }}" data-env-files-secret-download>
                        <input type="hidden" name="path" value="{{ $files['view'] }}">
                        <input type="hidden" name="confirm" value="secret">
                        <button type="submit" class="larena-button">{{ __('larena-env::admin.files.secret_download') }}</button>
                    </form>
                @else
                    <p><a class="larena-button" href="{{ route('larena.env.admin.site.files.download', ['domain' => $domain], false) }}?path={{ rawurlencode($files['view']) }}" data-env-files-download>{{ __('larena-env::admin.files.download') }}</a></p>
                @endif
            @endif
        @elseif ($files['folder'] !== null)
            {{-- What the folder takes: files dropped or chosen together, a folder, an empty file; each one planned first. --}}
            @if ($files['allows']['upload'])
                <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" enctype="multipart/form-data" class="larena-form larena-panel larena-panel-padded m-bottom-2" data-env-files-upload>
                    @csrf
                    <input type="hidden" name="op" value="upload">
                    <input type="hidden" name="folder" value="{{ $files['path'] }}">
                    <div class="larena-field">
                        <label for="env-files-upload">{{ __('larena-env::admin.files.upload_files') }}</label>
                        {{-- The browser's own field takes files dropped on it as well as chosen (until Framework's upload posts with a form). --}}
                        <input type="file" id="env-files-upload" name="files[]" multiple required aria-describedby="env-files-upload-note" style="min-height: 4.5rem; width: 100%; border: 1px dashed var(--sf-color-outline, currentColor); border-radius: var(--sf-radius-2, .5rem); padding: 1rem;">
                        <p id="env-files-upload-note" class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.files.upload_drop') }} {{ __('larena-env::admin.files.upload_note') }}</p>
                    </div>
                    <div class="flex flex-wrap items-cross-center g-2">
                        <label class="flex items-cross-center g-1 m-0"><input type="checkbox" name="overwrite" value="yes" style="width: auto; min-width: 1.25rem; min-height: 1.25rem;"> {{ __('larena-env::admin.files.overwrite') }}</label>
                        <button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.files.upload') }}</button>
                    </div>
                </form>
            @endif
            @if ($files['allows']['mkdir'] || $files['allows']['create'])
                <div class="flex flex-wrap g-1 m-bottom-2" data-env-files-toolbar>
                    @foreach (['mkdir' => 'new_folder', 'create' => 'new_file'] as $op => $label)
                        @continue(!$files['allows'][$op])
                        <button type="button" class="larena-button" data-sf-modal-open="env-files-{{ $op }}">{{ __('larena-env::admin.files.'.$label) }}</button>
                        <sf-modal id="env-files-{{ $op }}" position="inline-end" width="28rem" heading="{{ __('larena-env::admin.files.'.$label) }}">
                            <div data-larena-action-host>
                                <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form m-0" style="padding: 0; box-shadow: none; background: none; border: 0;" data-env-files-op="{{ $op }}" data-larena-action-form>
                                    @csrf
                                    <input type="hidden" name="op" value="{{ $op }}">
                                    <input type="hidden" name="folder" value="{{ $files['path'] }}">
                                    <div class="larena-field">
                                        <label for="env-files-{{ $op }}-name">{{ __('larena-env::admin.files.name') }}</label>
                                        <input type="text" id="env-files-{{ $op }}-name" name="name" required maxlength="255">
                                    </div>
                                    @if ($op === 'create')
                                        <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.files.new_file_note') }}</p>
                                    @endif
                                    <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                                </form>
                            </div>
                        </sf-modal>
                    @endforeach
                </div>
            @endif

            @if ($files['list'] !== null)
                @include('larena-admin::dataview.page', ['dataview' => $files['list'], 'sectionId' => 'env-site-files', 'owner' => 'larena/env',
                    'ariaLabel' => __('larena-env::admin.files.title'), 'queryUrl' => route('larena.env.admin.site', ['domain' => $domain], false),
                    'hidden' => ['tab' => 'files', 'path' => $files['path']], 'matchedCount' => count($files['folder']['entries']), 'source' => 'env_site_files'])
            @endif
            @if ($files['folder']['truncated'])
                <p data-env-files-truncated>{{ __('larena-env::admin.files.truncated') }}</p>
            @endif
            @if ($files['service'] !== [])
                {{-- PX-91: the server's entries at the site's root, folded; nothing here needs changing. --}}
                <details class="m-top-2" data-env-files-service>
                    <summary style="cursor: pointer;">{{ __('larena-env::admin.files.service', ['count' => (string) count($files['service'])]) }}</summary>
                    <p class="text-small color-on-surface-variant">{{ __('larena-env::admin.files.service_note') }}</p>
                    <ul class="m-0" style="overflow-wrap: anywhere;">
                        @foreach ($files['service'] as $entry)
                            <li data-env-file-service="{{ $entry->name }}">
                                @if ($entry->isDir())
                                    <a href="{{ $files['base'] }}&amp;path={{ rawurlencode($entry->name) }}">{{ $entry->name }}/</a>
                                @else
                                    {{ $entry->name }}
                                @endif
                                @if ($entry->type === 'link')
                                    <span class="color-on-surface-variant">→ {{ $entry->target ?? __('larena-env::admin.files.link') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($files['entryOp'] !== null)
                {{-- A row's «⋯» change (rename, pack, unpack): its form in the side panel over the folder, planned there. --}}
                <sf-modal id="env-files-entry-op" position="inline-end" width="28rem" heading="{{ __('larena-env::admin.files.operations.'.$files['entryOp']['op']) }} · {{ $files['entryOp']['name'] }}" autoload>
                    <div data-larena-action-host>
                        <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="larena-form m-0" style="padding: 0; box-shadow: none; background: none; border: 0;" data-env-files-op="{{ $files['entryOp']['op'] }}" data-larena-action-form>
                            @csrf
                            <input type="hidden" name="op" value="{{ $files['entryOp']['op'] }}">
                            <input type="hidden" name="path" value="{{ $files['entryOp']['path'] }}">
                            @if ($files['entryOp']['op'] === 'rename')
                                <div class="larena-field">
                                    <label for="env-files-new-name">{{ __('larena-env::admin.files.new_name') }}</label>
                                    <input type="text" id="env-files-new-name" name="name" value="{{ $files['entryOp']['name'] }}" required maxlength="255">
                                </div>
                            @elseif ($files['entryOp']['op'] === 'archive')
                                <div class="larena-field">
                                    <label for="env-files-format">{{ __('larena-env::admin.files.format') }}</label>
                                    <select id="env-files-format" name="format">
                                        @foreach ($files['formats'] as $format)
                                            <option value="{{ $format }}">{{ $format }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @else
                                <div class="larena-field">
                                    <label for="env-files-extract-to">{{ __('larena-env::admin.files.extract_to') }}</label>
                                    <input type="text" id="env-files-extract-to" name="folder" value="{{ $files['path'] }}" required maxlength="1024">
                                </div>
                                <label class="flex items-cross-center g-1 text-small"><input type="checkbox" name="overwrite" value="yes" style="width: auto; min-width: 1.25rem; min-height: 1.25rem;"> {{ __('larena-env::admin.files.extract_overwrite') }}</label>
                            @endif
                            <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                        </form>
                    </div>
                </sf-modal>
            @endif

            @if ($files['trash'] !== null)
                {{-- PX-95: what was removed, put back or removed for good, each the server's plan. --}}
                <details class="m-top-2" data-env-files-trash @if ($files['trashOpen'] ?? false) open @endif>
                    <summary style="cursor: pointer;">{{ __('larena-env::admin.files.trash.summary', ['count' => (string) count($files['trash']['entries'])]) }}</summary>
                    <p class="text-small color-on-surface-variant">{{ __('larena-env::admin.files.trash.kept', ['days' => (string) $files['trash']['days']]) }}</p>
                    @if ($files['trashView'] !== null)
                        @include('larena-admin::dataview.page', ['dataview' => $files['trashView'], 'sectionId' => 'env-site-trash', 'owner' => 'larena/env',
                            'ariaLabel' => __('larena-env::admin.files.trash.title'), 'queryUrl' => route('larena.env.admin.site', ['domain' => $domain], false),
                            'hidden' => ['tab' => 'files', 'path' => $files['path'], 'trash' => '1'], 'matchedCount' => count($files['trash']['entries']), 'source' => 'env_site_trash'])
                        @if ($files['trashEmpty'])
                            <form method="post" action="{{ route('larena.env.admin.site.files.plan', ['domain' => $domain], false) }}" class="m-top-1" data-env-files-trash-empty>
                                @csrf
                                <input type="hidden" name="op" value="trash-empty">
                                <button type="submit" class="larena-button larena-button-danger">{{ __('larena-env::admin.files.trash.empty_all') }}</button>
                            </form>
                        @endif
                    @else
                        <p data-env-files-trash-none>{{ __('larena-env::admin.files.trash.empty') }}</p>
                    @endif
                </details>
            @endif
        @endif
    @endif
</section>
