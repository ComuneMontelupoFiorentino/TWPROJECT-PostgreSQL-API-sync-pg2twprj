#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Classes\Services\PostgresConnection;
use Classes\Services\Logger;
use Classes\Services\Integration\CheckIntegration;
use Classes\Tasks\IssueToTicket;
use Classes\Tasks\OpenTodo;
use Classes\Tasks\InsertCommentToTodo;
use Classes\Tasks\SyncStatusPgToTwprj;
use Classes\Tasks\SyncStatusTwToSIT;

/**
 * ----------------------------
 * CLI OPTIONS
 * ----------------------------
 */
$longOpts = [
    "subject:",         // OPENTODO (obbligatorio)
    "description:",     // OPENTODO (obbligatorio)
    "taskId:",          // OPENTODO (obbligatorio)
    "gravity::",        // OPENTODO (facoltativo)
    "lat::",            // OPENTODO (facoltativo)
    "lon::",            // OPENTODO (facoltativo)
    "assignedBy::",     // OPENTODO (facoltativo)
    "assignee::",       // OPENTODO (facoltativo)
    "idIssue:",         // INSERT COMMENT (facoltativo)
    "comment:",         // INSERT COMMENT (facoltativo),
    "dry-run-rem-att", // MANUAL REMEDIATE ATTACHMENTS - riallinea nomi allegati (facoltativo, default se assente)
    "apply-rem-att"    // MANUAL REMEDIATE ATTACHMENTS - riallinea nomi allegati (esplicito per scrivere davvero)
];

$options = getopt("", $longOpts);

// Parsing CLI base
$envFlag = $argv[1] ?? null;
$command = $argv[2] ?? null;

if (!$envFlag || !$command) {
    echo "Uso:\n";
    echo "  php sync.php -test|-prod -IT\n";
    echo "  php sync.php -test|-prod -OT [--subject ...]\n";
    echo "  php sync.php -test|-prod -MRA [--apply-rem-att]\n";
    exit(1);
}

/**
 * ----------------------------
 * ENVIRONMENT
 * ----------------------------
 */
switch ($envFlag) {
    case '-test':
        $pgEnv  = 'pg_test';
        $appEnv = 'test';
        break;

    case '-prod':
        $pgEnv  = 'pg_prod';
        $appEnv = 'prod';
        break;

    default:
        echo "Ambiente non valido (-test|-prod)\n";
        exit(1);
}

echo "DEBUG: envFlag = $envFlag, appEnv = $appEnv\n";

/**
 * ----------------------------
 * SERVICES
 * ----------------------------
 */
$pgServiceFile = __DIR__ . '/config/pg_service.conf';

// Logger (taskId verrà settato dai task)
$logger = new Logger(__DIR__ . '/logs', 0);

// Connessione PostgreSQL (serve a IT e OT)
$dbConnection = new PostgresConnection($pgEnv, $pgServiceFile, $logger);
$pdo = $dbConnection->getPdo();

/**
 * ----------------------------
 * TASK DISPATCH
 * ----------------------------
 */
