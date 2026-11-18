@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.new_site.title'))
@section('heading', __('larena-env::admin.new_site.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- «Новый сайт» on the Framework's wizard (owner decision dec-83b7d143b782; study ux-study/2026-11-17-wizards, variant A):
         one list of steps — the kind, the address and settings, what will be done, the run, the result — a page per step;
         the site, its database, WordPress and HTTPS are one run. NewSiteController::step() gives each step's state. --}}
    <sf-wizard place="pages" navigation="visited" current="{{ $step }}" label="{{ __('larena-env::admin.new_site.wizard.label') }}"
        later-href="{{ route('larena.env.admin.index', [], false) }}" done-href="{{ $cardUrl ?? route('larena.env.admin.index', [], false) }}" focus-title
        running-text="{{ __('larena-env::admin.new_site.wizard.running') }}" data-env-new-site="{{ $step }}">
        @foreach (\Larena\Env\Http\Controllers\NewSiteController::STEPS as $key)
            <sf-wizard-step name="{{ $key }}" label="{{ __('larena-env::admin.new_site.wizard.step.'.$key.'.name') }}"
                href="{{ $key === 'kind' ? route('larena.env.admin.sites.new', [], false) : route('larena.env.admin.sites.new.step', ['step' => $key], false) }}"
                description="{{ __('larena-env::admin.new_site.wizard.step.'.$key.'.why') }}"
                type="{{ ['kind' => 'form', 'address' => 'form', 'review' => 'review', 'run' => 'action', 'done' => 'result'][$key] }}"
                @if ($key === 'review') action-text="{{ __('larena-env::admin.new_site.wizard.step.review.action') }}" @endif
                @if ($key === $step && $key === 'run' && $stepState !== '') state="{{ $stepState }}" @elseif (($states[$key] ?? '') !== '') state="{{ $states[$key] }}" @endif
                @if ($key === $step && ($message ?? null) !== null) message="{{ $message }}" @endif
                data-env-new-step="{{ $key }}">
                @if ($key === $step)
                    <form method="post" action="{{ route('larena.env.admin.sites.new.submit', ['step' => $key], false) }}" id="env-new-site-form" class="m-0 flex flex-column g-2" data-env-new-site-form="{{ $key }}">
                        @csrf
                        @include('larena-env::admin.new-site.'.$key)
                    </form>
                @endif
            </sf-wizard-step>
        @endforeach
    </sf-wizard>

    @if ($step === 'kind' && $canEnable)
        {{-- «Включить» of a switched-off kind (simai-env 1.57.5): its plan, question and run in the side panel over the
             wizard (larena/admin AdminPlannedAction); the kind then joins the tiles. --}}
        @foreach ($disabled as $id => $name)
            <sf-modal id="env-enable-{{ $id }}" position="inline-end" width="28rem" heading="{{ __('larena-env::admin.new_site.wizard.enable_heading', ['name' => $name]) }}">
                <div data-larena-action-host>
                    <form method="post" action="{{ route('larena.env.admin.sites.enable.plan', [], false) }}" class="m-0" data-larena-action-form data-larena-action-auto>
                        @csrf
                        <input type="hidden" name="id" value="{{ $id }}">
                        <button type="submit" class="larena-button">{{ __('larena-env::admin.new_site.wizard.enable') }}</button>
                    </form>
                </div>
            </sf-modal>
        @endforeach
    @endif
@endsection
