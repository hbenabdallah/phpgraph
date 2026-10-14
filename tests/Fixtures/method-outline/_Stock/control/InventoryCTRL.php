<?php

class InventoryCTRL
{
    function close($id)
    {
        return StockCTRL::moveStock($id);
    }
}
