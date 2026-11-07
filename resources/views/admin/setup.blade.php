@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.setup.title'))
@section('heading', __('larena-env::admin.setup.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))

@section('content')
    {{-- The server's first setup on the Framework's wizard (owner 2026-11-06): every step is done here — its fields, the
         server's plan, the run — and any step can be put off; what is left stays on the overview. Each step's type and
         state come from ServerSetupController::wizard(). --}}
    <sf-wizard place="pages" navigation="free" current="{{ $step }}" label="{{ __('larena-env::admin.setup.title') }}"
        later-href="{{ $overviewUrl }}" done-href="{{ $overviewUrl }}" focus-title
        running-text="{{ __('larena-env::admin.setup.running') }}" data-env-setup="{{ $step }}" data-env-setup-phase="{{ $phase }}">
        @foreach ($wizard as $key => $one)
            <sf-wizard-step name="{{ $key }}" label="{{ __('larena-env::admin.setup.step.'.$key.'.name') }}"
                href="{{ route('larena.env.admin.overview.setup', ['step' => $key], false) }}"
                description="{{ __('larena-env::admin.setup.step.'.$key.'.why') }}" optional
                type="{{ $one['type'] }}" @if ($one['state'] !== '') state="{{ $one['state'] }}" @endif
                @if ($one['action'] !== '') action-text="{{ $one['action'] }}" @endif
                @if ($one['message'] !== null) message="{{ $one['message'] }}" @endif
                data-env-setup-step="{{ $key }}" data-env-setup-state="{{ $states[$key] }}">
                @if ($key === $step)
                    <form method="post" action="{{ route('larena.env.admin.overview.setup.submit', ['step' => $key], false) }}" id="env-setup-form" class="m-0 flex flex-column g-2">
                        @csrf
                        <input type="hidden" name="phase" value="{{ $phase }}">
                        @if ($phase === 'review' && $plan !== null)
                            @include('larena-env::admin.setup.plan', ['plan' => $plan])
                        @elseif (in_array($phase, ['running', 'error'], true) && $job !== null)
                            <p class="m-0"><a href="{{ route('larena.env.admin.job', ['job' => $job], false) }}" target="_blank" rel="noopener" data-env-setup-job>{{ __('larena-env::admin.setup.job_log') }}</a></p>
                        @elseif ($phase !== 'result')
                            @include('larena-env::admin.setup.'.$key, ['phase' => $phase, 'state' => $states[$key]] + $data)
                        @endif
                    </form>
                @endif
            </sf-wizard-step>
        @endforeach
        <sf-wizard-step name="finish" label="{{ __('larena-env::admin.setup.finish.name') }}" type="result"
            href="{{ route('larena.env.admin.overview.setup', ['step' => 'finish'], false) }}" description="{{ __('larena-env::admin.setup.finish.why') }}" data-env-setup-step="finish">
            @if ($step === 'finish')
                <ul class="m-0 p-0" style="list-style: none;" data-env-setup-summary>
                    @foreach ($states as $key => $state)
                        <li class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-setup-summary-step="{{ $key }}">
                            <span>{{ __('larena-env::admin.setup.step.'.$key.'.name') }}</span>
                            <sf-badge appearance="tonal" status="{{ $state === 'done' ? 'success' : '' }}" text="{{ __('larena-env::admin.setup.state.'.$state) }}"></sf-badge>
                        </li>
                    @endforeach
                </ul>
                <p class="m-0 m-t-2">{{ __('larena-env::admin.setup.finish.rest') }}</p>
            @endif
        </sf-wizard-step>
    </sf-wizard>
@endsection
