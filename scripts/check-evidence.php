<?php

declare(strict_types=1);

$context = json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR);
$evidencePath = rtrim((string) $context['evidence_path'], '/').'/';
$errors = [];
foreach ((array) $context['required_evidence_files'] as $required) {
    if (!is_file($evidencePath.$required)) {
        $errors[] = "Missing evidence file: {$evidencePath}{$required}";
    }
}
$proposalPath = (string) $context['graph_sync_proposal_path'];
if (is_file($proposalPath)) {
    $proposal = json_decode((string) file_get_contents($proposalPath), true, 512, JSON_THROW_ON_ERROR);
    if (($proposal['canonical_update_allowed'] ?? null) !== false) {
        $errors[] = 'graph-sync-proposal must keep canonical_update_allowed=false';
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
    exit(1);
}
echo "Evidence contract is valid for the current repository state.\n";
