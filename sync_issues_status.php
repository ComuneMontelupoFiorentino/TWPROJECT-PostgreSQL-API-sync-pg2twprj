<?php
 declare(strict_types=1);
 
 require_once __DIR__ . '/config/get_db.php';

 /**
  * Controlla lo stato degli issues su Twproject e sincronizza i valori su pgsql.
  *
  * @param array $idIssue è l'issue presente in audit.todo_queue del quale monitorare lo status
  * @param string $APIKey chiave API Twproject
  * @return bool true se stato cambiato, false altrimenti
  * 
  * Created by Comune di Montelupo Fiorentino
  * Ufficio Supporto alla transizione digitale
  * Funzionario Enrico Gullì
  * 19-09-25
  */

  // funzione per scrivere i log 
  $logFile = __DIR__ . '/logs/sinc_todo_status.log';
  function write_log(string $message, string $logFile): void {
    $date = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$date] $message\n", FILE_APPEND | LOCK_EX);
 }

 // funzione per chiamata API
 function call_twproject_api(array $data, string $APIKey): ?array {
     $url = "https://mfnotwpro.ddns.net:8443/API/V1/";
     $data["APIKey"] = $APIKey;
 
     $ch = curl_init($url);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
     curl_setopt($ch, CURLOPT_POST, true);
     curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
     curl_setopt($ch, CURLOPT_HTTPHEADER, [
         'Content-Type: application/json',
         'Accept: application/json'
     ]);
 
     $response = curl_exec($ch);
 
     if (curl_errno($ch)) {
         error_log("Errore cURL: " . curl_error($ch));
         curl_close($ch);
         return null;
     }
 
     curl_close($ch);
     return json_decode($response, true);
 }
 
 try {

     write_log("=== Inizio sinc status todo ===", $logFile);

     $db = get_db();

     // Recupero gli id_todo_twprj  da sincronizzare
    $stmt = $db->query("SELECT id_todo_twprj  FROM audit.todo_queue WHERE status = 'sended' AND todo_status_twprj = 1 ORDER BY id ASC");
    $idsIssue = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$idsIssue) {
        write_log("Nessuna todo da inviare", $logFile);
    } else {

        // Carico APIKey
        $config = require __DIR__ . '/config/api.php';
        require_once __DIR__ . '/decrypt_util.php';
        $APIKey = decrypt_field($config['APIKey']);
 
        foreach ($idsIssue as $row) {
            $idIssue = (int) $row['id_todo_twprj'];
            // 1. Recupera dati dell’issue
            $issueData = [
                "command" => "get",
                "object"  => "issue",
                "id"      => $idIssue
            ];
            $api_response = call_twproject_api($issueData, $APIKey);
    
            if ($api_response && isset($api_response['object']['statusId'])) {
                $statusId = (int) $api_response['object']['statusId'];
    
                // Se è chiusa o completata
                if ($statusId === 2 || $statusId === 3) {
                    // 2. Recupera i commenti
                    $commentsData = [
                        "command" => "getComments",
                        "object"  => "issue",
                        "id"      => $idIssue
                    ];
                    $comments_response = call_twproject_api($commentsData, $APIKey);
    
                    $formatted_comments = [];
                    if (
                        $comments_response &&
                        isset($comments_response['comments']) &&
                        is_array($comments_response['comments']) &&
                        count($comments_response['comments']) > 0
                    ) {
                        // Ordina per data di creazione
                        usort($comments_response['comments'], function($a, $b) {
                            return $a['creationDate'] <=> $b['creationDate'];
                        });
    
                        foreach ($comments_response['comments'] as $comment) {
                            $timestamp = (int) round($comment['creationDate'] / 1000);
                            $date = date('Y-m-d H:i:s', $timestamp);
                            $formatted_comments[] = [
                                "date"    => $date,
                                "creator" => $comment['creator'],
                                "comment" => trim($comment['comment'])
                            ];
                        }
                    }
    
                    // 3. Aggiorna DB
                    $stmtUpdate = $db->prepare("
                        UPDATE audit.todo_queue 
                        SET todo_status_twprj = :status,
                            sinc_status = NOW(),
                            todo_comments_twprj = :comments
                        WHERE id_todo_twprj = :id_todo
                    ");
    
                    $stmtUpdate->execute([
                        ':status'   => $statusId,
                        ':comments' => json_encode($formatted_comments, JSON_UNESCAPED_UNICODE),
                        ':id_todo'  => $idIssue
                    ]);

                    write_log("Aggiornato todo_queue per issue $idIssue", $logFile);
                    
                } else {
                    write_log("Issue $idIssue ancora aperta (statusId = $statusId)", $logFile);
                }
            } else {
                write_log("Errore recupero issue $idIssue", $logFile);
            }
        }  
     }
 } catch (Exception $e) {
    write_log("Errore aggiornamento DB per issue $idIssue: " . $e->getMessage(), $logFile);
 }
 
