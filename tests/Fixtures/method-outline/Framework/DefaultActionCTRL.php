<?php

/**
 * The parent of every controller: a bit of everything.
 */
class DefaultActionCTRL
{
    function addToForwardResponse($key, $value)
    {
    }

    function exportCSV($rows)
    {
        if ($rows === array()) {
            throw new Exception('Nothing to export');
        }
    }

    function sendMail($to)
    {
    }

    function testConnection()
    {
    }
}
