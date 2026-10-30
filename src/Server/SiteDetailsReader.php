<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use UnexpectedValueException;

/** Reads one site and its logs through whichever driver is configured. Never throws: a refusal is a reason code. */
final readonly class SiteDetailsReader
{
    public function __construct(private PanelGateway $gateway)
    {
    }

    public function read(string $user, ?string $owner, string $domain): SiteDetailsResult
    {
        $source = $this->gateway->source();
        try {
            $answer = $this->gateway->ask(PanelRequest::read(new PanelActor($user, $owner), 'site:describe', ['domain' => $domain]));
        } catch (PanelRefused $refused) {
            return new SiteDetailsResult($source, null, $refused->reasonCode, true);
        }
        $failure = self::failureOf($answer);
        if ($failure !== null) {
            return new SiteDetailsResult($source, null, $failure[0], $failure[1]);
        }
        try {
            return new SiteDetailsResult($source, SiteDetails::fromDescription($answer->data));
        } catch (UnexpectedValueException) {
            return new SiteDetailsResult($source, null, PanelReason::ANSWER_SHAPE_INVALID);
        }
    }

    /** The last lines of the site's nginx log; `kind` is `access` or `error`, as the gateway names them. */
    public function log(string $user, ?string $owner, string $domain, string $kind, int $lines = 200): NginxLogResult
    {
        $source = $this->gateway->source();
        try {
            $answer = $this->gateway->ask(PanelRequest::read(new PanelActor($user, $owner), 'logs:nginx',
                ['domain' => $domain, 'kind' => $kind, 'lines' => (string) $lines]));
        } catch (PanelRefused $refused) {
            return new NginxLogResult($source, null, $refused->reasonCode, true);
        }
        $failure = self::failureOf($answer);
        if ($failure !== null) {
            return new NginxLogResult($source, null, $failure[0], $failure[1]);
        }
        try {
            return new NginxLogResult($source, NginxLogTail::fromData($answer->data));
        } catch (UnexpectedValueException) {
            return new NginxLogResult($source, null, PanelReason::ANSWER_SHAPE_INVALID);
        }
    }

    /**
     * Why a read answer cannot be used, or null when it carries data.
     *
     * @return array{0: string, 1: bool}|null reason code and whether the server refused
     */
    public static function failureOf(PanelAnswer $answer): ?array
    {
        if ($answer->status !== PanelAnswerStatus::Ok) {
            return [$answer->reason ?? PanelReason::ANSWER_INVALID, $answer->status === PanelAnswerStatus::Refused];
        }

        return $answer->kind === 'result' ? null : [PanelReason::ANSWER_SHAPE_INVALID, false];
    }
}
