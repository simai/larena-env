<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use UnexpectedValueException;

/**
 * One name a site answers on (site:names, simai-env 1.53.0): its main domain, an extra name such as www, or every
 * subdomain when the site was made in host mode; whether the name shows the site or sends visitors to the main domain,
 * whether its DNS points to this server, and whether the site's certificate covers it.
 */
final readonly class SiteName
{
    public const string PRIMARY = 'primary';
    public const string EXTRA = 'extra';
    public const string WILDCARD = 'wildcard';
    public const array KINDS = [self::PRIMARY, self::EXTRA, self::WILDCARD];

    public const string SERVE = 'serve';
    public const string REDIRECT = 'redirect';
    public const array MODES = [self::SERVE, self::REDIRECT];

    // A host name as the gateway takes it; a wildcard row is the same with `*.` in front.
    private const string HOST = '/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?)+\z/D';

    /** @param list<string> $addresses where the name's DNS points, as the server found it */
    public function __construct(
        public string $name,
        public string $kind,
        public string $mode,
        // Null: the server could not say (no answer from DNS, or not checked).
        public ?bool $pointsHere,
        public array $addresses,
        public bool $https,
    ) {
    }

    /** Whether a text is a host name the gateway would take. */
    public static function isHost(mixed $name): bool
    {
        return is_string($name) && preg_match(self::HOST, $name) === 1;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException a row whose shape the contract does not have: the screen shows none of it
     */
    public static function fromData(array $data): self
    {
        $kind = $data['kind'] ?? null;
        $mode = $data['mode'] ?? null;
        $name = $data['name'] ?? null;
        if (!in_array($kind, self::KINDS, true) || !in_array($mode, self::MODES, true) || !is_string($name)) {
            throw new UnexpectedValueException('site_name_invalid');
        }
        $host = $kind === self::WILDCARD ? (str_starts_with($name, '*.') ? substr($name, 2) : null) : $name;
        if (!self::isHost($host)) {
            throw new UnexpectedValueException('site_name_invalid');
        }
        $dns = $data['dns'] ?? null;
        $points = is_array($dns) ? ($dns['points_here'] ?? null) : false;
        $addresses = is_array($dns) ? ($dns['addresses'] ?? []) : null;
        if (!is_array($dns) || ($points !== null && !is_bool($points)) || !is_array($addresses) || !array_is_list($addresses)
            || array_filter($addresses, static fn (mixed $address): bool => !is_string($address)) !== [] || !is_bool($data['https'] ?? null)) {
            throw new UnexpectedValueException('site_name_invalid');
        }

        return new self(strtolower($name), $kind, $mode, $points, $addresses, $data['https']);
    }

    /** Only an extra name is changed here: the main domain is the site itself, and every subdomain is chosen at its making. */
    public function removable(): bool
    {
        return $this->kind === self::EXTRA;
    }
}
