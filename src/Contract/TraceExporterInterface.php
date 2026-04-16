<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

interface TraceExporterInterface
{
    public function export(SpanInterface $span): void;
    public function flush(): void;
}
