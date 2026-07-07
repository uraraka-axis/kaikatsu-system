@echo off
rem kaikatsu smoke test launcher. Keep this file CRLF + ASCII.
cd /d "%~dp0..\.."
python tests\e2e\smoke_test.py %*
exit /b %ERRORLEVEL%
