<?php

namespace App\Exceptions;

class SecurityReportExportException extends \RuntimeException
{
    // Custom properties specific to security report export failures can be added here
    protected string $reportId;
    protected string $format;

    public function __construct(string $message = '', string $reportId = '', string $format = '', int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->reportId = $reportId;
        $this->format = $format;
    }

    public function getReportId(): string
    {
        return $this->reportId;
    }

    public function getFormat(): string
    {
        return $this->format;
    }
}
