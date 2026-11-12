<?php
declare(strict_types=1);

namespace FroggaBio\DataMigration;

final class Logger
{
    /** @var resource|null */
    private $fh = null;
    private int $warnings = 0;

    public function __construct(?string $file, private readonly bool $verbose = false)
    {
        if ($file) {
            @mkdir(dirname($file), 0775, true);
            $fh = @fopen($file, 'ab');
            $this->fh = $fh ?: null;
        }
    }

    public function info(string $msg): void
    {
        $this->write('INFO', $msg, true);
    }

    public function warn(string $msg): void
    {
        $this->warnings++;
        $this->write('WARN', $msg, true);
    }

    public function debug(string $msg): void
    {
        $this->write('DEBUG', $msg, $this->verbose);
    }

    public function section(string $title): void
    {
        $this->write('INFO', str_repeat('=', 8) . " $title " . str_repeat('=', max(4, 60 - strlen($title))), true);
    }

    public function warnings(): int
    {
        return $this->warnings;
    }

    private function write(string $level, string $msg, bool $console): void
    {
        $line = sprintf('[%s] %-5s %s', date('H:i:s'), $level, $msg);
        if ($console) {
            fwrite($level === 'WARN' ? STDERR : STDOUT, $line . PHP_EOL);
        }
        if ($this->fh) {
            fwrite($this->fh, '[' . date('Y-m-d') . ' ' . substr($line, 1) . PHP_EOL);
        }
    }
}
