<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Command;

use Cryjkd\HyperfXhprof\Report\ReportWriter;
use Hyperf\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Console entry point:
 *   php bin/hyperf.php profiler                  # list saved reports
 *   php bin/hyperf.php profiler show <id>        # print one report
 *   php bin/hyperf.php profiler compare <id...>  # compare two or more reports
 *   php bin/hyperf.php profiler clear            # delete all reports
 *   php bin/hyperf.php profiler index            # rebuild index.html (compare page)
 */
class ProfilerCommand extends Command
{
    /**
     * Set the command name through the constructor instead of redeclaring the
     * `$name` property: Hyperf 2.2 declares it untyped while 3.1 declares it
     * `?string`, so a typed/untyped redeclaration would trip PHP's typed-property
     * invariance check on one of the two lines.
     */
    public function __construct()
    {
        parent::__construct('profiler');
    }

    protected function configure()
    {
        $this->setDescription('List, view, compare and clear saved profiling reports.');
        $this->addArgument('action', InputArgument::OPTIONAL, 'list|show|compare|clear|index', 'list');
        $this->addArgument('ids', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Profile id(s): "show" takes one, "compare" takes two or more.');
    }

    public function handle()
    {
        $writer = new ReportWriter();
        $action = (string) ($this->input->getArgument('action') ?? 'list');
        $ids = array_values(array_filter((array) ($this->input->getArgument('ids') ?? []), 'is_string'));

        switch ($action) {
            case 'list':
                return $this->handleList($writer);
            case 'show':
                return $this->handleShow($writer, $ids[0] ?? '');
            case 'compare':
                return $this->handleCompare($writer, $ids);
            case 'clear':
                return $this->handleClear($writer);
            case 'index':
                return $this->handleIndex($writer);
            default:
                $this->error('Unknown action "' . $action . '". Use: list | show | compare | clear | index');
                return self::INVALID;
        }
    }

    private function handleList(ReportWriter $writer): int
    {
        $reports = $writer->list();
        if ($reports === []) {
            $this->info('No profiling reports found in ' . $writer->directory());
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($reports as $report) {
            $data = $writer->summary($report['id']);
            $rows[] = [
                $report['id'],
                date('Y-m-d H:i:s', $report['at']),
                $data['method'] ?? '',
                $data['uri'] ?? '',
                isset($data['wall_us']) ? $this->ms((int) $data['wall_us']) : '',
                isset($data['peak_mem']) ? $this->bytes((int) $data['peak_mem']) : '',
            ];
        }

        $this->table(['ID', 'Time', 'Method', 'URI', 'Wall', 'Peak Mem'], $rows);
        $this->info('Compare page: ' . $writer->directory() . DIRECTORY_SEPARATOR . 'index.html');

        return self::SUCCESS;
    }

    private function handleShow(ReportWriter $writer, string $id): int
    {
        if ($id === '') {
            $this->error('Please provide a profile id: php bin/hyperf.php profiler show <id>');
            return self::INVALID;
        }

        $data = $writer->load($id);
        if ($data === null) {
            $this->error('Profile not found: ' . $id);
            return self::FAILURE;
        }

        $this->info('Profile: ' . $id);
        $this->line('URI      : ' . ($data['uri'] ?? ''));
        $this->line('Method   : ' . ($data['method'] ?? ''));
        $this->line('Wall     : ' . $this->ms((int) ($data['wall_us'] ?? 0)));
        $this->line('CPU      : ' . $this->ms((int) ($data['cpu_us'] ?? 0)));
        $this->line('PeakMem  : ' . $this->bytes((int) ($data['peak_mem'] ?? 0)));
        $this->line('Report   : ' . $writer->directory() . DIRECTORY_SEPARATOR . $id . '.html');
        $this->line('');

        $rows = [];
        foreach ($data['nodes'] ?? [] as $node) {
            $rows[] = [
                $node['name'],
                (string) $node['calls'],
                $this->ms((int) $node['wall_us']),
                $this->ms((int) $node['excl_wall_us']),
            ];
        }

        $this->table(['Function', 'Calls', 'Incl (ms)', 'Excl (ms)'], $rows);

        return self::SUCCESS;
    }

    private function handleClear(ReportWriter $writer): int
    {
        $count = 0;
        foreach ($writer->list() as $report) {
            if ($writer->remove($report['id'])) {
                ++$count;
            }
        }

        $this->info("Removed {$count} report(s).");

        return self::SUCCESS;
    }

    /**
     * @param array<int, string> $ids
     */
    private function handleCompare(ReportWriter $writer, array $ids): int
    {
        if (count($ids) < 2) {
            $this->error('Please provide at least two ids: php bin/hyperf.php profiler compare <id1> <id2> [id3 ...]');
            return self::INVALID;
        }

        $summaries = [];
        foreach ($ids as $id) {
            $summary = $writer->summary($id);
            if ($summary === null) {
                $this->error('Profile not found: ' . $id);
                return self::FAILURE;
            }
            $summaries[] = $summary;
        }

        $this->renderCompareOverview($summaries, $ids);
        $this->renderCompareFunctions($summaries, $ids);

        $path = $writer->compare($ids);
        $this->info('Full comparison page: ' . $path);

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $summaries
     * @param array<int, string> $ids
     */
    private function renderCompareOverview(array $summaries, array $ids): void
    {
        $headers = array_merge(['Metric'], array_map([$this, 'shortId'], $ids));
        $rows = [
            array_merge(['Wall (ms)'], array_map(fn ($s) => $this->ms((int) $s['wall_us']), $summaries)),
            array_merge(['CPU (ms)'], array_map(fn ($s) => $this->ms((int) $s['cpu_us']), $summaries)),
            array_merge(['Peak mem'], array_map(fn ($s) => $this->bytes((int) $s['peak_mem']), $summaries)),
        ];

        $this->table($headers, $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $summaries
     * @param array<int, string> $ids
     */
    private function renderCompareFunctions(array $summaries, array $ids): void
    {
        $funcs = [];
        foreach ($summaries as $summary) {
            foreach ($summary['nodes'] ?? [] as $node) {
                $funcs[$node['name']][$summary['id']] = (int) $node['wall_us'];
            }
        }

        uasort($funcs, static fn (array $a, array $b): int => max($b) <=> max($a));
        $top = array_slice($funcs, 0, 15, true);
        if ($top === []) {
            return;
        }

        $headers = array_merge(['Function'], array_map([$this, 'shortId'], $ids), ['Δ (max−min)']);
        $rows = [];
        foreach ($top as $name => $per) {
            $vals = [];
            $min = PHP_INT_MAX;
            $max = 0;
            foreach ($ids as $id) {
                $value = (int) ($per[$id] ?? 0);
                $vals[] = $this->ms($value);
                $min = min($min, $value);
                $max = max($max, $value);
            }
            $delta = $max - $min;
            $rows[] = array_merge([$name], $vals, [$delta > 0 ? '+' . $this->ms($delta) : '—']);
        }

        $this->line('');
        $this->table($headers, $rows);
    }

    private function handleIndex(ReportWriter $writer): int
    {
        $writer->writeIndex();
        $this->info('Index rebuilt: ' . $writer->directory() . DIRECTORY_SEPARATOR . 'index.html');

        return self::SUCCESS;
    }

    private function shortId(string $id): string
    {
        return strlen($id) > 18 ? '…' . substr($id, -16) : $id;
    }

    private function ms(int $us): string
    {
        return sprintf('%.3f ms', $us / 1000);
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return sprintf('%.2f %s', $value, $units[$unit]);
    }
}
