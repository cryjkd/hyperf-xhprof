<?php

declare(strict_types=1);

namespace Cryjkd\HyperfXhprof\Report;

use Cryjkd\HyperfXhprof\Profiler;
use Cryjkd\HyperfXhprof\Trace;

/**
 * Persists traces as JSON + self-contained HTML files and prunes old reports.
 */
final class ReportWriter
{
    public function save(Trace $trace): string
    {
        $trace->finish();

        $dir = $this->directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create profiler storage directory: ' . $dir);
        }

        $id = $trace->id;
        $json = json_encode($trace->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($dir . DIRECTORY_SEPARATOR . $id . '.json', $json);

        $html = (new HtmlReport())->render($trace);
        file_put_contents($dir . DIRECTORY_SEPARATOR . $id . '.html', $html);

        $this->prune($dir, (int) ($this->storage()['keep'] ?? 100));
        $this->writeIndex();

        return $id;
    }

    public function directory(): string
    {
        $path = (string) ($this->storage()['path'] ?? '');
        if ($path === '' && defined('BASE_PATH')) {
            $path = BASE_PATH . '/runtime/profiler';
        }
        if ($path === '') {
            $path = sys_get_temp_dir() . '/fenxi-profiler';
        }

        return rtrim($path, '/\\');
    }

    /**
     * @return array<int, array{id:string, file:string, at:int}>
     */
    public function list(): array
    {
        $dir = $this->directory();
        if (! is_dir($dir)) {
            return [];
        }

        $result = [];
        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*.json') as $file) {
            $result[] = [
                'id' => basename((string) $file, '.json'),
                'file' => (string) $file,
                'at' => (int) filemtime((string) $file),
            ];
        }
        usort($result, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $id): ?array
    {
        $file = $this->directory() . DIRECTORY_SEPARATOR . $id . '.json';
        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    public function loadHtml(string $id): ?string
    {
        $file = $this->directory() . DIRECTORY_SEPARATOR . $id . '.html';
        if (! is_file($file)) {
            return null;
        }

        return (string) file_get_contents($file);
    }

    public function remove(string $id): bool
    {
        $dir = $this->directory();
        $json = $dir . DIRECTORY_SEPARATOR . $id . '.json';
        $html = $dir . DIRECTORY_SEPARATOR . $id . '.html';

        $ok = false;
        if (is_file($json)) {
            $ok = @unlink($json) || $ok;
        }
        if (is_file($html)) {
            $ok = @unlink($html) || $ok;
        }

        return $ok;
    }

    /**
     * Load a lightweight summary (meta + flat nodes, without the call tree).
     *
     * @return array<string, mixed>|null
     */
    public function summary(string $id): ?array
    {
        $data = $this->load($id);
        if ($data === null) {
            return null;
        }

        return [
            'id' => $data['id'] ?? $id,
            'uri' => $data['uri'] ?? '',
            'method' => $data['method'] ?? '',
            'started_at' => $data['started_at'] ?? null,
            'wall_us' => $data['wall_us'] ?? 0,
            'cpu_us' => $data['cpu_us'] ?? 0,
            'peak_mem' => $data['peak_mem'] ?? 0,
            'nodes' => $data['nodes'] ?? [],
        ];
    }

    /**
     * (Re)build index.html: the select-multiple + compare page for all saved reports.
     */
    public function writeIndex(): void
    {
        $dir = $this->directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            return;
        }

        $summaries = [];
        foreach ($this->list() as $report) {
            $summary = $this->summary($report['id']);
            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'index.html',
            (new CompareReport())->render($summaries)
        );
    }

    /**
     * Build a standalone comparison page for the given profile ids and return its path.
     *
     * @param array<int, string> $ids
     */
    public function compare(array $ids): string
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));

        $summaries = [];
        foreach ($ids as $id) {
            $summary = $this->summary($id);
            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        if (count($summaries) < 2) {
            throw new \InvalidArgumentException('At least two valid profile ids are required to compare.');
        }

        $dir = $this->directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create profiler storage directory: ' . $dir);
        }

        $filename = 'compare-' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.html';
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . $filename,
            (new CompareReport())->render($summaries, array_column($summaries, 'id'))
        );

        return $dir . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        $storage = Profiler::config()['storage'] ?? [];

        return is_array($storage) ? $storage : [];
    }

    private function prune(string $dir, int $keep): void
    {
        if ($keep <= 0) {
            return;
        }

        $files = (array) glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if (count($files) <= $keep) {
            return;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - $keep) as $file) {
            @unlink($file);
            @unlink(substr($file, 0, -5) . '.html');
        }
    }
}
