<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use UnexpectedValueException;

/**
 * Reads the names a site answers on (site:names, simai-env 1.53.0). Never throws: a refusal, a failure or an answer of
 * another shape is a reason code, and the block says it in words instead of guessing. An owner reads only their own
 * sites: the gateway refuses the others (owner_scope).
 */
final readonly class SiteNamesReader
{
    public function __construct(private PanelGateway $gateway)
    {
    }

    public function read(PanelActor $actor, string $domain): SiteNamesResult
    {
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, 'site:names', ['domain' => $domain]));
        } catch (PanelRefused $refused) {
            return new SiteNamesResult(null, $refused->reasonCode, true);
        }
        $failure = SiteDetailsReader::failureOf($answer);
        if ($failure !== null) {
            return new SiteNamesResult(null, $failure[0], $failure[1]);
        }
        try {
            $names = SiteNames::fromData($answer->data);
        } catch (UnexpectedValueException) {
            return new SiteNamesResult(null, PanelReason::ANSWER_SHAPE_INVALID);
        }

        // The names of another site than the one asked are not shown as this one's.
        return strcasecmp($names->domain, $domain) === 0 ? new SiteNamesResult($names) : new SiteNamesResult(null, PanelReason::ANSWER_SHAPE_INVALID);
    }
}
