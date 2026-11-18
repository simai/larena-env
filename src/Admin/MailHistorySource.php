<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Admin\Contracts\AdminHistorySource;
use Larena\Admin\History\AdminHistoryEntry;
use Larena\Env\Mail\MailLetters;

/**
 * «История» → «Почта» (owner decision C335, step 2): every letter of the panel as one row — when, to whom (the address
 * partly hidden: r***@mail.ru), which letter (its subject) and how it ended: «Отправлено», «Ждёт отправки», «Не ушло,
 * попытка 2 из 4: …», «Не доставлено: причина». Never the letter's text or a link. The object «Почта» is the history's
 * filter for them. Only those who may change the server's settings see the panel's letters.
 */
final readonly class MailHistorySource implements AdminHistorySource
{
    public const string OBJECT = 'mail';

    public function __construct(
        private MailLetters $letters,
        private Translator $translator,
        private ?AccessOperationAuthorizer $authorizer = null,
    ) {
    }

    public function entries(Request $request, int $limit): array
    {
        if (!$this->allowed($request)) {
            return [];
        }

        return array_map(fn (array $letter): AdminHistoryEntry => $this->entryOf($letter), $this->letters->recent(min($limit, 500)));
    }

    public function entry(Request $request, string $key): ?AdminHistoryEntry
    {
        if (!$this->allowed($request) || preg_match('/\Aletter-(\d{1,18})\z/D', $key, $found) !== 1) {
            return null;
        }
        $letter = $this->letters->one((int) $found[1]);

        return $letter === null ? null : $this->entryOf($letter);
    }

    /** @param array{id: int, kind: string, to: string, subject: string, state: string, reason: ?string, attempts: int, at: string, finished: ?string, next: ?string} $letter */
    private function entryOf(array $letter): AdminHistoryEntry
    {
        $to = MailLetters::masked($letter['to']);
        $reason = $letter['reason'] === null ? '' : $this->reason($letter['reason']);
        [$outcome, $result] = match ($letter['state']) {
            MailLetters::SENT => ['done', $this->text('history.sent')],
            MailLetters::FAILED => ['failed', $letter['reason'] === MailLetters::REASON_OFF ? $this->text('history.off') : $this->text('history.failed', ['reason' => $reason])],
            default => ['running', $letter['attempts'] === 0 ? $this->text('history.queued')
                : $this->text('history.retrying', ['attempt' => (string) ($letter['attempts'] + 1), 'of' => (string) (count(MailLetters::RETRY_MINUTES) + 1), 'reason' => $reason])],
        };
        $kind = $this->text('history.kinds.'.$letter['kind']);

        return new AdminHistoryEntry(
            key: 'letter-'.$letter['id'],
            title: $this->text('history.title', ['subject' => $letter['subject']]),
            at: self::iso($letter['at']),
            actor: $this->text('history.actor'),
            actorKey: self::OBJECT,
            object: $this->text('history.object'),
            objectKey: self::OBJECT,
            outcome: $outcome,
            note: $this->text('history.to', ['to' => $to]).' · '.$result,
            details: [
                [$this->text('history.kind'), str_starts_with($kind, 'larena-env::') ? $letter['kind'] : $kind],
                [$this->text('history.to_label'), $to],
                [$this->text('history.attempts'), (string) max(0, $letter['attempts'])],
            ],
        );
    }

    private function allowed(Request $request): bool
    {
        return $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false;
    }

    private function reason(string $reason): string
    {
        $key = 'larena-env::mail.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line === $key ? (string) $this->translator->get('larena-env::mail.reasons.failed') : rtrim($line, '.');
    }

    /** The record's UTC time as the history takes it. */
    private static function iso(string $at): string
    {
        $time = strtotime($at.' UTC');

        return $time === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-env::mail.'.$key, $replace);
    }
}
