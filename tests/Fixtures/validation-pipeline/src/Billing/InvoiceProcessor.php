<?php

namespace App\Billing;

final class InvoiceProcessor
{
    public function __construct(private Invoice $useCase)
    {
    }

    public function process(object $data): void
    {
        $this->useCase->handle($data);
    }
}
