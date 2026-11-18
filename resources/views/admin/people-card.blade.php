@extends('larena-admin::layouts.app')

@section('title', $person['name'].' — '.__('larena-env::admin.people.title'))
@section('heading', $person['name'])
@section('eyebrow', __('larena-env::admin.people.heading'))
@section('description', $canSaid)

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- A person's card (roles study part 7, PX-79): what they may do in words, their sites, their server account, keys
         and the last sign-in; «Посмотреть как он», blocking, and what they may do changed in its side panel. The person
         signed in reads «вы», never their own address. --}}
    @php
        $text = static fn (string $key, array $replace = []): string => (string) __('larena-env::admin.people.'.$key, $replace);
        $items = [
            ['id' => 'can', 'label' => $text('card.can'), 'value' => $can, 'hint' => $canSaid],
            ['id' => 'status', 'label' => $text('card.status'), 'value' => $person['blocked'] ? $text('state.blocked') : $text('state.ok'),
                'status' => $person['blocked'] ? 'warning' : 'success'],
        ];
        if ($person['level'] === 'own') {
            $items[] = ['id' => 'sites', 'label' => $text('card.sites'), 'value' => $person['sites'] === [] ? $text('card.no_sites') : implode(', ', $person['sites'])];
            $items[] = ['id' => 'account', 'label' => $text('card.account'), 'value' => $person['owner'] ?? $text('card.no_account'), 'mono' => $person['owner'] !== null];
            if ($person['owner'] !== null) {
                $items[] = ['id' => 'keys', 'label' => $text('card.keys'), 'value' => $text('card.keys_value')];
            }
        }
        if ($secretFilesKept) {
            $items[] = ['id' => 'secret', 'label' => $text('card.secret'), 'value' => $text('invite.fact.'.($person['secret'] ? 'secret_yes' : 'secret_no'))];
        }
        $items[] = ['id' => 'last', 'label' => $text('card.last'), 'value' => $last];
    @endphp
    <section class="larena-panel larena-panel-padded" aria-labelledby="env-person-summary" data-env-person-card="{{ $person['id'] }}" data-env-person-level="{{ $person['level'] }}">
        <h2 id="env-person-summary" class="text-2 m-0 m-bottom-1">{{ $text('card.summary') }}</h2>
        @if ($self)
            <p class="m-0 m-bottom-1 color-on-surface-variant">{{ $text('card.self') }}</p>
        @endif
        @include('larena-env::admin.partials.facts', ['items' => $items, 'label' => $text('card.summary'), 'mark' => 'person'])
        @if ($person['blocked'])
            <p class="m-0 m-top-1 color-on-surface-variant text-small">{{ $text('card.blocked_note') }}</p>
        @endif

        @if ($canOperate && !$self)
            <div class="flex flex-wrap g-1 m-top-2" data-env-person-actions>
                @if ($person['owner'] !== null && !$person['blocked'])
                    <form method="post" action="{{ route('larena.env.admin.people.act', [], false) }}" class="m-0">
                        @csrf
                        <input type="hidden" name="subject" value="{{ $person['subject'] }}">
                        <sf-button native-type="submit" appearance="outline" scheme="on-surface" icon-start="visibility" text="{{ __('larena-env::admin.people.act_as') }}" data-env-person-act></sf-button>
                    </form>
                @endif
                @if ($person['level'] === 'own')
                    <sf-button appearance="outline" scheme="on-surface" text="{{ $text('card.change') }}" data-sf-modal-open="env-person-change" data-env-person-change></sf-button>
                @endif
                @if ($canAddKey && $person['owner'] !== null)
                    <sf-button appearance="outline" scheme="on-surface" text="{{ __('larena-env::admin.people.add_key') }}" data-sf-modal-open="env-person-key" data-env-person-key></sf-button>
                @endif
                <sf-button appearance="outline" scheme="on-surface" href="{{ route('larena.env.admin.people.block', ['person' => (string) $person['id']], false) }}"
                    text="{{ $text('block.'.($person['blocked'] ? 'unopen' : 'open')) }}" data-env-person-block></sf-button>
            </div>
        @endif
    </section>

    @if ($canOperate && !$self && $person['level'] === 'own')
        {{-- What the person may do: their server account and files with passwords (q-4997ae4e93), in the side panel. --}}
        <sf-modal id="env-person-change" position="inline-end" width="28rem" heading="{{ $text('card.change_heading', ['name' => $person['name']]) }}">
            <form method="post" action="{{ route('larena.env.admin.people.assign', [], false) }}" class="m-0" data-env-person-change-form>
                @csrf
                <input type="hidden" name="subject" value="{{ $person['subject'] }}">
                <div class="flex flex-column g-2">
                    <sf-dropdown name="owner" label="{{ $text('card.account_choose') }}" value="{{ $person['owner'] ?? '' }}"
                        options="{{ json_encode(array_merge([['value' => '', 'text' => $text('card.account_none')]],
                            array_map(static fn (string $owner): array => ['value' => $owner, 'text' => __('larena-env::admin.people.invite.account_existing', ['name' => $owner, 'count' => (string) ($ownerSites[$owner] ?? 0)])],
                                array_values(array_unique(array_merge($owners, $person['owner'] !== null ? [$person['owner']] : []))))), JSON_UNESCAPED_UNICODE) }}"></sf-dropdown>
                    @if ($secretFilesKept)
                        <sf-checkbox name="secret_files" value="yes" label="{{ __('larena-env::admin.people.secret_files') }}" description="{{ __('larena-env::admin.people.secret_files_hint') }}" @if ($person['secret']) checked @endif data-env-person-secret-files></sf-checkbox>
                    @endif
                </div>
                <div class="flex flex-wrap g-1 m-top-2">
                    <sf-button native-type="submit" appearance="filled" text="{{ $text('card.save') }}"></sf-button>
                </div>
            </form>
        </sf-modal>
    @endif

    @if ($canOperate && $canAddKey && $person['owner'] !== null)
        <sf-modal id="env-person-key" position="inline-end" width="28rem" heading="{{ __('larena-env::admin.people.add_key') }}">
            <form method="post" action="{{ route('larena.env.admin.people.owners.plan', [], false) }}" class="larena-form m-0" style="padding: 0; box-shadow: none; background: none; border: 0; border-radius: 0;">
                @csrf
                <input type="hidden" name="action" value="add-key">
                <input type="hidden" name="name" value="{{ $person['owner'] }}">
                <div class="larena-field">
                    <label for="env-person-key-pubkey">{{ __('larena-env::admin.fields.pubkey') }}</label>
                    <textarea id="env-person-key-pubkey" name="pubkey" rows="3" required></textarea>
                </div>
                <div class="larena-form-actions">
                    <button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button>
                </div>
            </form>
        </sf-modal>
    @endif
@endsection
