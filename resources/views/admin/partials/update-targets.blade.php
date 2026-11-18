{{-- simai-env and the panel's own build, in the order they are updated (simai-env pins the panel's version): each with
     its versions always («Установлена X · последняя Y · проверено …», owner 2026-11-18), its state, what is new and what
     restarts under «Подробнее», and «Обновить» through «Действие с планом» (larena/admin AdminPlannedAction): the plan,
     the confirmation and the run with its steps in a side panel, which waits through the panel's restart and shows how
     it ended. Shown on «Обновления» among the panel's settings and at the head of «Программы и обновления». --}}
@if (!$supported)
    <section class="larena-panel larena-empty" data-env-update="unsupported">
        <p>{{ __('larena-env::admin.update.unsupported') }}</p>
    </section>
@else
    @php($envWaits = ($plans['env'] ?? null)?->updateAvailable === true)
    <div class="grid g-2 grid-col-1 xl:grid-col-2" style="align-items: start;">
    @foreach ($plans as $target => $update)
        @php($installed = $update?->current ?? (($versions ?? [])[$target]['installed'] ?? null) ?? ($target === 'panel' ? ($panelBuild ?? null) : null))
        @php($latest = $update?->available ?? (($versions ?? [])[$target]['pinned'] ?? null))
        <section class="larena-panel p-2" style="margin: 0;" data-env-update="{{ $target }}" data-env-update-state="{{ $update === null ? 'unknown' : ($update->blocked ?? ($update->updateAvailable ? 'available' : 'current')) }}" aria-labelledby="env-update-{{ $target }}">
            <div class="flex flex-wrap items-cross-center content-main-between g-1 m-bottom-1">
                <h2 id="env-update-{{ $target }}" class="m-0 text-2">{{ __('larena-env::admin.update.targets.'.$target) }}</h2>
                <sf-badge appearance="tonal" status="{{ $update === null ? 'error' : ($update->blocked !== null ? 'warning' : ($update->updateAvailable ? 'info' : 'success')) }}"
                    text="{{ __('larena-env::admin.update.states.'.($update === null ? 'unknown' : ($update->blocked !== null ? 'blocked' : ($update->updateAvailable ? 'available' : 'current')))) }}"></sf-badge>
            </div>
            {{-- The versions, always: also when there is nothing to update, also when the plan was not read. --}}
            <p class="m-0 m-bottom-1" data-env-update-versions="{{ $target }}">
                {{ __('larena-env::admin.update.installed') }} <b data-env-update-installed>{{ $installed ?? __('larena-env::admin.update.unknown_version') }}</b>
                · {{ mb_strtolower(__('larena-env::admin.update.latest')) }} <b data-env-update-latest>{{ $latest ?? __('larena-env::admin.update.unknown_version') }}</b>
                · {{ mb_strtolower(__('larena-env::admin.update.checked')) }}
                @if ($update?->checkedAt !== null)
                    <sf-time datetime="{{ gmdate('Y-m-d\TH:i:s\Z', $update->checkedAt) }}" format="datetime" data-env-update-checked></sf-time>
                @else
                    <span data-env-update-checked>{{ __('larena-env::admin.update.never') }}</span>
                @endif
            </p>
            @if ($update === null)
                <p class="m-0" role="alert">{{ __('larena-env::admin.update.unreadable') }}</p>
            @else
                @if ($update->blocked !== null)
                    <p data-env-update-blocked role="note">{{ __('larena-env::admin.update.blocked.'.$update->blocked) }}</p>
                @elseif ($update->updateAvailable)
                    <p data-env-update-summary>{{ ($update->current === null ? __('larena-env::admin.update.summary_new', ['available' => $update->available ?? '—']) : __('larena-env::admin.update.summary', ['current' => $update->current, 'available' => $update->available ?? '—'])) }}</p>
                @else
                    <p data-env-update-summary class="color-on-surface-variant">{{ __('larena-env::admin.update.up_to_date', ['current' => $installed ?? '—']) }}</p>
                @endif
                @if ($target === 'env')
                    <p class="text-small color-on-surface-variant" role="note" data-env-update-env-not-panel>{{ __('larena-env::admin.update.env_not_panel') }}</p>
                @endif
                {{-- What is new and what restarts: open while an update waits, folded otherwise. --}}
                <details class="m-y-1" data-env-update-details="{{ $target }}" @if ($update->updateAvailable) open @endif>
                    <summary class="color-primary" style="cursor: pointer;">{{ __('larena-env::admin.update.details') }}</summary>
                    @include('larena-env::admin.partials.update-plan', ['update' => $update])
                </details>
                @if ($update->updateAvailable && $canUpdate)
                    @if ($target === 'panel' && $envWaits)
                        {{-- The order: simai-env first, it pins the panel's version. --}}
                        <p class="m-0 m-top-1" role="note" data-env-update-env-first>{{ __('larena-env::admin.update.env_first') }}</p>
                        <p class="m-0 m-top-1"><button type="button" class="larena-button" disabled data-env-update-open="{{ $target }}">{{ __('larena-env::admin.update.run') }}</button></p>
                    @else
                        @if ($target === 'panel')
                            <p class="m-0 m-top-1 text-small color-on-surface-variant" role="note" data-env-update-panel-restarts>{{ __('larena-env::admin.update.panel_restarts') }}</p>
                        @endif
                        <p class="m-0 m-top-2"><button type="button" class="larena-button larena-button-primary" data-sf-modal-open="env-update-{{ $target }}" data-env-update-open="{{ $target }}">{{ __('larena-env::admin.update.run') }}</button></p>
                        {{-- «Действие с планом»: the server's plan of the update asked at once in the side panel; its acceptance
                             starts the job, whose steps follow in place (sf-progress-run). It cannot be stopped. --}}
                        <sf-modal id="env-update-{{ $target }}" position="inline-end" width="28rem" heading="{{ __('larena-env::admin.update.plan_heading.'.$target) }}">
                        <div data-larena-action-host>
                        <form method="post" action="{{ route('larena.env.admin.update.plan', [], false) }}" class="m-0" data-env-update-plan="{{ $target }}" data-larena-action-form data-larena-action-auto>
                            @csrf
                            <input type="hidden" name="target" value="{{ $target }}">
                            <button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.update.plan') }}</button>
                        </form>
                        </div>
                        </sf-modal>
                    @endif
                @endif
            @endif
        </section>
    @endforeach
    </div>
@endif
