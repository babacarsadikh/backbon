<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use App\Application\Settings\SettingsInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        PDO::class => function (ContainerInterface $container) {
            $settings = $container->get(SettingsInterface::class);
            $dbSettings = $settings->get('db');
            
            $host = $dbSettings['host'];
            // Remplace 'localhost' par '127.0.0.1' dans le host
            if ($host === 'localhost') {
                $host = '127.0.0.1';
            }
            
            $dbname = $dbSettings['dbname'];
            $username = $dbSettings['user'];
            $password = $dbSettings['pass'];
            $charset = $dbSettings['charset'];
            
            $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
            
            try {
                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false, // Ajoutez cette ligne
                ]);
                
                return $pdo;
            } catch (PDOException $e) {
                // Message d'erreur plus détaillé
                error_log("Erreur PDO: " . $e->getMessage());
                throw new PDOException("Impossible de se connecter à la base de données: " . $e->getMessage(), (int)$e->getCode());
            }
        },
    ]);
};