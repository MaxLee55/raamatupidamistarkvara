<?php
function findDatabaseDumpProgram(string $applicationDirectory): string
{
    $roots=array_unique([dirname($applicationDirectory,2),dirname(PHP_BINARY,2)]);
    $candidates=[];
    foreach($roots as $root) {
        $candidates[]=$root.DIRECTORY_SEPARATOR.'mysql'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'mysqldump.exe';
        foreach(['mysql','mariadb'] as $family) {
            $versions=glob($root.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.$family.DIRECTORY_SEPARATOR.'*',GLOB_ONLYDIR)?:[];
            rsort($versions,SORT_NATURAL);
            foreach($versions as $version) foreach(['mysqldump.exe','mariadb-dump.exe'] as $program) $candidates[]=$version.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.$program;
        }
    }
    foreach($candidates as $program) if(is_file($program)) return $program;
    throw new RuntimeException('SQL-varukoopia programmi ei leitud. Andmeid ei kustutatud.');
}
function createDatabaseBackup(string $backupPath,?string $applicationDirectory=null): void
{
    global $host,$dbname,$username,$password;
    $dumpProgram=findDatabaseDumpProgram($applicationDirectory??__DIR__);
    $directory=dirname($backupPath);
    if(!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Varukoopia kausta ei saanud luua.');
    $credentialFile=tempnam(sys_get_temp_dir(),'rm_db_backup_');
    if($credentialFile===false) throw new RuntimeException('Varukoopia ajutist seadistusfaili ei saanud luua.');
    $quote=static function(string $value): string {
        return '"'.strtr($value,["\\"=>"\\\\",'"'=>'\\"',"\n"=>'\\n',"\r"=>'\\r',"\t"=>'\\t']).'"';
    };
    try {
        @chmod($credentialFile,0600);
        $config="[client]\nhost=".$quote((string)$host)."\nuser=".$quote((string)$username)."\npassword=".$quote((string)$password)."\n";
        if(file_put_contents($credentialFile,$config)===false) throw new RuntimeException('Varukoopia ajutisi seadeid ei saanud salvestada.');
        $command=[$dumpProgram,'--defaults-extra-file='.$credentialFile,'--single-transaction','--routines','--triggers','--hex-blob','--no-tablespaces','--default-character-set=utf8mb4'];
        if(!str_contains(strtolower($dumpProgram),'mariadb')) { $command[]='--column-statistics=0'; $command[]='--set-gtid-purged=OFF'; }
        $command[]=(string)$dbname;
        $process=proc_open($command,[0=>['pipe','r'],1=>['file',$backupPath,'wb'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)) throw new RuntimeException('SQL-varukoopia loomist ei saanud käivitada.');
        fclose($pipes[0]); $errorOutput=stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exitCode=proc_close($process); clearstatcache(true,$backupPath);
        if($exitCode!==0 || !is_file($backupPath) || filesize($backupPath)<100) {
            if(is_file($backupPath)) unlink($backupPath);
            error_log('Andmebaasi varukoopia loomine ebaõnnestus: '.$errorOutput);
            throw new RuntimeException('SQL-varukoopia loomine ebaõnnestus. Andmeid ei kustutatud.');
        }
    } finally { if(is_file($credentialFile)) unlink($credentialFile); }
}
