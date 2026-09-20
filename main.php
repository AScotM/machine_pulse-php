<?php

declare(strict_types=1);

const ATTR_NORMAL = 0;
const ATTR_BOLD = 1;
const ATTR_DIM = 2;

final class CPUStats
{
    public int $total = 0;
    public int $idle = 0;
}

final class MemoryStats
{
    public int $total = 0;
    public int $available = 0;
    public int $used = 0;
    public int $swapTotal = 0;
    public int $swapFree = 0;
}

final class DiskStats
{
    public int $readBytes = 0;
    public int $writeBytes = 0;
}

final class NetworkStats
{
    public int $rxBytes = 0;
    public int $txBytes = 0;
}

final class PSIStats
{
    public float $someAvg10 = 0.0;
    public float $someAvg60 = 0.0;
    public float $someAvg300 = 0.0;
    public float $fullAvg10 = 0.0;
    public float $fullAvg60 = 0.0;
    public float $fullAvg300 = 0.0;
}

final class RawSample
{
    public float $timestamp = 0.0;
    public CPUStats $cpu;
    public MemoryStats $memory;
    public DiskStats $disk;
    public NetworkStats $network;
    public float $load1 = 0.0;
    public float $load5 = 0.0;
    public float $load15 = 0.0;
    public int $processes = 0;
    public int $running = 0;
    public float $uptime = 0.0;
    public PSIStats $cpuPressure;
    public PSIStats $memPressure;
    public PSIStats $ioPressure;
    public int $context = 0;
    public int $interrupts = 0;
    public int $forks = 0;
}

final class Metrics
{
    public float $cpuPercent = 0.0;
    public float $memoryPercent = 0.0;
    public float $swapPercent = 0.0;
    public float $load1 = 0.0;
    public float $load5 = 0.0;
    public float $load15 = 0.0;
    public float $loadNormalized = 0.0;
    public int $processCount = 0;
    public int $runningProcesses = 0;
    public float $diskReadRate = 0.0;
    public float $diskWriteRate = 0.0;
    public float $networkRXRate = 0.0;
    public float $networkTXRate = 0.0;
    public float $cpuPSI = 0.0;
    public float $memoryPSI = 0.0;
    public float $ioPSI = 0.0;
    public float $contextRate = 0.0;
    public float $interruptRate = 0.0;
    public float $forkRate = 0.0;
    public float $uptime = 0.0;
}

final class PulseSnapshot
{
    public Metrics $metrics;
    public float $pulse = 0.0;
    public float $pressure = 0.0;
    public float $vitality = 0.0;
    public string $state = 'DORMANT';
    public string $description = '';
    public array $pulseHistory = [];
    public float $lastUpdated = 0.0;
    public int $sampleCounter = 0;
}

function clampFloat(float $value, float $minimum, float $maximum): float
{
    if ($value < $minimum) {
        return $minimum;
    }

    if ($value > $maximum) {
        return $maximum;
    }

    return $value;
}

function normalizeLog(float $value, float $reference): float
{
    if ($value <= 0.0 || $reference <= 0.0) {
        return 0.0;
    }

    return clampFloat(
        log1p($value) / log1p($reference),
        0.0,
        1.0
    );
}

function safeRate(int $current, int $previous, float $elapsed): float
{
    if ($elapsed <= 0.0 || $current < $previous) {
        return 0.0;
    }

    return ($current - $previous) / $elapsed;
}