try {
    switch ($command) {

        // ISSUE TO TICKET
        case '-IT':
            $task = new IssueToTicket($pdo, $logger, $appEnv);
            $task->run();
            break;

        // OPEN TODO
        case '-OT':
            $task = new OpenTodo($pdo, $logger, $appEnv);

            if (!empty($options)) {
                $task->runFromCli($options);
            } else {
                $task->runFromQueue();
            }
            break;
        // INSERT COMMENT
        case '-IC':
        // Legge le configurazioni come per OpenTodo
        $iniFile = __DIR__ . '/config/twproject_config.ini';
        $section = "twprj_{$appEnv}";
        $ini = parse_ini_file($iniFile, true);
        $apiUrl = rtrim($ini[$section]['url'], '/') . '/';
        $apiKey = $ini[$section]['key'];

        $task = new InsertCommentToTodo($pdo, $logger, $appEnv, $apiUrl, $apiKey);

        if (isset($options['idIssue'], $options['comment'])) {
            $task->runFromCli($options);
        } else {
            $task->runFromQueue();
        }
        break;

        // SYNC STATUS POSTGRES → TWPROJECT
        case '-SSTw':
            $iniFile = __DIR__ . '/config/twproject_config.ini';
            $section = "twprj_{$appEnv}";
            $ini = parse_ini_file($iniFile, true);
            $apiUrl = rtrim($ini[$section]['url'], '/') . '/';
            $apiKey = $ini[$section]['key'];

            $task = new \Classes\Tasks\SyncStatusPgToTwprj($pdo, $logger, $appEnv, $apiUrl, $apiKey);
            $task->run();
            break;

        // SYNC STATUS TW → SIT
        case '-SSSit':
            $iniFile = __DIR__ . '/config/twproject_config.ini';
            $section = "twprj_{$appEnv}";
            $ini = parse_ini_file($iniFile, true);

            if (empty($ini[$section])) {
                throw new RuntimeException("Sezione {$section} non presente in twproject_config.ini");
            }

            $apiUrl = rtrim($ini[$section]['url'], '/') . '/';
            $apiKey = $ini[$section]['key'];

            $task = new \Classes\Tasks\SyncStatusTwToSIT($pdo, $logger, $appEnv, $apiUrl, $apiKey);
            $task->run();
            break;
        // SYNC STATUS COMPLETA
        case '-SS':
            $iniFile = __DIR__ . '/config/twproject_config.ini';
            $section = "twprj_{$appEnv}";
            $ini = parse_ini_file($iniFile, true);
        
            if (empty($ini[$section])) {
                throw new RuntimeException("Sezione {$section} non presente in twproject_config.ini");
            }
        
            $apiUrl = rtrim($ini[$section]['url'], '/') . '/';
            $apiKey = $ini[$section]['key'];
            
            // Esegui SyncStatusPgToTwprj
            $logger->info("=== Inizio SyncStatusPgToTwprj (Postgres → Twproject) ===");
            $taskPgToTw = new \Classes\Tasks\SyncStatusPgToTwprj($pdo, $logger, $appEnv, $apiUrl, $apiKey);
            $taskPgToTw->run();
            $logger->info("=== Fine SyncStatusPgToTwprj ===");

            // Esegui SyncStatusTwToSIT
            $logger->info("=== Inizio SyncStatusTwToSIT (Twproject → SIT) ===");
            $taskTwToSit = new \Classes\Tasks\SyncStatusTwToSIT($pdo, $logger, $appEnv, $apiUrl, $apiKey);
            $taskTwToSit->run();
            $logger->info("=== Fine SyncStatusTwToSIT ===");
            break;
        // CHECK INTEGRATION
        case '-CI': 
            $db = new PostgresConnection(
                $pgEnv,
                __DIR__ . '/config/pg_service.conf',
                $logger
            );

            $checker = new CheckIntegration(
                $db,
                $logger,
                __DIR__ . '/sync.php',
                $envFlag
            );

            $checker->run();

            echo "CheckIntegration completato\n";
            break;

        // MANUAL REMEDIATE ATTACHMENTS (fix duplicati file allegati)
        case '-MRA':
            // Default: dry-run. Serve --apply-rem-att esplicito per scrivere sul DB/filesystem.
            $dryRun = !isset($options['apply-rem-att']);
    
            if ($dryRun) {
                echo "Modalità DRY-RUN (nessuna scrittura). Passa --apply-rem-att per eseguire davvero.\n";
            } else {
                echo "Modalità APPLY: verranno scritti file e aggiornato il DB.\n";
            }
    
            $task = new \Classes\Tasks\ManualRemediateAttachments($pdo, $logger, $appEnv, $dryRun);
            $task->run();
            break;

        default:
            throw new RuntimeException("Comando non valido: {$command}");
    }

} catch (Throwable $e) {
    $logger->error($e->getMessage());
    echo "ERRORE: {$e->getMessage()}\n";
    exit(1);
}
