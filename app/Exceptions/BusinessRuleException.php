<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a request violates a business rule or state-machine constraint.
 * Rendered as a consistent JSON error envelope by bootstrap/app.php.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'business_rule_violation',
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
