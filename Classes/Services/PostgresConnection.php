<?php
declare(strict_types=1);

namespace Classes\Services;

use PDO;
use PDOException;

/**
 * Gestione connessione PostgreSQL tramite PDO
 * Legge le configurazioni da un file pg_service.conf
 */
class PostgresConnection
{
    private PDO $pdo;

    /**
     * Costruttore
     *
     * @param string $pgServiceName Nome del service da usare (pg_test | pg_prod)
     * @param string $pgServiceFile Percorso completo al file pg_service.conf
     * @param Logger $logger Logger mensile per registrare connessione e errori
     *
     * @throws PDOException
     */
    public function __construct(string $pgServiceName, string $pgServiceFile, Logger $logger)
    {
        try {
            $config = $this->loadPgService($pgServiceFile, $pgServiceName);

            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'],
                $config['port'],
                $config['dbname']
            );

            $this->pdo = new PDO(
                $dsn,
                $config['user'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            // Test immediato della connessione
            $this->pdo->query('SELECT 1');

            $logger->info("Connessione PostgreSQL riuscita ({$pgServiceName})");

        } catch (PDOException $e) {
            $logger->error("Errore connessione PostgreSQL ({$pgServiceName}): " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Restituisce l'oggetto PDO
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Legge la configurazione di un service dal file pg_service.conf
     *
     * @param string $file Percorso al file pg_service.conf
     * @param string $service Nome del service da leggere
     * @return array ['host'=>..., 'port'=>..., 'dbname'=>..., 'user'=>..., 'password'=>...]
     *
     * @throws \RuntimeException se il file non esiste o il service non è definito
     */
    private function loadPgService(string $file, string $service): array
    {
        if (!file_exists($file)) {
            throw new \RuntimeException("File pg_service.conf non trovato: {$file}");
        }

        $data = parse_ini_file($file, true);

        if (!isset($data[$service])) {
            throw new \RuntimeException("Servizio PostgreSQL '{$service}' non definito in {$file}");
        }

        return $data[$service];
    }
}
