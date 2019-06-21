<?php

/*
 * This file is part of the Allegro framework.
 *
 * (c) 2019 Go Financial Technologies, JSC
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GoFinTech\Allegro\Implementation;


use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Default logger for Allegro that comes out of the box.
 * @package GoFinTech\Allegro\Implementation
 */
class ConsoleLogger extends AbstractLogger
{
    private $STDERR;
    /** @var bool */
    private $forceStdErr;

    public function __construct()
    {
        $this->STDERR = fopen('php://stderr', 'w');
    }

    /**
     * Forces all output to go on STDERR, not only the WARNING and above
     * @param bool $force
     */
    public function setForceOutputToStdErr(bool $force): void
    {
        $this->forceStdErr = $force;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function log($level, $message, array $context = array())
    {
        $lvl = ($level == LogLevel::INFO) ? '' : strtoupper($level) . ' ';

        $ex = $context['exception'] ?? null;
        if ($ex instanceof Throwable) {
            $msg = "$lvl$message [" . $this->formatException($ex) . "]\n"
                . $ex->getTraceAsString() . "\n";
        }
        else {
            $msg = "$lvl$message\n";
        }

        if ($this->forceStdErr) {
            $outputToStdErr = true;
        }
        else {
            switch ($level) {
                case LogLevel::NOTICE:
                case LogLevel::INFO:
                case LogLevel::DEBUG:
                    $outputToStdErr = false;
                    break;
                default:
                    $outputToStdErr = true;
                    break;
            }
        }

        if ($outputToStdErr) {
            fwrite($this->STDERR, $msg);
        }
        else {
            echo $msg;
        }
    }

    private function formatException(Throwable $ex): string
    {
        $className = get_class($ex);
        return "{$ex->getFile()}:{$ex->getLine()} $className: {$ex->getMessage()}";
    }
}
