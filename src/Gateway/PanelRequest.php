<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/** One simai-panel-request/1 request. It can only be a valid one: anything else is refused while it is being made. */
final readonly class PanelRequest
{
    public const string SCHEMA = 'simai-panel-request/1';

    private const string REQUEST_ID_PATTERN = '/\A[A-Za-z0-9._:-]{1,64}\z/D';

    /**
     * @param array<string, string> $args option names without `--` => strings
     *
     * @throws PanelRefused
     */
    public function __construct(
        public string $requestId,
        public PanelActor $actor,
        public string $command,
        public array $args = [],
        public bool $confirm = false,
    ) {
        if (preg_match(self::REQUEST_ID_PATTERN, $requestId) !== 1) {
            throw new PanelRefused(PanelReason::BAD_REQUEST);
        }
        PanelCommands::check($command, $args, $confirm);
    }

    /**
     * @param array<string, string> $args
     *
     * @throws PanelRefused
     */
    public static function read(PanelActor $actor, string $command, array $args = []): self
    {
        return new self(self::newId(), $actor, $command, $args);
    }

    /**
     * What an action would do, without doing it: the gateway answers `kind: plan` and nothing runs.
     *
     * @param array<string, string> $args
     *
     * @throws PanelRefused
     */
    public static function plan(PanelActor $actor, string $command, array $args = []): self
    {
        return new self(self::newId(), $actor, $command, $args);
    }

    /**
     * The same action, confirmed: the gateway starts it as a job and answers `kind: job`.
     *
     * @param array<string, string> $args
     *
     * @throws PanelRefused
     */
    public static function confirmed(PanelActor $actor, string $command, array $args = []): self
    {
        return new self(self::newId(), $actor, $command, $args, true);
    }

    /**
     * The request as the protocol writes it. `args` stays a JSON object when it is empty (the gateway refuses a list),
     * and `confirm` is true only for an action this package has had confirmed.
     *
     * @return array{schema: string, request_id: string, actor: array<string, ?string>, command: string, args: object, confirm: bool}
     */
    public function toDocument(): array
    {
        return [
            'schema' => self::SCHEMA,
            'request_id' => $this->requestId,
            'actor' => $this->actor->toDocument(),
            'command' => $this->command,
            'args' => (object) $this->args,
            'confirm' => $this->confirm,
        ];
    }

    /** The bytes for the gateway's stdin: one JSON line. JSON escapes every line break a value could hold. */
    public function toLine(): string
    {
        return json_encode($this->toDocument(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private static function newId(): string
    {
        return 'panel-'.bin2hex(random_bytes(12));
    }
}
