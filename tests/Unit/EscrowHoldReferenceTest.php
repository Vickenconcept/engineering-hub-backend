<?php

use App\Models\EscrowHoldReference;

test('generateUniqueHoldRef returns prefix EHR-', function () {
    $ref = EscrowHoldReference::generateUniqueHoldRef();
    expect($ref)->toStartWith('EHR-');
    expect(strlen($ref))->toBe(16); // EHR- + 12 chars
});

test('generateUniqueHoldRef is alphanumeric after prefix', function () {
    $ref = EscrowHoldReference::generateUniqueHoldRef();
    $suffix = substr($ref, 4);
    expect($suffix)->toMatch('/^[A-Z0-9]{12}$/');
});
