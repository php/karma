<?php

class Git
{
    const GIT_EXECUTABLE = 'git';
    const NULLREV = '0000000000000000000000000000000000000000';

    private static $repositoryPath = null;

    /**
     * Returns the path to the current repository.
     *
     * Tries to determine the path of the current repository in which
     * the hook was invoked.
     *
     * @return string
     */
    public static function getRepositoryPath()
    {
        if (is_null(self::$repositoryPath)) {
            $path = exec(sprintf('%s rev-parse --git-dir', self::GIT_EXECUTABLE));
            self::$repositoryPath = is_dir($path) ? realpath($path) : false;
        }

        return self::$repositoryPath;
    }

    /**
     * Run git shell command and return result
     *
     * @param $cmd string
     * @param $arg string
     * @param ... string
     * @return string
     */
    public static function gitExec($cmd)
    {
        $cmd = self::GIT_EXECUTABLE . " --git-dir=" . self::getRepositoryPath() . " " . $cmd;
        $args = func_get_args();
        array_shift($args);
        $cmd = vsprintf($cmd, $args);
        $output = shell_exec($cmd);
        return (string)$output;
    }
}
