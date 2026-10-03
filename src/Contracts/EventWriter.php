<?php

declare(strict_types=1);

namespace LogMonitor\Contracts;

interface EventWriter
{
    public function write(array $event): void;
}
