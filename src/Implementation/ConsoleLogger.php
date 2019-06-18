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

        switch ($level) {
            case LogLevel::NOTICE:
            case LogLevel::INFO:
            case LogLevel::DEBUG:
                echo $msg;
                break;
            default:
                fwrite(STDERR, $msg);
                break;
        }
    }

    private function formatException(Throwable $ex): string
    {
        $className = get_class($ex);
        return "{$ex->getFile()}:{$ex->getLine()} $className: {$ex->getMessage()}";
    }
}
