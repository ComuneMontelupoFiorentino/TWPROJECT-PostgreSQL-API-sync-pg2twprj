# Twproject Issues Sync

Automazione per invio e sincronizzazione delle issue/todo da PostgreSQL a Twproject tramite API.

---

## Descrizione

Questo progetto consente di:
1. Recuperare le todo dalla tabella PostgreSQL e creare issue su Twproject.
2. Monitorare lo stato degli issue creati e aggiornare PostgreSQL con status e commenti.

Sviluppato in **PHP**, con **PostgreSQL**, schedulabile tramite **cron**.

---

## Struttura

- `config/` → Configurazioni database e API.
- `logs/` → Log dei processi cron.
- `src/` → Script principali:
  - `get_data_pgsql.php` → Invia le todo a Twproject.
  - `sinc_todo_status.php` → Sincronizza lo stato degli issue.
  - `open_todo_function.php` → Funzioni helper.
  - `decrypt_util.php` → Decriptazione APIKey.
- `cron/` → Esempi di configurazione cron.

---

## License

This project is licensed under the European Union Public License v1.2 (EUPL).
See the full license text here: https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12

© 2025 Comune di Montelupo Fiorentino.

