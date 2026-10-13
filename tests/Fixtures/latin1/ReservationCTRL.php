<?php

/**
 * Contrôleur des réservations : crée et annule une réservation.
 */
class ReservationCTRL
{
    function annuler($id)
    {
        if ($id === null) {
            throw new Exception('Réservation introuvable ¤LAB_RS01¤');
        }
        $this->charger($id);
    }

    function charger($id)
    {
    }
}
