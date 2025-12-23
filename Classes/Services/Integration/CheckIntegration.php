<?php
declare(strict_types=1);

namespace Classes\Services\Integration;

use Classes\Services\PostgresConnection;
use Classes\Services\Logger;
use PDO;
use RuntimeException;

class CheckIntegration
{
    private PDO $pdo;
    private Logger $logger;

    /**
     * Mappa stato DB → comando CLI
     */
    private const COMMAND_MAP = [
        'insert_comment_todo' => 'IC',
        'open_todo'           => 'OT',
        'todo_sended'         => 'SSSit',
    ];

    /**
     * Comandi sempre eseguiti
     */
    private const ALWAYS_COMMANDS = [
        'SSTw',
        'IT',
    ];

    /**
     * File lock per evitare esecuzioni concorrenti
     */
    private string $lockFile;

    public function __construct(
        PostgresConnection $connection,
        Logger $logger,
        private string $cliScriptPath,
        private string $envFlag // -test | -prod
    ) {
        $this->pdo = $connection->getPdo();
        $this->logger = $logger;

        $this->logger->setTask('checkintegration');

        $this->lockFile = sys_get_temp_dir() . '/checkintegration.lock';
    }

    /**
     * Entry point
     */
    public function run(): void
    {
        if (!$this->acquireLock()) {
            $this->logger->info('Processo già in esecuzione, skip');
            return;
        }

        try {
            $status = $this->checkStatus();

            // Comandi condizionati
            foreach (self::COMMAND_MAP as $statusKey => $command) {
                if (!empty($status[$statusKey])) {
                    $this->executeCommand($command);
                } else {
                    $this->logger->info("{$command} skipped (condizione falsa)");
                }
            }

            // Comandi sempre eseguiti
            foreach (self::ALWAYS_COMMANDS as $command) {
                $this->executeCommand($command);
            }

        } catch (\Throwable $e) {
            $this->logger->error('Errore CheckIntegration: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Recupera stato integrazione dal DB
     */
    private function checkStatus(): array
    {
        $sql = <<<SQL
        SELECT 
            CASE 
                WHEN (SELECT COUNT(id)
                      FROM segn.reporting_ticket
                      WHERE is_from = 'twproject'
                        AND action_description IS NOT NULL) > 0
                THEN true ELSE false
            END AS insert_comment_todo,

            CASE 
                WHEN (SELECT COUNT(id)
                      FROM audit.todo_queue
                      WHERE status IN ('pending','failed')) > 0
                THEN true ELSE false
            END AS open_todo,

            CASE 
                WHEN (SELECT COUNT(id_todo_twprj)
                      FROM audit.todo_queue
                      WHERE status = 'sended'
                        AND todo_status_twprj = 1) > 0
                THEN true ELSE false
            END AS todo_sended
        SQL;

        $stmt = $this->pdo->query($sql);
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Impossibile recuperare stato integrazione');
        }

        return array_map('boolval', $row);
    }

    /**
     * Esegue comando CLI
     */
    private function executeCommand(string $command): void
    {
        $cmd = sprintf(
            'php %s %s -%s 2>&1',
            escapeshellarg($this->cliScriptPath),
            escapeshellarg($this->envFlag),
            escapeshellarg($command)
        );

        $this->logger->info("Eseguo comando: {$cmd}");

        $output = shell_exec($cmd);

        $this->logger->info(
            "Output {$command}: " . trim((string)$output)
        );
    }

    /**
     * Lock per evitare esecuzioni concorrenti (cron-safe)
     */
    private function acquireLock(): bool
    {
        $fp = fopen($this->lockFile, 'c');
        if (!$fp) {
            throw new RuntimeException('Impossibile creare lock file');
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            return false;
        }

        // Mantiene il file aperto
        $this->lockHandle = $fp;

        return true;
    }

    private function releaseLock(): void
    {
        if (isset($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            unset($this->lockHandle);
        }
    }
}
