#!/usr/bin/env php
<?php
declare(strict_types=1);

use DI\ContainerBuilder;
require dirname(__DIR__).'/vendor/autoload.php';
$builder=new ContainerBuilder();
(require dirname(__DIR__).'/app/settings.php')($builder);
(require dirname(__DIR__).'/app/dependencies.php')($builder);
$pdo=$builder->build()->get(PDO::class);
$sql=file_get_contents(dirname(__DIR__).'/database/migrations/20260904_create_rapports.sql');
foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement){
    try{$pdo->exec($statement);}catch(PDOException $error){if((int)$error->errorInfo[1]!==1061) throw $error;}
}
fwrite(STDOUT,"Migration rapports appliquée.\n");
