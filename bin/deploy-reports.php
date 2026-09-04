#!/usr/bin/env php
<?php
declare(strict_types=1);

$source = dirname(__DIR__);
$target = '/home/c2559919c/public_html/api';
$backup = '/home/c2559919c/backups/reports-' . date('Ymd-His');
$files = [
    'composer.json','composer.lock','app/routes/rapportroute.php',
    'src/Application/Reports/ReportService.php','src/Application/Reports/ReportManager.php','src/Application/Reports/ReportExporter.php',
    'bin/generate-daily-report.php','bin/migrate-reports.php','database/migrations/20260904_create_rapports.sql','public/assets/logobeton.png',
];
foreach ($files as $file) {
    $destination = $target . '/' . $file;
    if (is_file($destination)) { $backupFile=$backup.'/'.$file; if(!is_dir(dirname($backupFile))) mkdir(dirname($backupFile),0775,true); copy($destination,$backupFile); }
    if (!is_dir(dirname($destination))) mkdir(dirname($destination),0775,true);
    if (!copy($source.'/'.$file,$destination)) throw new RuntimeException('Copie impossible: '.$file);
}
$routes=$target.'/app/routes.php'; $routesBackup=$backup.'/app/routes.php'; if(!is_dir(dirname($routesBackup))) mkdir(dirname($routesBackup),0775,true); copy($routes,$routesBackup);
$contents=file_get_contents($routes);
if (!str_contains($contents,"group('/rapports'")) {
    $block="\n    // Rapports de production\n    \$app->group('/rapports', function (Group \$group) {\n        \$reportRoutes = require __DIR__ . '/routes/rapportroute.php';\n        \$reportRoutes(\$group);\n    });\n";
    $position=strrpos($contents,'};'); if($position===false) throw new RuntimeException('Structure de routes.php inattendue.');
    file_put_contents($routes,substr($contents,0,$position).$block.substr($contents,$position));
}
fwrite(STDOUT,"Fichiers copiés. Sauvegarde: $backup\n");