function readTextFile(string $path): string
{
    $data = @file_get_contents($path);

    if ($data === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    return $data;
}

function cpuCount(): int
{
    $data = @file_get_contents('/proc/cpuinfo');

    if ($data === false) {
        return 1;
    }

    if (preg_match_all('/^processor\s*:/m', $data, $matches) === false) {
        return 1;
    }

    $count = count($matches[0]);

    return max(1, $count);
}

function readCPUStats(): array
{
    $handle = @fopen('/proc/stat', 'r');

    if ($handle === false) {
        throw new RuntimeException('Unable to open /proc/stat');
    }

    $stats = new CPUStats();
    $context = 0;
    $interrupts = 0;
    $forks = 0;
    $foundCPU = false;

    try {
        while (($line = fgets($handle)) !== false) {
            $fields = preg_split('/\s+/', trim($line));

            if ($fields === false || count($fields) === 0) {
                continue;
            }

            switch ($fields[0]) {
                case 'cpu':
                    if (count($fields) < 5) {
                        continue 2;
                    }

                    $values = [];

                    for ($i = 1; $i < count($fields); $i++) {
                        $values[] = (int)$fields[$i];
                    }

                    $total = 0;
                    $limit = min(8, count($values));

                    for ($i = 0; $i < $limit; $i++) {
                        $total += $values[$i];
                    }

                    $idle = $values[3] ?? 0;
                    $idle += $values[4] ?? 0;

                    $stats->total = $total;
                    $stats->idle = $idle;
                    $foundCPU = true;
                    break;

                case 'ctxt':
                    $context = isset($fields[1])
                        ? (int)$fields[1]
                        : 0;
                    break;

                case 'intr':
                    $interrupts = isset($fields[1])
                        ? (int)$fields[1]
                        : 0;
                    break;

                case 'processes':
                    $forks = isset($fields[1])
                        ? (int)$fields[1]
                        : 0;
                    break;
            }
        }
    } finally {
        fclose($handle);
    }

    if (!$foundCPU) {
        throw new RuntimeException('CPU statistics unavailable');
    }

    return [
        $stats,
        $context,
        $interrupts,
        $forks,
    ];
}

function readMemoryStats(): MemoryStats
{
    $handle = @fopen('/proc/meminfo', 'r');

    if ($handle === false) {
        throw new RuntimeException('Unable to open /proc/meminfo');
    }

    $values = [];

    try {
        while (($line = fgets($handle)) !== false) {
            if (!preg_match(
                '/^([^:]+):\s+(\d+)/',
                $line,
                $matches
            )) {
                continue;
            }

            $values[$matches[1]] = (int)$matches[2] * 1024;
        }
    } finally {
        fclose($handle);
    }

    $stats = new MemoryStats();

    $stats->total = $values['MemTotal'] ?? 0;
    $stats->available = $values['MemAvailable'] ?? 0;

    if ($stats->available === 0) {
        $stats->available =
            ($values['MemFree'] ?? 0) +
            ($values['Buffers'] ?? 0) +
            ($values['Cached'] ?? 0) +
            ($values['SReclaimable'] ?? 0);
    }

    if ($stats->total > $stats->available) {
        $stats->used =
            $stats->total -
            $stats->available;
    }

    $stats->swapTotal = $values['SwapTotal'] ?? 0;
    $stats->swapFree = $values['SwapFree'] ?? 0;

    return $stats;
}

function readLoad(): array
{
    $data = trim(readTextFile('/proc/loadavg'));
    $fields = preg_split('/\s+/', $data);

    if ($fields === false || count($fields) < 4) {
        throw new RuntimeException('Invalid /proc/loadavg');
    }

    $load1 = (float)$fields[0];
    $load5 = (float)$fields[1];
    $load15 = (float)$fields[2];

    $running = 0;
    $processParts = explode('/', $fields[3], 2);

    if (count($processParts) === 2) {
        $running = (int)$processParts[0];
    }

    return [
        $load1,
        $load5,
        $load15,
        $running,
    ];
}

function readUptime(): float
{
    $data = trim(readTextFile('/proc/uptime'));
    $fields = preg_split('/\s+/', $data);

    if ($fields === false || count($fields) < 1) {
        throw new RuntimeException('Invalid /proc/uptime');
    }

    return (float)$fields[0];
}

function readProcessCount(): int
{
    $entries = @scandir('/proc');

    if ($entries === false) {
        return 0;
    }

    $count = 0;

    foreach ($entries as $entry) {
        if ($entry === '' || !ctype_digit($entry)) {
            continue;
        }

        if (is_dir('/proc/' . $entry)) {
            $count++;
        }
    }

    return $count;
}

function allowedBlockDevices(): array
{
    $entries = @scandir('/sys/block');

    if ($entries === false) {
        return [];
    }

    $allowed = [];

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $excluded = false;

        foreach ([
            'loop',
            'ram',
            'zram',
            'fd',
            'sr',
            'dm-',
        ] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $excluded = true;
                break;
            }
        }

        if (!$excluded) {
            $allowed[$name] = true;
        }
    }

    return $allowed;
}

function readDiskStats(): DiskStats
{
    $stats = new DiskStats();
    $allowed = allowedBlockDevices();

    if (count($allowed) === 0) {
        return $stats;
    }

    $handle = @fopen('/proc/diskstats', 'r');

    if ($handle === false) {
        return $stats;
    }

    try {
        while (($line = fgets($handle)) !== false) {
            $fields = preg_split('/\s+/', trim($line));

            if ($fields === false || count($fields) < 14) {
                continue;
            }

            $name = $fields[2];

            if (!isset($allowed[$name])) {
                continue;
            }

            $readSectors = (int)$fields[5];
            $writeSectors = (int)$fields[9];

            $stats->readBytes += $readSectors * 512;
            $stats->writeBytes += $writeSectors * 512;
        }
    } finally {
        fclose($handle);
    }

    return $stats;
}

function readNetworkStats(): NetworkStats
{
    $stats = new NetworkStats();

    $handle = @fopen('/proc/net/dev', 'r');

    if ($handle === false) {
        return $stats;
    }

    $lineNumber = 0;

    try {
        while (($line = fgets($handle)) !== false) {
            $lineNumber++;

            if ($lineNumber <= 2) {
                continue;
            }

            $position = strpos($line, ':');

            if ($position === false) {
                continue;
            }

            $interface = trim(
                substr($line, 0, $position)
            );

            if ($interface === 'lo') {
                continue;
            }

            $data = trim(
                substr($line, $position + 1)
            );

            $fields = preg_split('/\s+/', $data);

            if ($fields === false || count($fields) < 16) {
                continue;
            }

            $stats->rxBytes += (int)$fields[0];
            $stats->txBytes += (int)$fields[8];
        }
    } finally {
        fclose($handle);
    }

    return $stats;
}

function readPSI(string $path): PSIStats
{
    $stats = new PSIStats();

    $handle = @fopen($path, 'r');

    if ($handle === false) {
        return $stats;
    }

    try {
        while (($line = fgets($handle)) !== false) {
            $fields = preg_split('/\s+/', trim($line));

            if ($fields === false || count($fields) < 2) {
                continue;
            }

            $full = $fields[0] === 'full';

            for ($i = 1; $i < count($fields); $i++) {
                $parts = explode('=', $fields[$i], 2);

                if (count($parts) !== 2) {
                    continue;
                }

                $key = $parts[0];
                $value = (float)$parts[1];

                if ($full) {
                    switch ($key) {
                        case 'avg10':
                            $stats->fullAvg10 = $value;
                            break;

                        case 'avg60':
                            $stats->fullAvg60 = $value;
                            break;

                        case 'avg300':
                            $stats->fullAvg300 = $value;
                            break;
                    }
                } else {
                    switch ($key) {
                        case 'avg10':
                            $stats->someAvg10 = $value;
                            break;

                        case 'avg60':
                            $stats->someAvg60 = $value;
                            break;

                        case 'avg300':
                            $stats->someAvg300 = $value;
                            break;
                    }
                }
            }
        }
    } finally {
        fclose($handle);
    }

    return $stats;
}

