@echo off
echo ========================================================
echo Pushing SmartNotes AI Backend to GitHub...
echo Repository: https://github.com/vishaljv76-svg/ai_class_bebackend.git
echo ========================================================

cd /d "C:\xampp\htdocs\ai_class_be"

if not exist ".git" (
    echo Initializing git repository...
    git init
    git branch -M main
)

echo Adding remote repository...
git remote remove origin 2>nul
git remote add origin https://github.com/vishaljv76-svg/ai_class_bebackend.git

echo Staging all backend files...
git add .

echo Committing files...
git commit -m "Initial commit: Complete SmartNotes AI PHP backend, MySQL schema, and Postman collection"

echo.
echo Pushing to GitHub (main branch)...
git push -u origin main

echo.
echo ========================================================
echo Done! If prompted, sign in to your GitHub account.
echo ========================================================
pause

