    ];
    $database->beginTransaction();
    try {
        foreach ($statements as $sql) {
            if ($database->exec($sql) === false) {
                throw new \RuntimeException('SQLite schema installation failed: ' . ($database->errorInfo()[2] ?? 'unknown error'));
            }
        }
        $database->commit();
    } catch (\Throwable $error) {
        $database->rollBack();
        throw $error;
    }
};
