<?php

// The parent of every controller: its actions are run on the controller the action names.
class Base
{
    function action()
    {
        return $this->autre();
    }

    function autre()
    {
        return 1;
    }
}
