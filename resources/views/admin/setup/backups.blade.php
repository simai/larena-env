{{-- An S3 storage for copies away from the server, connected right here: the server checks the keys before keeping it. --}}
@if ($storages !== [])
    <ul class="m-0 p-0" style="list-style: none;" data-env-setup-now>
        @foreach ($storages as $storage)
            <li class="p-y-1 border-top-1 border-top-solid border-outline-variant"><b>{{ $storage->name }}</b>@if ($storage->bucket !== null) · {{ $storage->bucket }}@endif</li>
        @endforeach
    </ul>
@endif
@if ($phase === 'form')
    <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.storages.add_note') }}</p>
    <sf-dropdown name="provider" label="{{ __('larena-env::admin.storages.fields.provider') }}" value="yandex" required
        options='@json(array_map(static fn (string $one): array => ['value' => $one, 'text' => __('larena-env::admin.storages.providers.'.$one)], array_keys(\Larena\Env\Backup\BackupStorage::PROVIDERS)))'></sf-dropdown>
    <sf-input name="name" label="{{ __('larena-env::admin.storages.fields.name') }}" hint="{{ __('larena-env::admin.storages.fields.name_hint') }}" pattern="[a-z0-9_-]+" autocomplete="off" required></sf-input>
    <sf-input name="bucket" label="{{ __('larena-env::admin.storages.fields.bucket') }}" autocomplete="off" required></sf-input>
    <sf-input name="access_key" label="{{ __('larena-env::admin.storages.fields.access_key') }}" autocomplete="off" required></sf-input>
    <sf-input name="secret_key" label="{{ __('larena-env::admin.storages.fields.secret_key') }}" input-type="password" autocomplete="off" required></sf-input>
    <details>
        <summary class="color-primary" style="cursor: pointer;">{{ __('larena-env::admin.more') }}</summary>
        <div class="flex flex-column g-2 m-t-2">
            <sf-input name="prefix" label="{{ __('larena-env::admin.storages.fields.prefix') }}" autocomplete="off"></sf-input>
            <sf-input name="endpoint" label="{{ __('larena-env::admin.storages.fields.endpoint') }}" hint="{{ __('larena-env::admin.storages.fields.endpoint_hint') }}" autocomplete="off"></sf-input>
            <sf-input name="region" label="{{ __('larena-env::admin.storages.fields.region') }}" autocomplete="off"></sf-input>
            <sf-input name="keep" label="{{ __('larena-env::admin.storages.fields.keep') }}" inputmode="numeric" pattern="[0-9]{1,2}" default-value="30"></sf-input>
        </div>
    </details>
    <input type="hidden" name="default" value="1">
@elseif ($state === 'done')
    <div><sf-button appearance="link" size="1/2" text="{{ __('larena-env::admin.setup.backups.another') }}" href="{{ route('larena.env.admin.setup', ['step' => 'backups', 'change' => '1'], false) }}"></sf-button></div>
@elseif ($storages === [])
    <p class="m-0" data-env-setup-unsupported>{{ __('larena-env::admin.setup.unsupported') }}</p>
@endif
