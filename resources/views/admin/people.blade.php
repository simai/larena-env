@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.people.title'))
@section('heading', __('larena-env::admin.people.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.people.description'))
{{-- «Пригласить человека», the list's one filled button, beside the heading (C301). --}}
@section('actions'){{ ($peopleView ?? null)['heading']->actions ?? '' }}@endsection

@section('content')
    @include('larena-env::admin.partials.notes')
    @if (($reasonText ?? null) !== null)
        <p class="m-0 m-bottom-1 color-error" role="alert">{{ $reasonText }}</p>
    @endif

    {{-- «Люди» (roles study part 7, PX-67…PX-70): one list of the panel's people — what each may do in words, the last
         sign-in, «Посмотреть как он» and «⋯» — and «Пригласить человека» as the list's one filled button. --}}
    <div data-env-people>
        @if (($peopleView ?? null) !== null)
            @include('larena-admin::dataview.page', ['dataview' => $peopleView, 'sectionId' => 'env-people', 'owner' => 'larena/env',
                'ariaLabel' => __('larena-env::admin.people.list'), 'queryUrl' => route('larena.env.admin.people', [], false),
                'matchedCount' => $peopleCount ?? 0, 'source' => 'env_people'])
        @endif
    </div>

    @if ($canOperate ?? true)
        {{-- «Пригласить человека»: one form in the side panel (PX-67) — name, email, the first password, what they may do
             (sf-input-choice), and for «Свои сайты» their server account, new or one already on the server. The plan
             in words comes next; nothing changes before «Пригласить». --}}
        <sf-modal id="env-invite" position="inline-end" width="30rem" heading="{{ __('larena-env::admin.people.invite.heading') }}" @if ($inviteOpen ?? false) autoload @endif data-env-invite>
            <form method="post" action="{{ route('larena.env.admin.people.invite.plan', [], false) }}" class="m-0" autocomplete="off" data-env-invite-form>
                @csrf
                <div class="flex flex-column g-2">
                    <sf-input name="name" label="{{ __('larena-env::admin.people.invite.name') }}" default-value="{{ ($inviteOld ?? [])['name'] ?? '' }}" autocomplete="off" required></sf-input>
                    <sf-input name="email" input-type="email" label="{{ __('larena-env::admin.people.invite.email') }}" hint="{{ __('larena-env::admin.people.invite.email_hint') }}" default-value="{{ ($inviteOld ?? [])['email'] ?? '' }}" autocomplete="off" required></sf-input>
                    <sf-input name="password" input-type="password" label="{{ __('larena-env::admin.people.invite.password') }}" hint="{{ __('larena-env::admin.people.invite.password_hint') }}" autocomplete="new-password" minlength="12" required></sf-input>
                    <sf-input-choice name="level" label="{{ __('larena-env::admin.people.invite.level') }}" layout="list" required value="{{ (($inviteOld ?? [])['level'] ?? 'own') === 'all' ? 'all' : 'own' }}" data-env-invite-level
                        items="{{ json_encode(array_map(static fn (string $level): array => ['value' => $level, 'label' => __('larena-env::admin.people.invite.levels.'.$level.'.label'), 'caption' => __('larena-env::admin.people.invite.levels.'.$level.'.caption')], ['own', 'all']), JSON_UNESCAPED_UNICODE) }}"></sf-input-choice>
                    <fieldset class="m-0 p-0 flex flex-column g-2" style="border: 0;" data-env-invite-own>
                        <legend class="m-0 m-bottom-1 text-medium">{{ __('larena-env::admin.people.invite.own_title') }}</legend>
                        @if ($owners !== [])
                            <sf-dropdown name="account" label="{{ __('larena-env::admin.people.invite.account') }}" value="{{ ($inviteOld ?? [])['account'] ?? 'new' }}"
                                options="{{ json_encode(array_merge(($canCreateAccount ?? false) ? [['value' => 'new', 'text' => __('larena-env::admin.people.invite.account_new')]] : [],
                                    array_map(static fn (string $owner): array => ['value' => $owner, 'text' => __('larena-env::admin.people.invite.account_existing', ['name' => $owner, 'count' => (string) (($ownerSites ?? [])[$owner] ?? 0)])], $owners)), JSON_UNESCAPED_UNICODE) }}"></sf-dropdown>
                        @else
                            <input type="hidden" name="account" value="new">
                        @endif
                        @if ($canCreateAccount ?? false)
                            <sf-input name="account_name" label="{{ __('larena-env::admin.people.invite.account_name') }}" hint="{{ __('larena-env::admin.people.invite.account_name_hint') }}" default-value="{{ ($inviteOld ?? [])['account_name'] ?? '' }}" pattern="[a-z][a-z0-9_\-]{0,31}" autocomplete="off" spellcheck="false"></sf-input>
                        @endif
                        @if ($secretFilesKept ?? false)
                            <sf-checkbox name="secret_files" value="yes" label="{{ __('larena-env::admin.people.secret_files') }}" description="{{ __('larena-env::admin.people.secret_files_hint') }}" @if ((($inviteOld ?? [])['secret'] ?? false) === true) checked @endif data-env-invite-secret></sf-checkbox>
                        @endif
                    </fieldset>
                </div>
                <div class="flex flex-wrap g-1 m-top-2">
                    <sf-button native-type="submit" appearance="filled" text="{{ __('larena-env::admin.people.invite.show_plan') }}" data-env-invite-plan></sf-button>
                </div>
            </form>
        </sf-modal>
    @endif

    @if ($owners !== [])
        {{-- The server's accounts (PX-68: «Аккаунт на сервере», the one name): whose each is, its disk, a key, blocking and
             removing an empty one. Folded: a person's account is said on their row and card. --}}
        <details class="larena-panel" style="margin: 1rem 0;" data-env-owners>
            <summary class="p-2 text-medium" style="cursor: pointer;">{{ __('larena-env::admin.people.spaces') }} · {{ count($owners) }}</summary>
            <p class="m-0 p-x-2 p-bottom-1 color-on-surface-variant text-small">{{ __('larena-env::admin.people.spaces_note') }}</p>
            @if ($canBlock ?? false)
                <div class="m-0 p-x-2 p-bottom-1 color-on-surface-variant text-small">@include('larena-env::admin.partials.explanation', ['text' => __('larena-env::admin.people.block_explain')])</div>
            @endif
            <ul class="m-0 p-0" style="list-style: none;">
                @foreach ($owners as $owner)
                    <li class="flex flex-wrap items-cross-center g-2 p-x-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-owner="{{ $owner }}" data-env-owner-blocked="{{ in_array($owner, $blockedOwners ?? [], true) ? 'yes' : 'no' }}">
                        <div class="grow min-w-0" style="flex-basis: 12rem;">
                            <div class="flex flex-wrap items-cross-center g-1">
                                <span class="mono" style="overflow-wrap: anywhere;">{{ $owner }}</span>
                                @if (in_array($owner, $blockedOwners ?? [], true))
                                    <sf-badge status="error" appearance="tonal" text="{{ __('larena-env::admin.people.blocked') }}"></sf-badge>
                                @endif
                            </div>
                            <div class="text-small color-on-surface-variant">{{ array_key_exists($owner, $ownerPeople ?? []) ? __('larena-env::admin.people.owner_person', ['name' => ($ownerPeople ?? [])[$owner]]) : __('larena-env::admin.people.owner_nobody') }}@if (isset($ownerDisk[$owner])) · {{ __('larena-env::admin.people.disk', ['size' => \Larena\Env\Server\Bytes::human($ownerDisk[$owner])]) }}@endif</div>
                        </div>
                        @if ($canOperate ?? true)
                            @if ($canAddKey ?? false)
                                <button type="button" class="larena-button larena-control-floor" data-sf-modal-open="env-owner-key-{{ $owner }}" data-env-owner-add-key="{{ $owner }}">{{ __('larena-env::admin.people.add_key') }}</button>
                                <sf-modal id="env-owner-key-{{ $owner }}" position="inline-end" width="28rem" heading="{{ __('larena-env::admin.people.add_key') }}">
                                    <form method="post" action="{{ route('larena.env.admin.people.owners.plan', [], false) }}" class="larena-form m-0" style="padding: 0; box-shadow: none; background: none; border: 0; border-radius: 0;">
                                        @csrf
                                        <input type="hidden" name="action" value="add-key">
                                        <input type="hidden" name="name" value="{{ $owner }}">
                                        <div class="larena-field">
                                            <label for="env-owner-key-{{ $owner }}-pubkey">{{ __('larena-env::admin.fields.pubkey') }}</label>
                                            <textarea id="env-owner-key-{{ $owner }}-pubkey" name="pubkey" rows="3" required></textarea>
                                        </div>
                                        <div class="larena-form-actions">
                                            <button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button>
                                        </div>
                                    </form>
                                </sf-modal>
                            @endif
                            @if (($canBlock ?? false) && !array_key_exists($owner, $ownerPeople ?? []))
                                <form method="post" action="{{ route('larena.env.admin.people.owners.plan', [], false) }}" class="m-0">
                                    @csrf
                                    <input type="hidden" name="action" value="{{ in_array($owner, $blockedOwners ?? [], true) ? 'unblock' : 'block' }}">
                                    <input type="hidden" name="name" value="{{ $owner }}">
                                    <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.people.'.(in_array($owner, $blockedOwners ?? [], true) ? 'unblock' : 'owner_block')) }}</button>
                                </form>
                            @endif
                            @if (($canRemove ?? false) && (($ownerSites ?? [])[$owner] ?? 0) === 0 && !array_key_exists($owner, $ownerPeople ?? []))
                                <form method="post" action="{{ route('larena.env.admin.people.owners.plan', [], false) }}" class="m-0" data-env-owner-remove="{{ $owner }}">
                                    @csrf
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="name" value="{{ $owner }}">
                                    <button type="submit" class="larena-button larena-button-danger larena-control-floor">{{ __('larena-env::admin.people.remove') }}</button>
                                </form>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