final class Sampler
{
    private ?RawSample $previous = null;
    private int $cpuCount;

    public function __construct()
    {
        $this->cpuCount = cpuCount();
    }

    private function rawSample(): RawSample
    {
        [
            $cpu,
            $context,
            $interrupts,
            $forks,
        ] = readCPUStats();

        $memory = readMemoryStats();

        [
            $load1,
            $load5,
            $load15,
            $running,
        ] = readLoad();

        $sample = new RawSample();

        $sample->timestamp = hrtime(true) / 1_000_000_000;
        $sample->cpu = $cpu;
        $sample->memory = $memory;
        $sample->disk = readDiskStats();
        $sample->network = readNetworkStats();
        $sample->load1 = $load1;
        $sample->load5 = $load5;
        $sample->load15 = $load15;
        $sample->processes = readProcessCount();
        $sample->running = $running;
        $sample->uptime = readUptime();
        $sample->cpuPressure = readPSI('/proc/pressure/cpu');
        $sample->memPressure = readPSI('/proc/pressure/memory');
        $sample->ioPressure = readPSI('/proc/pressure/io');
        $sample->context = $context;
        $sample->interrupts = $interrupts;
        $sample->forks = $forks;

        return $sample;
    }

    public function sample(): Metrics
    {
        $current = $this->rawSample();

        $metrics = new Metrics();

        $metrics->load1 = $current->load1;
        $metrics->load5 = $current->load5;
        $metrics->load15 = $current->load15;
        $metrics->processCount = $current->processes;
        $metrics->runningProcesses = $current->running;
        $metrics->uptime = $current->uptime;
        $metrics->cpuPSI = $current->cpuPressure->someAvg10;
        $metrics->memoryPSI = $current->memPressure->someAvg10;
        $metrics->ioPSI = $current->ioPressure->someAvg10;

        if ($current->memory->total > 0) {
            $metrics->memoryPercent =
                $current->memory->used /
                $current->memory->total *
                100.0;
        }

        if ($current->memory->swapTotal > 0) {
            $swapUsed = max(
                0,
                $current->memory->swapTotal -
                $current->memory->swapFree
            );

            $metrics->swapPercent =
                $swapUsed /
                $current->memory->swapTotal *
                100.0;
        }

        $metrics->loadNormalized = clampFloat(
            $current->load1 / max(1, $this->cpuCount),
            0.0,
            2.0
        );

        if ($this->previous === null) {
            $this->previous = $current;

            return $metrics;
        }

        $previous = $this->previous;

        $elapsed =
            $current->timestamp -
            $previous->timestamp;

        $totalDelta = 0;
        $idleDelta = 0;

        if ($current->cpu->total >= $previous->cpu->total) {
            $totalDelta =
                $current->cpu->total -
                $previous->cpu->total;
        }

        if ($current->cpu->idle >= $previous->cpu->idle) {
            $idleDelta =
                $current->cpu->idle -
                $previous->cpu->idle;
        }

        if ($totalDelta > 0 && $idleDelta <= $totalDelta) {
            $metrics->cpuPercent =
                ($totalDelta - $idleDelta) /
                $totalDelta *
                100.0;
        }

        $metrics->diskReadRate = safeRate(
            $current->disk->readBytes,
            $previous->disk->readBytes,
            $elapsed
        );

        $metrics->diskWriteRate = safeRate(
            $current->disk->writeBytes,
            $previous->disk->writeBytes,
            $elapsed
        );

        $metrics->networkRXRate = safeRate(
            $current->network->rxBytes,
            $previous->network->rxBytes,
            $elapsed
        );

        $metrics->networkTXRate = safeRate(
            $current->network->txBytes,
            $previous->network->txBytes,
            $elapsed
        );

        $metrics->contextRate = safeRate(
            $current->context,
            $previous->context,
            $elapsed
        );

        $metrics->interruptRate = safeRate(
            $current->interrupts,
            $previous->interrupts,
            $elapsed
        );

        $metrics->forkRate = safeRate(
            $current->forks,
            $previous->forks,
            $elapsed
        );

        $this->previous = $current;

        return $metrics;
    }
}

final class PulseEngine
{
    private array $history = [];
    private int $maxHistory;

    public function __construct(int $maxHistory = 120)
    {
        $this->maxHistory = max(1, $maxHistory);
    }

