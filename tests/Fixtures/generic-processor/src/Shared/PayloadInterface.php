<?php

namespace App\Shared;

interface PayloadInterface
{
    public function toQuery(): object;
}
