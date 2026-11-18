<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use DateTimeImmutable;
use Larena\Env\Security\FileScan;
use Larena\Env\Security\GeoStatus;
use Larena\Env\Security\McpStatus;
use Larena\Env\Security\SecurityStatus;
use Larena\Env\Server\Moment;

/**
 * The settings of «Безопасность» as one card «значение · Изменить» (study part 8, PX-82, PX-83): the protection level,
 * the countries, access for AI assistants, the sites' files and their vulnerable packages, each with what it is now in
 * one line and an action that opens it in place (?edit=…), so the page is a list of things to do and a short card, not
 * 4000 px of forms. Times are said as «2 ч назад», which needs no time zone: the reader's own (PX-83).
 */
final class SecurityProtection
{
    private function __construct()
    {
    }

    /** @return list<array<string, mixed>> */
    public static function items(SecurityStatus $status, bool $geoSupported, ?GeoStatus $geo, bool $mcpSupported, ?McpStatus $mcp, bool $filesSupported, ?FileScan $files, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $items = [['type' => 'section', 'id' => 'protection', 'label' => self::text('protection.title')]];
        $items[] = ['id' => 'level', 'label' => self::text('protection.level'),
            'value' => $status->level === null ? self::text('protection.none') : self::text('levels.'.$status->level.'.name'),
            'action' => self::open('level', 'edit')];
        if ($geoSupported) {
            $items[] = ['id' => 'geo', 'label' => self::text('protection.geo'),
                'value' => $geo === null ? self::text('protection.unknown')
                    : self::text('geo.modes.'.$geo->mode).($geo->mode !== 'off' ? ' · '.implode(', ', array_slice($geo->countries, 0, 6)).(count($geo->countries) > 6 ? '…' : '') : ''),
                'action' => self::open('geo', 'edit')];
        }
        if ($mcpSupported) {
            $items[] = ['id' => 'mcp', 'label' => self::text('protection.mcp'),
                'value' => $mcp === null ? self::text('protection.unknown')
                    : ($mcp->enabled ? self::text('protection.mcp_value', ['state' => self::text('mcp.on'), 'count' => (string) count($mcp->keys)]) : self::text('mcp.off')),
                'action' => self::open('mcp', 'edit')];
        }
        if ($filesSupported) {
            $when = Moment::ago($files?->checkedAt, $now, static fn (string $key, array $replace = []): string => (string) __('larena-env::admin.'.$key, $replace));
            $items[] = ['id' => 'files', 'label' => self::text('protection.files'),
                'value' => $files === null || $when === null ? self::text('files_never') : self::text('protection.files_value', ['when' => $when, 'count' => (string) $files->foundCount()]),
                'status' => $files !== null && $files->foundCount() > 0 ? 'error' : null,
                'action' => self::open('files', 'open')];
        }
        $packages = array_sum(array_map('count', $status->vulnerabilities));
        $items[] = ['id' => 'vulnerabilities', 'label' => self::text('protection.vulnerabilities'),
            'value' => $packages === 0 ? self::text('protection.none') : self::text('protection.vulnerabilities_value', ['count' => (string) $packages, 'sites' => (string) count($status->vulnerabilities)]),
            'status' => $packages > 0 ? 'warning' : null]
            + ($packages > 0 ? ['action' => self::open('vulnerabilities', 'show')] : []);

        return array_map(static fn (array $item): array => array_filter($item, static fn (mixed $value): bool => $value !== null), $items);
    }

    /** @return array{label: string, href: string} */
    private static function open(string $part, string $word): array
    {
        return ['label' => self::text('protection.'.$word), 'href' => '?edit='.$part.'#env-security-'.$part];
    }

    /** @param array<string, string> $replace */
    private static function text(string $key, array $replace = []): string
    {
        return (string) __('larena-env::admin.security.'.$key, $replace);
    }
}
