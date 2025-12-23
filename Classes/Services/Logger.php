<?php
declare(strict_types=1);

namespace Classes\Services;

class Logger
{
    private string $task = '';
    private string $logFile;

    public function __construct(
        private string $baseLogDir,
        private int $taskId = 0
    ) {
        $this->prepareDirectories();
        $this->logFile = $this->getLogFilePath();
    }

    public function setTask(string $task): void
    {
        $this->task = $task;
        $this->logFile = $this->getLogFilePath(); // aggiorna file log
    }

    public function setTaskId(int $taskId): void
    {
        $this->taskId = $taskId;
        $this->logFile = $this->getLogFilePath();
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $date = date('Y-m-d H:i:s');
        file_put_contents(
            $this->logFile,
            "[$date][$level] $message\n",
            FILE_APPEND | LOCK_EX
        );
    }

    private function getLogFilePath(): string
    {
        $y = date('Y');
        $m = date('m');
        $d = date('d');

        $filename = $this->task ?: ($this->taskId ?: 'app');

        return sprintf(
            '%s/%s/%s/%s/%s.log',
            rtrim($this->baseLogDir, '/'),
            $y,
            $m,
            $d,
            $filename
        );
    }

    private function prepareDirectories(): void
    {
        $path = sprintf(
            '%s/%s/%s/%s',
            rtrim($this->baseLogDir, '/'),
            date('Y'),
            date('m'),
            date('d')
        );

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}
