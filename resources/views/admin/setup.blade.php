@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.setup.title'))
@section('heading', __('larena-env::admin.setup.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))

@section('content')
    {{-- The server's first setup (ENV 2026-11-06): one step at a time; each is done on its own screen, the server's own
         state passes a step by itself, and any step can be put off — what is left stays on the overview. --}}
    <div class="flex flex-wrap g-3 items-cross-start" data-env-setup="{{ $step }}">
        <ol class="m-0 p-0 grow-0" style="list-style: none; flex-basis: 15rem;" aria-label="{{ __('larena-env::admin.setup.steps') }}">
            @foreach ($states as $key => $state)
                <li class="p-y-1" data-env-setup-step="{{ $key }}" data-env-setup-state="{{ $state }}">
                    <a href="{{ route('larena.env.admin.setup', ['step' => $key], false) }}" @if ($key === $step) aria-current="step" @endif
                        class="flex g-1 items-cross-center" style="min-height: 2.75rem;{{ $key === $step ? ' font-weight: 600;' : '' }}">
                        <sf-icon icon="{{ $state === 'done' ? 'check_circle' : ($state === 'skipped' ? 'schedule' : 'radio_button_unchecked') }}" aria-hidden="true"></sf-icon>
                        <span>{{ $loop->iteration }}. {{ __('larena-env::admin.setup.step.'.$key.'.name') }}</span>
                        <span class="sr-only">{{ __('larena-env::admin.setup.state.'.$state) }}</span>
                    </a>
                </li>
            @endforeach
        </ol>
        <section class="larena-panel larena-panel-padded grow min-w-0" style="flex-basis: 20rem;" aria-labelledby="env-setup-step">
            <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.setup.of', ['number' => (string) $number, 'count' => (string) count($states)]) }}</p>
            <h2 id="env-setup-step" class="text-2">{{ __('larena-env::admin.setup.step.'.$step.'.name') }}</h2>
            <p>{{ __('larena-env::admin.setup.step.'.$step.'.why') }}</p>
            @if ($states[$step] === 'done')
                <p data-env-setup-done><sf-badge status="success" appearance="tonal" text="{{ __('larena-env::admin.setup.state.done') }}"></sf-badge></p>
            @endif
            <div class="flex flex-wrap g-2 m-t-3">
                @if ($links[$step] !== null)
                    <a class="larena-button {{ $states[$step] === 'done' ? '' : 'larena-button-primary' }}" style="min-height: 2.75rem;" href="{{ $links[$step] }}" data-env-setup-open>{{ __('larena-env::admin.setup.step.'.$step.'.open') }}</a>
                @endif
                <form method="post" action="{{ route('larena.env.admin.setup.mark', [], false) }}" class="m-0 flex flex-wrap g-2">
                    @csrf
                    <input type="hidden" name="step" value="{{ $step }}">
                    @if ($states[$step] !== 'done')
                        <button type="submit" name="state" value="done" class="larena-button" style="min-height: 2.75rem;" data-env-setup-mark="done">{{ __('larena-env::admin.setup.mark_done') }}</button>
                        <button type="submit" name="state" value="skipped" class="larena-button" style="min-height: 2.75rem;" data-env-setup-mark="skipped">{{ __('larena-env::admin.setup.skip') }}</button>
                    @else
                        <button type="submit" name="state" value="done" class="larena-button" style="min-height: 2.75rem;" data-env-setup-mark="next">{{ __('larena-env::admin.setup.next') }}</button>
                    @endif
                </form>
            </div>
            <p class="m-0 m-t-3"><a href="{{ route('larena.env.admin.overview', [], false) }}" data-env-setup-later>{{ __('larena-env::admin.setup.later') }}</a></p>
        </section>
    </div>
@endsection
