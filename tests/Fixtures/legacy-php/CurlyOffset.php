<?php

class Rights
{
    function check($session)
    {
        $level = $session['TBLDROIT2']{2};
        $this->allow($level{0}, "{$level} ${level}");
        return $level;
    }

    function allow($level, $label)
    {
    }
}
