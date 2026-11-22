{{-- «Временная ссылка» (simai-env 1.65.0), inside «Домены и HTTPS» beside the site's names: the site on the server's own IP
     and a port, before its domain points here, for an hour, a day or the minutes chosen; it closes by itself. The link with
     its key is shown once, on the run's screen; here only until when it is open, with «Продлить» (the same link, a new
     deadline, shown again) and «Закрыть». What stops it (an alias, a paused site) is said in words, without a button that
     leads into a refusal; what only the server knows (no address, no nginx, no free port) it says in the plan. --}}
<section class="larena-panel larena-panel-padded" data-env-preview-link="{{ $previewLink['state'] !== null ? 'open' : ($previewLink['refusal'] ?? 'closed') }}" aria-labelledby="env-site-preview-link">
    <h2 id="env-site-preview-link" class="text-2">{{ __('larena-env::admin.preview_link.title') }}</h2>
    @if ($previewLink['state'] !== null)
        <p data-env-preview-link-until>{{ __('larena-env::admin.preview_link.open_until') }}
            @if (\Larena\Env\Server\Moment::iso($previewLink['state']->expiresAt) !== null)<sf-time datetime="{{ \Larena\Env\Server\Moment::iso($previewLink['state']->expiresAt) }}" format="datetime"></sf-time>@else{{ \Larena\Env\Server\Moment::human($previewLink['state']->expiresAt) }}@endif
        </p>
        <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.preview_link.open_note') }}</p>
        <p class="flex flex-wrap gap-1 m-top-1">
            @if (in_array(\Larena\Env\Operations\SiteOperation::PreviewLink, $tabOperations, true))
                <button type="button" class="larena-button" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::PreviewLink->value }}" data-env-preview-link-renew>{{ __('larena-env::admin.preview_link.renew') }}</button>
            @endif
            @if (in_array(\Larena\Env\Operations\SiteOperation::PreviewLinkClose, $tabOperations, true))
                <button type="button" class="larena-button" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::PreviewLinkClose->value }}" data-env-preview-link-close>{{ __('larena-env::admin.preview_link.close') }}</button>
            @endif
        </p>
    @elseif ($previewLink['refusal'] !== null)
        <p role="note" data-env-preview-link-refusal="{{ $previewLink['refusal'] }}">{{ __('larena-env::admin.preview_link.refusals.'.$previewLink['refusal']) }}</p>
    @else
        <p>{{ __('larena-env::admin.preview_link.explain') }}</p>
        @if (in_array(\Larena\Env\Operations\SiteOperation::PreviewLink, $tabOperations, true))
            <p class="m-0"><button type="button" class="larena-button" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::PreviewLink->value }}" data-env-preview-link-open>{{ __('larena-env::admin.preview_link.open') }}</button></p>
        @endif
    @endif
</section>
