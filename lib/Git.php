<?php

class Git
{
    const GIT_EXECUTABLE = 'git';
    const NULLREV = '0000000000000000000000000000000000000000';

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
        $path = exec(sprintf('%s rev-parse --git-dir', self::GIT_EXECUTABLE));
        if (!is_dir($path)) {
            return false;
        }

        return realpath($path);
    }
}
