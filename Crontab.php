<?php
/*
 * CakePHP Model - dynamische Crontab-Verwaltung
 * __________________________________________________________________
 *
 * Dieses Model beinhaltet die Logik zur Verwaltung und zum Verteilen
 * von Crontabs innerhalb einer AutoScalingGroup hinter einem ELB mit
 * unbekannter Anzahl von Instanzen.
 *
 * Als Kommunikationsschnittstelle wird eine REDIS-Gruppe mit einer
 * geringen Lebensdauer verwendet. Generell wird dabei jede Instanz mit
 * einem Listener-Tab initialisiert.
 *
 * Wird der Server, auf dem der ProductionCrontab läuft systembedingt
 * terminiert, verliert der REDIS-Cache innerhalb der nächsten Sekunde
 * seine Gültigkeit und ein nächster Listener-Tab wird zum aktiven Crontab.
 *
 * @copyright     Copyright 2016, Guido Odendahl | Cocobit Code & Colors GmbH
 * @link          http://www.cocobit.de
 */

App::uses('AppModel','Model');

class Crontab extends AppModel {
    public $name = 'Crontab';
    public $useTable = 'system_crontabs';

    # Diese Email wird im aktuellen Crontab eingebunden
    const CRON_EMAIL = 'info@cocobit.de';

    # Benennung der Tab-Zustände
    const LISTENER = 'listener';
    const RUNNER = 'runner';
    const CRONCODE_LISTENER = '# CronCode::LISTENER';
    const CRONCODE_RUNNER = '# CronCode::RUNNER';
    const CRONCODE_DEACITVATE = '# CronCode::DEACTIVATED';

    # Dateiname des Shellscripts
    const SHELL_SCRIPT = 'crnspacefellows';

    # Cron-Zeitkonfigurationen
    const MINUTE 		= '* * * * *';	# Jede Minute
    const FIVE_MINUTES 	= '*/5 * * * *';# Alle 5 Minuten
    const HOUR			= '0 * * * *';	# Jede volle Stunde
    const DAY 			= '0 3 * * *';	# Jeden Tag um 03:00 Uhr
    const CHRONIC 		= '0 9 * * 5';	# Jeden Tag um 06:00 Uhr
    const IVI 		    = '* * * * *'; # Alle 2 Minuten
    const MIDNIGHT 		= '0 0 * * *'; # Immer um Mitternacht

    # Ordner im Linux-Dateisystem
    const CRONLOG_FOLDER = 'logs';

    private $cronlog_path = false;
	
    /**
     * Speichert die Einträge aller eingetragenen Cronjobs des Users
     * @var array
     */
    protected $rows = array();

    /**
     * Konfiguration aller Einträge Jobs des Users
     * @var array
     */
    private $jobs = array(
        'mining_scheduler' 	=> self::MINUTE,
        'infinion_data' 	=> self::MINUTE,
        'mail_fallback' 	=> self::MINUTE,
        'tournament' 	    => self::MINUTE,
        'stats5' 			=> self::FIVE_MINUTES,
        'hourly' 			=> self::HOUR,
        'comeback' 			=> self::HOUR,
        'chronic' 			=> self::CHRONIC,
        'ivi' 			    => self::IVI,
        'midnight' 		    => self::MIDNIGHT,
    );



    /**
     * deklariert diesen Server als neuen CronServer
     *
     * @return void
     */
    public function createNewCron() {

        # Prüfe, ob Shellscript und Logs-Ordner existieren
        $this->_checkFileSystem();

        # Prüfe, ob ein Cron auf einer anderen Instanz aktiv ist
        if(!$result = $this->_checkRunningCron()) {
            # Markiere das System mit dem Status "Intitialisieren"
            Cache::write(CRON_INIT,'initialize',CRON_INIT);

            # Lese die Tab-Zeilen aus
            $this->tabHead(self::RUNNER,self::CRONCODE_RUNNER);
            # .. und füge die Jobs hinzu
            foreach($this->jobs as $job => $intervall) {
                $this->rows[] = $this->makeRow($intervall,$job);
            }
            # Schreibe den Tab in das Dateisystem
            $this->saveTabs();
        }

        # Lege die IP Adresse im Cache ab
        Cache::write(CRON_RUNNING,PRIVATE_IP,CRON_RUNNING);
    }



    /**
     * Prüfe, ob der aktuelle Cron läuft, dann erstelle keinen neuen
     *
     * @return boolean
     */
    private function _checkRunningCron() {
        $rows = array();
        exec('crontab -l', $rows);
        # Prüfe, ob die erste Zeile CRONCOE_RUNNER == true ist
        return $rows[1] == self::CRONCODE_RUNNER;
    }



