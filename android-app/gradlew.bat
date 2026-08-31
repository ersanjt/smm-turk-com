@echo off
REM Portable Android build helper. Prefer Android Studio, or a local Gradle install.
where gradle >nul 2>&1
if %ERRORLEVEL%==0 (
  gradle %*
  exit /b %ERRORLEVEL%
)
echo Install Android Studio or Gradle, then from android-app run: gradle assembleDebug
exit /b 1
