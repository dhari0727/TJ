@echo off
REM JourneyAI ML service launcher (Windows / XAMPP). Serves on http://127.0.0.1:5000 with waitress.
REM Logs go to ml\logs\service.log. Run from a console, or register with Task Scheduler / NSSM (docs\DEPLOYMENT.md).
cd /d "%~dp0.."
if not exist ml\logs mkdir ml\logs
echo Starting JourneyAI ML service on http://127.0.0.1:5000 ...
ml\venv\Scripts\python.exe -m ml.app >> ml\logs\service.log 2>&1
