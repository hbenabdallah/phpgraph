<?php

/**
 * DAO of the reservations.
 */
class ReservationDAO
{
    function saveReservation($reservation)
    {
        $this->checkQuantity($reservation);
    }

    function checkQuantity($reservation)
    {
        if ($reservation->quantity <= 0) {
            throw new Exception('Not enough quantity');
        }
    }

    function purgeArchives($days)
    {
        if ($days > 365) {
            throw new Exception('Too old to purge');
        }
    }
}
