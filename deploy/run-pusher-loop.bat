@echo off
REM Fallback for the office PC when Task Scheduler is not an option.
REM Keeps ONE collector process alive and restarts it 10 s after any crash.
REM Prefer deploy\install-windows-task.ps1 (starts at boot, survives logoff).
set MIBDIRS=C:\xampp\php\extras\mibs
cd /d C:\xampp\htdocs\nocview
if not exist tmp mkdir tmp
:loop
C:\xampp\php\php.exe C:\xampp\htdocs\nocview\snmp_pusher.php --loop --interval=60 --log=C:\xampp\htdocs\nocview\tmp\pusher.log
timeout /t 10 /nobreak >nul
goto loop
