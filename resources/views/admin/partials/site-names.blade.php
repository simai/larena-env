{{-- The names a site answers on, inside «Домены и HTTPS» (owner decision 2026-11-07, simai-env 1.53.0): one row per name,
     an extra name added or removed through the server's plan as every action of the card. The main domain and every
     subdomain of a host-mode site are only read here. --}}
<section class="larena-panel larena-panel-padded" data-env-names="{{ $siteNames->names === null ? 'failed' : 'ok' }}" aria-labelledby="env-site-names">
    <h2 id="env-site-names" class="text-2">{{ __('larena-env::admin.names.title') }}</h2>
    @if ($siteNames->names === null)
        <p role="alert" data-env-names-problem>{{ $namesReasonText ?? __('larena-env::admin.names.none') }}</p>
    @else
        <p class="color-on-surface-variant">{{ __('larena-env::admin.names.explain') }}</p>
        <div class="larena-table-scroll">
            <table class="larena-table larena-table-stack" data-env-names-rows>
                <thead>
                    <tr>
                        <th scope="col">{{ __('larena-env::admin.names.name') }}</th>
                        <th scope="col">{{ __('larena-env::admin.names.kind') }}</th>
                        <th scope="col">{{ __('larena-env::admin.names.mode') }}</th>
                        <th scope="col">{{ __('larena-env::admin.names.dns') }}</th>
                        <th scope="col">{{ __('larena-env::admin.names.https') }}</th>
                        <th scope="col"><span class="sr-only">{{ __('larena-env::admin.names.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($siteNames->names->names as $row)
                        <tr data-env-name="{{ $row->name }}" data-env-name-kind="{{ $row->kind }}" data-env-name-mode="{{ $row->mode }}"
                            data-env-name-dns="{{ $row->pointsHere === null ? 'unknown' : ($row->pointsHere ? 'here' : 'elsewhere') }}" data-env-name-https="{{ $row->https ? 'yes' : 'no' }}">
                            <td data-label="{{ __('larena-env::admin.names.name') }}"><span class="mono" style="overflow-wrap: anywhere;">{{ $row->name }}</span></td>
                            <td data-label="{{ __('larena-env::admin.names.kind') }}">{{ __('larena-env::admin.names.kinds.'.$row->kind) }}</td>
                            <td data-label="{{ __('larena-env::admin.names.mode') }}">{{ __('larena-env::admin.names.modes.'.$row->mode) }}</td>
                            <td data-label="{{ __('larena-env::admin.names.dns') }}">
                                <sf-badge appearance="tonal" status="{{ $row->pointsHere === null ? 'neutral' : ($row->pointsHere ? 'success' : 'warning') }}"
                                    text="{{ __('larena-env::admin.names.dns_states.'.($row->pointsHere === null ? 'unknown' : ($row->pointsHere ? 'here' : 'elsewhere'))) }}"></sf-badge>
                                @if ($row->pointsHere === false && $row->addresses !== [])
                                    <span class="text-small color-on-surface-variant" style="overflow-wrap: anywhere;" data-env-name-addresses>{{ __('larena-env::admin.names.dns_addresses', ['addresses' => implode(', ', $row->addresses)]) }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('larena-env::admin.names.https') }}">
                                <sf-badge appearance="tonal" status="{{ $row->https ? 'success' : 'neutral' }}" text="{{ __('larena-env::admin.names.https_states.'.($row->https ? 'yes' : 'no')) }}"></sf-badge>
                            </td>
                            <td data-label="{{ __('larena-env::admin.names.actions') }}">
                                @if ($row->removable() && ($namesAllows['remove'] ?? false))
                                    <form method="post" action="{{ route('larena.env.admin.site.plan', ['domain' => $domain], false) }}" class="m-0" data-env-name-remove="{{ $row->name }}">
                                        @csrf
                                        <input type="hidden" name="operation" value="{{ \Larena\Env\Operations\SiteOperation::NameRemove->value }}">
                                        <input type="hidden" name="name" value="{{ $row->name }}">
                                        <sf-button appearance="outline" size="1/2" native-type="submit" text="{{ __('larena-env::admin.names.remove') }}"></sf-button>
                                    </form>
                                @elseif ($row->kind === \Larena\Env\Server\SiteName::WILDCARD)
                                    <span class="text-small color-on-surface-variant" data-env-name-wildcard>{{ __('larena-env::admin.names.wildcard_note') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($namesAllows['add'] ?? false)
            @if ($siteNames->names->full())
                <p data-env-names-full>{{ __('larena-env::admin.names.full', ['count' => (string) \Larena\Env\Server\SiteNames::MOST]) }}</p>
            @else
                {{-- What the server suggests (www.<domain> while it is not a name yet) is one click to its plan: sent to the
                     main domain, checked in DNS, as most sites want www. --}}
                @foreach ($siteNames->names->suggest as $suggested)
                    <form method="post" action="{{ route('larena.env.admin.site.plan', ['domain' => $domain], false) }}" class="flex flex-wrap items-cross-center g-1 m-top-1" data-env-name-suggest="{{ $suggested }}">
                        @csrf
                        <input type="hidden" name="operation" value="{{ \Larena\Env\Operations\SiteOperation::NameAdd->value }}">
                        <input type="hidden" name="name" value="{{ $suggested }}">
                        <input type="hidden" name="mode" value="{{ \Larena\Env\Server\SiteName::REDIRECT }}">
                        <input type="hidden" name="check-dns" value="yes">
                        <sf-button appearance="outline" size="1" native-type="submit" text="{{ __('larena-env::admin.names.suggest', ['name' => $suggested]) }}"></sf-button>
                        <span class="text-small color-on-surface-variant">{{ __('larena-env::admin.names.suggest_note', ['name' => $suggested]) }}</span>
                    </form>
                @endforeach
                <div class="flex flex-wrap g-1 m-top-1">
                    <button type="button" class="larena-button" data-sf-modal-open="env-name-add" data-env-name-add-open>{{ __('larena-env::admin.names.add') }}</button>
                </div>
                <sf-modal id="env-name-add" position="inline-end" width="28rem" heading="{{ __('larena-env::admin.names.add') }}">
                    <form method="post" action="{{ route('larena.env.admin.site.plan', ['domain' => $domain], false) }}" class="larena-form m-0" style="padding: 0; box-shadow: none; background: none; border: 0; border-radius: 0;" data-env-name-add>
                        @csrf
                        <input type="hidden" name="operation" value="{{ \Larena\Env\Operations\SiteOperation::NameAdd->value }}">
                        <div class="flex flex-column g-2">
                            <sf-input name="name" label="{{ __('larena-env::admin.names.fields.name') }}" hint="{{ __('larena-env::admin.names.fields.name_hint', ['domain' => $domain]) }}" autocomplete="off" spellcheck="false" maxlength="253" required></sf-input>
                            <sf-dropdown name="mode" label="{{ __('larena-env::admin.names.fields.mode') }}" value="{{ \Larena\Env\Server\SiteName::REDIRECT }}" required
                                options="{{ json_encode(array_map(static fn (string $one): array => ['value' => $one, 'text' => __('larena-env::admin.names.modes.'.$one)], [\Larena\Env\Server\SiteName::REDIRECT, \Larena\Env\Server\SiteName::SERVE]), JSON_UNESCAPED_UNICODE) }}"></sf-dropdown>
                            <div class="larena-field" data-env-switch="check-dns">
                                <input type="hidden" name="check-dns" value="no">
                                <label class="flex items-cross-center g-1" for="env-name-add-check-dns">
                                    <input type="checkbox" id="env-name-add-check-dns" name="check-dns" value="yes" checked>
                                    <span>{{ __('larena-env::admin.names.fields.check_dns') }}</span>
                                </label>
                            </div>
                            <p class="m-0 text-small color-on-surface-variant" data-env-name-add-note>{{ __('larena-env::admin.names.add_note') }}</p>
                        </div>
                        <div class="larena-form-actions">
                            <button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button>
                        </div>
                    </form>
                </sf-modal>
            @endif
        @endif
    @endif
</section>