    public function calculate(Metrics $metrics): PulseSnapshot
    {
        $cpuActivity = clampFloat(
            $metrics->cpuPercent / 100.0,
            0.0,
            1.0
        );

        $memoryActivity = clampFloat(
            $metrics->memoryPercent / 100.0,
            0.0,
            1.0
        );

        $loadActivity = clampFloat(
            $metrics->loadNormalized,
            0.0,
            1.0
        );

        $diskRate =
            $metrics->diskReadRate +
            $metrics->diskWriteRate;

        $networkRate =
            $metrics->networkRXRate +
            $metrics->networkTXRate;

        $diskActivity = normalizeLog(
            $diskRate,
            100 * 1024 * 1024
        );

        $networkActivity = normalizeLog(
            $networkRate,
            100 * 1024 * 1024
        );

        $processActivity = normalizeLog(
            (float)$metrics->runningProcesses,
            32.0
        );

        $pulse =
            $cpuActivity * 0.30 +
            $memoryActivity * 0.12 +
            $loadActivity * 0.20 +
            $diskActivity * 0.12 +
            $networkActivity * 0.10 +
            $processActivity * 0.08 +
            clampFloat(
                $metrics->cpuPSI / 100.0,
                0.0,
                1.0
            ) * 0.08;

        $cpuPressure = clampFloat(
            $metrics->cpuPSI / 25.0,
            0.0,
            1.0
        );

        $memoryPressure = clampFloat(
            $metrics->memoryPSI / 15.0,
            0.0,
            1.0
        );

        $ioPressure = clampFloat(
            $metrics->ioPSI / 20.0,
            0.0,
            1.0
        );

        $loadPressure = 0.0;

        if ($metrics->loadNormalized > 0.75) {
            $loadPressure = clampFloat(
                ($metrics->loadNormalized - 0.75) / 0.75,
                0.0,
                1.0
            );
        }

        $swapPressure = 0.0;

        if ($metrics->swapPercent > 20.0) {
            $swapPressure = clampFloat(
                ($metrics->swapPercent - 20.0) / 80.0,
                0.0,
                1.0
            );
        }

        $pressure =
            $cpuPressure * 0.25 +
            $memoryPressure * 0.35 +
            $ioPressure * 0.25 +
            $loadPressure * 0.10 +
            $swapPressure * 0.05;

        $pulse = clampFloat(
            $pulse,
            0.0,
            1.0
        );

        $pressure = clampFloat(
            $pressure,
            0.0,
            1.0
        );

        $vitality = clampFloat(
            1.0 -
            $pressure * 0.78 -
            max(0.0, $pulse - 0.92) * 0.25,
            0.0,
            1.0
        );

        [
            $state,
            $description,
        ] = $this->deriveState(
            $pulse,
            $pressure,
            $vitality
        );

        $this->history[] = $pulse;

        if (count($this->history) > $this->maxHistory) {
            $this->history = array_slice(
                $this->history,
                -$this->maxHistory
            );
        }

        $snapshot = new PulseSnapshot();

        $snapshot->metrics = $metrics;
        $snapshot->pulse = $pulse;
        $snapshot->pressure = $pressure;
        $snapshot->vitality = $vitality;
        $snapshot->state = $state;
        $snapshot->description = $description;
        $snapshot->pulseHistory = $this->history;
        $snapshot->lastUpdated = microtime(true);

        return $snapshot;
    }

    private function deriveState(
        float $pulse,
        float $pressure,
        float $vitality
    ): array {
        if ($pressure >= 0.80 || $vitality < 0.25) {
            return [
                'STORM',
                'Resources contend beneath the surface.',
            ];
        }

        if ($pressure >= 0.48 || $vitality < 0.50) {
            return [
                'STRAINED',
                'The machine is carrying weight.',
            ];
        }

        if ($pulse >= 0.78 && $pressure < 0.35) {
            return [
                'SURGING',
                'Strong currents move without resistance.',
            ];
        }

        if ($pulse >= 0.52) {
            return [
                'ACTIVE',
                'The garden is working.',
            ];
        }

        if ($pulse >= 0.28) {
            return [
                'AWAKE',
                'Small currents move through the system.',
            ];
        }

        if ($pulse >= 0.08) {
            return [
                'CALM',
                'Quiet computation beneath the surface.',
            ];
        }

        return [
            'DORMANT',
            'The machine sleeps beneath the soil.',
        ];
    }
}

function shellCommandExists(string $command): bool
{
    $paths = explode(
        PATH_SEPARATOR,
        getenv('PATH') ?: ''
    );

    foreach ($paths as $path) {
        if ($path === '') {
            continue;
        }

        $candidate =
            rtrim($path, DIRECTORY_SEPARATOR) .
            DIRECTORY_SEPARATOR .
            $command;

        if (is_file($candidate) && is_executable($candidate)) {
            return true;
        }
    }

    return false;
}

final class Terminal
{
    private ?string $originalState = null;
    private bool $raw = false;

    public function enableRaw(): void
    {
        if (!defined('STDIN') || !defined('STDOUT')) {
            throw new RuntimeException(
                'Machine Pulse requires a terminal'
            );
        }

        if (!shellCommandExists('stty')) {
            throw new RuntimeException(
                'Machine Pulse requires stty'
            );
        }

        $state = shell_exec('stty -g < /dev/tty 2>/dev/null');

        if ($state === null || trim($state) === '') {
            throw new RuntimeException(
                'Unable to read terminal state'
            );
        }

        $this->originalState = trim($state);

        shell_exec(
            'stty -echo -icanon min 0 time 1 isig < /dev/tty'
        );

        stream_set_blocking(STDIN, false);

        $this->raw = true;

        echo "\033[?1049h";
        echo "\033[?25l";
        echo "\033[2J";
        echo "\033[H";

        fflush(STDOUT);
    }

    public function restore(): void
    {
        if (!$this->raw) {
            return;
        }

        if ($this->originalState !== null) {
            shell_exec(
                'stty ' .
                escapeshellarg($this->originalState) .
                ' < /dev/tty 2>/dev/null'
            );
        }

        stream_set_blocking(STDIN, true);

        echo "\033[0m";
        echo "\033[?25h";
        echo "\033[?1049l";

        fflush(STDOUT);

        $this->raw = false;
    }

