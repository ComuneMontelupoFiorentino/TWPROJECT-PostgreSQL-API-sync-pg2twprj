# Twproject Integration Sync

Automazione per l'integrazione e la sincronizzazione bidirezionale tra Twproject (tramite API) e un database PostgreSQL (sistema ProSIT per la gestione di ticket). Gestisce la creazione di Issue (Todo), la sincronizzazione degli stati e il trasferimento di commenti e allegati.

**Keywords**: PROJECT MANAGEMENT, API, TWProject, PostgreSQL, Riuso, PA

## Requisiti di funzionamento

- Server con PHP versione `8` o superiore
- PHP deve essere compilato con le seguenti estensioni: `php-cli`, `php-curl`, `php-pgsql`, `openssl`
- Database `Postgresql` versione `15` o superiore
- Client Twproject con licenza ENTERPRISE v. 7.1.027.13979 con [relative API]([https://www.postgresql.org/docs/current/libpq-pgservice.html](https://twproject.com/support/api-documentation/#issue-create))

## Installazione

Per ogni funzionalità, è possibile lanciare lo script in due modalità distinte, una modalità di `test` ed una modalità di `produzione`.

**Elenco delle funzionalità**

- `IssueToTicket`: Monitora i todo presenti in un Task e li importa nel database locale insieme agli allegati, se presenti: recupera le issue (todo) aperti (status = 1), verifica se esiste già nella tabella PostgreSQL e se assente la inserisce. Inoltre esegue il download locale degli allegati e li referenzia nel DB.   
- `OpenTodo`: Consente la creazione di Issue (todo) su Twproject in due modalità: Schedulata leggendo la tabella `todo_queue` o tramite CLI.
- `InsertComment`: Consente di inserire commenti su un issues (todo) esistente in modalità schedulata o tramite CLI.
- `SyncStatusPgToTwprj`: Consente la sincronizzazione dello stato di un issues (todo) in base allo stato del record (ticket) che lo ha generato: Estrae da TwProject i record aperti ("status" => "1") da un task predefinito, confronta ogni issue (todo)  con i record della tabella audit.control_issue_to_ticket. Se l'issue non esiste nella tabella vuol dire che è stato eliminato da prosit o non è stato inserito e verrà stampato apposito Log. Se lo stato è allineato, skip. Se lo stato non è allineato ma risulta sincronizzato sul DB (campo sync = true) e su TwProject risulta chiuso (Status = 2) allora vuol dire che l'issue è stato riaperto e il ticket verrà riaperto. Se lo stato non è allineato e risulta non sincronizzato sul DB (campo sync = false) e su TwProject risulta chiuso (Status = 2) allora vuol dire che il ticket è stato chiuso su prosit e l'issue (todo) verrà chiuso su TWproject (Status = 2).
> NOTA
>
> se un Todo sincronizzato su prosit viene cancellato o eliminato su twproject, su prosit restarà invariato
- `SyncStatusTwToSIT`: Consente la sincronizzazione dello stato di un record (ticket) in base allo stato del issues (todo) che lo ha generato.
- `SyncStatus`: Consente di eseguire antrambi le funzioni SyncStatusPgToTwprj e SyncStatusTwToSIT.
- `CheckIntegration`: Consente di eseguire Query finalizzate alla scelta della funzione da eseguire. Se attivata controlla, tramite chiamate "leggere" su PostgreSQL e Twprject, in autonomia la necessità di sincronizzazione ed eventualmente esegue la specifica funzione. Utile per schedulazioni tramite CRON

### Struttura delle cartelle

```bash
|── cartella principale
    |── Classes/
        |── Services/
            |── Integration/
                |── CheckIntegration.php
            |── Logger.php
            |── PostgresConnection.php
        |── Tasks/
            |── InsertCommentToTodo.php
            |── IssueToTicket.php
            |── OpenTodo.php
            |── RecordToTodo.php
            |── SyncStatusPgToTwprj.php
            |── SyncStatusTwToSIT.php
    |── config/
        |── pg_service.conf
        |── twproject_config.ini
    |── Logs/
        |── ANNO/
            |── MESE
              |── GIORNO
    |── bootstrap.php
    |── sync.php
```
## Configurazione

Configurare il file `config/pg_service.conf` con i parametri di connessione al db.
Il file può contenere due configurazioni `pg_test` e `pg_prod`. 
Questa distinzione è stata introdotta per garantire una maggiore flessibilità e test. Nulla vieta di impostare la stessa configurazione sia per test che per produzione.

Per i dettagli sulla struttura e sulla configurazione del file pg_service.conf consultare il [manuale](https://www.postgresql.org/docs/current/libpq-pgservice.html) Postgres dedicato.

Configurare il file `config/twproject_client_config.ini` con i parametri del servizio twproject.
Anche in questo caso è presente la distinzione tra ambiente di test e produzione per il servizio ckan.

```ìni
[ckan_test]
url=https://[PORTALE_CKAN].it
key=[TOKEN-O-API-KEY] 
resource_module=[ID-RISORSA-DATASTORE] 
; path locale dove ExportDataWriter salva i file
resource_local_path=/PERCORSO/AI/FILE/LOCALI/
[twprj_test]
url=[URL]
key==[API-KEY] 
; path locale in cui salvare gli allegati
resource_local_path=/PERCORSO/AI/FILE/LOCALI/
; url pubblico in cui inserire gli allegati di twproject
; la cartella deve essere accessibile e con privileggi di R&W
public_url_attachments=https://[DOMINIO/[PERCORSO]/[AI]/[FILE]
; task da cui sincronizzare i todo da inserire in pgsql
task_issue_to_ticket=[ID TASK]
```

## Utilizzo della funzionalità

Una volta completati gli step di configurazione precedenti, è possibile lanciare la funzionalità direttamente da riga di comando posizionandosi nella cartella principale (stesso livello del file `sync.php`)

```cli
$ php sync.php [ambiente] [comando] [parametro]
```

Per qualsiasi funzionalità è necessario definire ambiente di esecuzione e comando

### Ambiente

La definizione dell'ambiente di esecuzione è obbligatorio, quindi definire uno tra:

- `--test`, lancio in ambiente di test
- `--prod`, lancio in ambiente di produzione

in caso nessuna o entrambe le opzioni vengano specificate, lo script terminerà con errore.


### Comando

I comandi possono necessitare di filtri

| **FUNZIONALITA**              | **COMANDO**      | **PARAMETRO**            | **NOTE** |
|-------------------------------|------------------|--------------------------|----------------------|
| IssueToTicket                 | -IT              |                          |                      |
| OpendTodo                     | -OT              | --[campo] "[valore]"     | Senza parametri legge da tabella locale todo_queue, altrimenti settare i parametri come descritti nella [documentazione]([https://www.postgresql.org/docs/current/libpq-pgservice.html](https://twproject.com/support/api-documentation/#issue-create)), ad eccezione di gravity per cui è consentito usare solo la versione numerica: 1 = "01_GRAVITY_LOW", 2 = "02_GRAVITY_MEDIUM", 3 = "03_GRAVITY_HIGH",4 = "04_GRAVITY_CRITICAL", 5 = "05_GRAVITY_BLOCK"          |
| InsertComment                 | -IC              | --idIssue [ID TODO] --comment "[Test commento]" | Senza parametro verranno inviati i commenti presenti nella tabella postgres, altrimenti i parametri sono obbligatori           |
| SyncStatusPgToTwprj           | -SSTw            |                          |                      |
| SyncStatusTwToSIT             | -SSSit           |                          |                      |
| SyncStatus                    | -SS              |                          |                      |
| CheckIntegration              | -CI              |                          |                      |

Esempi:

**IssueToTicket** 
```ìni
php sync.php -test -IT
```
**OpendTodo**
```ìni
php sync.php -test -OT
```
```ìni
php sync.php -test -OT   --subject "Lampione rotto"   --description "Non si accende"   --taskId 9999   --lat 43.72   --lon 11.12  --gravity 1
```
> NOTA
>
> Se nella tabellla todo_queue è presente un commento, verrà inserito in coda al testo, direttamente nel body
**InsertComment**
```ìni
php sync.php -test -IC
```
```ìni
php sync.php -test -IC --idIssue 123 --comment "Test commento"
```
**SyncStatusPgToTwprj**
```ìni
php sync.php -prod -SSTw 
```
**SyncStatusTwToSIT**
```ìni
php sync.php -prod -SSSit
```
**SyncStatus**
```ìni
php sync.php -prod -SS
```
**CheckIntegration**
```ìni
php sync.php -prod -CI
```

## License

This project is licensed under the European Union Public License v1.2 (EUPL).
See the full license text here: https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12

© 2025 Comune di Montelupo Fiorentino.

