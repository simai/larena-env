{{-- What will be done, in words (part 1, «Действие с планом»): the server's plan for this site, one line per change with
     «Изменить» back to its step (the Framework's sf-wizard-plan); nothing changes until «Создать сайт». --}}
<ul class="sf-wizard-plan" data-env-new-plan="{{ $plan->command }}">
    @foreach ($facts as $fact)
        <li data-env-new-fact><span><b>{{ $fact['label'] }}</b> — {{ $fact['value'] }}</span>
            @if ($fact['step'] !== '')
                <sf-button appearance="link" size="1/2" text="{{ __('larena-env::admin.setup.edit') }}" href="{{ route('larena.env.admin.sites.new.step', ['step' => $fact['step']], false) }}" data-wizard-step="{{ $fact['step'] }}"></sf-button>
            @endif
        </li>
    @endforeach
</ul>
@if (($plan->resources ?? null) !== null)
    @foreach ($plan->resources->warnings as $warning)
        <sf-alert status="{{ $plan->resources->ok ? 'warning' : 'error' }}" text="{{ __('larena-env::admin.routes.resources.warnings.'.(in_array($warning, ['ram_below_recommended', 'size_unknown', 'disk_unknown'], true) ? $warning : 'other')) }}"></sf-alert>
    @endforeach
@endif
@if ($then !== [])
    <p class="m-0 color-on-surface-variant" data-env-new-then>{{ __('larena-env::admin.new_site.wizard.next_here', ['steps' => implode(' → ', $then)]) }}</p>
@endif
<details data-env-plan-server>
    <summary class="color-primary" style="cursor: pointer;">{{ __('larena-env::admin.more') }}</summary>
    <p>{{ $plan->description }}</p>
    <p class="m-0 color-on-surface-variant">{{ \Larena\Env\Admin\PlanValues::effects($plan->effects) }}</p>
</details>
