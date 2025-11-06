<?php
namespace FedEx\Http;

class HttpException extends \RuntimeException
{
    /**
     * @var int|null
     */
    private $statusCode;

    public function __construct(string $message, int $statusCode = null, \Throwable $previous = null)
    {
        parent::__construct($message, $statusCode ?? 0, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
