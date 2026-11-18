<?php

declare(strict_types=1);

namespace Larena\Env\People;

use Illuminate\Contracts\Translation\Translator;
use Larena\Audit\Contracts\AuditReferenceNames;

/** A site's file in «История» by its name: «.env на demo.test», from the subject `env.file:<domain>/<path>`. */
final readonly class SecretFileAuditNames implements AuditReferenceNames
{
    public function __construct(private Translator $translator)
    {
    }

    public function names(array $refs): array
    {
        $names = [];
        foreach ($refs as $ref) {
            if (!str_starts_with($ref, SecretFileAccess::SUBJECT)) {
                continue;
            }
            $parts = explode('/', substr($ref, strlen(SecretFileAccess::SUBJECT)), 2);
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $names[$ref] = (string) $this->translator->get('larena-env::admin.files.secret_history_name', ['path' => $parts[1], 'domain' => $parts[0]]);
            }
        }

        return $names;
    }
}
