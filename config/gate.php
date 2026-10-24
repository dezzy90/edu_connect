<?php

return [
    'account_invites_enabled' => (bool) env('GATE_ACCOUNT_INVITES_ENABLED', false),
    // Opt-in complex staffing: secretaries operate all explicitly assigned schools; DMs only secondary.
    'secretary_complex_ids' => (static function (): array {
        $text = trim((string) env('GATE_SECRETARY_COMPLEX_IDS', ''));
        if ($text === '') {
            return [];
        }
        if (! preg_match('/^[1-9]\d*(?:,[1-9]\d*)*$/D', $text)) {
            return [0]; // Malformed opt-in denies staffing access, never widens it.
        }

        return array_map('intval', explode(',', $text));
    })(),
    'enabled' => (bool) env('GATE_ENABLED', false),
    'operator_enabled' => (bool) env('GATE_OPERATOR_ENABLED', false),
    'setup_enabled' => (bool) env('GATE_SETUP_ENABLED', false),
    'activation_enabled' => (bool) env('GATE_ACTIVATION_ENABLED', false),
    // Administrative launch hold. Never treat this switch as evidence of TLS remediation.
    'activation_release_ready' => (bool) env('GATE_ACTIVATION_RELEASE_READY', false),
    // Draft preparation scope is separate from live gate-processing scope.
    'setup_school_ids' => (static function (): array {
        $text = trim((string) env('GATE_SETUP_SCHOOL_IDS', ''));
        if (! preg_match('/^[1-9]\d*(?:,[1-9]\d*)*$/D', $text)) {
            return [];
        }
        $ids = array_map('intval', explode(',', $text));

        return count($ids) === count(array_unique($ids)) && ! array_filter($ids, static fn ($id) => $id < 1) ? $ids : [];
    })(),
    // Empty/malformed explicit scope denies processing, never means every school.
    'school_ids' => (static function (): array {
        $text = trim((string) env('GATE_SCHOOL_IDS', ''));
        if (! preg_match('/^[1-9]\d*(?:,[1-9]\d*)*$/D', $text)) {
            return [];
        }
        $ids = array_map('intval', explode(',', $text));

        return count($ids) === count(array_unique($ids)) && ! array_filter($ids, static fn ($id) => $id < 1)
            ? $ids : [];
    })(),
    'mirror_delivery_enabled' => (bool) env('GATE_MIRROR_DELIVERY_ENABLED', false),
    'parent_delivery_enabled' => (bool) env('GATE_PARENT_DELIVERY_ENABLED', false),
    'parent_push_enabled' => false,
];