    public function readKey(): ?string
    {
        $read = [STDIN];
        $write = [];
        $except = [];

        $result = @stream_select(
            $read,
            $write,
            $except,
            0,
            0
        );

        if ($result === false || $result === 0) {
            return null;
        }

        $key = fread(STDIN, 1);

        if ($key === false || $key === '') {
            return null;
        }

        return $key;
    }
}

function terminalSize(): array
{
    $rows = 24;
    $columns = 80;

    $size = @shell_exec(
        'stty size < /dev/tty 2>/dev/null'
    );

    if ($size !== null) {
        $parts = preg_split(
            '/\s+/',
            trim($size)
        );

        if (
            $parts !== false &&
            count($parts) >= 2
        ) {
            $parsedRows = (int)$parts[0];
            $parsedColumns = (int)$parts[1];

            if ($parsedRows > 0) {
                $rows = $parsedRows;
            }

            if ($parsedColumns > 0) {
                $columns = $parsedColumns;
            }
        }
    }

    return [$rows, $columns];
}

function ansiColor(int $color): string
{
    return match ($color) {
        1 => "\033[34m",
        2 => "\033[36m",
        3 => "\033[32m",
        4 => "\033[35m",
        5 => "\033[33m",
        6 => "\033[31m",
        7 => "\033[37m",
        default => "\033[0m",
    };
}

function ansiAttr(int $attr, int $color): string
{
    $output = "\033[0m";

    if (($attr & ATTR_BOLD) !== 0) {
        $output .= "\033[1m";
    }

    if (($attr & ATTR_DIM) !== 0) {
        $output .= "\033[2m";
    }

    if ($color > 0) {
        $output .= ansiColor($color);
    }

    return $output;
}

final class Cell
{
    public string $char = ' ';
    public int $color = 0;
    public int $attr = ATTR_NORMAL;
}

final class Screen
{
    public int $height;
    public int $width;
    private array $cells = [];

    public function __construct(int $height, int $width)
    {
        $this->resize($height, $width);
    }

    public function resize(int $height, int $width): void
    {
        $this->height = max(1, $height);
        $this->width = max(1, $width);

        $this->cells = [];

        for ($y = 0; $y < $this->height; $y++) {
            $row = [];

            for ($x = 0; $x < $this->width; $x++) {
                $row[] = new Cell();
            }

            $this->cells[] = $row;
        }
    }

    public function clear(): void
    {
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $cell = $this->cells[$y][$x];

                $cell->char = ' ';
                $cell->color = 0;
                $cell->attr = ATTR_NORMAL;
            }
        }
    }

    public function put(
        int $y,
        int $x,
        string $text,
        int $color,
        int $attr
    ): void {
        if ($y < 0 || $y >= $this->height) {
            return;
        }

        $characters = preg_split(
            '//u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ($characters === false) {
            return;
        }

        $position = $x;

        foreach ($characters as $character) {
            if ($position < 0) {
                $position++;
                continue;
            }

            if ($position >= $this->width) {
                break;
            }

            $cell = $this->cells[$y][$position];

            $cell->char = $character;
            $cell->color = $color;
            $cell->attr = $attr;

            $position++;
        }
    }

    public function flush(): void
    {
        $output = "\033[H";

        $lastColor = -1;
        $lastAttr = -1;

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $cell = $this->cells[$y][$x];

                if (
                    $cell->color !== $lastColor ||
                    $cell->attr !== $lastAttr
                ) {
                    $output .= ansiAttr(
                        $cell->attr,
                        $cell->color
                    );

                    $lastColor = $cell->color;
                    $lastAttr = $cell->attr;
                }

                $output .= $cell->char;
            }

            if ($y !== $this->height - 1) {
                $output .= "\r\n";
            }
        }

        $output .= "\033[0m";

        echo $output;
        fflush(STDOUT);
    }
}

function stringLength(string $value): int
{
    $result = preg_match_all(
        '/./us',
        $value,
        $matches
    );

    if ($result === false) {
        return strlen($value);
    }

    return $result;
}

function truncateText(string $value, int $maximum): string
{
    if ($maximum <= 0) {
        return '';
    }

    $characters = preg_split(
        '//u',
        $value,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    if ($characters === false) {
        return substr($value, 0, $maximum);
    }

    if (count($characters) <= $maximum) {
        return $value;
    }

    if ($maximum === 1) {
        return $characters[0];
    }

    return implode(
        '',
        array_slice(
            $characters,
            0,
            $maximum - 1
        )
    ) . '…';
}

function centerX(int $width, string $text): int
{
    return max(
        0,
        intdiv(
            $width - stringLength($text),
            2
        )
    );
}

function stateColor(string $state): int
{
    return match ($state) {
        'DORMANT' => 1,
        'CALM' => 2,
        'AWAKE' => 3,
        'ACTIVE' => 3,
        'SURGING' => 5,
        'STRAINED' => 5,
        'STORM' => 6,
        default => 7,
    };
}

function formatBytes(float $value): string
{
    if ($value < 0.0) {
        $value = 0.0;
    }

    $units = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB',
    ];

    foreach ($units as $unit) {
        if ($value < 1024.0 || $unit === 'TB') {
            return sprintf(
                '%.1f %s',
                $value,
                $unit
            );
        }

        $value /= 1024.0;
    }

    return '0 B';
}

function formatRate(float $value): string
{
    return formatBytes($value) . '/s';
}

function formatCountRate(float $value): string
{
    if ($value < 1000.0) {
        return sprintf(
            '%.0f/s',
            $value
        );
    }

    if ($value < 1_000_000.0) {
        return sprintf(
            '%.1fK/s',
            $value / 1000.0
        );
    }

    return sprintf(
        '%.1fM/s',
        $value / 1_000_000.0
    );
}

