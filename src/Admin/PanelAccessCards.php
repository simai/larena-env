<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Env\Panel\PanelAddress;

/**
 * «Кто может открыть панель» as two cards «значение · Изменить» (study part 8, PX-82): the panel's address and who may
 * open it, each the Framework's description list headed by its section with «Изменить», which opens the change in place
 * (?edit=…). The certificate's fingerprint, long, shows its start and «Показать» (?show=fingerprint) the whole of it.
 */
final class PanelAccessCards
{
    private function __construct()
    {
    }

    /** How much of a fingerprint shows folded: its first four bytes. */
    public const int FOLDED = 11;

    /**
     * @param bool $canEdit whether any change of the address is offered to this person
     *
     * @return list<array<string, mixed>>
     */
    public static function address(PanelAddress $address, bool $canEdit, bool $showFingerprint): array
    {
        $items = [['type' => 'section', 'id' => 'address', 'label' => self::text('cards.address')]
            + ($canEdit ? ['action' => ['label' => self::text('cards.edit'), 'href' => '?edit=address#env-panel-address']] : [])];
        if ($address->url !== null) {
            $items[] = ['id' => 'url', 'label' => self::text('cards.url'), 'value' => $address->url, 'href' => $address->url, 'copy' => true];
        }
        $items[] = ['id' => 'by-ip', 'label' => self::text('cards.by_ip'),
            'value' => $address->ipAccess && $address->ipUrl !== null ? $address->ipUrl : self::line('panel_address.ip_off')];
        $sha = $address->ipAccess ? ($address->ipCertificate['sha256'] ?? null) : null;
        if (is_string($sha) && $sha !== '') {
            $long = mb_strlen($sha) > self::FOLDED;
            $items[] = ['id' => 'fingerprint', 'label' => self::text('cards.fingerprint'), 'mono' => true,
                'value' => $showFingerprint || !$long ? $sha : mb_substr($sha, 0, self::FOLDED).'…',
                'hint' => self::text('cards.fingerprint_hint')]
                + ($showFingerprint ? ['copy' => true] : [])
                + ($long ? ['action' => ['label' => self::text($showFingerprint ? 'cards.hide' : 'cards.show'), 'href' => ($showFingerprint ? '?' : '?show=fingerprint').'#env-panel-address']] : []);
        }
        $items[] = ['id' => 'domain', 'label' => self::text('cards.domain'),
            'value' => $address->domain === null ? self::line('panel_address.no_domain') : ($address->domainUrl ?? $address->domain),
            'hint' => $address->domain === null ? null : self::line('panel_address.'.($address->domainSsl ? 'ssl_on' : 'ssl_off'))];
        if ($address->port !== null) {
            $items[] = ['id' => 'port', 'label' => self::text('cards.port'), 'value' => (string) $address->port, 'numeric' => true];
        }

        return array_map(static fn (array $item): array => array_filter($item, static fn (mixed $value): bool => $value !== null), $items);
    }

    /**
     * @param list<string>|string|null $access the addresses that may open the panel, 'any' for everyone, null unknown
     *
     * @return list<array<string, mixed>>
     */
    public static function access(array|string|null $access, string $you, bool $listed, bool $canEdit): array
    {
        $items = [['type' => 'section', 'id' => 'who', 'label' => self::text('cards.who')]
            + ($canEdit ? ['action' => ['label' => self::text('cards.edit'), 'href' => '?edit=access#env-panel-access']] : [])];
        $items[] = ['id' => 'from', 'label' => self::text('cards.from'),
            'value' => $access === null ? self::line('panel_access.unknown') : (is_string($access) ? self::text('cards.any') : self::text('cards.list'))];
        if (is_array($access)) {
            $items[] = ['id' => 'addresses', 'label' => self::text('cards.addresses'), 'value' => implode(', ', $access), 'mono' => true];
        }
        $items[] = ['id' => 'you', 'label' => self::text('cards.you'), 'value' => $you, 'mono' => true]
            + ($listed ? [] : ['status' => 'warning']);

        return $items;
    }

    private static function text(string $key): string
    {
        return self::line('panel_access.'.$key);
    }

    private static function line(string $key): string
    {
        return (string) __('larena-env::admin.'.$key);
    }
}
