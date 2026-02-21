<?php

use App\Models\EscrowHoldReference;

// Test format without touching DB (generateHoldRefCandidate does not run queries)
test('generateHoldRefCandidate returns prefix EHR-', function () {
    $ref = EscrowHoldReference::generateHoldRefCandidate();
    expect($ref)->toStartWith('EHR-');
    expect(strlen($ref))->toBe(16); // EHR- + 12 chars
});

test('generateHoldRefCandidate is alphanumeric after prefix', function () {
    $ref = EscrowHoldReference::generateHoldRefCandidate();
    $suffix = substr($ref, 4);
    expect($suffix)->toMatch('/^[A-Z0-9]{12}$/');
});
