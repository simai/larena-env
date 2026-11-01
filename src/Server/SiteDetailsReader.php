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

    public function read(PanelActor $actor, string $domain): SiteDetailsResult
    {
        $source = $this->gateway->source();
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, 'site:describe', ['domain' => $domain]));
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
    /** With $match the server searches its last 20000 lines for that fixed string (simai-env 1.28.0). */
    public function log(PanelActor $actor, string $domain, string $kind, int $lines = 200, ?string $match = null): NginxLogResult
    {
        $source = $this->gateway->source();
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, 'logs:nginx',
                ['domain' => $domain, 'kind' => $kind, 'lines' => (string) $lines] + ($match === null ? [] : ['match' => $match])));
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

    /** simai-env's check of the site: its state and what did not pass. Null when the gateway did not answer it. */
    public function doctor(PanelActor $actor, string $domain): ?SiteDoctor
    {
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, 'site:doctor', ['domain' => $domain]));
        } catch (PanelRefused) {
            return null;
        }
        if (self::failureOf($answer) !== null) {
            return null;
        }
        try {
            return SiteDoctor::fromData($answer->data);
        } catch (UnexpectedValueException) {
            return null;
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
