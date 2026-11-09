{{-- The protection level, «Усиленный» advised; applied right here after the server's plan. --}}
@if ($level === null && $phase !== 'form')
    <p class="m-0" data-env-setup-unsupported>{{ __('larena-env::admin.setup.unsupported') }}</p>
@else
    @if ($level !== null)
        <p class="m-0" data-env-setup-now>{{ __('larena-env::admin.security.level') }}: <b>{{ __('larena-env::admin.security.levels.'.$level.'.name') }}</b></p>
    @endif
    @if ($phase === 'form')
        <sf-dropdown name="set" label="{{ __('larena-env::admin.security.level') }}" value="{{ in_array($level, ['enhanced', 'strict'], true) ? $level : 'enhanced' }}" required
            options='@json(array_map(static fn (string $one): array => ['value' => $one, 'text' => __('larena-env::admin.security.levels.'.$one.'.name').($one === 'enhanced' ? ' — '.__('larena-env::admin.security.level_recommended') : '')], \Larena\Env\Security\SecurityStatus::LEVELS))'></sf-dropdown>
        <ul class="m-0 p-l-3" data-env-setup-levels>
            @foreach (\Larena\Env\Security\SecurityStatus::LEVELS as $one)
                <li><b>{{ __('larena-env::admin.security.levels.'.$one.'.name') }}</b> — {{ __('larena-env::admin.security.levels.'.$one.'.text') }}</li>
            @endforeach
        </ul>
    @elseif ($state === 'done')
        <div><sf-button appearance="link" text="{{ __('larena-env::admin.setup.edit') }}" href="{{ route('larena.env.admin.overview.setup', ['step' => 'protection', 'change' => '1'], false) }}"></sf-button></div>
    @endif
@endif
