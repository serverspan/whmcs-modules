<?php

declare(strict_types=1);

namespace ServerSpan\WHMCS\ColeteOnline;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?array $response = null
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function response(): ?array
    {
        return $this->response;
    }
}
