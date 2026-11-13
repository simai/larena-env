{{-- Where the server writes when something goes wrong: Telegram or a webhook, set here, then a test message. --}}

@if ($channel === null && $phase !== 'form')
    <p class="m-0" data-env-setup-unsupported>{{ __('larena-env::admin.setup.unsupported') }}</p>
@else
    @if (in_array($channel['kind'] ?? null, ['webhook', 'telegram'], true))
        <p class="m-0" data-env-setup-now>{{ __('larena-env::admin.alerts.now') }}: <b>{{ __('larena-env::admin.alerts.kinds.'.$channel['kind']) }}</b>
            @if (is_string($channel['target'] ?? null)) · <span style="overflow-wrap: anywhere;">{{ $channel['target'] }}</span>@endif</p>
    @endif
    @if ($phase === 'form')
        <sf-dropdown name="kind" label="{{ __('larena-env::admin.alerts.kind') }}" value="{{ ($channel['kind'] ?? null) === 'webhook' ? 'webhook' : 'telegram' }}" required
            options="{{ json_encode([['value' => 'telegram', 'text' => __('larena-env::admin.alerts.kinds.telegram')], ['value' => 'webhook', 'text' => __('larena-env::admin.alerts.kinds.webhook')]], JSON_UNESCAPED_UNICODE) }}"></sf-dropdown>
        <sf-input name="target" label="{{ __('larena-env::admin.alerts.target') }}" default-value="{{ is_string($channel['target'] ?? null) ? $channel['target'] : '' }}" autocomplete="off" required></sf-input>
        <sf-input name="token" label="{{ __('larena-env::admin.alerts.token') }}" input-type="password" autocomplete="off" hint="{{ __('larena-env::admin.alerts.token_help') }}"></sf-input>
        {{-- How to make a Telegram bot and find the chat's number, in three short steps (block 4). --}}
        <details data-env-setup-howto="alerts">
            <summary class="color-primary" style="cursor: pointer;">{{ __('larena-env::admin.setup.alerts.howto') }}</summary>
            <ol class="m-0 m-t-1">
                @foreach ([0, 1, 2] as $number)
                    <li>{{ __('larena-env::admin.setup.alerts.howto_steps.'.$number) }}</li>
                @endforeach
            </ol>
        </details>
    @elseif ($state === 'done')
        <div class="flex flex-wrap g-2">
            @if ($canTest)
                <sf-button appearance="outline" text="{{ __('larena-env::admin.alerts.test') }}" native-type="submit" name="intent" value="test" data-env-setup-test></sf-button>
            @endif
            <sf-button appearance="link" text="{{ __('larena-env::admin.setup.edit') }}" href="{{ route('larena.env.admin.overview.setup', ['step' => 'alerts', 'change' => '1'], false) }}"></sf-button>
        </div>
    @endif
@endif
