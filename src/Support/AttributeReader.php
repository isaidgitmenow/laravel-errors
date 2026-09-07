<?php
// file: src/Support/AttributeReader.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Support;

use Isaidgitmenow\LaravelErrors\Attributes\Audit;
use Isaidgitmenow\LaravelErrors\Attributes\DontReport;
use Isaidgitmenow\LaravelErrors\Attributes\ErrorCode;
use Isaidgitmenow\LaravelErrors\Attributes\HttpCode;
use Isaidgitmenow\LaravelErrors\Attributes\LogAs;
use Isaidgitmenow\LaravelErrors\Attributes\RateLimit;
use Isaidgitmenow\LaravelErrors\Attributes\ReportTo;
use Isaidgitmenow\LaravelErrors\Attributes\RetryAfter;
use Isaidgitmenow\LaravelErrors\Attributes\Sensitive;
use Isaidgitmenow\LaravelErrors\Attributes\TranslatedMessage;
use Isaidgitmenow\LaravelErrors\Attributes\WithContext;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Singura clasă care face reflecție pe atributele pachetului.
 * Produce un array normalizat, serializabil (cache-uibil), fără instanțe de atribute
 * — cu excepția RateLimit, care e readonly și mic; se stochează ca array și se reconstruiește.
 */
final class AttributeReader
{
    /**
     * @param class-string $class
     * @return array<string, mixed>  gol dacă clasa nu are niciun atribut al pachetului
     */
    public function read(string $class): array
    {
        $ref  = new ReflectionClass($class);
        $data = [];

        if ($a = $this->first($ref, HttpCode::class)) {
            $data['http_code'] = $a->newInstance()->code;
        }
        if ($this->first($ref, DontReport::class)) {
            $data['dont_report'] = true;
        }
        if ($a = $this->first($ref, ReportTo::class)) {
            $i = $a->newInstance();
            $data['report_to']              = is_array($i->channels) ? $i->channels : [$i->channels];
            $data['report_to_environments'] = $i->environments;
        }
        if ($a = $this->first($ref, TranslatedMessage::class)) {
            $i = $a->newInstance();
            $data['translated_message'] = ['key' => $i->key, 'params' => $i->params, 'choice' => $i->choice];
        }
        if ($a = $this->first($ref, ErrorCode::class)) {
            $i = $a->newInstance();
            $data['error_code'] = ['code' => $i->code, 'type' => $i->type, 'title' => $i->title];
        }
        if ($a = $this->first($ref, LogAs::class)) {
            $data['log_as'] = $a->newInstance()->level;
        }
        if ($a = $this->first($ref, RetryAfter::class)) {
            $data['retry_after'] = $a->newInstance()->seconds;
        }
        if ($a = $this->first($ref, RateLimit::class)) {
            $i = $a->newInstance();
            $data['rate_limit'] = ['max' => $i->max, 'interval' => $i->intervalInMinutes, 'by' => $i->by];
        }
        if ($a = $this->first($ref, Audit::class)) {
            $i = $a->newInstance();
            $data['audit'] = ['retention' => $i->retention, 'category' => $i->category];
        }

        // #[WithContext] pe clasă (proprietăți) și pe metode publice
        if ($a = $this->first($ref, WithContext::class)) {
            $i = $a->newInstance();
            $data['with_context']           = $i->properties;
            $data['with_context_sensitive'] = $i->sensitive;   // ['iban' => 'last4'] pentru array-uri din metode
        }
        $methods = [];
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getAttributes(WithContext::class) !== []) {
                $methods[] = $m->getName();
            }
        }
        if ($methods !== []) {
            $data['with_context_methods'] = $methods;
        }

        // #[Sensitive] pe proprietăți (inclusiv promovate). Cele ne-publice nu pot fi extrase de context() —
        // le raportăm separat ca validatorul (I-13) să avertizeze.
        $sensitive = [];
        $nonPublic = [];
        foreach ($ref->getProperties() as $p) {
            $attrs = $p->getAttributes(Sensitive::class);
            if ($attrs === []) {
                continue;
            }
            if ($p->isPublic()) {
                $sensitive[$p->getName()] = $attrs[0]->newInstance()->mask;
            } else {
                $nonPublic[] = $p->getName();
            }
        }
        if ($sensitive !== []) {
            $data['sensitive'] = $sensitive;
        }
        if ($nonPublic !== []) {
            $data['sensitive_non_public'] = $nonPublic;
        }

        return $data;
    }

    private function first(ReflectionClass $ref, string $attribute): ?\ReflectionAttribute
    {
        $attrs = $ref->getAttributes($attribute);

        return $attrs === [] ? null : $attrs[0];
    }
}
