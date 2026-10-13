<?php

class Router
{
    var $handler;

    function Router()
    {
        $handler =& new Handler();
        $handler->run();
    }
}

class Handler
{
    function run()
    {
    }
}
