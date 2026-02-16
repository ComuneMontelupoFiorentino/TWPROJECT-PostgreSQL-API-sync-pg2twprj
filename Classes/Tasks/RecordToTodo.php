<?php
declare(strict_types=1);

namespace Classes\Tasks;

use PDO;
use Classes\Services\Logger;
use RuntimeException;
use Classes\Tasks\SetIssueCoord;

class RecordToTodo
{
    private PDO $pdo;
    private Logger $logger;
    private string $apiUrl;
    private string $apiKey;
    private string $appEnvironment;

    private array $gravityMap = [
        1 => "01_GRAVITY_LOW",
        2 => "02_GRAVITY_MEDIUM",
        3 => "03_GRAVITY_HIGH",
        4 => "04_GRAVITY_CRITICAL",
        5 => "05_GRAVITY_BLOCK"
    ];

    public function __construct(PDO $pdo, Logger $logger, string $appEnvironment)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->appEnvironment = $appEnvironment;

        $iniFile = __DIR__ . '/../../config/twproject_config.ini';
        if (!file_exists($iniFile)) {
            throw new RuntimeException("File twproject_config.ini non trovato");
        }

        $section = "twprj_{$appEnvironment}";
        $ini = parse_ini_file($iniFile, true);
        if (empty($ini[$section])) {
            throw new RuntimeException("Sezione {$section} non presente in twproject_config.ini");
        }

        $this->apiUrl = rtrim($ini[$section]['url'], '/') . '/';
        $this->apiKey = $ini[$section]['key'];

        $this->logger->setTask('RecordToTodo');
    }

    public function processSingleTodo(array $todo): void
    {
        try {
            $issueId = $this->sendTodo($todo);

            if ($issueId !== false) {
                $this->updateTodoSent($todo['id'] ?? 0, $issueId);
                $this->logger->info("Todo inviata. ID Twproject: $issueId");

                // Gestione coordinate (se presenti)
                $this->handleCoordinates($todo['id'] ?? 0);
            } else {
                $this->logger->error("Invio Todo fallito");
            }
        } catch (\Throwable $e) {
            $this->logger->error("Errore invio Todo: " . $e->getMessage());
        }
    }

    public function run(): void
    {
        $this->logger->info("=== Inizio invio todo da queue ===");

        $stmt = $this->pdo->query(
            "SELECT * FROM audit.todo_queue WHERE status IN ('pending','failed') ORDER BY created_at ASC"
        );

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            try {
                $issueId = $this->sendTodo($row);

                if ($issueId !== false) {
                    $this->updateTodoSent($row['id'], $issueId);
                    $this->logger->info("Todo ID {$row['id']} inviata. ID Twproject: $issueId");

                    // Gestione coordinate
                    $this->handleCoordinates((int)$row['id']);
                } else {
                    $this->updateTodoFailed($row['id']);
                    $this->logger->error("Todo ID {$row['id']} fallita");
                }

            } catch (\Throwable $e) {
                $this->updateTodoFailed($row['id']);
                $this->logger->error("Errore invio Todo ID {$row['id']}: " . $e->getMessage());
            }
        }

        $this->logger->info("=== Fine invio todo ===");
    }
    
    /**
     * Gestisce eventuale invio coordinate
     */
    private function handleCoordinates(int $recordId): void
    {
        try {
            $setCoordTask = new SetIssueCoord(
                $this->pdo,
                $this->logger,
                $this->appEnvironment
            );

            $result = $setCoordTask->setCoordinatesFromDb($recordId);

            if ($result) {
                $this->logger->info("Coordinate inviate per record $recordId");
            } else {
                $this->logger->info("Nessuna coordinata da inviare per record $recordId");
            }

        } catch (\Throwable $e) {
            $this->logger->error(
                "Errore invio coordinate per record $recordId: " . $e->getMessage()
            );
        }
    }
    private function sendTodo(array $todo): int|false
    {
        // Mappa gravity se presente
        if (isset($todo['gravity'])) {
            $todo['gravity'] = in_array($todo['gravity'], $this->gravityMap) ? $todo['gravity'] : '01_GRAVITY_LOW';
        } else {
            $todo['gravity'] = '01_GRAVITY_LOW';
        }

        $data = [
            "command" => "create",
            "object"  => "issue",
            "data"    => [
                "subject"        => $todo['subject'] ?? '',
                "description"    => $todo['body'] ?? '',
                "taskId"         => (int)($todo['task_id'] ?? 0),
                "gravity"        => $todo['gravity'],
                "signalledOnDate"=> date('d/m/Y'),
                "tags"           => "Manutenzione, Prosit, segnalazioni",
                "shouldCloseBy"  => strtotime("+7 days") * 1000,
                "assignedById"   => isset($todo['assigned_by']) ? (int)$todo['assigned_by'] : null,
                "assigneeId"     => isset($todo['assignee_id']) ? (int)$todo['assignee_id'] : null,
                "typeId"         => 51
            ],
            "APIKey" => $this->apiKey
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $this->logger->error("Errore cURL (issue): " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        $responseData = json_decode($response, true);
        if (!isset($responseData['object']['id'])) {
            $this->logger->error("Creazione issue fallita: " . $response);
            return false;
        }

        return (int)$responseData['object']['id'];
    }

    private function updateTodoSent(int $id, int $issueId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE audit.todo_queue
            SET status = 'sended',
                sent_at = NOW(),
                todo_status_twprj = 1,
                id_todo_twprj = :issueId
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':issueId' => $issueId
        ]);
    }

    private function updateTodoFailed(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE audit.todo_queue
            SET status = 'failed',
                sent_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
    }
}
