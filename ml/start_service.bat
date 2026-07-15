@echo off
REM JourneyAI ML service launcher (Windows / XAMPP).
REM Double-click or run from cmd. Serves on http://127.0.0.1:5000
cd /d "%~dp0.."
echo Starting JourneyAI ML service on http://127.0.0.1:5000 ...
ml\venv\Scripts\python.exe -m ml.app
pause
