<?php

/*
 * This file is part of the Allegro framework.
 *
 * (c) 2019-2021 Go Financial Technologies, JSC
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GoFinTech\Allegro;

use ErrorException;
use Exception;
use GoFinTech\Allegro\Implementation\ConsoleLogger;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Config\Exception\FileLocatorFileNotFoundException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Core Allegro application class.
 * It contains shared components that are essential
 * to any application, regardless of its type.
 *
 * @package GoFinTech\Allegro
 */
class AllegroApp
{
    /** @var string */
    private $appDir;
    /** @var ContainerBuilder */
    private $container;
    /** @var FileLocator */
    private $configLocator;
    /** @var bool|null null if functionality is not available */
    private $termSignalReceived;
    /** @var bool */
    private $loggerFailed;

    /**
     * Initializes the core components.
     */
    public function __construct()
    {
        self::installErrorHandler();
        try {
            $this->appDir = $this->findApplicationDir();
            $this->configLocator = new FileLocator(["{$this->appDir}/config", $this->appDir]);
            $this->container = $this->loadServiceDefinitions($this->configLocator);
            $this->container->set('app', $this);
            $this->container->set('GoFinTech\Allegro\AllegroApp', $this);
            $this->container->set(AllegroApp::class, $this);
        }
        catch (RuntimeException $ex) {
            throw $ex;
        }
        catch (Exception $ex) {
            throw new RuntimeException("Allegro initialization error ({$ex->getMessage()})", 0, $ex);
        }
    }

    /**
     * Returns application top level directory
     * @return string
     */
    public function getAppDir(): string
    {
        return $this->appDir;
    }

    /**
     * Returns dependency injection container
     * @return ContainerBuilder
     */
    public function getContainer(): ContainerBuilder
    {
        return $this->container;
    }

    /**
     * Returns configuration parameter value.
     * Makes sure the parameter placeholders are resolved.
     *
     * @param string $name Parameter name
     * @return mixed Parameter value
     */
    public function getParameter(string $name)
    {
        $value = $this->container->getParameter($name);
        if ($this->container->isCompiled())
            return $value;
        else
            return $this->container->resolveEnvPlaceholders($value, true);
    }

    /**
     * Returns locator for loading additional configuration.
     * Mainly useful for Allegro extensions.
     *
     * @return FileLocator
     */
    public function getConfigLocator(): FileLocator
    {
        return $this->configLocator;
    }

    /**
     * Installs PHP error handler that raises exceptions by default.
     * There is no need to call this method directly as it is called by AllegroApp automatically.
     * However, it may be useful in scripts that do advanced initialization
     * before running any AllegroApp.
     */
    public static function installErrorHandler(): void
    {
        set_error_handler([self::class, 'phpErrorHandler']);
    }

    /**
     * "Standard" exception-raising error handler.
     * @param $severity
     * @param $message
     * @param $file
     * @param $line
     * @throws ErrorException
     */
    public static function phpErrorHandler($severity, $message, $file, $line)
    {
        if (!(error_reporting() & $severity)) {
            // This error code is not included in error_reporting
            return;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    private function installSigTermHandler()
    {
        if (function_exists('pcntl_signal')) {
            /** @noinspection PhpComposerExtensionStubsInspection */
            pcntl_signal(SIGTERM, [$this, 'phpSigTermHandler']);
            $this->termSignalReceived = false;
        } else {
            $this->termSignalReceived = null;
        }
    }

    private function phpSigTermHandler(
        /** @noinspection PhpUnusedParameterInspection */
        $signo)
    {
        $this->termSignalReceived = true;
    }

    public function isTermSignalReceived()
    {
        if (is_null($this->termSignalReceived))
            return false;

        /** @noinspection PhpComposerExtensionStubsInspection */
        pcntl_signal_dispatch();

        return $this->termSignalReceived;
    }

    /**
     * Locates the application top level directory.
     * @return string path to app dir
     */
    private function findApplicationDir(): string
    {
        $dir = getenv('ALLEGRO_APP_DIR');
        if ($dir)
            return $dir;

        $dir = getcwd();
        $dir = strtr($dir, '\\', '/');
        while ($dir) {
            if (file_exists("$dir/config/allegro.yml"))
                return $dir;
            $slash = strrpos($dir, '/');
            if ($slash === false)
                break;
            $dir = substr($dir, 0, $slash);
        }
        throw new RuntimeException("Allegro app dir not found. Did you forget to add allegro.yml?");
    }

    /**
     * @param FileLocator $locator
     * @return Container
     * @throws Exception
     */
    private function loadServiceDefinitions(FileLocator $locator): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $loader = new YamlFileLoader($builder, $locator);

        $configFile = getenv('ALLEGRO_ENV_CONFIG');
        $configFileRequired = true;

        if (empty($configFile)) {
            $configFile = 'config.yml';
            $configFileRequired = false;
        }

        $builder->setParameter('allegro.appDir', $this->appDir);
        
        $loader->load('allegro.yml');
        try {
            $loader->load($configFile);
        }
        catch (FileLocatorFileNotFoundException $ex) {
            if ($configFileRequired)
                throw $ex;
        }
        $loader->load('vendor/gofintech/allegro/config/services.yml');
        $loader->load('services.yml');

        return $builder;
    }

    /**
     * Prepares application for runtime
     */
    public function compile(): void
    {
        $this->installSigTermHandler();
        $this->container->compile(true);
    }

    /**
     * Shorthand for getContainer()->get('logger')
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        /** @var LoggerInterface $logger */
        try {
            $logger = $this->container->get('logger');
        } catch (Exception $ex) {
            $logger = new ConsoleLogger();
            if (!$this->loggerFailed) {
                $this->loggerFailed = true;
                $logger->warning(
                    'Original logger initialization failed, falling back to ConsoleLogger.',
                    ['exception' => $ex]
                );
            }
        }
        return $logger;
    }

    /**
     * Notifies an external monitoring system that the process is alive.
     */
    public function ping(): void
    {
        touch('/tmp/allegro.ping');
    }

    /**
     * Common check for known but often overlooked errors
     * that do not self-correct.
     *
     * For use in main loops that suppress runtime exceptions
     * for performance reasons.
     *
     * Normally the runtime checks such as liveness probes
     * should take care of the process health but these are often
     * overlooked. So this is a compromise between having checks
     * in every application and adding hacks to exception
     * handlers in the main loops, not punishing app developers
     * despite them deserving it.
     *
     * @param Exception $ex
     * @return bool
     */
    public function isExceptionDeadly($ex): bool
    {
        if (get_class($ex) == 'PDOException' && $ex->getCode() == 'HY000') {
            // PostgreSQL PDO: SQLSTATE[HY000]: General error: 7 no connection to the server
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * @param string $class
     * @param $configSection
     * @param $legacyConfigSection
     * @return AllegroApp
     * @internal For use with app implementations to handle constructor parameter logic.
     */
    public static function resolveConstructorParameters(string $class, &$configSection, $legacyConfigSection): AllegroApp
    {
        if (is_object($configSection) && $configSection instanceof AllegroApp) {
            $app = $configSection;
            $configSection = $legacyConfigSection;
            if (!is_string($configSection))
                throw new InvalidArgumentException("$class::_construct() legacy AllegroApp provided without config section name");
            return $app;
        }
        if (is_string($configSection)) {
            return new AllegroApp();
        }
        throw new InvalidArgumentException("$class::_construct() unsupported parameter combination");
    }
}
