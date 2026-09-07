<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Isaidgitmenow\LaravelErrors\Support\Masker;

describe('Masker', function () {
    it('masks using last4', function () {
        expect(Masker::mask('12345678', 'last4'))->toBe('****5678');
        expect(Masker::mask('123', 'last4'))->toBe('***');
    });

    it('masks using first_last', function () {
        expect(Masker::mask('12345678', 'first_last'))->toBe('1***8');
        expect(Masker::mask('12', 'first_last'))->toBe('**');
    });

    it('masks using email', function () {
        expect(Masker::mask('test@example.com', 'email'))->toBe('t***@example.com');
        expect(Masker::mask('invalid_email', 'email'))->toBe('[REDACTED]');
    });

    it('returns REDACTED for unknown mask format', function () {
        expect(Masker::mask('secret', 'unknown_format'))->toBe('[REDACTED]');
    });

    it('masks hash using sha256 hmac', function () {
        // Just verify it starts with hmac: and has length 17 (5 + 12)
        $result = Masker::mask('secret', 'hash');
        expect(str_starts_with($result, 'hmac:'))->toBeTrue();
        expect(strlen($result))->toBe(17);
    });

    it('masks using length', function () {
        expect(Masker::mask('secret', 'length'))->toBe('[REDACTED:6]');
    });

    it('returns REDACTED for objects', function () {
        expect(Masker::mask(new \stdClass(), 'last4'))->toBe('[REDACTED]');
    });
});
