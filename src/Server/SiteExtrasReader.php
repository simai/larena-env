<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use UnexpectedValueException;

/**
 * The reads a site's tabs and the server's overview add (Panel plan stage 3): PHP settings and extensions, databases,
 * SFTP accounts and the server's load. Each is asked only when the server's gateway has it, and a refusal or a
 * failure is null: the tab then says it cannot show this, never guesses.
 */
final readonly class SiteExtrasReader
{
    public function __construct(private PanelGateway $gateway)
    {
    }

    /** @return list<PhpSetting>|null */
    public function phpSettings(PanelActor $actor, string $domain): ?array
    {
        return $this->list($actor, 'site:php-ini-list', ['domain' => $domain], 'settings', PhpSetting::fromData(...));
    }

    /** @return list<PhpExtension>|null */
    public function phpExtensions(PanelActor $actor, string $php): ?array
    {
        return $this->list($actor, 'php:extensions', ['php' => $php], 'extensions', PhpExtension::fromData(...));
    }

    /** @return list<SiteDatabase>|null the site's databases, from the server's list (whose own owner scope applies) */
    public function databases(PanelActor $actor, string $domain): ?array
    {
        $all = $this->list($actor, 'db:list', [], 'databases', SiteDatabase::fromData(...));

        return $all === null ? null : array_values(array_filter($all, static fn (SiteDatabase $db): bool => $db->site === $domain));
    }

    /** @return list<SftpAccount>|null */
    public function sftpAccounts(PanelActor $actor, string $domain): ?array
    {
        return $this->list($actor, 'access:list', ['domain' => $domain], 'accounts', SftpAccount::fromData(...));
    }

    public function metrics(PanelActor $actor): ?ServerMetrics
    {
        $data = $this->data($actor, 'self:metrics', []);

        return $data === null ? null : ServerMetrics::fromData($data);
    }

    /**
     * @template T
     *
     * @param array<string, string> $args
     * @param callable(array<array-key, mixed>): (T|null) $make
     *
     * @return list<T>|null
     */
    private function list(PanelActor $actor, string $command, array $args, string $key, callable $make): ?array
    {
        $data = $this->data($actor, $command, $args);
        if ($data === null) {
            return null;
        }
        $items = is_array($data[$key] ?? null) ? $data[$key] : (array_is_list($data) ? $data : []);
        $list = [];
        foreach ($items as $item) {
            $made = is_array($item) ? $make($item) : null;
            if ($made !== null) {
                $list[] = $made;
            }
        }

        return $list;
    }

    /**
     * @param array<string, string> $args
     *
     * @return array<array-key, mixed>|null
     */
    private function data(PanelActor $actor, string $command, array $args): ?array
    {
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, $command, $args));
        } catch (PanelRefused|UnexpectedValueException) {
            return null;
        }

        return SiteDetailsReader::failureOf($answer) === null ? $answer->data : null;
    }
}
