<?php
namespace ServerSpan\LogicBoxesTools;

final class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly array $response = []
    ) {
        parent::__construct($message);
    }
}
