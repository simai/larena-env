{{-- Values as the Framework's description list (sf-list-description, C33): a label, the value, a hint under it and one
     action after it («Изменить», «Показать»); a section item heads a card with its «Изменить». The settings cards of study
     part 8 (PX-82): the value and the way to change it stand together. $items as the component takes them, given to it
     as its JSON island (escaped for a script element); $label names the list for a screen reader; $mark, the page's own. --}}
<sf-list-description label="{{ $label ?? '' }}" layout="{{ $layout ?? 'auto' }}" overflow="wrap" @if ($dividers ?? true) dividers @endif data-env-facts="{{ $mark ?? '' }}"><script type="application/json" data-sf-settings>{!! json_encode(['items' => array_values($items)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script></sf-list-description>
