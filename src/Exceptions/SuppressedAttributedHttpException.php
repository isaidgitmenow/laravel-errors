<?php
// file: src/Exceptions/SuppressedAttributedHttpException.php

declare(strict_types=1);

namespace Isaidgitmenow\LaravelErrors\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/** #[DontReport] pe o clasă mapată: contractul nativ, verificat PRIMUL în Handler::shouldntReport(). */
final class SuppressedAttributedHttpException extends AttributedHttpException implements ShouldntReport {}
