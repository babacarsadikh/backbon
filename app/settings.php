<?php
declare(strict_types=1);

use App\Application\Settings\Settings;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        SettingsInterface::class => function () {
            return new Settings([
                'displayErrorDetails' => true, // ⚠️ Mets à false en production
                'logError'            => false,
                'logErrorDetails'     => false,

                'logger' => [
                    'name'  => 'slim-app',
                    'path'  => isset($_ENV['docker']) 
                                ? 'php://stdout' 
                                : __DIR__ . '/../logs/app.log',
                    'level' => Logger::DEBUG,
                ],

                // 🔥 Configuration base de données
                'db' => [
                    'host'    => 'localhost',
                    'user'    => 'root',
                    'pass'    => '',
                    'dbname'  => 'gestion_bon_livraison',
                    'charset' => 'utf8mb4',
                ],
            ]);
        }
    ]);
};
