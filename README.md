
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