function formatUptime(float $seconds): string
{
    $total = max(0, (int)$seconds);

    $days = intdiv($total, 86400);
    $total %= 86400;

    $hours = intdiv($total, 3600);
    $total %= 3600;

    $minutes = intdiv($total, 60);

    if ($days > 0) {
        return sprintf(
            '%dd %02dh %02dm',
            $days,
            $hours,
            $minutes
        );
    }

    if ($hours > 0) {
        return sprintf(
            '%02dh %02dm',
            $hours,
            $minutes
        );
    }

    return sprintf(
        '%02dm',
        $minutes
    );
}

function makeBar(float $value, int $width): string
{
    if ($width <= 0) {
        return '';
    }

    $value = clampFloat(
        $value,
        0.0,
        1.0
    );

    $filled = (int)round(
        $value * $width
    );

    $filled = max(
        0,
        min($width, $filled)
    );

    return
        str_repeat('█', $filled) .
        str_repeat('░', $width - $filled);
}

function sparkline(array $values, int $width): string
{
    if ($width <= 0 || count($values) === 0) {
        return '';
    }

    $characters = [
        '▁',
        '▂',
        '▃',
        '▄',
        '▅',
        '▆',
        '▇',
        '█',
    ];

    if (count($values) > $width) {
        $values = array_slice(
            $values,
            -$width
        );
    }

    $output = '';

    if (count($values) < $width) {
        $output .= str_repeat(
            ' ',
            $width - count($values)
        );
    }

    foreach ($values as $value) {
        $value = clampFloat(
            (float)$value,
            0.0,
            1.0
        );

        $index = (int)round(
            $value *
            (count($characters) - 1)
        );

        $index = max(
            0,
            min(
                count($characters) - 1,
                $index
            )
        );

        $output .= $characters[$index];
    }

    return $output;
}

final class MachinePulse
{
    private Sampler $sampler;
    private PulseEngine $engine;
    private Terminal $terminal;
    private Screen $screen;
    private PulseSnapshot $snapshot;
    private bool $running = true;
    private float $samplePeriod = 1.0;
    private int $samples = 0;
    private int $cpuCount;
    private float $nextSample = 0.0;

