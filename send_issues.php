<?php
declare(strict_types=1);

require_once __DIR__ . '/config/get_db.php';
require_once __DIR__ . '/open_issue_function.php'; // contiene sendTodoFromQueue

$logFile = __DIR__ . '/logs/todo_queue.log';

function write_log(string $message, string $logFile): void {
    $date = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$date] $message\n", FILE_APPEND | LOCK_EX);
}

try {
    write_log("=== Inizio invio todo ===", $logFile);

    $db = get_db();

    // Recupero i record da inviare
    $stmt = $db->query("SELECT * FROM audit.todo_queue WHERE status IN ('pending', 'failed') ORDER BY created_at ASC");
    $todos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Carico apiKey
    $config = require __DIR__ . '/config/api.php';
    require_once __DIR__ . '/decrypt_util.php';
    $APIKey = decrypt_field($config['APIKey']);

    foreach ($todos as $todoRecord) {
        $issueId = sendTodoFromQueue($todoRecord, $APIKey);

        if ($issueId !== false) {

            // Invio riuscito → aggiorno anche id_todo_twprj
            $newStatus = 'sended';
            $stmtUpdate = $db->prepare("
                UPDATE audit.todo_queue
                SET status = :status,
                    sent_at = NOW(),
                    todo_status_twprj = 1,
                    id_todo_twprj = :issueId
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':status'  => $newStatus,
                ':issueId' => $issueId,
                ':id'      => $todoRecord['id']
            ]);

            write_log("Todo ID {$todoRecord['id']} inviata. ID Twproject: $issueId", $logFile);
        } else {

            // Invio fallito → aggiorno solo lo status
            $newStatus = 'failed';
            $stmtUpdate = $db->prepare("
                UPDATE audit.todo_queue
                SET status = :status,
                    sent_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':status'  => $newStatus,
                ':id'      => $todoRecord['id']
            ]);

            write_log("Todo ID {$todoRecord['id']} fallita", $logFile);

        } 
    }

    write_log("=== Fine invio todo ===", $logFile);

} catch (PDOException $e) {
    write_log("Errore DB: " . $e->getMessage(), $logFile);
    exit(1);
} catch (Exception $e) {
    write_log("Errore generale: " . $e->getMessage(), $logFile);
    exit(1);
}
