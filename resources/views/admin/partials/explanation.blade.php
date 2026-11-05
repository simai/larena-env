{{-- An explanation: its first sentence shown, the rest under «Подробнее» (the panel's UX audit, COPY-01). --}}
<p class="m-0">{{ \Larena\Env\Admin\Explanation::split((string) $text)[0] }}</p>
@if (\Larena\Env\Admin\Explanation::split((string) $text)[1] !== null)
    <details class="m-top-1" data-env-explanation-more>
        <summary class="color-primary" style="cursor: pointer;">{{ __('larena-env::admin.more') }}</summary>
        <p class="m-0 m-top-1">{{ \Larena\Env\Admin\Explanation::split((string) $text)[1] }}</p>
    </details>
@endif
