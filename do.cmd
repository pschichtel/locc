@echo off

pushd
cd "%~dp0"
set "PHP=\xampp\php\php.exe"
set /p "target=Target: "

"%PHP%" locc.php -nosingle -noempty -nocomment --dir "%target%" > php.log 2> php.error.log

popd
