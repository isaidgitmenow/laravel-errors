<?php

declare(strict_types=1);

use Isaidgitmenow\LaravelErrors\Attributes\DontReport;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;

describe('Attributes', function () {

    it('HttpCode attribute stores the status code', function () {
        $attr = new HttpCode(402);
        expect($attr->code)->toBe(402);
    });

    it('DontReport attribute can be instantiated', function () {
        $attr = new DontReport();
        expect($attr)->toBeInstanceOf(DontReport::class);
    });

    it('ReportTo attribute stores single channel', function () {
        $attr = new ReportTo('slack');
        expect($attr->channels)->toBe('slack');
    });

    it('ReportTo attribute stores multiple channels', function () {
        $attr = new ReportTo(['slack', 'sentry']);
        expect($attr->channels)->toBe(['slack', 'sentry']);
    });

    it('TranslatedMessage attribute stores the translation key', function () {
        $attr = new TranslatedMessage('errors.payment_failed');
        expect($attr->key)->toBe('errors.payment_failed');
    });

    it('WithContext attribute stores the property list', function () {
        $attr = new WithContext(['user_id', 'transaction_id']);
        expect($attr->properties)->toBe(['user_id', 'transaction_id']);
    });

    it('RateLimit attribute stores max and interval', function () {
        $attr = new RateLimit(max: 10, intervalInMinutes: 5);
        expect($attr->max)->toBe(10);
        expect($attr->intervalInMinutes)->toBe(5);
    });

    it('RateLimit attribute defaults to 5 minute interval', function () {
        $attr = new RateLimit(max: 10);
        expect($attr->intervalInMinutes)->toBe(5);
    });

});
