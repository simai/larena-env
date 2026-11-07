<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * What came of several reads asked as one `batch` call. Three outcomes need three different next steps, so they are
 * kept apart (critics of 0.4.1, 2026-11-07): the answers; a server that does not know `batch` (an older simai-env,
 * asked one by one from then on); and a batch that failed as a whole, where a gateway that did not answer at all
 * means the server is down, not that batching is wrong, so asking each read again would only wait the timeout again.
 */
final readonly class PanelBatchAnswer
{
    /** @param list<PanelAnswer>|null $answers */
    private function __construct(
        public ?array $answers,
        public ?string $reason,
        public bool $unknownToServer,
    ) {
    }

    /** @param list<PanelAnswer> $answers the answers in the order of the requests */
    public static function answered(array $answers): self
    {
        return new self($answers, null, false);
    }

    /** The server answered the batch as a command it does not run: ask one by one. */
    public static function unknownToServer(string $reason): self
    {
        return new self(null, $reason, true);
    }

    /** The batch failed as a whole for `$reason` (the process, the credentials, an unreadable answer). */
    public static function failed(string $reason): self
    {
        return new self(null, $reason, false);
    }

    /** Whether the gateway did not answer at all: ssh or sudo failed, or the server hung past the timeout. */
    public function unanswered(): bool
    {
        return $this->answers === null && $this->reason === PanelReason::UNANSWERED;
    }
}