    public function __construct()
    {
        [$height, $width] = terminalSize();

        $this->sampler = new Sampler();
        $this->engine = new PulseEngine(120);
        $this->terminal = new Terminal();
        $this->screen = new Screen(
            $height,
            $width
        );

        $this->snapshot = new PulseSnapshot();
        $this->snapshot->metrics = new Metrics();

        $this->cpuCount = cpuCount();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function takeSample(): void
    {
        $metrics = $this->sampler->sample();

        $this->samples++;

        $snapshot = $this->engine->calculate(
            $metrics
        );

        $snapshot->sampleCounter =
            $this->samples;

        $this->snapshot = $snapshot;
    }

    private function drawBorder(): void
    {
        $height = $this->screen->height;
        $width = $this->screen->width;

        if ($height < 2 || $width < 2) {
            return;
        }

        $this->screen->put(
            0,
            0,
            '╔',
            7,
            ATTR_DIM
        );

        for ($x = 1; $x < $width - 1; $x++) {
            $this->screen->put(
                0,
                $x,
                '═',
                7,
                ATTR_DIM
            );
        }

        $this->screen->put(
            0,
            $width - 1,
            '╗',
            7,
            ATTR_DIM
        );

        for ($y = 1; $y < $height - 1; $y++) {
            $this->screen->put(
                $y,
                0,
                '║',
                7,
                ATTR_DIM
            );

            $this->screen->put(
                $y,
                $width - 1,
                '║',
                7,
                ATTR_DIM
            );
        }

        $this->screen->put(
            $height - 1,
            0,
            '╚',
            7,
            ATTR_DIM
        );

        for ($x = 1; $x < $width - 1; $x++) {
            $this->screen->put(
                $height - 1,
                $x,
                '═',
                7,
                ATTR_DIM
            );
        }

        $this->screen->put(
            $height - 1,
            $width - 1,
            '╝',
            7,
            ATTR_DIM
        );
    }

    private function drawTitle(): void
    {
        $title = ' MACHINE PULSE ';

        $this->screen->put(
            0,
            centerX(
                $this->screen->width,
                $title
            ),
            $title,
            stateColor($this->snapshot->state),
            ATTR_BOLD
        );
    }

    private function drawHeart(
        int $centerY,
        int $centerX,
        float $pulse,
        int $color
    ): void {
        $frames = [
            [
                '       ●       ',
                '     ●   ●     ',
                '   ●       ●   ',
                ' ●           ● ',
                '   ●       ●   ',
                '     ●   ●     ',
                '       ●       ',
            ],
            [
                '       ◉       ',
                '    ◉     ◉    ',
                '  ◉         ◉  ',
                '◉             ◉',
                '  ◉         ◉  ',
                '    ◉     ◉    ',
                '       ◉       ',
            ],
            [
                '       ◎       ',
                '   ◎       ◎   ',
                ' ◎           ◎ ',
                '◎             ◎',
                ' ◎           ◎ ',
                '   ◎       ◎   ',
                '       ◎       ',
            ],
        ];

        $frame = 0;

        if ($pulse >= 0.65) {
            $frame = 2;
        } elseif ($pulse >= 0.30) {
            $frame = 1;
        }

        foreach ($frames[$frame] as $index => $line) {
            $this->screen->put(
                $centerY + $index,
                $centerX - intdiv(
                    stringLength($line),
                    2
                ),
                $line,
                $color,
                ATTR_BOLD
            );
        }
    }

    private function drawMetric(
        int $y,
        string $label,
        string $value,
        float $level,
        int $color
    ): void {
        $width = $this->screen->width;

        if ($width < 50) {
            $line = sprintf(
                '%-12s %s',
                $label,
                $value
            );

            $this->screen->put(
                $y,
                3,
                truncateText(
                    $line,
                    $width - 6
                ),
                $color,
                ATTR_NORMAL
            );

            return;
        }

        $barWidth = min(
            24,
            max(
                $width - 46,
                8
            )
        );

        $this->screen->put(
            $y,
            3,
            sprintf(
                '%-12s %-13s',
                $label,
                $value
            ),
            $color,
            ATTR_NORMAL
        );

        $this->screen->put(
            $y,
            30,
            makeBar(
                $level,
                $barWidth
            ),
            $color,
            ATTR_NORMAL
        );
    }

    private function drawCompact(): void
    {
        $snapshot = $this->snapshot;
        $metrics = $snapshot->metrics;

        $color = stateColor(
            $snapshot->state
        );

        $y = 2;

        $this->screen->put(
            $y,
            3,
            'State: ' . $snapshot->state,
            $color,
            ATTR_BOLD
        );

        $y += 2;

        $this->drawMetric(
            $y++,
            'Pulse',
            sprintf(
                '%.2f',
                $snapshot->pulse
            ),
            $snapshot->pulse,
            $color
        );

        $this->drawMetric(
            $y++,
            'Pressure',
            sprintf(
                '%.2f',
                $snapshot->pressure
            ),
            $snapshot->pressure,
            6
        );

        $this->drawMetric(
            $y++,
            'Vitality',
            sprintf(
                '%.2f',
                $snapshot->vitality
            ),
            $snapshot->vitality,
            3
        );

        $y++;

        $this->drawMetric(
            $y++,
            'CPU',
            sprintf(
                '%.1f%%',
                $metrics->cpuPercent
            ),
            $metrics->cpuPercent / 100.0,
            2
        );

        $this->drawMetric(
            $y++,
            'Memory',
            sprintf(
                '%.1f%%',
                $metrics->memoryPercent
            ),
            $metrics->memoryPercent / 100.0,
            4
        );

        $this->drawMetric(
            $y++,
            'Load',
            sprintf(
                '%.2f',
                $metrics->load1
            ),
            clampFloat(
                $metrics->loadNormalized,
                0.0,
                1.0
            ),
            5
        );

        $diskRate =
            $metrics->diskReadRate +
            $metrics->diskWriteRate;

        $this->drawMetric(
            $y++,
            'Disk',
            formatRate($diskRate),
            normalizeLog(
                $diskRate,
                100 * 1024 * 1024
            ),
            3
        );

        $networkRate =
            $metrics->networkRXRate +
            $metrics->networkTXRate;

        $this->drawMetric(
            $y++,
            'Network',
            formatRate($networkRate),
            normalizeLog(
                $networkRate,
                100 * 1024 * 1024
            ),
            2
        );

        $y++;

        if ($y < $this->screen->height - 3) {
            $this->screen->put(
                $y,
                3,
                truncateText(
                    $snapshot->description,
                    $this->screen->width - 6
                ),
                $color,
                ATTR_DIM
            );
        }
    }

    private function drawFull(): void
    {
        $snapshot = $this->snapshot;
        $metrics = $snapshot->metrics;

        $width = $this->screen->width;
        $height = $this->screen->height;

        $color = stateColor(
            $snapshot->state
        );

        $this->drawHeart(
            2,
            intdiv($width, 2),
            $snapshot->pulse,
            $color
        );

        $stateLine = sprintf(
            '%s  PULSE %.2f  PRESSURE %.2f  VITALITY %.2f',
            $snapshot->state,
            $snapshot->pulse,
            $snapshot->pressure,
            $snapshot->vitality
        );

        $this->screen->put(
            9,
            centerX(
                $width,
                $stateLine
            ),
            $stateLine,
            $color,
            ATTR_BOLD
        );

        $description = truncateText(
            $snapshot->description,
            $width - 8
        );

        $this->screen->put(
            10,
            centerX(
                $width,
                $description
            ),
            $description,
            $color,
            ATTR_DIM
        );

        $historyWidth = min(
            $width - 10,
            80
        );

        $history = sparkline(
            $snapshot->pulseHistory,
            $historyWidth
        );

        $this->screen->put(
            12,
            centerX(
                $width,
                $history
            ),
            $history,
            $color,
            ATTR_BOLD
        );

        $y = 14;

        $this->drawMetric(
            $y++,
            'CPU',
            sprintf(
                '%.1f%%',
                $metrics->cpuPercent
            ),
            $metrics->cpuPercent / 100.0,
            2
        );

        $this->drawMetric(
            $y++,
            'Memory',
            sprintf(
                '%.1f%%',
                $metrics->memoryPercent
            ),
            $metrics->memoryPercent / 100.0,
            4
        );

        $this->drawMetric(
            $y++,
            'Swap',
            sprintf(
                '%.1f%%',
                $metrics->swapPercent
            ),
            $metrics->swapPercent / 100.0,
            5
        );

        $this->drawMetric(
            $y++,
            'Load',
            sprintf(
                '%.2f %.2f %.2f',
                $metrics->load1,
                $metrics->load5,
                $metrics->load15
            ),
            clampFloat(
                $metrics->loadNormalized,
                0.0,
                1.0
            ),
            5
        );

        $this->drawMetric(
            $y++,
            'Disk read',
            formatRate(
                $metrics->diskReadRate
            ),
            normalizeLog(
                $metrics->diskReadRate,
                100 * 1024 * 1024
            ),
            3
        );

        $this->drawMetric(
            $y++,
            'Disk write',
            formatRate(
                $metrics->diskWriteRate
            ),
            normalizeLog(
                $metrics->diskWriteRate,
                100 * 1024 * 1024
            ),
            3
        );

        $this->drawMetric(
            $y++,
            'Network RX',
            formatRate(
                $metrics->networkRXRate
            ),
            normalizeLog(
                $metrics->networkRXRate,
                100 * 1024 * 1024
            ),
            2
        );

        $this->drawMetric(
            $y++,
            'Network TX',
            formatRate(
                $metrics->networkTXRate
            ),
            normalizeLog(
                $metrics->networkTXRate,
                100 * 1024 * 1024
            ),
            2
        );

        $y += 2;

        if ($y < $height - 7) {
            $this->screen->put(
                $y++,
                3,
                'Pressure',
                7,
                ATTR_BOLD
            );

            $pressureLine = sprintf(
                'CPU %.2f%%   Memory %.2f%%   I/O %.2f%%',
                $metrics->cpuPSI,
                $metrics->memoryPSI,
                $metrics->ioPSI
            );

            $this->screen->put(
                $y,
                3,
                truncateText(
                    $pressureLine,
                    $width - 6
                ),
                6,
                ATTR_NORMAL
            );

            $y += 2;
        }

        if ($y < $height - 5) {
            $processLine = sprintf(
                'Processes %d   Running %d   Context %s   IRQ %s   Forks %s',
                $metrics->processCount,
                $metrics->runningProcesses,
                formatCountRate(
                    $metrics->contextRate
                ),
                formatCountRate(
                    $metrics->interruptRate
                ),
                formatCountRate(
                    $metrics->forkRate
                )
            );

            $this->screen->put(
                $y++,
                3,
                truncateText(
                    $processLine,
                    $width - 6
                ),
                7,
                ATTR_DIM
            );
        }

        if ($y < $height - 4) {
            $uptimeLine = sprintf(
                'Uptime %s   CPUs %d   Samples %d',
                formatUptime(
                    $metrics->uptime
                ),
                $this->cpuCount,
                $snapshot->sampleCounter
            );

            $this->screen->put(
                $y,
                3,
                truncateText(
                    $uptimeLine,
                    $width - 6
                ),
                7,
                ATTR_DIM
            );
        }
    }

    private function drawFooter(): void
    {
        $height = $this->screen->height;
        $width = $this->screen->width;

        if ($height < 3) {
            return;
        }

        $text = 'q / Ctrl-C leave the pulse';

        $this->screen->put(
            $height - 2,
            centerX(
                $width,
                $text
            ),
            $text,
            7,
            ATTR_DIM
        );
    }

    private function draw(): void
    {
        [
            $height,
            $width,
        ] = terminalSize();

        if (
            $height !== $this->screen->height ||
            $width !== $this->screen->width
        ) {
            $this->screen->resize(
                $height,
                $width
            );
        }

        $this->screen->clear();

        if ($height < 12 || $width < 38) {
            $message = 'Terminal too small';

            $this->screen->put(
                intdiv($height, 2),
                centerX(
                    $width,
                    $message
                ),
                $message,
                6,
                ATTR_BOLD
            );

            $this->screen->flush();

            return;
        }

        $this->drawBorder();
        $this->drawTitle();

        if ($height >= 31 && $width >= 72) {
            $this->drawFull();
        } else {
            $this->drawCompact();
        }

        $this->drawFooter();
        $this->screen->flush();
    }

    private function handleKey(string $key): void
    {
        switch ($key) {
            case 'q':
            case 'Q':
                $this->stop();
                break;

            case 'r':
            case 'R':
                try {
                    $this->takeSample();
                    $this->draw();
                } catch (Throwable) {
                }
                break;
        }
    }

    public function run(): void
    {
        $this->terminal->enableRaw();

        try {
            $this->takeSample();
            $this->draw();

            $this->nextSample =
                microtime(true) +
                $this->samplePeriod;

            while ($this->running) {
                $key = $this->terminal->readKey();

                if ($key !== null) {
                    $this->handleKey($key);
                }

                $now = microtime(true);

                if ($now >= $this->nextSample) {
                    try {
                        $this->takeSample();
                        $this->draw();
                    } catch (Throwable) {
                    }

                    $this->nextSample =
                        $now +
                        $this->samplePeriod;
                }

                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                usleep(20_000);
            }
        } finally {
            $this->terminal->restore();
        }
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(
        STDERR,
        "Machine Pulse requires PHP CLI.\n"
    );

    exit(1);
}

if (PHP_OS_FAMILY !== 'Linux') {
    fwrite(
        STDERR,
        "Machine Pulse requires Linux.\n"
    );

    exit(1);
}

$machine = new MachinePulse();

if (
    function_exists('pcntl_signal') &&
    defined('SIGINT') &&
    defined('SIGTERM')
) {
    pcntl_signal(
        SIGINT,
        static function () use ($machine): void {
            $machine->stop();
        }
    );

    pcntl_signal(
        SIGTERM,
        static function () use ($machine): void {
            $machine->stop();
        }
    );
}

try {
    $machine->run();

    fwrite(
        STDERR,
        "Machine Pulse ended.\n"
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "Machine Pulse: " .
        $exception->getMessage() .
        PHP_EOL
    );

    exit(1);
}
