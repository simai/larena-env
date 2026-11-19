<?php

declare(strict_types=1);

namespace Larena\Env\Http\Support;

use Closure;
use Illuminate\Http\Request;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Http\Middleware\ResolvePanelActor;

/**
 * The receipt of a plan the person saw (the server side of «Действие с планом»): the plan's screen is given one, the
 * confirmation brings it back, and only a receipt that is this person's, fresh, not used yet and made for exactly the
 * same action and values lets the action run. Kept in the person's session: an id, the action, a hash of the values in
 * one canonical form, who it was made for, until when; taken once, used or not.
 *
 * Two ways to use it. The values the confirmation sends again are checked against the hash (`confirms`): a changed value,
 * a reused or an old receipt stops the run before the server is asked anything. Or what the confirmation makes waits in
 * the receipt itself (`take`, a DNS change, the panel's mail settings, an invitation) and the form sends only the id.
 */
final readonly class PlanReceipts
{
    /** The form field the receipt comes back in. */
    public const string FIELD = 'receipt';

    /** The reason a confirmation is refused for, said by `larena-env::admin.reasons.plan_receipt`. */
    public const string REFUSED = 'plan_receipt';

    private const string SESSION = 'larena_env_plan_receipts';

    /** How many plans one person may have open at once (tabs, a re-planned choice); the oldest go first. */
    private const int KEEP = 10;

    /** @param (Closure(): int)|null $now the clock, in seconds */
    public function __construct(
        private int $seconds = 900,
        private ?Closure $now = null,
    ) {
    }

    /**
     * A receipt for the plan just shown: of the action (a command, or the panel's own name of it) with these values, and
     * what the confirmation makes, when it is kept here rather than sent back.
     *
     * @param array<array-key, mixed> $values
     * @param array<string, mixed> $kept
     */
    public function issue(Request $request, string $action, array $values = [], array $kept = [], ?int $seconds = null): string
    {
        // Without a session nothing can be confirmed: no receipt, and the confirmation is refused.
        if (!$request->hasSession()) {
            return '';
        }
        $now = $this->now();
        $id = bin2hex(random_bytes(16));
        $receipts = array_filter($this->all($request), static fn (mixed $receipt): bool => is_array($receipt) && (int) ($receipt['until'] ?? 0) >= $now);
        $receipts[$id] = [
            'action' => $action,
            'hash' => self::hash($id, $action, $values),
            'actor' => self::actor($request),
            'until' => $now + ($seconds ?? $this->seconds),
            'kept' => $kept,
        ];
        $request->session()->put(self::SESSION, array_slice($receipts, -self::KEEP, null, true));

        return $id;
    }

    /**
     * Whether the confirmation carries a receipt of this action with exactly these values; the receipt is used up either way.
     *
     * @param array<array-key, mixed> $values
     */
    public function confirms(Request $request, string $action, array $values = [], string $field = self::FIELD): bool
    {
        return $this->pull($request, $action, $values, $field) !== null;
    }

    /**
     * What waits in the confirmed receipt of this action, once; null for none, another person's, a used or an old one.
     *
     * @return array<string, mixed>|null
     */
    public function take(Request $request, string $action, string $field = self::FIELD): ?array
    {
        return $this->pull($request, $action, null, $field);
    }

    /**
     * @param array<array-key, mixed>|null $values null where what runs is what the receipt keeps
     * @return array<string, mixed>|null
     */
    private function pull(Request $request, string $action, ?array $values, string $field): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }
        $id = $request->input($field);
        $receipts = $this->all($request);
        if (!is_string($id) || preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1 || !isset($receipts[$id])) {
            return null;
        }
        $receipt = $receipts[$id];
        unset($receipts[$id]);
        $request->session()->put(self::SESSION, $receipts);
        if (!is_array($receipt) || ($receipt['action'] ?? null) !== $action || (int) ($receipt['until'] ?? 0) < $this->now()
            || !is_string($receipt['actor'] ?? null) || !hash_equals($receipt['actor'], self::actor($request))
            || ($values !== null && (!is_string($receipt['hash'] ?? null) || !hash_equals($receipt['hash'], self::hash($id, $action, $values))))) {
            return null;
        }

        return is_array($receipt['kept'] ?? null) ? $receipt['kept'] : [];
    }

    /**
     * The values in one form whatever way they came: keys in order, numbers and yes/no as the form sends them, an empty
     * value the same as one not sent (a form gives back an empty field as nothing).
     */
    public static function canonical(mixed $values): mixed
    {
        if (is_array($values)) {
            $list = array_is_list($values);
            $out = [];
            foreach ($values as $key => $value) {
                $value = self::canonical($value);
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                $out[(string) $key] = $value;
            }
            if ($list) {
                return array_values($out);
            }
            ksort($out, SORT_STRING);

            return $out;
        }

        return match (true) {
            $values === null => null,
            is_bool($values) => $values ? 'yes' : 'no',
            is_scalar($values) => (string) $values,
            default => null,
        };
    }

    /** @param array<array-key, mixed> $values */
    private static function hash(string $id, string $action, array $values): string
    {
        $text = json_encode([$action, self::canonical($values)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // Keyed by the receipt's own id: the session never holds a hash a typed secret could be guessed back from alone.
        return hash_hmac('sha256', (string) $text, $id);
    }

    /** Who the receipt is for: the signed-in person, and whom the panel acts as for them. */
    private static function actor(Request $request): string
    {
        $actor = $request->attributes->get(ResolvePanelActor::ATTRIBUTE);
        $signedIn = $request->attributes->get('larena_access_actor');

        return implode('|', [
            is_string($signedIn) ? $signedIn : '',
            $actor instanceof PanelActor ? $actor->user : '',
            $actor instanceof PanelActor ? (string) $actor->owner : '',
            $actor instanceof PanelActor ? (string) $actor->impersonatedBy : '',
        ]);
    }

    /** @return array<string, mixed> */
    private function all(Request $request): array
    {
        $receipts = $request->session()->get(self::SESSION, []);

        return is_array($receipts) ? $receipts : [];
    }

    private function now(): int
    {
        return $this->now !== null ? ($this->now)() : time();
    }
}
