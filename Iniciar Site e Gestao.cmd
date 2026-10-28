@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-site.ps1" %*
pause
