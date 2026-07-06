<?php
namespace BtIpay\Opencart\Sdk;

use BTransilvania\Api\Model\Response\ResponseModelInterface;

class Response
{
    protected ResponseModelInterface $response;

    public function __construct(ResponseModelInterface $response) {
        $this->response = $response;
    }

    public function getErrorMessage(): string
    {
        return $this->response->getErrorMessage() ?? 'Unknown error, error code: '.$this->response->getErrorCode();
    }

    public function isSuccess(): bool
    {
        return $this->response->isSuccess();
    }
}