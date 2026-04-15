<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Contract;

use ChenZhanjie\Agentic\Contract\SpanInterface;

interface TraceExporterInterface
{
    public function export(SpanInterface $span): void;
    public function flush(): void;
}
