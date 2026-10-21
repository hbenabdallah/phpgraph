<?php

// @phpgraph-todo ContainerInterface::get() returns mixed: no provider types it by the class it is given

namespace Psr\Container {
    interface ContainerInterface
    {
        /** @return mixed */
        public function get(string $id);
    }
}

namespace App {
    use Psr\Container\ContainerInterface;

    class Mailer { public function send(): void {} }
    class Notifier { public function send(): void {} }

    class Controller
    {
        public function __construct(private ContainerInterface $container) {}

        public function notify(): void
        {
            $this->container->get(Mailer::class)->send(); // @phpgraph-expect calls Psr\Container\ContainerInterface::get INFERRED @phpgraph-expect calls App\Mailer::send INFERRED
        }
    }
}
