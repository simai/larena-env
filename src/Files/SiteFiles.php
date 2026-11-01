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
 * The reads of a site's «Файлы» tab and its nginx directives (Panel plan 6.1–6.3): a folder, a text file, the trash, the
 * directives, and a file handed over for download. Each answers its data or a reason code the screen says in words.
 */
final readonly class SiteFiles
{
    /** The most of a file the editor shows: the gateway answers text up to 1 MB inline. */
    public const int READ_MAX_BYTES = 1_048_576;

    public function __construct(private PanelGateway $gateway)
    {
    }

    /** @return array{entries: list<FileEntry>, truncated: bool}|string the folder, or why it cannot be shown */
    public function folder(PanelActor $actor, string $domain, string $path): array|string
    {
        $data = $this->data($actor, 'fs:list', ['domain' => $domain, 'path' => $path]);
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

    /** @return array{content: ?string, binary: bool, size: ?int, mtime: ?string, truncated: bool}|string the file's text, or why it cannot be shown */
    public function text(PanelActor $actor, string $domain, string $path): array|string
    {
        $data = $this->data($actor, 'fs:read', ['domain' => $domain, 'path' => $path, 'max_bytes' => (string) self::READ_MAX_BYTES]);
        if (is_string($data)) {
            return $data;
        }
        $binary = ($data['binary'] ?? false) === true || !is_string($data['content'] ?? null);

        return ['content' => $binary ? null : (string) $data['content'], 'binary' => $binary, 'size' => is_int($data['size'] ?? null) ? $data['size'] : null,
            'mtime' => is_string($data['mtime'] ?? null) ? $data['mtime'] : null, 'truncated' => ($data['truncated'] ?? false) === true];
    }

    /** @return list<TrashItem>|string */
    public function trash(PanelActor $actor, string $domain): array|string
    {
        $data = $this->data($actor, 'fs:trash-list', ['domain' => $domain]);
        if (is_string($data)) {
            return $data;
        }
        try {
            return array_map(static fn (mixed $item): TrashItem => TrashItem::fromData(is_array($item) ? $item : []), array_values((array) ($data['items'] ?? [])));
        } catch (UnexpectedValueException) {
            return PanelReason::ANSWER_SHAPE_INVALID;
        }
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
            $request = PanelRequest::read($actor, 'fs:download', ['domain' => $domain, 'path' => $path]);
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
