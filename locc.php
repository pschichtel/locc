#!/usr/bin/php
<?php
    define ('DS', DIRECTORY_SEPARATOR);

    function isText($string)
    {
        $chars = array_merge(array_map('chr', range(32,127)), array("\012", "\015", "\t", "\b"));

        if (strpos($string, "\0") !== false)
        {
            return false;
        }
        if (empty($string))
        {
            return true;
        }

        $originalSize = strlen($string);

        foreach ($chars as $char)
        {
            $string = str_replace($char, '', $string);
        }

        if (strlen($string) / $originalSize > 0.3)
        {
            return false;
        }
        return true;
    }
    
    function isTextfile($file, $blocksize = 512)
    {
        $fp = fopen($file, 'rb');
        flock($fp, LOCK_SH);
        $string = fread($fp, $blocksize);
        fclose($fp);

        return isText($string);
    }

    function getArg($name, $default = null)
    {
        global $_ARGS;
        if (isset($_ARGS[$name]))
        {
            return $_ARGS[$name];
        }
        else
        {
            return $default;
        }
    }

    function isArg($name)
    {
        global $_ARGS;
        return isset($_ARGS[$name]);
    }

    function readDirRecursive($path, array &$list, array &$blacklist = array())
    {
        $path = rtrim($path, '/\\');
        $dir = opendir($path);
        while(($entry = readdir($dir)) !== false)
        {
            if ($entry == '.' || $entry == '..')
            {
                continue;
            }
            $entry = $path . DS . $entry;
            if (in_array($entry, $blacklist))
            {
                continue;
            }
            if (is_dir($entry))
            {
                readDirRecursive($entry, $list, $blacklist);
            }
            else
            {
                if (isTextfile($entry))
                {
                    $list[] = $entry;
                }
            }
        }
        closedir($dir);
    }

    function isCommentLine($line, $lannguage)
    {
        if ($lannguage->slComment)
        {
            if (is_array($lannguage->slComment))
            {
                foreach ($lannguage->slComment as $slComment)
                {
                    if (substr($line, 0, strlen($slComment)) == $slComment)
                    {
                        return true;
                    }
                }
            }
            else
            {
                return (substr($line, 0, strlen($lannguage->slComment)) == $lannguage->slComment);
            }
        }
        return false;
    }

    function startsMultilineComment($line, $language)
    {
        if ($language->mlComment && is_array($language->mlComment))
        {
            return (substr_count($line, $language->mlComment[0]) > 0);
        }
        return false;
    }

    function endsMultilineComment($line, $language)
    {
        if ($language->mlComment && is_array($language->mlComment))
        {
            return (substr_count($line, $language->mlComment[0]) > 0);
        }
        return false;
    }

    function normalizePath($path)
    {
        //return str_replace('/', DS, str_replace('\\', DS, $path));
        return preg_replace('/[\\/]/', DS, $path);
    }


    $_ARGS = array();
    for ($i = 1; $i < $argc; ++$i)
    {
        if ($argv[$i][0] == '-')
        {
            $argv[$i] = substr($argv[$i], 1);
        }
        if ($argv[$i][0] == '-')
        {
            if (isset($argv[$i + 1]))
            {
                $_ARGS[substr($argv[$i], 1)] = $argv[++$i];
            }
        }
        else
        {
            $_ARGS[$argv[$i]] = true;
        }
    }


    $extMap = array();
    $languages = array();
    $unknown = array(
        'name' => 'Unknown',
        'slComment' => null,
        'mlComment' => null
    );
    
    if (is_readable('languages.json'))
    {
        $languages = json_decode(file_get_contents('languages.json'));
    }

    foreach ($languages as $i => $language)
    {
        if (isset($language->extentions) && is_array($language->extentions))
        {
            foreach ($language->extentions as $extention)
            {
                $extMap[$extention] =& $languages[$i];
            }
        }
        else
        {
            echo 'The Language ' . $language->name . ' has no extentions!' . "\n";
        }
    }

    $targetdir = trim(getArg('dir', '.'));
    $targetdir = preg_replace('/[\\/]$/', '', $targetdir);
    $rawBlacklist = array();
    $resolvedBlacklist = array();
    $blacklistPath = getArg('blacklist', $targetdir . DS . 'blacklist.txt');
    if (file_exists($blacklistPath) && is_file($blacklistPath))
    {
        $rawBlacklist = file($blacklistPath, FILE_SKIP_EMPTY_LINES);
    }
    foreach ($rawBlacklist as $entry)
    {
        $entry = $targetdir . DS . normalizePath(trim($entry));
        $paths = array();
        if (substr_count($entry, '*') > 0)
        {
            $paths = glob($entry);
        }
        else
        {
            $paths = array($entry);
        }
        foreach ($paths as $path)
        {
            if (file_exists($path))
            {
                if (is_dir($path) || isTextfile($path))
                {
                    $resolvedBlacklist[] = $path;
                }
            }
        }
    }

    //var_dump($resolvedBlacklist);

    $targetFiles = array();
    readDirRecursive($targetdir, $targetFiles, $resolvedBlacklist);

    $result = array(
        'languageMap' => array(),
        'lines' => 0,
        'realLines' => 0,
        'files' => count($targetFiles),
        'languages' => 0,
        'ignoredLines' => 0
    );
    
    $ignoreEmptyLines = isArg('noempty');
    $ignoreSingleCharLines = isArg('nosingles');
    $ignoreCommentLines = isArg('nocomments');
    foreach ($targetFiles as $file)
    {
        $ext = null;
        $extDelimPos = strrpos($file, '.');
        if ($extDelimPos !== false)
        {
            $tmp_ext = strtolower(substr($file, $extDelimPos + 1));
            if (isset($extMap[$tmp_ext]))
            {
                $ext = $tmp_ext;
            }
        }
        
        $lines = file($file);
        $fileData = array(
            'path' => $file,
            'ext' => $ext,
            'type' => ($ext ? $extMap[$ext] : $unknown),
            'lines' => count($lines),
            'realLines' => 0,
            'ignoredLines' => 0
        );

        foreach ($lines as $line)
        {
            $line = trim($line);
            if ($ignoreEmptyLines && empty($line))
            {
                ++$fileData['ignoredLines'];
                continue;
            }
            if ($ignoreSingleCharLines && strlen($line) == 1)
            {
                ++$$fileData['ignoredLines'];
                continue;
            }
            if ($ignoreCommentLines)
            {
                if (isCommentLine($line, $fileData['type']))
                {
                    ++$$fileData['ignoredLines'];
                    continue;
                }
            }
            ++$fileData['realLines'];
        }
        $result['lines'] += $fileData['lines'];
        $result['realLines'] += $fileData['realLines'];
        $result['ignoredLines'] += $fileData['ignoredLines'];
        $result['languageMap'][$ext][] = $fileData;
    }

    $result['languages'] = count($result['languageMap']);

    //var_dump($result);
    echo "Lines: {$result['lines']}\n";
    echo "Real lines: {$result['realLines']}\n";
    echo "Ignored lines: {$result['ignoredLines']}\n";
    echo "Languages: {$result['languages']}\n";
    
    var_dump(array_keys($result['languageMap']));
    
    var_dump($resolvedBlacklist);

?>