    /**
     * Entferne alle Cronjobs
     * Achtung! Damit kann dieser Server nicht mehr automatisch in den Cronverbund
     * eingebunden werden
     *
     * @return void
     */
    public function removeCron() {

        # Prüfe, ob Shellscript und Logs-Ordner existieren
        $this->_checkFileSystem();

        # Erstelle die nötigen Zeilen für einen deaktivierten Crontab
        $this->tabHead(self::RUNNER,self::CRONCODE_DEACITVATE);
        $this->rows[] = '# !!! Cron is deactivated !!!';

        # Schreibe den Tab in das Dateisystem
        $this->saveTabs();

        # Schreibe die CronInfo in den Cache
        $this->writeInfos2Cache(self::CRONCODE_DEACITVATE);
    }



    /**
     * Erstelle einen ListenerCronJob
     *
     * @return void
     */
    public function createListener() {

        # Prüfe, ob Shellscript und Logs-Ordner existieren
        $this->_checkFileSystem();

        # Erstelle die nötigen Zeilen für einen Listener-Crontab
        $this->tabHead(self::LISTENER,self::CRONCODE_LISTENER);
        $this->rows[] = $this->makeRow(self::MINUTE,'listener');

        # Schreibe den Tab in das Dateisystem
        $this->saveTabs();

        # Schreibe die CronInfo in den Cache
        $this->writeInfos2Cache(self::CRONCODE_LISTENER);
    }



    /**
     * Prüfe, ob alle Dateien und Pfade für den Cron vorhanden sind
     *
     * @return array
     */
    private function _checkFileSystem() {
        # existiert der Ordner Cron?
        if(!is_dir(ROOT.DS.'cron')) {
            $errorMessage = "<div style='margin:10px auto; width:400px;font-family:monospace;text-align:center;'>";
            if(DEBUG_LEVEL > 0) {
                $errorMessage .= "<h3>=== Cron-Ordner existiert nicht! ===</h3>Lege einen Ordner <strong style='color:#F50000;'>\"";
                $errorMessage .= ROOT.DS."cron\"</strong> mit den Rechten <strong style='color:#F50000;'>0777</strong> an!";
            } else {
                $errorMessage .= "<h3>=== ".GAME_NAME." Serverwartung ===</h3>Bitte warten, der Server wird konfigieriert!";
            }
            $errorMessage .= "</div>";
            return array(
                'error' => $errorMessage
            );

        }
        # Prüfe, ob der Cronlog-Pfad existiert
        if(!is_dir(self::cronlogPath())) {
            # Nein, also erstelle den Ordner
            mkdir(self::cronlogPath());
            chmod(self::cronlogPath(),0777);
            $folder = self::cronlogPath().DS.'dev';
            mkdir($folder);
            chmod($folder,0777);
            $folder = self::cronlogPath().DS.'live';
            mkdir($folder);
            chmod($folder,0777);
        }

        # Prüfe, ob das Cron-Shellscript exisitert
        if(!file_exists(self::cronScriptLocation())) {
            # Nein, also erstelle das Cron-Shellscript
            $shell_script = array(
                '#!/bin/bash',
                'TERM=dumb',
                'export TERM',
                'cmd="cake"',
                'bin="/usr/bin"',
                'path_cake="'.ROOT.DS.'lib/Cake/Console/"',
                'app="'.ROOT.DS.APP_DIR.DS.'"',
                'PATH="$PATH:${bin}:${path_cake}"',
                'while [ $# -ne 0 ]; do',
                '   cmd="${cmd} $1"',
                '   shift',
                'done',
                '$cmd'
            );

            # Schreibe das Shellscript in das Dateisystem
            $handle = fopen(self::cronScriptLocation(), 'w');

            foreach($shell_script as $row) {
                fwrite($handle, $row.PHP_EOL);
            }

            fclose($handle);

            chmod(self::cronScriptLocation(),0777);
        }

        return array(
            'error' => false
        );
    }



    /**
     * Definiere den Cronlog-Pfad
     *
     * @param string $version
     * @return string
     */
    static function cronlogPath($version = false) {
        $return = ROOT.DS.'cron'.DS.self::CRONLOG_FOLDER;
        if($version === true) {
            $return .= DS.(PRIVATE_IP == PRIVATE_DEV_IP ? 'dev' : 'live');
        }
        return $return;
    }



    /**
     * Definiere den CronScript-Pfad
     *
     * @return string
     */
    static function cronScriptLocation() {
        return ROOT.DS.'cron'.DS.self::SHELL_SCRIPT;
    }



    /**
     * Erstelle den Kopf des Crontabs und lege ihn in der Member-Variable $row ab
     *
     * @param string $type
     * @param string $croncode
     * @return void
     */
    private function tabHead($type = self::RUNNER,$croncode = self::CRONCODE_RUNNER) {

        $this->rows = array(
            '### '.GAME_NAME.' - CronTab ###',
            $croncode,
            '# Dynamischer Crontab ('.(PRIVATE_IP == PRIVATE_DEV_IP ? 'DEV' : 'LIVE').')',
            '# (c) Cocobit 2016',
            'MAILTO = "'.self::CRON_EMAIL.'"',
            '',
        );

    }


