<?php

// interne() is called from its own class only: not an entry point.
class A
{
    function publique()
    {
        return $this->interne();
    }

    function interne()
    {
        return 1;
    }
}
