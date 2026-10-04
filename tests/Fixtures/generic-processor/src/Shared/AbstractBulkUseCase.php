<?php

namespace App\Shared;

abstract class AbstractBulkUseCase
{
    public function __invoke(object $query): void
    {
        $this->handleItem($query);
    }

    abstract protected function handleItem(object $query): void;
}
