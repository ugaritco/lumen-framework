<?php

use Heritage\Console\Events\CommandFinished;
use Heritage\Console\Events\CommandStarting;
use Heritage\Contracts\Console\Kernel as ConsoleKernelContract;
use Heritage\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Ugarit\Lumen\Application;
use Ugarit\Lumen\Console\Kernel as ConsoleKernel;
use Ugarit\Lumen\Exceptions\Handler as ExceptionHandler;

class KernelTest extends \Ugarit\Lumen\Testing\TestCase
{
    /**
     * Creates the application.
     *
     * Needs to be implemented by subclasses.
     *
     * @return \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    public function createApplication()
    {
        $app = new Application();

        $app->configure('app');

        $app->singleton(ExceptionHandlerContract::class, fn () => new ExceptionHandler());
        $app->singleton(ConsoleKernelContract::class, function () use ($app) {
            return tap(new ConsoleKernel($app), function ($kernel) {
                $kernel->rerouteSymfonyCommandEvents();
            });
        });

        return $app;
    }

    public function testItCanRerouteToSymfonyEvent()
    {
        $this->expectsEvents([CommandStarting::class, CommandFinished::class]);

        $this->scribe('cache:forget', ['key' => 'lumen']);
    }
}
