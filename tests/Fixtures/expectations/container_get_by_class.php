<?php

// A PSR-11 container asked for a class gives an instance of it (Libraries\PsrContainer).

namespace Psr\Container {
    interface ContainerInterface
    {
        /** @return mixed */
        public function get(string $id);
    }
}

namespace Illuminate\Contracts\Container {
    interface Container extends \Psr\Container\ContainerInterface
    {
        /** @return mixed */
        public function make(string $abstract, array $parameters = []);
    }
}

namespace Symfony\Component\DependencyInjection {
    interface ContainerInterface extends \Psr\Container\ContainerInterface
    {
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

        public function byId(): void
        {
            // A service id says nothing of its class: two classes declare send(), nothing is guessed.
            $this->container->get('mailer')->send(); // @phpgraph-expect calls Psr\Container\ContainerInterface::get INFERRED
        }

        public function symfony(\Symfony\Component\DependencyInjection\ContainerInterface $container): void
        {
            $container->get(Notifier::class)->send(); // @phpgraph-expect calls Psr\Container\ContainerInterface::get INFERRED @phpgraph-expect calls App\Notifier::send INFERRED
        }

        public function laravel(\Illuminate\Contracts\Container\Container $app): void
        {
            // A container building a class: Laravel's make(), documented `mixed` before Laravel 10.
            $app->make(Notifier::class)->send(); // @phpgraph-expect calls Illuminate\Contracts\Container\Container::make INFERRED @phpgraph-expect calls App\Notifier::send INFERRED
        }
    }
}
