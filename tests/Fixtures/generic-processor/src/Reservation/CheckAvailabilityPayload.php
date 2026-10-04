<?php

namespace App\Reservation;

use App\Shared\PayloadInterface;

final class CheckAvailabilityPayload implements PayloadInterface
{
    public function toQuery(): CheckAvailabilityQuery
    {
        return new CheckAvailabilityQuery();
    }
}
