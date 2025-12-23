<?php
declare(strict_types=1);

namespace Classes\Tasks;

use PDO;
use Classes\Services\Logger;
use RuntimeException;

class OpenTodo
{
    private PDO $pdo;
    private Logger $logger;
    private array $config;
    private string $apiUrl;
    private string $apiKey;
    private string $appEnv;

    private array $gravityMap = [
        1 => "01_GRAVITY_LOW",
        2 => "02_GRAVITY_MEDIUM",
        3 => "03_GRAVITY_HIGH",
        4 => "04_GRAVITY_CRITICAL",
        5 => "05_GRAVITY_BLOCK"
    ];

    public function __construct(PDO $pdo, Logger $logger, string $envName)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->appEnv = $envName; // <-- usa $envName, non $env

        $iniFile = __DIR__ . '/../../config/twproject_config.ini';
        if (!file_exists($iniFile)) {
            throw new RuntimeException("File twproject_config.ini non trovato");
        }

        $section = "twprj_{$envName}";
        $ini = parse_ini_file($iniFile, true);
        if (empty($ini[$section])) {
            throw new RuntimeException("Sezione {$section} non presente in twproject_config.ini");
        }

        $this->apiUrl = rtrim($ini[$section]['url'], '/') . '/';
        $this->apiKey = $ini[$section]['key'];

        $this->logger->setTask('OpenTodo');
    }

    /**
     * Esegue l'invio dei record pendenti/falliti dalla tabella todo_queue
     */
    public function runFromQueue(): void
    {
        require_once __DIR__ . '/RecordToTodo.php';
        // Passa l'ambiente, non la chiave
        $recordToTodo = new RecordToTodo($this->pdo, $this->logger, $this->appEnv ?? 'test');
        $recordToTodo->run();
    }

    /**
     * Esegue l'invio di un singolo todo passato da CLI
     */
    public function runFromCli(array $options): void
    {
        if (empty($options['taskId'])) {
            throw new RuntimeException("Parametro obbligatorio mancante: --taskId");
        }
        if (empty($options['subject']) && empty($options['description'])) {
            throw new RuntimeException("Parametro obbligatorio mancante: --subject o --description");
        }

        $todo = [
            'task_id'     => (int)$options['taskId'],
            'subject'     => $options['subject'] ?? '',
            'body'        => $options['description'] ?? '',
            'gravity'     => isset($options['gravity']) ? $this->mapGravity((int)$options['gravity']) : "01_GRAVITY_LOW",
            'assigned_by' => isset($options['assignedBy']) ? (int)$options['assignedBy'] : null,
            'assignee_id' => isset($options['assignee']) ? (int)$options['assignee'] : null,
            'lat'         => $options['lat'] ?? null,
            'lon'         => $options['lon'] ?? null
        ];

        // Usa RecordToTodo per processare il singolo todo, così logica coerente
        require_once __DIR__ . '/RecordToTodo.php';
        $recordToTodo = new RecordToTodo($this->pdo, $this->logger, $this->appEnv);
        $recordToTodo->processSingleTodo($todo);
    }

    private function mapGravity(int $value): string
    {
        return $this->gravityMap[$value] ?? "01_GRAVITY_LOW";
    }
}
