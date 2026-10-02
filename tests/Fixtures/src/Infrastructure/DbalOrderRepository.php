<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure;

use App\Sales\Domain\Order;
use App\Sales\Domain\OrderRepository;
use Doctrine\DBAL\Connection;

final class DbalOrderRepository implements OrderRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(Order $order): void
    {
        $this->connection->executeStatement('SELECT 1');
    }
}