    /**
     * Erstelle eine Job-Zeile des abs
     *
     * @param string $time
     * @param string $command
     * @return string
     */
    private function makeRow($time = self::MINUTE,$command = '') {
        $row = $time.' '.self::cronScriptLocation().' cron ';
        $row .= $command;
        $row .= ' -app ';
        $row .= ROOT.DS.APP_DIR.DS;
        # Ausgabe im Logfile
        $row .= ' >> '.self::cronlogPath(true).DS.$command.'.log';

        return $row;
    }



    /**
     * Prüfe, ob der aktuelle Cron läuft, dann erstelle keinen neuen
     *
     * @param string $status
     * @return void
     */
    public function writeInfos2Cache($status) {
        # Existiert ein Eintrag im Cache?
        if(!$crinf = Cache::read(CRON_INFO,CRON_INFO)) {
            $crinf = array();
        }
        # Lese den Tab ein
        exec('crontab -l', $rows);

        # Füge den neuen Tab zur Variabel hinzu
        $crinf[PRIVATE_IP] = array(
            'status' => $status,
            'crontab' => $rows,
            'private_ip' => PRIVATE_IP,
            'timestamp' => time()
        );

        foreach($crinf as $ip=>$value) {
            # Ist der Tab älter als 5 Minuten, soll er gelöscht werden, auch wenn der Cache noch besteht
            if(time() > ($value['timestamp'] + 300)) {
                unset($crinf[$ip]);
            }
        }
        Cache::write(CRON_INFO,$crinf,CRON_INFO);
    }



    /**
     * Hole die private IP-Adresse des Servers
     *
     * @return string
     */
    static function ipAdress() {
        $command = 'echo "$(hostname -I)"';
        return exec($command);
    }



    /**
     * Entfernt alle Whitespaces aus dem Hinzugefügten Cronjob
     * Wird auf alle Felder bis auf das Command Feld angewendet
     *
     * @param string $cell
     * @return string
     */
    protected function _sanitize($cell) {
        return preg_replace('/\s+/', '', $cell);
    }



    /**
     * Liefert alle Cronjobs als Array
     *
     * @return array
     */
    public function getAllTabs() {
        return $this->rows;
    }



    /**
     * Findet alle eingetragen Cronjobs die mit den Suchparameters übereinstimmen
     *
     * @param array[optional] $dataArr
     * @return array
     */
    public function getTabs($dataArr = array()) {
        $result = array();
        foreach($this->rows as $i => $row) {
            if((!isset($dataArr['minute'])  	|| $dataArr['minute'] == $row[0])
            && (!isset($dataArr['hour'])    	|| $dataArr['hour'] == $row[1])
            && (!isset($dataArr['dom'])     	|| $dataArr['dom'] == $row[2])
            && (!isset($dataArr['month'])   	|| $dataArr['month'] == $row[3])
            && (!isset($dataArr['dow'])     	|| $dataArr['dow'] == $row[4])
            && (!isset($dataArr['command']) 	|| $dataArr['command'] == $row[5])
            && (!isset($dataArr['commandSW']) 	|| $dataArr['commandSW'] == substr($row[5], 0, strlen($dataArr['commandSW'])))
            && (!isset($dataArr['commandPM']) 	|| preg_match($dataArr['commandPM'], $row[5]))
            ) {
                $result[$i] = $row;
            }
        }
        return $result;
    }



    /**
     * Entfernt alle Cronjobs die mit den Suchparameters übereinstimmen
     * gleiche Suchparameter wie Crontab::getTabs() und zusätzlich 'row' damit kann gezielt eine Zeile gelöscht werden
     *
     * @param array $dataArr
     * @return int Anzahl der gelöschten Zeilen
     */
    public function removeTabs($dataArr = array()) {
        if(isset($dataArr['row'])) {
                unset($this->row[$dataArr['row']]);
                return true;
        }
        $rows = $this->getTabs($dataArr);
        foreach($rows AS $i => $row) {
            unset($this->row[$i]);
        }
        return count($rows);
    }



    /**
     * Benötigt ein sechs Spalten numerisches Array mit den Feldern:
     * Minute, Stunde, Tag im Monat, Monat, Tag der Woche, Befehl
     *
     * @param array $row
     * @return boolean
     */
    public function addTabs($row = array()) {
        if(count($row) != 6) {
            return false;
        }
        $command = array_pop($row);
        $row = array_map(array($this, '_sanitize'), $row);
        $row[] = $command;
        $this->rows[] = $row;
        return true;
    }



    /**
     * Speichert alle Cronjobs in die Crontab Datei des Users
     *
     * @return boolean
     */
    public function saveTabs() {
        $file = tempnam(sys_get_temp_dir(), 'PHP_CRONTAB');
        $handle = fopen($file, 'w');

        foreach($this->rows as $row) {
            fwrite($handle, $row.PHP_EOL);
        }
        fclose($handle);
        exec("crontab $file");
        unlink($file);
        $tmp = $this->rows;
        return ($tmp === $this->rows);
    }

}


?>