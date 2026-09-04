#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Application\Reports\ReportManager;
use DI\ContainerBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('Africa/Dakar');

$builder = new ContainerBuilder();
(require dirname(__DIR__) . '/app/settings.php')($builder);
(require dirname(__DIR__) . '/app/dependencies.php')($builder);
$container = $builder->build();
$date = $argv[1] ?? date('Y-m-d', strtotime('yesterday'));

try {
    $report = (new ReportManager($container->get(PDO::class), dirname(__DIR__) . '/var/reports'))
        ->generate(['date_debut' => $date, 'date_fin' => $date]);
    fwrite(STDOUT, sprintf("Rapport %s: %s (#%d)\n", $date, $report['statut'], $report['id']));
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, sprintf("Rapport %s en erreur: %s\n", $date, $error->getMessage()));
    exit(1);
}
