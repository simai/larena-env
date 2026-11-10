<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use UnexpectedValueException;

/**
 * Reads where a site stands on its creation route (site:status) and, for a WordPress copy, the archives and dumps the
 * server found in the site's folder (wp:status). Never throws: anything but a good answer is null, and the block that
 * shows it is left out, as on a server without these commands.
 */
final readonly class SiteRouteReader
{
    public function __construct(private PanelGateway $gateway)
    {
    }

    public function status(PanelActor $actor, string $domain): ?SiteStatus
    {
        $data = $this->data($actor, 'site:status', $domain);
        try {
            $status = $data === null ? null : SiteStatus::fromData($data);
        } catch (UnexpectedValueException) {
            return null;
        }

        return $status !== null && strcasecmp($status->domain, $domain) === 0 ? $status : null;
    }

    /** @return array{files: list<string>, dumps: list<string>}|null */
    public function wordpressCopies(PanelActor $actor, string $domain): ?array
    {
        $data = $this->data($actor, 'wp:status', $domain);
        if ($data === null || ($data['schema'] ?? null) !== 'simai-wp-status/1') {
            return null;
        }
        $list = static fn (string $name): array => array_values(array_filter(is_array($data[$name] ?? null) ? $data[$name] : [], 'is_string'));

        return ['files' => $list('files'), 'dumps' => $list('dumps')];
    }

    /** @return array<array-key, mixed>|null */
    private function data(PanelActor $actor, string $command, string $domain): ?array
    {
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, $command, ['domain' => $domain]));
        } catch (PanelRefused) {
            return null;
        }

        return SiteDetailsReader::failureOf($answer) === null ? $answer->data : null;
    }
}
