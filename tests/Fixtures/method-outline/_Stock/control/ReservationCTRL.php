<?php

class ReservationCTRL extends DefaultActionCTRL
{
    function saveNewReservation($reservation)
    {
        $connection = Context::getDBconnection();
        $societe = SocieteDAO::getInstance($connection);
        $dao = new ReservationDAO();
        $dao->saveReservation($reservation);
        $this->addToForwardResponse('reservation', $reservation);
    }

    function showReservations()
    {
        $this->exportCSV(array());
    }
}
