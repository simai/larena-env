<?php

declare(strict_types=1);

namespace Larena\Env\Files;

use Larena\Env\Contracts\PanelFileGateway;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelDownload;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use UnexpectedValueException;

/**
 * The reads of a site's «Файлы» tab and its nginx directives (simai-env 1.39.0, Panel plan 6.1–6.3): a folder, a text
 * file, the directives, and a file handed over for download. Each answers its data or a reason code the screen says.
 */
final readonly class SiteFiles
{
    /**
     * The most of a file the panel saves: its text travels in the request line, which the gateway takes up to 64 KiB in
     * all. A longer text is shown, never saved from the editor; it is downloaded, changed and uploaded instead.
     */
    public const int WRITE_MAX_BYTES = 61_440;

    public function __construct(private PanelGateway $gateway)
    {
    }

    /** @return array{entries: list<FileEntry>, truncated: bool}|string the folder, or why it cannot be shown */
    public function folder(PanelActor $actor, string $domain, string $path): array|string
    {
        $data = $this->data($actor, 'file:list', $path === '.' ? ['domain' => $domain] : ['domain' => $domain, 'path' => $path]);
        if (is_string($data)) {
            return $data;
        }
        try {
            $entries = array_map(static fn (mixed $entry): FileEntry => FileEntry::fromData(is_array($entry) ? $entry : []), array_values((array) ($data['entries'] ?? [])));
        } catch (UnexpectedValueException) {
            return PanelReason::ANSWER_SHAPE_INVALID;
        }
        // Folders first, then by name, as a file manager reads.
        usort($entries, static fn (FileEntry $a, FileEntry $b): int => [$a->isDir() ? 0 : 1, strtolower($a->name)] <=> [$b->isDir() ? 0 : 1, strtolower($b->name)]);

        return ['entries' => $entries, 'truncated' => ($data['truncated'] ?? false) === true];
    }

    /**
     * A text file as file:read answers it (UTF-8 up to 1 MiB) with the sha256 a save expects, so another person's change
     * meanwhile is refused, never overwritten. A binary or larger file is a reason (`not_text`, `too_large`).
     *
     * @return array{content: string, sha256: string, size: ?int, mtime: ?string, savable: bool}|string
     */
    public function text(PanelActor $actor, string $domain, string $path): array|string
    {
        $data = $this->data($actor, 'file:read', ['domain' => $domain, 'path' => $path]);
        if (is_string($data)) {
            return $data;
        }
        $content = $data['content'] ?? null;
        $sha256 = $data['sha256'] ?? null;
        if (!is_string($content) || !is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            return PanelReason::ANSWER_SHAPE_INVALID;
        }

        return ['content' => $content, 'sha256' => $sha256, 'size' => is_int($data['size'] ?? null) ? $data['size'] : null,
            'mtime' => FileEntry::moment($data['mtime'] ?? null), 'savable' => self::savable($content)];
    }

    /** Whether the text fits the request line a save sends, as JSON writes it there. */
    public static function savable(string $content): bool
    {
        $encoded = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) && strlen($encoded) <= self::WRITE_MAX_BYTES;
    }

    /**
     * The site's own nginx directives and a change applied but not kept yet: its id and when the server puts the previous
     * directives back by itself.
     *
     * @return array{content: string, pending: ?string, expires: ?string}|string
     */
    public function overlay(PanelActor $actor, string $domain): array|string
    {
        $data = $this->data($actor, 'site:nginx-overlay-get', ['domain' => $domain]);
        if (is_string($data)) {
            return $data;
        }

        $pending = is_array($data['pending'] ?? null) ? $data['pending'] : [];
        $change = is_string($pending['change_id'] ?? null) && $pending['change_id'] !== '' ? $pending['change_id'] : null;

        return ['content' => is_string($data['content'] ?? null) ? $data['content'] : '', 'pending' => $change,
            'expires' => $change !== null && is_string($pending['expires_at'] ?? null) ? $pending['expires_at'] : null];
    }

    /** The file written to $into and checked, or its reason in the answer. */
    public function download(PanelActor $actor, string $domain, string $path, string $into): PanelDownload
    {
        try {
            $request = PanelRequest::read($actor, 'file:download', ['domain' => $domain, 'path' => $path]);
        } catch (PanelRefused $refused) {
            return PanelDownload::of(PanelAnswer::refused('panel-download', $refused->reasonCode));
        }
        if (!$this->gateway instanceof PanelFileGateway) {
            return PanelDownload::of(PanelAnswer::failed($request->requestId, PanelReason::NOT_CONFIGURED));
        }

        return $this->gateway->download($request, $into);
    }

    /**
     * @param array<string, string> $args
     *
     * @return array<array-key, mixed>|string
     */
    private function data(PanelActor $actor, string $command, array $args): array|string
    {
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, $command, $args));
        } catch (PanelRefused $refused) {
            return $refused->reasonCode;
        }

        return $answer->status === PanelAnswerStatus::Ok && $answer->kind === 'result' ? $answer->data : ($answer->reason ?? PanelReason::ANSWER_INVALID);
    }
}
