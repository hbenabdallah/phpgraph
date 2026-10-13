<?php

ReservationDAO::purgeArchives(400);
ReservationDAO::saveReservation(null);
SocieteDAO::getInstance(null);
