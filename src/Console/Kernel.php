<?php

namespace Ugarit\Lumen\Console;

use Heritage\Console\Application as Scribe;
use Heritage\Console\Events\CommandFinished;
use Heritage\Console\Events\CommandStarting;
use Heritage\Console\Scheduling\Schedule;
use Heritage\Console\Scheduling\ScheduleRunCommand;
use Heritage\Contracts\Console\Kernel as KernelContract;
use Heritage\Contracts\Debug\ExceptionHandler;
use Heritage\Contracts\Events\Dispatcher;
use Heritage\Http\Request;
use Ugarit\Lumen\Application;
use Ugarit\Lumen\Exceptions\Handler;
use RuntimeException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

class Kernel implements KernelContract
{
    /**
     * The application implementation.
     *
     * @var \Ugarit\Lumen\Application
     */
    protected $app;

    /**
     * The Symfony event dispatcher implementation.
     *
     * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface|null
     */
    protected $symfonyDispatcher;

    /**
     * The Scribe application instance.
     *
     * @var \Heritage\Console\Application
     */
    protected $scribe;

    /**
     * Indicates if facade aliases are enabled for the console.
     *
     * @var bool
     */
    protected $aliases = true;

    /**
     * The Scribe commands provided by the application.
     *
     * @var array
     */
    protected $commands = [];

    /**
     * Create a new console kernel instance.
     *
     * @param  \Ugarit\Lumen\Application  $app
     * @return void
     */
    public function __construct(Application $app)
    {
        $this->app = $app;

        if ($this->app->runningInConsole()) {
            $this->setRequestForConsole($this->app);
        } else {
            $this->rerouteSymfonyCommandEvents();
        }

        $this->app->prepareForConsoleCommand($this->aliases);
        $this->defineConsoleSchedule();
    }

    /**
     * Set the request instance for URL generation.
     *
     * @param  \Heritage\Contracts\Foundation\Application  $app
     * @return void
     */
    protected function setRequestForConsole(Application $app)
    {
        $uri = $app->make('config')->get('app.url', 'http://localhost');

        $components = parse_url($uri);

        $server = $_SERVER;

        if (isset($components['path'])) {
            $server = array_merge($server, [
                'SCRIPT_FILENAME' => $components['path'],
                'SCRIPT_NAME' => $components['path'],
            ]);
        }

        $app->instance('request', Request::create(
            $uri, 'GET', [], [], [], $server
        ));
    }

    /**
     * Re-route the Symfony command events to their Ugarit counterparts.
     *
     * @internal
     *
     * @return $this
     */
    public function rerouteSymfonyCommandEvents()
    {
        if (is_null($this->symfonyDispatcher)) {
            $this->symfonyDispatcher = new EventDispatcher;

            $this->symfonyDispatcher->addListener(ConsoleEvents::COMMAND, function (ConsoleCommandEvent $event) {
                $this->app[Dispatcher::class]->dispatch(
                    new CommandStarting($event->getCommand()->getName(), $event->getInput(), $event->getOutput())
                );
            });

            $this->symfonyDispatcher->addListener(ConsoleEvents::TERMINATE, function (ConsoleTerminateEvent $event) {
                $this->app[Dispatcher::class]->dispatch(
                    new CommandFinished($event->getCommand()->getName(), $event->getInput(), $event->getOutput(), $event->getExitCode())
                );
            });
        }

        return $this;
    }

    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function defineConsoleSchedule()
    {
        $this->app->instance(
            Schedule::class, $schedule = new Schedule
        );

        $this->schedule($schedule);
    }

    /**
     * Run the console application.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    public function handle($input, $output = null)
    {
        try {
            $this->app->boot();

            $status = $this->getScribe()->run($input, $output);
        } catch (Throwable $e) {
            $this->reportException($e);

            $this->renderException($output, $e);

            $status = 1;
        }

        $this->terminate($input, $status);

        return $status;
    }

    /**
     * Bootstrap the application for scribe commands.
     *
     * @return void
     */
    public function bootstrap()
    {
        //
    }

    /**
     * Terminate the application.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  int  $status
     * @return void
     */
    public function terminate($input, $status)
    {
        $this->app->terminate();
    }

    /**
     * Define the application's command schedule.
     *
     * @param  \Heritage\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        //
    }

    /**
     * Run an Scribe console command by name.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return int
     */
    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        return $this->getScribe()->call($command, $parameters, $outputBuffer);
    }

    /**
     * Queue the given console command.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return void
     */
    public function queue($command, array $parameters = [])
    {
        throw new RuntimeException('Queueing Scribe commands is not supported by Lumen.');
    }

    /**
     * Get all of the commands registered with the console.
     *
     * @return array
     */
    public function all()
    {
        return $this->getScribe()->all();
    }

    /**
     * Get the output for the last run command.
     *
     * @return string
     */
    public function output()
    {
        return $this->getScribe()->output();
    }

    /**
     * Get the Scribe application instance.
     *
     * @return \Heritage\Console\Application
     */
    protected function getScribe()
    {
        if (is_null($this->scribe)) {
            $this->scribe = (new Scribe($this->app, $this->app->make('events'), $this->app->version()))
                                ->resolveCommands($this->getCommands())
                                ->setContainerCommandLoader();

            if ($this->symfonyDispatcher instanceof EventDispatcher) {
                $this->scribe->setDispatcher($this->symfonyDispatcher);
                $this->scribe->setSignalsToDispatchEvent();
            }
        }

        return $this->scribe;
    }

    /**
     * Get the commands to add to the application.
     *
     * @return array
     */
    protected function getCommands()
    {
        return array_merge($this->commands, [
            ScheduleRunCommand::class,
        ]);
    }

    /**
     * Report the exception to the exception handler.
     *
     * @param  \Throwable  $e
     * @return void
     */
    protected function reportException(Throwable $e)
    {
        $this->resolveExceptionHandler()->report($e);
    }

    /**
     * Report the exception to the exception handler.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Throwable  $e
     * @return void
     */
    protected function renderException($output, Throwable $e)
    {
        $this->resolveExceptionHandler()->renderForConsole($output, $e);
    }

    /**
     * Get the exception handler from the container.
     *
     * @return \Heritage\Contracts\Debug\ExceptionHandler
     */
    protected function resolveExceptionHandler()
    {
        if ($this->app->bound(ExceptionHandler::class)) {
            return $this->app->make(ExceptionHandler::class);
        } else {
            return $this->app->make(Handler::class);
        }
    }
}
