<?php
// Vorlage: als config.php kopieren und ausfüllen. config.php nicht ins Repo.
return [
    'initpw' => 'BITTE-AENDERN',      // Einrichtungspasswort: ersten Admin anlegen, Notfall-Zugang (mind. 8 Zeichen)
    'cron_token' => 'BITTE-AENDERN',  // für den Cron-Aufruf per URL: cron.php?token=... (langer Zufallstext)
    'zeitlimit' => 50,                // Sekunden je Verdichtungslauf; der nächste Lauf macht weiter
    'backup_behalten' => 5,           // so viele Sicherungen je Datenbank bleiben im Backup-Ordner
    // 'backup_ordner' => __DIR__ . '/data/backup',
    // 'sqlite' => __DIR__ . '/data/sqlman.sqlite',   // anderer Ort der App-Datenbank
    // 'debug' => true,               // nur lokal: PHP-Fehler im Browser zeigen

    // Messdaten-Datenbanken (MySQL/MariaDB, vom ioBroker-SQL-Adapter befüllt).
    // Schlüssel (nur a-z, 0-9, _) nicht mehr ändern, wenn schon Einstellungen dazu gespeichert sind.
    'datenbanken' => [
        'haus' => [
            'name' => 'Haus',
            'dsn'  => 'mysql:host=localhost;dbname=iobroker;charset=utf8mb4',
            'user' => 'iobroker',
            'pass' => 'GEHEIM',
        ],
        // 'garten' => ['name' => 'Gartenhaus', 'dsn' => 'mysql:host=...;dbname=...;charset=utf8mb4', 'user' => '...', 'pass' => '...'],
    ],
];
